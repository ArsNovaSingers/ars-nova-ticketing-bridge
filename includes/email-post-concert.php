<?php
/**
 * The post-concert thank-you - ans_post_concert.
 *
 * A MARKETING email, and the first one this plugin has ever sent. Everything
 * else here is transactional: a confirmation or a reminder about a ticket
 * somebody already bought. The moment an email promotes the NEXT concert it
 * changes category, which is why marketing-optout.php exists and why this file
 * refuses to send to an opted-out address. See that file's header.
 *
 * -- What it deliberately does NOT do ----------------------------------------
 *
 * It does not carry the ticket PDF or the download button. The concert has
 * happened; re-sending a spent ticket invites somebody to turn up to a room
 * that is already dark. It therefore does NOT opt into
 * ans_tb_email_ticket_email_ids, and that omission is the point.
 *
 * It shows no prices, no order table and no billing address, for the same
 * reason the reminder does not: the first build of that email inherited all
 * three and read like a second charge.
 *
 * -- The parts that are actually new -----------------------------------------
 *
 * PHOTOS. Media-library attachment ids on the event, rendered at a constrained
 * width with the attachment's own alt text. Most clients block images by
 * default, so the email is written to read correctly with every picture
 * missing - a message that is only a photo strip arrives blank.
 *
 * WHAT'S NEXT resolves itself from the soonest published event after this one,
 * with a manual override. Typing it by hand is how it goes stale, and a
 * thank-you promoting a concert that has already happened is worse than one
 * promoting nothing.
 *
 * -- When it sends -----------------------------------------------------------
 *
 * Days AFTER the concert (ans_thanks_offsets, default 1) at a time of day
 * (ans_thanks_send_time, default 10:00), or at one explicit moment
 * (ans_thanks_send_at) that overrides both. Untouched, that is 10am the morning
 * after.
 *
 * The cron job is a dumb 15-minute heartbeat that knows nothing. Every
 * correctness decision is recomputed from wp_timezone() at each tick by
 * ans_tb_thanks_schedule(), because wp_schedule_event() recurring events are
 * fixed +N-second intervals that never re-anchor to local time - a "daily
 * 09:05" job starts firing at 08:05 the day Denver leaves MDT. Correctness has
 * to live in the due-ness calculation; cron only supplies the ticks.
 *
 * A slot is due from its moment until ans_tb_thanks_window() seconds after it,
 * then expired for good. That window replaces a safety property the old
 * exact-day match was providing by accident: it is what stops somebody arming a
 * concert from last season and instantly mailing its entire audience. Inside
 * the tick it is cut short again at local midnight, so a slot missed late in
 * the evening can never come back and mail a concert at 2am.
 *
 * THE HEARTBEAT DOES NOT ARM ITSELF. ans_tb_thanks_schedule_cron() will not
 * schedule anything until a person POSTs to /thanks/cron on the live site.
 * Uploading these files is therefore not enough to start sending, which is the
 * whole point: the pre-flight this file keeps telling you to run is only worth
 * anything if the files cannot start sending before you run it.
 *
 * @package ArsNovaTicketingBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Event meta keys for the thank-you. Separate namespace from the reminder's. */
function ans_tb_thanks_meta_keys() {
	return array(
		'enabled'    => 'ans_thanks_enabled',
		'offsets'    => 'ans_thanks_offsets',
		'subject'    => 'ans_thanks_subject',
		'heading'    => 'ans_thanks_heading',
		'intro'      => 'ans_thanks_intro',
		'highlights' => 'ans_thanks_highlights',
		'closing'    => 'ans_thanks_closing',
		'images'     => 'ans_thanks_images',
		'next_event' => 'ans_thanks_next_event',
		/*
		 * send_time, not "time". It sits directly beside send_at in the Custom
		 * Fields panel, and "ans_thanks_time" next to "ans_thanks_send_at"
		 * reads as "time of what?" - the kind of ambiguity that gets one of the
		 * two edited when the operator meant the other. Both keys are new, so
		 * the clearer name costs nothing.
		 */
		'send_time'  => 'ans_thanks_send_time',
		'send_at'    => 'ans_thanks_send_at',
	);
}

/**
 * Parse "2, 14" into days AFTER the concert.
 *
 * A near-copy of ans_tb_reminder_parse_offsets() and deliberately its own
 * function, because that one REJECTS anything negative and its comment explains
 * why: "a reminder for a concert that has already happened is never what anyone
 * meant." True for a reminder. The exact opposite here. Rather than teach one
 * parser two contradictory rules, each email owns the direction of its own
 * clock, and 2 means the same thing in both files - two days from the concert,
 * in whichever direction that email runs.
 *
 * @param mixed $raw
 * @return int[] Ascending: the soonest thank-you first.
 */
function ans_tb_thanks_parse_offsets( $raw ) {
	if ( is_string( $raw ) ) {
		$raw = preg_split( '/[\s,]+/', trim( $raw ) );
	}
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$out = array();
	foreach ( $raw as $v ) {
		if ( '' === trim( (string) $v ) ) {
			continue;
		}
		$n = (int) $v;
		if ( $n >= 0 && $n <= 365 ) {
			$out[] = $n;
		}
	}
	$out = array_values( array_unique( $out ) );
	sort( $out );
	return $out;
}

/**
 * The default offsets for an event that carries none of its own.
 *
 * ONE. Flat, unconditional, for every event. 1.27.2 defaulted to 2; dropping it
 * to 1 is what makes an untouched event land at 10am the morning after, which
 * is what was actually asked for.
 *
 * DO NOT REINTRODUCE THE PAST/FUTURE SPLIT. A version of this function returned
 * array( 2 ) for a concert already past and array( 1 ) for one still to come,
 * meaning to protect events armed under the old default from silently changing
 * the slot they were waiting on. It is logically broken and it cannot work: a
 * concert is ALWAYS in the past by the time its day-1 slot falls due, so the
 * default flips to 2 at exactly the moment slot 1 should fire. Slot 1 vanishes
 * with no `missed` row to show for it, the send silently relocates to day 2,
 * and the requested behaviour - the morning after at 10:00 - could never
 * happen at all. The default always behaved as 2.
 *
 * It also protected nobody. Both environments were queried live on 2026-09-13:
 * ans_thanks_enabled rows 0, ans_thanks_offsets rows 0, _ans_thanks_sent_% rows
 * 0. Nothing has ever been armed and nothing has ever been sent under any
 * default, so there was no already-armed concert to strand. The double-send
 * guard for a token that stops being computed lives in
 * ans_tb_thanks_already_sent()'s prefix scan, which is where it belongs.
 *
 * @return int[]
 */
function ans_tb_thanks_default_offsets() {
	return ans_tb_thanks_parse_offsets( apply_filters( 'ans_tb_thanks_default_offsets', array( 1 ) ) );
}

/**
 * Parse a typed time of day into "H:i", or null.
 *
 * null means INVALID, and invalid is never quietly promoted into a fallback:
 * ans_tb_thanks_schedule() stops the event dead on a bad per-event value rather
 * than sending at some other hour nobody chose. An email to every ticket holder
 * of a concert is not the place to guess.
 *
 * am/pm is accepted because a human typing a send time into a REST field writes
 * "10am". Rejecting that would make the strict-invalid rule above fire on a
 * perfectly reasonable input, which is how a strict rule earns a reputation for
 * being broken and gets loosened by somebody in a hurry.
 *
 * @param mixed $raw
 * @return string|null Normalized "H:i", or null when unparseable.
 */
function ans_tb_thanks_parse_time( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return null;
	}

	$h = null;
	$i = 0;

	if ( preg_match( '/^(\d{1,2})\s*:\s*(\d{2})(?:\s*:\s*\d{2})?$/', $raw, $m ) ) {
		$h = (int) $m[1];
		$i = (int) $m[2];
	} elseif ( preg_match( '/^(\d{1,2})(?:\s*:\s*(\d{2}))?\s*([ap])\.?m\.?$/i', $raw, $m ) ) {
		$h  = (int) $m[1];
		$i  = ( isset( $m[2] ) && '' !== $m[2] ) ? (int) $m[2] : 0;
		$pm = ( 0 === strcasecmp( 'p', $m[3] ) );
		if ( 12 === $h ) {
			$h = $pm ? 12 : 0;
		} elseif ( $pm ) {
			$h += 12;
		}
	} else {
		return null;
	}

	if ( $h < 0 || $h > 23 || $i < 0 || $i > 59 ) {
		return null;
	}
	return sprintf( '%02d:%02d', $h, $i );
}

/**
 * Parse an explicit send moment into "Y-m-d H:i" in site time, or null.
 *
 * The strict format gate comes FIRST and is the whole point of the function.
 * A bare `new DateTimeImmutable( $raw, $tz )` - which is what ans_tb_local_ts()
 * does, and why this does not call it - happily accepts "tomorrow 10am" and
 * "now". A stored "now" would resolve to the current minute at every one of the
 * 96 daily heartbeats, so it would be permanently due: one typed word, and the
 * event mails its whole list the moment anything else stops it.
 *
 * The gate also rejects "2026-11-02 10" - no minutes. That is deliberate. "10"
 * is as likely to be a day as an hour, and guessing is how a send lands on the
 * wrong date.
 *
 * Nonexistent and ambiguous wall-clock times are RESOLVED and stored, not
 * rejected: the spring-forward gap 02:30 resolves forward to 03:30, an
 * ambiguous autumn time resolves to the first occurrence. The next GET then
 * shows the operator the moment that will actually happen. A round-trip string
 * comparison would look like a tighter validator and would false-reject that
 * legitimate 02:30 -> 03:30 resolution. Measured on PHP 8.2.29, Denver.
 *
 * @param mixed $raw
 * @return string|null Normalized "Y-m-d H:i", or null.
 */
function ans_tb_thanks_parse_send_at( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return null;
	}

	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{1,2}:\d{2}(:\d{2})?$/', $raw ) ) {
		return null;
	}
	$norm = str_replace( 'T', ' ', $raw );
	$tz   = wp_timezone();

	foreach ( array( 'Y-m-d H:i', 'Y-m-d H:i:s' ) as $fmt ) {
		$dt = DateTimeImmutable::createFromFormat( $fmt, $norm, $tz );
		/*
		 * getLastErrors() returns FALSE in PHP 8.2 when there is nothing to
		 * report - it is not an empty array. `$e['error_count']` on a bare
		 * truthiness check would be a warning on every clean parse, and the
		 * obvious `if ( $e['error_count'] || ... )` is a fatal. Hence is_array().
		 */
		$e = DateTimeImmutable::getLastErrors();
		if ( false === $dt ) {
			continue;
		}
		if ( is_array( $e ) && ( $e['error_count'] || $e['warning_count'] ) ) {
			continue;
		}
		return $dt->format( 'Y-m-d H:i' );
	}
	return null;
}

/**
 * Whole days SINCE the concert. Negative while it is still in the future.
 *
 * Calendar days in site time, not 24-hour blocks - "two days after" has to mean
 * the day a person would call two days after, regardless of a 7:30pm curtain.
 *
 * REPORTING ONLY since send times arrived. It no longer decides a send -
 * ans_tb_thanks_tick() compares real timestamps from ans_tb_thanks_schedule().
 * This still answers the REST config output, the preview route and the board.
 *
 * It used to add the site's raw UTC offset by hand - a single number that knows
 * nothing about DST - while every other file in this plugin already carried a
 * real DateTimeZone. On 1 Nov 2026 Denver drops from -6 to -7 and that
 * arithmetic silently moves every answer by an hour, which near midnight is a
 * whole day.
 *
 * @param int $event_id
 * @return int|null Null when the event carries no readable date.
 */
function ans_tb_thanks_days_since( $event_id ) {
	if ( ! function_exists( 'ans_tb_event_ts' ) ) {
		return null;
	}
	$ts = (int) ans_tb_event_ts( (int) $event_id );
	if ( ! $ts ) {
		return null;
	}
	$tz    = wp_timezone();
	$today = ( new DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 );
	$day   = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->setTime( 0, 0 );
	/*
	 * NOTE THE OPERAND ORDER. ans_tb_reminder_days_out() writes
	 * $today->diff( $day ) because it counts days UNTIL. This counts days SINCE
	 * and is its exact negation. Getting it backwards makes "2 days after" mean
	 * "2 days before", passes php -l, passes review by resembling
	 * reminder-schedule.php, and mails the wrong cohort. Verified: today
	 * 2026-11-02 with a 2026-10-31 concert returns +2.
	 */
	return (int) $day->diff( $today )->format( '%r%a' );
}

/**
 * The next concert after this one, for the "what's next" block.
 *
 * Candidates are sorted in PHP off ans_tb_event_ts() rather than by a meta
 * query on event_date_time. The stored format is not guaranteed to sort
 * lexicographically, and a meta-value sort that is subtly wrong would promote
 * the wrong concert while looking entirely healthy. Twenty-odd events cost
 * nothing to sort properly.
 *
 * @param int $after_event_id
 * @return array|null ans_tb_event_details() row, or null when nothing is next.
 */
function ans_tb_thanks_next_event( $after_event_id ) {
	$after_event_id = (int) $after_event_id;

	/*
	 * Request-level memo. No behaviour change within a request - the answer
	 * cannot move mid-request - but ans_tb_thanks_send() calls this once per
	 * RECIPIENT, so an event with 150 orders re-ran the 200-event scan below,
	 * plus a wp_get_object_terms() per candidate, 150 times inside one send
	 * loop. That was affordable exactly once a day and is not affordable now.
	 */
	static $memo = array();
	if ( array_key_exists( $after_event_id, $memo ) ) {
		return $memo[ $after_event_id ];
	}

	$override = (int) get_post_meta( $after_event_id, 'ans_thanks_next_event', true );
	if ( $override > 0 && function_exists( 'ans_tb_event_details' ) ) {
		$row = ans_tb_event_details( $override );
		if ( $row ) {
			return $memo[ $after_event_id ] = $row;
		}
	}

	if ( ! function_exists( 'ans_tb_event_ts' ) || ! function_exists( 'ans_tb_event_details' ) ) {
		return $memo[ $after_event_id ] = null;
	}
	$from = (int) ans_tb_event_ts( $after_event_id );
	if ( ! $from ) {
		$from = time();
	}

	$ids = get_posts( array(
		'post_type'        => 'tc_events',
		'post_status'      => 'publish',
		'posts_per_page'   => 200,
		'fields'           => 'ids',
		'suppress_filters' => true,
	) );

	/*
	 * Everything belonging to the production they just attended is excluded,
	 * not merely the single performance.
	 *
	 * Found on staging before this shipped: for a Rivers & Streams Oct 9 order
	 * the "coming up next" block proudly advertised "Rivers & Streams - Oct 10,
	 * Livestream". Chronologically correct and editorially absurd - it invites
	 * somebody who was in the room last night to buy a ticket to watch the same
	 * concert on a screen.
	 *
	 * event_category is the right discriminator because it is what already
	 * groups performances into a production: the livestream duplicate, the
	 * second night and the matinee all share it. Filtering on the "Livestream"
	 * location string instead would have fixed the symptom, missed the second
	 * night entirely, and broken the day a production is livestream-only.
	 */
	$own_terms = wp_get_object_terms( $after_event_id, 'event_category', array( 'fields' => 'ids' ) );
	$own_terms = is_wp_error( $own_terms ) ? array() : array_map( 'intval', $own_terms );

	$best      = null;
	$best_ts   = 0;
	$best_live = true;
	foreach ( $ids as $id ) {
		$id = (int) $id;
		if ( $id === $after_event_id ) {
			continue;
		}
		if ( '1' === (string) get_post_meta( $id, 'ans_hide', true ) ) {
			continue;
		}
		if ( $own_terms ) {
			$terms = wp_get_object_terms( $id, 'event_category', array( 'fields' => 'ids' ) );
			$terms = is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
			if ( array_intersect( $own_terms, $terms ) ) {
				continue;
			}
		}
		$ts = (int) ans_tb_event_ts( $id );
		if ( $ts <= $from ) {
			continue;
		}

		/*
		 * Tie-break away from a livestream. When the next production opens with
		 * an in-person night and a stream on the same day, the person we are
		 * writing to just sat in a room - offer them the room.
		 */
		$is_live = ( 0 === strcasecmp( 'Livestream', trim( (string) get_post_meta( $id, 'event_location', true ) ) ) );

		if ( ! $best_ts || $ts < $best_ts || ( $ts === $best_ts && $best_live && ! $is_live ) ) {
			$best_ts   = $ts;
			$best      = $id;
			$best_live = $is_live;
		}
	}
	return $memo[ $after_event_id ] = ( $best ? ans_tb_event_details( $best ) : null );
}

