<?php
/**
 * Move a ticket line item to a different performance, over REST.
 *
 * WHY
 *
 * Patrons ask to swap nights. On 2026-09-22 order 7977 (one Adult ticket, Rivers & Streams)
 * had to move from Oct 10 Denver to Oct 9 Boulder, and nothing in ars-nova/v1 could do it:
 * the swap was done over SSH with a hand-written `wp eval-file`, touching four separate
 * places by hand. That gap recurs every season, so it is a route now.
 *
 * WHAT A "MOVE" TOUCHES - ALL FOUR, OR THE TICKET LIES SOMEWHERE
 *
 * 1. The WooCommerce line item: product_id, name, and the When / Where / _ans_event_id /
 *    _ans_event_ts meta that includes/display-names.php freezes at checkout. Rebuilt with
 *    that file's own helpers, so a moved ticket reads exactly as a fresh purchase would.
 * 2. The order's Tickera cart meta: tc_cart_contents and tc_cart_info are keyed by ticket
 *    type id.
 * 3. Every Tickera ticket instance on that line: event_id and ticket_type_id. These are
 *    what the PDF prints and what the door scanner checks. The ticket CODE is unchanged.
 * 4. Sales bookkeeping: total_sales on both products, stock if either manages it, and the
 *    WooCommerce Analytics product lookup row.
 *
 * WHAT IT DELIBERATELY WILL NOT DO
 *
 * - It will not move money. If the two products are priced differently it refuses unless
 *   allow_price_difference = true, and even then it leaves the line total exactly as paid.
 *   Charge or refund the difference separately (order/{id}/refund refunds).
 * - It will not move part of a line. The whole line item moves, every ticket on it.
 * - It will not move a ticket on an unpaid order. Only completed / processing orders
 *   hold tickets that scan.
 * - It will not move to a product that is not a published Tickera ticket, or to one that
 *   is out of stock for the quantity being moved.
 * - It does not email the patron. Send the ticket link from tickera/order-tickets.
 *
 * SAFETY, MIRRORING includes/order-status.php AND includes/order-refunds.php
 *
 * - DRY RUN BY DEFAULT. Without dry_run = false it changes nothing and returns the plan.
 * - On the live storefront a real change additionally requires confirm_production = true.
 * - Every applied move writes an order note naming this route and both performances.
 * - After writing, everything is RE-READ from the database and returned, rather than the
 *   intended values being echoed back. Prefer the artefact over the account of it.
 *
 * ROUTE
 *   POST ars-nova/v1/order/{id}/move-ticket
 *     to_product_id          int   required - the ticket product for the new performance
 *     item_id                int   optional - required only if the order has >1 ticket line
 *     reason                 str   optional - lands in the order note
 *     dry_run                bool  default TRUE
 *     confirm_production     bool  required true on Live to apply
 *     allow_price_difference bool  default false
 *
 * @package ars-nova-ticketing-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is this the live storefront? Same answer as order-status.php and order-refunds.php.
 */
function ans_omt_is_production() {
	if ( function_exists( 'ans_ost_is_production' ) ) {
		return ans_ost_is_production();
	}
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	return in_array( strtolower( (string) $host ), array( 'arsnovasingers.org', 'www.arsnovasingers.org' ), true );
}

/**
 * The ticket instances belonging to one line item of one order.
 *
 * Tickera stamps each instance with `item_id`. Instances minted before that meta existed
 * are matched by ticket_type_id instead, so an old order is not silently skipped.
 *
 * @param int $order_id
 * @param int $item_id
 * @param int $product_id The line's current product - the fallback match.
 * @return array<int,array> id, status, code, event_id, ticket_type_id
 */
