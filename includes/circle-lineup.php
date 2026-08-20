<?php
/**
 * Ars Nova Ticketing Bridge - [ans_circle_lineup]
 *
 * The Nova Circle benefit lineup, in date order, for the /nova-circle/ page.
 *
 * WHY THIS SELECTS BY PERK TIER AND NOT BY ans_event_kind
 * ------------------------------------------------------
 * v1.14.0 added ans_event_kind so [ans_season_projects] could stop listing the
 * season-long membership as if it were a concert. It would be tempting to reuse
 * that key here and simply ask for kind="perk". It would be wrong.
 *
 * A Nova Circle benefit reaches a patron two different ways, and both belong in
 * one list:
 *   1. a dedicated perk event - the insider look at the choir, the open
 *      rehearsal, the donor brunch - which exists only because the Circle
 *      exists, and
 *   2. a pre-concert talk marked on an ORDINARY concert night, which is a
 *      Circle benefit attached to a performance the whole public can buy a
 *      ticket to.
 *
 * A kind filter finds the first group and misses the second. Marking those
 * concerts kind="perk" to compensate would drop three real performances off
 * This Season - trading one leak for a worse one. So the question this
 * shortcode asks is the one the data already answers: does this event carry a
 * perk belonging to the requested tier?
 *
 * EFFECTIVE TIER
 * --------------
 * ans_perk_tier, trimmed and lowercased, defaulting to `circle` when ans_perk
 * is set but ans_perk_tier is blank. That default is the documented v1.11.0
 * behaviour and is kept deliberately - events marked before the tier key
 * existed are Circle perks, and silently dropping them would empty this page.
 *
 * DATES
 * -----
 * Parsed through ans_tb_local_ts() and rendered through wp_date(). Never a bare
 * strtotime(), never date_i18n(). The stored value is a naive site-local wall
 * clock while WordPress runs PHP in UTC; getting this wrong printed every
 * performance 6-7 hours early once already (v1.9.4). It is a standing rule in
 * this plugin, not a preference.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * [ans_circle_lineup] - tier benefits in date order.
 *
 * Attributes:
 *   tier="circle"      which perk tier to list. Default: circle.
 *   from="2026-08-01"  earliest event to show. Default: today.
 *   to="2027-08-31"    latest. Default: none.
 *   show_past="0"      include events before `from`. Default 0.
 *   drafts="auto"      auto = logged-in editors also see drafts (badged);
 *                      never = publish only; always = include drafts for all.
 *   limit="30"         max rows. Default 30.
 *   empty_text="..."   shown when nothing matches.
 * ----------------------------------------------------------------------- */

add_shortcode( 'ans_circle_lineup', 'ans_cl_render' );

/**
 * The perk tier an event belongs to, normalised.
 *
 * @param int $event_id tc_events post ID.
 * @return string Lowercased tier slug, or '' when the event carries no perk.
 */
function ans_cl_event_tier( $event_id ) {
    $event_id = (int) $event_id;
    $perk     = trim( (string) get_post_meta( $event_id, 'ans_perk', true ) );
    if ( '' === $perk ) {
        return '';
    }
    $tier = strtolower( trim( (string) get_post_meta( $event_id, 'ans_perk_tier', true ) ) );

    // v1.11.0 default: a perk with no tier named is a Nova Circle perk.
    return '' !== $tier ? $tier : 'circle';
}