/**
 * The photo strip.
 *
 * Single column on purpose. Multi-column email layouts need nested tables that
 * several clients render at full width anyway, and a stack of wide photos beats
 * a grid that collapses unpredictably on a phone.
 *
 * Every image carries the attachment's own alt text, and the email is written
 * so the words alone still make sense - Outlook and Gmail both block remote
 * images until the reader asks for them, so an image-only email arrives empty
 * for most of its audience on first open.
 *
 * @param int  $event_id
 * @param bool $plain
 * @return string
 */
function ans_tb_thanks_photo_strip( $event_id, $plain = false ) {
	$raw = trim( (string) get_post_meta( (int) $event_id, 'ans_thanks_images', true ) );
	if ( '' === $raw ) {
		return '';
	}
	$ids = array_filter( array_map( 'intval', preg_split( '/[\s,]+/', $raw ) ) );
	if ( ! $ids ) {
		return '';
	}

	if ( $plain ) {
		$out = "\n";
		foreach ( $ids as $id ) {
			$url = wp_get_attachment_url( $id );
			if ( $url ) {
				$out .= $url . "\n";
			}
		}
		return $out;
	}

	$out = '';
	foreach ( $ids as $id ) {
		$src = wp_get_attachment_image_src( $id, 'large' );
		if ( ! $src || empty( $src[0] ) ) {
			continue;
		}
		$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		if ( '' === $alt ) {
			$alt = 'Ars Nova Singers in concert';
		}
		$caption = trim( (string) wp_get_attachment_caption( $id ) );

		$out .= '<div style="margin: 0 0 18px 0;">'
			. '<img src="' . esc_url( $src[0] ) . '" alt="' . esc_attr( $alt ) . '" width="560" '
			. 'style="display:block;width:100%;max-width:560px;height:auto;border:0;border-radius:6px;" />';
		if ( '' !== $caption ) {
			$out .= '<p style="margin:6px 0 0;font-size:13px;line-height:1.5;color:#666;'
				. 'font-family:Helvetica,Arial,sans-serif;">' . esc_html( $caption ) . '</p>';
		}
		$out .= '</div>';
	}
	return $out;
}

/**
 * The "what's next" promo block.
 *
 * @param int  $event_id
 * @param bool $plain
 * @return string
 */
function ans_tb_thanks_next_block( $event_id, $plain = false ) {
	$next = ans_tb_thanks_next_event( $event_id );
	if ( ! $next ) {
		return '';
	}

	if ( $plain ) {
		return "\nComing up next: " . $next['title'] . "\n" . $next['when'] . "\n"
			. $next['location'] . "\n" . $next['permalink'] . "\n";
	}

	$out = '<div style="margin: 24px 0 0 0; font-family: Helvetica,Arial,sans-serif;">'
		. '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" '
		. 'style="border: 2px solid #1f3d5c; border-radius: 6px; background: #f7f9fb;" bgcolor="#f7f9fb">'
		. '<tr><td style="padding: 18px 20px;">'
		. '<p style="margin:0 0 6px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#1f3d5c;">Coming up next</p>'
		. '<p style="margin:0 0 4px;font-size:19px;font-weight:bold;color:#111;">' . esc_html( $next['title'] ) . '</p>';

	if ( ! empty( $next['when'] ) ) {
		$out .= '<p style="margin:0 0 4px;font-size:15px;color:#222;">' . esc_html( $next['when'] ) . '</p>';
	}
	if ( ! empty( $next['location'] ) ) {
		$out .= '<p style="margin:0 0 12px;font-size:15px;color:#222;">' . esc_html( $next['location'] ) . '</p>';
	}
	if ( ! empty( $next['permalink'] ) ) {
		$out .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:6px 0 0;">'
			. '<tr><td align="center" bgcolor="#1f3d5c" style="padding:12px;border-radius:4px;">'
			. '<a href="' . esc_url( $next['permalink'] ) . '" style="display:inline-block;padding:12px 22px;'
			. 'font-family:Helvetica,Arial,sans-serif;font-size:16px;font-weight:bold;line-height:1;'
			. 'color:#ffffff;text-decoration:none;border-radius:4px;">Tickets and details</a>'
			. '</td></tr></table>';
	}

	$out .= '</td></tr></table></div>';
	return $out;
}

/**
 * The unsubscribe footer.
 *
 * Inside the email BODY rather than bolted on by a footer filter, because the
 * footer text is shared with every transactional email on the site and this
 * line must appear on exactly one of them.
 */
function ans_tb_thanks_unsub_footer( $to, $plain = false ) {
	if ( ! $to || ! function_exists( 'ans_tb_optout_link' ) ) {
		return '';
	}
	$link = ans_tb_optout_link( $to );
	if ( $plain ) {
		return "\n\nYou are receiving this because you had a ticket to this concert.\n"
			. "To stop receiving concert announcements: " . $link . "\n";
	}
	return '<p style="margin:28px 0 0;padding-top:16px;border-top:1px solid rgba(0,0,0,.15);'
		. 'font-size:12px;line-height:1.6;color:#787c82;font-family:Helvetica,Arial,sans-serif;">'
		. 'You are receiving this because you had a ticket to this concert. '
		. '<a href="' . esc_url( $link ) . '" style="color:#787c82;text-decoration:underline;">'
		. 'Stop receiving concert announcements</a>.</p>';
}

/* -------------------------------------------------------------------------
 * The email class
 * ---------------------------------------------------------------------- */

/**
 * Registered inside the filter, NOT at require time.
 *
 * WC_Email does not exist when this file is included - WooCommerce loads its
 * mailer lazily - so `class X extends WC_Email` at the top level is a fatal on
 * every request. Declaring it here is the same shape email-event-reminder.php
 * uses and for the same reason.
 */
add_filter( 'woocommerce_email_classes', function ( $emails ) {

	if ( ! class_exists( 'ANS_TB_Post_Concert_Email' ) && class_exists( 'WC_Email' ) ) {

		class ANS_TB_Post_Concert_Email extends WC_Email {

			/** Which event this send is about. Set by the sender before trigger(). */
			public $ans_event_id = 0;

			public function __construct() {
				$this->id             = 'ans_post_concert';
				$this->title          = 'Post-concert thank-you';
				$this->description    = 'Sent to everyone who held a ticket, a set number of days AFTER the concert. Carries photos, highlights and the next concert. This is a MARKETING email: it respects the unsubscribe list.';
				$this->customer_email = true;
				$this->template_html  = 'emails/customer-processing-order.php';
				$this->template_plain = 'emails/plain/customer-processing-order.php';

				/*
				 * MUST be set. WC_Email::get_email_type() falls back to 'plain'
				 * when $email_type is null, and init_form_fields() below replaces
				 * WooCommerce's own field list - which is where the email_type
				 * setting normally comes from. Without this the whole email,
				 * photo strip included, rendered as plain text on staging: an
				 * email built around photographs, delivered with no photographs
				 * and no styling, and nothing anywhere reporting an error.
				 */
				$this->email_type = 'html';

				$this->placeholders = array(
					'{event_title}' => '',
					'{event_date}'  => '',
					'{first_name}'  => '',
					'{next_title}'  => '',
					'{next_date}'   => '',
				);

				parent::__construct();
			}

			public function get_default_subject() {
				return 'Thank you for being there - {event_title}';
			}

			public function get_default_heading() {
				return 'Thank you for being there';
			}

			public function get_default_intro() {
				return 'Thank you for joining us for {event_title}. An audience changes what happens in a room, and yours did.';
			}

			public function get_default_closing() {
				return "With our thanks,\n\nArs Nova Singers";
			}

			public function init_form_fields() {
				$this->form_fields = array(
					'enabled' => array(
						'title'   => 'Enable/Disable',
						'type'    => 'checkbox',
						'label'   => 'Enable this email',
						'default' => 'yes',
					),
					'subject' => array(
						'title'       => 'Subject',
						'type'        => 'text',
						'description' => 'Placeholders: {event_title}, {event_date}, {first_name}, {next_title}, {next_date}',
						'placeholder' => $this->get_default_subject(),
						'default'     => '',
					),
					'heading' => array(
						'title'       => 'Email heading',
						'type'        => 'text',
						'placeholder' => $this->get_default_heading(),
						'default'     => '',
					),
					'intro'   => array(
						'title'       => 'Opening paragraph',
						'type'        => 'textarea',
						'description' => 'The first thing under the heading. A blank line starts a new block; a block beginning "## " becomes a heading.',
						'placeholder' => $this->get_default_intro(),
						'default'     => '',
						'css'         => 'width:400px; height:80px;',
					),
					'closing'    => array(
						'title'       => 'Sign-off',
						'type'        => 'textarea',
						'description' => 'Appears after the next-concert block and before the unsubscribe line.',
						'placeholder' => $this->get_default_closing(),
						'default'     => '',
						'css'         => 'width:400px; height:80px;',
					),
					'email_type' => array(
						'title'       => 'Email type',
						'type'        => 'select',
						'description' => 'Leave as HTML. This email is built around photographs; plain text drops every one of them.',
						'default'     => 'html',
						'class'       => 'email_type wc-enhanced-select',
						'options'     => $this->get_email_type_options(),
					),
				);
			}

			/**
			 * PUBLIC, and it has to be.
			 *
			 * v1.24.0 shipped a private method on the reminder that WP_Hook
			 * reached via call_user_func_array from outside the class; staging
			 * returned a 500 that `php -l` could not have caught. Narrowing this
			 * reintroduces that.
			 *
			 * @param WC_Order $order
			 * @param int      $event_id
			 * @return void
			 */
			public function populate_placeholders( $order, $event_id = 0 ) {
				if ( ! is_object( $order ) ) {
					return;
				}
				/*
				 * Resolve the event ourselves when the caller did not name one.
				 * order/{id}/email-preview calls this with the order alone, and
				 * without this the subject rendered "Thank you for being there -"
				 * with the title silently empty. That is the identical shape of
				 * the bug fixed in 1.26.1 ("Reminder:  is "), caught here before
				 * shipping rather than after, by rendering the email and reading
				 * the subject line instead of trusting a 200.
				 */
				$event_id = (int) $event_id;
				if ( ! $event_id ) {
					$this->object = $order;
					$event_id     = $this->ans_resolve_event_id();
				}
				$ts = $event_id && function_exists( 'ans_tb_event_ts' ) ? (int) ans_tb_event_ts( $event_id ) : 0;

				$this->placeholders['{event_title}'] = $event_id ? html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ) : '';
				$this->placeholders['{event_date}']  = $ts ? wp_date( 'l, F j', $ts ) : '';
				$this->placeholders['{first_name}']  = $order->get_billing_first_name();

				$next = $event_id ? ans_tb_thanks_next_event( $event_id ) : null;
				$this->placeholders['{next_title}'] = $next ? $next['title'] : '';
				$this->placeholders['{next_date}']  = $next ? $next['when'] : '';
			}

			/** Resolve the event this order is about, when the sender did not say. */
			public function ans_resolve_event_id() {
				$event_id = (int) $this->ans_event_id;
				if ( $event_id ) {
					return $event_id;
				}
				if ( is_object( $this->object ) && function_exists( 'ans_tb_order_events' ) ) {
					$events = ans_tb_order_events( (int) $this->object->get_id() );
					if ( $events && ! empty( $events[0]['id'] ) ) {
						return (int) $events[0]['id'];
					}
				}
				return 0;
			}

			public function trigger( $order_id ) {
				$this->setup_locale();

				$order = $order_id ? wc_get_order( $order_id ) : false;
				if ( ! $order ) {
					$this->restore_locale();
					return false;
				}
				$this->object    = $order;
				$this->recipient = $order->get_billing_email();

				$this->populate_placeholders( $order, $this->ans_resolve_event_id() );

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
				return $ok;
			}

			public function get_content_html() {
				return $this->ans_body( false );
			}

			public function get_content_plain() {
				return $this->ans_body( true );
			}

			/**
			 * The whole body.
			 *
			 * Uses the woocommerce_email_header / _footer ACTIONS rather than
			 * wc_get_template, matching email-event-reminder.php. That shape is
			 * proven on this site and on this theme; a second, different way of
			 * wrapping an email is a second thing that can render differently.
			 */
			protected function ans_body( $plain = false ) {
				$event_id = $this->ans_resolve_event_id();
				$cfg      = ans_tb_thanks_config( $event_id );
				$rt       = 'ans_tb_reminder_richtext';
				$has_rt   = function_exists( $rt );

				ob_start();
				if ( $plain ) {
					echo strtoupper( $this->get_heading() ) . "\n\n";
				} else {
					do_action( 'woocommerce_email_header', $this->get_heading(), $this );
				}

				$intro = $this->format_string( $cfg['copy']['intro'] );
				echo $has_rt ? $rt( $intro, $plain ) : esc_html( $intro );

				echo ans_tb_thanks_photo_strip( $event_id, $plain );

				if ( '' !== trim( (string) $cfg['copy']['highlights'] ) && $has_rt ) {
					echo $rt( $cfg['copy']['highlights'], $plain );
				}

				echo ans_tb_thanks_next_block( $event_id, $plain );

				if ( '' !== trim( (string) $cfg['copy']['closing'] ) && $has_rt ) {
					echo $rt( $this->format_string( $cfg['copy']['closing'] ), $plain );
				}

				echo ans_tb_thanks_unsub_footer( $this->get_recipient(), $plain );

				if ( $plain ) {
					// format_string, or {site_title} and {store_address} print literally.
					echo "\n" . wp_strip_all_tags( wptexturize( $this->format_string( get_option( 'woocommerce_email_footer_text' ) ) ) );
				} else {
					do_action( 'woocommerce_email_footer', $this );
				}
				return ob_get_clean();
			}
		}
	}

	if ( class_exists( 'ANS_TB_Post_Concert_Email' ) ) {
		$emails['ANS_TB_Post_Concert_Email'] = new ANS_TB_Post_Concert_Email();
	}
	return $emails;
} );

