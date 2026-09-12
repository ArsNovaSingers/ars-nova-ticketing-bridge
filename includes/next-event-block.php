<?php
/**
 * [ans_next_event] — the home page's "what is on next" band.
 *
 * WHY THIS EXISTS
 * The home page's second position used to be static: whatever prose somebody
 * last typed there. That is the one slot on the site guaranteed to be seen by
 * a first-time visitor, and it was the slot most likely to be out of date,
 * because keeping it current is a manual job nobody owns. This block removes
 * the job: it reads the season out of Tickera and renders whatever is actually
 * next, for ever, with no editorial step.
 *
 * WHAT IT SHOWS — a PROJECT, not a performance.
 * A concert here is a run of nights, not one date. Resolving "next" down to a
 * single tc_events post would advertise Rivers & Streams three separate times
 * across October and hide two thirds of the run each time. So performances are
 * grouped by their event_category term — the thing this site already calls a
 * project — and the group with the soonest upcoming performance wins. That is
 * also what makes the artwork and the blurb available: both hang off the
 * project's page, not off any individual night.
 *
 * WHEN IT ROLLS OVER
 * A project stays current until its LAST performance has started, plus a grace
 * window (default four hours, so a concert does not vanish from the home page
 * while the audience is still in the hall). Only then does the next project
 * take the slot.
 *
 * TIME IS SITE-LOCAL, AND THAT IS NOT AUTOMATIC.
 * WordPress forces PHP's default timezone to UTC no matter what the site is
 * set to, so a bare strtotime() on a Tickera date reads a Mountain-time string
 * as if it were UTC. That exact bug shipped once and printed every performance
 * on the season-packages page six to seven hours early for weeks. Every date
 * here goes through ans_tb_local_ts() / ans_tb_event_ts(), which build a
 * DateTimeImmutable against wp_timezone(), and every rendered date goes through
 * wp_date(). Do not introduce strtotime() or date() into this file.
 *
 * BOUNDARY: this file renders. It does not decide what a concert IS — kind
 * filtering is ans_tb_event_kind()'s job, grouping is ans_sp_event_term()'s,
 * and the venue-to-city reduction is ans_sp_place()'s. If one of those is
 * wrong, fix it there; three listings depend on each of them and they must not
 * drift apart.
 *
 * @package ArsNovaTicketingBridge
 * @since   1.23.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'ans_next_event', 'ans_ne_render' );

/**
 * How long after a performance starts it still counts as "on", in seconds.
 *
 * Four hours. A concert that started at 7:30 is still the thing happening
 * tonight at 10, and a visitor who lands on the home page during the interval
 * should not be told the next event is five weeks away.
 */
if ( ! defined( 'ANS_NE_GRACE' ) ) {
	define( 'ANS_NE_GRACE', 4 * HOUR_IN_SECONDS );
}

/**
 * Is this performance a livestream rather than a room with seats in it?
 *
 * Livestream events are real tc_events posts that deliberately duplicate an
 * in-person night — same project, same date, same start time, different
 * "venue". Rendering them as their own card would show the visitor two
 * Saturdays. They are folded into the matching in-person card instead, and
 * only stand alone when no in-person performance shares their date.
 *
 * @param int $event_id tc_events post ID.
 * @return bool
 */
function ans_ne_is_livestream( $event_id ) {
	$loc = strtolower( (string) get_post_meta( (int) $event_id, 'event_location', true ) );
	return ( '' !== $loc && false !== strpos( $loc, 'livestream' ) );
}

/**
 * Every upcoming performance, grouped by project, soonest project first.
 *
 * @param string[] $kinds Event kinds to allow. Empty array means do not filter.
 * @return array<string,array> Keyed group => { term, page_id, title, first, events[] }.
 */
