<?php
/**
 * Event reminder email.
 *
 * A concert reminder is not an order notification, and reusing
 * customer_completed_order for one was the wrong answer: its subject
 * ("Your order from Ars Nova Singers is on its way!") and heading
 * ("Good things are heading your way!") read as a fresh purchase. Sent the
 * night before a concert to buyers who had already been rattled by the
 * September checkout failures, that invites "have I been charged twice?".
 *
 * So this is its own WC_Email with its own id, which buys three things:
 * subject, heading and intro are editable by staff under WooCommerce >
 * Settings > Emails with no developer involved; the reminder can be sent
 * without touching order status; and it cannot be confused with a receipt.
 *
 * It deliberately REUSES rather than reimplements:
 *   - the navy event block (date / location / map / concert link) via the
 *     woocommerce_email_before_order_table action, by opting this email id
 *     into ans_tb_email_carries_ticket();
 *   - the ticket PDF attachments, gated by that same filter;
 *
 * It deliberately does NOT render the order table. woocommerce_email_order_details
 * brings prices, subtotal, total, payment method and the billing address with it,
 * which is how the first build ended up looking like a second charge. A reminder
 * shows the event and the ticket, never the transaction.
 *
 * One thing it cannot reuse. bridge-for-woocommerce renders the "Tickets:"
 * table on woocommerce_email_after_order_table, but gates it on a hard-coded
 * list of email ids AND THEN branches on those same ids again. Adding our id
 * to tc_add_content_email_after_order_table_allowed_email_type_ids would pass
 * the gate and match no branch, rendering nothing - registered but not
 * effective. So the table is rendered here directly from Tickera's own
 * tickera_get_tickets_table_email(), which is the function the bridge itself
 * calls. If the bridge ever learns this email id, set the filter
 * ans_tb_reminder_render_tickets_table to false rather than getting two.
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.24.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opt the reminder into the ticket PDF attachment and the event block.
 *
 * Both ans_tb_email_attach_tickets() and ans_tb_email_event_block() gate on
 * ans_tb_email_carries_ticket(), so this single line buys both.
 */
add_filter( 'ans_tb_email_ticket_email_ids', function ( $ids ) {
	$ids[] = 'ans_event_reminder';
	return $ids;
} );

/**
 * Describe the gap between now and the event in words a reader uses.
 *
 * Returns 'tomorrow', 'today', 'in 3 days' and so on. Day boundaries are
 * compared in SITE time, not by dividing a difference in seconds: an event at
 * 5pm tomorrow is "tomorrow" whether it is now 9pm or 9am.
 *
 * @param int $event_ts Unix timestamp of the event.
 * @return string
 */
function ans_tb_days_until_phrase( $event_ts ) {
	if ( ! $event_ts ) {
		return '';
	}
	$tz    = wp_timezone();
	$now   = new DateTimeImmutable( 'now', $tz );
	$then  = ( new DateTimeImmutable( '@' . (int) $event_ts ) )->setTimezone( $tz );
	$days  = (int) $now->setTime( 0, 0 )->diff( $then->setTime( 0, 0 ) )->format( '%r%a' );

	if ( 0 === $days ) {
		return 'today';
	}
	if ( 1 === $days ) {
		return 'tomorrow';
	}
	if ( $days < 0 ) {
		return '';
	}
	return sprintf( 'in %d days', $days );
}

/**
 * Register the email with WooCommerce.
 *
 * The class is declared inside the callback because WC_Email does not exist
 * when this plugin's includes are required - WooCommerce loads its mailer
 * lazily, and extending a class that is not yet defined is a fatal error.
 *
 * @param array $emails WC_Email instances keyed by class name.
 * @return array
 */
