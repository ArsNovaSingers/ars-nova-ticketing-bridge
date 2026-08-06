<?php
/**
 * Ars Nova Ticketing Bridge — bulk post meta.
 *
 * Why this exists: setting one meta key across many products had no route.
 * The connector's wp_bulk_update_posts writes through wp/v2/posts, which does
 * not serve WooCommerce products — every row returns rest_post_invalid_id, and
 * its dry-run reports success anyway because it never touches the endpoint.
 * ans_rest_call is fenced to our own namespaces so it cannot reach wc/v3.
 *
 * First need: featured images (_thumbnail_id) on 42 ticket products, which
 * were rendering as blank placeholders in the cart and at checkout.
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route( ANS_TB_NS, '/bulk-post-meta', array(
        'methods'             => 'POST',
        'callback'            => 'ans_tb_bulk_post_meta',
        'permission_callback' => 'ans_tb_perm',
    ) );
} );

/**
 * POST /bulk-post-meta
 *
 * Body: {
 *   dry_run?: bool            default TRUE — must pass false to write
 *   post_type?: string        optional guard, e.g. "product". Rows whose post
 *                             is a different type are refused, not silently skipped.
 *   items: [ { id, meta: { key: value, ... } } ]
 * }
 *
 * A real dry run: it resolves every post and reports the before/after it WOULD
 * write, and writes nothing. Unlike wp_bulk_update_posts, a green dry run here
 * means the write will actually land.
 */
function ans_tb_bulk_post_meta( $req ) {
    $p    = ans_tb_params( $req );
    $dry  = array_key_exists( 'dry_run', $p ) ? (bool) filter_var( $p['dry_run'], FILTER_VALIDATE_BOOLEAN ) : true;
    $type = isset( $p['post_type'] ) ? sanitize_key( $p['post_type'] ) : '';

    $items = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();
    if ( empty( $items ) ) {
        return new WP_Error( 'missing_items', 'items[] is required.', array( 'status' => 400 ) );
    }

    $results = array();
    $changed = 0;

    foreach ( $items as $item ) {
        $id = isset( $item['id'] ) ? (int) $item['id'] : 0;
        if ( ! $id || ! get_post( $id ) ) {
            $results[] = array( 'id' => $id, 'error' => 'No post with that ID.' );
            continue;
        }
        if ( $type && get_post_type( $id ) !== $type ) {
            $results[] = array( 'id' => $id, 'error' => 'Expected post_type ' . $type . ', got ' . get_post_type( $id ) . '.' );
            continue;
        }
        $meta = isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array();
        if ( empty( $meta ) ) {
            $results[] = array( 'id' => $id, 'error' => 'No meta supplied for this row.' );
            continue;
        }

        $row = array( 'id' => $id, 'name' => get_the_title( $id ), 'fields' => array() );
        $row_changed = false;

        foreach ( $meta as $key => $value ) {
            $key = sanitize_key( $key );
            if ( '' === $key ) {
                continue;
            }
            $before = get_post_meta( $id, $key, true );
            $after  = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $value;

            if ( (string) $before !== (string) $after ) {
                $row_changed = true;
                if ( ! $dry ) {
                    update_post_meta( $id, $key, $after );
                }
            }
            $row['fields'][ $key ] = array( 'before' => $before, 'after' => $after );
        }

        $row['action'] = $row_changed ? ( $dry ? 'would_change' : 'changed' ) : 'no_change';
        if ( $row_changed ) {
            $changed++;
        }
        $results[] = $row;
    }

    if ( ! $dry ) {
        // Featured images are cached in the product lookup/thumbnail caches.
        if ( function_exists( 'wc_delete_product_transients' ) ) {
            foreach ( $results as $r ) {
                if ( isset( $r['id'] ) && 'product' === get_post_type( $r['id'] ) ) {
                    wc_delete_product_transients( (int) $r['id'] );
                }
            }
        }
        clean_post_cache_bulk( wp_list_pluck( array_filter( $results, function ( $r ) {
            return isset( $r['id'] ) && empty( $r['error'] );
        } ), 'id' ) );
    }

    return array(
        'dry_run' => $dry,
        'total'   => count( $results ),
        'changed' => $changed,
        'note'    => $dry ? 'DRY RUN — nothing written. Re-send with dry_run: false.' : 'Applied.',
        'results' => $results,
    );
}

/** clean_post_cache() over a list, guarding against an empty set. */
function clean_post_cache_bulk( $ids ) {
    foreach ( (array) $ids as $id ) {
        if ( $id ) {
            clean_post_cache( (int) $id );
        }
    }
}
