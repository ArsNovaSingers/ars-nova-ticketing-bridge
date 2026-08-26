<?php
/**
 * Refund a WooCommerce order over REST, so a Claude session can do it directly.
 *
 * WHY
 *
 * Refunds were the one customer-service action nobody could do without wp-admin. Kim
 * could not find the button (it lives on the WooCommerce order screen, not Tickera's
 * order screen or the customer account view) and a session had no way to help beyond
 * describing where to click. Meanwhile a real patron sat double-charged for two days.
 *
 * SAFETY, BECAUSE THIS MOVES REAL MONEY
 *
 * - DRY RUN BY DEFAULT. Called with no arguments it refunds nothing and returns the plan:
 *   what is refundable, what would be refunded, which tickets would be voided.
 * - On the production site a live refund additionally requires confirm_production = true.
 *   The same shape ars-nova-ops uses for plugin installs, for the same reason.
 * - It refunds THROUGH the gateway (refund_payment = true), so the money actually returns
 *   to the customer's card. A WooCommerce-only refund that marks the order refunded while
 *   Stripe still holds the money is the worst possible outcome - the books say resolved and
 *   the customer is still out of pocket - so this never does that silently: if the gateway
 *   refund fails, the whole call fails and nothing is recorded.
 * - It refuses to refund more than the order's remaining refundable amount.
 *
 * Ticket voiding is NOT handled here. wc_create_refund() fires the hooks that
 * includes/refund-voids-tickets.php listens on, so a refund issued through this endpoint
 * voids tickets by exactly the same path as one issued by hand in wp-admin. One behaviour,
 * one place, no drift.
 *
 * ROUTES
 *   GET  ars-nova/v1/order/{id}/refund   - plan only, never moves money
 *   POST ars-nova/v1/order/{id}/refund   - dry_run defaults TRUE; pass false to act
 *
 * @package ars-nova-ticketing-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Is this the live storefront? Mirrors ans_ops_is_production(). */
function ans_ref_is_production() {
    $host = wp_parse_url( home_url(), PHP_URL_HOST );
    $prod = apply_filters( 'ans_ref_production_hosts', array( 'arsnovasingers.org', 'www.arsnovasingers.org' ) );
    return in_array( strtolower( (string) $host ), array_map( 'strtolower', $prod ), true );
}

/**
 * Describe an order for the plan: what is refundable and what tickets hang off it.
 *
 * @param WC_Order $order
 * @return array
 */
function ans_ref_describe( $order ) {
    $items = array();
    foreach ( $order->get_items() as $item_id => $item ) {
        $items[] = array(
            'order_item_id'  => (int) $item_id,
            'name'           => $item->get_name(),
            'qty'            => (int) $item->get_quantity(),
            'total'          => wc_format_decimal( $item->get_total(), 2 ),
            'qty_refunded'   => abs( (int) $order->get_qty_refunded_for_item( $item_id ) ),
            'total_refunded' => wc_format_decimal( $order->get_total_refunded_for_item( $item_id ), 2 ),
        );
    }

    $tickets = array();
    if ( function_exists( 'ans_rvt_ticket_ids' ) ) {
        foreach ( ans_rvt_ticket_ids( $order->get_id() ) as $tid ) {
            $tickets[] = array(
                'id'          => $tid,
                'ticket_code' => (string) get_post_meta( $tid, 'ticket_code', true ),
                'status'      => get_post_status( $tid ),
                'checked_in'  => function_exists( 'ans_rvt_is_checked_in' ) ? ans_rvt_is_checked_in( $tid ) : null,
            );
        }
    }

    return array(
        'order_id'          => $order->get_id(),
        'status'            => $order->get_status(),
        'customer'          => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
        'email'             => $order->get_billing_email(),
        'total'             => wc_format_decimal( $order->get_total(), 2 ),
        'already_refunded'  => wc_format_decimal( $order->get_total_refunded(), 2 ),
        'remaining'         => wc_format_decimal( $order->get_remaining_refund_amount(), 2 ),
        'payment_method'    => $order->get_payment_method_title(),
        'transaction_id'    => $order->get_transaction_id(),
        'gateway_supports_refunds' => ( ( $gw = wc_get_payment_gateway_by_order( $order ) ) && $gw->supports( 'refunds' ) ),
        'line_items'        => $items,
        'tickets'           => $tickets,
    );
}

function ans_ref_route_plan( WP_REST_Request $req ) {
    $order = wc_get_order( (int) $req['id'] );
    if ( ! $order ) {
        return new WP_Error( 'ans_ref_no_order', 'No such order.', array( 'status' => 404 ) );
    }
    return rest_ensure_response( array(
        'ok'       => true,
        'dry_run'  => true,
        'is_production' => ans_ref_is_production(),
        'order'    => ans_ref_describe( $order ),
        'note'     => 'Nothing was refunded. POST with dry_run=false (and confirm_production=true on Live) to act.',
    ) );
}

