<?php
/**
 * Ars Nova Ticketing Bridge — [ans_season_packages]
 *
 * The season-packages / subscriptions purchase page.
 *
 * DESIGN NOTE — read before changing anything here.
 * This shortcode deliberately does NOT create a bundle product, a container
 * product, or any new purchasable object. It is a *guided way to add ordinary
 * ticket products to the cart*. The patron picks a tier and their nights, and
 * we add the same real Tickera ticket products they'd get by browsing concert
 * pages one at a time.
 *
 * That is the whole point:
 *   - Tickera issues real per-event tickets, PDFs and check-in codes, because
 *     nothing about the line items is unusual.
 *   - Checkout is the ordinary path, already proven.
 *   - Pricing is handled entirely by the cart discount rules. This file does
 *     not calculate or apply a single discount.
 *
 * Anything that replaces this with a container product re-opens
 * Tickera_Wiki_01 trap #1 (orders not shaped the way Tickera expects generate
 * no tickets, silently).
 *
 * PERK MARK AND EXPLAINER — 1.12.0.
 * The night chip carries the Nova Circle mark rather than a line of text -
 * the words crowded the chip. A "?" button sits beside it, OUTSIDE the
 * <label>: inside, asking what the symbol means would also select that night.
 * It opens a small popover with the explanation, and a link into the full
 * tier modal. The symbol keeps its meaning for screen readers through
 * visually-hidden text, since an icon alone says nothing.
 *
 * PERK NIGHTS — added 1.11.0.
 * A performance can carry a tier perk (the Nova Circle pre-concert talks).
 * The label lives in the EVENT's own ans_perk meta, with ans_perk_tier naming
 * the tier it belongs to, so a rescheduled concert carries its talk with it and
 * Kim can edit it in the Custom Fields panel without a plugin release. Choosing
 * that tier selects those nights automatically and says so - a patron buying
 * Nova Circle for the talks should not have to work out which night each talk
 * falls on. Nothing is locked: any night can still be chosen.
 *
 * DETAIL MODAL — added 1.10.0.
 * Each tier card carries a "What's included" button that opens a modal
 * describing the tier. Four things about it are deliberate:
 *   0. The mark is set at 27px on the chip and 30px in the legend - 18px was
 *      too small to read as the Nova Circle mark rather than a smudge
 *      (Jonathan, 2026-08-20). Change it in ans_pkg_perk_icon()'s default.
 *   1. The panels are rendered SERVER-SIDE and hidden, not built from the JSON
 *      payload. The copy is therefore in the page for search engines and for
 *      anyone whose JS fails, and nothing is injected as unescaped HTML.
 *   2. The button is a real <button> inside the card, and the card's own click
 *      handler ignores clicks that came from it. Without that guard, asking
 *      "what is this?" would silently select the tier and scroll you to Step 2.
 *   3. The tier card is a flex column and the button is pushed to the bottom
 *      with margin-top:auto, over a blurb with a three-line min-height. Both
 *      are needed: the min-height keeps the buttons on one baseline even when
 *      one blurb wraps to a third line, which is what put the Nova Circle
 *      button lower than the other two on 2026-08-18.
 *   4. The copy lives on ans_pkg_tiers() beside the price it describes, and
 *      that array is filtered — so a future release can source the dated perks
 *      from the events themselves rather than from this file.
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Parent product_cat term whose children are the packageable concerts. */
if ( ! defined( 'ANS_PKG_PARENT_TERM' ) ) {
    define( 'ANS_PKG_PARENT_TERM', 86 );
}

add_shortcode( 'ans_season_packages', 'ans_pkg_render' );

/**
 * Tier definitions. Prices are display-only — the cart discount rules are what
 * actually charge the patron. Keep these in step with the rules, and with the
 * ANS 26-27 Ticket Pricing sheet, which is the source of truth for both.
 *
 * Detail-modal keys (all optional; a tier without them gets no button):
 *   eyebrow   — small caps line above the modal title
 *   meta      — one line under it; limited inline HTML (<b>) allowed
 *   intro     — opening paragraph
 *   subhead   — heading above the benefit list
 *   benefits  — [ ['t' => title, 'when' => optional dates, 'd' => description] ]
 *   callout   — highlighted note; limited inline HTML (<b>) allowed
 *   foot      — small print in the modal footer
 */
