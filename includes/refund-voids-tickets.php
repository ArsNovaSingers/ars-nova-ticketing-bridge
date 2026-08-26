<?php
/**
 * Refunding a WooCommerce order voids its Tickera tickets.
 *
 * WHY THIS FILE EXISTS
 *
 * The Tickera Bridge for WooCommerce (v1.7.7) only voids tickets when an order is
 * CANCELLED. Its check_tickets_action() reads:
 *
 *     if ( 'cancelled' == $new_status ) { $this->trash_associated_tickets( $post_id, true ); }
 *     else                              { $this->untrash_associated_tickets( $post_id ); }
 *
 * A refund is not a cancellation, so a refunded order takes the ELSE branch - which
 * actively RE-PUBLISHES the tickets. Verified on Live 2026-08-26: John McClellan was
 * refunded five of six orders and still held six publishable ticket instances
 * (7364, 7367, 7370, 7376, 7380) for a capacity-limited house concert.
 *
 * The download link does die on refund - woo_validate_downloadable_ticket_order_status()
 * only whitelists 'completed' (plus 'processing' when downloads are allowed). But a PDF
 * already saved to a phone still carries a live ticket code, and check-in reads the
 * ticket, not the link. Voiding has to happen at the ticket.
 *
 * TWO THINGS MAKE THIS HARDER THAN IT LOOKS
 *
 * 1. HOOK ORDER. WooCommerce fires woocommerce_order_status_{$new} BEFORE
 *    woocommerce_order_status_changed. The bridge listens on the latter at priority 10.
 *    Anything trashed on the former is untrashed milliseconds later. So this file hooks
 *    woocommerce_order_status_changed at priority 20 - after the bridge, not before.
 *
 * 2. RESURRECTION. The bridge untrashes on EVERY non-cancelled status change, forever.
 *    An order edited weeks later would silently restore tickets voided today. So voided
 *    tickets are stamped with _ans_voided_by_refund and re-voided on any later status
 *    change. The stamp is the durable record; the trash state is not.
 *
 * PARTIAL REFUNDS are honoured per line item. Refunding 2 of 3 seats voids 2 and leaves
 * 1 live, because WooCommerce records refunded quantity per line and each ticket instance
 * records the order item it came from. Already-checked-in tickets are skipped and left
 * alone - somebody who has already walked through the door keeps their admission, and
 * that is a deliberate choice recorded in the order note rather than a silent one.
 *
 * @package ars-nova-ticketing-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Post meta stamped on any ticket this file voids. */
const ANS_RVT_VOID_FLAG = '_ans_voided_by_refund';

/**
 * Every ticket instance belonging to an order, in any post status.
 *
 * Ticket instances are children of the order post. Queried directly rather than through
 * Tickera\TC_Orders::get_tickets_ids() so this keeps working if that class moves - the
 * parent/child relationship is the stable part.
 *
 * @param int $order_id
 * @return int[]
 */
function ans_rvt_ticket_ids( $order_id ) {
    $ids = get_posts( array(
        'post_type'        => 'tc_tickets_instances',
        'post_parent'      => (int) $order_id,
        'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
        'numberposts'      => -1,
        'fields'           => 'ids',
        'suppress_filters' => false,
    ) );
    return array_map( 'intval', (array) $ids );
}

/** Has this ticket already been scanned at the door? */
function ans_rvt_is_checked_in( $ticket_id ) {
    $checkins = get_post_meta( $ticket_id, 'tc_checkins', true );
    if ( empty( $checkins ) || ! is_array( $checkins ) ) {
        return false;
    }
    foreach ( $checkins as $c ) {
        if ( isset( $c['status'] ) && 'Pass' === $c['status'] ) {
            return true;
        }
    }
    return false;
}

/**
 * Void one ticket: stamp it, then trash it.
 *
 * The stamp goes on FIRST and is never removed, so a later untrash by the Tickera bridge
 * can be detected and undone. Returns the ticket code for the order note, or '' if the
 * ticket was skipped.
 *
 * @param int    $ticket_id
 * @param string $reason
 * @return string
 */
function ans_rvt_void_ticket( $ticket_id, $reason = 'refund' ) {
    if ( ans_rvt_is_checked_in( $ticket_id ) ) {
        return '';
    }
    $code = (string) get_post_meta( $ticket_id, 'ticket_code', true );
    update_post_meta( $ticket_id, ANS_RVT_VOID_FLAG, array(
        'reason' => $reason,
        'at'     => current_time( 'mysql' ),
    ) );
    if ( 'trash' !== get_post_status( $ticket_id ) ) {
        wp_trash_post( $ticket_id );
    }
    return '' !== $code ? $code : ( '#' . $ticket_id );
}

/**
 * Re-void anything the Tickera bridge resurrected.
 *
 * Runs on every status change regardless of the new status. Cheap - one meta lookup per
 * ticket on an order that is rarely large - and it is the only thing standing between a
 * voided ticket and a routine order edit six weeks from now.
 *
 * @param int $order_id
 * @return string[] ticket codes re-voided
 */
function ans_rvt_reassert_voids( $order_id ) {
    $revoided = array();
    foreach ( ans_rvt_ticket_ids( $order_id ) as $ticket_id ) {
        if ( ! get_post_meta( $ticket_id, ANS_RVT_VOID_FLAG, true ) ) {
            continue;
        }
        if ( 'trash' === get_post_status( $ticket_id ) ) {
            continue;
        }
        $code = ans_rvt_void_ticket( $ticket_id, 'reasserted' );
        if ( '' !== $code ) {
            $revoided[] = $code;
        }
    }
    return $revoided;
}

