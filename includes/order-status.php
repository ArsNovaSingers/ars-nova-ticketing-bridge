<?php
/**
 * Change a WooCommerce order's status over REST, so a Claude session can do it directly.
 *
 * WHY
 *
 * The connector could LIST orders and never change one. On 2026-09-20 a $0 test order,
 * placed to verify the free student-ticket path end to end, had to be cancelled over SSH
 * with `wp eval` because nothing in ars-nova/v1 and nothing on the connector could do it.
 * Order status is not an exotic operation here - comps, test orders, abandoned checkouts
 * and the failed-order ticket cleanup all turn on it - so that gap recurs every time.
 *
 * WHAT THIS DELIBERATELY WILL NOT DO
 *
 * - It will NOT set an order to `refunded`. That status marks the books settled while the
 *   gateway still holds the customer's money, which is exactly the outcome
 *   includes/order-refunds.php exists to prevent. Use POST order/{id}/refund instead - it
 *   refunds THROUGH the gateway and lets the refund hooks void tickets by the normal path.
 * - It will NOT trash an order. Trashing is what leaves phantom ticket codes behind,
 *   because the bridge trashes ticket instances on `cancelled` and `refunded` only.
 *   Cancel instead; that is the cleanup that actually cleans up.
 * - It does NOT issue, re-issue or restore tickets. Ticket lifecycle belongs to the
 *   Tickera bridge's own hooks. This endpoint changes status and then tells you the truth
 *   about what that did to the tickets.
 *
 * SAFETY, MIRRORING includes/order-refunds.php
 *
 * - DRY RUN BY DEFAULT. Called without dry_run = false it changes nothing and returns the
 *   plan: current status, target status, and what will happen to the tickets.
 * - On the live storefront a real change additionally requires confirm_production = true.
 * - Every applied change writes an order note naming this route, so wp-admin shows what
 *   touched the order and why.
 *
 * TICKETS ARE THE POINT, SO THEY ARE COUNTED BOTH SIDES
 *
 * A status change has a second, invisible effect: the Tickera + WooCommerce bridge moves
 * ticket instances to `trash` when an order becomes `cancelled` or `refunded`, and it does
 * NOT do so for `failed` - the documented hole in
 * claude/ticketing/Phantom_Ticket_Exposure_2026-09-07.md section 5. Re-reading the order
 * does not reveal any of that. So this endpoint counts ticket instances by post_status
 * before and after and returns both counts. Prefer the artefact over the account of it.
 *
 * ROUTES
 *   GET  ars-nova/v1/order/{id}/status   - current status, allowed targets, ticket counts
 *   POST ars-nova/v1/order/{id}/status   - dry_run defaults TRUE; pass false to act
 *
 * @package ars-nova-ticketing-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is this the live storefront?
 *
 * Mirrors ans_ref_is_production() rather than calling it, so this file does not depend on
 * include order. Same host list, same filter shape.
 */
function ans_ost_is_production() {
	if ( function_exists( 'ans_ref_is_production' ) ) {
		return ans_ref_is_production();
	}
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	$prod = apply_filters( 'ans_ost_production_hosts', array( 'arsnovasingers.org', 'www.arsnovasingers.org' ) );
	return in_array( strtolower( (string) $host ), array_map( 'strtolower', $prod ), true );
}

/**
 * Statuses this route refuses to set, and why. Keys are bare statuses, no `wc-` prefix.
 *
 * @return array<string,string>
 */
function ans_ost_blocked_targets() {
	return array(
		'refunded' => 'Refunding is money, not a status. Use POST ars-nova/v1/order/{id}/refund, which refunds through the gateway and lets the refund hooks void the tickets. Setting this status by hand would mark the books settled while the customer is still out of pocket.',
		'trash'    => 'Trashing an order leaves its Tickera ticket codes live, because the bridge only trashes ticket instances on cancelled and refunded. Cancel the order instead.',
	);
}

/**
 * Count this order's Tickera ticket instances, grouped by post_status.
 *
 * Deliberately a direct query: WP_Query will not return `trash` alongside `publish`
 * without argument gymnastics, and `trash` is the status that matters most here.
 *
 * @param int $order_id
 * @return array<string,int> e.g. array( 'publish' => 2 ) or array( 'trash' => 2 )
 */
