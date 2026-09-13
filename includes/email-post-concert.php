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
 * Whole days SINCE the concert. Negative while it is still in the future.
 *
 * Calendar days in site time, not 24-hour blocks - "two days after" has to mean
 * the day a person would call two days after, regardless of a 7:30pm curtain.
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
	$event_day = (int) floor( ( $ts + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) / DAY_IN_SECONDS );
	$today     = (int) floor( ( time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) / DAY_IN_SECONDS );
	return $today - $event_day;
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

	$override = (int) get_post_meta( $after_event_id, 'ans_thanks_next_event', true );
	if ( $override > 0 && function_exists( 'ans_tb_event_details' ) ) {
		$row = ans_tb_event_details( $override );
		if ( $row ) {
			return $row;
		}
	}

	if ( ! function_exists( 'ans_tb_event_ts' ) || ! function_exists( 'ans_tb_event_details' ) ) {
		return null;
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
	return $best ? ans_tb_event_details( $best ) : null;
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

	$offsets = ans_tb_thanks_parse_offsets( get_post_meta( $event_id, $keys['offsets'], true ) );
	if ( ! $offsets ) {
		$offsets            = ans_tb_thanks_parse_offsets( apply_filters( 'ans_tb_thanks_default_offsets', array( 2 ) ) );
		$sources['offsets'] = 'default';
	} else {
		$sources['offsets'] = 'event';
	}

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
	);
}

/** Per-order idempotency key. Distinct from the reminder's so the two can never be confused. */
function ans_tb_thanks_sent_key( $event_id, $offset ) {
	return '_ans_thanks_sent_' . (int) $event_id . '_' . (int) $offset;
}

function ans_tb_thanks_already_sent( $order_id, $event_id, $offset ) {
	return '' !== trim( (string) get_post_meta( (int) $order_id, ans_tb_thanks_sent_key( $event_id, $offset ), true ) );
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
 * @param int    $order_id
 * @param int    $event_id
 * @param int    $offset     Days after, for the record.
 * @param string $divert_to  Non-empty = a preview to one named address.
 * @return array
 */
function ans_tb_thanks_send( $order_id, $event_id, $offset = 0, $divert_to = '' ) {
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
		update_post_meta( $order_id, ans_tb_thanks_sent_key( $event_id, $offset ), current_time( 'mysql' ) );
	}

	return array(
		'order_id' => $order_id,
		'to'       => $to,
		'sent'     => (bool) $result,
		'preview'  => (bool) $divert_to,
		'event_id' => $event_id,
		'offset'   => (int) $offset,
	);
}

/**
 * Send the thank-you for one event.
 *
 * dry_run defaults TRUE. An accidental call must do nothing - this email reaches
 * every ticket holder of a concert at once, and there is no recalling it.
 */
function ans_tb_thanks_run_event( $event_id, $offset = null, $dry_run = true, $force = false ) {
	$event_id = (int) $event_id;
	$cfg      = ans_tb_thanks_config( $event_id );
	$offset   = ( null === $offset ) ? (int) $cfg['days_since'] : (int) $offset;

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

		if ( ! $force && ans_tb_thanks_already_sent( $oid, $event_id, $offset ) ) {
			$skipped[] = array( 'order_id' => $oid, 'to' => $to, 'reason' => 'already_sent' );
			continue;
		}
		if ( function_exists( 'ans_tb_is_opted_out' ) && ans_tb_is_opted_out( $to ) ) {
			$skipped[] = array( 'order_id' => $oid, 'to' => $to, 'reason' => 'opted_out' );
			continue;
		}
		if ( $dry_run ) {
			$would[] = array( 'order_id' => $oid, 'to' => $to );
			continue;
		}
		$sent[] = ans_tb_thanks_send( $oid, $event_id, $offset );
	}

	return array(
		'event_id'   => $event_id,
		'title'      => html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ),
		'offset'     => $offset,
		'days_since' => $cfg['days_since'],
		'dry_run'    => (bool) $dry_run,
		'orders'     => count( $order_ids ),
		'would_send' => $would,
		'skipped'    => $skipped,
		'sent'       => $sent,
	);
}

/** Daily pass: send whatever falls due today. */
function ans_tb_thanks_tick( $dry_run = false ) {
	if ( ! $dry_run && function_exists( 'ans_tb_reminder_is_live' ) && ! ans_tb_reminder_is_live() ) {
		return array( 'refused' => true, 'reason' => 'not_production' );
	}
	$ids = get_posts( array(
		'post_type'        => 'tc_events',
		'post_status'      => 'publish',
		'posts_per_page'   => 200,
		'fields'           => 'ids',
		'suppress_filters' => true,
	) );

	$ran = array();
	foreach ( $ids as $id ) {
		$cfg = ans_tb_thanks_config( (int) $id );
		if ( ! $cfg['enabled'] || null === $cfg['days_since'] ) {
			continue;
		}
		if ( ! in_array( (int) $cfg['days_since'], $cfg['offsets'], true ) ) {
			continue;
		}
		$ran[] = ans_tb_thanks_run_event( (int) $id, (int) $cfg['days_since'], $dry_run, false );
	}
	return array( 'checked' => count( $ids ), 'ran' => $ran );
}

