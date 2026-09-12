<?php
/**
 * Per-event reminder configuration and the scheduler that fires it.
 *
 * v1.24.x could send a reminder. It could not say WHEN, to WHICH event, or
 * whether it had already done so - and on 2026-09-11 that last gap nearly cost
 * 28 people a duplicate email: the batch call timed out at the connector while
 * PHP kept running, and nothing in the system could answer "did that go?".
 * The only witness was the SMTP log, and the first query against it returned
 * zero because the database clock is UTC and the rows were stamped local time.
 * A send that cannot be asked about is a send that gets repeated.
 *
 * So three things live here:
 *
 * 1. CONFIGURATION PER EVENT. Copy and schedule are read from the event post,
 *    falling back to the global email settings, falling back to code defaults.
 *    One template serves the season; any single performance can override any
 *    field. Kim asked for exactly this: "parking information ... sometimes
 *    would simply be 'Parking on premises' or, for St. Paul's, 'Free Parking
 *    available in the lot at ___'", and "when needed, information specific to
 *    the event or venue."
 *
 * 2. A RECORD OF WHAT WAS SENT. Every successful send writes order meta keyed
 *    by event and offset. That is what makes a retry safe and a timeout
 *    survivable.
 *
 * 3. THE SCHEDULE. A daily pass works out which events are due a reminder
 *    today and sends them. Offsets are days before the event, so [7, 1] means
 *    a week out and the day before.
 *
 * Ownership note: the event fields here use the `ans_` prefix, matching what
 * this plugin already owns (ans_note, ans_perk, ans_private_location). The
 * `ansp_` prefix belongs to ars-nova-singers-portal, which already writes
 * ansp_event_venue - and ans_private_location is ALREADY written by both
 * plugins, which is one source of truth too few. Do not make that worse from
 * here; when Singers Hub grows a UI for these, it should read and write these
 * same keys rather than mint parallel ones.
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.25.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Event meta keys, and the defaults used when an event says nothing. */
function ans_tb_reminder_meta_keys() {
	return array(
		'enabled'  => 'ans_reminder_enabled',
		'offsets'  => 'ans_reminder_offsets',
		'subject'  => 'ans_reminder_subject',
		'heading'  => 'ans_reminder_heading',
		'intro'    => 'ans_reminder_intro',
		'lead'     => 'ans_reminder_lead',
		'closing'  => 'ans_reminder_closing',
		'video'    => 'ans_event_video',
	);
}

/**
 * Parse "7, 1" or "7" or [7,1] into a clean, sorted, de-duplicated list of ints.
 *
 * Rejects negatives - an offset is days BEFORE the event, and a reminder for a
 * concert that has already happened is never what anyone meant.
 *
 * @param mixed $raw
 * @return int[]
 */
function ans_tb_reminder_parse_offsets( $raw ) {
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
	rsort( $out );
	return $out;
}

/**
 * The resolved reminder configuration for one event.
 *
 * Every copy field resolves event -> global email setting -> code default, so
 * an event that says nothing behaves exactly as it did before this file
 * existed. `sources` reports which layer answered, because "why does this
 * event say something different" is the question people actually ask.
 *
 * @param int $event_id
 * @return array
 */