/* -------------------------------------------------------------------------
 * Config, sending, scheduling
 * ---------------------------------------------------------------------- */

/**
 * How long a slot stays sendable after its moment. The staleness window.
 *
 * Six hours, and every one of these has to be true at once. Far longer than the
 * 15-minute heartbeat, so no slot can be stepped over by a missed or slow tick.
 * Long enough to survive a realistic WP-Cron outage on a site where cron only
 * fires on traffic. Short enough that a 10:00 slot expires at 16:00 the SAME
 * local day and can never leak into the small hours. And far shorter than a
 * day, which is the property that makes arming a concert from last season inert
 * instead of an instant mass mailing.
 *
 * That last one is not theoretical. Before send times, the only thing standing
 * between "somebody ticks ans_thanks_enabled on an old concert" and "every
 * ticket holder gets mailed within the hour" was the exact-day match this
 * change removes. The window is what replaces it.
 *
 * THE CLAMP IS ARMOUR, NOT TIDINESS. This filter is the single edit that would
 * silently restore that hazard, and a filter is exactly how somebody widens a
 * rule without ever opening this file. Because a filter that quietly ignores
 * you is its own trap, every readout reports the effective window_seconds and a
 * window_clamped flag.
 *
 * @param bool|null $clamped Set by reference: true when the filter was overruled.
 * @return int Seconds. 15 minutes to 12 hours.
 */
function ans_tb_thanks_window( &$clamped = null ) {
	$raw     = (int) apply_filters( 'ans_tb_thanks_staleness_window', 6 * HOUR_IN_SECONDS );
	$w       = max( 15 * MINUTE_IN_SECONDS, min( 12 * HOUR_IN_SECONDS, $raw ) );
	$clamped = ( $w !== $raw );
	return $w;
}

/**
 * The scheduling-only read: when, if ever, does this event send?
 *
 * Split out of ans_tb_thanks_config() purely for cost. This is the ONLY thing
 * the heartbeat calls per event, 96 times a day, so it reads five meta values
 * and does date math and nothing else. It must never call
 * ans_tb_thanks_next_event(), never resolve copy, never touch images and never
 * query orders - all of which ans_tb_thanks_config() does, and all of which are
 * why that function is not on this path.
 *
 * Every wall-clock meaning in this file lives in the timestamps this function
 * returns, recomputed from wp_timezone() at every tick. That is what makes the
 * scheme DST-proof where cron cannot be: wp_schedule_event() recurring events
 * are fixed +N-second intervals and never re-anchor to local time, so a "daily
 * 09:05" job quietly becomes 08:05 the day Denver leaves MDT.
 *
 * @param int $event_id
 * @return array
 */
