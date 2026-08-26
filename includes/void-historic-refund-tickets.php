<?php
/**
 * Clean up tickets left alive by refunds issued BEFORE v1.15.0.
 *
 * WHY THIS IS NEEDED SEPARATELY
 *
 * refund-voids-tickets.php fires on a STATUS CHANGE. An order refunded before that
 * code existed is already sitting at `refunded` and no further transition is coming,
 * so nothing will ever void its tickets. The resurrection guard cannot help either:
 * it only protects tickets carrying _ans_voided_by_refund, and these never got stamped.
 *
 * Concretely, on Live 2026-08-26: John McClellan was refunded five of six orders for a
 * capacity-limited house concert and still held six publishable tickets
 * (7364, 7367, 7370, 7376, 7380).
 *
 * WHY NOT JUST FLIP THE STATUS TO RE-TRIGGER THE HOOK
 *
 * Because moving an order to `completed` and back fires WooCommerce's customer emails.
 * The patron would receive a stack of spurious "your order is complete" messages for
 * orders they were refunded for. The cure would be more visible to the customer than
 * the disease.
 *
 * WHAT THIS DOES
 *
 * Voids the tickets on an order that is ALREADY refunded, using the same functions and
 * the same stamp as the live path, so the result is indistinguishable from an order
 * refunded after v1.15.0 - including being protected by the resurrection guard from
 * then on. Idempotent: running it twice changes nothing the second time.
 *
 * ROUTES
 *   GET  ars-nova/v1/order/{id}/void-tickets   what WOULD be voided, never acts
 *   POST ars-nova/v1/order/{id}/void-tickets   dry_run defaults TRUE
 *
 * Refuses any order that is not fully refunded, so it cannot be used to quietly kill
 * tickets somebody has actually paid for.
 *
 * @package ars-nova-ticketing-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Report the ticket state on an order without changing anything.
 *
 * @param int $order_id
 * @return array|WP_Error
 */
function ans_vh_survey( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return new WP_Error( 'ans_vh_no_order', 'No such order.', array( 'status' => 404 ) );
    }

    $live = array();
    $dead = array();
    $used = array();

    foreach ( ans_rvt_ticket_ids( $order_id ) as $tid ) {
        $row = array(
            'id'          => $tid,
            'ticket_code' => (string) get_post_meta( $tid, 'ticket_code', true ),
            'status'      => get_post_status( $tid ),
            'stamped'     => (bool) get_post_meta( $tid, ANS_RVT_VOID_FLAG, true ),
        );
        if ( ans_rvt_is_checked_in( $tid ) ) {
            $row['checked_in'] = true;
            $used[] = $row;
        } elseif ( 'trash' === $row['status'] ) {
            $dead[] = $row;
        } else {
            $live[] = $row;
        }
    }

    return array(
        'order_id'         => $order_id,
        'status'           => $order->get_status(),
        'customer'         => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
        'total'            => wc_format_decimal( $order->get_total(), 2 ),
        'total_refunded'   => wc_format_decimal( $order->get_total_refunded(), 2 ),
        'fully_refunded'   => ( 'refunded' === $order->get_status() ),
        'tickets_live'     => $live,
        'tickets_voided'   => $dead,
        'tickets_used'     => $used,
    );
}

function ans_vh_route_survey( WP_REST_Request $req ) {
    $survey = ans_vh_survey( (int) $req['id'] );
    if ( is_wp_error( $survey ) ) {
        return $survey;
    }
    return rest_ensure_response( array(
        'ok'      => true,
        'dry_run' => true,
        'survey'  => $survey,
        'note'    => 'Nothing changed. POST with dry_run=false to void the live tickets on this refunded order.',
    ) );
}

function ans_vh_route_void( WP_REST_Request $req ) {
    $order_id = (int) $req['id'];
    $survey   = ans_vh_survey( $order_id );
    if ( is_wp_error( $survey ) ) {
        return $survey;
    }

    if ( ! $survey['fully_refunded'] ) {
        return new WP_Error(
            'ans_vh_not_refunded',
            'Refusing: order ' . $order_id . ' is "' . $survey['status'] . '", not "refunded". This route only cleans up tickets left behind by a refund.',
            array( 'status' => 409, 'survey' => $survey )
        );
    }

    $dry_run = $req->has_param( 'dry_run' ) ? rest_sanitize_boolean( $req->get_param( 'dry_run' ) ) : true;

    if ( $dry_run ) {
        return rest_ensure_response( array(
            'ok'          => true,
            'dry_run'     => true,
            'would_void'  => wp_list_pluck( $survey['tickets_live'], 'ticket_code' ),
            'would_skip_checked_in' => wp_list_pluck( $survey['tickets_used'], 'ticket_code' ),
            'survey'      => $survey,
            'note'        => 'DRY RUN. Re-send with dry_run=false'
                             . ( ans_ref_is_production() ? ' and confirm_production=true.' : '.' ),
        ) );
    }

    if ( ans_ref_is_production() && ! rest_sanitize_boolean( $req->get_param( 'confirm_production' ) ) ) {
        return new WP_Error(
            'ans_vh_needs_confirmation',
            'This is the LIVE site. Re-send with confirm_production=true.',
            array( 'status' => 428, 'survey' => $survey )
        );
    }

    $voided = array();
    foreach ( $survey['tickets_live'] as $row ) {
        $code = ans_rvt_void_ticket( $row['id'], 'historic refund cleanup' );
        if ( '' !== $code ) {
            $voided[] = $code;
        }
    }

    if ( $voided ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->add_order_note( sprintf(
                'Voided %d ticket(s) left live by a refund issued before v1.15.0: %s',
                count( $voided ),
                implode( ', ', $voided )
            ) );
        }
    }

    return rest_ensure_response( array(
        'ok'      => true,
        'dry_run' => false,
        'voided'  => $voided,
        'skipped_checked_in' => wp_list_pluck( $survey['tickets_used'], 'ticket_code' ),
        'after'   => ans_vh_survey( $order_id ),
    ) );
}

add_action( 'rest_api_init', function () {
    register_rest_route( ANS_TB_NS, '/order/(?P<id>\d+)/void-tickets', array(
        array(
            'methods'             => 'GET',
            'permission_callback' => 'ans_tb_perm',
            'callback'            => 'ans_vh_route_survey',
        ),
        array(
            'methods'             => 'POST',
            'permission_callback' => 'ans_tb_perm',
            'callback'            => 'ans_vh_route_void',
            'args'                => array(
                'dry_run'            => array( 'type' => 'boolean', 'required' => false ),
                'confirm_production' => array( 'type' => 'boolean', 'required' => false ),
            ),
        ),
    ) );
} );