function ans_tb_reminder_config( $event_id ) {
	$event_id = (int) $event_id;
	$keys     = ans_tb_reminder_meta_keys();
	$global   = get_option( 'woocommerce_ans_event_reminder_settings', array() );
	$global   = is_array( $global ) ? $global : array();

	$copy_map = array(
		'subject' => 'subject',
		'heading' => 'heading',
		'intro'   => 'intro',
		'lead'    => 'lead',
		'closing' => 'additional_content',
	);

	$resolved = array();
	$sources  = array();
	foreach ( $copy_map as $field => $global_key ) {
		$event_val = trim( (string) get_post_meta( $event_id, $keys[ $field ], true ) );
		if ( '' !== $event_val ) {
			$resolved[ $field ] = $event_val;
			$sources[ $field ]  = 'event';
			continue;
		}
		$g = isset( $global[ $global_key ] ) ? trim( (string) $global[ $global_key ] ) : '';
		$resolved[ $field ] = $g;
		$sources[ $field ]  = ( '' !== $g ) ? 'global' : 'default';
	}

	$offsets_raw = get_post_meta( $event_id, $keys['offsets'], true );
	$offsets     = ans_tb_reminder_parse_offsets( $offsets_raw );
	if ( ! $offsets ) {
		$offsets           = ans_tb_reminder_parse_offsets( apply_filters( 'ans_tb_reminder_default_offsets', array( 7, 1 ) ) );
		$sources['offsets'] = 'default';
	} else {
		$sources['offsets'] = 'event';
	}

	$enabled_raw = get_post_meta( $event_id, $keys['enabled'], true );
	$enabled     = ( '' === trim( (string) $enabled_raw ) ) ? false : (bool) intval( $enabled_raw );

	return array(
		'event_id' => $event_id,
		'enabled'  => $enabled,
		'offsets'  => $offsets,
		'video'    => trim( (string) get_post_meta( $event_id, $keys['video'], true ) ),
		'copy'     => $resolved,
		'sources'  => $sources,
	);
}

/* -------------------------------------------------------------------------
 * Environment guard
 * ---------------------------------------------------------------------- */

/**
 * Is this the production site?
 *
 * Staging on this project is a CLONE. It carries real orders with real patron
 * email addresses, and it inherited Live's mail credentials - so a scheduler
 * running there does not send test mail, it sends real mail to real people who
 * are not expecting it. Nothing about a hostname makes that obvious, which is
 * why PROJECT_RULES section 3a already says `siteurl` is the only acceptable
 * proof of which environment you are on. The same rule applies to code.
 *
 * Found the hard way on 2026-09-11: the scheduler was verified on staging by
 * enabling a real event, and that left a clone one cron tick away from mailing
 * eleven patrons.
 *
 * @return bool
 */
function ans_tb_reminder_is_live() {
	$live = apply_filters( 'ans_tb_reminder_live_url', 'https://arsnovasingers.org' );
	return untrailingslashit( (string) get_option( 'siteurl' ) ) === untrailingslashit( (string) $live );
}

/* -------------------------------------------------------------------------
 * The record of what was sent
 * ---------------------------------------------------------------------- */

/**
 * Order meta key for one (event, offset) reminder.
 *
 * Keyed by BOTH, deliberately. A patron holding tickets to three concerts
 * should get three reminders, and keying by event alone would silence two of
 * them. A season package makes that the normal case, not the edge case.
 */
function ans_tb_reminder_sent_key( $event_id, $offset ) {
	return '_ans_reminder_sent_' . (int) $event_id . '_' . (int) $offset;
}

/**
 * Has this order already had this event's reminder at this offset?
 *
 * @return string Empty if never sent, otherwise the ISO timestamp it was.
 */
function ans_tb_reminder_already_sent( $order_id, $event_id, $offset ) {
	$order = wc_get_order( (int) $order_id );
	if ( ! $order ) {
		return '';
	}
	return (string) $order->get_meta( ans_tb_reminder_sent_key( $event_id, $offset ), true );
}

/**
 * Record a send. Hooked to the action fired by ans_tb_send_reminder().
 *
 * Previews are NOT recorded: a preview goes to a named staff address and must
 * never make the system believe a customer has been told anything.
 *
 * @param int    $order_id
 * @param string $divert_to Non-empty when this was a preview.
 * @param array  $result
 * @return void
 */