function ans_pkg_tiers() {
    return apply_filters( 'ans_pkg_tiers', array(
        'flex3' => array(
            'key'    => 'flex3',
            'name'   => 'Flex Pass',
            'pick'   => 3,
            'price'  => 102,
            'per'    => 34,
            'save'   => '15% off',
            'blurb'  => 'Choose any three concerts. Mix and match to suit your schedule.',

            'eyebrow'  => 'Season package',
            'meta'     => '<b>$102</b> · three concerts · $34 each',
            'intro'    => 'For the way most people actually attend — a few concerts a season, chosen around real life. Pick the three that appeal and the nights that fit; the discount takes care of itself.',
            'subhead'  => 'What the Flex Pass gives you',
            'benefits' => array(
                array(
                    't' => 'Any three of the five mainstage concerts',
                    'd' => 'Mix and match however you like. No fixed combination, no house choice.',
                ),
                array(
                    't' => 'Your night, for each one',
                    'd' => 'Every performance date is on the table — Boulder, Denver or Longmont, whichever suits.',
                ),
                array(
                    't' => '15% off the single-ticket price',
                    'd' => '$34 a concert instead of $40, applied automatically in the cart. There is no code to remember.',
                ),
                array(
                    't' => 'Real tickets, one per performance',
                    'd' => 'You are buying ordinary tickets, not a voucher. Each one arrives by email and scans at the door.',
                ),
            ),
            'foot'     => 'General admission seating.',
        ),
        'season5' => array(
            'key'    => 'season5',
            'name'   => 'Season Package',
            'pick'   => 5,
            'price'  => 160,
            'per'    => 32,
            'save'   => '20% off',
            'blurb'  => 'All five mainstage concerts, with your choice of night for each.',

            'eyebrow'  => 'Season package',
            'meta'     => '<b>$160</b> · five concerts · $32 each',
            'intro'    => 'The whole season, at the lowest price per concert we offer on general admission. You still choose the night for every concert — the package fixes what you see, not when you see it.',
            'subhead'  => 'What the Season Package gives you',
            'benefits' => array(
                array(
                    't' => 'All five mainstage concerts',
                    'd' => 'The full arc of Confluence, September through May.',
                ),
                array(
                    't' => 'Your night, for each one',
                    'd' => 'Choose the date and the city for every concert independently.',
                ),
                array(
                    't' => '20% off the single-ticket price',
                    'd' => '$32 a concert instead of $40 — our best general-admission rate.',
                ),
                array(
                    't' => 'Real tickets, one per performance',
                    'd' => 'Five ordinary tickets, emailed straight away, each one scannable at the door.',
                ),
            ),
            'foot'     => 'General admission seating.',
        ),
        'circle' => array(
            'key'    => 'circle',
            'name'   => 'Nova Circle',
            'pick'   => 5,
            'price'  => 300,
            'per'    => 0,
            'save'   => 'Premium tier',
            'blurb'  => 'All five concerts, premium reserved seating, and exclusive guest-artist events.',
            'extra_sku' => 'ANS-PKG-CIRCLE-FEE',
            'mark'      => true,

            'eyebrow'  => 'A special circle of support',
            'meta'     => '<b>$300</b> · all five concerts · membership included',
            'intro'    => 'The Nova Circle is your invitation to step behind the curtain and get closer to the action. As a Nova Circle member, you won’t just attend our concerts — you’ll experience the artistry, camaraderie, and preparation that bring each performance to life, from pre-concert talks with Artistic Director Tom Morgan to an intimate rehearsal and brunch with our March Residency guest artist.',
            'subhead'  => 'Your Nova Circle benefits',
            'benefits' => array(
                array(
                    't' => 'Premium Reserved Seating',
                    'd' => 'Enjoy the best seats in the house at every Nova Circle concert, reserved exclusively for you.',
                ),
                array(
                    't'    => 'Exclusive Pre-Concert Talks',
                    'when' => 'October 9 · February 7 · May 21',
                    'd'    => 'Get an insider’s introduction before the music begins. Join Artistic Director Tom Morgan and the evening’s guest artist for an exclusive pre-concert talk.',
                ),
                array(
                    't'    => 'Rehearsal with Blake Morgan',
                    'when' => 'March 11',
                    'd'    => 'You’re invited to sit in on rehearsal the evening before Brunch with Blake, and watch him work with the singers of Ars Nova.',
                ),
                array(
                    't'    => 'Brunch with Blake',
                    'when' => 'March 12',
                    'd'    => 'Join our March Residency guest artist, Blake Morgan of the world-renowned British vocal ensemble VOCES8, for an intimate brunch — an exclusive opportunity to connect over conversation and community.',
                ),
                array(
                    't'    => 'Complimentary Drink Voucher',
                    'when' => 'April concerts',
                    'd'    => 'Raise a glass on us at either April concert location, The Dairy or the Savoy.',
                ),
                array(
                    't'    => '“An Insider’s View of the Choir”',
                    'when' => 'September 24 · January 28 · May 6',
                    'd'    => 'Step inside the preparation process at select rehearsals throughout the season. Attend one, two, or all three — the choice is yours.',
                ),
            ),
            'perk_explainer' => 'A Nova Circle pre-concert talk happens an hour before this performance: Artistic Director Tom Morgan and the evening\'s guest artist, on what you are about to hear. It is included with the Nova Circle package and only happens on the nights marked here - the other dates are the same concert without the talk.',
            'callout'  => '<b>Choosing your nights:</b> the pre-concert talks happen on <b>October 9</b> (Rivers &amp; Streams), <b>February 7</b> (Sound &amp; Motion) and <b>May 21</b> (the season finale). Pick those nights in Step 2 and you get the talk and the concert together.',
            'foot'     => 'Membership is included when you buy the Nova Circle package. Nothing separate to purchase.',
        ),
    ) );
}

/**
 * The packageable concerts, each with its performances.
 *
 * Reads the product_cat children of ANS_PKG_PARENT_TERM — so adding a
 * performance means categorising one product, not editing this file.
 *
 * @return array [ ['term_id','name','performances'=>[['id','date_ts','when','where','price']]] ]
 */
function ans_pkg_concerts() {
    if ( ! taxonomy_exists( 'product_cat' ) ) {
        return array();
    }
    $terms = get_terms( array(
        'taxonomy'   => 'product_cat',
        'parent'     => (int) ANS_PKG_PARENT_TERM,
        'hide_empty' => false,
    ) );
    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return array();
    }

    $out = array();
    foreach ( $terms as $t ) {
        $product_ids = get_posts( array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'fields'         => 'ids',
            'tax_query'      => array( array(
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => (int) $t->term_id,
            ) ),
        ) );

        $perfs = array();
        foreach ( $product_ids as $pid ) {
            $event_id  = (int) get_post_meta( $pid, '_event_name', true );
            $ts        = 0;
            $where     = '';
            $perk      = '';
            $perk_tier = '';
            if ( $event_id ) {
                // MUST go through ans_tb_local_ts(): the stored value is a naive
                // site-local wall clock and WordPress runs PHP in UTC, so a bare
                // strtotime() here printed every performance 6-7 hours early on
                // this page. See the helper's docblock in the main plugin file.
                $ts    = ans_tb_event_ts( $event_id );
                $where = (string) get_post_meta( $event_id, 'event_location', true );
                // Perk label lives on the EVENT, not here, so a rescheduled
                // concert carries its pre-concert talk with it and Kim can edit
                // it without a plugin release.
                $perk      = (string) get_post_meta( $event_id, 'ans_perk', true );
                $perk_tier = (string) get_post_meta( $event_id, 'ans_perk_tier', true );
            }
            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
            if ( ! $product || ! $product->is_purchasable() ) {
                continue;
            }
            $perfs[] = array(
                'id'      => $pid,
                'date_ts' => $ts,
                'when'    => $ts ? wp_date( 'D, M j · g:i a', $ts ) : get_the_title( $pid ),
                'where'   => function_exists( 'ans_sp_place' ) ? ans_sp_place( $where ) : $where,
                'price'   => (float) $product->get_regular_price(),
                'perk'      => $perk,
                'perk_tier' => $perk_tier ? $perk_tier : 'circle',
            );
        }
        if ( empty( $perfs ) ) {
            continue;
        }
        usort( $perfs, function ( $a, $b ) {
            return $a['date_ts'] <=> $b['date_ts'];
        } );

        $out[] = array(
            'term_id'      => (int) $t->term_id,
            'name'         => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
            'first_ts'     => $perfs[0]['date_ts'],
            'performances' => $perfs,
        );
    }

    usort( $out, function ( $a, $b ) {
        return $a['first_ts'] <=> $b['first_ts'];
    } );
    return $out;
}

