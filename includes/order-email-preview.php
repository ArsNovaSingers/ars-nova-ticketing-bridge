<?php
/**
 * Ars Nova Ticketing Bridge - render a real order email without sending it.
 *
 * WHY THIS EXISTS
 * ---------------
 * v1.16.0 rewrote what the confirmation email contains, and there was no way to
 * look at the result. Nothing in any connector can trigger a WooCommerce order
 * email, there is no shell on the Kinsta boxes, and the only alternative was to
 * ask a human to click "Resend order emails" in wp-admin and read their inbox.
 * That is not a test anybody will run twice, so email changes were effectively
 * being shipped unseen.
 *
 * This renders the actual WC_Email - same class, same template, same hooks - and
 * hands back the HTML. Because it goes through WC_Email::get_content(), the
 * template fires woocommerce_email_before_order_table and every other hook for
 * real. It is the email, not a reconstruction of it.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 * It never sends to the customer. GET renders only. POST sends, but ONLY to an
 * address the caller names explicitly - there is no "send to the buyer" path,
 * because the whole point is to test without touching a patron. Sending is
 * wp_mail() with the rendered body rather than $email->trigger(), so no order
 * state changes and no WooCommerce "email sent" bookkeeping is faked.
 *
 * SECURITY NOTE
 * -------------
 * The rendered HTML contains ticket download URLs, which are bearer links with no
 * expiry (see ticket-downloads.php). This route is admin-only via ans_tb_perm,
 * the same gate as every other route in this plugin. Do not relax that.
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.17.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch the live WC_Email object by its id.
 *
 * Keyed lookup on get_emails() uses CLASS names ('WC_Email_Customer_Completed_Order'),
 * which drift; matching on ->id is stable and is what every hook uses.
 *
 * @param string $email_id e.g. 'customer_completed_order'.
 * @return WC_Email|null
 */
function ans_tb_get_wc_email( $email_id ) {
	if ( ! function_exists( 'WC' ) || ! is_object( WC() ) ) {
		return null;
	}
	$mailer = WC()->mailer(); // also runs init_transactional_emails()
	if ( ! is_object( $mailer ) || ! method_exists( $mailer, 'get_emails' ) ) {
		return null;
	}
	foreach ( (array) $mailer->get_emails() as $obj ) {
		if ( is_object( $obj ) && isset( $obj->id ) && $obj->id === $email_id ) {
			return $obj;
		}
	}
	return null;
}

/**
 * Render one order email to HTML, plus the attachments it would carry.
 *
 * @param int    $order_id WooCommerce order ID.
 * @param string $email_id WC_Email id.
 * @return array|WP_Error
 */