function ans_tb_reminder_record_send( $order_id, $divert_to, $result ) {
	if ( $divert_to ) {
		return;
	}
	if ( empty( $result['sent'] ) ) {
		return;
	}
	$order = wc_get_order( (int) $order_id );
	if ( ! $order || ! function_exists( 'ans_tb_order_events' ) ) {
		return;
	}
	$offset = isset( $GLOBALS['ans_tb_reminder_offset'] ) ? (int) $GLOBALS['ans_tb_reminder_offset'] : -1;

	foreach ( ans_tb_order_events( (int) $order_id ) as $e ) {
		if ( empty( $e['id'] ) ) {
			continue;
		}
		$order->update_meta_data(
			ans_tb_reminder_sent_key( (int) $e['id'], $offset ),
			current_time( 'mysql' )
		);
	}
	$order->save();
}
add_action( 'ans_tb_reminder_sent', 'ans_tb_reminder_record_send', 10, 3 );

/* -------------------------------------------------------------------------
 * Which orders belong to an event
 * ---------------------------------------------------------------------- */

/**
 * Paid orders holding at least one LIVE ticket for this event.
 *
 * The linkage is the ticket instance's post_parent, which is the order id -
 * the same relationship ans_tb_order_ticket_ids() reads in the other
 * direction. Only `publish` instances count, because refund-voids-tickets.php
 * trashes instances on refund; an order refunded since purchase therefore
 * drops out of this list without anyone having to remember to remove it.
 *
 * @param int $event_id
 * @return int[] Order ids, ascending.
 */
function ans_tb_reminder_event_order_ids( $event_id ) {
	$event_id = (int) $event_id;
	if ( ! $event_id || ! post_type_exists( 'tc_tickets_instances' ) ) {
		return array();
	}

	$instances = get_posts( array(
		'post_type'      => 'tc_tickets_instances',
		'post_status'    => 'publish',
		'fields'         => 'id=>parent',
		'posts_per_page' => -1,
		'meta_query'     => array(
			array(
				'key'   => 'event_id',
				'value' => (string) $event_id,
			),
		),
	) );

	$order_ids = array();
	foreach ( (array) $instances as $parent ) {
		$parent = (int) $parent;
		if ( $parent ) {
			$order_ids[ $parent ] = true;
		}
	}
	$order_ids = array_map( 'intval', array_keys( $order_ids ) );

	$out = array();
	foreach ( $order_ids as $oid ) {
		$order = wc_get_order( $oid );
		if ( $order && function_exists( 'ans_tb_reminder_order_is_sendable' ) && ans_tb_reminder_order_is_sendable( $order ) ) {
			$out[] = $oid;
		}
	}
	sort( $out );
	return $out;
}

/* -------------------------------------------------------------------------
 * Sending with an event's own copy
 * ---------------------------------------------------------------------- */

/**
 * Send one reminder with this event's configuration applied.
 *
 * WC_Email reads its settings ONCE, in init_settings() at construction, so
 * filtering the option alone changes nothing - the object is already holding
 * the old array. The settings are therefore filtered AND init_settings() is
 * called again, then both are undone. That is why this wraps rather than
 * reimplements: a preview and a real send must travel the identical path, and
 * the moment they diverge a preview stops proving anything.
 *
 * A resolved-empty field is UNSET rather than written as ''. WC_Email's
 * get_option() returns a set-but-empty value in preference to the default, so
 * writing '' would silently blank a heading rather than inherit one.
 *
 * @param int    $order_id
 * @param int    $event_id
 * @param int    $offset    Days before the event; -1 for an ad-hoc send.
 * @param string $divert_to Preview address, or '' to mail the customer.
 * @return array|WP_Error
 */
