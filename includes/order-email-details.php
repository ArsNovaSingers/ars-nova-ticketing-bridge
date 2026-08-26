<?php
/**
 * Ars Nova Ticketing Bridge - make the confirmation email actually deliver the ticket.
 *
 * WHY THIS EXISTS
 * ---------------
 * Patrons were buying the same ticket over and over. John McClellan placed SIX
 * orders for a capacity-limited house concert in 28 minutes from one IP on
 * 2026-08-25; Lorraine placed two 2m49s apart. Neither was confused about what
 * they wanted. They could not tell that the purchase had worked.
 *
 * Read the email they got. Tickera renders TICKETS_TABLE: a small bordered table
 * whose columns are Event Name | Ticket Type | Ticket, and whose last cell is a
 * bare anchor with six characters of visible text - the word "Download". The
 * ticket code is never printed. No PDF is attached, because every *_attach_ticket
 * key in bridge-for-woocommerce defaults to 'no' and the attachment hook guards on
 * isset(), so an unset option means no attachment. Ours was unset.
 *
 * So the only thing in that email that IS the ticket was one word in a table cell,
 * and nothing anywhere said where the concert was. A patron who does not recognise
 * "Download" as their ticket has no evidence the order succeeded, and the rational
 * response is to buy again. The re-ordering was not a checkout defect. It was this.
 *
 * THREE CHANGES, IN ORDER OF HOW MUCH THEY MATTER
 * -----------------------------------------------
 * 1. Attach the PDF. Belt and braces, and for an older patron an attachment is the
 *    most familiar object in an email. Done here rather than by flipping Tickera's
 *    setting so it lives in version control and cannot be silently switched off in
 *    wp-admin by someone who does not know why it was on.
 * 2. Turn the "Download" anchor into a real button that says what it is.
 * 3. Put the event - date, time, VENUE AND STREET ADDRESS, link back to the concert
 *    page - at the top of the email. Every one of the 21 events on this site has
 *    event_location populated with a full address. None of it was reaching buyers.
 *
 * THE TRAP IN CHANGE 2 - READ BEFORE EDITING
 * ------------------------------------------
 * `tickera_download_ticket_url_front_link` fires from TWO call sites with DIFFERENT
 * arities:
 *   - tickera_get_ticket_download_link()     -> 4 args, $value is '<a ...>Download</a>'
 *   - tickera_get_raw_ticket_download_link() -> 3 args, $value is a BARE URL
 * ticket-downloads.php calls the raw one and expects a URL string back. Returning
 * button markup on that path would corrupt every WooCommerce download row and this
 * file's own attachment lookup. Hence: 4th arg defaults, and we pass $value through
 * untouched unless it actually contains an anchor.
 *
 * Also note tickera_apply_filters() fans one call out to three hook names
 * (bare, tc_, tickera_). The tickera_ prefixed pass runs LAST, so hooking it wins.
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer-facing order emails that should carry a ticket.
 *
 * Deliberately excludes 'new_order' (admin) and the cancelled/refunded notices -
 * attaching a ticket PDF to a refund notice would be worse than attaching nothing.
 *
 * @param string $email_id WC_Email id.
 * @return bool
 */
function ans_tb_email_carries_ticket( $email_id ) {
	$ids = apply_filters( 'ans_tb_email_ticket_email_ids', array(
		'customer_completed_order',
		'customer_processing_order',
		'customer_invoice',
	) );
	return in_array( (string) $email_id, (array) $ids, true );
}

/* -------------------------------------------------------------------------
 * 1. Attach the ticket PDF
 * ---------------------------------------------------------------------- */