/**
 * The Nova Circle mark, inline.
 *
 * The N is the Ars Nova logo's own polygon; the O is one compound path (outer
 * circle, inner ellipse, evenodd) which is why the sides are heavier than the
 * top - that is the serif stress, not an accident. The N is drawn in
 * currentColor so it flips to white on a selected navy chip; a fixed navy N
 * disappeared there. Full asset set lives in the Brand archive on the shared
 * drive: Ars Nova Shared Resource Archive/Brand/nova-circle-icon-set/.
 *
 * @param string $class Extra class.
 * @param int    $size  Pixel size.
 * @return string
 */
function ans_pkg_perk_icon( $class = '', $size = 27 ) {
    return '<svg class="ans-pkg__perkicon ' . esc_attr( $class ) . '" viewBox="0 0 100 100" width="' . (int) $size . '" height="' . (int) $size . '" aria-hidden="true" focusable="false">'
        . '<path d="M92.5,50 A42.5,42.5 0 1,0 7.5,50 A42.5,42.5 0 1,0 92.5,50 Z M83.5,50 A33.5,40.5 0 1,1 16.5,50 A33.5,40.5 0 1,1 83.5,50 Z" fill="#c7a24a" fill-rule="evenodd"/>'
        . '<g transform="translate(23.001,26.031) scale(0.45522)">'
        . '<polygon points="20.08 12.53 16.87 12.53 16.87 12.53 16.82 12.53 16.87 12.81 16.87 94.85 7.76 101.72 7.76 104.47 28.69 104.47 28.7 101.72 19.58 94.85 19.58 38.17 21.9 38.17 21.91 38.22 78.08 94.39 78.08 94.39 68.96 101.26 68.96 104.47 108.81 104.47 108.81 101.26 20.08 12.53" fill="currentColor"/>'
        . '</g></svg>';
}

/**
 * Does this tier have enough detail copy to be worth a modal?
 *
 * @param array $t Tier.
 * @return bool
 */
function ans_pkg_has_detail( $t ) {
    return ! empty( $t['benefits'] ) && is_array( $t['benefits'] );
}

/**
 * Header artwork for a tier's modal.
 *
 * Taken from the tier's own membership product image when it has one, so the
 * art is changed in WooCommerce rather than in this file. Tiers without a
 * membership product simply get the plain navy band.
 *
 * @param array $t Tier (after extra_id resolution).
 * @return string Image URL, or '' for none.
 */
function ans_pkg_detail_art( $t ) {
    if ( empty( $t['extra_id'] ) ) {
        return '';
    }
    $thumb = get_post_thumbnail_id( (int) $t['extra_id'] );
    if ( ! $thumb ) {
        return '';
    }
    $src = wp_get_attachment_image_url( $thumb, 'large' );
    return $src ? $src : '';
}

/*
 * Note on .ans-pkg__perkicon: the negative vertical margin is load-bearing.
 * At 27px the mark is taller than the chip text, and without it the perk
 * chips stand 11px taller than their neighbours, so a row of nights comes
 * out ragged. The margin lets the mark overflow the line box instead.
 */