function ans_tb_reminder_send_with_config( $order_id, $event_id, $offset = -1, $divert_to = '' ) {
	if ( ! function_exists( 'ans_tb_send_reminder' ) || ! function_exists( 'ans_tb_reminder_email' ) ) {
		return new WP_Error( 'no_reminder', 'The reminder email is not available.', array( 'status' => 500 ) );
	}
	$email = ans_tb_reminder_email();
	if ( ! $email ) {
		return new WP_Error( 'no_reminder', 'The reminder email is not registered.', array( 'status' => 500 ) );
	}

	$cfg    = ans_tb_reminder_config( $event_id );
	$global = get_option( 'woocommerce_ans_event_reminder_settings', array() );
	$merged = is_array( $global ) ? $global : array();

	$map = array(
		'subject' => 'subject',
		'heading' => 'heading',
		'intro'   => 'intro',
		'lead'    => 'lead',
		'closing' => 'additional_content',
	);
	foreach ( $map as $field => $key ) {
		$val = trim( (string) $cfg['copy'][ $field ] );
		if ( '' === $val ) {
			unset( $merged[ $key ] );
		} else {
			$merged[ $key ] = $val;
		}
	}

	/* Kim asked for the short video in reminders. It hangs off the event, so it
	   only appears where someone has put one. */
	if ( $cfg['video'] ) {
		$tail = "\n\n## A taste of the evening\n\n" . $cfg['video'];
		$merged['additional_content'] = isset( $merged['additional_content'] )
			? $merged['additional_content'] . $tail
			: ltrim( $tail );
	}

	$merged['enabled'] = 'yes';

	$cb = function () use ( $merged ) {
		return $merged;
	};
	add_filter( 'pre_option_woocommerce_ans_event_reminder_settings', $cb, 999 );
	$email->init_settings();

	$GLOBALS['ans_tb_reminder_offset'] = (int) $offset;
	$result = ans_tb_send_reminder( $order_id, $divert_to );
	unset( $GLOBALS['ans_tb_reminder_offset'] );

	remove_filter( 'pre_option_woocommerce_ans_event_reminder_settings', $cb, 999 );
	$email->init_settings();

	if ( is_array( $result ) ) {
		$result['event_id'] = (int) $event_id;
		$result['offset']   = (int) $offset;
	}
	return $result;
}

/* -------------------------------------------------------------------------
 * Running one event's reminder
 * ---------------------------------------------------------------------- */

/**
 * Send this event's reminder to everyone holding a live ticket for it.
 *
 * dry_run defaults to TRUE everywhere it is exposed. Orders already recorded
 * at this (event, offset) are skipped unless $force - which is the whole point
 * of the record, and the thing whose absence nearly double-mailed 28 people.
 *
 * @param int  $event_id
 * @param int  $offset
 * @param bool $dry_run
 * @param bool $force
 * @return array
 */
function ans_tb_reminder_run_event( $event_id, $offset = -1, $dry_run = true, $force = false ) {
	$event_id = (int) $event_id;
	$offset   = (int) $offset;

	if ( ! $dry_run && ! ans_tb_reminder_is_live() && ! apply_filters( 'ans_tb_reminder_allow_non_production', false ) ) {
		return array(
			'event_id' => $event_id,
			'offset'   => $offset,
			'refused'  => true,
			'reason'   => 'Not the production site (' . get_option( 'siteurl' ) . '). Real sends are refused on a clone, '
				. 'which holds real patron addresses and inherited live mail credentials.',
		);
	}

	$orders  = ans_tb_reminder_event_order_ids( $event_id );
	$results = array();
	$sent    = 0;
	$skipped = 0;

	foreach ( $orders as $oid ) {
		$already = ans_tb_reminder_already_sent( $oid, $event_id, $offset );
		if ( $already && ! $force ) {
			$results[] = array( 'order_id' => $oid, 'sent' => false, 'reason' => 'already sent ' . $already );
			$skipped++;
			continue;
		}
		if ( $dry_run ) {
			$order     = wc_get_order( $oid );
			$results[] = array(
				'order_id'    => $oid,
				'sent'        => false,
				'would_go_to' => $order ? $order->get_billing_email() : '',
				'reason'      => 'dry run',
			);
			$sent++;
			continue;
		}
		$r = ans_tb_reminder_send_with_config( $oid, $event_id, $offset, '' );
		if ( is_wp_error( $r ) ) {
			$results[] = array( 'order_id' => $oid, 'sent' => false, 'reason' => $r->get_error_message() );
			$skipped++;
			continue;
		}
		$results[] = $r;
		if ( ! empty( $r['sent'] ) ) {
			$sent++;
		} else {
			$skipped++;
		}
	}

	return array(
		'event_id'  => $event_id,
		'offset'    => $offset,
		'dry_run'   => (bool) $dry_run,
		'force'     => (bool) $force,
		'orders'    => count( $orders ),
		'sent'      => $sent,
		'skipped'   => $skipped,
		'results'   => $results,
	);
}