/**
 * Attach each live ticket PDF to the customer's order email.
 *
 * Uses ans_tb_order_ticket_ids() from ticket-downloads.php, which returns ONLY
 * `publish` instances. That is load-bearing: a refunded order's tickets are
 * trashed by refund-voids-tickets.php, and a voided ticket must never be mailed
 * out as an attachment.
 *
 * The filename is reconstructed exactly the way bridge-for-woocommerce does it
 * (apply_filters 'tc_pdf_ticket_name'), so the bridge's own cleanup on
 * woocommerce_email_sent finds and deletes our temp files too - we create no
 * garbage it will not collect.
 *
 * tickera_maybe_create_temporary_ticket_file() returns nothing and swallows its
 * own exceptions, so its success can only be established by testing for the file.
 *
 * Priority 20: after the bridge's own handler at 10, and we skip anything it
 * already added, so enabling Tickera's setting later cannot produce duplicates.
 *
 * @param array  $attachments Absolute file paths.
 * @param string $email_id    WC_Email id.
 * @param mixed  $order       Usually WC_Order.
 * @param mixed  $email       WC_Email. Optional - WooCommerce has passed 3 args historically.
 * @return array
 */
function ans_tb_email_attach_tickets( $attachments, $email_id = '', $order = null, $email = null ) {
	if ( ! is_array( $attachments ) ) {
		$attachments = array();
	}
	if ( ! ans_tb_email_carries_ticket( $email_id ) ) {
		return $attachments;
	}
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
		return $attachments;
	}
	if ( ! function_exists( 'ans_tb_order_ticket_ids' ) || ! function_exists( 'tickera_maybe_create_temporary_ticket_file' ) ) {
		return $attachments;
	}

	$upload = wp_upload_dir();
	if ( empty( $upload['basedir'] ) ) {
		return $attachments;
	}
	$dir = trailingslashit( $upload['basedir'] ) . 'tc-tmp/';

	foreach ( ans_tb_order_ticket_ids( (int) $order->get_id() ) as $ticket_id ) {
		$code = (string) get_post_meta( $ticket_id, 'ticket_code', true );
		if ( '' === trim( $code ) ) {
			continue;
		}
		$file = apply_filters( 'tc_pdf_ticket_name', $code, $ticket_id );
		$path = $dir . $file . '.pdf';

		if ( ! file_exists( $path ) ) {
			tickera_maybe_create_temporary_ticket_file( $ticket_id );
		}
		if ( file_exists( $path ) && ! in_array( $path, $attachments, true ) ) {
			$attachments[] = $path;
		}
	}

	return $attachments;
}
add_filter( 'woocommerce_email_attachments', 'ans_tb_email_attach_tickets', 20, 4 );

/* -------------------------------------------------------------------------
 * 2. A download link that looks like a ticket
 * ---------------------------------------------------------------------- */

/**
 * Replace Tickera's bare "Download" anchor with a button that names itself.
 *
 * See the arity trap in this file's header. $value is HTML on one call site and a
 * bare URL on the other; we only ever touch the HTML one.
 *
 * @param mixed  $value        Anchor HTML, or a bare URL on the raw call site.
 * @param int    $ticket_id    Ticket instance ID.
 * @param int    $order_id     Order ID (instance post_parent).
 * @param string $download_url Un-escaped URL. ABSENT on the 3-arg call site.
 * @return mixed
 */
function ans_tb_email_download_button( $value, $ticket_id = 0, $order_id = 0, $download_url = '' ) {
	if ( ! is_string( $value ) || false === stripos( $value, '<a ' ) ) {
		return $value; // raw-URL call site - hands off.
	}

	$url = '';
	if ( is_string( $download_url ) && '' !== trim( $download_url ) ) {
		$url = trim( $download_url );
	} elseif ( preg_match( '/href=["\']([^"\']+)["\']/i', $value, $m ) ) {
		$url = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
	}
	if ( '' === $url ) {
		return $value;
	}
	// An email needs an absolute URL. The bridge's tc_download_ticket_url_front
	// filter already returns one, but a relative URL here would be a dead link.
	if ( ! preg_match( '#^https?://#i', $url ) ) {
		$url = home_url( '/' . ltrim( $url, '/' ) );
	}

	$bg    = apply_filters( 'ans_tb_email_button_bg', '#1f3d5c' );
	$label = apply_filters( 'ans_tb_email_button_label', 'Download your ticket (PDF)' );

	$html  = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:6px 0;"><tr>';
	$html .= '<td align="center" bgcolor="' . esc_attr( $bg ) . '" style="border-radius:4px;">';
	$html .= '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 22px;';
	$html .= 'font-family:Helvetica,Arial,sans-serif;font-size:16px;font-weight:bold;line-height:1;';
	$html .= 'color:#ffffff;text-decoration:none;border-radius:4px;">' . esc_html( $label ) . '</a>';
	$html .= '</td></tr></table>';

	return apply_filters( 'ans_tb_email_download_button_html', $html, $url, $ticket_id, $order_id );
}
add_filter( 'tickera_download_ticket_url_front_link', 'ans_tb_email_download_button', 20, 4 );