function ans_tb_thanks_schedule( $event_id ) {
	$event_id = (int) $event_id;
	$keys     = ans_tb_thanks_meta_keys();

	$out = array(
		'event_id'       => $event_id,
		'enabled'        => false,
		'mode'           => 'offsets',
		'event_ts'       => 0,
		'offsets'        => array(),
		'offsets_source' => 'default',
		'send_time'      => null,
		'time_source'    => 'default',
		'send_at'        => null,
		'slots'          => array(),
		'floored'        => array(),
		'cross_slots'    => array(),
		'schedule_error' => '',
	);

	$enabled_raw     = get_post_meta( $event_id, $keys['enabled'], true );
	$out['enabled']  = ( '' === trim( (string) $enabled_raw ) ) ? false : (bool) intval( $enabled_raw );
	$out['event_ts'] = function_exists( 'ans_tb_event_ts' ) ? (int) ans_tb_event_ts( $event_id ) : 0;

	$offsets = ans_tb_thanks_parse_offsets( get_post_meta( $event_id, $keys['offsets'], true ) );
	if ( $offsets ) {
		$out['offsets']        = $offsets;
		$out['offsets_source'] = 'event';
	} else {
		/* A flat 1 for every event - the morning after. See the function, and
		   the note there about why the past/future split must not come back. */
		$out['offsets'] = ans_tb_thanks_default_offsets();
	}

	$raw_send_at    = trim( (string) get_post_meta( $event_id, $keys['send_at'], true ) );
	$out['send_at'] = ( '' === $raw_send_at ) ? null : ans_tb_thanks_parse_send_at( $raw_send_at );

	/*
	 * MODE AND CROSS-REGIME TOKENS ARE SETTLED FIRST, BEFORE ANY EARLY RETURN.
	 *
	 * Two separate bugs lived in doing this last.
	 *
	 * One: the send_time block below used to run before the mode was known and
	 * could return on a bad value, so a concert that had been given an explicit
	 * ans_thanks_send_at was disarmed by a typo in ans_thanks_send_time - a
	 * field explicit mode never reads. The operator sees bad_time against an
	 * event whose send moment is spelled correctly and sitting right there.
	 *
	 * Two: every early return below used to hand back a schedule whose
	 * cross_slots was still the empty array it was initialised to. cross_slots
	 * is the double-send guard (see ans_tb_thanks_already_sent()); an error
	 * path returning an EMPTY guard is the one shape where the guard silently
	 * means "nothing has ever been sent".
	 */
	$explicit           = ( '' !== $raw_send_at );
	$out['mode']        = $explicit ? 'explicit' : 'offsets';
	$out['cross_slots'] = $explicit ? array_map( 'strval', $out['offsets'] ) : array( 'at' );

	/* Time of day: event -> global -> code default. */
	$raw_time = trim( (string) get_post_meta( $event_id, $keys['send_time'], true ) );
	if ( '' !== $raw_time ) {
		$parsed = ans_tb_thanks_parse_time( $raw_time );
		if ( null === $parsed ) {
			/*
			 * Stop - but only in the mode that actually uses this field.
			 * Somebody typed a time into THIS event and got it wrong; sending
			 * at an hour they did not choose is worse than not sending, and the
			 * state word bad_time puts it in front of a human on the board.
			 *
			 * In explicit mode send_at supplies the whole moment and send_time
			 * is never read, so the same typo is recorded (time_source
			 * 'invalid', send_time left null) and nothing is disarmed.
			 */
			$out['time_source'] = 'invalid';
			if ( ! $explicit ) {
				$out['schedule_error'] = 'bad_time';
				return $out;
			}
		} else {
			$out['send_time']   = $parsed;
			$out['time_source'] = 'event';
		}
	} else {
		$global = get_option( 'woocommerce_ans_post_concert_settings', array() );
		$g      = ( is_array( $global ) && isset( $global['send_time'] ) ) ? trim( (string) $global['send_time'] ) : '';
		$parsed = ( '' !== $g ) ? ans_tb_thanks_parse_time( $g ) : null;
		if ( null !== $parsed ) {
			$out['send_time']   = $parsed;
			$out['time_source'] = 'global';
		} else {
			/*
			 * A bad GLOBAL is ignored where a bad per-event value stops the
			 * event dead. Blast radius is the whole difference: one typo in a
			 * site-wide field must not silently disarm every concert at once.
			 */
			$fallback           = ans_tb_thanks_parse_time( apply_filters( 'ans_tb_thanks_default_time', '10:00' ) );
			$out['send_time']   = ( null === $fallback ) ? '10:00' : $fallback;
			$out['time_source'] = 'default';
		}
	}

	$tz = wp_timezone();

	if ( $explicit ) {
		/* mode and cross_slots - the offset tokens this mode suppresses - were
		   settled above, before the first early return. */

		if ( null === $out['send_at'] ) {
			/*
			 * No silent fall back to offset mode. The operator asked for ONE
			 * specific moment; quietly substituting a different rule because
			 * their typing was wrong is exactly how a wrong-day send happens.
			 */
			$out['schedule_error'] = 'bad_send_at';
			return $out;
		}
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $out['send_at'], $tz );
		if ( false === $dt ) {
			$out['schedule_error'] = 'bad_send_at';
			return $out;
		}
		/* No event date is needed here. This is the one mode that still works
		   when event_date_time is unreadable, and that is a feature. */
		$out['slots'] = array( 'at' => $dt->getTimestamp() );
	} else {
		if ( ! $out['event_ts'] ) {
			$out['schedule_error'] = 'no_event_date';
			return $out;
		}
		list( $h, $i ) = array_map( 'intval', explode( ':', $out['send_time'] ) );
		/*
		 * The '@' constructor is UTC-anchored, so setTimezone() BEFORE
		 * setTime() is mandatory: without it setTime(0,0,0) zeroes a UTC clock
		 * and every slot lands 6 or 7 hours off - the same class of error this
		 * whole change exists to remove.
		 *
		 * Zoned calendar math, not $ts + N*86400. Concert 2026-10-31 19:30 MDT,
		 * offset 1, 10:00 gives ts 1793552400 = 2026-11-01 10:00 MST. The step
		 * from the offset-0 slot is 90000 seconds, a 25-hour day. Handled
		 * because a real DateTimeZone is being carried, not a scalar.
		 */
		$day = ( new DateTimeImmutable( '@' . $out['event_ts'] ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
		foreach ( $out['offsets'] as $n ) {
			$m = $day->modify( '+' . (int) $n . ' day' )->setTime( $h, $i, 0 );
			$out['slots'][ (string) $n ] = $m->getTimestamp();
		}
	}

	/*
	 * The floor: no thank-you before the curtain. ans_tb_thanks_parse_offsets()
	 * accepts 0, so offset 0 with a 10:00 send time is a "thank you for being
	 * there" at 10am on concert morning. Reported rather than silently dropped -
	 * it shows up as before_event on the board and in the tick's skipped_events.
	 */
	foreach ( $out['slots'] as $token => $ts_slot ) {
		if ( $out['event_ts'] && $ts_slot < $out['event_ts'] ) {
			$out['floored'][ $token ] = $ts_slot;
			unset( $out['slots'][ $token ] );
		}
	}

	return $out;
}

/**
 * One slot's timestamp, for readouts and REST. A lookup, never a second
 * implementation of the math in ans_tb_thanks_schedule().
 *
 * @param int        $event_id
 * @param int|string $slot
 * @return int Timestamp, or 0 when that slot will not fire.
 */
function ans_tb_thanks_due_ts( $event_id, $slot ) {
	$sch  = ans_tb_thanks_schedule( (int) $event_id );
	$slot = (string) $slot;
	return isset( $sch['slots'][ $slot ] ) ? (int) $sch['slots'][ $slot ] : 0;
}

/**
 * Which slot an operator means when they name none.
 *
 * The most recent slot at or before now - the one that just came round -
 * falling back to the nearest slot in either direction when nothing has yet.
 * Deliberately window-free: this only LABELS a run, and nothing read here may
 * ever be able to cause or prevent a send. The window lives in
 * ans_tb_thanks_tick() and nowhere else, so there is no flag to get wrong.
 *
 * 1.27.2 defaulted this to days_since, which could name a slot the event does
 * not have: offsets (2) on day 5 wrote _ans_thanks_sent_<id>_5 and recorded a
 * send against a token nothing would ever consult again. That is a real bug
 * being fixed here, not a rename.
 *
 * @param array $sch ans_tb_thanks_schedule() result.
 * @return string|null Slot token, or null when this event has no slot at all.
 */
function ans_tb_thanks_resolve_slot( $sch ) {
	if ( empty( $sch['slots'] ) ) {
		return null;
	}
	$now  = time();
	$best = null;
	foreach ( $sch['slots'] as $token => $moment ) {
		if ( $moment <= $now && ( null === $best || $moment > $sch['slots'][ $best ] ) ) {
			$best = $token;
		}
	}
	if ( null !== $best ) {
		return (string) $best;
	}
	foreach ( $sch['slots'] as $token => $moment ) {
		if ( null === $best || abs( $moment - $now ) < abs( $sch['slots'][ $best ] - $now ) ) {
			$best = $token;
		}
	}
	return (string) $best;
}

/**
 * The state word for one slot.
 *
 * `missed` existing as a visible state is what makes a 6-hour window
 * acceptable: a fully missed day becomes something a person can SEE and act on
 * through POST /thanks/run, rather than something that silently auto-recovers
 * and mails people a day late with nobody in the loop.
 *
 * @param array      $sch
 * @param int|string $slot
 * @param bool       $has_sent Whether any order carries a sent record for it.
 * @return string
 */
function ans_tb_thanks_slot_state( $sch, $slot, $has_sent = false ) {
	$slot = (string) $slot;

	if ( empty( $sch['enabled'] ) ) {
		return 'disabled';
	}
	if ( ! empty( $sch['schedule_error'] ) ) {
		return (string) $sch['schedule_error'];   // no_event_date | bad_time | bad_send_at
	}
	if ( $has_sent ) {
		return 'sent';
	}
	if ( isset( $sch['floored'][ $slot ] ) ) {
		return 'before_event';
	}
	if ( ! isset( $sch['slots'][ $slot ] ) ) {
		return 'superseded';
	}

	$now    = time();
	$moment = (int) $sch['slots'][ $slot ];
	if ( $now < $moment ) {
		return 'pending';
	}
	return ( ( $now - $moment ) <= ans_tb_thanks_window() ) ? 'due_now' : 'missed';
}

/**
 * Every slot token a readout will show for this event.
 *
 * Its own function so that a caller computing SENT COUNTS counts exactly the
 * tokens ans_tb_thanks_slot_rows() is about to render. /thanks/schedule used to
 * count over $sch['slots'] alone while rendering this wider set, so a slot that
 * genuinely sent under the other regime came back `superseded` with sent:null -
 * the row a person opens the board to read, answering "no idea" about a send
 * that definitely happened.
 *
 * @param array $sch ans_tb_thanks_schedule() result.
 * @return string[]
 */
function ans_tb_thanks_slot_tokens( $sch ) {
	$tokens = array_map( 'strval', array_keys( (array) $sch['slots'] ) );
	foreach ( array_keys( (array) $sch['floored'] ) as $t ) {
		$tokens[] = (string) $t;
	}
	if ( 'explicit' === $sch['mode'] ) {
		foreach ( (array) $sch['cross_slots'] as $t ) {
			$tokens[] = (string) $t;
		}
	}
	return array_values( array_unique( $tokens ) );
}

/**
 * Per-slot sent/pending counts for one event, or null when it is too big.
 *
 * The counts are what turn `sent` from an advertised state word into one a
 * readout can actually emit. Without them ans_tb_thanks_slot_state() is handed
 * $has_sent = false for every slot and a completed send reads as `missed` or
 * `due_now` - the two words that make a person fire a second one by hand.
 *
 * One meta read per order per token, so it is capped: on the one route somebody
 * opens to find out whether a send went, answering without the counts beats
 * timing out. A null return is the caller's cue to flag counts_omitted rather
 * than report zeroes, which would read as "nothing has been sent".
 *
 * @param int      $event_id
 * @param int[]    $order_ids
 * @param array    $sch ans_tb_thanks_schedule() result.
 * @param int      $cap Orders above which the counts are refused. 0 = no cap.
 * @return array|null token => array( sent, pending ), or null when capped out.
 */
function ans_tb_thanks_sent_counts( $event_id, $order_ids, $sch, $cap = 500 ) {
	$event_id  = (int) $event_id;
	$order_ids = array_map( 'intval', (array) $order_ids );
	if ( $cap > 0 && count( $order_ids ) > $cap ) {
		return null;
	}

	$counts = array();
	foreach ( ans_tb_thanks_slot_tokens( $sch ) as $token ) {
		$key = ans_tb_thanks_sent_key( $event_id, $token );
		$n   = 0;
		foreach ( $order_ids as $oid ) {
			if ( '' !== trim( (string) get_post_meta( $oid, $key, true ) ) ) {
				$n++;
			}
		}
		$counts[ (string) $token ] = array(
			'sent'    => $n,
			'pending' => count( $order_ids ) - $n,
		);
	}
	return $counts;
}

/**
 * One row per slot, for the readouts.
 *
 * Includes the floored slots and, in explicit mode, the offset tokens that
 * send_at suppresses. A slot that will NOT fire is precisely the thing somebody
 * checking "did the thank-you go?" needs to see.
 *
 * @param array      $sch
 * @param array|null $sent_counts token => array( sent, pending ), or null.
 * @return array
 */
function ans_tb_thanks_slot_rows( $sch, $sent_counts = null ) {
	$tokens = ans_tb_thanks_slot_tokens( $sch );

	$rows = array();
	foreach ( $tokens as $t ) {
		$ts = 0;
		if ( isset( $sch['slots'][ $t ] ) ) {
			$ts = (int) $sch['slots'][ $t ];
		} elseif ( isset( $sch['floored'][ $t ] ) ) {
			$ts = (int) $sch['floored'][ $t ];
		}
		$sent = ( is_array( $sent_counts ) && ! empty( $sent_counts[ $t ]['sent'] ) );

		$row = array(
			'slot'      => $t,
			'due_ts'    => $ts,
			'due_local' => $ts ? wp_date( 'Y-m-d H:i T', $ts ) : null,
			'state'     => ans_tb_thanks_slot_state( $sch, $t, $sent ),
		);
		if ( is_array( $sent_counts ) ) {
			$row['sent']    = isset( $sent_counts[ $t ]['sent'] ) ? (int) $sent_counts[ $t ]['sent'] : null;
			$row['pending'] = isset( $sent_counts[ $t ]['pending'] ) ? (int) $sent_counts[ $t ]['pending'] : null;
		}
		$rows[] = $row;
	}
	return $rows;
}

/**
 * Is this event so far past that no slot of its could possibly still be live?
 *
 * The cheap reject that makes a 96-times-a-day pass affordable: past seasons
 * fall out for one integer comparison, before any time parsing and before a
 * single DateTimeImmutable is constructed.
 *
 * The +2 days of slack is deliberate slop covering any time of day plus a DST
 * hour. A reject that is too eager silently drops a real send; one that is too
 * lazy costs a few object constructions. Only one of those is a bug.
 *
 * @param int      $event_id
 * @param int|null $window
 * @return bool
 */
function ans_tb_thanks_is_long_past( $event_id, $window = null ) {
	$event_id = (int) $event_id;
	$keys     = ans_tb_thanks_meta_keys();

	/* An explicit moment is never inferred from the event date. */
	if ( '' !== trim( (string) get_post_meta( $event_id, $keys['send_at'], true ) ) ) {
		return false;
	}
	if ( ! function_exists( 'ans_tb_event_ts' ) ) {
		return false;
	}
	$ts = (int) ans_tb_event_ts( $event_id );
	if ( ! $ts ) {
		return false;   // Nothing to judge by; schedule() reports it as no_event_date.
	}

	$offsets = ans_tb_thanks_parse_offsets( get_post_meta( $event_id, $keys['offsets'], true ) );
	if ( ! $offsets ) {
		/* The same default ans_tb_thanks_schedule() applies, and it has to be:
		   this function decides whether that schedule is even worth computing.
		   A smaller default here than there rejects a live slot. */
		$offsets = ans_tb_thanks_default_offsets();
	}
	$max    = $offsets ? max( $offsets ) : 0;
	$window = ( null === $window ) ? ans_tb_thanks_window() : (int) $window;

	return time() > ( $ts + ( $max + 2 ) * DAY_IN_SECONDS + $window );
}

/**
 * Resolved thank-you config for one event: event -> global -> code default.
 *
 * `highlights` has NO global layer, on purpose. A site-wide default list of
 * concert highlights is wrong for every concert but the one it was written for,
 * and a plausible wrong answer is worse than an absent one. Same reasoning as
 * directions and parking in ans_tb_reminder_logistics().
 *
 * @param int $event_id
 * @return array
 */
function ans_tb_thanks_config( $event_id ) {
	$event_id = (int) $event_id;
	$keys     = ans_tb_thanks_meta_keys();
	$global   = get_option( 'woocommerce_ans_post_concert_settings', array() );
	$global   = is_array( $global ) ? $global : array();

	$defaults = array(
		'subject' => 'Thank you for being there - {event_title}',
		'heading' => 'Thank you for being there',
		'intro'   => 'Thank you for joining us for {event_title}. An audience changes what happens in a room, and yours did.',
		'closing' => "With our thanks,\n\nArs Nova Singers",
	);

	$resolved = array();
	$sources  = array();
	foreach ( array( 'subject', 'heading', 'intro', 'closing' ) as $field ) {
		$event_val = trim( (string) get_post_meta( $event_id, $keys[ $field ], true ) );
		if ( '' !== $event_val ) {
			$resolved[ $field ] = $event_val;
			$sources[ $field ]  = 'event';
			continue;
		}
		$g = isset( $global[ $field ] ) ? trim( (string) $global[ $field ] ) : '';
		if ( '' !== $g ) {
			$resolved[ $field ] = $g;
			$sources[ $field ]  = 'global';
			continue;
		}
		$resolved[ $field ] = $defaults[ $field ];
		$sources[ $field ]  = 'default';
	}

	$resolved['highlights'] = trim( (string) get_post_meta( $event_id, $keys['highlights'], true ) );
	$sources['highlights']  = ( '' !== $resolved['highlights'] ) ? 'event' : 'none';

	/*
	 * The scheduling half comes from ans_tb_thanks_schedule() rather than being
	 * recomputed here, so the config an operator READS and the moments the
	 * heartbeat ACTS on can never drift apart. The split exists for cost - see
	 * the note at the top of ans_tb_thanks_tick() before undoing it.
	 */
	$sch                  = ans_tb_thanks_schedule( $event_id );
	$offsets              = $sch['offsets'];
	$sources['offsets']   = $sch['offsets_source'];
	$sources['send_time'] = $sch['time_source'];
	$sources['send_at']   = $sch['send_at'] ? 'event' : 'none';

	$enabled_raw = get_post_meta( $event_id, $keys['enabled'], true );
	$images_raw  = trim( (string) get_post_meta( $event_id, $keys['images'], true ) );
	$image_ids   = $images_raw ? array_values( array_filter( array_map( 'intval', preg_split( '/[\s,]+/', $images_raw ) ) ) ) : array();
	$next        = ans_tb_thanks_next_event( $event_id );

	return array(
		'event_id'   => $event_id,
		'enabled'    => ( '' === trim( (string) $enabled_raw ) ) ? false : (bool) intval( $enabled_raw ),
		'offsets'    => $offsets,
		'days_since' => ans_tb_thanks_days_since( $event_id ),
		'copy'       => $resolved,
		'images'     => $image_ids,
		'next_event' => $next ? array( 'id' => $next['id'], 'title' => $next['title'], 'when' => $next['when'] ) : null,
		'sources'    => $sources,
		/* Additive. Every key above keeps its 1.27.2 shape and meaning: the REST
		   GET and ans_tb_thanks_send() both read them, and the portal and the
		   bridge are deployed separately, so a reshape here breaks a deploy that
		   has not happened yet. */
		'schedule'   => $sch,
	);
}

/**
 * Per-order idempotency key. Distinct from the reminder's so the two can never
 * be confused.
 *
 * For an offset slot the token is the integer as a decimal string, so the KEY
 * FORMAT is byte-identical to 1.27.2's and no migration is needed.
 *
 * What that identical format does NOT buy, and used to be claimed here: that
 * nobody gets a second copy. The key is only as stable as the TOKEN handed to
 * it, and the default offset moved from 2 to 1. An event armed under 1.27.2
 * that already mailed everybody at slot '2' asks this function for
 * _ans_thanks_sent_<id>_1 under the new default - a key no order has ever
 * carried - and mails the whole list again. ans_tb_thanks_already_sent() is the
 * only thing standing there, and it no longer trusts any token list: it scans
 * every key with this prefix on the order. (A past/future default split was
 * tried as a second guard and removed; see ans_tb_thanks_default_offsets() for
 * why it could never have worked.)
 *
 * For an explicit send the token is the literal constant 'at', and that
 * constant is the single most important decision in this change. A token
 * derived from the send_at value would mean that EDITING ans_thanks_send_at
 * after a send mints a brand-new slot carrying no sent record - and the next
 * heartbeat mails every ticket holder all over again. A constant makes that
 * impossible: the edited value resolves to the same already-populated key and
 * nothing sends. The cost is that a genuine reschedule after a send does not
 * re-send, which is the safe direction and which POST /thanks/run with force
 * already covers.
 *
 * @param int        $event_id
 * @param int|string $slot
 * @return string
 */
function ans_tb_thanks_sent_key( $event_id, $slot ) {
	$slot = (string) $slot;
	$slot = is_numeric( $slot ) ? (string) (int) $slot : preg_replace( '/[^A-Za-z0-9]/', '', $slot );
	return '_ans_thanks_sent_' . (int) $event_id . '_' . $slot;
}

/**
 * Has this order already had this thank-you - under ANY regime this event has
 * ever run under?
 *
 * ASK THE ORDER, DO NOT ASK A TOKEN LIST. This used to test the current slot
 * plus the current cross-regime tokens, which covers the regime FLIP - explicit
 * send fires, operator clears ans_thanks_send_at, the event reverts to offsets,
 * an offset slot is still inside its 6-hour window, and without the check every
 * ticket holder is mailed again - but not a token that simply stopped being
 * computed. The 2 -> 1 default change is exactly that: an event armed under
 * 1.27.2 that sent at slot '2' would ask about '1' and 'at', find neither, and
 * mail everybody a second copy of an email they already have. Any future change
 * to how tokens are derived has the same shape, and a guard that has to be
 * updated alongside every such change is a guard that will be missed once.
 *
 * One get_post_meta( $order_id ) pulls the order's whole meta row from the
 * cache that is already primed, so scanning by prefix costs no more queries
 * than the two or three individual reads it replaces.
 *
 * Returns the MATCHING slot rather than a bool so the caller can say which
 * regime already covered this order. Callers must test '' !== the result -
 * a bare `if ( ans_tb_thanks_already_sent(...) )` is now wrong for slot '0'.
 *
 * @param int    $order_id
 * @param int    $event_id
 * @param string $slot        The slot being run.
 * @param array  $cross_slots Tokens from the OTHER regime, from schedule()['cross_slots'].
 * @param array  $live_slots  This event's OWN currently-live tokens, from
 *                            array_keys( schedule()['slots'] ). A prefix hit on
 *                            one of these is a sibling send, not a duplicate.
 * @return string '' when clear, otherwise the slot token that already sent.
 */
function ans_tb_thanks_already_sent( $order_id, $event_id, $slot, $cross_slots = array(), $live_slots = array() ) {
	$order_id = (int) $order_id;
	$event_id = (int) $event_id;
	$all      = get_post_meta( $order_id );
	$all      = is_array( $all ) ? $all : array();

	$first = function ( $v ) {
		if ( is_array( $v ) ) {
			$v = reset( $v );
		}
		return trim( (string) $v );
	};

	/*
	 * The named tokens are tested first so the token REPORTED back is the one
	 * the caller expects - 'already_sent' against the slot being run, rather
	 * than whichever key happened to sort first in the meta row.
	 */
	foreach ( array_merge( array( (string) $slot ), array_map( 'strval', (array) $cross_slots ) ) as $s ) {
		$k = ans_tb_thanks_sent_key( $event_id, $s );
		if ( isset( $all[ $k ] ) && '' !== $first( $all[ $k ] ) ) {
			return (string) $s;
		}
	}

	/*
	 * THE SCAN SKIPS THIS EVENT'S OWN OTHER LIVE SLOTS, AND IT HAS TO.
	 *
	 * A bare prefix scan treats every foreign key as a duplicate, which quietly
	 * breaks multi-slot events: offsets "2, 14" sends on day 2, and on day 14
	 * every single order carries _ans_thanks_sent_<id>_2, so every one of them
	 * comes back already_sent_other_regime and the day-14 follow-up goes to
	 * nobody at all. Nothing reports it as a fault - the run returns 140 tidy
	 * skips - and 1.27.2 sent both, so it is a straight regression.
	 *
	 * A token that is ANOTHER LIVE SLOT of this same event is therefore a
	 * sibling send, not a second copy of this one. Everything else still counts:
	 * 'at' against an offset token, an offset token against 'at', or a token
	 * that has simply stopped being computed (offsets edited from "2" to "1")
	 * are all foreign to the live set and all still block - which is the whole
	 * point of scanning by prefix instead of trusting a token list.
	 */
	$live   = array_map( 'strval', (array) $live_slots );
	$prefix = '_ans_thanks_sent_' . $event_id . '_';
	foreach ( $all as $k => $v ) {
		if ( 0 !== strpos( (string) $k, $prefix ) ) {
			continue;
		}
		if ( '' === $first( $v ) ) {
			continue;
		}
		$token = (string) substr( (string) $k, strlen( $prefix ) );
		if ( $token !== (string) $slot && in_array( $token, $live, true ) ) {
			continue;
		}
		return $token;
	}
	return '';
}

/**
 * Send one thank-you.
 *
 * The opt-out check lives HERE, at the only place that can call wp_mail(),
 * rather than at the callers. One check at the choke point outlives three at
 * the callers, and a future caller cannot route around it - the same reasoning
 * the singers portal used for its notification master switch after publishing a
 * project silently emailed the whole choir.
 *
 * @param int        $order_id
 * @param int        $event_id
 * @param int|string $slot       Slot token for the record: an offset, or 'at'.
 * @param string     $divert_to  Non-empty = a preview to one named address.
 * @return array
 */
function ans_tb_thanks_send( $order_id, $event_id, $slot = 0, $divert_to = '' ) {
	$order_id = (int) $order_id;
	$event_id = (int) $event_id;
	$order    = wc_get_order( $order_id );
	if ( ! $order ) {
		return array( 'order_id' => $order_id, 'sent' => false, 'reason' => 'no_such_order' );
	}
	$to = $divert_to ? $divert_to : $order->get_billing_email();

	if ( ! $divert_to && function_exists( 'ans_tb_is_opted_out' ) && ans_tb_is_opted_out( $to ) ) {
		return array( 'order_id' => $order_id, 'to' => $to, 'sent' => false, 'reason' => 'opted_out' );
	}

	$mailer = WC()->mailer();
	$emails = $mailer->get_emails();
	$email  = isset( $emails['ANS_TB_Post_Concert_Email'] ) ? $emails['ANS_TB_Post_Concert_Email'] : null;
	if ( ! $email ) {
		return array( 'order_id' => $order_id, 'sent' => false, 'reason' => 'email_not_registered' );
	}

	/*
	 * WooCommerce's own "Enable this email" checkbox, honoured HERE and read
	 * BEFORE the pre_option filter below - which is the only place it can be
	 * read at all, because that filter replaces the whole settings array with a
	 * three-key one carrying 'enabled' => 'yes'. The email object then reports
	 * itself enabled no matter what the box says, so the one switch a person
	 * finds by going to WooCommerce > Settings > Emails and unticking the row
	 * was inert: it changed the stored option, the option was overwritten in
	 * flight, and every ticket holder was mailed anyway with nothing reporting
	 * why. Of the two ways to fix that, this refuses EXPLICITLY - reason
	 * 'email_disabled' in the run report - rather than dropping the injected
	 * 'yes' and letting WC_Email fall silent with sent:false and no reason;
	 * somebody reading a run of 150 refusals needs to be told which switch.
	 *
	 * The injected 'yes' stays for the sends that get past this gate: the
	 * filtered array must not read as disabled just because it is partial.
	 *
	 * A PREVIEW is deliberately still allowed. It goes to one named operator
	 * address, and checking what the email looks like before arming it is the
	 * normal reason the box is off. The per-event ans_thanks_enabled meta
	 * remains the arming switch; this is the site-wide master.
	 */
	if ( ! $divert_to ) {
		$stored = get_option( 'woocommerce_ans_post_concert_settings', array() );
		if ( is_array( $stored ) && isset( $stored['enabled'] ) && 'no' === $stored['enabled'] ) {
			return array(
				'order_id' => $order_id,
				'to'       => $to,
				'sent'     => false,
				'reason'   => 'email_disabled',
			);
		}
	}

	/*
	 * READ THE CONFIG BEFORE add_filter( 'pre_option_...' ) BELOW, and keep it
	 * that way. That filter replaces the whole option with a 3-key array, so a
	 * config read underneath it would see no send_time and the global time layer
	 * would silently vanish. Harmless today because due-ness is settled long
	 * before the send - fatal to the global default the day these lines get
	 * tidied into a different order.
	 */
	$cfg = ans_tb_thanks_config( $event_id );

	/*
	 * Per-event copy is injected by filtering the OPTION the email reads, then
	 * re-running init_settings(). Setting $email->subject directly does not
	 * survive get_subject(), which re-reads its own settings.
	 */
	$merged = array_filter(
		array(
			'enabled' => 'yes',
			'subject' => $cfg['copy']['subject'],
			'heading' => $cfg['copy']['heading'],
		),
		function ( $v ) {
			return '' !== trim( (string) $v );
		}
	);
	$pre = function () use ( $merged ) {
		return $merged;
	};
	add_filter( 'pre_option_woocommerce_ans_post_concert_settings', $pre );
	$email->init_settings();

	$divert = function ( $recipient ) use ( $divert_to ) {
		return $divert_to ? $divert_to : $recipient;
	};
	if ( $divert_to ) {
		add_filter( 'woocommerce_email_recipient_ans_post_concert', $divert, 99 );
	}

	$email->ans_event_id = $event_id;
	$result              = $email->trigger( $order_id );
	$email->ans_event_id = 0;

	if ( $divert_to ) {
		remove_filter( 'woocommerce_email_recipient_ans_post_concert', $divert, 99 );
	}
	remove_filter( 'pre_option_woocommerce_ans_post_concert_settings', $pre );
	$email->init_settings();

	if ( $result && ! $divert_to ) {
		update_post_meta( $order_id, ans_tb_thanks_sent_key( $event_id, $slot ), current_time( 'mysql' ) );
	}

	return array(
		'order_id' => $order_id,
		'to'       => $to,
		'sent'     => (bool) $result,
		'preview'  => (bool) $divert_to,
		'event_id' => $event_id,
		'slot'     => (string) $slot,
		/* Kept alongside 'slot' for anything outside this repo still reading it.
		   Null rather than (int) 'at' = 0, which would read as offset zero. */
		'offset'   => is_numeric( $slot ) ? (int) $slot : null,
	);
}

/**
 * Send the thank-you for one event.
 *
 * dry_run defaults TRUE. An accidental call must do nothing - this email reaches
 * every ticket holder of a concert at once, and there is no recalling it.
 *
 * IT DOES CONSULT THE PER-EVENT ARMING SWITCH, in both modes - reason
 * 'not_enabled'. See the note at the top of the body for why a disarmed concert
 * is refused here rather than flagged, and why a dry run gets the same answer.
 *
 * IT NEVER CONSULTS THE STALENESS WINDOW. An operator hitting POST /thanks/run
 * has stated intent, and this route keeps working exactly as it always has,
 * including for a slot the heartbeat has already given up on as `missed`. There
 * is deliberately no $respect_window parameter anywhere in this file: the
 * window lives in ans_tb_thanks_tick() and nowhere else, so there is no flag
 * whose wrong value mass-mails a concert.
 *
 * @param int        $event_id
 * @param int|string $slot     Null resolves it. Numeric = an offset; 'at' = the
 *                             explicit send. An existing caller passing an int
 *                             still works.
 * @param bool       $dry_run
 * @param bool       $force
 * @return array
 */
function ans_tb_thanks_run_event( $event_id, $slot = null, $dry_run = true, $force = false ) {
	$event_id = (int) $event_id;
	$cfg      = ans_tb_thanks_config( $event_id );
	$sch      = $cfg['schedule'];

	/*
	 * IS THIS CONCERT STILL ARMED? This is the only function that mails a whole
	 * audience over REST, and it was the one path that never asked.
	 *
	 * Jonathan spots wrong photos on a concert that mailed last night and
	 * disarms it with POST thanks-config enabled=0, meaning to fix the images
	 * and re-arm. The board then drops it - GET /thanks/schedule filters to
	 * armed events only, so the row simply disappears. Kim, working from a
	 * schedule readout she pulled an hour earlier, sees that slot sitting at
	 * `missed` and does the documented thing for a missed slot: POST
	 * /thanks/run dry_run=false. The moment is in the past, so not_yet_due
	 * passes, and the entire ticket list of a concert a colleague had
	 * deliberately taken out of service is mailed. The same gap turns a
	 * wrong-id typo - 4312 for 4132 - into a full audience send against an
	 * event nobody ever armed.
	 *
	 * Nothing else caught it. $sch['enabled'] was computed here and went
	 * unread, and WC_Email::is_enabled() inside trigger() cannot help: the
	 * pre_option filter in ans_tb_thanks_send() injects 'enabled' => 'yes'
	 * before it runs. The site-wide WooCommerce checkbox IS enforced in
	 * ans_tb_thanks_send(), as 'email_disabled'; this is the per-event arming
	 * switch that docblock names, and it belongs HERE rather than there - a
	 * per-order gate reports one event-level fact 150 times over, and a dry run
	 * never reaches send() at all.
	 *
	 * Asked before the slot is even resolved, because which slot was meant is
	 * not the question when the concert is out of service.
	 * ans_tb_thanks_slot_state() reports 'disabled' ahead of schedule_error for
	 * the same reason, so the board and this route agree on what matters first.
	 *
	 * A DRY RUN REFUSES TOO rather than flagging and listing recipients anyway.
	 * Every other refusal on this route - unknown_slot, before_event,
	 * no_moment, not_yet_due - answers in both modes, and a dry run's whole job
	 * is to say what a real run would do. A would_send list of 140 addresses
	 * for a disarmed concert answers a different question from the one asked,
	 * and an operator reading that as confirmation is exactly how Kim got here.
	 * The diagnostic is not lost: force=1 with dry_run=1 still lists every
	 * recipient and mails nobody.
	 *
	 * force runs it in both modes, like the due-ness refusals below. Sending
	 * from a disarmed event is a decision a person is allowed to make - the
	 * re-send after the photos are fixed, without re-arming a concert whose
	 * season is over - it just cannot be one they make by accident.
	 *
	 * The heartbeat is unaffected: ans_tb_thanks_tick() drops unarmed events
	 * before it ever calls this, so nothing reaches this refusal from cron.
	 */
	if ( ! $force && empty( $sch['enabled'] ) ) {
		return array(
			'event_id' => $event_id,
			'refused'  => true,
			'reason'   => 'not_enabled',
			'note'     => 'Thank-yous are not armed on this event (ans_thanks_enabled is off), which is also why it is '
				. 'absent from /thanks/schedule. A dry run refuses for the same reason a real one does. Arm it with '
				. 'POST thanks-config enabled=1, or pass force=1 to send from a disarmed event - force=1 with dry_run=1 '
				. 'lists the recipients without mailing anyone.',
		);
	}

	if ( null === $slot || '' === $slot ) {
		$slot = ans_tb_thanks_resolve_slot( $sch );
		if ( null === $slot ) {
			return array(
				'event_id'       => $event_id,
				'refused'        => true,
				'reason'         => 'unresolved',
				'schedule_error' => $sch['schedule_error'],
				'note'           => 'This event has no send slot to name. Fix the schedule, or pass an explicit slot.',
			);
		}
	}
	$slot = (string) $slot;

	/*
	 * IS THIS SLOT EVEN THIS EVENT'S? A caller-supplied token was taken on
	 * trust and passed straight to the send loop, so POST /thanks/run with
	 * slot=7 on an event whose only offset is 1 mailed the entire audience,
	 * reported due_ts 0, and wrote _ans_thanks_sent_<id>_7 - a key no readout
	 * ever consults, so the real slot still looked unsent and would send again.
	 * The tokens are listed back rather than merely refused: the operator who
	 * typed 7 is most often one keystroke from the right answer.
	 *
	 * cross_slots is in the accepted set in BOTH modes, not just the one that
	 * renders it. An event that has flipped regimes still has a real token from
	 * the other one - 'at' after send_at was cleared, an offset after it was
	 * set - and re-running that token is a legitimate thing to want. It is also
	 * the only accepted token with no moment of its own, which is why accepting
	 * it here is not the end of the story: the no_moment refusal below is what
	 * stops an unbounded token reaching the send loop.
	 */
	$known = array_values( array_unique( array_merge(
		ans_tb_thanks_slot_tokens( $sch ),
		array_map( 'strval', (array) $sch['cross_slots'] )
	) ) );

	/*
	 * WHAT IS ADVERTISED IS NARROWER THAN WHAT IS ACCEPTED.
	 *
	 * cross_slots is synthesised in BOTH modes whether or not the event has
	 * ever been near the other regime: in offsets mode it is unconditionally
	 * array( 'at' ), and in explicit mode it is whatever offsets resolved to,
	 * the code default included. Listing those back to an operator who mistyped
	 * a slot reads as "'at' is a valid token for this concert" on an event that
	 * has never had an ans_thanks_send_at in its life - and the very next thing
	 * that operator does is type the token we just suggested. It is still
	 * ACCEPTED, because an event that genuinely flipped regimes has a real
	 * token from the other one; it is only ADVERTISED when the event's own meta
	 * shows it has actually carried that regime.
	 */
	$meta_keys = ans_tb_thanks_meta_keys();
	$carried   = ( 'explicit' === $sch['mode'] )
		? ( 'event' === $sch['offsets_source'] )
		: metadata_exists( 'post', $event_id, $meta_keys['send_at'] );

	$advertised = array_map( 'strval', array_keys( (array) $sch['slots'] ) );
	foreach ( array_keys( (array) $sch['floored'] ) as $t ) {
		$advertised[] = (string) $t;
	}
	if ( $carried ) {
		foreach ( (array) $sch['cross_slots'] as $t ) {
			$advertised[] = (string) $t;
		}
	}
	$advertised = array_values( array_unique( $advertised ) );

	if ( ! $force && ! in_array( $slot, $known, true ) ) {
		return array(
			'event_id' => $event_id,
			'refused'  => true,
			'reason'   => 'unknown_slot',
			'slot'     => $slot,
			'slots'    => $advertised,
			'note'     => 'This event has no such slot. Use one of "slots", or pass force=1 to run it anyway.',
		);
	}

	/*
	 * HAS THE MOMENT ARRIVED? ans_tb_thanks_resolve_slot() falls back to the
	 * NEAREST slot in either direction when nothing has come round yet, so on
	 * an armed concert three weeks out an unqualified POST /thanks/run resolved
	 * to a slot in the future and mailed the whole audience BEFORE the concert
	 * - a thank-you for a performance nobody has heard - and then wrote the
	 * idempotency key, so the real send was suppressed for good.
	 *
	 * These three refusals - before_event, no_moment, not_yet_due - are the
	 * whole of this route's bound, and they are a bound on the FUTURE only.
	 * None of them consults the staleness window: a slot already past stays
	 * runnable forever here, including one the heartbeat has given up on as
	 * `missed`, which is the whole reason this route exists. force overrides
	 * all three, for a deliberate early send.
	 */
	$moment = isset( $sch['slots'][ $slot ] ) ? (int) $sch['slots'][ $slot ] : 0;

	/*
	 * A FLOORED SLOT IS NEVER RUNNABLE WITHOUT FORCE, AND ITS FLOORED MOMENT IS
	 * NOT A DUE-NESS BOUND.
	 *
	 * A floored slot is one whose moment falls BEFORE the curtain - offset 0
	 * with a 10:00 send time on a 19:30 concert, or a send_at typed for the
	 * morning of. Falling back to that moment here meant the not_yet_due check
	 * passed the instant 10:00 came round, and the whole audience was thanked
	 * for a concert that had not started yet. The schedule already refuses to
	 * fire these - that is what the floor in ans_tb_thanks_schedule() is - so
	 * this route must not be the one door that lets them through.
	 *
	 * force still runs it, because a deliberate early send is a decision a
	 * person is allowed to make; it just cannot be one they make by accident.
	 */
	if ( ! $force && isset( $sch['floored'][ $slot ] ) ) {
		$floor = (int) $sch['floored'][ $slot ];
		return array(
			'event_id'    => $event_id,
			'refused'     => true,
			'reason'      => 'before_event',
			'slot'        => $slot,
			'due_ts'      => $floor,
			'due_local'   => $floor ? wp_date( 'Y-m-d H:i T', $floor ) : null,
			'event_ts'    => $sch['event_ts'],
			'event_local' => $sch['event_ts'] ? wp_date( 'Y-m-d H:i T', $sch['event_ts'] ) : null,
			'note'        => 'That slot falls before the concert starts, so it never fires on its own. '
				. 'Fix the offset or send_at, or pass force=1 to thank an audience that has not heard the concert yet.',
		);
	}

	/*
	 * A TOKEN WITH NO MOMENT CANNOT BE BOUNDED, SO IT CANNOT BE RUN.
	 *
	 * The not_yet_due check below reads the moment out of slots (and used to
	 * fall back to floored). A cross-regime token has neither - in offsets mode
	 * cross_slots is unconditionally array( 'at' ) - so $moment stayed 0 and
	 * the `$moment &&` in that condition short-circuited the entire guard.
	 * POST /thanks/run slot=at on any ordinary concert therefore mailed the
	 * whole audience with no due-ness check of any kind, and the unknown_slot
	 * refusal above had helpfully listed 'at' as a valid token to try.
	 *
	 * force still runs it: re-running a genuine 'at' after send_at was cleared
	 * is a real thing to want, and it is a decision a person makes.
	 */
	if ( ! $force && ! $moment ) {
		$with_moments = array_values( array_unique( array_merge(
			array_map( 'strval', array_keys( (array) $sch['slots'] ) ),
			array_map( 'strval', array_keys( (array) $sch['floored'] ) )
		) ) );
		return array(
			'event_id' => $event_id,
			'refused'  => true,
			'reason'   => 'no_moment',
			'slot'     => $slot,
			'slots'    => $with_moments,
			'note'     => 'That slot has no moment on this event, so there is nothing to check it against. '
				. 'Use one of "slots" - a floored one still needs force - or pass force=1 to run it anyway.',
		);
	}

	if ( ! $force && $moment > time() ) {
		return array(
			'event_id'  => $event_id,
			'refused'   => true,
			'reason'    => 'not_yet_due',
			'slot'      => $slot,
			'due_ts'    => $moment,
			'due_local' => wp_date( 'Y-m-d H:i T', $moment ),
			'note'      => 'That slot has not come round yet. Wait for it, or pass force=1 to send early.',
		);
	}

	if ( ! $dry_run && function_exists( 'ans_tb_reminder_is_live' ) && ! ans_tb_reminder_is_live() ) {
		return array(
			'event_id' => $event_id,
			'refused'  => true,
			'reason'   => 'not_production',
			'note'     => 'Staging is a clone carrying real patron addresses and Live mail credentials. Real sends are refused here. Use a preview.',
		);
	}

	$order_ids = function_exists( 'ans_tb_reminder_event_order_ids' ) ? ans_tb_reminder_event_order_ids( $event_id ) : array();
	$would     = array();
	$skipped   = array();
	$sent      = array();

	foreach ( $order_ids as $oid ) {
		$order = wc_get_order( $oid );
		if ( ! $order ) {
			continue;
		}
		$to = $order->get_billing_email();

		if ( ! $force ) {
			/* array_keys( slots ) - this event's OTHER live tokens - so a
			   day-2 send does not suppress the day-14 follow-up. See the scan
			   in ans_tb_thanks_already_sent(). */
			$hit = ans_tb_thanks_already_sent(
				$oid,
				$event_id,
				$slot,
				$sch['cross_slots'],
				array_map( 'strval', array_keys( (array) $sch['slots'] ) )
			);
			if ( '' !== $hit ) {
				$skipped[] = array(
					'order_id' => $oid,
					'to'       => $to,
					'reason'   => ( $hit === $slot ) ? 'already_sent' : 'already_sent_other_regime',
					'slot'     => $hit,
				);
				continue;
			}
		}
		if ( function_exists( 'ans_tb_is_opted_out' ) && ans_tb_is_opted_out( $to ) ) {
			$skipped[] = array( 'order_id' => $oid, 'to' => $to, 'reason' => 'opted_out' );
			continue;
		}
		if ( $dry_run ) {
			$would[] = array( 'order_id' => $oid, 'to' => $to );
			continue;
		}
		$sent[] = ans_tb_thanks_send( $oid, $event_id, $slot );
	}

	$due_ts = isset( $sch['slots'][ $slot ] ) ? (int) $sch['slots'][ $slot ] : 0;

	return array(
		'event_id'   => $event_id,
		'title'      => html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ),
		'slot'       => $slot,
		'offset'     => is_numeric( $slot ) ? (int) $slot : null,
		'due_ts'     => $due_ts,
		'due_local'  => $due_ts ? wp_date( 'Y-m-d H:i T', $due_ts ) : null,
		/* Reporting only. It stopped deciding anything when send times arrived. */
		'days_since' => $cfg['days_since'],
		'dry_run'    => (bool) $dry_run,
		'orders'     => count( $order_ids ),
		'would_send' => $would,
		'skipped'    => $skipped,
		'sent'       => $sent,
	);
}