add_filter( 'woocommerce_email_classes', 'ans_tb_register_reminder_email' );
function ans_tb_register_reminder_email( $emails ) {

	if ( ! class_exists( 'WC_Email' ) || class_exists( 'ANS_TB_Email_Event_Reminder' ) ) {
		return is_array( $emails ) ? $emails : array();
	}

	/**
	 * The concert reminder.
	 */
	class ANS_TB_Email_Event_Reminder extends WC_Email {

		public function __construct() {
			$this->id             = 'ans_event_reminder';
			$this->customer_email = true;
			$this->title          = 'Event reminder';
			$this->description    = 'Sent to ticket buyers ahead of a performance. Carries their ticket PDF, '
				. 'the venue and the directions. Not triggered by order status - it is sent deliberately, '
				. 'per performance, from the Ars Nova tooling.';

			$this->placeholders = array(
				'{event_title}' => '',
				'{event_date}'  => '',
				'{event_time}'  => '',
				'{days_until}'  => '',
				'{order_number}' => '',
				'{first_name}'  => '',
			);

			parent::__construct();
		}

		public function get_default_subject() {
			return 'Reminder: {event_title} is {days_until}';
		}

		public function get_default_heading() {
			return '{event_title}';
		}

		/**
		 * Default opening paragraph. Editable in WooCommerce > Settings > Emails.
		 */
		public function get_default_intro() {
			return 'A reminder about your upcoming concert. Everything you need is below, '
				. 'and your ticket is attached to this email as a PDF.';
		}

		public function get_intro() {
			$intro = $this->get_option( 'intro', $this->get_default_intro() );
			return $this->format_string( $intro );
		}

		/**
		 * Adds the intro field. Everything else is WooCommerce's standard set.
		 */
		public function init_form_fields() {
			parent::init_form_fields();

			$fields = $this->form_fields;
			$after  = array();

			foreach ( $fields as $key => $field ) {
				$after[ $key ] = $field;
				if ( 'heading' === $key ) {
					$after['lead'] = array(
						'title'       => 'Ticket-block sentence',
						'type'        => 'textarea',
						'desc_tip'    => true,
						'description' => 'The line inside the navy ticket box, above the date and venue.',
						'placeholder' => $this->get_default_lead(),
						'default'     => $this->get_default_lead(),
						'css'         => 'width:400px; height:60px;',
					);
					$after['intro'] = array(
						'title'       => 'Opening paragraph',
						'type'        => 'textarea',
						'desc_tip'    => true,
						'description' => 'The first paragraph of the email. Available placeholders: '
							. '{event_title}, {event_date}, {event_time}, {days_until}, {first_name}, {order_number}',
						'placeholder' => $this->get_default_intro(),
						'default'     => $this->get_default_intro(),
						'css'         => 'width:400px; height:80px;',
					);
				}
			}

			$this->form_fields = $after;
		}

		/**
		 * Populate placeholders and send to the customer on the order.
		 *
		 * Returns false rather than throwing when the order is missing, not
		 * paid, or has no live ticket instances. A reminder for a cancelled or
		 * refunded order is worse than no reminder, and refund-voids-tickets.php
		 * trashes instances on refund - so "has live tickets" is the honest test
		 * of whether this person is still coming.
		 *
		 * @param int $order_id
		 * @return bool True if WooCommerce accepted the send.
		 */
		public function trigger( $order_id ) {
			$this->setup_locale();

			$order = $order_id ? wc_get_order( $order_id ) : false;
			if ( ! $order ) {
				$this->restore_locale();
				return false;
			}

			$this->object    = $order;

			if ( ! ans_tb_reminder_order_is_sendable( $order ) ) {
				$this->restore_locale();
				return false;
			}
			$this->recipient = $order->get_billing_email();

			$events = function_exists( 'ans_tb_order_events' ) ? ans_tb_order_events( (int) $order_id ) : array();
			$first  = $events ? $events[0] : array();
			$ts     = 0;
			if ( ! empty( $first['id'] ) && function_exists( 'ans_tb_event_ts' ) ) {
				$ts = (int) ans_tb_event_ts( (int) $first['id'] );
			}

			$this->placeholders['{event_title}']  = isset( $first['title'] ) ? $first['title'] : '';
			$this->placeholders['{event_date}']   = $ts ? wp_date( 'l, F j', $ts ) : '';
			$this->placeholders['{event_time}']   = $ts ? wp_date( 'g:i a', $ts ) : '';
			$this->placeholders['{days_until}']   = ans_tb_days_until_phrase( $ts );
			$this->placeholders['{order_number}'] = $order->get_order_number();
			$this->placeholders['{first_name}']   = $order->get_billing_first_name();

			$ok = false;
			if ( $this->is_enabled() && $this->get_recipient() ) {
				$ok = $this->send(
					$this->get_recipient(),
					$this->get_subject(),
					$this->get_content(),
					$this->get_headers(),
					$this->get_attachments()
				);
			}

			$this->restore_locale();
			return (bool) $ok;
		}

		/**
		 * Swap the event block's lead sentence for reminder wording.
		 *
		 * ans_tb_email_event_block() opens with "Your ticket is attached to this
		 * email as a PDF" - true, but it reads like a receipt. The filter is
		 * added and removed around our own render so no other email is affected.
		 *
		 * PUBLIC, and it has to be: WP_Hook calls this through call_user_func_array
		 * from outside the class, so a private method is a fatal TypeError the
		 * moment the email renders. Caught on staging, which is the point of staging.
		 */
		public function get_default_lead() {
			return 'Your ticket is attached to this email as a PDF. You do not need to print it — '
				. 'we will have a guest list at the door.';
		}

		public function reminder_lead( $lead ) {
			return $this->format_string( $this->get_option( 'lead', $this->get_default_lead() ) );
		}

		public function get_content_html() {
			add_filter( 'ans_tb_email_lead_text', array( $this, 'reminder_lead' ), 20 );
			ob_start();
			do_action( 'woocommerce_email_header', $this->get_heading(), $this );
			echo ans_tb_reminder_richtext( $this->get_intro(), false );
			do_action( 'woocommerce_email_before_order_table', $this->object, false, false, $this );
			if ( apply_filters( 'ans_tb_reminder_render_tickets_table', true, $this->object ) ) {
				echo ans_tb_reminder_tickets_table( $this->object, false );
			}
			echo ans_tb_reminder_event_sections( $this->object, false );
			echo ans_tb_reminder_richtext( $this->get_additional_content(), false );
			do_action( 'woocommerce_email_footer', $this );
			$out = ob_get_clean();
			remove_filter( 'ans_tb_email_lead_text', array( $this, 'reminder_lead' ), 20 );
			return $out;
		}

		public function get_content_plain() {
			add_filter( 'ans_tb_email_lead_text', array( $this, 'reminder_lead' ), 20 );
			ob_start();
			echo strtoupper( $this->get_heading() ) . "\n\n";
			echo ans_tb_reminder_richtext( $this->get_intro(), true );
			do_action( 'woocommerce_email_before_order_table', $this->object, false, true, $this );
			if ( apply_filters( 'ans_tb_reminder_render_tickets_table', true, $this->object ) ) {
				echo ans_tb_reminder_tickets_table( $this->object, true );
			}
			echo ans_tb_reminder_event_sections( $this->object, true );
			echo ans_tb_reminder_richtext( $this->get_additional_content(), true );
			echo "\n" . wp_strip_all_tags( wptexturize( get_option( 'woocommerce_email_footer_text' ) ) );
			$out = ob_get_clean();
			remove_filter( 'ans_tb_email_lead_text', array( $this, 'reminder_lead' ), 20 );
			return $out;
		}
	}

	$emails['ANS_TB_Email_Event_Reminder'] = new ANS_TB_Email_Event_Reminder();
	return $emails;
}