/* -------------------------------------------------------------------------
 * 3. Where the concert actually is
 * ---------------------------------------------------------------------- */

/**
 * A maps link, but only when the location is really an address.
 *
 * Several events carry deliberate non-addresses - "Rehearsal venue to be confirmed",
 * and the house concert's "Private residence, Boulder - address provided with your
 * ticket", which exists precisely so a private home address is NOT published
 * (PROJECT_RULES section 8). Handing those to Google Maps produces a confident,
 * wrong pin. Require a digit, and refuse known placeholder phrasing outright.
 *
 * @param string $location Raw event_location.
 * @return string URL, or '' when a map would be misleading.
 */
function ans_tb_event_map_url( $location ) {
	$location = trim( (string) $location );
	if ( '' === $location ) {
		return '';
	}
	if ( ! preg_match( '/\d/', $location ) ) {
		return ''; // no street number and no zip - not an address
	}
	if ( preg_match( '/to be confirmed|provided with your ticket|private residence|tba|tbd/i', $location ) ) {
		return '';
	}
	return apply_filters(
		'ans_tb_event_map_url',
		'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $location ),
		$location
	);
}

/**
 * Describe one Tickera event for display in an email.
 *
 * event_date_time is stored as local wall time in 'Y-m-d H:i' with no timezone, so
 * it is parsed IN the site timezone and formatted back through wp_date. Parsing it
 * with a bare strtotime() and formatting with date_i18n() double-applies the GMT
 * offset and silently shifts every concert time - the exact class of bug that put
 * performances 6-7 hours early once already.
 *
 * @param int $event_id tc_events post ID.
 * @return array|null
 */
function ans_tb_event_details( $event_id ) {
	$event_id = (int) $event_id;
	if ( ! $event_id || 'tc_events' !== get_post_type( $event_id ) ) {
		return null;
	}

	$when  = '';
	$start = (string) get_post_meta( $event_id, 'event_date_time', true );
	if ( '' !== trim( $start ) ) {
		$dt = date_create_immutable_from_format( 'Y-m-d H:i', $start, wp_timezone() );
		if ( $dt instanceof DateTimeImmutable ) {
			$when = wp_date( 'l, F j, Y \a\t g:i A', $dt->getTimestamp() );
		}
	}

	// Private address when the event has one (house concerts), else the public
	// location. See includes/event-private-location.php.
	$location = function_exists( 'ans_tb_event_location' )
		? ans_tb_event_location( $event_id )
		: trim( (string) get_post_meta( $event_id, 'event_location', true ) );

	return array(
		'id'        => $event_id,
		'title'     => html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ),
		'when'      => $when,
		'location'  => $location,
		'permalink' => (string) get_permalink( $event_id ),
		'map_url'   => ans_tb_event_map_url( $location ),
	);
}

/**
 * Distinct events represented by an order's live tickets.
 *
 * @param int $order_id WooCommerce order ID.
 * @return array[] ans_tb_event_details() rows.
 */
function ans_tb_order_events( $order_id ) {
	$out = array();
	if ( ! function_exists( 'ans_tb_order_ticket_links' ) ) {
		return $out;
	}
	$seen = array();
	foreach ( ans_tb_order_ticket_links( (int) $order_id ) as $link ) {
		$eid = isset( $link['event_id'] ) ? (int) $link['event_id'] : 0;
		if ( ! $eid || isset( $seen[ $eid ] ) ) {
			continue;
		}
		$seen[ $eid ] = true;
		$details      = ans_tb_event_details( $eid );
		if ( $details ) {
			$out[] = $details;
		}
	}
	return $out;
}

