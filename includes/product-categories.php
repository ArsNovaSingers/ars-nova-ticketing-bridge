<?php
/**
 * Ars Nova Ticketing Bridge — WooCommerce product category management.
 *
 * Split into includes/ rather than appended to the main file: the main file is
 * already ~81KB and single-purpose additions are easier to review and revert
 * from their own file.
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ==========================================================================
 * v1.7.0 — WooCommerce product categories (product_cat)
 *
 * Why this exists: the connector could create and update ticket products but
 * had no way to CATEGORISE them. wp_create_category / wp_bulk_assign_terms
 * operate on the post 'category'/'post_tag' taxonomies, wc_update_product
 * exposes no categories field, and ans_rest_call is (correctly) fenced to our
 * own namespaces so it cannot reach wc/v3/products/categories.
 *
 * Needed for season packages (TSK-0140): a cart discount rule groups concerts
 * by WooCommerce product category, and Tickera event categories are a
 * completely separate taxonomy (Tickera_Wiki_01 trap #8).
 *
 * Routes are under /wc/ rather than /tickera/ because product_cat is
 * WooCommerce's taxonomy, not Tickera's.
 * ========================================================================== */

add_action( 'rest_api_init', 'ans_tb_register_v170_routes' );

function ans_tb_register_v170_routes() {
    register_rest_route( ANS_TB_NS, '/wc/product-categories', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'ans_tb_list_product_categories',
            'permission_callback' => 'ans_tb_perm',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'ans_tb_create_product_category',
            'permission_callback' => 'ans_tb_perm',
        ),
    ) );
    register_rest_route( ANS_TB_NS, '/wc/product-category/(?P<id>\d+)', array(
        array(
            'methods'             => 'POST, PUT, PATCH',
            'callback'            => 'ans_tb_update_product_category',
            'permission_callback' => 'ans_tb_perm',
        ),
        array(
            'methods'             => 'DELETE',
            'callback'            => 'ans_tb_delete_product_category',
            'permission_callback' => 'ans_tb_perm',
        ),
    ) );
    register_rest_route( ANS_TB_NS, '/wc/assign-product-categories', array(
        'methods'             => 'POST',
        'callback'            => 'ans_tb_assign_product_categories',
        'permission_callback' => 'ans_tb_perm',
    ) );
}

/** Guard: product_cat only exists when WooCommerce is active. */
function ans_tb_pc_guard() {
    if ( ! taxonomy_exists( 'product_cat' ) ) {
        return new WP_Error(
            'no_taxonomy',
            'product_cat taxonomy is not registered — is WooCommerce active?',
            array( 'status' => 400 )
        );
    }
    return true;
}

/** Uniform term payload for product_cat. */
function ans_tb_pc_payload( $term_id ) {
    $t = get_term( (int) $term_id, 'product_cat' );
    if ( ! $t || is_wp_error( $t ) ) {
        return array( 'id' => (int) $term_id, 'error' => 'term not found' );
    }
    return array(
        'id'          => (int) $t->term_id,
        'name'        => $t->name,
        'slug'        => $t->slug,
        'parent'      => (int) $t->parent,
        'description' => $t->description,
        'count'       => (int) $t->count,
    );
}

/**
 * Resolve a category reference to a term ID.
 * Accepts a numeric term ID, or a name/slug. Creates the term when
 * $auto_create is true and nothing matches.
 */
function ans_tb_pc_resolve( $ref, $auto_create = true, $parent = 0 ) {
    if ( is_numeric( $ref ) ) {
        $t = get_term( (int) $ref, 'product_cat' );
        if ( $t && ! is_wp_error( $t ) ) {
            return (int) $t->term_id;
        }
        return new WP_Error( 'no_term', 'No product_cat term with ID ' . (int) $ref . '.', array( 'status' => 404 ) );
    }

    $name = trim( (string) $ref );
    if ( '' === $name ) {
        return new WP_Error( 'bad_term', 'Empty category reference.', array( 'status' => 400 ) );
    }

    $t = get_term_by( 'slug', sanitize_title( $name ), 'product_cat' );
    if ( ! $t ) {
        $t = get_term_by( 'name', $name, 'product_cat' );
    }
    if ( $t ) {
        return (int) $t->term_id;
    }

    if ( ! $auto_create ) {
        return new WP_Error(
            'no_term',
            'No product_cat term named "' . $name . '" and auto_create is off.',
            array( 'status' => 404 )
        );
    }

    $res = wp_insert_term( $name, 'product_cat', array( 'parent' => (int) $parent ) );
    if ( is_wp_error( $res ) ) {
        return $res;
    }
    return (int) $res['term_id'];
}

/** Normalise a categories field: array, or comma-separated string. */
function ans_tb_pc_to_list( $value ) {
    if ( is_array( $value ) ) {
        return $value;
    }
    if ( is_string( $value ) && '' !== trim( $value ) ) {
        return array_map( 'trim', explode( ',', $value ) );
    }
    return array();
}