/**
 * Is this order still one we should be reminding?
 *
 * Two tests, and the second is the load-bearing one. Status must be a paid
 * status, and the order must still have LIVE ticket instances -
 * refund-voids-tickets.php trashes instances on refund, so an order that has
 * been refunded since purchase has no publish-status tickets left even though
 * its status may linger. Reminding someone about a concert they no longer hold
 * a ticket for is the kind of error people forward to the board.
 *
 * @param WC_Order $order
 * @return bool
 */
function ans_tb_reminder_order_is_sendable( $order ) {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) ) {
		return false;
	}
	$paid = apply_filters( 'ans_tb_reminder_paid_statuses', array( 'completed', 'processing' ) );
	if ( ! in_array( (string) $order->get_status(), (array) $paid, true ) ) {
		return false;
	}
	if ( ! function_exists( 'ans_tb_order_ticket_ids' ) ) {
		return false;
	}
	return (bool) ans_tb_order_ticket_ids( (int) $order->get_id() );
}

/**
 * The "Tickets:" table with each ticket's own download button.
 *
 * See the file header for why this is rendered here rather than inherited from
 * bridge-for-woocommerce's woocommerce_email_after_order_table handler.
 *
 * @param WC_Order $order
 * @param bool     $plain
 * @return string
 */