/**
 * The heartbeat pass: send whatever is due right now.
 *
 * DO NOT PUT ans_tb_thanks_config() BACK INSIDE THIS LOOP. That is what this
 * pass used to do, and it is why ans_tb_thanks_schedule() exists. config()
 * calls ans_tb_thanks_next_event(), which runs its own 200-row get_posts plus a
 * wp_get_object_terms() per candidate - roughly O(N^2) queries per pass. That
 * was merely wasteful once a day. At 96 passes a day it is a self-inflicted
 * outage. The full config is reached exclusively through
 * ans_tb_thanks_run_event(), for an event that is genuinely due, a handful of
 * times a season.
 *
 * Deliberately NOT cached: no transient, no option remembering "nothing due". A
 * stale cache surviving a deploy or a Custom-Fields meta edit is a way to MISS
 * a send, and not missing one is this job's entire value.
 *
 * ROLLOUT. One thing stands between an upload and a send, and it is the one
 * that matters: the heartbeat is not scheduled at all until somebody POSTs
 * /thanks/cron (see ans_tb_thanks_schedule_cron()), so the pre-flight below is
 * enforced rather than merely recommended. The 2 -> 1 default change applies
 * flatly, to every event that carries no offsets of its own; nothing is
 * currently armed anywhere (verified live on both environments, 2026-09-13 -
 * see ans_tb_thanks_default_offsets()), so there is no already-armed concert to
 * swap a slot out from under.
 *
 * The first tick after arming still recomputes every armed event's due moments
 * under the new rule. Any concert whose new offset-1 10:00 moment lands inside
 * the last 6 hours becomes due_now immediately. The window is exactly what
 * bounds that: under a 36-hour window a concert from two days ago would fire,
 * under 6 hours only one from yesterday and only between 10:00 and 16:00
 * today. Read GET /thanks/schedule and a dry POST /thanks/tick BEFORE arming,
 * and if something unexpected appears set ans_thanks_enabled to 0 on that
 * event - never widen or narrow the window filter to deal with it.
 *
 * @param bool $dry_run
 * @return array
 */