/** GET /wc/product-categories */
function ans_tb_list_product_categories( $req ) {
    $guard = ans_tb_pc_guard();
    if ( is_wp_error( $guard ) ) {
        return $guard;
    }
    $p     = ans_tb_params( $req );
    $terms = get_terms( array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => ! empty( $p['hide_empty'] ),
    ) );
    if ( is_wp_error( $terms ) ) {
        return $terms;
    }
    $out = array();
    foreach ( $terms as $t ) {
        $out[] = ans_tb_pc_payload( $t->term_id );
    }
    return array( 'count' => count( $out ), 'categories' => $out );
}

/** POST /wc/product-categories — body: { name, slug?, parent?, description? } */
function ans_tb_create_product_category( $req ) {
    $guard = ans_tb_pc_guard();
    if ( is_wp_error( $guard ) ) {
        return $guard;
    }
    $p    = ans_tb_params( $req );
    $name = isset( $p['name'] ) ? sanitize_text_field( $p['name'] ) : '';
    if ( '' === $name ) {
        return new WP_Error( 'missing_name', 'name is required.', array( 'status' => 400 ) );
    }

    // Idempotent: return the existing term rather than erroring on re-run.
    $existing = get_term_by( 'slug', sanitize_title( isset( $p['slug'] ) && '' !== $p['slug'] ? $p['slug'] : $name ), 'product_cat' );
    if ( ! $existing ) {
        $existing = get_term_by( 'name', $name, 'product_cat' );
    }
    if ( $existing ) {
        $payload             = ans_tb_pc_payload( $existing->term_id );
        $payload['action']   = 'existing';
        return $payload;
    }

    $res = wp_insert_term( $name, 'product_cat', array(
        'slug'        => isset( $p['slug'] ) ? sanitize_title( $p['slug'] ) : '',
        'description' => isset( $p['description'] ) ? wp_kses_post( $p['description'] ) : '',
        'parent'      => isset( $p['parent'] ) ? (int) $p['parent'] : 0,
    ) );
    if ( is_wp_error( $res ) ) {
        return $res;
    }
    $payload           = ans_tb_pc_payload( (int) $res['term_id'] );
    $payload['action'] = 'created';
    return $payload;
}

/** POST /wc/product-category/{id} */
function ans_tb_update_product_category( $req ) {
    $guard = ans_tb_pc_guard();
    if ( is_wp_error( $guard ) ) {
        return $guard;
    }
    $id = (int) $req['id'];
    $t  = get_term( $id, 'product_cat' );
    if ( ! $t || is_wp_error( $t ) ) {
        return new WP_Error( 'not_found', 'No product_cat term with that ID.', array( 'status' => 404 ) );
    }
    $p    = ans_tb_params( $req );
    $args = array();
    if ( isset( $p['name'] ) ) {
        $args['name'] = sanitize_text_field( $p['name'] );
    }
    if ( isset( $p['slug'] ) ) {
        $args['slug'] = sanitize_title( $p['slug'] );
    }
    if ( isset( $p['description'] ) ) {
        $args['description'] = wp_kses_post( $p['description'] );
    }
    if ( isset( $p['parent'] ) ) {
        $args['parent'] = (int) $p['parent'];
    }
    if ( empty( $args ) ) {
        return new WP_Error( 'nothing_to_do', 'No updatable fields supplied.', array( 'status' => 400 ) );
    }
    $res = wp_update_term( $id, 'product_cat', $args );
    if ( is_wp_error( $res ) ) {
        return $res;
    }
    return ans_tb_pc_payload( $id );
}

/** DELETE /wc/product-category/{id} */
function ans_tb_delete_product_category( $req ) {
    $guard = ans_tb_pc_guard();
    if ( is_wp_error( $guard ) ) {
        return $guard;
    }
    $id = (int) $req['id'];
    $t  = get_term( $id, 'product_cat' );
    if ( ! $t || is_wp_error( $t ) ) {
        return new WP_Error( 'not_found', 'No product_cat term with that ID.', array( 'status' => 404 ) );
    }
    $name = $t->name;
    $res  = wp_delete_term( $id, 'product_cat' );
    if ( is_wp_error( $res ) ) {
        return $res;
    }
    return array( 'deleted' => true, 'id' => $id, 'name' => $name );
}

/**
 * POST /wc/assign-product-categories
 *
 * Body: {
 *   dry_run?:     bool    default TRUE — must pass false explicitly to write
 *   mode?:        "replace" | "append" | "remove"   default "replace"
 *   auto_create?: bool    default true — create categories named but absent
 *   parent?:      int     parent term for anything auto-created
 *   items: [ { product_id? | sku?, categories: ["Name"|123, ...] | "A,B" } ]
 * }
 *
 * "replace" REPLACES every product_cat on the product (this is what drops
 * "Uncategorized"). "append" adds without removing. "remove" detaches only the
 * named categories.
 *
 * Idempotent — re-running with the same body is a no-op and reports
 * changed: 0.
 */