function ans_tb_reminder_tickets_table( $order, $plain = false ) {
	if ( ! is_object( $order ) || ! function_exists( 'tickera_get_tickets_table_email' ) ) {
		return '';
	}
	$table = trim( (string) tickera_get_tickets_table_email( (int) $order->get_id() ) );
	if ( '' === $table ) {
		return '';
	}
	if ( $plain ) {
		return "\nTICKETS\n" . wp_strip_all_tags( $table ) . "\n";
	}
	return '<p style="margin:0 0 16px;"><strong>Tickets:</strong></p>' . $table;
}

/**
 * Fetch the registered reminder email object, or null.
 *
 * @return WC_Email|null
 */
function ans_tb_reminder_email() {
	if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
		return null;
	}
	$emails = WC()->mailer()->get_emails();
	return isset( $emails['ANS_TB_Email_Event_Reminder'] ) ? $emails['ANS_TB_Email_Event_Reminder'] : null;
}

/**
 * Send one reminder, optionally diverted to a named address.
 *
 * When $divert_to is set the mail goes ONLY there and the subject is prefixed
 * [PREVIEW] - the customer is never contacted. The diversion works through
 * WooCommerce's own recipient filter rather than by reimplementing trigger(),
 * so a preview exercises exactly the code path a real send does, attachments
 * included. A preview that renders through a different path proves nothing.
 *
 * @param int    $order_id
 * @param string $divert_to Optional email address.
 * @return array|WP_Error
 */
function ans_tb_send_reminder( $order_id, $divert_to = '' ) {
	$email = ans_tb_reminder_email();
	if ( ! $email ) {
		return new WP_Error( 'no_email', 'The reminder email is not registered. Is WooCommerce active?', array( 'status' => 500 ) );
	}

	$order = wc_get_order( (int) $order_id );
	if ( ! $order ) {
		return new WP_Error( 'no_order', 'Order not found.', array( 'status' => 404 ) );
	}

	$divert_to = $divert_to ? sanitize_email( $divert_to ) : '';
	$recip_cb  = null;
	$subj_cb   = null;

	if ( $divert_to ) {
		$recip_cb = function () use ( $divert_to ) {
			return $divert_to;
		};
		$subj_cb = function ( $subject ) {
			return '[PREVIEW] ' . $subject;
		};
		add_filter( 'woocommerce_email_recipient_ans_event_reminder', $recip_cb, 999 );
		add_filter( 'woocommerce_email_subject_ans_event_reminder', $subj_cb, 999 );
	}

	$sent = $email->trigger( (int) $order_id );

	if ( $divert_to ) {
		remove_filter( 'woocommerce_email_recipient_ans_event_reminder', $recip_cb, 999 );
		remove_filter( 'woocommerce_email_subject_ans_event_reminder', $subj_cb, 999 );
	}

	$result = array(
		'order_id'  => (int) $order_id,
		'sent'      => (bool) $sent,
		'to'        => $divert_to ? $divert_to : $order->get_billing_email(),
		'preview'   => (bool) $divert_to,
		'sendable'  => ans_tb_reminder_order_is_sendable( $order ),
		'status'    => $order->get_status(),
	);

	/**
	 * Fires after every reminder attempt, preview or real.
	 *
	 * reminder-schedule.php listens here to record what was sent, which is what
	 * makes a retry safe. On 2026-09-11 a batch send timed out at the connector
	 * while PHP carried on, and nothing in the system could answer whether it
	 * had gone - the SMTP log was the only witness. This is the choke point
	 * every send passes through, so it is the honest place to record.
	 *
	 * @param int    $order_id
	 * @param string $divert_to Non-empty for a preview.
	 * @param array  $result
	 */
	do_action( 'ans_tb_reminder_sent', (int) $order_id, $divert_to, $result );

	return $result;
}