/**
 * Print the "here is your ticket, here is where to go" block above the order table.
 *
 * Renders nothing when the order has no live ticket instances. During checkout that
 * is a real state (Tickera generates instances at payment), and an empty confident
 * block is worse than none.
 *
 * @param mixed $order        WC_Order.
 * @param bool  $sent_to_admin
 * @param bool  $plain_text
 * @param mixed $email        WC_Email.
 * @return void
 */
function ans_tb_email_event_block( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
	if ( $sent_to_admin ) {
		return;
	}
	$email_id = ( is_object( $email ) && isset( $email->id ) ) ? $email->id : '';
	if ( ! ans_tb_email_carries_ticket( $email_id ) ) {
		return;
	}
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
		return;
	}

	$events = ans_tb_order_events( (int) $order->get_id() );
	if ( ! $events ) {
		return;
	}

	$lead = 'Your ticket is attached to this email as a PDF. You can print it, or just show it on your phone at the door.';
	$lead = apply_filters( 'ans_tb_email_lead_text', $lead, $order );

	if ( $plain_text ) {
		echo "\n" . esc_html( count( $events ) > 1 ? 'YOUR TICKETS' : 'YOUR TICKET' ) . "\n";
		echo esc_html( $lead ) . "\n\n";
		foreach ( $events as $e ) {
			echo esc_html( $e['title'] ) . "\n";
			if ( $e['when'] ) {
				echo esc_html( $e['when'] ) . "\n";
			}
			if ( $e['location'] ) {
				echo esc_html( $e['location'] ) . "\n";
			}
			if ( $e['permalink'] ) {
				echo esc_url( $e['permalink'] ) . "\n";
			}
			echo "\n";
		}
		echo "----------------------------------------\n\n";
		return;
	}

	$heading = count( $events ) > 1 ? 'Your tickets' : 'Your ticket';
	?>
	<div style="margin:0 0 24px 0;font-family:Helvetica,Arial,sans-serif;">
		<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
			style="border:2px solid #1f3d5c;border-radius:6px;background:#f7f9fb;">
			<tr><td style="padding:18px 20px;">
				<p style="margin:0 0 6px 0;font-size:19px;font-weight:bold;color:#1f3d5c;">
					<?php echo esc_html( $heading ); ?>
				</p>
				<p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#222;">
					<?php echo esc_html( $lead ); ?>
				</p>
				<?php foreach ( $events as $i => $e ) : ?>
					<?php if ( $i > 0 ) : ?>
						<div style="height:1px;background:#d6dee6;margin:16px 0;"></div>
					<?php endif; ?>
					<p style="margin:0 0 4px 0;font-size:17px;font-weight:bold;color:#111;">
						<?php echo esc_html( $e['title'] ); ?>
					</p>
					<?php if ( $e['when'] ) : ?>
						<p style="margin:0 0 4px 0;font-size:15px;color:#222;">
							<?php echo esc_html( $e['when'] ); ?>
						</p>
					<?php endif; ?>
					<?php if ( $e['location'] ) : ?>
						<p style="margin:0 0 4px 0;font-size:15px;color:#222;">
							<strong>Location:</strong> <?php echo esc_html( $e['location'] ); ?>
							<?php if ( $e['map_url'] ) : ?>
								<br /><a href="<?php echo esc_url( $e['map_url'] ); ?>"
									style="color:#1f3d5c;">Open in Google Maps</a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php if ( $e['permalink'] ) : ?>
						<p style="margin:8px 0 0 0;font-size:15px;">
							<a href="<?php echo esc_url( $e['permalink'] ); ?>" style="color:#1f3d5c;font-weight:bold;">
								View concert details &rarr;
							</a>
						</p>
					<?php endif; ?>
				<?php endforeach; ?>
			</td></tr>
		</table>
	</div>
	<?php
}
add_action( 'woocommerce_email_before_order_table', 'ans_tb_email_event_block', 5, 4 );