function ans_omt_line_tickets( $order_id, $item_id, $product_id ) {
	$ids = get_posts(
		array(
			'post_type'      => 'tc_tickets_instances',
			'post_parent'    => (int) $order_id,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	$out = array();
	foreach ( (array) $ids as $tid ) {
		$t_item = (int) get_post_meta( $tid, 'item_id', true );
		$t_type = (int) get_post_meta( $tid, 'ticket_type_id', true );
		$mine   = $t_item ? ( $t_item === (int) $item_id ) : ( $t_type === (int) $product_id );
		if ( ! $mine ) {
			continue;
		}
		$out[] = array(
			'id'             => (int) $tid,
			'status'         => get_post_status( $tid ),
			'code'           => (string) get_post_meta( $tid, 'ticket_code', true ),
			'event_id'       => (int) get_post_meta( $tid, 'event_id', true ),
			'ticket_type_id' => $t_type,
		);
	}
	return $out;
}

/**
 * Compact, re-readable picture of one line item. Used for before AND after.
 *
 * @param WC_Order_Item_Product $item
 * @return array
 */
function ans_omt_describe_item( $item ) {
	$meta = array();
	foreach ( array( 'When', 'Where', '_ans_event_id', '_ans_event_ts' ) as $k ) {
		$meta[ $k ] = $item->get_meta( $k );
	}
	return array(
		'item_id'    => $item->get_id(),
		'product_id' => $item->get_product_id(),
		'name'       => $item->get_name(),
		'qty'        => (int) $item->get_quantity(),
		'total'      => wc_format_decimal( $item->get_total(), 2 ),
		'meta'       => $meta,
	);
}

/**
 * Describe a destination/origin performance for humans and for the order note.
 *
 * @param int $product_id
 * @return array|null
 */
function ans_omt_performance( $product_id ) {
	$ctx = function_exists( 'ans_dn_context' ) ? ans_dn_context( (int) $product_id ) : null;
	if ( ! $ctx ) {
		return null;
	}
	return array(
		'product_id' => (int) $product_id,
		'event_id'   => (int) $ctx['event_id'],
		'concert'    => $ctx['concert'],
		'tier'       => $ctx['tier_label'],
		'when'       => $ctx['when'],
		'where'      => $ctx['venue'],
		'label'      => trim( $ctx['concert'] . ' ' . $ctx['tier_label'] ) . ', ' . $ctx['when'] . ', ' . $ctx['venue'],
	);
}

/**
 * Validate a request and build the move plan. Pure read - never writes.
 *
 * @param WP_REST_Request $req
 * @return array|WP_Error Plan array with keys order, item, from, to, tickets, qty, warnings.
 */
function ans_omt_plan( $req ) {
	$order = wc_get_order( (int) $req['id'] );
	if ( ! $order ) {
		return new WP_Error( 'ans_omt_not_found', 'No such order.', array( 'status' => 404 ) );
	}

	if ( ! in_array( $order->get_status(), array( 'completed', 'processing' ), true ) ) {
		return new WP_Error(
			'ans_omt_not_paid',
			sprintf( 'Order %d is `%s`. Only completed or processing orders hold tickets that scan, so only those can be moved.', $order->get_id(), $order->get_status() ),
			array( 'status' => 400 )
		);
	}

	// Which line? Only ticket lines count.
	$ticket_lines = array();
	foreach ( $order->get_items() as $iid => $it ) {
		if ( 'yes' === get_post_meta( $it->get_product_id(), '_tc_is_ticket', true ) ) {
			$ticket_lines[ (int) $iid ] = $it;
		}
	}
	$item_id = (int) $req->get_param( 'item_id' );
	if ( ! $item_id ) {
		if ( 1 !== count( $ticket_lines ) ) {
			$list = array();
			foreach ( $ticket_lines as $iid => $it ) {
				$list[] = $iid . ' = ' . $it->get_name() . ' x' . $it->get_quantity();
			}
			return new WP_Error(
				'ans_omt_which_item',
				'This order has ' . count( $ticket_lines ) . ' ticket lines. Pass item_id. Lines: ' . implode( '; ', $list ),
				array( 'status' => 400 )
			);
		}
		$item_id = (int) array_key_first( $ticket_lines );
	}
	if ( ! isset( $ticket_lines[ $item_id ] ) ) {
		return new WP_Error( 'ans_omt_bad_item', sprintf( 'Item %d is not a ticket line on order %d.', $item_id, $order->get_id() ), array( 'status' => 400 ) );
	}
	$item = $ticket_lines[ $item_id ];
	$from = (int) $item->get_product_id();

	// Where to?
	$to = (int) $req->get_param( 'to_product_id' );
	if ( $to === $from ) {
		return new WP_Error( 'ans_omt_same', 'That line is already on product ' . $to . '. Nothing to move.', array( 'status' => 400 ) );
	}
	$to_product = wc_get_product( $to );
	if ( ! $to_product || 'yes' !== get_post_meta( $to, '_tc_is_ticket', true ) ) {
		return new WP_Error( 'ans_omt_not_ticket', sprintf( 'Product %d is not a Tickera ticket product.', $to ), array( 'status' => 400 ) );
	}
	if ( 'publish' !== $to_product->get_status() ) {
		return new WP_Error( 'ans_omt_not_published', sprintf( 'Product %d is `%s`, not published.', $to, $to_product->get_status() ), array( 'status' => 400 ) );
	}
	$to_event = (int) get_post_meta( $to, '_event_name', true );
	if ( ! $to_event || 'publish' !== get_post_status( $to_event ) ) {
		return new WP_Error( 'ans_omt_event_not_live', sprintf( 'Product %d points at event %d, which is not published. A ticket on a draft event cannot be sold or trusted at the door.', $to, $to_event ), array( 'status' => 400 ) );
	}

	$qty      = (int) $item->get_quantity();
	$warnings = array();

	if ( $to_product->managing_stock() && (int) $to_product->get_stock_quantity() < $qty ) {
		return new WP_Error( 'ans_omt_no_stock', sprintf( 'Product %d has %d left; moving %d would oversell that performance.', $to, (int) $to_product->get_stock_quantity(), $qty ), array( 'status' => 400 ) );
	}

	$from_product = wc_get_product( $from );
	$p_from       = $from_product ? (float) $from_product->get_regular_price( 'edit' ) : 0.0;
	$p_to         = (float) $to_product->get_regular_price( 'edit' );
	if ( abs( $p_from - $p_to ) > 0.001 ) {
		if ( ! (bool) $req->get_param( 'allow_price_difference' ) ) {
			return new WP_Error(
				'ans_omt_price_differs',
				sprintf( 'Price differs: product %d is %s, product %d is %s. This route does not move money. Pass allow_price_difference=true to move anyway and settle the difference separately.', $from, wc_format_decimal( $p_from, 2 ), $to, wc_format_decimal( $p_to, 2 ) ),
				array( 'status' => 400 )
			);
		}
		$warnings[] = sprintf( 'Price differs (%s vs %s). The line total stays as paid; settle the difference separately.', wc_format_decimal( $p_from, 2 ), wc_format_decimal( $p_to, 2 ) );
	}

	$tickets = ans_omt_line_tickets( $order->get_id(), $item_id, $from );
	if ( count( $tickets ) !== $qty ) {
		$warnings[] = sprintf( 'Line quantity is %d but %d ticket instance(s) were found for it. All found instances will be moved; check the order afterwards.', $qty, count( $tickets ) );
	}

	return array(
		'order'    => $order,
		'item'     => $item,
		'from'     => ans_omt_performance( $from ),
		'to'       => ans_omt_performance( $to ),
		'from_id'  => $from,
		'to_id'    => $to,
		'qty'      => $qty,
		'tickets'  => $tickets,
		'warnings' => $warnings,
	);
}

/**
 * POST handler. Dry run unless told otherwise.
 *
 * @param WP_REST_Request $req
 * @return WP_REST_Response|WP_Error
 */
function ans_omt_route( $req ) {
	$plan = ans_omt_plan( $req );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}

	$order   = $plan['order'];
	$item    = $plan['item'];
	$from    = $plan['from_id'];
	$to      = $plan['to_id'];
	$qty     = $plan['qty'];
	$dry_run = null === $req->get_param( 'dry_run' ) ? true : (bool) $req->get_param( 'dry_run' );
	$reason  = (string) $req->get_param( 'reason' );

	$summary = array(
		'order_id'        => $order->get_id(),
		'item_before'     => ans_omt_describe_item( $item ),
		'from'            => $plan['from'],
		'to'              => $plan['to'],
		'qty'             => $qty,
		'tickets_before'  => $plan['tickets'],
		'warnings'        => $plan['warnings'],
		'is_production'   => ans_omt_is_production(),
	);

	if ( $dry_run ) {
		$summary['dry_run'] = true;
		$summary['changed'] = false;
		$summary['note']    = 'Nothing was changed. Pass dry_run=false' . ( ans_omt_is_production() ? ' and confirm_production=true' : '' ) . ' to apply. Ticket codes are kept; only the performance changes.';
		return rest_ensure_response( $summary );
	}

	if ( ans_omt_is_production() && ! (bool) $req->get_param( 'confirm_production' ) ) {
		return new WP_Error( 'ans_omt_confirm_production', 'This is the live storefront. Pass confirm_production=true to move a real ticket.', array( 'status' => 400 ) );
	}

	// 1. The line item - rebuilt with display-names.php's own helpers.
	$ctx = function_exists( 'ans_dn_context' ) ? ans_dn_context( $to ) : null;
	$item->set_product_id( $to );
	$item->set_variation_id( 0 );
	if ( $ctx ) {
		$item->set_name( ans_dn_headline( $ctx ) );
		foreach ( ans_dn_rows( $ctx ) as $label => $value ) {
			$item->update_meta_data( $label, $value );
		}
		$item->update_meta_data( '_ans_event_id', $ctx['event_id'] );
		$item->update_meta_data( '_ans_event_ts', $ctx['ts'] );
	}
	$item->save();

	// 2. Tickera cart meta on the order, re-keyed from the old ticket type to the new one.
	$cc = $order->get_meta( 'tc_cart_contents' );
	if ( is_array( $cc ) && isset( $cc[ $from ] ) ) {
		$cc[ $to ] = ( isset( $cc[ $to ] ) ? (int) $cc[ $to ] : 0 ) + (int) $cc[ $from ];
		unset( $cc[ $from ] );
		$order->delete_meta_data( 'tc_cart_contents' ); // clears duplicate rows too
		$order->add_meta_data( 'tc_cart_contents', $cc );
	}
	$ci = $order->get_meta( 'tc_cart_info' );
	if ( is_array( $ci ) && isset( $ci['owner_data'] ) && is_array( $ci['owner_data'] ) ) {
		foreach ( $ci['owner_data'] as $field => $by_type ) {
			if ( is_array( $by_type ) && array_key_exists( $from, $by_type ) ) {
				$ci['owner_data'][ $field ][ $to ] = $by_type[ $from ];
				unset( $ci['owner_data'][ $field ][ $from ] );
			}
		}
		$order->update_meta_data( 'tc_cart_info', $ci );
	}
	$order->save();

	// 3. The ticket instances - what the PDF prints and the door scanner checks.
	$to_event = (int) get_post_meta( $to, '_event_name', true );
	foreach ( $plan['tickets'] as $t ) {
		update_post_meta( $t['id'], 'event_id', (string) $to_event );
		update_post_meta( $t['id'], 'ticket_type_id', (string) $to );
	}

	// 4. Bookkeeping: stock (only where managed), total_sales, analytics.
	$from_product = wc_get_product( $from );
	$to_product   = wc_get_product( $to );
	if ( $from_product && $from_product->managing_stock() ) {
		wc_update_product_stock( $from_product, $qty, 'increase' );
	}
	if ( $to_product && $to_product->managing_stock() ) {
		wc_update_product_stock( $to_product, $qty, 'decrease' );
	}
	update_post_meta( $from, 'total_sales', max( 0, (int) get_post_meta( $from, 'total_sales', true ) - $qty ) );
	update_post_meta( $to, 'total_sales', (int) get_post_meta( $to, 'total_sales', true ) + $qty );
	$analytics = 'skipped';
	if ( class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Products\DataStore' ) ) {
		try {
			\Automattic\WooCommerce\Admin\API\Reports\Products\DataStore::sync_order_products( $order->get_id() );
			$analytics = 'resynced';
		} catch ( \Throwable $e ) {
			$analytics = 'failed: ' . $e->getMessage();
		}
	}

	$codes = implode( ', ', wp_list_pluck( $plan['tickets'], 'code' ) );
	$order->add_order_note(
		sprintf(
			'Ticket(s) %s moved via ars-nova/v1 order/%d/move-ticket. From: %s (product %d). To: %s (product %d). Ticket codes unchanged; no money moved.%s',
			'' !== $codes ? $codes : '(none found)',
			$order->get_id(),
			$plan['from'] ? $plan['from']['label'] : 'unknown',
			$from,
			$plan['to'] ? $plan['to']['label'] : 'unknown',
			$to,
			'' !== $reason ? ' Reason: ' . $reason : ''
		)
	);

	// Re-read everything from the database. The objects in hand are not evidence.
	$fresh      = wc_get_order( $order->get_id() );
	$fresh_item = $fresh ? $fresh->get_item( $item->get_id() ) : null;

	$summary['dry_run']       = false;
	$summary['changed']       = true;
	$summary['item_after']    = $fresh_item ? ans_omt_describe_item( $fresh_item ) : null;
	$summary['tickets_after'] = ans_omt_line_tickets( $order->get_id(), $item->get_id(), $to );
	$summary['cart_contents'] = $fresh ? $fresh->get_meta( 'tc_cart_contents' ) : null;
	$summary['analytics']     = $analytics;
	$summary['note']          = 'Re-read from the database. Fetch fresh ticket links with GET tickera/order-tickets?order_id=' . $order->get_id() . ' and send them to the patron.';

	return rest_ensure_response( $summary );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			ANS_TB_NS,
			'/order/(?P<id>\d+)/move-ticket',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'ans_tb_perm',
				'callback'            => 'ans_omt_route',
				'args'                => array(
					'to_product_id'          => array( 'type' => 'integer', 'required' => true ),
					'item_id'                => array( 'type' => 'integer', 'required' => false ),
					'reason'                 => array( 'type' => 'string',  'required' => false ),
					'dry_run'                => array( 'type' => 'boolean', 'required' => false ),
					'confirm_production'     => array( 'type' => 'boolean', 'required' => false ),
					'allow_price_difference' => array( 'type' => 'boolean', 'required' => false ),
				),
			)
		);
	}
);