add_action( 'rest_api_init', function () {

	/**
	 * POST ars-nova/v1/order/{id}/reminder/preview  {to}
	 *
	 * Renders and mails the reminder to ONE named address. Never the customer.
	 */
	register_rest_route( ANS_TB_NS, '/order/(?P<id>\d+)/reminder/preview', array(
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
			return ans_tb_send_reminder( (int) $req['id'], $to );
		},
	) );

	/**
	 * POST ars-nova/v1/order/{id}/reminder/send
	 *
	 * The real thing: mails the customer on the order.
	 */
	register_rest_route( ANS_TB_NS, '/order/(?P<id>\d+)/reminder/send', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_tb_perm',
		'callback'            => function ( $req ) {
			return ans_tb_send_reminder( (int) $req['id'], '' );
		},
	) );

	/**
	 * POST ars-nova/v1/reminder/send-batch  {order_ids:[], dry_run:true}
	 *
	 * Takes an EXPLICIT list of order ids rather than deriving them from an
	 * event. Deriving the list is the scheduler's job and belongs with the
	 * scheduler; until that exists, whoever sends a batch has to have looked at
	 * the list first, which is the right amount of friction for an irreversible
	 * send to real customers.
	 *
	 * dry_run defaults to TRUE. You have to ask for the send.
	 */
	register_rest_route( ANS_TB_NS, '/reminder/send-batch', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_tb_perm',
		'callback'            => function ( $req ) {
			$ids = $req->get_param( 'order_ids' );
			if ( ! is_array( $ids ) || ! $ids ) {
				return new WP_Error( 'missing_order_ids', 'order_ids must be a non-empty array.', array( 'status' => 400 ) );
			}
			if ( count( $ids ) > 200 ) {
				return new WP_Error( 'too_many', 'Refusing more than 200 orders in one call.', array( 'status' => 400 ) );
			}

			$dry = $req->get_param( 'dry_run' );
			$dry = ( null === $dry ) ? true : (bool) $dry;

			$results = array();
			$ok = 0;
			$skipped = 0;

			foreach ( $ids as $raw ) {
				$oid   = (int) $raw;
				$order = $oid ? wc_get_order( $oid ) : false;
				if ( ! $order ) {
					$results[] = array( 'order_id' => $oid, 'sent' => false, 'reason' => 'order not found' );
					$skipped++;
					continue;
				}
				$sendable = ans_tb_reminder_order_is_sendable( $order );
				if ( ! $sendable ) {
					$results[] = array(
						'order_id' => $oid,
						'sent'     => false,
						'reason'   => 'not sendable (status ' . $order->get_status() . ', or no live tickets)',
					);
					$skipped++;
					continue;
				}
				if ( $dry ) {
					$results[] = array(
						'order_id' => $oid,
						'sent'     => false,
						'would_go_to' => $order->get_billing_email(),
						'reason'   => 'dry run',
					);
					$ok++;
					continue;
				}
				$r = ans_tb_send_reminder( $oid, '' );
				if ( is_wp_error( $r ) ) {
					$results[] = array( 'order_id' => $oid, 'sent' => false, 'reason' => $r->get_error_message() );
					$skipped++;
					continue;
				}
				$results[] = $r;
				if ( ! empty( $r['sent'] ) ) {
					$ok++;
				} else {
					$skipped++;
				}
			}

			return array(
				'dry_run'   => $dry,
				'requested' => count( $ids ),
				'ok'        => $ok,
				'skipped'   => $skipped,
				'results'   => $results,
			);
		},
	) );
} );