function ans_ne_upcoming_groups( $kinds ) {
	if ( ! post_type_exists( 'tc_events' ) ) {
		return array();
	}

	$posts = get_posts(
		array(
			'post_type'        => 'tc_events',
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'suppress_filters' => false,
		)
	);

	$now    = time();
	$groups = array();

	foreach ( $posts as $po ) {
		if ( ! empty( $kinds ) && ! in_array( ans_tb_event_kind( $po->ID ), $kinds, true ) ) {
			continue;
		}

		// An event Kim has explicitly hidden stays hidden here too.
		if ( get_post_meta( $po->ID, 'ans_hide', true ) ) {
			continue;
		}

		$ts = ans_tb_event_ts( $po->ID );
		if ( ! $ts || ( $ts + ANS_NE_GRACE ) <= $now ) {
			continue;
		}

		$term = function_exists( 'ans_sp_event_term' ) ? ans_sp_event_term( $po->ID ) : null;

		// An uncategorised event still gets a slot of its own rather than being
		// dropped. Hiding a real concert is the more expensive failure: nobody
		// reports a page that looks fine, and the first anyone would know is a
		// patron unable to find the night they wanted.
		$key = $term ? 'term-' . $term->term_id : 'event-' . $po->ID;

		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'term'   => $term,
				'title'  => $term ? $term->name : $po->post_title,
				'first'  => $ts,
				'events' => array(),
			);
		}

		$groups[ $key ]['events'][] = array(
			'id'     => $po->ID,
			'ts'     => $ts,
			'title'  => $po->post_title,
			'stream' => ans_ne_is_livestream( $po->ID ),
			'loc'    => (string) get_post_meta( $po->ID, 'event_location', true ),
		);

		if ( $ts < $groups[ $key ]['first'] ) {
			$groups[ $key ]['first'] = $ts;
		}
	}

	uasort(
		$groups,
		function ( $a, $b ) {
			return $a['first'] <=> $b['first'];
		}
	);

	return $groups;
}

/**
 * Resolve the page a project links to, and the artwork and blurb hanging off it.
 *
 * Order matters and mirrors [ans_season_projects] deliberately — if these two
 * disagree, the home page sends people somewhere the season page does not.
 *
 * @param array $group One entry from ans_ne_upcoming_groups().
 * @return array{page_id:int,url:string,thumb:int,blurb:string}
 */
function ans_ne_resolve_page( $group ) {
	$page_id = 0;

	if ( $group['term'] ) {
		$page_id = (int) get_term_meta( $group['term']->term_id, 'ans_page_id', true );
	}

	if ( ! $page_id && function_exists( 'ans_se_page_for' ) ) {
		$page_id = (int) ans_se_page_for( $group['title'] );
	}

	$url   = $page_id ? (string) get_permalink( $page_id ) : '';
	$thumb = $page_id ? (int) get_post_thumbnail_id( $page_id ) : 0;

	// Blurb: the term description is the editorial summary Kim controls from
	// Events -> Categories; the page excerpt is the fallback. Both are plain
	// text by convention, so neither is run through the_content filters.
	$blurb = '';
	if ( $group['term'] && '' !== trim( (string) $group['term']->description ) ) {
		$blurb = trim( (string) $group['term']->description );
	} elseif ( $page_id ) {
		$blurb = trim( (string) get_post_field( 'post_excerpt', $page_id ) );
	}

	return array(
		'page_id' => $page_id,
		'url'     => $url,
		'thumb'   => $thumb,
		'blurb'   => $blurb,
	);
}

/**
 * Turn a project's performances into the cards the band renders.
 *
 * Folds livestreams into the in-person night they shadow. Returns at most
 * $limit cards plus a count of what did not fit.
 *
 * @param array $events Raw event rows from ans_ne_upcoming_groups().
 * @param int   $limit  Maximum cards to render.
 * @return array{cards:array,extra:int}
 */
function ans_ne_cards( $events, $limit ) {
	usort(
		$events,
		function ( $a, $b ) {
			return $a['ts'] <=> $b['ts'];
		}
	);

	$live   = array();
	$stream = array();
	foreach ( $events as $e ) {
		if ( $e['stream'] ) {
			$stream[] = $e;
		} else {
			$live[] = $e;
		}
	}

	// Index the livestreams by calendar day so a same-day one can be absorbed.
	$stream_days = array();
	foreach ( $stream as $s ) {
		$stream_days[ wp_date( 'Y-m-d', $s['ts'] ) ] = true;
	}

	$cards = array();
	foreach ( $live as $e ) {
		$day     = wp_date( 'Y-m-d', $e['ts'] );
		$cards[] = array(
			'ts'     => $e['ts'],
			'place'  => function_exists( 'ans_sp_place' ) ? ans_sp_place( $e['loc'] ) : $e['loc'],
			'stream' => isset( $stream_days[ $day ] ),
		);
		unset( $stream_days[ $day ] );
	}

	// Any livestream with no in-person night on the same date stands on its own.
	foreach ( $stream as $s ) {
		$day = wp_date( 'Y-m-d', $s['ts'] );
		if ( isset( $stream_days[ $day ] ) ) {
			$cards[] = array(
				'ts'     => $s['ts'],
				'place'  => __( 'Livestream', 'ars-nova' ),
				'stream' => false,
			);
			unset( $stream_days[ $day ] );
		}
	}

	usort(
		$cards,
		function ( $a, $b ) {
			return $a['ts'] <=> $b['ts'];
		}
	);

	$extra = max( 0, count( $cards ) - $limit );

	return array(
		'cards' => array_slice( $cards, 0, $limit ),
		'extra' => $extra,
	);
}