function ans_ost_ticket_counts( $order_id ) {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_status, COUNT(*) AS n
			   FROM {$wpdb->posts}
			  WHERE post_type = 'tc_tickets_instances'
			    AND post_parent = %d
			  GROUP BY post_status",
			(int) $order_id
		),
		ARRAY_A
	);

	$out = array();
	foreach ( (array) $rows as $row ) {
		$out[ (string) $row['post_status'] ] = (int) $row['n'];
	}
	return $out;
}

/**
 * Describe an order compactly. Small on purpose - this route is called to act, not to browse.
 *
 * @param WC_Order $order
 * @return array
 */
function ans_ost_describe( $order ) {
	$items = array();
	foreach ( $order->get_items() as $item ) {
		$items[] = array(
			'name' => $item->get_name(),
			'qty'  => (int) $item->get_quantity(),
		);
	}

	return array(
		'id'             => $order->get_id(),
		'status'         => $order->get_status(),
		'total'          => wc_format_decimal( $order->get_total(), 2 ),
		'currency'       => $order->get_currency(),
		'payment_method' => $order->get_payment_method(),
		'email'          => $order->get_billing_email(),
		'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
		'items'          => $items,
		'tickets'        => ans_ost_ticket_counts( $order->get_id() ),
	);
}

/**
 * Normalise a caller's status string. Accepts 'completed', 'wc-completed' and 'Completed'.
 *
 * @param string $status
 * @return string bare status, lowercased
 */
function ans_ost_normalise( $status ) {
	$status = strtolower( trim( (string) $status ) );
	return preg_replace( '/^wc-/', '', $status );
}

/**
 * What will happen to this order's tickets if it moves to $target?
 *
 * States the mechanism rather than guessing, and says plainly where it does not know.
 *
 * @param string $current bare status
 * @param string $target  bare status
 * @return string
 */
function ans_ost_ticket_forecast( $current, $target ) {
	if ( $current === $target ) {
		return 'No change - the order is already in this status.';
	}
	if ( in_array( $target, array( 'cancelled', 'refunded' ), true ) ) {
		return 'The Tickera bridge trashes this order\'s ticket instances. The codes stop scanning and drop off the attendee list.';
	}
	if ( 'failed' === $target ) {
		return 'WARNING: the bridge does NOT trash ticket instances on `failed`. Live codes will remain on the attendee list. This is the documented hole in Phantom_Ticket_Exposure_2026-09-07.md section 5.';
	}
	if ( in_array( $target, array( 'completed', 'processing' ), true ) ) {
		if ( in_array( $current, array( 'cancelled', 'refunded' ), true ) ) {
			// MEASURED on staging 2026-09-21, order 7644: completed -> cancelled trashed both
			// instances, and cancelled -> completed restored both to publish. The bridge does
			// untrash. An earlier draft of this line asserted the opposite; it was wrong, and it
			// was caught only because the route reports real counts either side. Trust those
			// counts, not this sentence.
			return 'The bridge restores the trashed ticket instances to publish - measured, not assumed. Confirm with the returned counts.';
		}
		return 'Tickets become scannable: only `completed` and `processing` pass the door check.';
	}
	return 'Unknown. The ticket counts returned before and after are the authority, not this sentence.';
}

/**
 * GET - the plan. Never writes.
 *
 * @param WP_REST_Request $req
 * @return WP_REST_Response|WP_Error
 */
function ans_ost_route_plan( $req ) {
	$order = wc_get_order( (int) $req['id'] );
	if ( ! $order ) {
		return new WP_Error( 'ans_ost_not_found', 'No such order.', array( 'status' => 404 ) );
	}

	$allowed = array();
	foreach ( array_keys( wc_get_order_statuses() ) as $key ) {
		$bare = ans_ost_normalise( $key );
		if ( ! isset( ans_ost_blocked_targets()[ $bare ] ) ) {
			$allowed[] = $bare;
		}
	}

	return rest_ensure_response(
		array(
			'order'           => ans_ost_describe( $order ),
			'allowed_targets' => $allowed,
			'blocked_targets' => ans_ost_blocked_targets(),
			'is_production'   => ans_ost_is_production(),
			'note'            => 'Plan only. POST the same path with status and dry_run=false to act.',
		)
	);
}

/**
 * POST - change the status. Dry run unless told otherwise.
 *
 * @param WP_REST_Request $req
 * @return WP_REST_Response|WP_Error
 */