function ans_ref_route_refund( WP_REST_Request $req ) {
    $order = wc_get_order( (int) $req['id'] );
    if ( ! $order ) {
        return new WP_Error( 'ans_ref_no_order', 'No such order.', array( 'status' => 404 ) );
    }

    $described = ans_ref_describe( $order );
    $remaining = (float) $described['remaining'];

    $dry_run = $req->has_param( 'dry_run' ) ? rest_sanitize_boolean( $req->get_param( 'dry_run' ) ) : true;
    $reason  = sanitize_text_field( (string) $req->get_param( 'reason' ) );
    $amount  = $req->has_param( 'amount' ) ? round( (float) $req->get_param( 'amount' ), 2 ) : $remaining;

    // Optional per-line refund: [ { order_item_id, qty, refund_total } ]
    $line_items = array();
    foreach ( (array) $req->get_param( 'line_items' ) as $li ) {
        $iid = isset( $li['order_item_id'] ) ? (int) $li['order_item_id'] : 0;
        if ( ! $iid ) {
            continue;
        }
        $line_items[ $iid ] = array(
            'qty'          => isset( $li['qty'] ) ? (int) $li['qty'] : 0,
            'refund_total' => isset( $li['refund_total'] ) ? round( (float) $li['refund_total'], 2 ) : 0,
        );
    }

    if ( $amount <= 0 ) {
        return new WP_Error( 'ans_ref_nothing_to_refund', 'Order has no remaining refundable amount.', array( 'status' => 400, 'order' => $described ) );
    }
    if ( $amount > $remaining + 0.001 ) {
        return new WP_Error(
            'ans_ref_over_refund',
            sprintf( 'Refund of %s exceeds the remaining refundable amount of %s.', $amount, $remaining ),
            array( 'status' => 400, 'order' => $described )
        );
    }
    if ( ! $described['gateway_supports_refunds'] ) {
        return new WP_Error(
            'ans_ref_gateway',
            'The gateway on this order does not support API refunds. Refund it in the gateway dashboard, then record it here.',
            array( 'status' => 409, 'order' => $described )
        );
    }

    if ( $dry_run ) {
        return rest_ensure_response( array(
            'ok'      => true,
            'dry_run' => true,
            'would_refund' => array(
                'amount'     => wc_format_decimal( $amount, 2 ),
                'reason'     => $reason,
                'line_items' => $line_items,
                'via_gateway'=> true,
            ),
            'is_production' => ans_ref_is_production(),
            'order'   => $described,
            'note'    => 'DRY RUN. Nothing was refunded. Re-send with dry_run=false'
                         . ( ans_ref_is_production() ? ' and confirm_production=true.' : '.' ),
        ) );
    }

    if ( ans_ref_is_production() && ! rest_sanitize_boolean( $req->get_param( 'confirm_production' ) ) ) {
        return new WP_Error(
            'ans_ref_needs_confirmation',
            'This is the LIVE store and this call moves real money. Re-send with confirm_production=true.',
            array( 'status' => 428, 'order' => $described )
        );
    }

    $refund = wc_create_refund( array(
        'amount'         => $amount,
        'reason'         => '' !== $reason ? $reason : 'Refunded via Ars Nova connector',
        'order_id'       => $order->get_id(),
        'line_items'     => $line_items,
        'refund_payment' => true,   // Money actually goes back through Stripe.
        'restock_items'  => false,  // Tickets are not stock; ticket voiding is handled by the refund hooks.
    ) );

    if ( is_wp_error( $refund ) ) {
        return new WP_Error(
            'ans_ref_failed',
            'Gateway refund failed, so nothing was recorded: ' . $refund->get_error_message(),
            array( 'status' => 502, 'order' => $described )
        );
    }

    // Re-read rather than trust: the same verify-by-reading rule the rest of this plugin follows.
    $fresh = wc_get_order( $order->get_id() );

    return rest_ensure_response( array(
        'ok'        => true,
        'dry_run'   => false,
        'refund_id' => $refund->get_id(),
        'refunded'  => wc_format_decimal( $refund->get_amount(), 2 ),
        'reason'    => $refund->get_reason(),
        'stripe_refund_id' => get_post_meta( $order->get_id(), '_stripe_refund_id', true ),
        'order'     => ans_ref_describe( $fresh ),
        'note'      => 'Verified by re-reading the order. Ticket voiding, if any, was performed by the refund hooks - see the order notes.',
    ) );
}

add_action( 'rest_api_init', function () {
    register_rest_route( ANS_TB_NS, '/order/(?P<id>\d+)/refund', array(
        array(
            'methods'             => 'GET',
            'permission_callback' => 'ans_tb_perm',
            'callback'            => 'ans_ref_route_plan',
        ),
        array(
            'methods'             => 'POST',
            'permission_callback' => 'ans_tb_perm',
            'callback'            => 'ans_ref_route_refund',
            'args'                => array(
                'amount'             => array( 'type' => 'number',  'required' => false ),
                'reason'             => array( 'type' => 'string',  'required' => false ),
                'dry_run'            => array( 'type' => 'boolean', 'required' => false ),
                'confirm_production' => array( 'type' => 'boolean', 'required' => false ),
                'line_items'         => array( 'type' => 'array',   'required' => false ),
            ),
        ),
    ) );
} );
