<?php
/**
 * Plugin Name: Ars Nova Ticketing Bridge
 * Description: Admin-only REST endpoints that let the Ars Nova WordPress MCP connector create & list Tickera events and Bridge ticket-type products by command. Writes the same post/meta the Tickera + WooCommerce Bridge admin UI writes. DEV automation helper.
 * Version: 1.14.0
 * Author: Ars Nova (Jonathan Raabe) + Claude
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ANS_TB_VERSION', '1.14.0' );
define( 'ANS_TB_NS', 'ars-nova/v1' );

/** Permission gate: admin only (connector authenticates as an admin app-password user). */
function ans_tb_perm() {
    return current_user_can( 'manage_options' );
}

/**
 * Parse a site-local date string into a Unix timestamp.
 *
 * READ THIS BEFORE REACHING FOR strtotime() ANYWHERE NEAR AN EVENT DATE.
 *
 * Tickera stores `event_date_time` as a naive wall-clock string, 'Y-m-d H:i',
 * meaning site-local time — "2026-10-09 19:30" is 7:30 pm in Denver. But
 * WordPress unconditionally calls date_default_timezone_set('UTC'), so a bare
 * strtotime() on that string reads it as 7:30 pm UTC. Feed the result to
 * wp_date(), which correctly renders in the site timezone, and the two
 * assumptions fight: the page prints 1:30 pm.
 *
 * That is not hypothetical. It shipped, and the season-packages page showed
 * every performance six or seven hours early for weeks — six in October and
 * April, seven in December and February, because the offset follows daylight
 * saving. The concert pages escaped only because they happened to use
 * date_i18n(), whose legacy timestamp handling cancels the same error out.
 * Two wrongs looking right is worse than one wrong looking wrong.
 *
 * So: every read of an event date goes through here, and every render goes
 * through wp_date(). A string that carries its own offset or timezone is
 * honoured as written — DateTimeImmutable ignores the fallback zone in that
 * case, which is the behaviour we want.
 *
 * @param string $raw Site-local date string, or one carrying its own offset.
 * @return int Unix timestamp, or 0 if empty or unparseable.
 */
function ans_tb_local_ts( $raw ) {
    $raw = trim( (string) $raw );
    if ( '' === $raw ) {
        return 0;
    }
    try {
        $dt = new DateTimeImmutable( $raw, wp_timezone() );
    } catch ( Exception $e ) {
        return 0;
    }
    return $dt->getTimestamp();
}

/**
 * Start timestamp of a tc_events performance, site-timezone correct.
 *
 * @param int $event_id tc_events post ID.
 * @return int Unix timestamp, or 0 when the event carries no date.
 */
function ans_tb_event_ts( $event_id ) {
    return ans_tb_local_ts( get_post_meta( (int) $event_id, 'event_date_time', true ) );
}

/**
 * Midnight this morning, in the site timezone, as a timestamp.
 *
 * strtotime('today') resolves against UTC for the reason above, so on a
 * Mountain evening it lands on tomorrow — which quietly dropped that same
 * evening's concert out of the season listings while it was still hours away.
 *
 * @return int
 */
function ans_tb_today_ts() {
    return ans_tb_local_ts( current_time( 'Y-m-d' ) . ' 00:00' );
}

/* -------------------------------------------------------------------------
 * v1.14.0 - what KIND of thing a tc_events post is.
 *
 * tc_events was only ever a performance. It is now also the season-long Nova
 * Circle membership and the Circle's own perk evenings, and [ans_season_projects]
 * had no way to tell them apart: it enumerated every event in its date window,
 * so "Nova Circle Membership 2026/27" fell into the uncategorised fallback and
 * rendered on the public /this-season/ page as a bare project card.
 *
 * The meta key is `ans_event_kind`, deliberately WITHOUT an underscore prefix -
 * exactly like ans_perk and ans_perk_tier. An underscore-prefixed key is hidden
 * from WordPress's Custom Fields panel, and hiding it would mean Kim needs a
 * plugin release to reclassify one event. She should not.
 * ----------------------------------------------------------------------- */

/**
 * The values `ans_event_kind` is allowed to take.
 *
 * @return string[]
 */
function ans_tb_event_kinds() {
    return array( 'concert', 'membership', 'perk', 'private' );
}

/**
 * The kind of a tc_events post, normalised.
 *
 * Unset or empty means `concert`. That is what makes this change safe to ship:
 * every event that already exists keeps behaving exactly as it does today, and
 * no data migration has to run before the code lands.
 *
 * An unrecognised value ALSO falls back to `concert`, and that direction is
 * chosen on purpose. A typo has to fail VISIBLE - a stray card on This Season
 * that somebody notices and reports - rather than fail HIDDEN, which is what
 * returning something like 'private' would do: a real concert would silently
 * vanish from the season listing and nobody would find out until a patron
 * could not find the night they wanted. The noisy failure is the recoverable
 * one. ans_tb_event_payload() returns the raw stored value alongside this
 * normalised one so a typo can still be audited rather than merely absorbed.
 *
 * @param int $event_id tc_events post ID.
 * @return string One of ans_tb_event_kinds().
 */
function ans_tb_event_kind( $event_id ) {
    $kind = strtolower( trim( (string) get_post_meta( (int) $event_id, 'ans_event_kind', true ) ) );
    if ( '' === $kind || ! in_array( $kind, ans_tb_event_kinds(), true ) ) {
        return 'concert';
    }
    return $kind;
}

/**
 * Parse a shortcode `kind` attribute into a list of kinds to allow.
 *
 * Shared by [ans_season_events] and [ans_season_projects] so the two cannot
 * drift apart - the whole defect being fixed here is one listing knowing
 * something the other does not.
 *
 * @param string $raw Comma-separated list, or the literal "any".
 * @return string[] Kinds to allow; an EMPTY array means do not filter at all.
 */
function ans_tb_kind_filter( $raw ) {
    $raw = strtolower( trim( (string) $raw ) );
    if ( '' === $raw || 'any' === $raw ) {
        return array();
    }
    $kinds = array();
    foreach ( explode( ',', $raw ) as $k ) {
        $k = trim( $k );
        if ( '' !== $k ) {
            $kinds[] = $k;
        }
    }
    // An attribute that parses to nothing (kind=" , ,") means the author asked
    // for no filter, not for an empty page.
    return array_values( array_unique( $kinds ) );
}

add_action( 'rest_api_init', 'ans_tb_register_routes' );

function ans_tb_register_routes() {
    register_rest_route( ANS_TB_NS, '/tickera/status', array(
        'methods'             => 'GET',
        'callback'            => 'ans_tb_status',
        'permission_callback' => 'ans_tb_perm',
    ) );
    register_rest_route( ANS_TB_NS, '/tickera/events', array(
        'methods'             => 'GET',
        'callback'            => 'ans_tb_list_events',
        'permission_callback' => 'ans_tb_perm',
    ) );
    register_rest_route( ANS_TB_NS, '/tickera/event', array(
        'methods'             => 'POST',
        'callback'            => 'ans_tb_create_event',
        'permission_callback' => 'ans_tb_perm',
    ) );
    register_rest_route( ANS_TB_NS, '/tickera/event/(?P<id>\d+)', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'ans_tb_get_event',
            'permission_callback' => 'ans_tb_perm',
        ),
        array(
            'methods'             => 'POST, PUT, PATCH',
            'callback'            => 'ans_tb_update_event',
            'permission_callback' => 'ans_tb_perm',
        ),
        array(
            'methods'             => 'DELETE',
            'callback'            => 'ans_tb_delete_event',
            'permission_callback' => 'ans_tb_perm',
        ),
    ) );
    register_rest_route( ANS_TB_NS, '/tickera/templates', array(
        'methods'             => 'GET',
        'callback'            => 'ans_tb_list_templates',
        'permission_callback' => 'ans_tb_perm',
    ) );
    register_rest_route( ANS_TB_NS, '/tickera/ticket-type', array(
        'methods'             => 'POST',
        'callback'            => 'ans_tb_create_ticket_type',
        'permission_callback' => 'ans_tb_perm',
    ) );
    register_rest_route( ANS_TB_NS, '/tickera/ticket-types', array(
        'methods'             => 'GET',
        'callback'            => 'ans_tb_list_ticket_types',
        'permission_callback' => 'ans_tb_perm',
    ) );
}

/** Candidate post types for Tickera ticket templates (varies by version). */
function ans_tb_template_post_types() {
    return array( 'tc_templates', 'tc_ticket_templates', 'ticket_template', 'tickeratemplates' );
}

/** Best-guess default ticket-template ID (first published template found). */
function ans_tb_default_template_id() {
    foreach ( ans_tb_template_post_types() as $pt ) {
        if ( post_type_exists( $pt ) ) {
            $posts = get_posts( array(
                'post_type'   => $pt,
                'numberposts' => 1,
                'post_status' => 'publish',
                'orderby'     => 'ID',
                'order'       => 'ASC',
                'fields'      => 'ids',
            ) );
            if ( ! empty( $posts ) ) {
                return (int) $posts[0];
            }
        }
    }
    return 0;
}

function ans_tb_bridge_active() {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    return is_plugin_active( 'bridge-for-woocommerce/bridge-for-woocommerce.php' );
}

/** GET /tickera/status — environment probe so the connector can verify prerequisites. */
function ans_tb_status() {
    return array(
        'plugin'              => 'ars-nova-ticketing-bridge',
        'version'             => ANS_TB_VERSION,
        'site'                => home_url(),
        'woocommerce_active'  => class_exists( 'WooCommerce' ),
        'tickera_active'      => post_type_exists( 'tc_events' ),
        'bridge_active'       => ans_tb_bridge_active(),
        'default_template_id' => ans_tb_default_template_id(),
    );
}

/** Shape one event for output, including any linked ticket-type products. */
function ans_tb_event_payload( $id ) {
    $id = (int) $id;
    return array(
        'id'                  => $id,
        'title'               => get_the_title( $id ),
        'status'              => get_post_status( $id ),
        'event_date_time'     => get_post_meta( $id, 'event_date_time', true ),
        'event_end_date_time' => get_post_meta( $id, 'event_end_date_time', true ),
        'event_location'      => get_post_meta( $id, 'event_location', true ),
        'ans_perk'            => get_post_meta( $id, 'ans_perk', true ),
        'ans_perk_tier'       => get_post_meta( $id, 'ans_perk_tier', true ),
        /*
         * Both the normalised kind and the raw stored string.
         *
         * The normaliser silently absorbs anything it does not recognise (see
         * ans_tb_event_kind), so on its own it would report a healthy-looking
         * 'concert' for an event somebody typed 'membrship' into - the write
         * would look applied and the classification would be wrong. Returning
         * the raw value too means a write is verified by READING IT BACK
         * rather than trusted, and an audit can find the typo.
         */
        'ans_event_kind'      => ans_tb_event_kind( $id ),
        'ans_event_kind_raw'  => get_post_meta( $id, 'ans_event_kind', true ),
        'permalink'           => get_permalink( $id ),
        'edit_link'           => html_entity_decode( (string) get_edit_post_link( $id, 'raw' ) ),
        'ticket_types'        => ans_tb_event_ticket_types( $id ),
    );
}

/** Ticket-type products linked to an event (product meta _ticket=yes AND event_name=id). */
function ans_tb_event_ticket_types( $event_id ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return array();
    }
    $products = get_posts( array(
        'post_type'   => 'product',
        'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
        'numberposts' => 100,
        'meta_query'  => array(
            'relation' => 'AND',
            array( 'key' => '_ticket', 'value' => 'yes' ),
            array( 'key' => 'event_name', 'value' => (int) $event_id ),
        ),
    ) );
    $out = array();
    foreach ( $products as $po ) {
        $prod  = function_exists( 'wc_get_product' ) ? wc_get_product( $po->ID ) : null;
        $out[] = array(
            'id'       => $po->ID,
            'name'     => $po->post_title,
            'price'    => $prod ? $prod->get_regular_price() : '',
            'stock'    => $prod ? $prod->get_stock_quantity() : null,
            'status'   => $po->post_status,
            'template' => (int) get_post_meta( $po->ID, 'ticket_template', true ),
        );
    }
    return $out;
}

