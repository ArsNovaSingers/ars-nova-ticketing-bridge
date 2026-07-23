<?php
/**
 * Plugin Name: Ars Nova Ticketing Bridge
 * Description: Admin-only REST endpoints that let the Ars Nova WordPress MCP connector create & list Tickera events and Bridge ticket-type products by command. Writes the same post/meta the Tickera + WooCommerce Bridge admin UI writes. DEV automation helper.
 * Version: 1.0.0
 * Author: Ars Nova (Jonathan Raabe) + Claude
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ANS_TB_VERSION', '1.0.0' );
define( 'ANS_TB_NS', 'ars-nova/v1' );

/** Permission gate: admin only (connector authenticates as an admin app-password user). */
function ans_tb_perm() {
    return current_user_can( 'manage_options' );
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
        'methods'             => 'GET',
        'callback'            => 'ans_tb_get_event',
        'permission_callback' => 'ans_tb_perm',
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

/** Normalize a date string to Tickera's 'Y-m-d H:i' storage format. */
function ans_tb_norm_date( $value ) {
    $ts = strtotime( (string) $value );
    return $ts ? gmdate( 'Y-m-d H:i', $ts ) : '';
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
    $product->set_virtual( true );
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

    // Bridge wiring: these four metas are exactly what the Bridge product-save writes.
    update_post_meta( $product_id, '_ticket', 'yes' );
    update_post_meta( $product_id, 'event_name', (int) $event_id );
    update_post_meta( $product_id, '_ticket_availability', 'open_ended' );
    if ( $template ) {
        update_post_meta( $product_id, 'ticket_template', (int) $template );
    }

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