/* -------------------------------------------------------------------------
 * REST
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', function () {

	register_rest_route( ANS_TB_NS, '/event/(?P<id>\d+)/thanks-config', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'ans_tb_perm',
			'callback'            => function ( $req ) {
				$id            = (int) $req['id'];
				$cfg           = ans_tb_thanks_config( $id );
				$cfg['title']  = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
				$cfg['orders'] = function_exists( 'ans_tb_reminder_event_order_ids' )
					? count( ans_tb_reminder_event_order_ids( $id ) ) : 0;
				$cfg['note']   = 'offsets are days AFTER the concert. highlights and images are per-event only - there is no global layer for either.';
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
				$keys    = ans_tb_thanks_meta_keys();
				$written = array();

				if ( null !== $req->get_param( 'enabled' ) ) {
					update_post_meta( $id, $keys['enabled'], $req->get_param( 'enabled' ) ? '1' : '0' );
					$written[] = 'enabled';
				}
				if ( null !== $req->get_param( 'offsets' ) ) {
					update_post_meta( $id, $keys['offsets'], implode( ',', ans_tb_thanks_parse_offsets( $req->get_param( 'offsets' ) ) ) );
					$written[] = 'offsets';
				}
				if ( null !== $req->get_param( 'images' ) ) {
					$raw = $req->get_param( 'images' );
					$raw = is_array( $raw ) ? implode( ',', $raw ) : (string) $raw;
					$ids = array_filter( array_map( 'intval', preg_split( '/[\s,]+/', trim( $raw ) ) ) );
					if ( $ids ) {
						update_post_meta( $id, $keys['images'], implode( ',', $ids ) );
					} else {
						delete_post_meta( $id, $keys['images'] );
					}
					$written[] = 'images';
				}
				if ( null !== $req->get_param( 'next_event' ) ) {
					$n = (int) $req->get_param( 'next_event' );
					if ( $n > 0 ) {
						update_post_meta( $id, $keys['next_event'], $n );
					} else {
						delete_post_meta( $id, $keys['next_event'] );
					}
					$written[] = 'next_event';
				}
				foreach ( array( 'subject', 'heading', 'intro', 'highlights', 'closing' ) as $f ) {
					$v = $req->get_param( $f );
					if ( null === $v ) {
						continue;
					}
					$v = trim( (string) $v );
					if ( '' === $v ) {
						delete_post_meta( $id, $keys[ $f ] );
					} else {
						update_post_meta( $id, $keys[ $f ], sanitize_textarea_field( $v ) );
					}
					$written[] = $f;
				}

				return array( 'written' => $written, 'config' => ans_tb_thanks_config( $id ) );
			},
		),
	) );

	register_rest_route( ANS_TB_NS, '/event/(?P<id>\d+)/thanks/run', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_tb_perm',
		'callback'            => function ( $req ) {
			$dry = $req->get_param( 'dry_run' );
			$dry = ( null === $dry ) ? true : (bool) $dry;
			$off = $req->get_param( 'offset' );
			return ans_tb_thanks_run_event(
				(int) $req['id'],
				( null === $off ) ? null : (int) $off,
				$dry,
				(bool) $req->get_param( 'force' )
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
			return ans_tb_thanks_send( (int) $orders[0], $id, (int) ans_tb_thanks_days_since( $id ), $to );
		},
	) );

	register_rest_route( ANS_TB_NS, '/thanks/tick', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_tb_perm',
		'callback'            => function ( $req ) {
			$dry = $req->get_param( 'dry_run' );
			return ans_tb_thanks_tick( ( null === $dry ) ? true : (bool) $dry );
		},
	) );
} );

/**
 * Daily job, 09:05 site time.
 *
 * Five minutes after the reminder's pass rather than the same minute: both
 * read the same events and the same orders, and two overlapping runs on one
 * shared-hosting PHP worker is how a slow query becomes a timeout in the middle
 * of a send. Never scheduled off production, and unscheduled if a database
 * clone brought one along - the guard that exists because staging was once left
 * one cron tick from mailing eleven real patrons.
 */
add_action( 'init', function () {
	if ( function_exists( 'ans_tb_reminder_is_live' ) && ! ans_tb_reminder_is_live() ) {
		$ts = wp_next_scheduled( 'ans_tb_thanks_daily' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'ans_tb_thanks_daily' );
		}
		return;
	}
	if ( ! wp_next_scheduled( 'ans_tb_thanks_daily' ) ) {
		$local = strtotime( 'tomorrow 09:05', current_time( 'timestamp' ) );
		wp_schedule_event( $local - ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), 'daily', 'ans_tb_thanks_daily' );
	}
} );

add_action( 'ans_tb_thanks_daily', function () {
	ans_tb_thanks_tick( false );
} );