/** GET /tickera/events */
function ans_tb_list_events( $req ) {
    if ( ! post_type_exists( 'tc_events' ) ) {
        return new WP_Error( 'tickera_inactive', 'Tickera is not active (no tc_events post type).', array( 'status' => 400 ) );
    }
    $posts = get_posts( array(
        'post_type'   => 'tc_events',
        'post_status' => array( 'publish', 'draft', 'future', 'pending', 'private' ),
        'numberposts' => 200,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ) );
    $out = array();
    foreach ( $posts as $po ) {
        $out[] = ans_tb_event_payload( $po->ID );
    }
    return array( 'count' => count( $out ), 'events' => $out );
}

/** GET /tickera/event/{id} */
function ans_tb_get_event( $req ) {
    $id = (int) $req['id'];
    if ( get_post_type( $id ) !== 'tc_events' ) {
        return new WP_Error( 'not_found', 'No tc_events post with that ID.', array( 'status' => 404 ) );
    }
    return ans_tb_event_payload( $id );
}

/** GET /tickera/templates */
function ans_tb_list_templates( $req ) {
    foreach ( ans_tb_template_post_types() as $pt ) {
        if ( post_type_exists( $pt ) ) {
            $posts = get_posts( array( 'post_type' => $pt, 'numberposts' => 50, 'post_status' => 'any' ) );
            $out   = array();
            foreach ( $posts as $po ) {
                $out[] = array( 'id' => $po->ID, 'title' => $po->post_title, 'post_type' => $pt );
            }
            return array( 'post_type' => $pt, 'count' => count( $out ), 'templates' => $out );
        }
    }
    return array( 'post_type' => null, 'count' => 0, 'templates' => array(), 'note' => 'No Tickera template post type found; ticket_template will default to 0.' );
}

/** Merge JSON body + query params into one array. */
function ans_tb_params( $req ) {
    $p = $req->get_json_params();
    if ( empty( $p ) || ! is_array( $p ) ) {
        $p = $req->get_params();
    }
    return is_array( $p ) ? $p : array();
}

/**
 * Normalize a date string to Tickera's 'Y-m-d H:i' storage format (site-local).
 *
 * This used to be strtotime() + gmdate(), which was right only by accident:
 * both halves assumed UTC, so a naive local string round-tripped unchanged
 * while the two genuinely wrong cases went unnoticed. An offset-bearing input
 * ("2026-10-09T19:30:00-06:00") came back as 01:30 the next day and was then
 * stored as if it were local; a relative input ("now", "today") resolved
 * against UTC and rolled over to tomorrow every Mountain evening.
 *
 * Parsing in the site timezone and formatting in the site timezone is right
 * for all three cases, and right on purpose rather than by cancellation.
 */
function ans_tb_norm_date( $value ) {
    $value = trim( (string) $value );
    if ( '' === $value ) {
        return '';
    }
    try {
        $dt = new DateTimeImmutable( $value, wp_timezone() );
    } catch ( Exception $e ) {
        return '';
    }
    return $dt->setTimezone( wp_timezone() )->format( 'Y-m-d H:i' );
}

/** POST /tickera/event — create a tc_events event with date/location meta. */
function ans_tb_create_event( $req ) {
    if ( ! post_type_exists( 'tc_events' ) ) {
        return new WP_Error( 'tickera_inactive', 'Tickera is not active (no tc_events post type).', array( 'status' => 400 ) );
    }
    $p     = ans_tb_params( $req );
    $title = isset( $p['title'] ) ? sanitize_text_field( $p['title'] ) : '';
    if ( '' === $title ) {
        return new WP_Error( 'missing_title', 'title is required.', array( 'status' => 400 ) );
    }
    $status = ( isset( $p['status'] ) && in_array( $p['status'], array( 'publish', 'draft' ), true ) ) ? $p['status'] : 'draft';
    $desc   = isset( $p['description'] ) ? wp_kses_post( $p['description'] ) : '';

    $post_id = wp_insert_post( array(
        'post_type'    => 'tc_events',
        'post_status'  => $status,
        'post_title'   => $title,
        'post_content' => $desc,
    ), true );
    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    if ( ! empty( $p['date'] ) ) {
        $d = ans_tb_norm_date( $p['date'] );
        if ( $d ) {
            update_post_meta( $post_id, 'event_date_time', $d );
        }
    }
    if ( ! empty( $p['end_date'] ) ) {
        $d = ans_tb_norm_date( $p['end_date'] );
        if ( $d ) {
            update_post_meta( $post_id, 'event_end_date_time', $d );
        }
    }
    if ( isset( $p['location'] ) ) {
        update_post_meta( $post_id, 'event_location', sanitize_text_field( $p['location'] ) );
    }

    return ans_tb_event_payload( $post_id );
}

/** POST /tickera/ticket-type — create a WooCommerce product wired as a Tickera ticket. */
function ans_tb_create_ticket_type( $req ) {
    if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product_Simple' ) ) {
        return new WP_Error( 'woo_inactive', 'WooCommerce is not active.', array( 'status' => 400 ) );
    }
    if ( ! post_type_exists( 'tc_events' ) ) {
        return new WP_Error( 'tickera_inactive', 'Tickera is not active.', array( 'status' => 400 ) );
    }
    $p        = ans_tb_params( $req );
    $event_id = isset( $p['event_id'] ) ? (int) $p['event_id'] : 0;
    if ( ! $event_id || get_post_type( $event_id ) !== 'tc_events' ) {
        return new WP_Error( 'bad_event', 'event_id must reference an existing tc_events post.', array( 'status' => 400 ) );
    }
    $name = isset( $p['name'] ) ? sanitize_text_field( $p['name'] ) : '';
    if ( '' === $name ) {
        return new WP_Error( 'missing_name', 'name is required.', array( 'status' => 400 ) );
    }
    $status       = ( isset( $p['status'] ) && in_array( $p['status'], array( 'publish', 'draft' ), true ) ) ? $p['status'] : 'publish';
    $price        = isset( $p['price'] ) ? (string) $p['price'] : '';
    $desc         = isset( $p['description'] ) ? wp_kses_post( $p['description'] ) : '';
    $short        = isset( $p['short_description'] ) ? wp_kses_post( $p['short_description'] ) : '';
    $sku          = isset( $p['sku'] ) ? sanitize_text_field( $p['sku'] ) : '';
    $has_stock    = array_key_exists( 'stock', $p ) && '' !== $p['stock'] && null !== $p['stock'];
    $stock        = $has_stock ? (int) $p['stock'] : null;
    $template     = ( isset( $p['ticket_template'] ) && '' !== $p['ticket_template'] ) ? (int) $p['ticket_template'] : ans_tb_default_template_id();

    $product = new WC_Product_Simple();
    $product->set_name( $name );
    $product->set_status( $status );
    $product->set_catalog_visibility( 'visible' );
    // Tickera's docs contradict themselves on Virtual. Settled by observation,
    // not by reading: a NON-virtual ticket product comes back with
    // shipping_required = true, which would demand a shipping address at
    // checkout for a PDF ticket. So Virtual defaults to TRUE. Overridable.
    $product->set_virtual( isset( $p['virtual'] ) ? (bool) $p['virtual'] : true );
    if ( '' !== $price ) {
        $product->set_regular_price( $price );
    }
    if ( $desc ) {
        $product->set_description( $desc );
    }
    if ( $short ) {
        $product->set_short_description( $short );
    }
    if ( $sku ) {
        try {
            $product->set_sku( $sku );
        } catch ( Exception $e ) { /* ignore duplicate SKU */ }
    }
    if ( $has_stock ) {
        $product->set_manage_stock( true );
        $product->set_stock_quantity( $stock );
        $product->set_stock_status( 'instock' );
    }
    $product_id = $product->save();
    if ( ! $product_id ) {
        return new WP_Error( 'product_failed', 'Failed to create the product.', array( 'status' => 500 ) );
    }

    // Bridge wiring. These keys were VERIFIED on 2026-07-31 by creating a ticket
    // through the WooCommerce admin UI and diffing its post meta against ours.
    //
    // Tickera 3.6.0.0 uses UNDERSCORE-PREFIXED keys. This plugin previously wrote
    // `_ticket`, `event_name` and `ticket_template`, which Tickera never reads —
    // so every ticket product it created was invisible to Tickera. That single
    // mistake caused the empty ticket table, "Unknown ticket ID" and the USD0
    // price. Do not "tidy" these names.
    ans_tb_write_ticket_meta( $product_id, $event_id, $template, $p );

    return array(
        'id'              => (int) $product_id,
        'name'            => $name,
        'event_id'        => (int) $event_id,
        'event_title'     => get_the_title( $event_id ),
        'price'           => $price,
        'stock'           => $stock,
        'ticket_template' => (int) $template,
        'status'          => $status,
        'permalink'       => get_permalink( $product_id ),
        'edit_link'       => html_entity_decode( (string) get_edit_post_link( $product_id, 'raw' ) ),
    );
}

/** GET /tickera/ticket-types?event_id=123 (event_id optional). */
function ans_tb_list_ticket_types( $req ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return new WP_Error( 'woo_inactive', 'WooCommerce is not active.', array( 'status' => 400 ) );
    }
    $event_id = (int) $req->get_param( 'event_id' );
    $meta     = array( 'relation' => 'AND', array( 'key' => '_ticket', 'value' => 'yes' ) );
    if ( $event_id ) {
        $meta[] = array( 'key' => 'event_name', 'value' => $event_id );
    }
    $products = get_posts( array(
        'post_type'   => 'product',
        'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
        'numberposts' => 200,
        'meta_query'  => $meta,
    ) );
    $out = array();
    foreach ( $products as $po ) {
        $prod  = wc_get_product( $po->ID );
        $out[] = array(
            'id'       => $po->ID,
            'name'     => $po->post_title,
            'event_id' => (int) get_post_meta( $po->ID, 'event_name', true ),
            'price'    => $prod ? $prod->get_regular_price() : '',
            'stock'    => $prod ? $prod->get_stock_quantity() : null,
            'status'   => $po->post_status,
        );
    }
    return array( 'count' => count( $out ), 'ticket_types' => $out );
}

/* ============================================================================
 * v1.1.0 — event update / delete, and the [ans_season_events] display shortcode
 * ========================================================================== */