function ans_tb_render_order_email( $order_id, $email_id = 'customer_completed_order' ) {
	$order_id = (int) $order_id;
	$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	if ( ! $order ) {
		return new WP_Error( 'no_order', 'Order not found.', array( 'status' => 404 ) );
	}

	$email = ans_tb_get_wc_email( $email_id );
	if ( ! $email ) {
		return new WP_Error( 'no_email', 'No WC_Email with id ' . $email_id, array( 'status' => 400 ) );
	}

	// WC_Email reads these off itself while rendering.
	$email->object    = $order;
	$email->recipient = $order->get_billing_email();
	if ( method_exists( $email, 'get_placeholders' ) && property_exists( $email, 'placeholders' ) ) {
		$email->placeholders = array_merge(
			(array) $email->placeholders,
			array(
				'{order_date}'   => wc_format_datetime( $order->get_date_created() ),
				'{order_number}' => $order->get_order_number(),
			)
		);
	}

	/*
	 * An email class that fills its own placeholders gets to do so. WC_Email
	 * populates most of them inside trigger(), which this route deliberately
	 * never calls - so anything an email computes for itself (our reminder
	 * derives {event_title} and {days_until} from the order's linked event) was
	 * simply absent, and the subject rendered as the default template with every
	 * token blank: "Reminder:  is ".
	 *
	 * Discovered 2026-09-12 while verifying the venue chain. The real send was
	 * correct throughout; only this diagnostic lied. A diagnostic that
	 * misreports the subject is worse than none - it invites somebody to fix a
	 * subject that was never broken.
	 *
	 * method_exists rather than a class check, so any future email can opt in by
	 * offering the same method.
	 */
	if ( method_exists( $email, 'populate_placeholders' ) ) {
		$email->populate_placeholders( $order );
	}

	$subject = '';
	$html    = '';
	try {
		$subject = $email->get_subject();
		$html    = $email->get_content();
		if ( method_exists( $email, 'style_inline' ) ) {
			$html = $email->style_inline( $html );
		}
	} catch ( Throwable $e ) {
		return new WP_Error( 'render_failed', $e->getMessage(), array( 'status' => 500 ) );
	}

	// Exactly what woocommerce_email_attachments would produce for this send.
	$attachments = apply_filters( 'woocommerce_email_attachments', array(), $email_id, $order, $email );
	$attach_info = array();
	foreach ( (array) $attachments as $path ) {
		$attach_info[] = array(
			'file'   => basename( (string) $path ),
			'exists' => file_exists( $path ),
			'bytes'  => file_exists( $path ) ? filesize( $path ) : 0,
		);
	}

	return array(
		'order_id'     => $order_id,
		'order_status' => $order->get_status(),
		'email_id'     => $email_id,
		'email_type'   => method_exists( $email, 'get_email_type' ) ? $email->get_email_type() : '',
		'subject'      => $subject,
		'attachments'  => $attach_info,
		'events'       => function_exists( 'ans_tb_order_events' ) ? ans_tb_order_events( $order_id ) : array(),
		'html'         => $html,
	);
}

add_action( 'rest_api_init', function () {

	/**
	 * GET  ars-nova/v1/order/{id}/email-preview            - render, send nothing
	 * POST ars-nova/v1/order/{id}/email-preview {to: "..."} - send that render to ONE named address
	 *
	 * ?html=1 on the GET returns raw HTML instead of JSON, so it can be opened in
	 * a browser tab and actually looked at.
	 */
	register_rest_route( ANS_TB_NS, '/order/(?P<id>\d+)/email-preview', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'ans_tb_perm',
			'callback'            => function ( $req ) {
				$out = ans_tb_render_order_email(
					(int) $req['id'],
					(string) ( $req->get_param( 'email_id' ) ?: 'customer_completed_order' )
				);
				if ( is_wp_error( $out ) ) {
					return $out;
				}
				if ( $req->get_param( 'html' ) ) {
					$resp = new WP_REST_Response( $out['html'] );
					$resp->header( 'Content-Type', 'text/html; charset=utf-8' );
					return $resp;
				}
				$out['note'] = 'Nothing was sent. POST with {"to":"you@example.com"} to mail this render.';
				return $out;
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => 'ans_tb_perm',
			'callback'            => function ( $req ) {
				$to = sanitize_email( (string) $req->get_param( 'to' ) );
				if ( ! $to || ! is_email( $to ) ) {
					return new WP_Error(
						'missing_to',
						'A valid "to" address is required. This route never mails the customer.',
						array( 'status' => 400 )
					);
				}

				$out = ans_tb_render_order_email(
					(int) $req['id'],
					(string) ( $req->get_param( 'email_id' ) ?: 'customer_completed_order' )
				);
				if ( is_wp_error( $out ) ) {
					return $out;
				}

				$paths = array();
				foreach ( $out['attachments'] as $a ) {
					if ( ! empty( $a['exists'] ) ) {
						$upload  = wp_upload_dir();
						$paths[] = trailingslashit( $upload['basedir'] ) . 'tc-tmp/' . $a['file'];
					}
				}

				$subject = '[PREVIEW] ' . $out['subject'];
				$sent    = wp_mail(
					$to,
					$subject,
					$out['html'],
					array( 'Content-Type: text/html; charset=UTF-8' ),
					$paths
				);

				return array(
					'sent'        => (bool) $sent,
					'to'          => $to,
					'subject'     => $subject,
					'order_id'    => $out['order_id'],
					'email_id'    => $out['email_id'],
					'attachments' => $out['attachments'],
					'note'        => 'Sent only to the address named above. The customer was not contacted.',
				);
			},
		),
	) );
} );