function ans_ost_route_set( $req ) {
	$order = wc_get_order( (int) $req['id'] );
	if ( ! $order ) {
		return new WP_Error( 'ans_ost_not_found', 'No such order.', array( 'status' => 404 ) );
	}

	$target = ans_ost_normalise( $req->get_param( 'status' ) );
	if ( '' === $target ) {
		return new WP_Error( 'ans_ost_no_status', 'A target `status` is required.', array( 'status' => 400 ) );
	}

	$blocked = ans_ost_blocked_targets();
	if ( isset( $blocked[ $target ] ) ) {
		return new WP_Error( 'ans_ost_blocked', $blocked[ $target ], array( 'status' => 400 ) );
	}

	$valid = array_map( 'ans_ost_normalise', array_keys( wc_get_order_statuses() ) );
	if ( ! in_array( $target, $valid, true ) ) {
		return new WP_Error(
			'ans_ost_bad_status',
			sprintf( 'Unknown status "%s". Valid: %s', $target, implode( ', ', $valid ) ),
			array( 'status' => 400 )
		);
	}

	$current = $order->get_status();
	$dry_run = null === $req->get_param( 'dry_run' ) ? true : (bool) $req->get_param( 'dry_run' );
	$reason  = (string) $req->get_param( 'reason' );
	$before  = ans_ost_ticket_counts( $order->get_id() );

	if ( $current === $target ) {
		return rest_ensure_response(
			array(
				'changed' => false,
				'reason'  => sprintf( 'Order %d is already `%s`. Nothing to do.', $order->get_id(), $target ),
				'order'   => ans_ost_describe( $order ),
			)
		);
	}

	if ( $dry_run ) {
		return rest_ensure_response(
			array(
				'dry_run'         => true,
				'changed'         => false,
				'from'            => $current,
				'to'              => $target,
				'tickets_before'  => $before,
				'ticket_forecast' => ans_ost_ticket_forecast( $current, $target ),
				'is_production'   => ans_ost_is_production(),
				'note'            => 'Nothing was changed. Pass dry_run=false' . ( ans_ost_is_production() ? ' and confirm_production=true' : '' ) . ' to apply.',
			)
		);
	}

	if ( ans_ost_is_production() && ! (bool) $req->get_param( 'confirm_production' ) ) {
		return new WP_Error(
			'ans_ost_confirm_production',
			'This is the live storefront. Pass confirm_production=true to change a real order.',
			array( 'status' => 400 )
		);
	}

	$note = sprintf(
		'Status changed %s to %s via ars-nova/v1 order/%d/status.%s',
		$current,
		$target,
		$order->get_id(),
		'' !== $reason ? ' Reason: ' . $reason : ''
	);

	$ok = $order->update_status( $target, $note );
	if ( ! $ok ) {
		return new WP_Error(
			'ans_ost_update_failed',
			sprintf( 'WooCommerce refused the transition %s to %s.', $current, $target ),
			array( 'status' => 500 )
		);
	}

	// Re-read. The order object in hand is not evidence about the database.
	$fresh = wc_get_order( $order->get_id() );
	$after = ans_ost_ticket_counts( $order->get_id() );

	return rest_ensure_response(
		array(
			'dry_run'        => false,
			'changed'        => true,
			'from'           => $current,
			'to'             => $fresh ? $fresh->get_status() : $target,
			'tickets_before' => $before,
			'tickets_after'  => $after,
			'reason'         => '' !== $reason ? $reason : null,
			'order'          => $fresh ? ans_ost_describe( $fresh ) : null,
			'note'           => 'Verified by re-reading the order and re-counting ticket instances. Ticket changes, if any, were made by the bridge hooks - see the order notes.',
		)
	);
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			ANS_TB_NS,
			'/order/(?P<id>\d+)/status',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => 'ans_tb_perm',
					'callback'            => 'ans_ost_route_plan',
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => 'ans_tb_perm',
					'callback'            => 'ans_ost_route_set',
					'args'                => array(
						'status'             => array( 'type' => 'string',  'required' => true ),
						'reason'             => array( 'type' => 'string',  'required' => false ),
						'dry_run'            => array( 'type' => 'boolean', 'required' => false ),
						'confirm_production' => array( 'type' => 'boolean', 'required' => false ),
					),
				),
			)
		);
	}
);