function ans_tb_thanks_tick( $dry_run = false ) {
	/*
	 * FIRST STATEMENT, and it stays first. Not redundant with the scheduler
	 * guard: that one only helps once init has run on a clone, and between a
	 * database restore and the first request the clone's cron table still holds
	 * a live send hook. Staging carries real patron addresses and Live mail
	 * credentials; it was once left one cron tick from mailing eleven of them.
	 */
	if ( ! $dry_run && function_exists( 'ans_tb_reminder_is_live' ) && ! ans_tb_reminder_is_live() ) {
		return array( 'refused' => true, 'reason' => 'not_production' );
	}

	$ids = get_posts( array(
		'post_type'        => 'tc_events',
		'post_status'      => 'publish',
		'posts_per_page'   => 200,
		'fields'           => 'ids',
		'suppress_filters' => true,
		/*
		 * NOT IN ('','0') rather than = '1' or EXISTS, because it reproduces the
		 * PHP truthiness this file has always used exactly - '' is off,
		 * otherwise (bool) intval() - so a stored '2' stays visible. NOT IN
		 * compiles to an INNER JOIN, so events with no key at all drop out,
		 * which matches thanks defaulting OFF. The PHP check below remains the
		 * authority for anything the query lets through.
		 *
		 * Do NOT copy this narrowing to the reminder without re-checking its
		 * default: it is only safe here because thanks defaults off.
		 */
		'meta_query'             => array(
			array(
				'key'     => 'ans_thanks_enabled',
				'value'   => array( '', '0' ),
				'compare' => 'NOT IN',
			),
		),
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
	) );

	/*
	 * update_meta_cache(), not update_post_meta_cache as a query arg: with
	 * fields => 'ids' WP_Query skips its meta priming loop entirely, so that arg
	 * does nothing here. And not _prime_post_caches() - this pass never needs
	 * the post rows.
	 *
	 * It pulls EVERY meta value for these posts into memory, the long copy
	 * fields included. That is the reason posts_per_page stays at 200.
	 */
	if ( $ids ) {
		update_meta_cache( 'post', $ids );
	}

	$clamped = false;
	$window  = ans_tb_thanks_window( $clamped );
	$now     = time();
	$tz      = wp_timezone();
	$cap     = (int) apply_filters( 'ans_tb_thanks_max_events_per_tick', 0 );

	$ran     = array();
	$skipped = array();

	foreach ( $ids as $id ) {
		$id = (int) $id;
		if ( $cap > 0 && count( $ran ) >= $cap ) {
			break;
		}

		$enabled_raw = get_post_meta( $id, 'ans_thanks_enabled', true );
		if ( '' === trim( (string) $enabled_raw ) || ! intval( $enabled_raw ) ) {
			continue;   // Never armed. Not news, so not reported.
		}
		if ( ans_tb_thanks_is_long_past( $id, $window ) ) {
			continue;   // Past seasons leave for one integer comparison.
		}

		$sch = ans_tb_thanks_schedule( $id );
		if ( '' !== $sch['schedule_error'] ) {
			if ( count( $skipped ) < 50 ) {
				$skipped[] = array( 'event_id' => $id, 'reason' => $sch['schedule_error'] );
			}
			continue;
		}
		if ( $sch['floored'] && count( $skipped ) < 50 ) {
			$skipped[] = array(
				'event_id' => $id,
				'reason'   => 'before_event',
				'slots'    => array_map( 'strval', array_keys( $sch['floored'] ) ),
			);
		}

		foreach ( $sch['slots'] as $token => $moment ) {
			/*
			 * The entire due-ness rule, in one place. Due from the moment until
			 * $window seconds after it, then expired permanently. Never early:
			 * the only lower bound is $now >= $moment, so the tick at 09:53
			 * finds nothing and the one at ~10:07 sends. Worst-case lateness is
			 * one heartbeat plus Kinsta's drift - inside "around 10am".
			 *
			 * There is no catch-up path outside the window and no "send it late
			 * because we missed it". The deliberate path for that is
			 * POST /thanks/run, which a human has to choose.
			 *
			 * THE WINDOW IS ALSO CLAMPED TO LOCAL MIDNIGHT, HERE AND NOWHERE
			 * ELSE. Six hours is time-of-day agnostic, and the docblock on
			 * ans_tb_thanks_window() reasons about a 10:00 slot expiring at
			 * 16:00 the same day - true of the default, and false of anything
			 * later. A 21:00 send_time, or a 23:30 send_at, whose tick is
			 * missed because cron on this site only fires on traffic, comes
			 * back due at 02:00 and mails a concert's entire audience a
			 * marketing email in the middle of the night. The photo strip is
			 * the same either way; the 2am timestamp on it is not.
			 *
			 * Deliberately NOT applied to ans_tb_thanks_window() itself: POST
			 * /thanks/run is window-free by design and must stay that way, and
			 * ans_tb_thanks_slot_state() keeps reporting against the unclamped
			 * window. The visible consequence is a board that can read due_now
			 * after midnight for a slot this pass will no longer touch - which
			 * is the correct thing for a person to see, because running it is
			 * now their decision, through the route that exists for it.
			 */
			$midnight   = ( new DateTimeImmutable( '@' . (int) $moment ) )->setTimezone( $tz )
				->modify( '+1 day' )->setTime( 0, 0, 0 )->getTimestamp();
			$eff_window = min( $window, max( 0, $midnight - (int) $moment ) );
			$due        = ( $now >= $moment ) && ( ( $now - $moment ) <= $eff_window );
			if ( ! $due ) {
				continue;
			}
			$ran[] = ans_tb_thanks_run_event( $id, (string) $token, $dry_run, false );
		}
	}

	$next_heartbeat = wp_next_scheduled( 'ans_tb_thanks_heartbeat' );

	return array(
		'checked'            => count( $ids ),
		'ran'                => $ran,
		'skipped_events'     => $skipped,
		'window_seconds'     => $window,
		'window_clamped'     => (bool) $clamped,
		/* The effective window is additionally cut short at local midnight, per
		   slot. window_seconds above is the unclamped ceiling. */
		'window_to_midnight' => true,
		/* False means the heartbeat is NOT scheduled and nothing sends on its
		   own, however healthy every row below looks. */
		'cron_armed'         => ans_tb_thanks_cron_armed(),
		'cron_next'          => $next_heartbeat ? wp_date( 'Y-m-d H:i', $next_heartbeat ) : null,
		/* Reported so that, after a deploy, you can confirm the 09:05 daily row
		   is gone and exactly one sender is armed. */
		'cron_next_legacy'   => wp_next_scheduled( 'ans_tb_thanks_daily' ),
		'schedule'           => 'ans_tb_15min',
	);
}

/* -------------------------------------------------------------------------
 * REST
 * ---------------------------------------------------------------------- */

/**
 * Read a REST parameter as a boolean. Every switch in this file goes through
 * here, and none of them uses a bare (bool) cast.
 *
 * (bool) "false" is TRUE, and every one of these parameters arrives as a string
 * over a form post or a shell one-liner. That single cast meant dry_run="false"
 * ran a REAL send to a concert's whole ticket list, and force="no" switched OFF
 * the per-order idempotency guard - the last thing standing between a retry and
 * a second copy of the same email.
 *
 * rest_sanitize_boolean() is the core answer and is used below, but it only
 * knows "false" and "0"; "no" and "off" still come back true, and "no" is what
 * a person types. Those are handled first, here, rather than being left as a
 * smaller version of the same bug.
 *
 * TRIMMED ONCE, UP FRONT, BEFORE ANY TEST. The trim used to live inside the
 * no/off/n comparison alone, so it protected the words a person types and not
 * the ones a script sends: rest_sanitize_boolean() compares against the literal
 * 'false' with no trimming of its own, so dry_run=" false " - one stray space
 * out of a shell one-liner or a copied cell - fell through to (bool) " false "
 * and ran a REAL send to a concert's whole ticket list. The same space on
 * force=" no " switched the idempotency guard off.
 *
 * AN EXPLICITLY EMPTY VALUE TAKES THE DEFAULT, not (bool) ''. dry_run= with
 * nothing after it is not a considered "no": it is a parameter somebody built
 * from a variable that turned out empty. rest_sanitize_boolean('') is false,
 * and false is the dangerous answer for the one parameter whose whole job is to
 * default true. Empty is treated as absent, so every switch in this file falls
 * to its stated safe side - dry_run true, force false.
 *
 * @param mixed $raw     The raw parameter, or null when absent.
 * @param bool  $default What an ABSENT parameter means. dry_run passes true.
 * @return bool
 */