function ans_tb_assign_product_categories( $req ) {
    $guard = ans_tb_pc_guard();
    if ( is_wp_error( $guard ) ) {
        return $guard;
    }
    $p = ans_tb_params( $req );

    $dry  = isset( $p['dry_run'] ) ? (bool) filter_var( $p['dry_run'], FILTER_VALIDATE_BOOLEAN ) : true;
    $mode = isset( $p['mode'] ) ? strtolower( trim( (string) $p['mode'] ) ) : 'replace';
    if ( ! in_array( $mode, array( 'replace', 'append', 'remove' ), true ) ) {
        return new WP_Error( 'bad_mode', 'mode must be replace, append or remove.', array( 'status' => 400 ) );
    }
    $auto_create = isset( $p['auto_create'] ) ? (bool) filter_var( $p['auto_create'], FILTER_VALIDATE_BOOLEAN ) : true;
    $parent      = isset( $p['parent'] ) ? (int) $p['parent'] : 0;

    $items = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();
    if ( empty( $items ) ) {
        return new WP_Error( 'missing_items', 'items[] is required.', array( 'status' => 400 ) );
    }

    $results = array();
    $changed = 0;

    foreach ( $items as $item ) {
        $pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
        $sku = isset( $item['sku'] ) ? trim( (string) $item['sku'] ) : '';
        if ( ! $pid && '' !== $sku ) {
            $pid = ans_tb_product_id_by_sku( $sku );
        }
        if ( ! $pid ) {
            $results[] = array( 'sku' => $sku, 'error' => 'Could not resolve a product from product_id or sku.' );
            continue;
        }
        $post = get_post( $pid );
        if ( ! $post || 'product' !== $post->post_type ) {
            $results[] = array( 'product_id' => $pid, 'error' => 'Not a WooCommerce product.' );
            continue;
        }

        $before = wp_get_object_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) );
        $before = is_wp_error( $before ) ? array() : array_map( 'intval', $before );

        $target = array();
        $err    = null;
        foreach ( ans_tb_pc_to_list( isset( $item['categories'] ) ? $item['categories'] : array() ) as $ref ) {
            $tid = ans_tb_pc_resolve( $ref, ( $auto_create && 'remove' !== $mode ), $parent );
            if ( is_wp_error( $tid ) ) {
                $err = $tid->get_error_message();
                break;
            }
            $target[] = (int) $tid;
        }
        if ( $err ) {
            $results[] = array( 'product_id' => $pid, 'sku' => $sku, 'error' => $err );
            continue;
        }
        if ( empty( $target ) && 'replace' !== $mode ) {
            $results[] = array( 'product_id' => $pid, 'sku' => $sku, 'error' => 'No categories supplied.' );
            continue;
        }

        if ( 'replace' === $mode ) {
            $after = $target;
        } elseif ( 'append' === $mode ) {
            $after = array_values( array_unique( array_merge( $before, $target ) ) );
        } else {
            $after = array_values( array_diff( $before, $target ) );
        }

        sort( $before );
        $after_sorted = $after;
        sort( $after_sorted );
        $is_change = ( $before !== $after_sorted );

        if ( $is_change && ! $dry ) {
            $set = wp_set_object_terms( $pid, $after, 'product_cat', false );
            if ( is_wp_error( $set ) ) {
                $results[] = array( 'product_id' => $pid, 'sku' => $sku, 'error' => $set->get_error_message() );
                continue;
            }
        }
        if ( $is_change ) {
            $changed++;
        }

        $results[] = array(
            'product_id' => $pid,
            'sku'        => $sku !== '' ? $sku : get_post_meta( $pid, '_sku', true ),
            'name'       => $post->post_title,
            'before'     => array_map( 'ans_tb_pc_name', $before ),
            'after'      => array_map( 'ans_tb_pc_name', $after ),
            'action'     => $is_change ? ( $dry ? 'would_change' : 'changed' ) : 'no_change',
        );
    }

    // WooCommerce caches product-category counts; stale counts make the admin
    // and any category-based discount rule look wrong.
    if ( ! $dry ) {
        delete_transient( 'wc_term_counts' );
        if ( function_exists( 'wc_recount_all_terms' ) ) {
            wc_recount_all_terms();
        }
    }

    return array(
        'dry_run' => $dry,
        'mode'    => $mode,
        'total'   => count( $results ),
        'changed' => $changed,
        'note'    => $dry ? 'DRY RUN — nothing was written. Re-send with dry_run: false to apply.' : 'Applied.',
        'results' => $results,
    );
}

/** Term ID -> readable name, for before/after reporting. */
function ans_tb_pc_name( $term_id ) {
    $t = get_term( (int) $term_id, 'product_cat' );
    return ( $t && ! is_wp_error( $t ) ) ? $t->name : (string) $term_id;
}