/**
 * The band's stylesheet, emitted once per request.
 *
 * This lives in the plugin rather than in page content on purpose. CSS sitting
 * in a page is database-resident, so it cannot ride the files-only staging to
 * live push, and page-resident JavaScript can additionally be corrupted in
 * place by WordPress's own convert_chars() filter. Shipping from a plugin file
 * has neither problem.
 *
 * Breakpoints are the ones this site actually has, read from Kadence rather
 * than assumed: 1024/1025 is the header swap, 719 is the layout mobile edge.
 *
 * @return string
 */
function ans_ne_styles() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;

	return '<style id="ans-next-event">
.ans-ne{--ans-navy:#0e1b3a;--ans-navy-rgb:14,27,58;--ans-gold:#d8b25e;--ans-gold-soft:#c9a96a;position:relative;background:var(--ans-navy);overflow:hidden}
.ans-ne__photo{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 38%;opacity:.42;display:block}
.ans-ne__wash{position:absolute;inset:0;background:linear-gradient(90deg,rgba(var(--ans-navy-rgb),.99) 0%,rgba(var(--ans-navy-rgb),.98) 40%,rgba(var(--ans-navy-rgb),.88) 66%,rgba(var(--ans-navy-rgb),.66) 88%,rgba(var(--ans-navy-rgb),.58) 100%)}
.ans-ne__edges{position:absolute;inset:0;background:linear-gradient(180deg,rgba(var(--ans-navy-rgb),.9) 0%,rgba(var(--ans-navy-rgb),0) 20%,rgba(var(--ans-navy-rgb),0) 76%,rgba(var(--ans-navy-rgb),.92) 100%)}
.ans-ne__inner{position:relative;display:grid;grid-template-columns:460px minmax(0,1fr);align-items:stretch;min-height:560px}
.ans-ne__art{position:relative;overflow:hidden}
.ans-ne__art img{width:100%;height:100%;object-fit:cover;display:block}
.ans-ne__art::after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,rgba(var(--ans-navy-rgb),0) 52%,rgba(var(--ans-navy-rgb),.98) 100%)}
.ans-ne__art--none{background:linear-gradient(135deg,#0e1b3a 0%,#16423e 55%,#0e1b3a 100%)}
.ans-ne__body{padding:64px 88px 64px 72px;display:flex;flex-direction:column;justify-content:center;gap:28px}
.ans-ne__eyebrow{font-size:12px;font-weight:700;letter-spacing:.22em;text-transform:uppercase;color:var(--ans-gold-soft);text-shadow:0 1px 12px rgba(0,0,0,.5)}
.ans-ne__title{margin:0;font-family:Cinzel,Georgia,serif;font-weight:400;font-size:clamp(34px,5.2vw,68px);line-height:1.02;color:#fff;text-shadow:0 2px 20px rgba(0,0,0,.55)}
.ans-ne__dates{display:grid;grid-template-columns:repeat(var(--ans-ne-cols,3),minmax(0,1fr));gap:20px;max-width:660px}
.ans-ne__card{border-top:2px solid var(--ans-gold);padding-top:16px;display:flex;flex-direction:column;gap:5px}
.ans-ne__day{font-size:11px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.62)}
.ans-ne__date{font-family:Cinzel,Georgia,serif;font-size:30px;line-height:1;color:#fff;text-shadow:0 1px 12px rgba(0,0,0,.5)}
.ans-ne__time{font-size:13px;color:rgba(255,255,255,.85);margin-top:3px}
.ans-ne__place{font-size:13px;color:rgba(255,255,255,.62)}
.ans-ne__more{font-size:13px;color:rgba(255,255,255,.62);align-self:end}
.ans-ne__blurb{margin:0;font-size:16px;line-height:1.65;color:rgba(255,255,255,.82);max-width:60ch;text-wrap:pretty;text-shadow:0 1px 14px rgba(0,0,0,.55)}
.ans-ne__cta{display:inline-block;background:var(--ans-gold);color:var(--ans-navy);border:1px solid var(--ans-gold);border-radius:40px;padding:15px 38px;font-size:14px;font-weight:700;letter-spacing:1px;text-transform:uppercase;text-decoration:none;transition:background-color .2s ease,color .2s ease}
.ans-ne__cta:hover,.ans-ne__cta:focus-visible{background:var(--ans-navy);color:var(--ans-gold);border-color:var(--ans-gold)}
@media (max-width:1024px){
.ans-ne__inner{grid-template-columns:1fr;min-height:0}
.ans-ne__art{height:260px}
.ans-ne__art::after{background:linear-gradient(180deg,rgba(var(--ans-navy-rgb),0) 38%,rgba(var(--ans-navy-rgb),.97) 100%)}
.ans-ne__wash{background:linear-gradient(180deg,rgba(var(--ans-navy-rgb),.55) 0%,rgba(var(--ans-navy-rgb),.93) 46%,rgba(var(--ans-navy-rgb),.9) 100%)}
.ans-ne__body{padding:40px 48px 52px;gap:22px}
.ans-ne__dates{gap:16px;max-width:none}
.ans-ne__date{font-size:26px}
}
@media (max-width:719px){
.ans-ne__inner{grid-template-columns:1fr;min-height:0}
.ans-ne__art{height:220px}
.ans-ne__art::after{background:linear-gradient(180deg,rgba(var(--ans-navy-rgb),0) 45%,rgba(var(--ans-navy-rgb),.98) 100%)}
.ans-ne__photo{opacity:.3}
.ans-ne__wash{background:linear-gradient(180deg,rgba(var(--ans-navy-rgb),.9) 0%,rgba(var(--ans-navy-rgb),.96) 100%)}
.ans-ne__body{padding:36px 24px 44px;gap:20px}
.ans-ne__dates{grid-template-columns:1fr;gap:0;max-width:none}
.ans-ne__card{border-top:1px solid rgba(255,255,255,.18);border-left:none;padding:14px 0;flex-direction:row;align-items:baseline;gap:12px;flex-wrap:wrap}
.ans-ne__card:first-child{border-top:2px solid var(--ans-gold)}
.ans-ne__day{color:rgba(255,255,255,.55)}
.ans-ne__date{font-size:20px}
.ans-ne__time,.ans-ne__place{margin-top:0}
.ans-ne__cta{display:block;text-align:center;padding:15px 20px}
}
@media (prefers-reduced-motion:reduce){.ans-ne__cta{transition:none}}
</style>';
}

/**
 * Render the band.
 *
 * Attributes:
 *   kind     — comma-separated event kinds to allow, or "any". Default "concert".
 *   limit    — maximum date cards to show. Default 4.
 *   overlay  — attachment ID of the photograph washed behind the navy.
 *   eyebrow  — override the small gold label above the title.
 *   cta      — override the button label.
 *   empty    — text to show when nothing is upcoming. Default: render nothing.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function ans_ne_render( $atts ) {
	$a = shortcode_atts(
		array(
			'kind'    => 'concert',
			'limit'   => 4,
			'overlay' => '',
			'eyebrow' => '',
			'cta'     => 'Explore',
			'empty'   => '',
		),
		$atts,
		'ans_next_event'
	);

	$kinds  = function_exists( 'ans_tb_kind_filter' ) ? ans_tb_kind_filter( $a['kind'] ) : array( 'concert' );
	$groups = ans_ne_upcoming_groups( $kinds );

	// Nothing upcoming is a real state, not an error: it is what the site looks
	// like between seasons. Render nothing at all unless an author asked for a
	// message, so the home page closes up cleanly rather than showing a husk.
	if ( empty( $groups ) ) {
		$empty = trim( (string) $a['empty'] );
		return '' === $empty ? '' : '<div class="ans-ne ans-ne--empty"><p>' . esc_html( $empty ) . '</p></div>';
	}

	$group = reset( $groups );
	$page  = ans_ne_resolve_page( $group );
	$built = ans_ne_cards( $group['events'], max( 1, (int) $a['limit'] ) );
	$cards = $built['cards'];

	// The eyebrow says how many nights there are, because "three nights" is the
	// single most useful fact for someone deciding whether they can make it.
	$eyebrow = trim( (string) $a['eyebrow'] );
	if ( '' === $eyebrow ) {
		$total   = count( $cards ) + $built['extra'];
		$eyebrow = ( $total > 1 )
			/* translators: %s: number of performances, already localised. */
			? sprintf( __( 'Next performance &nbsp;·&nbsp; %s nights', 'ars-nova' ), number_format_i18n( $total ) )
			: __( 'Next performance', 'ars-nova' );
	}

	$out  = ans_ne_styles();
	$out .= '<section class="ans-ne" aria-label="' . esc_attr__( 'Next performance', 'ars-nova' ) . '">';

	$overlay_id = (int) $a['overlay'];
	if ( $overlay_id ) {
		// Decorative: the photograph carries no information the copy does not,
		// so an empty alt keeps it out of the accessibility tree rather than
		// making a screen-reader listen to it.
		$out .= wp_get_attachment_image(
			$overlay_id,
			'full',
			false,
			array(
				'class'       => 'ans-ne__photo',
				'alt'         => '',
				'aria-hidden' => 'true',
				'loading'     => 'lazy',
			)
		);
	}

	$out .= '<span class="ans-ne__wash" aria-hidden="true"></span>';
	$out .= '<span class="ans-ne__edges" aria-hidden="true"></span>';
	$out .= '<div class="ans-ne__inner">';

	if ( $page['thumb'] ) {
		$out .= '<div class="ans-ne__art">'
			. wp_get_attachment_image( $page['thumb'], 'large', false, array( 'alt' => '', 'aria-hidden' => 'true', 'loading' => 'lazy' ) )
			. '</div>';
	} else {
		$out .= '<div class="ans-ne__art ans-ne__art--none" aria-hidden="true"></div>';
	}

	$out .= '<div class="ans-ne__body">';
	$out .= '<div class="ans-ne__eyebrow">' . wp_kses( $eyebrow, array( 'br' => array() ) ) . '</div>';
	$out .= '<h2 class="ans-ne__title">' . esc_html( $group['title'] ) . '</h2>';

	$cols = max( 1, count( $cards ) );
	$out .= '<div class="ans-ne__dates" style="--ans-ne-cols:' . (int) $cols . '">';

	foreach ( $cards as $c ) {
		$place = $c['place'];
		if ( $c['stream'] ) {
			$place = '' === $place
				? __( 'Livestream', 'ars-nova' )
				/* translators: %s: city or venue name. */
				: sprintf( __( '%s &nbsp;+&nbsp; livestream', 'ars-nova' ), $place );
		}

		$out .= '<div class="ans-ne__card">';
		$out .= '<div class="ans-ne__day">' . esc_html( wp_date( 'l', $c['ts'] ) ) . '</div>';
		$out .= '<div class="ans-ne__date">' . esc_html( wp_date( 'M j', $c['ts'] ) ) . '</div>';
		$out .= '<div class="ans-ne__time">' . esc_html( wp_date( 'g:i a', $c['ts'] ) ) . '</div>';
		$out .= '<div class="ans-ne__place">' . wp_kses( $place, array() ) . '</div>';
		$out .= '</div>';
	}

	if ( $built['extra'] > 0 ) {
		$out .= '<div class="ans-ne__more">'
			/* translators: %s: number of further performances. */
			. esc_html( sprintf( _n( 'and %s more night', 'and %s more nights', $built['extra'], 'ars-nova' ), number_format_i18n( $built['extra'] ) ) )
			. '</div>';
	}

	$out .= '</div>';

	if ( '' !== $page['blurb'] ) {
		$out .= '<p class="ans-ne__blurb">' . esc_html( $page['blurb'] ) . '</p>';
	}

	if ( '' !== $page['url'] ) {
		$out .= '<div><a class="ans-ne__cta" href="' . esc_url( $page['url'] ) . '">'
			. esc_html( $a['cta'] )
			. '<span class="screen-reader-text"> ' . esc_html( $group['title'] ) . '</span>'
			. '</a></div>';
	}

	$out .= '</div></div></section>';

	return $out;
}