/** Print the stylesheet once per request. */
function ans_cl_styles() {
    static $done = false;
    if ( $done ) {
        return '';
    }
    $done = true;
    return '<style id="ans-circle-lineup">
.ans-cl{--ans-navy:#0e1b3a;--ans-gold:#c7a24a;--ans-cream:#f5f1e8;max-width:900px;margin:0 auto}
.ans-cl__row{display:flex;gap:24px;align-items:flex-start;padding:22px 0;border-bottom:1px solid rgba(14,27,58,.10)}
.ans-cl__row:last-child{border-bottom:0}
.ans-cl__date{flex:0 0 108px;text-align:center;background:var(--ans-navy);color:#fff;border-radius:8px;padding:12px 8px;line-height:1.15}
.ans-cl__dow{display:block;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#d8b25e}
.ans-cl__day{display:block;font-size:30px;font-weight:700;margin:2px 0}
.ans-cl__mon{display:block;font-size:11px;letter-spacing:2px;text-transform:uppercase}
.ans-cl__time{display:block;font-size:12px;margin-top:5px;color:#e7ebf3}
.ans-cl__body{flex:1 1 auto;min-width:0}
.ans-cl__perk{font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#8a6d24;margin:0 0 5px}
.ans-cl__title{font-size:22px;font-weight:700;line-height:1.25;margin:0 0 5px}
.ans-cl__title a{text-decoration:none;color:var(--ans-navy)}
.ans-cl__title a:hover{text-decoration:underline}
.ans-cl__venue{font-size:15px;margin:0;color:#3a4560}
.ans-cl__note{display:inline-block;font-size:12px;letter-spacing:1px;text-transform:uppercase;background:rgba(199,162,74,.22);color:#6d551a;padding:4px 10px;border-radius:20px;margin:8px 8px 0 0}
.ans-cl__note--draft{background:rgba(122,31,43,.14);color:#7a1f2b}
.ans-cl__empty{font-size:17px;color:#3a4560;font-style:italic}
@media (max-width:781px){.ans-cl__row{flex-wrap:wrap;gap:16px}.ans-cl__date{flex:0 0 88px}.ans-cl__title{font-size:19px}}
</style>';
}

/**
 * Render the tier lineup.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function ans_cl_render( $atts ) {
    $a = shortcode_atts( array(
        'tier'       => 'circle',
        'from'       => '',
        'to'         => '',
        'show_past'  => '0',
        'drafts'     => 'auto',
        'limit'      => 30,
        'empty_text' => 'Nova Circle events will be listed here once they are confirmed.',
    ), $atts, 'ans_circle_lineup' );

    if ( ! post_type_exists( 'tc_events' ) ) {
        return '';
    }

    $tier = strtolower( trim( (string) $a['tier'] ) );
    if ( '' === $tier ) {
        $tier = 'circle';
    }

    /*
     * Identical drafts rule to [ans_season_projects], deliberately. The Sept 24
     * insider event is still a draft because its venue, time and capacity are
     * unconfirmed, and this org has a standing rule that unconfirmed facts do
     * not reach public copy. `auto` therefore shows it to editors previewing
     * the page and to nobody else.
     */
    $can_edit    = current_user_can( 'edit_posts' );
    $want_drafts = ( 'always' === $a['drafts'] ) || ( 'auto' === $a['drafts'] && $can_edit );
    $statuses    = $want_drafts ? array( 'publish', 'draft', 'pending', 'private' ) : array( 'publish' );

    $from_ts = '' !== $a['from'] ? ans_tb_local_ts( $a['from'] ) : ( '1' === (string) $a['show_past'] ? 0 : ans_tb_today_ts() );
    $to_ts   = '' !== $a['to'] ? ans_tb_local_ts( $a['to'] . ' 23:59' ) : 0;

    $posts = get_posts( array(
        'post_type'   => 'tc_events',
        'post_status' => $statuses,
        'numberposts' => 300,
    ) );

    $rows = array();
    foreach ( $posts as $po ) {
        // Same per-event manual override the other two shortcodes honour.
        if ( get_post_meta( $po->ID, 'ans_hide', true ) ) {
            continue;
        }
        if ( ans_cl_event_tier( $po->ID ) !== $tier ) {
            continue;
        }
        $ts = ans_tb_local_ts( (string) get_post_meta( $po->ID, 'event_date_time', true ) );
        if ( ! $ts ) {
            continue; // no parseable date = not a real date on a calendar yet
        }
        if ( $from_ts && $ts < $from_ts ) {
            continue;
        }
        if ( $to_ts && $ts > $to_ts ) {
            continue;
        }

        $title = (string) get_post_meta( $po->ID, 'ans_display_title', true );
        if ( '' === $title ) {
            $title = ans_se_clean_title( $po->post_title );
        }

        /*
         * Link target: the event category's own ans_page_id term meta first,
         * then a page whose title matches the name. If neither resolves the row
         * renders unlinked - a dead href is worse than plain text, because a
         * patron who clicks it learns nothing except that we are careless.
         */
        $page_id = 0;
        $term    = ans_sp_event_term( $po->ID );
        if ( $term ) {
            $page_id = (int) get_term_meta( $term->term_id, 'ans_page_id', true );
        }
        if ( ! $page_id ) {
            $page_id = ans_se_page_for( $title );
        }

        $rows[] = array(
            'ts'    => $ts,
            'perk'  => wp_strip_all_tags( (string) get_post_meta( $po->ID, 'ans_perk', true ) ),
            'title' => wp_strip_all_tags( $title ),
            'venue' => wp_strip_all_tags( (string) get_post_meta( $po->ID, 'event_location', true ) ),
            'page'  => $page_id,
            'draft' => ( 'publish' !== $po->post_status ),
        );
    }

    usort( $rows, function ( $x, $y ) {
        return $x['ts'] <=> $y['ts'];
    } );

    $limit = max( 1, (int) $a['limit'] );
    if ( count( $rows ) > $limit ) {
        $rows = array_slice( $rows, 0, $limit );
    }

    $out = ans_cl_styles() . '<div class="ans-cl">';
    if ( empty( $rows ) ) {
        return $out . '<p class="ans-cl__empty">' . esc_html( $a['empty_text'] ) . '</p></div>';
    }

    foreach ( $rows as $r ) {
        $href = $r['page'] ? get_permalink( $r['page'] ) : '';

        $out .= '<div class="ans-cl__row">';
        $out .= '<div class="ans-cl__date">'
              . '<span class="ans-cl__dow">' . esc_html( wp_date( 'D', $r['ts'] ) ) . '</span>'
              . '<span class="ans-cl__day">' . esc_html( wp_date( 'j', $r['ts'] ) ) . '</span>'
              . '<span class="ans-cl__mon">' . esc_html( wp_date( 'M Y', $r['ts'] ) ) . '</span>'
              . '<span class="ans-cl__time">' . esc_html( wp_date( 'g:i a', $r['ts'] ) ) . '</span>'
              . '</div>';

        $out .= '<div class="ans-cl__body">';
        if ( '' !== $r['perk'] ) {
            $out .= '<p class="ans-cl__perk">' . esc_html( $r['perk'] ) . '</p>';
        }
        $name = esc_html( $r['title'] );
        if ( $href ) {
            $name = '<a href="' . esc_url( $href ) . '">' . $name . '</a>';
        }
        $out .= '<p class="ans-cl__title">' . $name . '</p>';
        if ( '' !== $r['venue'] ) {
            $out .= '<p class="ans-cl__venue">' . esc_html( $r['venue'] ) . '</p>';
        }
        if ( $r['draft'] ) {
            $out .= '<span class="ans-cl__note ans-cl__note--draft">Draft — not public</span>';
        }
        $out .= '</div></div>';
    }

    return $out . '</div>';
}
