<?php
/**
 * Ars Nova Ticketing Bridge - read and surgically edit Flycart discount rules.
 *
 * WHY THIS EXISTS
 * ---------------
 * On 2026-08-18 a real customer bought one Adult ticket to each of the five
 * mainstage concerts and was charged $176 instead of $160. The rules were not
 * broken. They were correct on 2026-08-06 when they were written, and they were
 * never touched again - while on 2026-08-10 Springs & Gears was rebuilt from one
 * performance into four, creating three brand-new products. Discount Rules free
 * cannot target categories (that is a PRO feature), so its rules enumerate
 * PRODUCT IDS. The new products were not in the list. The cart saw four
 * qualifying concerts instead of five: no 20% tier, and no discount at all on
 * the Springs & Gears ticket.
 *
 * Nothing surfaced an error. Nothing could have - from the plugin's point of
 * view a product simply was not in a list.
 *
 * That is a standing hazard, not a one-off: every time a performance is added or
 * a product rebuilt, these rules silently go stale and the failure lands on a
 * customer's card. It had no API surface at all, so it could not be audited,
 * diffed, or fixed from anywhere but a browser. Now it can.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 * ----------------------------------
 * It does not create, delete or reprice rules, and it does not touch discount
 * amounts. The only mutation is the membership of a `products` filter - the
 * exact thing that goes stale. Everything else stays in wp-admin where a human
 * looks at it. Writes default to a dry run.
 *
 * STORAGE, verified against plugin source (v2.6.16, `load_version` = v2):
 *   table  {prefix}wdr_rules
 *   filters/conditions/*_adjustments are plain JSON (not serialized)
 *   a products filter entry:
 *     {"type":"products","method":"in_list","value":["123","456"],
 *      "product_variants":[],"product_variants_for_sale_badge":[]}
 *   `deleted` is a soft-delete flag; there is no hard delete anywhere.
 *   There is NO persistent rule cache - a write lands on the next request.
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.9.6
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The Flycart rules table.
 *
 * @return string
 */
function ans_tb_wdr_table() {
    global $wpdb;
    return $wpdb->prefix . 'wdr_rules';
}

/**
 * Is Discount Rules present at all?
 *
 * @return bool
 */
function ans_tb_wdr_available() {
    global $wpdb;
    $table = ans_tb_wdr_table();
    return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore
}

/**
 * Decode a rule row into something readable.
 *
 * json_decode with assoc=true throughout: the plugin's own consumers take
 * stdClass, but we are reporting, not feeding its engine, and arrays survive
 * wp_json_encode round-tripping without surprises.
 *
 * @param object $row Raw DB row.
 * @return array
 */
function ans_tb_wdr_decode( $row ) {
    $json = function ( $v ) {
        if ( null === $v || '' === $v ) {
            return null;
        }
        $d = json_decode( $v, true );
        return ( JSON_ERROR_NONE === json_last_error() ) ? $d : $v;
    };

    return array(
        'id'                  => (int) $row->id,
        'title'               => $row->title,
        'enabled'             => (int) $row->enabled,
        'deleted'             => (int) $row->deleted,
        'exclusive'           => (int) $row->exclusive,
        'priority'            => null === $row->priority ? null : (int) $row->priority,
        'discount_type'       => $row->discount_type,
        'date_from'           => $row->date_from ? (int) $row->date_from : null,
        'date_to'             => $row->date_to ? (int) $row->date_to : null,
        'usage_limits'        => null === $row->usage_limits ? null : (int) $row->usage_limits,
        'used_limits'         => null === $row->used_limits ? null : (int) $row->used_limits,
        'filters'             => $json( $row->filters ),
        'conditions'          => $json( $row->conditions ),
        'product_adjustments' => $json( $row->product_adjustments ),
        'cart_adjustments'    => $json( $row->cart_adjustments ),
        'bulk_adjustments'    => $json( $row->bulk_adjustments ),
        'created_on'          => $row->created_on,
        'modified_on'         => $row->modified_on,
    );
}

/**
 * Fetch one rule by ID.
 *
 * Queried directly rather than through DBTable::getRules(). That method branches
 * on is_admin(), which is FALSE inside a REST request, so it would quietly
 * ignore the id and hand back the whole active rule set instead. Exactly the
 * class of silent wrong-answer this branch's HANDOFF is full of.
 *
 * @param int $id Rule ID.
 * @return object|null
 */
function ans_tb_wdr_row( $id ) {
    global $wpdb;
    $table = ans_tb_wdr_table();
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore
}

/**
 * Variation IDs for any variable products in a list.
 *
 * The plugin stores this alongside the product list and merges it at match
 * time; omitting it means variations of a variable product silently miss the
 * discount. Our tickets are simple products today, so this is usually empty -
 * which is precisely why it would be easy to get wrong later and never notice.
 *
 * @param array $product_ids Product IDs.
 * @return int[]
 */
