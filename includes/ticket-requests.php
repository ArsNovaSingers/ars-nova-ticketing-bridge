<?php
/**
 * Special requests — the escape hatch for a sold-out tier.
 *
 * WHY THIS EXISTS. Once a performance's free-ticket allocation is exhausted the
 * picker refuses to add another, and it should: a hard limit that quietly bends
 * is not a limit. But the limit is a number somebody typed in August and the
 * person reading the page is a real family in October. Jonathan, 2026-09-20:
 * "computers like hard limits but reality may be different."
 *
 * So the cap stays absolute at the cart, and beside the refusal there is a door:
 * the buyer asks, a human decides, and the decision is one click.
 *
 * THE SHAPE, and each part is deliberate:
 *   - The request is a RECORD (ans_ticket_request), not an email. An email can
 *     be lost, replied to twice, or actioned by two people. A record has one
 *     status, so approving twice is impossible and the ledger survives.
 *   - Kim is emailed at info@, with Approve and Decline links.
 *   - Approving REQUIRES BEING LOGGED IN as someone who can manage the shop.
 *     The token identifies the request; it is not the authority. A forwarded
 *     email therefore cannot issue a free ticket. Jonathan's call, 2026-09-20.
 *   - Approving RAISES THE CAP by the approved quantity before issuing, so the
 *     allocation keeps meaning "seats actually given away". Issuing outside the
 *     cap would drive WooCommerce stock negative and the next save of the
 *     project screen would silently recompute it away.
 *   - Issuance goes through ans_comp_issue(). We do not write a second ticket
 *     factory. That function already suppresses Mailchimp, disarms the Bridge's
 *     own duplicate-generation hook, verifies by read-back and completes the
 *     order last so the email carries the PDF.
 *
 * FAILS CLOSED, NOT OPEN. If ans-comp-tickets is missing, approval records the
 * decision and tells the approver to issue by hand rather than pretending. If
 * the portal is missing there is no allocation, so nothing is ever sold out and
 * this file is never reached.
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Post type holding one request. */
const ANS_TR_CPT = 'ans_ticket_request';

/** How long an Approve link stays good. */
const ANS_TR_TTL = 14 * DAY_IN_SECONDS;

/** Most seats one request may ask for. */
const ANS_TR_MAX_QTY = 4;

/**
 * Where request notifications go.
 *
 * Filterable so a season can redirect them without a release.
 *
 * @return string
 */
function ans_tr_notify_email() {
	return (string) apply_filters( 'ans_tr_notify_email', 'info@arsnovasingers.org' );
}

/**
 * Capability required to approve or decline.
 *
 * @return string
 */
function ans_tr_capability() {
	return (string) apply_filters( 'ans_tr_capability', 'manage_woocommerce' );
}

/**
 * Register the record type.
 *
 * Not public and not in REST: these carry a requester's name and email, and
 * nothing outside wp-admin has any business listing them.
 */