/**
 * Render staff-editable copy without making staff write HTML.
 *
 * Blank lines separate blocks. A block beginning "## " is a heading; anything
 * else is a paragraph. That is the whole syntax, and it exists because the
 * first build shipped Kim's section titles - "What to expect", "About Nicolò" -
 * through wpautop(), which rendered them as orphan sentences indistinguishable
 * from body copy. Headings have to survive the trip from a settings textarea.
 *
 * @param string $text
 * @param bool   $plain
 * @return string
 */
function ans_tb_reminder_richtext( $text, $plain = false ) {
	$text = trim( (string) $text );
	if ( '' === $text ) {
		return '';
	}
	$blocks = preg_split( "/\n\s*\n/", str_replace( "\r\n", "\n", $text ) );
	$out    = '';

	foreach ( $blocks as $block ) {
		$block = trim( $block );
		if ( '' === $block ) {
			continue;
		}
		$is_heading = ( 0 === strpos( $block, '## ' ) );
		$body       = $is_heading ? trim( substr( $block, 3 ) ) : $block;

		if ( $plain ) {
			$out .= $is_heading
				? "\n" . strtoupper( wp_strip_all_tags( $body ) ) . "\n"
				: wp_strip_all_tags( $body ) . "\n\n";
			continue;
		}

		if ( $is_heading ) {
			$out .= '<h2 style="margin:24px 0 8px;font-size:18px;font-weight:normal;'
				. 'font-family:Helvetica,Arial,sans-serif;color:#1f3d5c;">'
				. esc_html( $body ) . '</h2>';
		} else {
			$out .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;'
				. 'font-family:Helvetica,Arial,sans-serif;color:#222;">'
				. nl2br( wptexturize( wp_kses_post( $body ) ) ) . '</p>';
		}
	}

	return $out;
}

/**
 * Per-event logistics: directions and parking, read from the EVENT.
 *
 * These used to live in `_purchase_note` on the ticket product, which is the
 * wrong home twice over: a concert with three nights shares one product, so the
 * text cannot differ per performance, and a product field is invisible to
 * Singers Hub. Reading them from the event is the first step of moving that
 * ownership; the order email still uses the purchase note until the rest lands.
 *
 * Renders nothing when the event carries neither field, rather than falling
 * back to the purchase note - a silent fallback would hide the migration being
 * incomplete, and an empty section is easier to notice than a stale one.
 *
 * @param WC_Order $order
 * @param bool     $plain
 * @return string
 */
function ans_tb_reminder_event_sections( $order, $plain = false ) {
	if ( ! is_object( $order ) || ! function_exists( 'ans_tb_order_events' ) ) {
		return '';
	}
	$events = ans_tb_order_events( (int) $order->get_id() );
	if ( ! $events || empty( $events[0]['id'] ) ) {
		return '';
	}
	$event_id = (int) $events[0]['id'];

	$map = apply_filters( 'ans_tb_reminder_event_sections_map', array(
		'Getting there' => 'ans_directions',
		'Parking'       => 'ans_parking',
		'Good to know'  => 'ans_note',
	), $event_id );

	$out = '';
	foreach ( $map as $heading => $meta_key ) {
		$value = trim( (string) get_post_meta( $event_id, $meta_key, true ) );
		if ( '' === $value ) {
			continue;
		}
		$out .= ans_tb_reminder_richtext( '## ' . $heading . "\n\n" . $value, $plain );
	}
	return $out;
}