function ans_tb_wdr_variants( $product_ids ) {
    $out = array();
    if ( ! function_exists( 'wc_get_product' ) ) {
        return $out;
    }
    foreach ( (array) $product_ids as $pid ) {
        $product = wc_get_product( (int) $pid );
        if ( $product && $product->is_type( 'variable' ) ) {
            foreach ( $product->get_children() as $child ) {
                $out[] = (int) $child;
            }
        }
    }
    return array_values( array_unique( $out ) );
}

add_action( 'rest_api_init', function () {

    /* GET /discount-rules - every rule, decoded. */
    register_rest_route( ANS_TB_NS, '/discount-rules', array(
        'methods'             => 'GET',
        'permission_callback' => 'ans_tb_perm',
        'callback'            => 'ans_tb_wdr_list',
    ) );

    /* GET /discount-rule/{id} */
    register_rest_route( ANS_TB_NS, '/discount-rule/(?P<id>\d+)', array(
        'methods'             => 'GET',
        'permission_callback' => 'ans_tb_perm',
        'callback'            => 'ans_tb_wdr_get',
    ) );

    /* POST /discount-rule/{id}/filter-products */
    register_rest_route( ANS_TB_NS, '/discount-rule/(?P<id>\d+)/filter-products', array(
        'methods'             => 'POST',
        'permission_callback' => 'ans_tb_perm',
        'callback'            => 'ans_tb_wdr_set_filter_products',
    ) );
} );

/**
 * GET /discount-rules
 *
 * @param WP_REST_Request $req Request.
 * @return array|WP_Error
 */
function ans_tb_wdr_list( $req ) {
    global $wpdb;
    if ( ! ans_tb_wdr_available() ) {
        return new WP_Error( 'wdr_missing', 'Discount Rules table not found - is the plugin installed?', array( 'status' => 404 ) );
    }
    $include_deleted = filter_var( $req->get_param( 'include_deleted' ), FILTER_VALIDATE_BOOLEAN );
    $table           = ans_tb_wdr_table();
    $sql             = $include_deleted
        ? "SELECT * FROM {$table} ORDER BY priority ASC, id ASC"
        : "SELECT * FROM {$table} WHERE deleted = 0 ORDER BY priority ASC, id ASC";
    $rows = $wpdb->get_results( $sql ); // phpcs:ignore

    $rules = array();
    foreach ( (array) $rows as $row ) {
        $rules[] = ans_tb_wdr_decode( $row );
    }
    return array(
        'count' => count( $rules ),
        'rules' => $rules,
        'note'  => 'Discount Rules free cannot filter by product category - rules enumerate product IDs, so they go stale whenever products are rebuilt.',
    );
}

/**
 * GET /discount-rule/{id}
 *
 * @param WP_REST_Request $req Request.
 * @return array|WP_Error
 */
function ans_tb_wdr_get( $req ) {
    if ( ! ans_tb_wdr_available() ) {
        return new WP_Error( 'wdr_missing', 'Discount Rules table not found.', array( 'status' => 404 ) );
    }
    $row = ans_tb_wdr_row( (int) $req['id'] );
    if ( ! $row ) {
        return new WP_Error( 'wdr_not_found', 'No such rule.', array( 'status' => 404 ) );
    }
    return ans_tb_wdr_decode( $row );
}

/**
 * POST /discount-rule/{id}/filter-products
 *
 * Body: mode = add | remove | replace   (default add)
 *       product_ids = int[]             (required)
 *       filter_key  = string            (optional; which filter entry, if >1)
 *       dry_run     = bool              (DEFAULT TRUE)
 *
 * Rewrites only the `filters` column, leaving every other column and every
 * non-products filter entry byte-identical. Dry run is the default because this
 * changes what customers are charged, and the last time these rules were wrong
 * nobody found out until money had moved.
 *
 * @param WP_REST_Request $req Request.
 * @return array|WP_Error
 */