function ans_pkg_styles() {
    static $done = false;
    if ( $done ) {
        return '';
    }
    $done = true;
    return '<style id="ans-season-packages">
.ans-pkg{--ans-navy:#0e1b3a;--ans-gold:#c7a24a;--ans-gold-deep:#8a6d24;--ans-cream:#f5f1e8;max-width:1080px;margin:0 auto}
.ans-pkg__tiers{display:flex;align-items:stretch;gap:20px;flex-wrap:wrap;margin:0 0 40px}
.ans-pkg__tier{flex:1 1 260px;display:flex;flex-direction:column;border:2px solid rgba(14,27,58,.16);border-radius:14px;padding:26px 24px;cursor:pointer;background:#fff;transition:border-color .15s,box-shadow .15s}
.ans-pkg__tier:hover{border-color:var(--ans-gold)}
.ans-pkg__tier.is-on{border-color:var(--ans-navy);box-shadow:0 6px 26px rgba(14,27,58,.13)}
.ans-pkg__tier h3{display:flex;align-items:center;gap:10px;font-size:23px;margin:0 0 6px;color:var(--ans-navy)}
.ans-pkg__tiermark{flex:0 0 auto;margin:0}
.ans-pkg__price{font-size:34px;font-weight:700;color:var(--ans-navy);margin:0 0 2px}
.ans-pkg__per{font-size:13px;letter-spacing:1.5px;text-transform:uppercase;color:#8a6d24;margin:0 0 12px}
.ans-pkg__blurb{font-size:15px;line-height:1.6;color:#3a4560;margin:0;min-height:4.8em}
.ans-pkg__more{display:inline-flex;align-items:center;gap:6px;margin:14px 0 0;margin-top:auto;align-self:flex-start;padding:0;border:0;background:none;font:inherit;font-size:13.5px;font-weight:600;letter-spacing:.4px;color:#8a6d24;cursor:pointer;border-bottom:1px solid rgba(199,162,74,.55);line-height:1.9}
.ans-pkg__more:hover,.ans-pkg__more:focus-visible{color:var(--ans-navy);border-bottom-color:var(--ans-navy)}
.ans-pkg__more svg{width:13px;height:13px;flex:0 0 auto}
.ans-pkg__more:focus-visible,.ans-pkg__tier:focus-visible{outline:3px solid var(--ans-gold);outline-offset:3px}
.ans-pkg__step{font-size:13px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;color:#8a6d24;margin:0 0 14px}
.ans-pkg__concerts{display:flex;flex-direction:column;gap:14px;margin:0 0 28px}
.ans-pkg__concert{border:2px solid rgba(14,27,58,.14);border-radius:12px;padding:18px 20px;background:#fff}
.ans-pkg__concert.is-on{border-color:var(--ans-navy);background:rgba(14,27,58,.035)}
.ans-pkg__concert.is-off{opacity:.55}
.ans-pkg__ctitle{display:flex;align-items:center;gap:12px;font-size:20px;font-weight:700;color:var(--ans-navy);margin:0}
.ans-pkg__ctitle input{width:20px;height:20px;accent-color:#0e1b3a;flex:0 0 auto}
.ans-pkg__nights{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 0 32px}
.ans-pkg__night{display:inline-flex;align-items:center;gap:7px;border:1px solid rgba(14,27,58,.22);border-radius:30px;padding:7px 15px;font-size:14px;cursor:pointer;color:#25304a}
.ans-pkg__night:hover{border-color:var(--ans-gold)}
.ans-pkg__night input{accent-color:#0e1b3a}
.ans-pkg__night.is-on{background:var(--ans-navy);border-color:var(--ans-navy);color:#fff}
.ans-pkg__where{opacity:.72;font-size:13px}
.ans-pkg__nightwrap{display:inline-flex;align-items:center;gap:5px}
.ans-pkg__night:has(.ans-pkg__perk){padding-right:10px}
.ans-pkg__perk{display:inline-flex;align-items:center;line-height:0}
.ans-pkg__perk .ans-pkg__perkicon{display:block;flex:0 0 auto;margin:-7px 0}
.ans-pkg__perkicon{display:block;flex:0 0 auto}
.ans-pkg__why{width:22px;height:22px;flex:0 0 auto;border-radius:50%;border:1px solid rgba(14,27,58,.28);background:#fff;color:#25304a;font:700 12px/1 inherit;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;padding:0}
.ans-pkg__why:hover{border-color:var(--ans-gold);color:var(--ans-gold-deep)}
.ans-pkg__why[aria-expanded="true"]{background:var(--ans-navy);border-color:var(--ans-navy);color:#fff}
.ans-pkg__why:focus-visible{outline:3px solid var(--ans-gold);outline-offset:2px}
.ans-pkgw[hidden]{display:none}
.ans-pkgw{position:absolute;z-index:9998;width:min(330px,calc(100vw - 32px));background:#fff;border:1px solid rgba(14,27,58,.16);border-radius:12px;box-shadow:0 18px 44px rgba(9,16,34,.22);padding:16px 18px 14px}
.ans-pkgw__x{position:absolute;top:8px;right:8px;width:24px;height:24px;border:0;background:none;color:#6b7590;font-size:13px;line-height:1;cursor:pointer;border-radius:50%}
.ans-pkgw__x:hover{background:rgba(14,27,58,.07);color:var(--ans-navy)}
.ans-pkgw__title{margin:0 22px 6px 0;font-size:13px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:var(--ans-gold-deep)}
.ans-pkgw__body{margin:0;font-size:14px;line-height:1.55;color:#3a4560}
.ans-pkgw__more{margin:12px 0 0;padding:0;border:0;background:none;font:600 14px inherit;color:var(--ans-navy);text-decoration:underline;cursor:pointer}
.ans-pkgw__more:hover{color:var(--ans-gold-deep)}
.ans-pkgw__more[hidden]{display:none}
.ans-pkg__perknote{display:flex;align-items:flex-start;gap:10px;font-size:14px;line-height:1.55;color:#3a4560;background:var(--ans-cream);border-left:3px solid var(--ans-gold);border-radius:0 10px 10px 0;padding:12px 16px;margin:0 0 18px}
.ans-pkg__perkicon--note{margin-top:1px;color:var(--ans-navy)}
.ans-pkg__perknote[hidden]{display:none}
.ans-pkg__perknote b{color:var(--ans-navy)}
.ans-pkg__bar{position:sticky;bottom:0;background:var(--ans-cream);border-top:2px solid rgba(14,27,58,.14);padding:18px 22px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;border-radius:12px 12px 0 0}
.ans-pkg__count{font-size:16px;color:#25304a;flex:1 1 auto;margin:0}
.ans-pkg__count strong{color:var(--ans-navy)}
.ans-pkg__seats{display:flex;align-items:center;gap:9px;font-size:14px;color:#25304a}
.ans-pkg__seats select{padding:7px 10px;border-radius:8px;border:1px solid rgba(14,27,58,.28)}
.ans-pkg__go{background:var(--ans-navy);color:#fff;border:0;font-size:14px;font-weight:700;letter-spacing:1px;text-transform:uppercase;padding:14px 34px;border-radius:40px;cursor:pointer}
.ans-pkg__go:hover:not(:disabled){background:var(--ans-gold);color:var(--ans-navy)}
.ans-pkg__go:disabled{opacity:.42;cursor:not-allowed}
.ans-pkg__note{font-size:14px;color:#3a4560;margin:18px 0 0;font-style:italic}
.ans-pkg__err{color:#7a1f2b;font-size:14px;margin:10px 0 0}
.ans-pkg__empty{font-size:17px;color:#3a4560;font-style:italic}
@media (max-width:781px){.ans-pkg__nights{margin-left:0}.ans-pkg__bar{flex-direction:column;align-items:stretch}.ans-pkg__go{width:100%}}

/* ---- detail modal (1.10.0) ---- */
.ans-pkgm[hidden]{display:none}
.ans-pkgm{--ans-navy:#0e1b3a;--ans-gold:#c7a24a;--ans-gold-deep:#8a6d24;--ans-cream:#f5f1e8;position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:28px 20px}
.ans-pkgm__scrim{position:absolute;inset:0;background:rgba(9,16,34,.62);animation:ansPkgmFade .18s ease-out}
.ans-pkgm__box{position:relative;width:min(680px,100%);max-height:min(88vh,860px);background:#fff;border-radius:16px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 30px 80px rgba(9,16,34,.42);animation:ansPkgmRise .22s cubic-bezier(.2,.8,.3,1)}
@keyframes ansPkgmFade{from{opacity:0}to{opacity:1}}
@keyframes ansPkgmRise{from{opacity:0;transform:translateY(16px) scale(.985)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.ans-pkgm__scrim,.ans-pkgm__box{animation:none}}
.ans-pkgm__panel{display:flex;flex-direction:column;min-height:0;max-height:inherit}
.ans-pkgm__panel[hidden]{display:none}
.ans-pkgm__head{position:relative;background:var(--ans-navy);color:#fff;padding:30px 34px 26px;flex:0 0 auto}
.ans-pkgm__art{position:absolute;inset:0;background-size:cover;background-position:center;opacity:.30}
.ans-pkgm__head::after{content:"";position:absolute;inset:0;background:linear-gradient(105deg,rgba(14,27,58,.96) 30%,rgba(14,27,58,.55) 100%)}
.ans-pkgm__headinner{position:relative;z-index:2}
.ans-pkgm__eyebrow{font-size:11.5px;font-weight:700;letter-spacing:3px;text-transform:uppercase;color:var(--ans-gold);margin:0 0 8px}
.ans-pkgm__title{font-family:var(--ans-season-display,Cinzel),Georgia,serif;font-weight:600;font-size:32px;line-height:1.15;margin:0 0 10px;color:#fff}
.ans-pkgm__meta{margin:0;font-size:14.5px;color:rgba(255,255,255,.82)}
.ans-pkgm__meta b{color:#fff;font-weight:600}
.ans-pkgm__x{position:absolute;top:14px;right:14px;z-index:3;width:38px;height:38px;border-radius:50%;border:1px solid rgba(255,255,255,.32);background:rgba(255,255,255,.08);color:#fff;font-size:19px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center}
.ans-pkgm__x:hover{background:var(--ans-gold);border-color:var(--ans-gold);color:var(--ans-navy)}
.ans-pkgm__x:focus-visible{outline:3px solid var(--ans-gold);outline-offset:2px}
.ans-pkgm__body{padding:28px 34px 8px;overflow-y:auto;flex:1 1 auto}
.ans-pkgm__intro{font-size:15.5px;color:#3a4560;margin:0 0 24px}
.ans-pkgm__subhead{font-size:12px;font-weight:700;letter-spacing:2.4px;text-transform:uppercase;color:var(--ans-gold-deep);margin:0 0 16px}
.ans-pkgm__list{list-style:none;margin:0 0 24px;padding:0}
.ans-pkgm__list li{display:flex;gap:14px;padding:0 0 18px}
.ans-pkgm__list li:last-child{padding-bottom:0}
.ans-pkgm__ico{flex:0 0 auto;width:26px;height:26px;margin-top:2px;border-radius:50%;border:1.5px solid var(--ans-gold);display:flex;align-items:center;justify-content:center}
.ans-pkgm__ico svg{width:12px;height:12px;stroke:var(--ans-gold-deep);fill:none;stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round}
.ans-pkgm__bt{display:block;font-weight:700;color:var(--ans-navy);font-size:15.5px;line-height:1.4}
.ans-pkgm__bw{display:block;font-size:12px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--ans-gold-deep);margin:3px 0 4px}
.ans-pkgm__bd{display:block;font-size:14.5px;color:#3a4560;line-height:1.55}
.ans-pkgm__callout{border-left:3px solid var(--ans-gold);background:var(--ans-cream);border-radius:0 10px 10px 0;padding:16px 20px;margin:0 0 26px}
.ans-pkgm__callout p{margin:0;font-size:14.5px;color:#3a4560}
.ans-pkgm__callout b{color:var(--ans-navy)}
.ans-pkgm__foot{flex:0 0 auto;border-top:1px solid rgba(14,27,58,.12);background:var(--ans-cream);padding:18px 34px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.ans-pkgm__footnote{margin:0;font-size:13.5px;color:#3a4560;flex:1 1 200px}
.ans-pkgm__go{background:var(--ans-navy);color:#fff;border:0;font:inherit;font-size:14px;font-weight:700;letter-spacing:1px;text-transform:uppercase;padding:14px 30px;border-radius:40px;cursor:pointer}
.ans-pkgm__go:hover{background:var(--ans-gold);color:var(--ans-navy)}
.ans-pkgm__go:focus-visible,.ans-pkgm__back:focus-visible{outline:3px solid var(--ans-gold);outline-offset:3px}
.ans-pkgm__back{background:none;border:0;font:inherit;font-size:14px;font-weight:600;color:var(--ans-navy);text-decoration:underline;padding:14px 6px;cursor:pointer}
.ans-pkgm__back:hover{color:var(--ans-gold-deep)}
body.ans-pkgm-open{overflow:hidden}
@media (max-width:640px){
.ans-pkgm{padding:0}
.ans-pkgm__box{width:100%;max-height:100%;height:100%;border-radius:0}
.ans-pkgm__head{padding:26px 22px 22px}
.ans-pkgm__title{font-size:26px}
.ans-pkgm__body{padding:24px 22px 8px}
.ans-pkgm__foot{padding:16px 22px;flex-direction:column;align-items:stretch}
.ans-pkgm__go,.ans-pkgm__back{width:100%}
}
</style>';
}

/** [ans_season_packages] */
function ans_pkg_render( $atts ) {
    $a = shortcode_atts( array(
        'cart_url'   => '',
        'empty_text' => 'Season packages will be available here shortly.',
    ), $atts, 'ans_season_packages' );

    $concerts = ans_pkg_concerts();
    if ( empty( $concerts ) ) {
        return '<div class="ans-pkg"><p class="ans-pkg__empty">' . esc_html( $a['empty_text'] ) . '</p></div>';
    }

    $tiers = ans_pkg_tiers();

    // Nova Circle needs its membership product to exist; hide the tier otherwise.
    foreach ( $tiers as $k => $t ) {
        if ( empty( $t['extra_sku'] ) ) {
            continue;
        }
        $extra_id = function_exists( 'ans_tb_product_id_by_sku' ) ? ans_tb_product_id_by_sku( $t['extra_sku'] ) : 0;
        if ( ! $extra_id ) {
            unset( $tiers[ $k ] );
            continue;
        }
        $tiers[ $k ]['extra_id'] = (int) $extra_id;
    }

    $cart_url = $a['cart_url'] ? $a['cart_url'] : ( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '/cart/' );

    // The modal copy is rendered into the page, not shipped in the payload.
    $payload_tiers = array();
    foreach ( $tiers as $t ) {
        unset( $t['intro'], $t['subhead'], $t['benefits'], $t['callout'], $t['foot'], $t['meta'], $t['eyebrow'], $t['perk_explainer'], $t['mark'] );
        $payload_tiers[] = $t;
    }

    $perk_copy = array();
    foreach ( $tiers as $t ) {
        if ( ! empty( $t['perk_explainer'] ) ) {
            $perk_copy[ $t['key'] ] = array(
                'name'      => $t['name'],
                'explainer' => $t['perk_explainer'],
                'hasDetail' => ans_pkg_has_detail( $t ),
            );
        }
    }

    $payload = array(
        'tiers'    => $payload_tiers,
        'perkCopy' => $perk_copy,
        'concerts' => $concerts,
        'cartUrl'  => $cart_url,
        'restUrl'  => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
    );

    $allowed_inline = array( 'b' => array(), 'strong' => array(), 'em' => array(), 'i' => array() );

    ob_start();
    echo ans_pkg_styles(); // phpcs:ignore WordPress.Security.EscapeOutput
    ?>
<div class="ans-pkg" id="ans-pkg-app">
    <p class="ans-pkg__step">Step 1 — choose your package</p>
    <div class="ans-pkg__tiers">
        <?php foreach ( $tiers as $t ) : ?>
        <div class="ans-pkg__tier" data-tier="<?php echo esc_attr( $t['key'] ); ?>" role="button" tabindex="0">
            <h3>
                <?php if ( ! empty( $t['mark'] ) ) : ?>
                <?php echo ans_pkg_perk_icon( 'ans-pkg__tiermark', 30 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php endif; ?>
                <span><?php echo esc_html( $t['name'] ); ?></span>
            </h3>
            <p class="ans-pkg__price">$<?php echo esc_html( number_format_i18n( $t['price'] ) ); ?></p>
            <p class="ans-pkg__per">
                <?php echo esc_html( $t['per'] ? '$' . $t['per'] . ' per concert · ' . $t['save'] : $t['save'] ); ?>
            </p>
            <p class="ans-pkg__blurb"><?php echo esc_html( $t['blurb'] ); ?></p>
            <?php if ( ans_pkg_has_detail( $t ) ) : ?>
            <button type="button" class="ans-pkg__more" data-more="<?php echo esc_attr( $t['key'] ); ?>" aria-haspopup="dialog">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.6v.1"/></svg>
                What&rsquo;s included<span class="screen-reader-text"> in the <?php echo esc_html( $t['name'] ); ?></span>
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div id="ans-pkg-picker" hidden>
        <p class="ans-pkg__step" id="ans-pkg-steptext">Step 2 — choose your concerts and nights</p>
        <p class="ans-pkg__perknote" id="ans-pkg-perknote" hidden><?php echo ans_pkg_perk_icon( 'ans-pkg__perkicon--note', 30 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span id="ans-pkg-perknote-text"></span></p>
        <div class="ans-pkg__concerts">
            <?php foreach ( $concerts as $c ) : ?>
            <div class="ans-pkg__concert" data-term="<?php echo esc_attr( $c['term_id'] ); ?>">
                <label class="ans-pkg__ctitle">
                    <input type="checkbox" class="ans-pkg__pick" value="<?php echo esc_attr( $c['term_id'] ); ?>">
                    <span><?php echo esc_html( $c['name'] ); ?></span>
                </label>
                <div class="ans-pkg__nights">
                    <?php foreach ( $c['performances'] as $i => $p ) : ?>
                    <span class="ans-pkg__nightwrap">
                    <label class="ans-pkg__night<?php echo 0 === $i ? ' is-on' : ''; ?>">
                        <input type="radio"
                               name="night-<?php echo esc_attr( $c['term_id'] ); ?>"
                               value="<?php echo esc_attr( $p['id'] ); ?>"
                               <?php checked( 0, $i ); ?>>
                        <span><?php echo esc_html( $p['when'] ); ?></span>
                        <?php if ( $p['where'] ) : ?>
                        <span class="ans-pkg__where"><?php echo esc_html( $p['where'] ); ?></span>
                        <?php endif; ?>
                        <?php if ( ! empty( $p['perk'] ) ) : ?>
                        <span class="ans-pkg__perk" data-perk-tier="<?php echo esc_attr( $p['perk_tier'] ); ?>">
                            <?php echo ans_pkg_perk_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <span class="screen-reader-text"><?php echo esc_html( $p['perk'] ); ?></span>
                        </span>
                        <?php endif; ?>
                    </label>
                    <?php if ( ! empty( $p['perk'] ) ) : ?>
                    <button type="button" class="ans-pkg__why"
                            data-why="<?php echo esc_attr( $p['perk_tier'] ); ?>"
                            data-why-label="<?php echo esc_attr( $p['perk'] ); ?>"
                            aria-haspopup="dialog" aria-expanded="false"
                            aria-label="What does this mark mean?">?</button>
                    <?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="ans-pkg__bar">
            <p class="ans-pkg__count" id="ans-pkg-count"></p>
            <label class="ans-pkg__seats">Seats
                <select id="ans-pkg-seats">
                    <?php for ( $s = 1; $s <= 8; $s++ ) : ?>
                    <option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <button class="ans-pkg__go" id="ans-pkg-go" disabled>Add to cart</button>
        </div>
        <p class="ans-pkg__err" id="ans-pkg-err" hidden></p>
        <p class="ans-pkg__note">Your package discount is applied automatically in the cart. Each concert is a real ticket — you'll receive one per performance.</p>
    </div>
</div>

<div class="ans-pkgw" id="ans-pkg-why" hidden role="dialog" aria-modal="false" aria-labelledby="ans-pkg-why-title">
    <button type="button" class="ans-pkgw__x" data-why-close aria-label="Close">&#10005;</button>
    <p class="ans-pkgw__title" id="ans-pkg-why-title"></p>
    <p class="ans-pkgw__body" id="ans-pkg-why-body"></p>
    <button type="button" class="ans-pkgw__more" id="ans-pkg-why-more" hidden></button>
</div>

<div class="ans-pkgm" id="ans-pkg-modal" hidden>
    <div class="ans-pkgm__scrim" data-pkgm-close></div>
    <div class="ans-pkgm__box" role="dialog" aria-modal="true" aria-labelledby="ans-pkgm-title">
        <?php foreach ( $tiers as $t ) : ?>
            <?php if ( ! ans_pkg_has_detail( $t ) ) { continue; } ?>
            <?php $art = ans_pkg_detail_art( $t ); ?>
        <div class="ans-pkgm__panel" data-panel="<?php echo esc_attr( $t['key'] ); ?>" hidden>
            <div class="ans-pkgm__head">
                <?php if ( $art ) : ?>
                <div class="ans-pkgm__art" style="background-image:url('<?php echo esc_url( $art ); ?>')"></div>
                <?php endif; ?>
                <button type="button" class="ans-pkgm__x" data-pkgm-close aria-label="Close">&#10005;</button>
                <div class="ans-pkgm__headinner">
                    <?php if ( ! empty( $t['eyebrow'] ) ) : ?>
                    <p class="ans-pkgm__eyebrow"><?php echo esc_html( $t['eyebrow'] ); ?></p>
                    <?php endif; ?>
                    <h2 class="ans-pkgm__title" id="ans-pkgm-title-<?php echo esc_attr( $t['key'] ); ?>"><?php echo esc_html( $t['name'] ); ?></h2>
                    <?php if ( ! empty( $t['meta'] ) ) : ?>
                    <p class="ans-pkgm__meta"><?php echo wp_kses( $t['meta'], $allowed_inline ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ans-pkgm__body">
                <?php if ( ! empty( $t['intro'] ) ) : ?>
                <p class="ans-pkgm__intro"><?php echo esc_html( $t['intro'] ); ?></p>
                <?php endif; ?>
                <?php if ( ! empty( $t['subhead'] ) ) : ?>
                <p class="ans-pkgm__subhead"><?php echo esc_html( $t['subhead'] ); ?></p>
                <?php endif; ?>
                <ul class="ans-pkgm__list">
                    <?php foreach ( $t['benefits'] as $b ) : ?>
                    <li>
                        <span class="ans-pkgm__ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 12.5l5 5L20 6.5"/></svg></span>
                        <span>
                            <span class="ans-pkgm__bt"><?php echo esc_html( $b['t'] ); ?></span>
                            <?php if ( ! empty( $b['when'] ) ) : ?>
                            <span class="ans-pkgm__bw"><?php echo esc_html( $b['when'] ); ?></span>
                            <?php endif; ?>
                            <span class="ans-pkgm__bd"><?php echo esc_html( $b['d'] ); ?></span>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ( ! empty( $t['callout'] ) ) : ?>
                <div class="ans-pkgm__callout"><p><?php echo wp_kses( $t['callout'], $allowed_inline ); ?></p></div>
                <?php endif; ?>
            </div>
            <div class="ans-pkgm__foot">
                <?php if ( ! empty( $t['foot'] ) ) : ?>
                <p class="ans-pkgm__footnote"><?php echo esc_html( $t['foot'] ); ?></p>
                <?php endif; ?>
                <button type="button" class="ans-pkgm__back" data-pkgm-close>Keep looking</button>
                <button type="button" class="ans-pkgm__go" data-pkgm-choose="<?php echo esc_attr( $t['key'] ); ?>">Choose <?php echo esc_html( $t['name'] ); ?></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<script>
(function(){
    var D = <?php echo wp_json_encode( $payload ); ?>;
    var app = document.getElementById('ans-pkg-app');
    if (!app) return;
    var picker = document.getElementById('ans-pkg-picker');
    var countEl = document.getElementById('ans-pkg-count');
    var stepEl = document.getElementById('ans-pkg-steptext');
    var goBtn = document.getElementById('ans-pkg-go');
    var errEl = document.getElementById('ans-pkg-err');
    var seatsEl = document.getElementById('ans-pkg-seats');
    var perkNote = document.getElementById('ans-pkg-perknote');
    var perkNoteText = document.getElementById('ans-pkg-perknote-text');
    var why = document.getElementById('ans-pkg-why');
    var whyTitle = document.getElementById('ans-pkg-why-title');
    var whyBody = document.getElementById('ans-pkg-why-body');
    var whyMore = document.getElementById('ans-pkg-why-more');
    var whyBtn = null;
    var tier = null;

    function tierByKey(k){ return D.tiers.filter(function(t){ return t.key === k; })[0]; }
    function picks(){ return Array.prototype.slice.call(app.querySelectorAll('.ans-pkg__pick:checked')); }

    function paintNights(){
        Array.prototype.forEach.call(app.querySelectorAll('.ans-pkg__night'), function(l){
            var r = l.querySelector('input');
            l.classList.toggle('is-on', !!(r && r.checked));
        });
    }

    function sync(){
        if (!tier) return;
        var n = picks().length;
        var need = tier.pick;
        var full = n >= need;

        Array.prototype.forEach.call(app.querySelectorAll('.ans-pkg__concert'), function(c){
            var box = c.querySelector('.ans-pkg__pick');
            var on = box.checked;
            c.classList.toggle('is-on', on);
            // When the tier is satisfied, grey out (but never disable) the rest.
            c.classList.toggle('is-off', full && !on);
        });

        countEl.innerHTML = '<strong>' + n + ' of ' + need + '</strong> concerts chosen' +
            (n === need ? ' — ' + tier.name + ', $' + tier.price : '');
        goBtn.disabled = (n !== need);
        paintNights();
    }

    function chooseTier(el){
        if (!el) return;
        tier = tierByKey(el.getAttribute('data-tier'));
        if (!tier) return;
        Array.prototype.forEach.call(app.querySelectorAll('.ans-pkg__tier'), function(o){
            o.classList.toggle('is-on', o === el);
        });
        picker.hidden = false;
        var all = tier.pick >= D.concerts.length;
        Array.prototype.forEach.call(app.querySelectorAll('.ans-pkg__pick'), function(b){
            b.checked = all;
        });

        // Nights carrying a perk for THIS tier get selected for the patron.
        // Someone buying Nova Circle for the pre-concert talks should not have
        // to work out which night each talk is on - but nothing is locked, so
        // they can still choose any other night.
        var perks = 0;
        Array.prototype.forEach.call(app.querySelectorAll('.ans-pkg__concert'), function(c){
            var want = null;
            Array.prototype.forEach.call(c.querySelectorAll('.ans-pkg__night'), function(l){
                var badge = l.querySelector('.ans-pkg__perk');
                if (badge && badge.getAttribute('data-perk-tier') === tier.key) {
                    want = l.querySelector('input[type=radio]');
                }
            });
            if (want) { want.checked = true; perks++; }
        });
        if (perkNote) {
            if (perks && perkNoteText) {
                perkNoteText.innerHTML = '<b>' + perks + ' of your concerts carry a ' + tier.name +
                    ' extra</b> - marked with this symbol below. We have chosen those nights for you; change any of them if another date suits.';
                perkNote.hidden = false;
            } else {
                perkNote.hidden = true;
            }
        }
        stepEl.textContent = all
            ? 'Step 2 — choose a night for each concert'
            : 'Step 2 — choose any ' + tier.pick + ' concerts, and a night for each';
        sync();
        picker.scrollIntoView({behavior:'smooth', block:'start'});
    }

    Array.prototype.forEach.call(app.querySelectorAll('.ans-pkg__tier'), function(el){
        el.addEventListener('click', function(e){
            // A click on "What's included" asks a question; it must not answer it.
            if (e.target.closest('.ans-pkg__more')) return;
            chooseTier(el);
        });
        el.addEventListener('keydown', function(e){
            if (e.target.closest('.ans-pkg__more')) return;
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); chooseTier(el); }
        });
    });

    app.addEventListener('change', function(e){
        if (e.target.classList.contains('ans-pkg__pick')) sync();
        if (e.target.type === 'radio') paintNights();
    });

    /* ---------------------------- detail modal ---------------------------- */
    var modal = document.getElementById('ans-pkg-modal');
    var lastFocus = null;

    function openModal(key){
        if (!modal) return;
        var wanted = null;
        Array.prototype.forEach.call(modal.querySelectorAll('.ans-pkgm__panel'), function(p){
            var on = p.getAttribute('data-panel') === key;
            p.hidden = !on;
            if (on) wanted = p;
        });
        if (!wanted) return;
        modal.querySelector('.ans-pkgm__box').setAttribute('aria-labelledby', 'ans-pkgm-title-' + key);
        lastFocus = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('ans-pkgm-open');
        var x = wanted.querySelector('.ans-pkgm__x');
        if (x) x.focus();
    }

    function closeModal(){
        if (!modal || modal.hidden) return;
        modal.hidden = true;
        document.body.classList.remove('ans-pkgm-open');
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    app.addEventListener('click', function(e){
        var more = e.target.closest('[data-more]');
        if (!more) return;
        e.preventDefault();
        e.stopPropagation();
        openModal(more.getAttribute('data-more'));
    });

    if (modal) {
        modal.addEventListener('click', function(e){
            if (e.target.closest('[data-pkgm-close]')) { closeModal(); return; }
            var pick = e.target.closest('[data-pkgm-choose]');
            if (pick) {
                var key = pick.getAttribute('data-pkgm-choose');
                closeModal();
                chooseTier(app.querySelector('.ans-pkg__tier[data-tier="' + key + '"]'));
            }
        });

        document.addEventListener('keydown', function(e){
            if (modal.hidden) return;
            if (e.key === 'Escape' || e.key === 'Esc') { closeModal(); return; }
            if (e.key !== 'Tab') return;
            var panel = modal.querySelector('.ans-pkgm__panel:not([hidden])');
            if (!panel) return;
            var f = panel.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (!f.length) return;
            var first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });
    }

    /* ------------------------- the "?" explainer ------------------------- */
    function closeWhy(refocus){
        if (!why || why.hidden) return;
        why.hidden = true;
        if (whyBtn) {
            whyBtn.setAttribute('aria-expanded', 'false');
            if (refocus) whyBtn.focus();
        }
        whyBtn = null;
    }

    function openWhy(btn){
        if (!why) return;
        var key = btn.getAttribute('data-why');
        var copy = (D.perkCopy || {})[key];
        if (!copy) return;

        whyTitle.textContent = btn.getAttribute('data-why-label') || copy.name;
        whyBody.textContent = copy.explainer;
        if (copy.hasDetail) {
            whyMore.textContent = 'See everything in ' + copy.name;
            whyMore.setAttribute('data-tier', key);
            whyMore.hidden = false;
        } else {
            whyMore.hidden = true;
        }

        // Show first so it can be measured, then place it under the button,
        // nudged back inside the viewport if it would overhang the right edge.
        why.hidden = false;
        var r = btn.getBoundingClientRect();
        var w = why.offsetWidth;
        var left = window.pageXOffset + r.left + r.width / 2 - w / 2;
        var max = window.pageXOffset + document.documentElement.clientWidth - w - 12;
        left = Math.max(window.pageXOffset + 12, Math.min(left, max));
        why.style.left = left + 'px';
        why.style.top = (window.pageYOffset + r.bottom + 10) + 'px';

        if (whyBtn && whyBtn !== btn) whyBtn.setAttribute('aria-expanded', 'false');
        whyBtn = btn;
        btn.setAttribute('aria-expanded', 'true');
        why.querySelector('.ans-pkgw__x').focus();
    }

    app.addEventListener('click', function(e){
        var b = e.target.closest('.ans-pkg__why');
        if (!b) return;
        // The button sits OUTSIDE the <label> on purpose - inside it, asking the
        // question would also pick the night.
        e.preventDefault();
        if (whyBtn === b && !why.hidden) { closeWhy(true); return; }
        openWhy(b);
    });

    if (why) {
        why.addEventListener('click', function(e){
            if (e.target.closest('[data-why-close]')) { closeWhy(true); return; }
            var more = e.target.closest('.ans-pkgw__more');
            if (more) { var k = more.getAttribute('data-tier'); closeWhy(false); openModal(k); }
        });
        document.addEventListener('click', function(e){
            if (why.hidden) return;
            if (e.target.closest('#ans-pkg-why') || e.target.closest('.ans-pkg__why')) return;
            closeWhy(false);
        });
        document.addEventListener('keydown', function(e){
            if (!why.hidden && (e.key === 'Escape' || e.key === 'Esc')) closeWhy(true);
        });
        window.addEventListener('resize', function(){ closeWhy(false); });
    }

    goBtn.addEventListener('click', async function(){
        if (!tier) return;
        errEl.hidden = true;
        goBtn.disabled = true;
        var original = goBtn.textContent;
        goBtn.textContent = 'Adding…';
        try {
            var qty = parseInt(seatsEl.value, 10) || 1;
            var ids = picks().map(function(b){
                var wrap = b.closest('.ans-pkg__concert');
                var r = wrap.querySelector('input[type=radio]:checked');
                return parseInt(r.value, 10);
            });
            if (tier.extra_id) ids.push(parseInt(tier.extra_id, 10));

            var probe = await fetch(D.restUrl + 'cart', {credentials:'same-origin'});
            var nonce = probe.headers.get('Nonce') || probe.headers.get('X-WC-Store-API-Nonce');

            for (var i = 0; i < ids.length; i++) {
                var res = await fetch(D.restUrl + 'cart/add-item', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type':'application/json', 'Nonce': nonce},
                    body: JSON.stringify({id: ids[i], quantity: qty})
                });
                if (!res.ok) throw new Error('Could not add one of the tickets (product ' + ids[i] + ').');
            }
            window.location.href = D.cartUrl;
        } catch (err) {
            errEl.textContent = err.message + ' Please try again, or add the concerts individually from the concert pages.';
            errEl.hidden = false;
            goBtn.disabled = false;
            goBtn.textContent = original;
        }
    });
})();
</script>
    <?php
    return ob_get_clean();
}