/* -------------------------------------------------------------------------
 * The schedule
 * ---------------------------------------------------------------------- */

/**
 * Whole days from today to the event, in SITE time.
 *
 * Calendar days, not elapsed seconds. An event at 5pm tomorrow is 1 day out
 * whether it is now 9am or 11pm, which is what "the day before" means to a
 * person. Dividing a difference in seconds gets that wrong twice a day, and
 * the whole reason ans_tb_local_ts() exists in this plugin is that a previous
 * version of this mistake printed every concert six hours early for weeks.
 *
 * @param int $event_id
 * @return int|null Null when the event has no usable date.
 */
function ans_tb_reminder_days_out( $event_id ) {
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
	return (int) $today->diff( $day )->format( '%r%a' );
}

/**
 * Every published event with reminders switched on, and what it is due.
 *
 * @return array
 */
function ans_tb_reminder_schedule_overview() {
	$events = get_posts( array(
		'post_type'      => 'tc_events',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );

	$out = array();
	foreach ( (array) $events as $eid ) {
		$cfg = ans_tb_reminder_config( (int) $eid );
		if ( ! $cfg['enabled'] ) {
			continue;
		}
		$days = ans_tb_reminder_days_out( (int) $eid );
		$out[] = array(
			'event_id'  => (int) $eid,
			'title'     => html_entity_decode( get_the_title( (int) $eid ), ENT_QUOTES, 'UTF-8' ),
			'days_out'  => $days,
			'offsets'   => $cfg['offsets'],
			'due_today' => ( null !== $days && in_array( $days, $cfg['offsets'], true ) ),
			'orders'    => count( ans_tb_reminder_event_order_ids( (int) $eid ) ),
		);
	}
	return $out;
}

/**
 * The daily pass. Sends every reminder that falls due today.
 *
 * Safe to run more than once a day, and safe to run after a failure part way
 * through: the per-order record means the second run picks up only what the
 * first did not finish. That property is the entire reason this is not just a
 * loop over ans_tb_send_reminder().
 *
 * @param bool $dry_run
 * @return array
 */
function ans_tb_reminder_tick( $dry_run = false, $allow_non_production = false ) {
	if ( ! $dry_run && ! ans_tb_reminder_is_live() && ! $allow_non_production ) {
		return array(
			'ran_at'  => current_time( 'mysql' ),
			'refused' => true,
			'reason'  => 'Not the production site (' . get_option( 'siteurl' ) . '). This is a clone holding real patron '
				. 'addresses, so a real send is refused. Pass allow_non_production to override, or use dry_run.',
		);
	}
	$ran = array();
	foreach ( ans_tb_reminder_schedule_overview() as $row ) {
		if ( empty( $row['due_today'] ) ) {
			continue;
		}
		$ran[] = ans_tb_reminder_run_event( $row['event_id'], (int) $row['days_out'], $dry_run, false );
	}
	return array(
		'ran_at'  => current_time( 'mysql' ),
		'dry_run' => (bool) $dry_run,
		'events'  => count( $ran ),
		'detail'  => $ran,
	);
}
add_action( 'ans_tb_reminder_daily', 'ans_tb_reminder_tick' );

/**
 * Keep the daily job scheduled, at 9am site time.
 *
 * Scheduled from init rather than an activation hook, because a plugin UPDATE
 * does not fire activation - so an activation-only schedule would exist on
 * sites that happened to be activated after this shipped and nowhere else.
 *
 * WP-Cron only fires on traffic, and Kinsta may disable it in favour of a
 * system cron. That is why POST ans-ops style tick route exists below: the
 * schedule can be driven externally without changing any of this.
 */
function ans_tb_reminder_schedule_cron() {
	if ( ! ans_tb_reminder_is_live() ) {
		/* A clone must never carry this schedule. Clear it if a clone picked one
		   up before this guard existed, or inherited one in a database copy. */
		if ( wp_next_scheduled( 'ans_tb_reminder_daily' ) ) {
			wp_clear_scheduled_hook( 'ans_tb_reminder_daily' );
		}
		return;
	}
	if ( wp_next_scheduled( 'ans_tb_reminder_daily' ) ) {
		return;
	}
	$tz   = wp_timezone();
	$next = new DateTimeImmutable( 'today 09:00', $tz );
	if ( $next->getTimestamp() <= time() ) {
		$next = $next->modify( '+1 day' );
	}
	wp_schedule_event( $next->getTimestamp(), 'daily', 'ans_tb_reminder_daily' );
}
add_action( 'init', 'ans_tb_reminder_schedule_cron' );

/* -------------------------------------------------------------------------
 * REST: configuration, inspection, and firing
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', function () {

	/** GET/POST ars-nova/v1/event/{id}/reminder-config */
	register_rest_route( ANS_TB_NS, '/event/(?P<id>\d+)/reminder-config', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'ans_tb_perm',
			'callback'            => function ( $req ) {
				$id  = (int) $req['id'];
				$cfg = ans_tb_reminder_config( $id );
				$cfg['title']    = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
				$cfg['days_out'] = ans_tb_reminder_days_out( $id );
				$cfg['orders']   = count( ans_tb_reminder_event_order_ids( $id ) );
				$cfg['note']     = 'copy fields resolve event -> global email settings -> code default; "sources" says which answered.';
				return $cfg;
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => 'ans_tb_perm',
			'callback'            => function ( $req ) {
				$id   = (int) $req['id'];
				if ( 'tc_events' !== get_post_type( $id ) ) {
					return new WP_Error( 'not_an_event', 'That id is not a Tickera event.', array( 'status' => 404 ) );
				}
				$keys    = ans_tb_reminder_meta_keys();
				$written = array();

				if ( null !== $req->get_param( 'enabled' ) ) {
					update_post_meta( $id, $keys['enabled'], $req->get_param( 'enabled' ) ? '1' : '0' );
					$written[] = 'enabled';
				}
				if ( null !== $req->get_param( 'offsets' ) ) {
					$parsed = ans_tb_reminder_parse_offsets( $req->get_param( 'offsets' ) );
					update_post_meta( $id, $keys['offsets'], implode( ',', $parsed ) );
					$written[] = 'offsets';
				}
				foreach ( array( 'subject', 'heading', 'intro', 'lead', 'closing', 'video' ) as $f ) {
					$v = $req->get_param( $f );
					if ( null === $v ) {
						continue;
					}
					$v = trim( (string) $v );
					if ( '' === $v ) {
						delete_post_meta( $id, $keys[ $f ] );   // empty means inherit, not blank
					} else {
						update_post_meta( $id, $keys[ $f ], $v );
					}
					$written[] = $f;
				}

				return array(
					'written' => $written,
					'config'  => ans_tb_reminder_config( $id ),
				);
			},
		),
	) );

	/** POST ars-nova/v1/event/{id}/reminder/run  {offset, dry_run, force} */
	register_rest_route( ANS_TB_NS, '/event/(?P<id>\d+)/reminder/run', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_tb_perm',
		'callback'            => function ( $req ) {
			$id     = (int) $req['id'];
			$offset = $req->get_param( 'offset' );
			$offset = ( null === $offset ) ? (int) ans_tb_reminder_days_out( $id ) : (int) $offset;
			$dry    = $req->get_param( 'dry_run' );
			$dry    = ( null === $dry ) ? true : (bool) $dry;
			return ans_tb_reminder_run_event( $id, $offset, $dry, (bool) $req->get_param( 'force' ) );
		},
	) );

	/** POST ars-nova/v1/event/{id}/reminder/preview  {to} - one named address, never a customer */
	register_rest_route( ANS_TB_NS, '/event/(?P<id>\d+)/reminder/preview', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_tb_perm',
		'callback'            => function ( $req ) {
			$to = sanitize_email( (string) $req->get_param( 'to' ) );
			if ( ! $to || ! is_email( $to ) ) {
				return new WP_Error( 'missing_to', 'A valid "to" address is required.', array( 'status' => 400 ) );
			}
			$id     = (int) $req['id'];
			$orders = ans_tb_reminder_event_order_ids( $id );
			if ( ! $orders ) {
				return new WP_Error( 'no_orders', 'No paid orders hold a live ticket for this event, so there is nothing to render.', array( 'status' => 404 ) );
			}
			$order_id = $req->get_param( 'order_id' ) ? (int) $req->get_param( 'order_id' ) : $orders[0];
			$offset   = $req->get_param( 'offset' );
			$offset   = ( null === $offset ) ? (int) ans_tb_reminder_days_out( $id ) : (int) $offset;
			return ans_tb_reminder_send_with_config( $order_id, $id, $offset, $to );
		},
	) );

	/** GET ars-nova/v1/reminder/schedule - what is due, and when */
	register_rest_route( ANS_TB_NS, '/reminder/schedule', array(
		'methods'             => 'GET',
		'permission_callback' => 'ans_tb_perm',
		'callback'            => function () {
			return array(
				'now'           => current_time( 'mysql' ),
				'siteurl'       => get_option( 'siteurl' ),
				'is_production' => ans_tb_reminder_is_live(),
				'env_note'      => ans_tb_reminder_is_live()
					? 'Production. Real sends will run.'
					: 'NOT production - a clone. Real sends and the cron schedule are refused here.',
				'cron_next'   => wp_next_scheduled( 'ans_tb_reminder_daily' )
					? wp_date( 'Y-m-d H:i', wp_next_scheduled( 'ans_tb_reminder_daily' ) )
					: null,
				'cron_note'   => 'WP-Cron fires on traffic and may be disabled by the host. POST reminder/tick drives it directly.',
				'events'      => ans_tb_reminder_schedule_overview(),
			);
		},
	) );

	/** POST ars-nova/v1/reminder/tick  {dry_run} - run today's pass now */
	register_rest_route( ANS_TB_NS, '/reminder/tick', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_tb_perm',
		'callback'            => function ( $req ) {
			$dry = $req->get_param( 'dry_run' );
			$dry = ( null === $dry ) ? true : (bool) $dry;
			return ans_tb_reminder_tick( $dry );
		},
	) );

	/** GET ars-nova/v1/order/{id}/reminder/history - what this order has been sent */
	register_rest_route( ANS_TB_NS, '/order/(?P<id>\d+)/reminder/history', array(
		'methods'             => 'GET',
		'permission_callback' => 'ans_tb_perm',
		'callback'            => function ( $req ) {
			$order = wc_get_order( (int) $req['id'] );
			if ( ! $order ) {
				return new WP_Error( 'no_order', 'Order not found.', array( 'status' => 404 ) );
			}
			$hits = array();
			foreach ( $order->get_meta_data() as $m ) {
				$d = $m->get_data();
				if ( 0 === strpos( (string) $d['key'], '_ans_reminder_sent_' ) ) {
					$bits   = explode( '_', substr( (string) $d['key'], strlen( '_ans_reminder_sent_' ) ) );
					$hits[] = array(
						'event_id' => isset( $bits[0] ) ? (int) $bits[0] : 0,
						'offset'   => isset( $bits[1] ) ? (int) $bits[1] : null,
						'sent_at'  => $d['value'],
					);
				}
			}
			return array( 'order_id' => (int) $req['id'], 'reminders' => $hits );
		},
	) );
} );