function ans_tb_wdr_set_filter_products( $req ) {
    global $wpdb;

    if ( ! ans_tb_wdr_available() ) {
        return new WP_Error( 'wdr_missing', 'Discount Rules table not found.', array( 'status' => 404 ) );
    }

    $id  = (int) $req['id'];
    $row = ans_tb_wdr_row( $id );
    if ( ! $row ) {
        return new WP_Error( 'wdr_not_found', 'No such rule.', array( 'status' => 404 ) );
    }

    $p   = ans_tb_params( $req );
    $ids = isset( $p['product_ids'] ) ? (array) $p['product_ids'] : array();
    $ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
    if ( empty( $ids ) ) {
        return new WP_Error( 'missing_products', 'product_ids is required and must contain at least one product ID.', array( 'status' => 400 ) );
    }

    $mode = isset( $p['mode'] ) ? (string) $p['mode'] : 'add';
    if ( ! in_array( $mode, array( 'add', 'remove', 'replace' ), true ) ) {
        return new WP_Error( 'bad_mode', 'mode must be add, remove or replace.', array( 'status' => 400 ) );
    }
    $dry_run = array_key_exists( 'dry_run', $p )
        ? filter_var( $p['dry_run'], FILTER_VALIDATE_BOOLEAN )
        : true;

    $filters = json_decode( (string) $row->filters, true );
    if ( ! is_array( $filters ) ) {
        return new WP_Error( 'bad_filters', 'This rule has no decodable filters.', array( 'status' => 409 ) );
    }

    /* Locate the products filter. */
    $wanted = isset( $p['filter_key'] ) ? (string) $p['filter_key'] : null;
    $target = null;
    $found  = array();
    foreach ( $filters as $k => $f ) {
        if ( isset( $f['type'] ) && 'products' === $f['type'] ) {
            $found[] = (string) $k;
            if ( null === $target && ( null === $wanted || (string) $k === $wanted ) ) {
                $target = (string) $k;
            }
        }
    }
    if ( null === $target ) {
        $types = array();
        foreach ( $filters as $f ) {
            $types[] = isset( $f['type'] ) ? $f['type'] : null;
        }
        return new WP_Error( 'no_products_filter', 'This rule has no "products" filter to edit.', array(
            'status'       => 409,
            'filter_types' => $types,
        ) );
    }
    if ( count( $found ) > 1 && null === $wanted ) {
        return new WP_Error( 'ambiguous_filter', 'This rule has more than one products filter; pass filter_key to say which.', array(
            'status'      => 409,
            'filter_keys' => $found,
        ) );
    }

    $before = isset( $filters[ $target ]['value'] ) ? array_map( 'strval', (array) $filters[ $target ]['value'] ) : array();
    $add    = array_map( 'strval', $ids );

    switch ( $mode ) {
        case 'replace':
            $after = $add;
            break;
        case 'remove':
            $after = array_values( array_diff( $before, $add ) );
            break;
        default:
            $after = array_values( array_unique( array_merge( $before, $add ) ) );
    }

    sort( $after, SORT_NUMERIC );

    $result = array(
        'rule_id'    => $id,
        'rule_title' => $row->title,
        'filter_key' => $target,
        'mode'       => $mode,
        'before'     => $before,
        'after'      => $after,
        'added'      => array_values( array_diff( $after, $before ) ),
        'removed'    => array_values( array_diff( $before, $after ) ),
        'dry_run'    => $dry_run,
    );

    if ( empty( $result['added'] ) && empty( $result['removed'] ) ) {
        $result['ok']      = true;
        $result['message'] = 'No change - the filter already matches.';
        return $result;
    }

    if ( $dry_run ) {
        $result['ok']      = true;
        $result['message'] = 'Dry run. Nothing was written. Resend with dry_run=false to apply.';
        return $result;
    }

    $filters[ $target ]['value']                           = $after;
    $filters[ $target ]['product_variants']                = ans_tb_wdr_variants( $after );
    $filters[ $target ]['product_variants_for_sale_badge'] = isset( $filters[ $target ]['product_variants_for_sale_badge'] )
        ? $filters[ $target ]['product_variants_for_sale_badge']
        : array();
    if ( ! isset( $filters[ $target ]['method'] ) ) {
        $filters[ $target ]['method'] = 'in_list';
    }

    $encoded = wp_json_encode( $filters );
    if ( ! is_string( $encoded ) ) {
        return new WP_Error( 'encode_failed', 'Could not re-encode the filters.', array( 'status' => 500 ) );
    }

    $table   = ans_tb_wdr_table();
    $written = $wpdb->update( // phpcs:ignore
        $table,
        array(
            'filters'     => $encoded,
            'modified_on' => current_time( 'mysql' ),
        ),
        array( 'id' => $id ),
        array( '%s', '%s' ),
        array( '%d' )
    );

    /* Read back. A row count of 0 is not proof of failure and not proof of
       success either, so verify against what is actually stored. */
    $verify_row = ans_tb_wdr_row( $id );
    $verify     = $verify_row ? json_decode( (string) $verify_row->filters, true ) : null;
    $stored     = ( is_array( $verify ) && isset( $verify[ $target ]['value'] ) )
        ? array_map( 'strval', (array) $verify[ $target ]['value'] )
        : array();

    $result['rows_affected'] = ( false === $written ) ? null : (int) $written;
    $result['stored']        = $stored;
    $result['ok']            = ( $stored === $after );
    $result['message']       = $result['ok']
        ? 'Applied and verified by reading the row back.'
        : 'WRITE DID NOT VERIFY - the stored value does not match what was sent. Do not assume the discount is fixed.';
    $result['next']          = 'Clear the Kinsta full-page cache, then re-test a real cart. The plugin itself caches nothing.';

    return $result;
}