function ans_tb_thanks_param_bool( $raw, $default = false ) {
	if ( null === $raw ) {
		return (bool) $default;
	}
	if ( is_string( $raw ) ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return (bool) $default;
		}
		if ( in_array( strtolower( $raw ), array( 'no', 'off', 'n' ), true ) ) {
			return false;
		}
	}
	return rest_sanitize_boolean( $raw );
}

add_action( 'rest_api_init', function () {

	register_rest_route( ANS_TB_NS, '/event/(?P<id>\d+)/thanks-config', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'ans_tb_perm',
			'callback'            => function ( $req ) {
				$id           = (int) $req['id'];
				$cfg          = ans_tb_thanks_config( $id );
				$cfg['title'] = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );

				/*
				 * The order ids are resolved ONCE and the sent counts computed
				 * from them, rather than only counted. This route advertises
				 * `sent` in its own note and could never emit it: slot_rows()
				 * without counts is handed $has_sent = false for every slot, so
				 * a thank-you that went out to all 140 ticket holders this
				 * morning came back `due_now`, and the obvious reading of that
				 * is "it has not gone - send it", by hand, a second time.
				 * Counting is one meta read per order per slot on ONE event,
				 * which is what /thanks/schedule already pays across all of
				 * them, under the same 500-order cap.
				 */
				$order_ids     = function_exists( 'ans_tb_reminder_event_order_ids' )
					? ans_tb_reminder_event_order_ids( $id ) : array();
				$cfg['orders'] = count( $order_ids );

				$sch                   = $cfg['schedule'];
				$clamped               = false;
				$counts                = ans_tb_thanks_sent_counts( $id, $order_ids, $sch );
				$cfg['send_time']      = $sch['send_time'];
				$cfg['send_at']        = $sch['send_at'];
				$cfg['slots']          = ans_tb_thanks_slot_rows( $sch, $counts );
				$cfg['counts_omitted'] = ( null === $counts );
				$cfg['window_seconds'] = ans_tb_thanks_window( $clamped );
				$cfg['window_clamped'] = (bool) $clamped;
				$cfg['timezone']       = wp_timezone()->getName();
				$cfg['utc_offset']     = wp_date( 'P' );
				$cfg['is_production']  = function_exists( 'ans_tb_reminder_is_live' ) ? (bool) ans_tb_reminder_is_live() : null;
				$cfg['cron_armed']     = ans_tb_thanks_cron_armed();

				$cfg['note'] = 'offsets are days AFTER the concert, sent at send_time (default 10:00 site time). '
					. 'send_at is an explicit "Y-m-d H:i" that overrides offsets and send_time entirely and sends once, as slot "at". '
					. 'POST "" to clear either; omit the param to leave it alone. '
					. 'slots[].state: pending | due_now | missed | sent | before_event | superseded | disabled | bad_time | bad_send_at | no_event_date. '
					. 'sent needs the per-slot counts; counts_omitted true means this event has more than 500 orders and they were not computed, '
					. 'so a slot that HAS sent will read due_now or missed here. '
					. 'cron_armed false means the heartbeat is not scheduled at all and no slot below will fire on its own. '
					. 'days_since is reporting only - it no longer decides a send. '
					. 'highlights and images are per-event only; there is no global layer for either.';
				return $cfg;
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => 'ans_tb_perm',
			'callback'            => function ( $req ) {
				$id = (int) $req['id'];
				if ( 'tc_events' !== get_post_type( $id ) ) {
					return new WP_Error( 'not_an_event', 'That id is not a Tickera event.', array( 'status' => 404 ) );
				}
				$keys = ans_tb_thanks_meta_keys();

				/*
				 * VALIDATE EVERY PARAMETER FIRST, WRITE NOTHING UNTIL ALL OF
				 * THEM PASS. The two passes below are the fix, and the order is
				 * the whole of it.
				 *
				 * This route used to write enabled, offsets and images as it
				 * walked the parameters and only then parse send_time and
				 * send_at. One POST carrying enabled=1 alongside send_time="10
				 * am" therefore ARMED the concert and then returned a 400 whose
				 * own message ends "Nothing was written." The operator reads
				 * that, believes the event is untouched, fixes nothing, and
				 * walks away from an armed concert that mails its entire ticket
				 * list the next time that slot comes round - the exact outcome
				 * the strict-invalid rule exists to prevent, caused by the
				 * error path that enforces it.
				 *
				 * A 400 from here now means the event is byte-for-byte as it
				 * was. Nothing below this line touches the database; everything
				 * lands in $plan, and the commit loop at the bottom is the only
				 * writer.
				 */
				$plan = array();

				if ( null !== $req->get_param( 'enabled' ) ) {
					/*
					 * Parsed, not cast. enabled=false and enabled=no both
					 * arrive as non-empty strings and both used to evaluate
					 * TRUE. This is the per-event arming switch, so that
					 * misreading has exactly one direction - it arms a concert
					 * somebody was trying to disarm.
					 */
					$plan['enabled'] = array(
						'key'   => $keys['enabled'],
						'value' => ans_tb_thanks_param_bool( $req->get_param( 'enabled' ) ) ? '1' : '0',
					);
				}
				if ( null !== $req->get_param( 'offsets' ) ) {
					$plan['offsets'] = array(
						'key'   => $keys['offsets'],
						'value' => implode( ',', ans_tb_thanks_parse_offsets( $req->get_param( 'offsets' ) ) ),
					);
				}
				if ( null !== $req->get_param( 'images' ) ) {
					$raw = $req->get_param( 'images' );
					$raw = is_array( $raw ) ? implode( ',', $raw ) : (string) $raw;
					$img = array_filter( array_map( 'intval', preg_split( '/[\s,]+/', trim( $raw ) ) ) );
					$plan['images'] = $img
						? array( 'key' => $keys['images'], 'value' => implode( ',', $img ) )
						: array( 'key' => $keys['images'], 'delete' => true );
				}
				/*
				 * One stated clearing convention for both new fields: "" CLEARS
				 * the meta, an omitted param leaves it alone.
				 *
				 * An unparseable value is a hard 400 that writes nothing, both
				 * times. Quietly defaulting is how somebody who typed "10 am"
				 * never learns their concert mailed at an hour they did not
				 * choose - and by the time they find out, it has been sent to
				 * every ticket holder.
				 */
				if ( null !== $req->get_param( 'send_time' ) ) {
					$v = trim( (string) $req->get_param( 'send_time' ) );
					if ( '' === $v ) {
						$plan['send_time'] = array( 'key' => $keys['send_time'], 'delete' => true );
					} else {
						$norm = ans_tb_thanks_parse_time( $v );
						if ( null === $norm ) {
							return new WP_Error(
								'bad_send_time',
								'send_time must be a time of day: "10:00", "9:05", "10:00:00", "10am", "7:30 pm". Nothing was written.',
								array( 'status' => 400 )
							);
						}
						$plan['send_time'] = array( 'key' => $keys['send_time'], 'value' => $norm );
					}
				}

				if ( null !== $req->get_param( 'send_at' ) ) {
					$v = trim( (string) $req->get_param( 'send_at' ) );
					if ( '' === $v ) {
						$plan['send_at'] = array( 'key' => $keys['send_at'], 'delete' => true );
					} else {
						$norm = ans_tb_thanks_parse_send_at( $v );
						if ( null === $norm ) {
							return new WP_Error(
								'bad_send_at',
								'send_at must be an absolute moment in site time: "YYYY-MM-DD HH:MM" (or with seconds, or a T separator). '
									. 'Relative phrasings such as "tomorrow 10am" or "now" are refused on purpose - a stored "now" would be '
									. 'due at every heartbeat. Nothing was written.',
								array( 'status' => 400 )
							);
						}
						$plan['send_at'] = array( 'key' => $keys['send_at'], 'value' => $norm );
					}
				}

				if ( null !== $req->get_param( 'next_event' ) ) {
					$n = (int) $req->get_param( 'next_event' );
					$plan['next_event'] = ( $n > 0 )
						? array( 'key' => $keys['next_event'], 'value' => $n )
						: array( 'key' => $keys['next_event'], 'delete' => true );
				}
				foreach ( array( 'subject', 'heading', 'intro', 'highlights', 'closing' ) as $f ) {
					$v = $req->get_param( $f );
					if ( null === $v ) {
						continue;
					}
					$v = trim( (string) $v );
					$plan[ $f ] = ( '' === $v )
						? array( 'key' => $keys[ $f ], 'delete' => true )
						: array( 'key' => $keys[ $f ], 'value' => sanitize_textarea_field( $v ) );
				}

				/*
				 * THE ONLY WRITER. Everything above validated; nothing above
				 * touched the database. $plan preserves insertion order, so
				 * "written" still lists the fields in the order this callback
				 * has always listed them.
				 */
				$written = array();
				foreach ( $plan as $field => $write ) {
					if ( ! empty( $write['delete'] ) ) {
						delete_post_meta( $id, $write['key'] );
					} else {
						update_post_meta( $id, $write['key'], $write['value'] );
					}
					$written[] = $field;
				}

				/*
				 * Say what was just ARMED, not merely what was written. A
				 * send_at typed into the near past fires within ~15 minutes, to
				 * the whole list, and that is the intended semantic - it is one
				 * keystroke away from a surprise. An operator must see the
				 * resolved moment, whether it is already inside the window, and
				 * how many people that is, before they walk away from the
				 * keyboard. (A far-past typo - the wrong year - falls outside
				 * the window and does nothing, which is the safe direction.)
				 */
				$cfg       = ans_tb_thanks_config( $id );
				$sch       = $cfg['schedule'];
				$clamped   = false;
				$window    = ans_tb_thanks_window( $clamped );
				$order_ids = function_exists( 'ans_tb_reminder_event_order_ids' )
					? ans_tb_reminder_event_order_ids( $id ) : array();
				/* With the counts, a slot that has already sent says so instead
				   of raising a due_now that reads as "it is about to go again". */
				$counts = ans_tb_thanks_sent_counts( $id, $order_ids, $sch );
				$rows   = ans_tb_thanks_slot_rows( $sch, $counts );

				$due_now  = false;
				$warnings = array();
				foreach ( $rows as $row ) {
					if ( 'due_now' === $row['state'] ) {
						$due_now = true;
					}
				}
				/* Stored, not refused - but it will never fire. See the floor in
				   ans_tb_thanks_schedule(). */
				if ( isset( $sch['floored']['at'] ) ) {
					$warnings[] = 'send_at_before_event';
				}

				return array(
					'written'        => $written,
					'warnings'       => $warnings,
					'slots'          => $rows,
					'due_now'        => $due_now,
					'window_seconds' => $window,
					'window_clamped' => (bool) $clamped,
					'counts_omitted' => ( null === $counts ),
					'recipients'     => count( $order_ids ),
					'cron_armed'     => ans_tb_thanks_cron_armed(),
					'timezone'       => wp_timezone()->getName(),
					'note'           => 'due_now true means the heartbeat will mail "recipients" addresses within roughly 15 minutes '
						. '- but only when cron_armed is true. While it is false the heartbeat is not scheduled and nothing sends on '
						. 'its own; arm it with POST thanks/cron {"armed":true}.',
					'config'         => $cfg,
				);
			},
		),
	) );

	register_rest_route( ANS_TB_NS, '/event/(?P<id>\d+)/thanks/run', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_tb_perm',
		'callback'            => function ( $req ) {
			/* Parsed, never cast - see ans_tb_thanks_param_bool(). dry_run keeps
			   defaulting TRUE when the param is absent: an accidental call must
			   still do nothing. */
			$dry = ans_tb_thanks_param_bool( $req->get_param( 'dry_run' ), true );

			/* "slot" is the name now; "offset" still answers for anything
			   calling this the way 1.27.2 documented. */
			$slot = $req->get_param( 'slot' );
			if ( null === $slot ) {
				$off  = $req->get_param( 'offset' );
				$slot = ( null === $off ) ? null : (string) $off;
			} else {
				$slot = (string) $slot;
			}

			return ans_tb_thanks_run_event(
				(int) $req['id'],
				$slot,
				$dry,
				/* force absent means NOT forced: it bypasses both the due-ness
				   refusals above and per-order idempotency. */
				ans_tb_thanks_param_bool( $req->get_param( 'force' ), false )
			);
		},
	) );

	register_rest_route( ANS_TB_NS, '/event/(?P<id>\d+)/thanks/preview', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_tb_perm',
		'callback'            => function ( $req ) {
			$id = (int) $req['id'];
			$to = sanitize_email( (string) $req->get_param( 'to' ) );
			if ( ! $to || ! is_email( $to ) ) {
				return new WP_Error( 'missing_to', 'A valid "to" is required. This route never reaches a customer.', array( 'status' => 400 ) );
			}
			$orders = function_exists( 'ans_tb_reminder_event_order_ids' ) ? ans_tb_reminder_event_order_ids( $id ) : array();
			if ( ! $orders ) {
				return new WP_Error( 'no_orders', 'That event has no live ticket orders to render against.', array( 'status' => 400 ) );
			}
			/* A preview writes no idempotency key, so the slot is cosmetic here -
			   but it is resolved the same way run_event() resolves it, so there
			   is exactly one definition of "which slot are we talking about". */
			$slot = ans_tb_thanks_resolve_slot( ans_tb_thanks_schedule( $id ) );
			return ans_tb_thanks_send( (int) $orders[0], $id, ( null === $slot ) ? '0' : $slot, $to );
		},
	) );

	register_rest_route( ANS_TB_NS, '/thanks/tick', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_tb_perm',
		'callback'            => function ( $req ) {
			/* Same trap as /thanks/run and worse here, because this route is not
			   scoped to one event: a cast "false" is a real send across EVERY
			   armed concert at once. See ans_tb_thanks_param_bool(). */
			return ans_tb_thanks_tick( ans_tb_thanks_param_bool( $req->get_param( 'dry_run' ), true ) );
		},
	) );

	/**
	 * GET ars-nova/v1/thanks/schedule - the operator board.
	 *
	 * Read-only, and nothing in this path is reachable from cron. It mirrors
	 * ans_tb_reminder_schedule_overview() so the two boards read the same way.
	 */
	register_rest_route( ANS_TB_NS, '/thanks/schedule', array(
		'methods'             => 'GET',
		'permission_callback' => 'ans_tb_perm',
		'callback'            => function ( $req ) {
			$ids = get_posts( array(
				'post_type'              => 'tc_events',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'suppress_filters'       => true,
				'meta_query'             => array(
					array(
						'key'     => 'ans_thanks_enabled',
						'value'   => array( '', '0' ),
						'compare' => 'NOT IN',
					),
				),
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			) );
			if ( $ids ) {
				update_meta_cache( 'post', $ids );
			}

			$clamped = false;
			$window  = ans_tb_thanks_window( $clamped );
			$events  = array();

			/* The reporting horizon. See the note inside the loop; filterable
			   because "how far back do we still count" is a judgement about
			   this season's shape, not a correctness rule. */
			$board_now      = time();
			$horizon_past   = (int) apply_filters( 'ans_tb_thanks_board_horizon_past', 30 * DAY_IN_SECONDS );
			$horizon_future = (int) apply_filters( 'ans_tb_thanks_board_horizon_future', 14 * DAY_IN_SECONDS );

			foreach ( $ids as $eid ) {
				$eid = (int) $eid;
				$sch = ans_tb_thanks_schedule( $eid );
				if ( ! $sch['enabled'] ) {
					continue;
				}

				/*
				 * BOUND THE WORK BEFORE DOING IT. ans_tb_reminder_event_order_ids()
				 * runs a wc_get_order() per ticket order - full order
				 * hydration, not a count - and this loop called it for EVERY
				 * armed event on the site before the 500-order cap below got a
				 * look in. The cap therefore capped the cheap half and the
				 * expensive half was already paid: a season with a dozen armed
				 * concerts hydrated thousands of orders to render a board, on
				 * the one route somebody opens when they are already worried
				 * about a send.
				 *
				 * The horizon is what makes that affordable, and it is generous
				 * on purpose. Anything with a slot inside the last 30 days or
				 * the next 14 gets the full treatment, which covers every
				 * "did this morning's thank-you go?" reading. Last season's
				 * still-armed events and concerts months out are listed with
				 * their schedule intact and orders_omitted true - a visible
				 * "not counted", never a zero, because a zero here reads as
				 * "nobody has a ticket".
				 *
				 * An event with no computable moment at all (a schedule_error)
				 * has nothing to count against, so it skips the orders too.
				 */
				$moments = array_merge(
					array_values( (array) $sch['slots'] ),
					array_values( (array) $sch['floored'] )
				);
				$relevant = false;
				foreach ( $moments as $m ) {
					$m = (int) $m;
					if ( $m >= ( $board_now - $horizon_past ) && $m <= ( $board_now + $horizon_future ) ) {
						$relevant = true;
						break;
					}
				}

				$orders         = array();
				$counts         = null;
				$counts_omitted = false;
				if ( $relevant ) {
					$orders = function_exists( 'ans_tb_reminder_event_order_ids' )
						? ans_tb_reminder_event_order_ids( $eid ) : array();
					/* Counted over every token slot_rows() will render, not just
					   the live ones: a slot that genuinely sent under the other
					   regime used to come back `superseded` with sent:null. */
					$counts         = ans_tb_thanks_sent_counts( $eid, $orders, $sch );
					$counts_omitted = ( null === $counts );
				}

				$events[] = array(
					'event_id'       => $eid,
					'title'          => html_entity_decode( get_the_title( $eid ), ENT_QUOTES, 'UTF-8' ),
					'event_ts'       => $sch['event_ts'],
					'event_local'    => $sch['event_ts'] ? wp_date( 'Y-m-d H:i T', $sch['event_ts'] ) : null,
					'mode'           => $sch['mode'],
					'offsets'        => $sch['offsets'],
					'send_time'      => $sch['send_time'],
					'time_source'    => $sch['time_source'],
					'send_at'        => $sch['send_at'],
					'schedule_error' => $sch['schedule_error'],
					'orders'         => $relevant ? count( $orders ) : null,
					'orders_omitted' => ! $relevant,
					'counts_omitted' => $counts_omitted,
					'slots'          => ans_tb_thanks_slot_rows( $sch, $counts ),
				);
			}

			$next_heartbeat = wp_next_scheduled( 'ans_tb_thanks_heartbeat' );

			$res = rest_ensure_response( array(
				/*
				 * generated_at and a fresh generation_id on every call, because
				 * Kinsta's edge has already been caught serving a stale
				 * diagnostic route on this site. A cached board showing
				 * yesterday's state is worse than no board - it is what somebody
				 * uses to decide whether to fire a manual send. The header below
				 * is a request, not a guarantee; these two fields are what let a
				 * human notice it was ignored.
				 */
				'generated_at'       => wp_date( 'c' ),
				'generation_id'      => wp_generate_password( 8, false ),
				'timezone'           => wp_timezone()->getName(),
				'utc_offset'         => wp_date( 'P' ),
				'window_seconds'     => $window,
				'window_clamped'     => (bool) $clamped,
				/* Within the tick the window is additionally cut short at local
				   midnight, so no missed slot can fire in the small hours. */
				'window_to_midnight' => true,
				'is_production'      => function_exists( 'ans_tb_reminder_is_live' ) ? (bool) ans_tb_reminder_is_live() : null,
				/*
				 * THE FIRST THING TO READ WHEN NOTHING HAS SENT. False means the
				 * heartbeat is not scheduled at all: every row below can say
				 * due_now and not one of them will fire. Uploading the plugin
				 * files does not arm it; a person has to, through
				 * POST thanks/cron.
				 */
				'cron_armed'         => ans_tb_thanks_cron_armed(),
				'cron_next'          => $next_heartbeat ? wp_date( 'Y-m-d H:i T', $next_heartbeat ) : null,
				'cron_next_legacy'   => wp_next_scheduled( 'ans_tb_thanks_daily' ),
				'horizon_past'       => $horizon_past,
				'horizon_future'     => $horizon_future,
				'events'             => $events,
				'note'               => 'Every event with thanks armed. states: pending | due_now | missed | sent | before_event | superseded | '
					. 'disabled | bad_time | bad_send_at | no_event_date. A "missed" row is deliberate: the heartbeat never sends late on '
					. 'its own, so a missed day is a decision for a person, via POST /thanks/run. '
					. 'cron_armed false means nothing fires on its own at all - arm it with POST thanks/cron {"armed":true}. '
					. 'orders_omitted true means every slot of that event falls outside horizon_past/horizon_future, so its orders were '
					. 'not resolved and orders and sent are null rather than 0; counts_omitted true means it has more than 500 orders.',
			) );
			$res->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			return $res;
		},
	) );

	/**
	 * GET/POST ars-nova/v1/thanks/cron - the one-time arming switch.
	 *
	 * The ONLY thing that can start the heartbeat. See
	 * ans_tb_thanks_schedule_cron() for why it exists.
	 */
	register_rest_route( ANS_TB_NS, '/thanks/cron', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'ans_tb_perm',
			'callback'            => 'ans_tb_thanks_cron_state',
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => 'ans_tb_perm',
			'callback'            => function ( $req ) {
				$armed = $req->get_param( 'armed' );
				if ( null === $armed ) {
					return new WP_Error(
						'missing_armed',
						'Pass armed=true to start the 15-minute heartbeat, or armed=false to stop it. '
							. 'There is no default: arming this mails concert audiences, and a missing parameter must never do that.',
						array( 'status' => 400 )
					);
				}
				/* Parsed for the same reason as everywhere else in this file,
				   and most of all here: this is the switch that starts an
				   automatic mass send, so armed="no" must not arm it. */
				$armed = ans_tb_thanks_param_bool( $armed );

				update_option( 'ans_tb_thanks_cron_armed', $armed ? '1' : '0', false );
				/* Act on it now rather than waiting for the next init, so the
				   response below reports what is actually scheduled. */
				ans_tb_thanks_schedule_cron();

				return ans_tb_thanks_cron_state();
			},
		),
	) );
} );