add_action(
	'init',
	function () {
		register_post_type(
			ANS_TR_CPT,
			array(
				'labels'          => array(
					'name'          => 'Ticket Requests',
					'singular_name' => 'Ticket Request',
					'menu_name'     => 'Ticket Requests',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'woocommerce',
				'show_in_rest'    => false,
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
				'supports'        => array( 'title' ),
				'has_archive'     => false,
				'rewrite'         => false,
			)
		);
	}
);

/**
 * The token stored against a request.
 *
 * Derived from the request id, the action and the site's auth salt, so nothing
 * has to be stored in a guessable form and a token for "approve" cannot be
 * replayed as "decline".
 *
 * @param int    $request_id Request post ID.
 * @param string $action     approve|decline.
 * @return string
 */
function ans_tr_token( $request_id, $action ) {
	return hash_hmac( 'sha256', (int) $request_id . '|' . $action, wp_salt( 'auth' ) );
}

/**
 * Is this token good for this request and action?
 *
 * @param int    $request_id Request post ID.
 * @param string $action     approve|decline.
 * @param string $given      Token from the URL.
 * @return bool
 */
function ans_tr_token_ok( $request_id, $action, $given ) {
	return hash_equals( ans_tr_token( $request_id, $action ), (string) $given );
}

/**
 * The action URL that goes in Kim's email.
 *
 * @param int    $request_id Request post ID.
 * @param string $action     approve|decline.
 * @return string
 */
function ans_tr_action_url( $request_id, $action ) {
	return add_query_arg(
		array(
			'ans_tr'  => $action,
			'rid'     => (int) $request_id,
			'token'   => ans_tr_token( $request_id, $action ),
		),
		home_url( '/' )
	);
}

/**
 * Public route: a buyer asks for a seat on a sold-out tier.
 *
 * Public by necessity — the person asking is not logged in and never will be.
 * Four guards instead of an account: a nonce minted into the page, a honeypot,
 * a per-IP rate limit, and validation that the product really is the ticket it
 * claims to be for the event it claims to be for.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'ars-nova/v1',
			'/ticket-request',
			array(
				'methods'             => 'POST',
				'callback'            => 'ans_tr_rest_create',
				'permission_callback' => '__return_true',
			)
		);
	}
);

/**
 * Create a request.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function ans_tr_rest_create( $req ) {
	$body = $req->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = $req->get_params();
	}

	// 1. Honeypot. A real browser leaves this empty; most bots fill everything.
	if ( ! empty( $body['website'] ) ) {
		return new WP_Error( 'ans_tr_rejected', 'Could not send that request.', array( 'status' => 400 ) );
	}

	// 2. Nonce minted into the picker's payload.
	if ( ! wp_verify_nonce( (string) ( $body['nonce'] ?? '' ), 'ans_tr' ) ) {
		return new WP_Error(
			'ans_tr_stale',
			'This page has been open a while. Please refresh and try again.',
			array( 'status' => 403 )
		);
	}

	// 3. Rate limit by IP. Generous for a household, useless for a flood.
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
	$key = 'ans_tr_rl_' . md5( $ip );
	$hit = (int) get_transient( $key );
	if ( $hit >= 5 ) {
		return new WP_Error(
			'ans_tr_rate',
			'That is several requests in a short time. Please email info@arsnovasingers.org directly.',
			array( 'status' => 429 )
		);
	}

	// 4. The request has to be about a real ticket on a real published event.
	$product_id = absint( $body['product_id'] ?? 0 );
	$event_id   = absint( $body['event_id'] ?? 0 );
	$product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

	if ( ! $product || 'yes' !== get_post_meta( $product_id, '_tc_is_ticket', true ) ) {
		return new WP_Error( 'ans_tr_bad_product', 'That ticket could not be found.', array( 'status' => 400 ) );
	}
	if ( (int) get_post_meta( $product_id, '_event_name', true ) !== $event_id ) {
		return new WP_Error( 'ans_tr_mismatch', 'That ticket is not for that performance.', array( 'status' => 400 ) );
	}
	if ( 'publish' !== get_post_status( $event_id ) ) {
		return new WP_Error( 'ans_tr_event', 'That performance is not on sale.', array( 'status' => 400 ) );
	}

	$email = sanitize_email( (string) ( $body['email'] ?? '' ) );
	$name  = trim( wp_strip_all_tags( (string) ( $body['name'] ?? '' ) ) );
	$qty   = max( 1, min( ANS_TR_MAX_QTY, absint( $body['qty'] ?? 1 ) ) );
	$msg   = sanitize_textarea_field( (string) ( $body['message'] ?? '' ) );
	$msg   = function_exists( 'mb_substr' ) ? mb_substr( $msg, 0, 500 ) : substr( $msg, 0, 500 );

	if ( ! is_email( $email ) ) {
		return new WP_Error( 'ans_tr_email', 'Please give an email address we can reply to.', array( 'status' => 400 ) );
	}
	if ( '' === $name ) {
		return new WP_Error( 'ans_tr_name', 'Please give your name.', array( 'status' => 400 ) );
	}

	set_transient( $key, $hit + 1, HOUR_IN_SECONDS );

	return ans_tr_store_and_notify( $event_id, $product_id, $qty, $name, $email, $msg );
}

/**
 * Write the record and email the box office.
 *
 * @param int    $event_id   tc_events post ID.
 * @param int    $product_id Ticket product ID.
 * @param int    $qty        Seats asked for.
 * @param string $name       Requester name.
 * @param string $email      Requester email.
 * @param string $msg        Their message.
 * @return WP_REST_Response|WP_Error
 */
function ans_tr_store_and_notify( $event_id, $product_id, $qty, $name, $email, $msg ) {
	$tier  = (string) get_post_meta( $product_id, '_ans_tier', true );
	$label = $tier ? ucfirst( $tier ) : 'ticket';

	$request_id = wp_insert_post(
		array(
			'post_type'   => ANS_TR_CPT,
			'post_status' => 'publish',
			'post_title'  => sprintf( '%s — %s × %d (%s)', $name, $label, $qty, get_the_title( $event_id ) ),
		),
		true
	);

	if ( is_wp_error( $request_id ) || ! $request_id ) {
		return new WP_Error( 'ans_tr_store', 'Could not record that request.', array( 'status' => 500 ) );
	}

	update_post_meta( $request_id, '_ans_tr_event', (int) $event_id );
	update_post_meta( $request_id, '_ans_tr_product', (int) $product_id );
	update_post_meta( $request_id, '_ans_tr_tier', $tier );
	update_post_meta( $request_id, '_ans_tr_qty', (int) $qty );
	update_post_meta( $request_id, '_ans_tr_name', $name );
	update_post_meta( $request_id, '_ans_tr_email', $email );
	update_post_meta( $request_id, '_ans_tr_message', $msg );
	update_post_meta( $request_id, '_ans_tr_status', 'pending' );
	update_post_meta( $request_id, '_ans_tr_created', time() );

	ans_tr_send_notification( $request_id );

	return rest_ensure_response(
		array(
			'ok'      => true,
			'message' => 'Thank you — your request has gone to our box office. We will be in touch by email.',
		)
	);
}

/**
 * Email the box office with one-click Approve / Decline.
 *
 * Reply-To is the requester, so hitting reply in any mail client talks to the
 * family rather than to the website.
 *
 * @param int $request_id Request post ID.
 * @return bool
 */
function ans_tr_send_notification( $request_id ) {
	$event   = (int) get_post_meta( $request_id, '_ans_tr_event', true );
	$qty     = (int) get_post_meta( $request_id, '_ans_tr_qty', true );
	$tier    = (string) get_post_meta( $request_id, '_ans_tr_tier', true );
	$name    = (string) get_post_meta( $request_id, '_ans_tr_name', true );
	$email   = (string) get_post_meta( $request_id, '_ans_tr_email', true );
	$msg     = (string) get_post_meta( $request_id, '_ans_tr_message', true );
	$label   = $tier ? ucfirst( $tier ) : 'ticket';
	$when    = function_exists( 'ans_tb_event_ts' ) ? ans_tb_event_ts( $event ) : 0;
	$whenstr = $when ? wp_date( 'D j M Y, ' . get_option( 'time_format' ), $when ) : '';
	$venue   = (string) get_post_meta( $event, 'event_location', true );

	$subject = sprintf( '[Ars Nova] %s is asking for %d more %s ticket%s', $name, $qty, strtolower( $label ), 1 === $qty ? '' : 's' );

	$rows = array(
		'Who'         => $name . ' (' . $email . ')',
		'Asking for'  => sprintf( '%d × %s', $qty, $label ),
		'Performance' => trim( get_the_title( $event ) . ( $whenstr ? ' — ' . $whenstr : '' ) . ( $venue ? ' — ' . $venue : '' ) ),
	);

	$html  = '<p style="font:16px/1.5 Georgia,serif">';
	$html .= esc_html( $name ) . ' tried to book ' . (int) $qty . ' ' . esc_html( strtolower( $label ) );
	$html .= ' ticket' . ( 1 === $qty ? '' : 's' ) . ', but that tier is fully allocated for this performance.';
	$html .= ' They are asking whether we can make room.</p>';

	$html .= '<table style="font:15px/1.5 Georgia,serif;border-collapse:collapse">';
	foreach ( $rows as $k => $v ) {
		$html .= '<tr><td style="padding:4px 14px 4px 0;color:#666;vertical-align:top">' . esc_html( $k ) . '</td>';
		$html .= '<td style="padding:4px 0"><strong>' . esc_html( $v ) . '</strong></td></tr>';
	}
	$html .= '</table>';

	if ( '' !== $msg ) {
		$html .= '<p style="font:15px/1.5 Georgia,serif;background:#f6f4ef;padding:12px 14px;border-left:3px solid #d9d2c2">';
		$html .= nl2br( esc_html( $msg ) ) . '</p>';
	}

	$approve = ans_tr_action_url( $request_id, 'approve' );
	$decline = ans_tr_action_url( $request_id, 'decline' );

	$html .= '<p style="margin:26px 0 8px">';
	$html .= '<a href="' . esc_url( $approve ) . '" style="background:#14304d;color:#fff;text-decoration:none;';
	$html .= 'padding:13px 22px;border-radius:4px;font:bold 16px Georgia,serif;display:inline-block">';
	$html .= 'Approve &amp; send the ticket' . ( $qty > 1 ? 's' : '' ) . '</a>';
	$html .= '&nbsp;&nbsp;<a href="' . esc_url( $decline ) . '" style="color:#7a7a7a;font:15px Georgia,serif">Decline</a>';
	$html .= '</p>';

	$html .= '<p style="font:13px/1.5 Georgia,serif;color:#777">Approving raises this performance&rsquo;s allocation by ';
	$html .= (int) $qty . ' and emails the ticket' . ( 1 === $qty ? '' : 's' ) . ' straight to ' . esc_html( $email ) . '.';
	$html .= ' You will be asked to sign in first if you are not already — the link on its own cannot issue a ticket.';
	$html .= ' It works once, and expires in 14 days.</p>';

	return wp_mail(
		ans_tr_notify_email(),
		$subject,
		$html,
		array(
			'Content-Type: text/html; charset=UTF-8',
			'Reply-To: ' . $name . ' <' . $email . '>',
		)
	);
}

/**
 * Handle an Approve / Decline click.
 *
 * Runs on template_redirect so auth_redirect() can still send an unauthenticated
 * approver to wp-login and bring them straight back here afterwards. That is the
 * whole reason this is not an admin-post action: admin-post has no graceful
 * logged-out path, and Kim reading mail on a phone is the normal case.
 *
 * Order of checks matters. The token is verified BEFORE the login prompt, so a
 * junk link does not bounce a logged-out stranger through a login screen first.
 */
add_action(
	'template_redirect',
	function () {
		$action = isset( $_GET['ans_tr'] ) ? sanitize_key( wp_unslash( $_GET['ans_tr'] ) ) : '';

		if ( 'approve' !== $action && 'decline' !== $action ) {
			return;
		}

		$request_id = isset( $_GET['rid'] ) ? absint( wp_unslash( $_GET['rid'] ) ) : 0;
		$token      = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( ! $request_id || ANS_TR_CPT !== get_post_type( $request_id ) ) {
			ans_tr_screen( 'Not found', 'That request no longer exists.' );
		}

		if ( ! ans_tr_token_ok( $request_id, $action, $token ) ) {
			ans_tr_screen( 'Link not valid', 'That link does not match this request. It may have been altered in transit.' );
		}

		$created = (int) get_post_meta( $request_id, '_ans_tr_created', true );
		if ( $created && ( time() - $created ) > ANS_TR_TTL ) {
			ans_tr_screen( 'Link expired', 'This link is more than 14 days old. Open the request under WooCommerce &rsaquo; Ticket Requests instead.' );
		}

		$status = (string) get_post_meta( $request_id, '_ans_tr_status', true );
		if ( 'pending' !== $status ) {
			$who = (int) get_post_meta( $request_id, '_ans_tr_actor', true );
			$by  = $who ? get_userdata( $who ) : null;
			ans_tr_screen(
				'Already handled',
				sprintf(
					'This request was already marked <strong>%s</strong>%s. Nothing has been changed.',
					esc_html( $status ),
					$by ? ' by ' . esc_html( $by->display_name ) : ''
				)
			);
		}

		// Identity, not the link, is the authority. Sends to wp-login and back.
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		if ( ! current_user_can( ans_tr_capability() ) ) {
			ans_tr_screen( 'Not permitted', 'Your account cannot approve ticket requests. Ask Jonathan or Kim.' );
		}

		if ( 'decline' === $action ) {
			update_post_meta( $request_id, '_ans_tr_status', 'declined' );
			update_post_meta( $request_id, '_ans_tr_actor', get_current_user_id() );
			update_post_meta( $request_id, '_ans_tr_handled', time() );
			ans_tr_screen(
				'Declined',
				'Recorded. <strong>No email has been sent to them</strong> — if you want to say something, reply to the notification email; it goes straight to the requester.'
			);
		}

		ans_tr_approve( $request_id );
	}
);

/**
 * Approve: raise the cap, then issue through the comp engine.
 *
 * The cap is raised FIRST and deliberately. Issuing outside it would take
 * WooCommerce stock to -1, and the next save of the Singers Hub project screen
 * recomputes stock as (allocation - issued) and would erase the extra seat.
 * Raising the allocation keeps that arithmetic true and leaves the number
 * meaning what it says: seats actually given away.
 *
 * @param int $request_id Request post ID.
 * @return void Always ends the request.
 */
function ans_tr_approve( $request_id ) {
	$event_id   = (int) get_post_meta( $request_id, '_ans_tr_event', true );
	$product_id = (int) get_post_meta( $request_id, '_ans_tr_product', true );
	$tier       = (string) get_post_meta( $request_id, '_ans_tr_tier', true );
	$qty        = max( 1, (int) get_post_meta( $request_id, '_ans_tr_qty', true ) );
	$name       = (string) get_post_meta( $request_id, '_ans_tr_name', true );
	$email      = (string) get_post_meta( $request_id, '_ans_tr_email', true );

	// 1. Raise the cap, if there is one and the portal is here to hold it.
	$raised = false;
	if ( $tier && class_exists( 'ANSP_Free_Allocation' ) ) {
		$current = ANSP_Free_Allocation::get_allocation( $event_id, $tier );
		if ( null !== $current ) {
			ANSP_Free_Allocation::set_allocation( $event_id, $tier, (int) $current + $qty );
			$raised = true;
		}
	}

	// 2. Issue. We do not write a second ticket factory.
	if ( ! function_exists( 'ans_comp_issue' ) ) {
		update_post_meta( $request_id, '_ans_tr_status', 'approved-manual' );
		update_post_meta( $request_id, '_ans_tr_actor', get_current_user_id() );
		update_post_meta( $request_id, '_ans_tr_handled', time() );
		ans_tr_screen(
			'Approved — issue it by hand',
			'The allocation was raised, but the comp-ticket plugin is not active, so nothing was sent. Issue the ticket from WooCommerce and it will go out normally.'
		);
	}

	$result = ans_comp_issue(
		array(
			'performance_id'  => $product_id,
			'qty'             => $qty,
			'recipient_name'  => $name,
			'recipient_email' => $email,
			'reason'          => sprintf( 'Special request #%d approved — tier was fully allocated.', (int) $request_id ),
			'source'          => 'special-request',
			'issued_by'       => get_current_user_id(),
			'recipient_note'  => 'We were glad to make room for you — here is your ticket. See you at the concert.',
		)
	);

	if ( is_wp_error( $result ) ) {
		// Put the cap back. A failed issue must not leave a phantom seat behind.
		if ( $raised && class_exists( 'ANSP_Free_Allocation' ) ) {
			$now = ANSP_Free_Allocation::get_allocation( $event_id, $tier );
			if ( null !== $now ) {
				ANSP_Free_Allocation::set_allocation( $event_id, $tier, max( 0, (int) $now - $qty ) );
			}
		}

		update_post_meta( $request_id, '_ans_tr_status', 'failed' );
		update_post_meta( $request_id, '_ans_tr_error', $result->get_error_message() );

		ans_tr_screen(
			'Could not issue it',
			'Nothing was sent and the allocation was put back as it was.<br /><br /><code>'
			. esc_html( $result->get_error_message() ) . '</code>'
		);
	}

	update_post_meta( $request_id, '_ans_tr_status', 'approved' );
	update_post_meta( $request_id, '_ans_tr_actor', get_current_user_id() );
	update_post_meta( $request_id, '_ans_tr_handled', time() );
	update_post_meta( $request_id, '_ans_tr_order', (int) $result['order_id'] );

	ans_tr_screen(
		'Done',
		sprintf(
			'%d ticket%s on its way to <strong>%s</strong>.<br />Order #%d. This performance&rsquo;s %s allocation went up by %d, so the numbers still add up.',
			$qty,
			1 === $qty ? '' : 's',
			esc_html( $email ),
			(int) $result['order_id'],
			esc_html( $tier ? $tier : 'ticket' ),
			$qty
		)
	);
}

/**
 * A plain confirmation page. Ends the request.
 *
 * Deliberately not wp_die()'s red box — an approver on a phone should see
 * something that looks like the organisation, not a fatal error.
 *
 * @param string $title Heading.
 * @param string $body  HTML body, already escaped by the caller.
 * @return void
 */
function ans_tr_screen( $title, $body ) {
	$html  = '<div style="max-width:34em;margin:14vh auto;padding:0 6vw;font:17px/1.6 Georgia,serif;color:#14304d">';
	$html .= '<h1 style="font-size:1.6em;font-weight:normal;margin:0 0 .6em">' . esc_html( $title ) . '</h1>';
	$html .= '<p style="margin:0 0 1.6em">' . $body . '</p>';
	$html .= '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . ANS_TR_CPT ) ) . '" style="color:#14304d">';
	$html .= 'All ticket requests</a></p></div>';

	wp_die(
		$html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled and escaped above.
		esc_html( $title ),
		array( 'response' => 200 )
	);
}

/**
 * Status and contact at a glance on the request list.
 */
add_filter(
	'manage_' . ANS_TR_CPT . '_posts_columns',
	function ( $cols ) {
		$out = array( 'cb' => $cols['cb'] ?? '', 'title' => 'Request' );
		$out['ans_tr_status']  = 'Status';
		$out['ans_tr_contact'] = 'Contact';
		$out['ans_tr_order']   = 'Order';
		$out['date']           = $cols['date'] ?? 'Date';
		return $out;
	}
);

add_action(
	'manage_' . ANS_TR_CPT . '_posts_custom_column',
	function ( $col, $post_id ) {
		switch ( $col ) {
			case 'ans_tr_status':
				$s = (string) get_post_meta( $post_id, '_ans_tr_status', true );
				$c = array(
					'pending'  => '#b26a00',
					'approved' => '#1a7f37',
					'declined' => '#777',
					'failed'   => '#b32d2e',
				);
				echo '<strong style="color:' . esc_attr( $c[ $s ] ?? '#333' ) . '">' . esc_html( $s ? $s : '—' ) . '</strong>';
				$err = (string) get_post_meta( $post_id, '_ans_tr_error', true );
				if ( $err ) {
					echo '<br /><span class="description">' . esc_html( $err ) . '</span>';
				}
				break;

			case 'ans_tr_contact':
				$e = (string) get_post_meta( $post_id, '_ans_tr_email', true );
				echo $e ? '<a href="mailto:' . esc_attr( $e ) . '">' . esc_html( $e ) . '</a>' : '—';
				break;

			case 'ans_tr_order':
				$o = (int) get_post_meta( $post_id, '_ans_tr_order', true );
				if ( $o ) {
					echo '<a href="' . esc_url( admin_url( 'post.php?post=' . $o . '&action=edit' ) ) . '">#' . (int) $o . '</a>';
				} else {
					echo '—';
				}
				break;
		}
	},
	10,
	2
);