/**
 * How many tickets to void per order line, from the refunds recorded on the order.
 *
 * WooCommerce stores each refund as a child order whose line items carry a NEGATIVE
 * quantity against the original line. Summing them gives refunded qty per line, which is
 * what makes "refund 2 of 3" work.
 *
 * @param WC_Order $order
 * @return array<int,int> order_item_id => refunded qty
 */
function ans_rvt_refunded_qty_by_item( $order ) {
    $out = array();
    foreach ( $order->get_refunds() as $refund ) {
        foreach ( $refund->get_items() as $refund_item ) {
            $original_id = (int) $refund_item->get_meta( '_refunded_item_id' );
            if ( ! $original_id ) {
                continue;
            }
            $qty = abs( (int) $refund_item->get_quantity() );
            if ( $qty < 1 ) {
                continue;
            }
            $out[ $original_id ] = ( isset( $out[ $original_id ] ) ? $out[ $original_id ] : 0 ) + $qty;
        }
    }
    return $out;
}

/**
 * Void tickets to match what has actually been refunded on this order.
 *
 * Full refund (status 'refunded') voids everything still live. A partial refund voids only
 * the refunded quantity on each line. Un-checked-in tickets are consumed first so that a
 * partial refund never takes admission away from someone already inside.
 *
 * @param int $order_id
 * @return array{voided:string[],skipped_checked_in:int}
 */
function ans_rvt_apply( $order_id ) {
    $result = array( 'voided' => array(), 'skipped_checked_in' => 0 );

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return $result;
    }

    $ticket_ids = ans_rvt_ticket_ids( $order_id );
    if ( empty( $ticket_ids ) ) {
        return $result;
    }

    $full = ( 'refunded' === $order->get_status() );

    // Bucket live tickets by the order item they came from, checked-in ones last so they
    // are only reached if a partial refund covers more seats than are still unused.
    $by_item = array();
    foreach ( $ticket_ids as $ticket_id ) {
        if ( 'trash' === get_post_status( $ticket_id ) ) {
            continue;
        }
        $item_id = (int) get_post_meta( $ticket_id, 'item_id', true );
        if ( ans_rvt_is_checked_in( $ticket_id ) ) {
            $by_item[ $item_id ]['used'][] = $ticket_id;
        } else {
            $by_item[ $item_id ]['free'][] = $ticket_id;
        }
    }

    if ( $full ) {
        foreach ( $by_item as $buckets ) {
            foreach ( array_merge(
                isset( $buckets['free'] ) ? $buckets['free'] : array(),
                isset( $buckets['used'] ) ? $buckets['used'] : array()
            ) as $ticket_id ) {
                $code = ans_rvt_void_ticket( $ticket_id, 'order refunded in full' );
                if ( '' === $code ) {
                    $result['skipped_checked_in']++;
                } else {
                    $result['voided'][] = $code;
                }
            }
        }
        return $result;
    }

    foreach ( ans_rvt_refunded_qty_by_item( $order ) as $item_id => $qty ) {
        if ( empty( $by_item[ $item_id ] ) ) {
            continue;
        }
        $queue = array_merge(
            isset( $by_item[ $item_id ]['free'] ) ? $by_item[ $item_id ]['free'] : array(),
            isset( $by_item[ $item_id ]['used'] ) ? $by_item[ $item_id ]['used'] : array()
        );
        foreach ( array_slice( $queue, 0, $qty ) as $ticket_id ) {
            $code = ans_rvt_void_ticket( $ticket_id, 'line item refunded' );
            if ( '' === $code ) {
                $result['skipped_checked_in']++;
            } else {
                $result['voided'][] = $code;
            }
        }
    }

    return $result;
}

/** Write what happened onto the order, so Kim can see it without opening Tickera. */
function ans_rvt_note( $order_id, $result, $revoided = array() ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    $lines = array();
    if ( ! empty( $result['voided'] ) ) {
        $lines[] = sprintf(
            'Voided %d ticket(s) on refund: %s',
            count( $result['voided'] ),
            implode( ', ', $result['voided'] )
        );
    }
    if ( ! empty( $result['skipped_checked_in'] ) ) {
        $lines[] = sprintf(
            'Left %d already-checked-in ticket(s) valid - refunding does not retract admission for someone who has already attended. Void by hand if that is wrong.',
            (int) $result['skipped_checked_in']
        );
    }
    if ( ! empty( $revoided ) ) {
        $lines[] = sprintf(
            'Re-voided %d ticket(s) the Tickera bridge had restored: %s',
            count( $revoided ),
            implode( ', ', $revoided )
        );
    }
    if ( $lines ) {
        $order->add_order_note( implode( ' ', $lines ) );
    }
}

/**
 * Full refund, or any later status change on an order that has refunds.
 *
 * PRIORITY 20 IS LOAD-BEARING. The Tickera bridge hooks this same action at 10 and
 * untrashes every ticket on any non-cancelled transition. Running at 10 or earlier means
 * being silently undone.
 */
add_action(
    'woocommerce_order_status_changed',
    function ( $order_id, $old_status, $new_status ) {
        $revoided = ans_rvt_reassert_voids( $order_id );

        $result = array( 'voided' => array(), 'skipped_checked_in' => 0 );
        if ( 'refunded' === $new_status ) {
            $result = ans_rvt_apply( $order_id );
        }

        ans_rvt_note( $order_id, $result, $revoided );
    },
    20,
    3
);

/**
 * Partial refunds, which do not change the order status at all and so never reach the
 * hook above.
 */
add_action(
    'woocommerce_order_refunded',
    function ( $order_id, $refund_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || 'refunded' === $order->get_status() ) {
            return; // Full refund; the status-change hook owns it.
        }
        $result = ans_rvt_apply( $order_id );
        ans_rvt_note( $order_id, $result );
    },
    20,
    2
);