/**
 * What the arming switch is currently doing. Shared by GET and POST on
 * /thanks/cron so the two can never describe the state differently.
 *
 * @return array
 */
function ans_tb_thanks_cron_state() {
	$next = wp_next_scheduled( 'ans_tb_thanks_heartbeat' );
	return array(
		'cron_armed'       => ans_tb_thanks_cron_armed(),
		'cron_next'        => $next ? wp_date( 'Y-m-d H:i T', $next ) : null,
		'cron_next_legacy' => wp_next_scheduled( 'ans_tb_thanks_daily' ),
		'is_production'    => function_exists( 'ans_tb_reminder_is_live' ) ? (bool) ans_tb_reminder_is_live() : null,
		'note'             => 'While cron_armed is false the heartbeat is not scheduled and no thank-you sends on its own, '
			. 'however armed the individual events look. Arming does nothing on a clone: a non-production site clears the '
			. 'schedule regardless. Read GET thanks/schedule and a dry POST thanks/tick before arming.',
	);
}

/**
 * The 15-minute interval, registered UNCONDITIONALLY.
 *
 * Not gated on ans_tb_reminder_is_live(). If the schedule NAME is missing when
 * WP-Cron goes to reschedule a recurring event, that event silently stops after
 * its next run - no error, no notice, just a thank-you that never arrives
 * again. Only the EVENT is ever withheld from a clone; the interval definition
 * is harmless everywhere.
 *
 * 15 minutes rather than 5: Kinsta's system cron hits wp-cron.php roughly four
 * times an hour, so the delivered resolution is ~15 minutes whatever is
 * registered here. A 5-minute schedule would just be perpetually overdue at
 * every real cron hit - more bookkeeping, identical behaviour. 900s matches
 * what actually arrives, bounds worst-case lateness at one interval, and makes
 * the 6-hour staleness window 24x the cadence.
 */
add_filter( 'cron_schedules', function ( $s ) {
	if ( ! isset( $s['ans_tb_15min'] ) ) {
		$s['ans_tb_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Every 15 minutes (Ars Nova)',
		);
	}
	return $s;
} );

/**
 * Is the heartbeat allowed to be scheduled at all?
 *
 * A stored option, set once by a person through POST /thanks/cron. Nothing
 * else writes it - not activation, not an upgrade routine, not init.
 *
 * @return bool
 */
function ans_tb_thanks_cron_armed() {
	return '1' === (string) get_option( 'ans_tb_thanks_cron_armed', '' );
}

/**
 * Keep the heartbeat scheduled. Named, mirroring ans_tb_reminder_schedule_cron(),
 * so the two files read as one author.
 *
 * UPLOADING FILES MUST NOT START SENDING. This runs on init, so before the gate
 * below existed the act of copying these files onto production armed a
 * 15-minute cron - and the first tick recomputes every armed event's due
 * moments under whatever rules the new files carry. A concert whose recomputed
 * slot lands inside the staleness window is mailed to its entire ticket list
 * within about fifteen minutes of the upload finishing, which can easily be
 * before anybody has read the board, and certainly before anybody has run the
 * dry tick this file's own rollout note tells them to run. The REST routes are
 * reachable before that first tick, so a pre-flight was always POSSIBLE; the
 * gate is what makes it REQUIRED.
 *
 * The gate is one option, and it is deliberately not a constant or a filter: a
 * person has to POST to a route on the live site, which is an act with a
 * timestamp and an author, not a line in a file that travels with a deploy.
 *
 * It clears as well as withholds. An unarmed site with a heartbeat row left
 * over - from a database clone, or from this plugin's own 1.27.2, which
 * self-armed the same way - loses it here, which is the safe direction: a
 * schedule nobody has asked for is exactly what this is defending against.
 * After a deploy, GET /thanks/schedule reports cron_armed so that "nothing is
 * scheduled" reads as a decision rather than a fault.
 *
 * The old docblock here reasoned about running five minutes after the reminder,
 * "because two overlapping runs on one shared-hosting PHP worker is how a slow
 * query becomes a timeout in the middle of a send." That reasoning died with
 * the daily schedule, and the honest replacement is this: WP-Cron runs every
 * due event sequentially inside ONE request, so the real hazard was ever two
 * HEAVY passes in one request. The heartbeat is two queries on 95 of every 96
 * ticks - which is exactly what the ans_tb_thanks_schedule() split and the
 * narrowed, meta-primed query in ans_tb_thanks_tick() were bought for.
 *
 * Anchored at time() + 60s, not a wall-clock time. A heartbeat has no
 * meaningful wall-clock phase, and anchoring one to the clock is what dragged
 * hand-rolled UTC-offset arithmetic into this file in the first place. The +60s
 * keeps it from firing inside the request that scheduled it, and the arbitrary
 * phase is a small bonus, since it will not systematically land in the same
 * wp-cron run as ans_tb_reminder_daily at 09:00.
 */
function ans_tb_thanks_schedule_cron() {
	/*
	 * The 09:05 daily this replaces, wherever it still exists - and BEFORE the
	 * is_live return, so a clone sheds the old row too.
	 *
	 * wp_clear_scheduled_hook() rather than wp_unschedule_event( $ts, $hook ):
	 * the latter clears ONE occurrence, this clears all of them, including
	 * duplicates a database clone dragged in. reminder-schedule.php:607 already
	 * uses the stronger form. Cheap on every init - it reads the cached cron
	 * option and returns without a DB write once the hook is absent.
	 */
	wp_clear_scheduled_hook( 'ans_tb_thanks_daily' );

	if ( function_exists( 'ans_tb_reminder_is_live' ) && ! ans_tb_reminder_is_live() ) {
		/* A clone must never carry a send schedule. Staging was once left one
		   cron tick from mailing eleven real patrons. */
		wp_clear_scheduled_hook( 'ans_tb_thanks_heartbeat' );
		return;
	}
	if ( ! ans_tb_thanks_cron_armed() ) {
		/* Not armed: withhold the schedule AND shed any row that is already
		   there, so an upload can never leave a live sender behind. */
		wp_clear_scheduled_hook( 'ans_tb_thanks_heartbeat' );
		return;
	}
	if ( wp_next_scheduled( 'ans_tb_thanks_heartbeat' ) ) {
		return;
	}
	wp_schedule_event( time() + MINUTE_IN_SECONDS, 'ans_tb_15min', 'ans_tb_thanks_heartbeat' );
}
add_action( 'init', 'ans_tb_thanks_schedule_cron' );

add_action( 'ans_tb_thanks_heartbeat', function () {
	ans_tb_thanks_tick( false );
} );

/*
 * The legacy hook kept as an alias for ONE release, in case a system cron
 * entry, a WP Crontrol row or a stray scheduled event still drives this by
 * name. The scheduled ROW is cleared above; only the handler remains, and it is
 * harmless either way - the tick is idempotent by design, which is the property
 * that makes running it more often safe at all.
 */
add_action( 'ans_tb_thanks_daily', function () {
	ans_tb_thanks_tick( false );
} );