/** POST|PUT|PATCH /tickera/event/{id} — update an existing tc_events post. */
function ans_tb_update_event( $req ) {
    $id = (int) $req['id'];
    if ( get_post_type( $id ) !== 'tc_events' ) {
        return new WP_Error( 'not_found', 'No tc_events post with that ID.', array( 'status' => 404 ) );
    }
    $p      = ans_tb_params( $req );
    $fields = array( 'ID' => $id );

    if ( isset( $p['title'] ) && '' !== $p['title'] ) {
        $fields['post_title'] = sanitize_text_field( $p['title'] );
    }
    if ( isset( $p['description'] ) ) {
        $fields['post_content'] = wp_kses_post( $p['description'] );
    }
    if ( isset( $p['status'] ) && in_array( $p['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
        $fields['post_status'] = $p['status'];
    }
    if ( count( $fields ) > 1 ) {
        $res = wp_update_post( $fields, true );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
    }

    if ( isset( $p['date'] ) ) {
        $d = ans_tb_norm_date( $p['date'] );
        if ( $d ) {
            update_post_meta( $id, 'event_date_time', $d );
        } elseif ( '' === $p['date'] ) {
            delete_post_meta( $id, 'event_date_time' );
        }
    }
    if ( isset( $p['end_date'] ) ) {
        $d = ans_tb_norm_date( $p['end_date'] );
        if ( $d ) {
            update_post_meta( $id, 'event_end_date_time', $d );
        } elseif ( '' === $p['end_date'] ) {
            delete_post_meta( $id, 'event_end_date_time' );
        }
    }
    if ( isset( $p['location'] ) ) {
        update_post_meta( $id, 'event_location', sanitize_text_field( $p['location'] ) );
    }

    // Optional presentation metas the season shortcode will prefer if present.
    foreach ( array( 'ans_display_title', 'ans_note', 'ans_perk', 'ans_perk_tier', 'ans_event_kind' ) as $key ) {
        if ( isset( $p[ $key ] ) ) {
            update_post_meta( $id, $key, sanitize_text_field( $p[ $key ] ) );
        }
    }
    if ( isset( $p['ans_page_id'] ) ) {
        update_post_meta( $id, 'ans_page_id', (int) $p['ans_page_id'] );
    }
    if ( isset( $p['ans_hide'] ) ) {
        update_post_meta( $id, 'ans_hide', $p['ans_hide'] ? 1 : 0 );
    }

    $payload = ans_tb_event_payload( $id );

    /*
     * Report submitted keys this endpoint does not consume.
     *
     * Without this the handler returns HTTP 200 and a full, healthy-looking payload
     * after writing nothing — a caller cannot distinguish "applied" from "silently
     * dropped" except by diffing the response by eye. That cost a real session on
     * 2026-08-11: the write was sent as 'event_date_time' (the key the READ payload
     * returns) rather than 'date' (the key this endpoint accepts), and the resulting
     * 200 was read as success. The read/write asymmetry is the trap; the silence is
     * what made it expensive.
     */
    $known = array(
        'id', 'title', 'description', 'status', 'date', 'end_date', 'location',
        'ans_display_title', 'ans_note', 'ans_page_id', 'ans_hide',
        'ans_perk', 'ans_perk_tier', 'ans_event_kind',
    );
    $ignored = array_values( array_diff( array_keys( $p ), $known ) );
    if ( $ignored ) {
        $payload['ignored_fields'] = $ignored;
        $payload['warning']        = 'Not written — this endpoint does not accept: '
            . implode( ', ', $ignored )
            . '. Note the read/write asymmetry: the payload returns event_date_time and'
            . ' event_location, but writes take date and location.';
    }

    return $payload;
}

/** DELETE /tickera/event/{id} — trash (default) or permanently delete with force=1. */
function ans_tb_delete_event( $req ) {
    $id = (int) $req['id'];
    if ( get_post_type( $id ) !== 'tc_events' ) {
        return new WP_Error( 'not_found', 'No tc_events post with that ID.', array( 'status' => 404 ) );
    }
    $force = (bool) $req->get_param( 'force' );
    $title = get_the_title( $id );
    $res   = $force ? wp_delete_post( $id, true ) : wp_trash_post( $id );
    if ( ! $res ) {
        return new WP_Error( 'delete_failed', 'WordPress refused to remove that event.', array( 'status' => 500 ) );
    }
    return array( 'id' => $id, 'title' => $title, 'deleted' => true, 'permanent' => $force );
}

/* -------------------------------------------------------------------------
 * [ans_season_events] — a date-stacked list of performances.
 *
 * Reads tc_events, sorts ascending by event_date_time, and renders one row per
 * performance grouped under month headings.
 *
 * Attributes:
 *   from="2026-08-01"   earliest event to show. Default: today.
 *   to="2027-08-31"     latest event to show. Default: none.
 *   limit="50"          max rows. Default 100.
 *   group_by_month="1"  month subheadings on/off. Default 1.
 *   show_past="0"       include events before `from`. Default 0.
 *   drafts="auto"       auto = logged-in editors also see drafts (badged);
 *                       never = publish only; always = include drafts for all.
 *   kind="concert"      which kinds of event to list, comma-separated
 *                       (concert, membership, perk, private) - or "any" to
 *                       switch the filter off. Default: concerts only, so a
 *                       membership or a perk evening cannot leak into a
 *                       public performance listing.
 *
 * Public visitors only ever see published events. Editors see drafts marked
 * "DRAFT — not public" so the page can be previewed before launch.
 *
 * Naming convention it relies on (no extra data entry needed):
 *   "<Project Name> — <date>, <venue> (<FLAG>)"
 * Everything before the em dash becomes the public title and is matched against
 * a page of the same name to build the link. A trailing (PENDING),
 * (PLACEHOLDER...), (TBC) or (FREE) becomes the row's note. The
 * ans_display_title / ans_note / ans_page_id metas override this if set.
 * ----------------------------------------------------------------------- */

add_shortcode( 'ans_season_events', 'ans_se_render' );

/** Public-facing title: everything before the first em/en/hyphen dash separator. */
function ans_se_clean_title( $raw ) {
    $t = html_entity_decode( (string) $raw, ENT_QUOTES, 'UTF-8' );
    // Byte-safe: every separator starts with an ASCII space, so the byte offset
    // is always on a character boundary. Avoids an mbstring dependency.
    foreach ( array( ' — ', ' – ', ' - ' ) as $sep ) {
        $pos = strpos( $t, $sep );
        if ( false !== $pos ) {
            $t = substr( $t, 0, $pos );
            break;
        }
    }
    return trim( $t );
}

/** Turn a trailing (FLAG) in the event title into a human note. */
function ans_se_note_from_title( $raw ) {
    $t = html_entity_decode( (string) $raw, ENT_QUOTES, 'UTF-8' );
    if ( ! preg_match( '/\(([^)]+)\)\s*$/u', $t, $m ) ) {
        return '';
    }
    $flag = strtoupper( trim( $m[1] ) );
    if ( false !== strpos( $flag, 'PLACEHOLDER' ) ) {
        return 'Date and venue not yet confirmed';
    }
    if ( false !== strpos( $flag, 'PENDING' ) || false !== strpos( $flag, 'TBC' ) || false !== strpos( $flag, 'TBD' ) ) {
        return 'Not yet confirmed';
    }
    if ( false !== strpos( $flag, 'FREE' ) ) {
        return 'Free admission';
    }
    return '';
}

/** Find the concert page whose title matches this project name. */
function ans_se_page_for( $title ) {
    if ( '' === $title ) {
        return 0;
    }
    $q = new WP_Query( array(
        'post_type'              => 'page',
        'post_status'            => array( 'publish', 'draft', 'private' ),
        'title'                  => $title,
        'posts_per_page'         => 1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ) );
    return ! empty( $q->posts ) ? (int) $q->posts[0] : 0;
}

/** Print the stylesheet once per request. */
function ans_se_styles() {
    static $done = false;
    if ( $done ) {
        return '';
    }
    $done = true;
    return '<style id="ans-season-events">
.ans-se{--ans-navy:#0e1b3a;--ans-gold:#c7a24a;--ans-cream:#f5f1e8;--ans-teal:#16423e;max-width:900px;margin:0 auto}
.ans-se__month{font-size:13px;letter-spacing:3px;text-transform:uppercase;color:#9a7b2e;margin:44px 0 14px;padding-bottom:8px;border-bottom:1px solid rgba(14,27,58,.16)}
.ans-se__month:first-child{margin-top:0}
.ans-se__row{display:flex;gap:26px;align-items:flex-start;padding:24px 0;border-bottom:1px solid rgba(14,27,58,.10)}
.ans-se__date{flex:0 0 118px;text-align:center;background:var(--ans-navy);color:#fff;border-radius:8px;padding:14px 8px;line-height:1.15}
.ans-se__dow{display:block;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#d8b25e}
.ans-se__day{display:block;font-size:34px;font-weight:700;margin:2px 0}
.ans-se__mon{display:block;font-size:12px;letter-spacing:2px;text-transform:uppercase}
.ans-se__time{display:block;font-size:12px;margin-top:6px;color:#e7ebf3}
.ans-se__body{flex:1 1 auto;min-width:0}
.ans-se__title{font-size:26px;font-weight:700;line-height:1.2;margin:0 0 6px}
.ans-se__title a{text-decoration:none;color:var(--ans-navy)}
.ans-se__title a:hover{text-decoration:underline}
.ans-se__venue{font-size:16px;margin:0 0 8px;color:#3a4560}
.ans-se__note{display:inline-block;font-size:12px;letter-spacing:1px;text-transform:uppercase;background:rgba(199,162,74,.22);color:#6d551a;padding:4px 10px;border-radius:20px;margin:0 8px 8px 0}
.ans-se__note--draft{background:rgba(122,31,43,.14);color:#7a1f2b}
.ans-se__cta{flex:0 0 auto;align-self:center}
.ans-se__cta a{display:inline-block;background:var(--ans-gold);color:var(--ans-navy);text-decoration:none;font-size:14px;font-weight:700;letter-spacing:1px;text-transform:uppercase;padding:13px 30px;border-radius:40px}
.ans-se__cta a:hover{background:var(--ans-navy);color:#fff}
.ans-se__empty{font-size:17px;color:#3a4560;font-style:italic}
@media (max-width:781px){.ans-se__row{flex-wrap:wrap;gap:18px}.ans-se__date{flex:0 0 92px}.ans-se__cta{flex:1 1 100%;align-self:flex-start}.ans-se__title{font-size:22px}}
</style>';
}

function ans_se_render( $atts ) {
    $a = shortcode_atts( array(
        'from'           => '',
        'to'             => '',
        'limit'          => 100,
        'group_by_month' => '1',
        'show_past'      => '0',
        'drafts'         => 'auto',
        'kind'           => 'concert',
        'empty_text'     => 'Performance dates will be announced here.',
    ), $atts, 'ans_season_events' );

    if ( ! post_type_exists( 'tc_events' ) ) {
        return '';
    }

    $can_edit    = current_user_can( 'edit_posts' );
    $want_drafts = ( 'always' === $a['drafts'] ) || ( 'auto' === $a['drafts'] && $can_edit );
    $statuses    = $want_drafts ? array( 'publish', 'draft', 'pending', 'private' ) : array( 'publish' );

    $from_ts = '' !== $a['from'] ? ans_tb_local_ts( $a['from'] ) : ( '1' === (string) $a['show_past'] ? 0 : ans_tb_today_ts() );
    $to_ts   = '' !== $a['to'] ? ans_tb_local_ts( $a['to'] . ' 23:59' ) : 0;

    // Parsed once, not per event. Empty = no filtering (kind="any").
    $kinds = ans_tb_kind_filter( $a['kind'] );

    $posts = get_posts( array(
        'post_type'        => 'tc_events',
        'post_status'      => $statuses,
        'numberposts'      => 300,
        'suppress_filters' => false,
    ) );

    $rows = array();
    foreach ( $posts as $po ) {
        if ( get_post_meta( $po->ID, 'ans_hide', true ) ) {
            continue;
        }
        if ( $kinds && ! in_array( ans_tb_event_kind( $po->ID ), $kinds, true ) ) {
            continue;
        }
        $raw_date = (string) get_post_meta( $po->ID, 'event_date_time', true );
        if ( '' === $raw_date ) {
            continue; // no date = not a real performance yet
        }
        $ts = ans_tb_local_ts( $raw_date );
        if ( ! $ts ) {
            continue;
        }
        if ( $from_ts && $ts < $from_ts ) {
            continue;
        }
        if ( $to_ts && $ts > $to_ts ) {
            continue;
        }

        $display = (string) get_post_meta( $po->ID, 'ans_display_title', true );
        if ( '' === $display ) {
            $display = ans_se_clean_title( $po->post_title );
        }
        /** Lets the event's project (Event Category) supply the public name. */
        $display = apply_filters( 'ans_se_display_title', $display, $po->ID );
        $note = (string) get_post_meta( $po->ID, 'ans_note', true );
        if ( '' === $note ) {
            $note = ans_se_note_from_title( $po->post_title );
        }
        $page_id = (int) get_post_meta( $po->ID, 'ans_page_id', true );
        if ( ! $page_id ) {
            $page_id = ans_se_page_for( $display );
        }

        $rows[] = array(
            'ts'      => $ts,
            'title'   => $display,
            'venue'   => (string) get_post_meta( $po->ID, 'event_location', true ),
            'note'    => $note,
            'page'    => $page_id,
            'draft'   => ( 'publish' !== $po->post_status ),
            'free'    => ( false !== stripos( $po->post_title, '(FREE)' ) ),
        );
    }

    usort( $rows, function ( $x, $y ) {
        return $x['ts'] <=> $y['ts'];
    } );

    $limit = max( 1, (int) $a['limit'] );
    if ( count( $rows ) > $limit ) {
        $rows = array_slice( $rows, 0, $limit );
    }

    $out = ans_se_styles() . '<div class="ans-se">';
    if ( empty( $rows ) ) {
        return $out . '<p class="ans-se__empty">' . esc_html( $a['empty_text'] ) . '</p></div>';
    }

    $group    = ( '1' === (string) $a['group_by_month'] );
    $last_key = '';
    foreach ( $rows as $r ) {
        if ( $group ) {
            $key = wp_date( 'Y-m', $r['ts'] );
            if ( $key !== $last_key ) {
                $out     .= '<h3 class="ans-se__month">' . esc_html( wp_date( 'F Y', $r['ts'] ) ) . '</h3>';
                $last_key = $key;
            }
        }

        $href  = $r['page'] ? get_permalink( $r['page'] ) : '';
        $title = esc_html( $r['title'] );
        if ( $href ) {
            $title = '<a href="' . esc_url( $href ) . '">' . $title . '</a>';
        }

        $out .= '<div class="ans-se__row">';
        $out .= '<div class="ans-se__date">'
              . '<span class="ans-se__dow">' . esc_html( wp_date( 'D', $r['ts'] ) ) . '</span>'
              . '<span class="ans-se__day">' . esc_html( wp_date( 'j', $r['ts'] ) ) . '</span>'
              . '<span class="ans-se__mon">' . esc_html( wp_date( 'M', $r['ts'] ) ) . '</span>'
              . '<span class="ans-se__time">' . esc_html( wp_date( 'g:i a', $r['ts'] ) ) . '</span>'
              . '</div>';

        $out .= '<div class="ans-se__body">';
        $out .= '<p class="ans-se__title">' . $title . '</p>';
        if ( '' !== $r['venue'] ) {
            $out .= '<p class="ans-se__venue">' . esc_html( $r['venue'] ) . '</p>';
        }
        if ( '' !== $r['note'] ) {
            $out .= '<span class="ans-se__note">' . esc_html( $r['note'] ) . '</span>';
        }
        if ( $r['draft'] ) {
            $out .= '<span class="ans-se__note ans-se__note--draft">Draft — not public</span>';
        }
        $out .= '</div>';

        if ( $href ) {
            $label = $r['free'] ? 'Details' : 'Tickets';
            $out  .= '<div class="ans-se__cta"><a href="' . esc_url( $href . '#tickets' ) . '">' . esc_html( $label ) . '</a></div>';
        }
        $out .= '</div>';
    }

    return $out . '</div>';
}

/* -------------------------------------------------------------------------
 * [ans_season_projects] — the season's PROJECTS in order, not its performances.
 *
 * A "project" is a program (Rivers & Streams, Darkness & Light...). Its
 * individual performances belong underneath it. This groups the tc_events by
 * project name, sorts the projects by their first performance date, and renders
 * one block per project: date range, project name, how many performances and in
 * which cities, the project's blurb, and a button through to its page.
 *
 * The blurb is the linked page's EXCERPT — so Kim can edit it in the normal
 * WordPress place with no shortcode knowledge. The thumbnail is that page's
 * featured image, if one is set.
 *
 * Attributes:
 *   from="2026-08-01"  earliest performance to consider. Default: today.
 *   to="2027-08-31"    latest. Default: none.
 *   show_past="0"      include projects whose dates have all passed.
 *   drafts="auto"      auto = editors also see drafts (badged); never | always.
 *   kind="concert"     which kinds of event to consider, comma-separated
 *                      (concert, membership, perk, private) - or "any" to
 *                      switch the filter off. Default: concerts only. This is
 *                      what stops the Nova Circle membership and the Circle's
 *                      perk evenings from rendering here as project cards.
 *   limit="20"         max projects.
 *   empty_text="..."   shown when nothing matches.
 * ----------------------------------------------------------------------- */

add_shortcode( 'ans_season_projects', 'ans_sp_render' );

/** Pull a short, human place name out of a full venue string. */
function ans_sp_place( $location ) {
    $loc = trim( (string) $location );
    if ( '' === $loc ) {
        return '';
    }
    // "…, Boulder, CO 80303" -> "Boulder"
    if ( preg_match( '/,\s*([^,]+?),\s*[A-Z]{2}\s*\d{5}/', $loc, $m ) ) {
        return trim( $m[1] );
    }
    // Otherwise take the leading name: "Savoy, Denver — PLACEHOLDER…" -> "Savoy"
    $first = explode( ',', $loc );
    $first = explode( ' — ', $first[0] );
    return trim( $first[0] );
}

/** "October 9–11, 2026" / "September 12, 2026" / "Dec 6, 2026 – Jan 5, 2027" */
function ans_sp_date_range( $first, $last ) {
    if ( $first === $last ) {
        return wp_date( 'F j, Y', $first );
    }
    if ( wp_date( 'Y', $first ) === wp_date( 'Y', $last ) ) {
        if ( wp_date( 'm', $first ) === wp_date( 'm', $last ) ) {
            return wp_date( 'F j', $first ) . '–' . wp_date( 'j, Y', $last );
        }
        return wp_date( 'F j', $first ) . ' – ' . wp_date( 'F j, Y', $last );
    }
    return wp_date( 'F j, Y', $first ) . ' – ' . wp_date( 'F j, Y', $last );
}

function ans_sp_styles() {
    static $done = false;
    if ( $done ) {
        return '';
    }
    $done = true;
    return '<style id="ans-season-projects">
.ans-sp{--ans-navy:#0e1b3a;--ans-gold:#c7a24a;--ans-cream:#f5f1e8;max-width:1080px;margin:0 auto}
.ans-sp__item{display:flex;gap:48px;align-items:center;padding:52px 0}
.ans-sp__item:nth-child(even){flex-direction:row-reverse;background:rgba(14,27,58,.09);box-shadow:0 0 0 100vmax rgba(14,27,58,.09);clip-path:inset(0 -100vmax)}
.ans-sp__thumb{flex:0 0 380px}
.ans-sp__thumb img{width:100%;height:260px;object-fit:cover;border-radius:10px;display:block}
.ans-sp__thumb--placeholder{height:260px;border-radius:10px;background:linear-gradient(135deg,#0e1b3a 0%,#16423e 55%,#0e1b3a 100%);position:relative;overflow:hidden}
.ans-sp__thumb--placeholder::after{content:"";position:absolute;inset:0;background:linear-gradient(115deg,transparent 42%,rgba(199,162,74,.30) 50%,transparent 58%)}
.ans-sp__body{flex:1 1 auto;min-width:0}
.ans-sp__when{font-size:19px;font-weight:600;letter-spacing:2.5px;text-transform:uppercase;color:#8a6d24;margin:0 0 14px}
.ans-sp__name{font-size:34px;font-weight:700;line-height:1.15;margin:0 0 10px}
.ans-sp__name a{text-decoration:none;color:var(--ans-navy)}
.ans-sp__name a:hover{text-decoration:underline}
.ans-sp__meta{font-size:15px;color:#3a4560;margin:0 0 12px}
.ans-sp__blurb{font-size:17px;line-height:1.65;margin:0 0 18px;color:#25304a}
.ans-sp__note{display:inline-block;font-size:12px;letter-spacing:1px;text-transform:uppercase;background:rgba(199,162,74,.22);color:#6d551a;padding:4px 10px;border-radius:20px;margin:0 8px 12px 0}
.ans-sp__note--draft{background:rgba(122,31,43,.14);color:#7a1f2b}
.ans-sp__cta{display:inline-block;background:var(--ans-gold);color:var(--ans-navy);text-decoration:none;font-size:14px;font-weight:700;letter-spacing:1px;text-transform:uppercase;padding:13px 32px;border-radius:40px}
.ans-sp__cta:hover{background:var(--ans-navy);color:#fff}
.ans-sp__empty{font-size:17px;color:#3a4560;font-style:italic}
@media (max-width:781px){.ans-sp__item,.ans-sp__item:nth-child(even){flex-direction:column;gap:18px}.ans-sp__thumb{flex:1 1 auto;width:100%}.ans-sp__thumb img{height:210px}.ans-sp__thumb--placeholder{height:180px}.ans-sp__name{font-size:27px}}
</style>';
}

function ans_sp_render( $atts ) {
    $a = shortcode_atts( array(
        'from'       => '',
        'to'         => '',
        'show_past'  => '0',
        'drafts'     => 'auto',
        'kind'       => 'concert',
        'limit'      => 20,
        'empty_text' => 'This season\'s projects will be announced here.',
    ), $atts, 'ans_season_projects' );

    if ( ! post_type_exists( 'tc_events' ) ) {
        return '';
    }

    $can_edit    = current_user_can( 'edit_posts' );
    $want_drafts = ( 'always' === $a['drafts'] ) || ( 'auto' === $a['drafts'] && $can_edit );
    $statuses    = $want_drafts ? array( 'publish', 'draft', 'pending', 'private' ) : array( 'publish' );

    $from_ts = '' !== $a['from'] ? ans_tb_local_ts( $a['from'] ) : ( '1' === (string) $a['show_past'] ? 0 : ans_tb_today_ts() );
    $to_ts   = '' !== $a['to'] ? ans_tb_local_ts( $a['to'] . ' 23:59' ) : 0;

    // Parsed once, not per event. Empty = no filtering (kind="any").
    $kinds = ans_tb_kind_filter( $a['kind'] );

    $posts = get_posts( array(
        'post_type'   => 'tc_events',
        'post_status' => $statuses,
        'numberposts' => 300,
    ) );

    // Group performances into projects.
    $projects = array();
    foreach ( $posts as $po ) {
        if ( get_post_meta( $po->ID, 'ans_hide', true ) ) {
            continue;
        }
        // ans_hide above stays the per-event manual override. This is the
        // class-level rule: a membership or a perk evening is not a project.
        if ( $kinds && ! in_array( ans_tb_event_kind( $po->ID ), $kinds, true ) ) {
            continue;
        }
        $raw = (string) get_post_meta( $po->ID, 'event_date_time', true );
        $ts  = ans_tb_local_ts( $raw );
        if ( ! $ts ) {
            continue;
        }
        if ( $from_ts && $ts < $from_ts ) {
            continue;
        }
        if ( $to_ts && $ts > $to_ts ) {
            continue;
        }

        // A project is a Tickera Event Category. That is the real, editable
        // structure — Events > Categories in wp-admin. Only if an event has not
        // been categorised do we fall back to reading the project name out of
        // the event title.
        $term = ans_sp_event_term( $po->ID );

        if ( $term ) {
            $key  = 'term:' . $term->term_id;
            $name = $term->name;
        } else {
            $name = (string) get_post_meta( $po->ID, 'ans_display_title', true );
            if ( '' === $name ) {
                $name = ans_se_clean_title( $po->post_title );
            }
            if ( '' === $name ) {
                continue;
            }
            $key = 'title:' . strtolower( $name );
        }

        if ( ! isset( $projects[ $key ] ) ) {
            $projects[ $key ] = array(
                'name'   => $name,
                'term'   => $term,
                'first'  => $ts,
                'last'   => $ts,
                'count'  => 0,
                'places' => array(),
                'notes'  => array(),
                'draft'  => true,
            );
        }
        $p = &$projects[ $key ];
        $p['first'] = min( $p['first'], $ts );
        $p['last']  = max( $p['last'], $ts );
        $p['count']++;
        $place = ans_sp_place( get_post_meta( $po->ID, 'event_location', true ) );
        if ( '' !== $place && ! in_array( $place, $p['places'], true ) ) {
            $p['places'][] = $place;
        }
        $note = (string) get_post_meta( $po->ID, 'ans_note', true );
        if ( '' === $note ) {
            $note = ans_se_note_from_title( $po->post_title );
        }
        if ( '' !== $note && ! in_array( $note, $p['notes'], true ) ) {
            $p['notes'][] = $note;
        }
        if ( 'publish' === $po->post_status ) {
            $p['draft'] = false; // at least one performance is live
        }
        unset( $p );
    }

    uasort( $projects, function ( $x, $y ) {
        return $x['first'] <=> $y['first'];
    } );

    $limit    = max( 1, (int) $a['limit'] );
    $projects = array_slice( $projects, 0, $limit, true );

    $out = ans_sp_styles() . '<div class="ans-sp">';
    if ( empty( $projects ) ) {
        return $out . '<p class="ans-sp__empty">' . esc_html( $a['empty_text'] ) . '</p></div>';
    }

    foreach ( $projects as $p ) {
        // Link target: the category's own ans_page_id term meta wins, then a
        // page whose title matches the project name.
        $page_id = 0;
        if ( ! empty( $p['term'] ) ) {
            $page_id = (int) get_term_meta( $p['term']->term_id, 'ans_page_id', true );
        }
        if ( ! $page_id ) {
            $page_id = ans_se_page_for( $p['name'] );
        }
        $href = $page_id ? get_permalink( $page_id ) : '';

        $name_html = esc_html( $p['name'] );
        if ( $href ) {
            $name_html = '<a href="' . esc_url( $href ) . '">' . $name_html . '</a>';
        }

        $out .= '<div class="ans-sp__item">';

        if ( $page_id && has_post_thumbnail( $page_id ) ) {
            $out .= '<div class="ans-sp__thumb">' . get_the_post_thumbnail( $page_id, 'medium_large' ) . '</div>';
        } else {
            // No artwork yet. Emit a season-palette placeholder rather than
            // nothing, so the row keeps its two-column rhythm and the
            // alternating left/right cadence does not break mid-list.
            $out .= '<div class="ans-sp__thumb ans-sp__thumb--placeholder" aria-hidden="true"></div>';
        }

        $out .= '<div class="ans-sp__body">';
        $out .= '<p class="ans-sp__when">' . esc_html( ans_sp_date_range( $p['first'], $p['last'] ) ) . '</p>';
        $out .= '<h3 class="ans-sp__name">' . $name_html . '</h3>';

        $bits = array();
        $bits[] = ( 1 === $p['count'] ) ? '1 performance' : $p['count'] . ' performances';
        if ( ! empty( $p['places'] ) ) {
            $bits[] = implode( ', ', $p['places'] );
        }
        $out .= '<p class="ans-sp__meta">' . esc_html( implode( ' · ', $bits ) ) . '</p>';

        // Blurb: the Event Category's description first (Events > Categories),
        // then the project page's excerpt. Both are ordinary WordPress fields,
        // so Kim can edit either without touching a shortcode.
        $blurb = '';
        if ( ! empty( $p['term'] ) && '' !== trim( (string) $p['term']->description ) ) {
            $blurb = $p['term']->description;
        } elseif ( $page_id ) {
            $blurb = (string) get_post_field( 'post_excerpt', $page_id );
        }
        if ( '' !== trim( $blurb ) ) {
            $out .= '<p class="ans-sp__blurb">' . esc_html( wp_strip_all_tags( $blurb ) ) . '</p>';
        }

        foreach ( $p['notes'] as $note ) {
            $out .= '<span class="ans-sp__note">' . esc_html( $note ) . '</span>';
        }
        if ( $p['draft'] ) {
            $out .= '<span class="ans-sp__note ans-sp__note--draft">Draft — not public</span>';
        }

        if ( $href ) {
            $out .= '<div><a class="ans-sp__cta" href="' . esc_url( $href ) . '">Details &amp; Tickets</a></div>';
        }
        $out .= '</div></div>';
    }

    return $out . '</div>';
}

/* ============================================================================
 * v1.3.0 — projects are Tickera Event Categories
 *
 * Tickera registers an `event_category` taxonomy on tc_events. That is exactly
 * the "project" concept: a program that several performances belong to. It has
 * a name, a slug and a description, it is editable at Events > Categories, and
 * it survives an event being renamed. So it, not the event title, is the spine.
 *
 * Title parsing stays only as a fallback for events nobody has categorised yet.
 * ========================================================================== */

/** The event_category term for an event, or null if it has none. */
function ans_sp_event_term( $event_id ) {
    if ( ! taxonomy_exists( 'event_category' ) ) {
        return null;
    }
    $terms = wp_get_object_terms( (int) $event_id, 'event_category' );
    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return null;
    }
    // An event belongs to one project. If several are ticked, the lowest term
    // ID wins so the grouping is at least deterministic.
    usort( $terms, function ( $x, $y ) {
        return $x->term_id <=> $y->term_id;
    } );
    return $terms[0];
}

/**
 * One-time backfill: give every uncategorised event a category derived from its
 * title, so the existing season groups correctly without 17 manual edits.
 *
 * Idempotent and non-destructive — it never touches an event that already has a
 * category, so anything reassigned by hand in wp-admin stays reassigned. Safe to
 * delete this function once the season is categorised.
 */
function ans_tb_backfill_event_categories() {
    if ( ! taxonomy_exists( 'event_category' ) || ! post_type_exists( 'tc_events' ) ) {
        return 0;
    }
    $posts = get_posts( array(
        'post_type'   => 'tc_events',
        'post_status' => array( 'publish', 'draft', 'future', 'pending', 'private' ),
        'numberposts' => 300,
    ) );
    $done = 0;
    foreach ( $posts as $po ) {
        $existing = wp_get_object_terms( $po->ID, 'event_category', array( 'fields' => 'ids' ) );
        if ( is_wp_error( $existing ) || ! empty( $existing ) ) {
            continue; // already categorised — leave it alone
        }
        $name = ans_se_clean_title( $po->post_title );
        if ( '' === $name ) {
            continue;
        }
        $term = term_exists( $name, 'event_category' );
        if ( ! $term ) {
            $term = wp_insert_term( $name, 'event_category' );
        }
        if ( is_wp_error( $term ) ) {
            continue;
        }
        $term_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
        if ( $term_id ) {
            wp_set_object_terms( $po->ID, array( $term_id ), 'event_category', false );
            $done++;
        }
    }
    return $done;
}

// Run the backfill once, then never again.
add_action( 'init', function () {
    if ( '1' === get_option( 'ans_tb_cat_backfill_v1' ) ) {
        return;
    }
    if ( ! taxonomy_exists( 'event_category' ) ) {
        return; // Tickera not loaded yet on this request; try again next time.
    }
    ans_tb_backfill_event_categories();
    update_option( 'ans_tb_cat_backfill_v1', '1' );
}, 30 );

/**
 * Make the performance calendar category-aware too, so a renamed event still
 * shows the right project name. Filters the display title used by
 * [ans_season_events].
 */
add_filter( 'ans_se_display_title', function ( $title, $event_id ) {
    $term = ans_sp_event_term( $event_id );
    return $term ? $term->name : $title;
}, 10, 2 );

/* ============================================================================
 * v1.4.0 — full Tickera management surface
 *
 * Everything below exists so this system can be operated by command instead of
 * by hand in wp-admin. Endpoints are admin-only and namespaced under
 * ars-nova/v1.
 *
 * Design note: we deliberately do NOT modify the Tickera or Bridge plugins.
 * They are licensed premium code and an update would erase any change. This
 * plugin wraps them, writing the same post/meta their own admin screens write.
 * ========================================================================== */

add_action( 'rest_api_init', 'ans_tb_register_v14_routes' );

function ans_tb_register_v14_routes() {

    // --- Introspection: ground truth about what is actually registered ------
    register_rest_route( ANS_TB_NS, '/tickera/introspect', array(
        'methods'             => 'GET',
        'callback'            => 'ans_tb_introspect',
        'permission_callback' => 'ans_tb_perm',
    ) );

    // --- Ticket types: read one, update, delete ----------------------------
    register_rest_route( ANS_TB_NS, '/tickera/ticket-type/(?P<id>\d+)', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'ans_tb_get_ticket_type',
            'permission_callback' => 'ans_tb_perm',
        ),
        array(
            'methods'             => 'POST, PUT, PATCH',
            'callback'            => 'ans_tb_update_ticket_type',
            'permission_callback' => 'ans_tb_perm',
        ),
        array(
            'methods'             => 'DELETE',
            'callback'            => 'ans_tb_delete_ticket_type',
            'permission_callback' => 'ans_tb_perm',
        ),
    ) );

    // --- Bulk: stamp a ticket template onto ticket products ----------------
    register_rest_route( ANS_TB_NS, '/tickera/assign-template', array(
        'methods'             => 'POST',
        'callback'            => 'ans_tb_assign_template',
        'permission_callback' => 'ans_tb_perm',
    ) );

    // --- Event categories (projects) ---------------------------------------
    register_rest_route( ANS_TB_NS, '/tickera/event-categories', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'ans_tb_list_event_categories',
            'permission_callback' => 'ans_tb_perm',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'ans_tb_create_event_category',
            'permission_callback' => 'ans_tb_perm',
        ),
    ) );
    register_rest_route( ANS_TB_NS, '/tickera/event-category/(?P<id>\d+)', array(
        array(
            'methods'             => 'POST, PUT, PATCH',
            'callback'            => 'ans_tb_update_event_category',
            'permission_callback' => 'ans_tb_perm',
        ),
        array(
            'methods'             => 'DELETE',
            'callback'            => 'ans_tb_delete_event_category',
            'permission_callback' => 'ans_tb_perm',
        ),
    ) );

    // --- Attendees / issued tickets ----------------------------------------
    register_rest_route( ANS_TB_NS, '/tickera/attendees', array(
        'methods'             => 'GET',
        'callback'            => 'ans_tb_list_attendees',
        'permission_callback' => 'ans_tb_perm',
    ) );
}

/**
 * GET /tickera/introspect
 *
 * The anti-guessing endpoint. Reports what is ACTUALLY registered on this
 * install rather than what the documentation claims, because those have
 * already diverged more than once (menu says "Ticket Designer", docs say
 * "Ticket Templates"; several documented shortcodes ship as separate add-ons
 * and are simply absent).
 */
function ans_tb_introspect( $req ) {
    global $shortcode_tags, $wp_post_types, $wp_taxonomies;

    $tags = array_keys( (array) $shortcode_tags );
    sort( $tags );

    // Which of the shortcodes we care about are really present?
    $watch = array(
        'event', 'ticket', 'tc_event', 'tc_events_list', 'tc_event_date',
        'tc_event_location', 'tc_event_terms', 'tc_event_logo',
        'tc_event_sponsors_logo', 'tc_cart', 'tc_order_history',
        'tc_woo_event_tickets_left', 'event_tickets_left', 'all_events',
        'tc_category_sc', 'events_by_category', 'past_events',
        'tc_organizer_details', 'attendee_gravatars',
        'event_tickets_sold', 'tickets_sold', 'tickets_left',
        'ans_season_events', 'ans_season_projects',
    );
    $watched = array();
    foreach ( $watch as $t ) {
        $watched[ $t ] = shortcode_exists( $t );
    }

    // Tickera-ish post types and taxonomies actually registered.
    $tc_types = array();
    foreach ( (array) $wp_post_types as $name => $obj ) {
        if ( 0 === strpos( $name, 'tc_' ) || in_array( $name, array( 'product' ), true ) ) {
            $tc_types[ $name ] = array(
                'label'      => isset( $obj->label ) ? $obj->label : '',
                'public'     => ! empty( $obj->public ),
                'show_in_rest' => ! empty( $obj->show_in_rest ),
                'taxonomies' => get_object_taxonomies( $name ),
                'count'      => (int) wp_count_posts( $name )->publish + (int) wp_count_posts( $name )->draft,
            );
        }
    }

    $tax = array();
    foreach ( (array) $wp_taxonomies as $name => $obj ) {
        if ( in_array( 'tc_events', (array) $obj->object_type, true ) ) {
            $tax[ $name ] = array(
                'label'      => isset( $obj->label ) ? $obj->label : '',
                'hierarchical' => ! empty( $obj->hierarchical ),
                'terms'      => (int) wp_count_terms( array( 'taxonomy' => $name, 'hide_empty' => false ) ),
            );
        }
    }

    // Distinct meta keys actually in use on ticket products — the real answer
    // to "what does a working ticket product look like".
    global $wpdb;
    $ticket_meta_keys = $wpdb->get_col(
        "SELECT DISTINCT pm.meta_key
           FROM {$wpdb->postmeta} pm
           INNER JOIN {$wpdb->postmeta} flag
                   ON flag.post_id = pm.post_id
                  AND flag.meta_key = '_ticket'
                  AND flag.meta_value = 'yes'
          ORDER BY pm.meta_key"
    );

    $event_meta_keys = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT pm.meta_key
               FROM {$wpdb->postmeta} pm
               INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE p.post_type = %s
              ORDER BY pm.meta_key",
            'tc_events'
        )
    );

    return array(
        'plugin_version'        => ANS_TB_VERSION,
        'tickera_version'       => defined( 'TC_VERSION' ) ? TC_VERSION : null,
        'bridge_active'         => ans_tb_bridge_active(),
        'woocommerce_active'    => class_exists( 'WooCommerce' ),
        'default_template_id'   => ans_tb_default_template_id(),
        'template_post_type'    => post_type_exists( 'tc_templates' ) ? 'tc_templates' : null,
        'templates'             => ans_tb_template_list_simple(),
        'shortcodes_watched'    => $watched,
        'shortcodes_all'        => $tags,
        'post_types'            => $tc_types,
        'event_taxonomies'      => $tax,
        'ticket_product_meta_keys' => $ticket_meta_keys,
        'event_meta_keys'       => $event_meta_keys,
    );
}

/** Simple id/title list of ticket templates, any status. */
function ans_tb_template_list_simple() {
    if ( ! post_type_exists( 'tc_templates' ) ) {
        return array();
    }
    $posts = get_posts( array(
        'post_type'   => 'tc_templates',
        'post_status' => 'any',
        'numberposts' => 50,
    ) );
    $out = array();
    foreach ( $posts as $po ) {
        $out[] = array( 'id' => $po->ID, 'title' => $po->post_title, 'status' => $po->post_status );
    }
    return $out;
}

/** Shape a ticket-type product for output, including every Tickera meta on it. */
function ans_tb_ticket_type_payload( $id ) {
    $id   = (int) $id;
    $prod = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
    $meta = array();
    foreach ( (array) get_post_meta( $id ) as $k => $v ) {
        if ( '_' === substr( $k, 0, 1 ) && ! in_array( $k, array( '_ticket', '_ticket_availability', '_price', '_regular_price', '_virtual', '_stock', '_stock_status', '_manage_stock', '_sku' ), true ) ) {
            continue; // skip WooCommerce internals we do not care about
        }
        $meta[ $k ] = is_array( $v ) && 1 === count( $v ) ? $v[0] : $v;
    }
    return array(
        'id'          => $id,
        'name'        => get_the_title( $id ),
        'status'      => get_post_status( $id ),
        'price'       => $prod ? $prod->get_regular_price() : '',
        'stock'       => $prod ? $prod->get_stock_quantity() : null,
        'virtual'     => $prod ? $prod->is_virtual() : null,
        'purchasable' => $prod ? $prod->is_purchasable() : null,
        'event_id'    => (int) get_post_meta( $id, 'event_name', true ),
        'template_id' => (int) get_post_meta( $id, 'ticket_template', true ),
        'meta'        => $meta,
        'edit_link'   => html_entity_decode( (string) get_edit_post_link( $id, 'raw' ) ),
    );
}

/** GET /tickera/ticket-type/{id} */
function ans_tb_get_ticket_type( $req ) {
    $id = (int) $req['id'];
    if ( 'product' !== get_post_type( $id ) ) {
        return new WP_Error( 'not_found', 'No product with that ID.', array( 'status' => 404 ) );
    }
    return ans_tb_ticket_type_payload( $id );
}

/** POST /tickera/ticket-type/{id} — update a ticket-type product. */
function ans_tb_update_ticket_type( $req ) {
    $id = (int) $req['id'];
    if ( 'product' !== get_post_type( $id ) || ! function_exists( 'wc_get_product' ) ) {
        return new WP_Error( 'not_found', 'No product with that ID.', array( 'status' => 404 ) );
    }
    $p    = ans_tb_params( $req );
    $prod = wc_get_product( $id );
    if ( ! $prod ) {
        return new WP_Error( 'not_found', 'Product could not be loaded.', array( 'status' => 404 ) );
    }

    if ( isset( $p['name'] ) && '' !== $p['name'] ) {
        $prod->set_name( sanitize_text_field( $p['name'] ) );
    }
    if ( isset( $p['price'] ) ) {
        $prod->set_regular_price( (string) $p['price'] );
    }
    if ( isset( $p['status'] ) && in_array( $p['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
        $prod->set_status( $p['status'] );
    }
    if ( isset( $p['virtual'] ) ) {
        $prod->set_virtual( (bool) $p['virtual'] );
    }
    if ( array_key_exists( 'stock', $p ) ) {
        if ( null === $p['stock'] || '' === $p['stock'] ) {
            $prod->set_manage_stock( false );
        } else {
            $prod->set_manage_stock( true );
            $prod->set_stock_quantity( (int) $p['stock'] );
            $prod->set_stock_status( 'instock' );
        }
    }
    if ( isset( $p['description'] ) ) {
        $prod->set_description( wp_kses_post( $p['description'] ) );
    }
    if ( isset( $p['short_description'] ) ) {
        $prod->set_short_description( wp_kses_post( $p['short_description'] ) );
    }
    $prod->save();

    // Tickera / Bridge wiring.
    if ( isset( $p['event_id'] ) ) {
        $eid = (int) $p['event_id'];
        if ( $eid && 'tc_events' === get_post_type( $eid ) ) {
            update_post_meta( $id, 'event_name', $eid );
        }
    }
    if ( isset( $p['ticket_template'] ) ) {
        update_post_meta( $id, 'ticket_template', (int) $p['ticket_template'] );
    }
    if ( isset( $p['ticket_availability'] ) ) {
        update_post_meta( $id, '_ticket_availability', sanitize_text_field( $p['ticket_availability'] ) );
    }
    // Pass-through for any other Tickera meta we learn about later.
    if ( isset( $p['meta'] ) && is_array( $p['meta'] ) ) {
        foreach ( $p['meta'] as $k => $v ) {
            $k = sanitize_key( $k );
            if ( '' !== $k ) {
                update_post_meta( $id, $k, is_scalar( $v ) ? sanitize_text_field( (string) $v ) : $v );
            }
        }
    }
    update_post_meta( $id, '_ticket', 'yes' );

    return ans_tb_ticket_type_payload( $id );
}

/** DELETE /tickera/ticket-type/{id} */
function ans_tb_delete_ticket_type( $req ) {
    $id = (int) $req['id'];
    if ( 'product' !== get_post_type( $id ) ) {
        return new WP_Error( 'not_found', 'No product with that ID.', array( 'status' => 404 ) );
    }
    $force = (bool) $req->get_param( 'force' );
    $title = get_the_title( $id );
    $res   = $force ? wp_delete_post( $id, true ) : wp_trash_post( $id );
    if ( ! $res ) {
        return new WP_Error( 'delete_failed', 'WordPress refused to remove that product.', array( 'status' => 500 ) );
    }
    return array( 'id' => $id, 'name' => $title, 'deleted' => true, 'permanent' => $force );
}

/**
 * POST /tickera/assign-template
 * Body: { template_id: 123, event_id?: 456, product_ids?: [..] }
 * With neither event_id nor product_ids, applies to every ticket product.
 */
function ans_tb_assign_template( $req ) {
    $p           = ans_tb_params( $req );
    $template_id = isset( $p['template_id'] ) ? (int) $p['template_id'] : 0;
    if ( ! $template_id ) {
        $template_id = ans_tb_default_template_id();
    }
    if ( ! $template_id ) {
        return new WP_Error( 'no_template', 'No template_id given and no ticket template exists on this site.', array( 'status' => 400 ) );
    }

    // v1.8.1: match BOTH meta conventions. repair-tickets deletes the legacy
    // `_ticket` / `event_name` keys, so searching only those found nothing on
    // any repaired product — half of why this route was marked broken.
    $meta = array(
        'relation' => 'AND',
        array(
            'relation' => 'OR',
            array( 'key' => '_tc_is_ticket', 'value' => 'yes' ),
            array( 'key' => '_ticket', 'value' => 'yes' ),
        ),
    );
    if ( ! empty( $p['event_id'] ) ) {
        $meta[] = array(
            'relation' => 'OR',
            array( 'key' => '_event_name', 'value' => (int) $p['event_id'] ),
            array( 'key' => 'event_name', 'value' => (int) $p['event_id'] ),
        );
    }
    $args = array(
        'post_type'   => 'product',
        'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
        'numberposts' => 300,
        'meta_query'  => $meta,
    );
    if ( ! empty( $p['product_ids'] ) && is_array( $p['product_ids'] ) ) {
        $args['post__in'] = array_map( 'intval', $p['product_ids'] );
    }

    $updated = array();
    foreach ( get_posts( $args ) as $po ) {
        // v1.8.1: write the key the Ticket DESIGNER actually reads. This route
        // previously wrote `ticket_template`, which repair-tickets deletes as
        // stale — so assignment silently did nothing and tickets printed blank.
        update_post_meta( $po->ID, 'tc_designer_template_id', $template_id );
        if ( isset( $p['legacy_template'] ) ) {
            update_post_meta( $po->ID, '_ticket_template', (int) $p['legacy_template'] );
        }
        $updated[] = array( 'id' => $po->ID, 'name' => $po->post_title );
    }
    return array( 'template_id' => $template_id, 'count' => count( $updated ), 'updated' => $updated );
}

/** GET /tickera/event-categories */
function ans_tb_list_event_categories( $req ) {
    if ( ! taxonomy_exists( 'event_category' ) ) {
        return new WP_Error( 'no_taxonomy', 'event_category taxonomy is not registered.', array( 'status' => 400 ) );
    }
    $terms = get_terms( array( 'taxonomy' => 'event_category', 'hide_empty' => false ) );
    if ( is_wp_error( $terms ) ) {
        return $terms;
    }
    $out = array();
    foreach ( $terms as $t ) {
        $out[] = array(
            'id'          => $t->term_id,
            'name'        => $t->name,
            'slug'        => $t->slug,
            'parent'      => $t->parent,
            'description' => $t->description,
            'count'       => $t->count,
            'ans_page_id' => (int) get_term_meta( $t->term_id, 'ans_page_id', true ),
        );
    }
    return array( 'count' => count( $out ), 'categories' => $out );
}

/** POST /tickera/event-categories */
function ans_tb_create_event_category( $req ) {
    if ( ! taxonomy_exists( 'event_category' ) ) {
        return new WP_Error( 'no_taxonomy', 'event_category taxonomy is not registered.', array( 'status' => 400 ) );
    }
    $p    = ans_tb_params( $req );
    $name = isset( $p['name'] ) ? sanitize_text_field( $p['name'] ) : '';
    if ( '' === $name ) {
        return new WP_Error( 'missing_name', 'name is required.', array( 'status' => 400 ) );
    }
    $res = wp_insert_term( $name, 'event_category', array(
        'slug'        => isset( $p['slug'] ) ? sanitize_title( $p['slug'] ) : '',
        'description' => isset( $p['description'] ) ? wp_kses_post( $p['description'] ) : '',
        'parent'      => isset( $p['parent'] ) ? (int) $p['parent'] : 0,
    ) );
    if ( is_wp_error( $res ) ) {
        return $res;
    }
    if ( isset( $p['ans_page_id'] ) ) {
        update_term_meta( (int) $res['term_id'], 'ans_page_id', (int) $p['ans_page_id'] );
    }
    return ans_tb_term_payload( (int) $res['term_id'] );
}

/** POST /tickera/event-category/{id} */
function ans_tb_update_event_category( $req ) {
    $id = (int) $req['id'];
    $t  = get_term( $id, 'event_category' );
    if ( ! $t || is_wp_error( $t ) ) {
        return new WP_Error( 'not_found', 'No event_category term with that ID.', array( 'status' => 404 ) );
    }
    $p    = ans_tb_params( $req );
    $args = array();
    if ( isset( $p['name'] ) && '' !== $p['name'] ) {
        $args['name'] = sanitize_text_field( $p['name'] );
    }
    if ( isset( $p['slug'] ) && '' !== $p['slug'] ) {
        $args['slug'] = sanitize_title( $p['slug'] );
    }
    if ( isset( $p['description'] ) ) {
        $args['description'] = wp_kses_post( $p['description'] );
    }
    if ( isset( $p['parent'] ) ) {
        $args['parent'] = (int) $p['parent'];
    }
    if ( $args ) {
        $res = wp_update_term( $id, 'event_category', $args );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
    }
    if ( isset( $p['ans_page_id'] ) ) {
        update_term_meta( $id, 'ans_page_id', (int) $p['ans_page_id'] );
    }
    // Optionally re-assign events to this category.
    if ( isset( $p['event_ids'] ) && is_array( $p['event_ids'] ) ) {
        foreach ( $p['event_ids'] as $eid ) {
            $eid = (int) $eid;
            if ( 'tc_events' === get_post_type( $eid ) ) {
                wp_set_object_terms( $eid, array( $id ), 'event_category', false );
            }
        }
    }
    return ans_tb_term_payload( $id );
}

/** DELETE /tickera/event-category/{id} */
function ans_tb_delete_event_category( $req ) {
    $id = (int) $req['id'];
    $t  = get_term( $id, 'event_category' );
    if ( ! $t || is_wp_error( $t ) ) {
        return new WP_Error( 'not_found', 'No event_category term with that ID.', array( 'status' => 404 ) );
    }
    $name = $t->name;
    $res  = wp_delete_term( $id, 'event_category' );
    if ( is_wp_error( $res ) || ! $res ) {
        return new WP_Error( 'delete_failed', 'Could not delete that term.', array( 'status' => 500 ) );
    }
    return array( 'id' => $id, 'name' => $name, 'deleted' => true );
}

function ans_tb_term_payload( $id ) {
    $t = get_term( (int) $id, 'event_category' );
    if ( ! $t || is_wp_error( $t ) ) {
        return array();
    }
    return array(
        'id'          => $t->term_id,
        'name'        => $t->name,
        'slug'        => $t->slug,
        'parent'      => $t->parent,
        'description' => $t->description,
        'count'       => $t->count,
        'ans_page_id' => (int) get_term_meta( $t->term_id, 'ans_page_id', true ),
    );
}

/**
 * GET /tickera/attendees?event_id=&per_page=&page=
 * Issued tickets. Tickera stores these as tc_tickets_instances posts; we read
 * whatever post type is actually present rather than assuming a name.
 */
function ans_tb_list_attendees( $req ) {
    $candidates = array( 'tc_tickets_instances', 'tc_tickets', 'tc_attendees' );
    $pt         = null;
    foreach ( $candidates as $c ) {
        if ( post_type_exists( $c ) ) {
            $pt = $c;
            break;
        }
    }
    if ( ! $pt ) {
        return array(
            'post_type' => null,
            'count'     => 0,
            'attendees' => array(),
            'note'      => 'No Tickera ticket-instance post type found among: ' . implode( ', ', $candidates ) . '. Run /tickera/introspect to see what is registered.',
        );
    }

    $per_page = min( 200, max( 1, (int) $req->get_param( 'per_page' ) ?: 50 ) );
    $page     = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
    $event_id = (int) $req->get_param( 'event_id' );

    $args = array(
        'post_type'      => $pt,
        'post_status'    => 'any',
        'posts_per_page' => $per_page,
        'paged'          => $page,
    );
    if ( $event_id ) {
        $args['meta_query'] = array( array( 'key' => 'event_id', 'value' => $event_id ) );
    }
    $q   = new WP_Query( $args );
    $out = array();
    foreach ( $q->posts as $po ) {
        $meta = array();
        foreach ( (array) get_post_meta( $po->ID ) as $k => $v ) {
            $meta[ $k ] = is_array( $v ) && 1 === count( $v ) ? $v[0] : $v;
        }
        $out[] = array(
            'id'     => $po->ID,
            'title'  => $po->post_title,
            'status' => $po->post_status,
            'meta'   => $meta,
        );
    }
    return array(
        'post_type' => $pt,
        'total'     => (int) $q->found_posts,
        'page'      => $page,
        'per_page'  => $per_page,
        'count'     => count( $out ),
        'attendees' => $out,
    );
}

/* ============================================================================
 * v1.5.0 — targeted content find-and-replace
 *
 * Built because fixing one wrong shortcode across seven concert pages would
 * otherwise mean reading and rewriting every page in full. This does an exact,
 * literal string replacement on named posts only — no regex, no site-wide
 * sweep, no "replace everywhere" mode. dry_run defaults to TRUE so the first
 * call always reports what WOULD change.
 * ========================================================================== */

add_action( 'rest_api_init', function () {
    register_rest_route( ANS_TB_NS, '/content/replace', array(
        'methods'             => 'POST',
        'callback'            => 'ans_tb_content_replace',
        'permission_callback' => 'ans_tb_perm',
    ) );
    register_rest_route( ANS_TB_NS, '/content/find', array(
        'methods'             => 'GET',
        'callback'            => 'ans_tb_content_find',
        'permission_callback' => 'ans_tb_perm',
    ) );
} );

/** GET /content/find?needle=...&post_type=page — which posts contain a literal string. */
function ans_tb_content_find( $req ) {
    global $wpdb;
    $needle = (string) $req->get_param( 'needle' );
    if ( '' === $needle ) {
        return new WP_Error( 'missing_needle', 'needle is required.', array( 'status' => 400 ) );
    }
    $post_type = (string) ( $req->get_param( 'post_type' ) ?: 'page' );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID, post_title, post_status, post_type
               FROM {$wpdb->posts}
              WHERE post_type = %s
                AND post_status NOT IN ('trash','auto-draft','inherit')
                AND post_content LIKE %s
              ORDER BY ID",
            $post_type,
            '%' . $wpdb->esc_like( $needle ) . '%'
        )
    );

    $out = array();
    foreach ( (array) $rows as $r ) {
        $content = get_post_field( 'post_content', $r->ID );
        $out[]   = array(
            'id'          => (int) $r->ID,
            'title'       => $r->post_title,
            'status'      => $r->post_status,
            'occurrences' => substr_count( (string) $content, $needle ),
        );
    }
    return array( 'needle' => $needle, 'post_type' => $post_type, 'count' => count( $out ), 'posts' => $out );
}

/**
 * POST /content/replace
 * Body: { post_ids: [1,2], search: "...", replace: "...", dry_run: true }
 *
 * Exact literal replacement. post_ids is REQUIRED — there is deliberately no
 * way to run this across everything.
 */
function ans_tb_content_replace( $req ) {
    $p       = ans_tb_params( $req );
    $search  = isset( $p['search'] ) ? (string) $p['search'] : '';
    $replace = isset( $p['replace'] ) ? (string) $p['replace'] : '';
    $dry     = array_key_exists( 'dry_run', $p ) ? (bool) $p['dry_run'] : true;

    if ( '' === $search ) {
        return new WP_Error( 'missing_search', 'search is required.', array( 'status' => 400 ) );
    }
    if ( empty( $p['post_ids'] ) || ! is_array( $p['post_ids'] ) ) {
        return new WP_Error( 'missing_post_ids', 'post_ids is required — this endpoint will not run site-wide.', array( 'status' => 400 ) );
    }

    $results = array();
    foreach ( $p['post_ids'] as $raw_id ) {
        $id   = (int) $raw_id;
        $post = get_post( $id );
        if ( ! $post ) {
            $results[] = array( 'id' => $id, 'error' => 'not found' );
            continue;
        }
        $content = (string) $post->post_content;
        $hits    = substr_count( $content, $search );
        if ( 0 === $hits ) {
            $results[] = array( 'id' => $id, 'title' => $post->post_title, 'occurrences' => 0, 'changed' => false );
            continue;
        }
        if ( $dry ) {
            $results[] = array( 'id' => $id, 'title' => $post->post_title, 'occurrences' => $hits, 'changed' => false, 'dry_run' => true );
            continue;
        }
        $updated = str_replace( $search, $replace, $content );
        $res     = wp_update_post( array( 'ID' => $id, 'post_content' => $updated ), true );
        if ( is_wp_error( $res ) ) {
            $results[] = array( 'id' => $id, 'title' => $post->post_title, 'occurrences' => $hits, 'changed' => false, 'error' => $res->get_error_message() );
            continue;
        }
        $results[] = array( 'id' => $id, 'title' => $post->post_title, 'occurrences' => $hits, 'changed' => true );
    }

    $changed = 0;
    foreach ( $results as $r ) {
        if ( ! empty( $r['changed'] ) ) {
            $changed++;
        }
    }
    return array(
        'dry_run'         => $dry,
        'search'          => $search,
        'replace'         => $replace,
        'posts_examined'  => count( $results ),
        'posts_changed'   => $changed,
        'results'         => $results,
    );
}

/* ============================================================================
 * v1.6.0 — CORRECT Tickera 3.6 ticket meta
 *
 * Ground truth, captured 2026-07-31 from a ticket product created through the
 * WooCommerce admin UI (post 6716). These are the exact keys and default values
 * Tickera 3.6.0.0 + Bridge for WooCommerce 1.7.5 writes:
 *
 *   _tc_is_ticket                  yes
 *   _event_name                    <event post ID>
 *   tc_designer_template_id        0            <- NEW Ticket Designer template
 *   _ticket_template               0            <- legacy template field
 *   _available_checkins_per_ticket ''           <- '' = unlimited
 *   _checkins_time_basis           no
 *   _ticket_checkin_availability   open_ended
 *   _allow_ticket_checkout         no
 *   _ticket_availability           open_ended
 *
 * Everything this plugin wrote before v1.6.0 used un-prefixed keys and was
 * therefore invisible to Tickera.
 * ========================================================================== */

/**
 * Write the full, correct Tickera ticket meta set onto a product.
 *
 * @param int   $product_id  WooCommerce product.
 * @param int   $event_id    tc_events post ID.
 * @param int   $template    Ticket Designer template id (0 = default).
 * @param array $p           Optional overrides from the request.
 */
function ans_tb_write_ticket_meta( $product_id, $event_id, $template = 0, $p = array() ) {
    $product_id = (int) $product_id;
    $event_id   = (int) $event_id;

    update_post_meta( $product_id, '_tc_is_ticket', 'yes' );
    update_post_meta( $product_id, '_event_name', $event_id );

    // Two separate template fields. tc_designer_template_id binds to the new
    // Ticket Designer; _ticket_template is the legacy one. 0 means "default".
    update_post_meta( $product_id, 'tc_designer_template_id', (int) $template );
    update_post_meta( $product_id, '_ticket_template', (int) ( isset( $p['legacy_template'] ) ? $p['legacy_template'] : 0 ) );

    // '' = unlimited check-ins, matching the UI default.
    update_post_meta(
        $product_id,
        '_available_checkins_per_ticket',
        isset( $p['checkins_per_ticket'] ) ? sanitize_text_field( (string) $p['checkins_per_ticket'] ) : ''
    );
    update_post_meta(
        $product_id,
        '_checkins_time_basis',
        ! empty( $p['checkins_time_basis'] ) ? 'yes' : 'no'
    );
    update_post_meta(
        $product_id,
        '_ticket_checkin_availability',
        isset( $p['checkin_availability'] ) ? sanitize_text_field( (string) $p['checkin_availability'] ) : 'open_ended'
    );
    update_post_meta(
        $product_id,
        '_allow_ticket_checkout',
        ! empty( $p['allow_ticket_checkout'] ) ? 'yes' : 'no'
    );
    update_post_meta(
        $product_id,
        '_ticket_availability',
        isset( $p['ticket_availability'] ) ? sanitize_text_field( (string) $p['ticket_availability'] ) : 'open_ended'
    );

    // Remove the wrong keys this plugin wrote before v1.6.0 so products are not
    // left carrying both conventions.
    foreach ( array( '_ticket', 'event_name', 'ticket_template' ) as $stale ) {
        delete_post_meta( $product_id, $stale );
    }
}

/**
 * POST /tickera/repair-tickets
 * Body: { product_ids?: [..], dry_run?: bool }
 *
 * Re-stamps correct Tickera 3.6 meta onto ticket products created by earlier
 * versions of this plugin. With no product_ids, it finds every product carrying
 * the OLD `_ticket = yes` flag.
 */
add_action( 'rest_api_init', function () {
    register_rest_route( ANS_TB_NS, '/tickera/repair-tickets', array(
        'methods'             => 'POST',
        'callback'            => 'ans_tb_repair_tickets',
        'permission_callback' => 'ans_tb_perm',
    ) );
} );

function ans_tb_repair_tickets( $req ) {
    $p   = ans_tb_params( $req );
    $dry = array_key_exists( 'dry_run', $p ) ? (bool) $p['dry_run'] : true;

    if ( ! empty( $p['product_ids'] ) && is_array( $p['product_ids'] ) ) {
        $ids = array_map( 'intval', $p['product_ids'] );
    } else {
        $ids = get_posts( array(
            'post_type'   => 'product',
            'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
            'numberposts' => 300,
            'fields'      => 'ids',
            'meta_query'  => array( array( 'key' => '_ticket', 'value' => 'yes' ) ),
        ) );
    }

    $out = array();
    foreach ( $ids as $id ) {
        if ( 'product' !== get_post_type( $id ) ) {
            $out[] = array( 'id' => $id, 'error' => 'not a product' );
            continue;
        }
        $event_id = (int) get_post_meta( $id, 'event_name', true );
        if ( ! $event_id ) {
            $event_id = (int) get_post_meta( $id, '_event_name', true );
        }
        $template = (int) get_post_meta( $id, 'tc_designer_template_id', true );

        if ( $dry ) {
            $out[] = array(
                'id'       => $id,
                'name'     => get_the_title( $id ),
                'event_id' => $event_id,
                'would_fix' => true,
            );
            continue;
        }
        ans_tb_write_ticket_meta( $id, $event_id, $template, array() );
        $out[] = array( 'id' => $id, 'name' => get_the_title( $id ), 'event_id' => $event_id, 'fixed' => true );
    }

    return array( 'dry_run' => $dry, 'count' => count( $out ), 'results' => $out );
}

/* ============================================================================
 * v1.7.0 — bulk ticket-tier creation
 *
 * A 13-performance season with three tiers each is ~39 products. One call.
 * Idempotent on SKU: if a product with the same SKU already exists it is
 * UPDATED rather than duplicated, so re-running never creates a second set.
 * ========================================================================== */

add_action( 'rest_api_init', function () {
    register_rest_route( ANS_TB_NS, '/tickera/bulk-ticket-types', array(
        'methods'             => 'POST',
        'callback'            => 'ans_tb_bulk_ticket_types',
        'permission_callback' => 'ans_tb_perm',
    ) );
} );

/** Find an existing product by SKU without relying on wc_get_product_id_by_sku. */
function ans_tb_product_id_by_sku( $sku ) {
    if ( '' === $sku ) {
        return 0;
    }
    if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
        return (int) wc_get_product_id_by_sku( $sku );
    }
    global $wpdb;
    return (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1", $sku )
    );
}

/**
 * POST /tickera/bulk-ticket-types
 * Body: { dry_run?: bool, items: [ { event_id, name, price, sku?, stock?, status?, template?, short_description? } ] }
 */
function ans_tb_bulk_ticket_types( $req ) {
    if ( ! class_exists( 'WC_Product_Simple' ) ) {
        return new WP_Error( 'woo_inactive', 'WooCommerce is not active.', array( 'status' => 400 ) );
    }
    $p     = ans_tb_params( $req );
    $dry   = array_key_exists( 'dry_run', $p ) ? (bool) $p['dry_run'] : true;
    $items = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();

    if ( empty( $items ) ) {
        return new WP_Error( 'missing_items', 'items array is required.', array( 'status' => 400 ) );
    }

    $out = array();
    foreach ( $items as $it ) {
        $event_id = isset( $it['event_id'] ) ? (int) $it['event_id'] : 0;
        $name     = isset( $it['name'] ) ? sanitize_text_field( $it['name'] ) : '';
        $sku      = isset( $it['sku'] ) ? sanitize_text_field( $it['sku'] ) : '';

        if ( ! $event_id || 'tc_events' !== get_post_type( $event_id ) ) {
            $out[] = array( 'name' => $name, 'error' => 'event_id does not reference a tc_events post' );
            continue;
        }
        if ( '' === $name ) {
            $out[] = array( 'sku' => $sku, 'error' => 'name is required' );
            continue;
        }

        $existing = ans_tb_product_id_by_sku( $sku );

        if ( $dry ) {
            $out[] = array(
                'name'     => $name,
                'sku'      => $sku,
                'event_id' => $event_id,
                'action'   => $existing ? 'would update #' . $existing : 'would create',
            );
            continue;
        }

        $product = $existing ? wc_get_product( $existing ) : new WC_Product_Simple();
        if ( ! $product ) {
            $product = new WC_Product_Simple();
        }
        $product->set_name( $name );
        $product->set_status( isset( $it['status'] ) && in_array( $it['status'], array( 'publish', 'draft' ), true ) ? $it['status'] : 'draft' );
        $product->set_catalog_visibility( 'visible' );
        $product->set_virtual( true );
        if ( isset( $it['price'] ) && '' !== $it['price'] ) {
            $product->set_regular_price( (string) $it['price'] );
        }
        if ( ! empty( $it['short_description'] ) ) {
            $product->set_short_description( wp_kses_post( $it['short_description'] ) );
        }
        if ( $sku && ! $existing ) {
            try {
                $product->set_sku( $sku );
            } catch ( Exception $e ) { /* duplicate SKU — ignore */ }
        }
        if ( array_key_exists( 'stock', $it ) && '' !== $it['stock'] && null !== $it['stock'] ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( (int) $it['stock'] );
            $product->set_stock_status( 'instock' );
        } else {
            $product->set_manage_stock( false );
            $product->set_stock_status( 'instock' );
        }

        $pid = $product->save();
        if ( ! $pid ) {
            $out[] = array( 'name' => $name, 'error' => 'save failed' );
            continue;
        }

        ans_tb_write_ticket_meta( $pid, $event_id, isset( $it['template'] ) ? (int) $it['template'] : 0, array() );

        $out[] = array(
            'id'       => (int) $pid,
            'name'     => $name,
            'sku'      => $sku,
            'event_id' => $event_id,
            'price'    => isset( $it['price'] ) ? (string) $it['price'] : '',
            'action'   => $existing ? 'updated' : 'created',
        );
    }

    $created = 0;
    $updated = 0;
    foreach ( $out as $r ) {
        if ( isset( $r['action'] ) && 'created' === $r['action'] ) {
            $created++;
        }
        if ( isset( $r['action'] ) && 'updated' === $r['action'] ) {
            $updated++;
        }
    }
    return array( 'dry_run' => $dry, 'total' => count( $out ), 'created' => $created, 'updated' => $updated, 'results' => $out );
}

/* v1.7.0 - WooCommerce product category management (see includes/). */
require_once plugin_dir_path( __FILE__ ) . 'includes/product-categories.php';

/* v1.8.0 - season packages purchase page shortcode (see includes/). */
require_once plugin_dir_path( __FILE__ ) . 'includes/season-packages.php';

/* v1.8.2 - bulk post meta (see includes/). */
require_once plugin_dir_path( __FILE__ ) . 'includes/bulk-meta.php';

/**
 * Event Tickets block - the concert-page ticket picker. Replaces the three
 * separate [tc_wb_event] tables (and their nine add-to-cart buttons) with one
 * date-box picker, tier options revealed below, and a single add-to-cart.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/event-tickets-block.php';

/**
 * v1.9.5 - the buyer's real Tickera ticket PDFs as WooCommerce downloads, so
 * ticket orders auto-complete instead of parking at `processing` forever.
 * Pairs with the products being flagged downloadable; neither half works alone.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/ticket-downloads.php';

/* v1.9.5 - read/write the Tickera Mailchimp add-on settings (API key excluded). */
require_once plugin_dir_path( __FILE__ ) . 'includes/mailchimp-settings.php';

/**
 * v1.9.6 - read and surgically edit Flycart discount rules. Built after a real
 * customer was charged $176 for a $160 package because the rules enumerate
 * product IDs (categories are a PRO feature) and went stale when Springs & Gears
 * was rebuilt. Until now they had no API surface at all and could not be audited.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/discount-rules.php';

/**
 * v1.14.0 - [ans_circle_lineup], the Nova Circle benefit lineup for
 * /nova-circle/. Selects by PERK TIER rather than by ans_event_kind, because a
 * Circle benefit can be a dedicated perk event OR a pre-concert talk marked on
 * an ordinary concert night, and both have to appear in the same list.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/circle-lineup.php';
