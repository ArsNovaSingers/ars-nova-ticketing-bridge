<?php
/**
 * Ars Nova Ticketing Bridge - real ticket PDFs as WooCommerce downloads.
 *
 * WHY THIS EXISTS
 * ---------------
 * Ticket products are `virtual` but were not `downloadable`, and WooCommerce
 * only auto-completes an order when EVERY line item is both. So ticket orders
 * parked at `processing` forever and nothing ever reached `completed` - which
 * reads to staff like an unshipped parcel for a thing that has nothing to ship.
 *
 * The tempting fix is to flag the products downloadable and attach a
 * placeholder file. Don't. A WooCommerce downloadable file is defined on the
 * PRODUCT and is therefore identical for every buyer, while a Tickera ticket is
 * per ticket-instance. A static file would hand every customer the same PDF.
 *
 * So: flag the products downloadable (a data change, not code) and let this
 * filter supply the real per-buyer files at render time. Each row is that
 * buyer's own ticket, pulled from Tickera.
 *
 * WHAT THE LINK IS
 * ----------------
 * ?download_ticket=<instance>&order_key=<ts>&nonce=<wp_hash>
 * It streams the PDF directly (TCPDF, Content-Disposition: attachment) and
 * requires NO login - Tickera's `force_login` setting is unset on this site.
 * That is deliberate and load-bearing: our buyers check out as guests, so a
 * login-gated download would be useless to them. The trade-off is that the URL
 * is a bearer capability with no expiry. It is the same link already sent in
 * the order email, so surfacing it here changes nothing about the security
 * model - but it is why check-in scanning matters.
 *
 * NEVER set Tickera's `disable_ticket_download_hash` to "yes". That switches
 * off the hash check and makes every ticket on the site enumerable by ID.
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.9.5
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ticket instances belonging to a WooCommerce order.
 *
 * Tickera's Bridge sets each instance's post_parent to the WooCommerce order
 * ID, which is what makes this a plain query rather than a join. Only
 * `publish` instances count - the email table deliberately also merges trashed
 * instances carrying `_cancelled_order`, and a cancelled ticket must not appear
 * as a download.
 *
 * @param int $order_id WooCommerce order ID.
 * @return int[] tc_tickets_instances post IDs, oldest first.
 */
function ans_tb_order_ticket_ids( $order_id ) {
    $order_id = (int) $order_id;
    if ( ! $order_id || ! post_type_exists( 'tc_tickets_instances' ) ) {
        return array();
    }
    $ids = get_posts( array(
        'post_type'      => 'tc_tickets_instances',
        'post_status'    => 'publish',
        'post_parent'    => $order_id,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'posts_per_page' => -1,
    ) );
    return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
}

/**
 * One ticket instance, described.
 *
 * The URL comes from Tickera's own builder, never from string-assembly here:
 * the Bridge rewrites that URL through the `tc_download_ticket_url_front`
 * filter, and the nonce is a wp_hash over the ticket id plus an order_key we
 * have no business guessing.
 *
 * @param int $ticket_id tc_tickets_instances post ID.
 * @return array|null
 */
function ans_tb_ticket_link( $ticket_id ) {
    $ticket_id = (int) $ticket_id;
    if ( ! $ticket_id || ! function_exists( 'tickera_get_raw_ticket_download_link' ) ) {
        return null;
    }
    $url = tickera_get_raw_ticket_download_link( '', '', $ticket_id, true );
    if ( ! is_string( $url ) || '' === trim( $url ) ) {
        return null;
    }
    $code     = (string) get_post_meta( $ticket_id, 'ticket_code', true );
    $type_id  = (int) get_post_meta( $ticket_id, 'ticket_type_id', true );
    $event_id = (int) get_post_meta( $ticket_id, 'event_id', true );

    return array(
        'ticket_id'      => $ticket_id,
        'ticket_code'    => $code,
        'ticket_type_id' => $type_id,
        'event_id'       => $event_id,
        'event'          => $event_id ? get_the_title( $event_id ) : '',
        'url'            => $url,
    );
}

/**
 * All ticket links for an order, keyed by nothing - caller filters by product.
 *
 * @param int $order_id WooCommerce order ID.
 * @return array[]
 */
function ans_tb_order_ticket_links( $order_id ) {
    $out = array();
    foreach ( ans_tb_order_ticket_ids( $order_id ) as $tid ) {
        $link = ans_tb_ticket_link( $tid );
        if ( $link ) {
            $out[] = $link;
        }
    }
    return $out;
}

/**
 * Replace WooCommerce's download rows with this buyer's actual tickets.
 *
 * WooCommerce reaches this only for products flagged downloadable - see
 * WC_Abstract_Order::get_downloadable_items(), which skips anything where
 * is_downloadable() is false. That gate is why the product flag and this filter
 * are a matched pair: neither works alone.
 *
 * We return an empty array rather than $files when the order has no ticket
 * instances yet. Returning WooCommerce's own rows would mean advertising a
 * download that does not exist, and Tickera generates instances at payment, so
 * "not yet" is a real state during checkout.
 *
 * @param array         $files Existing download rows.
 * @param WC_Order_Item $item  Order item.
 * @param WC_Order      $order Order.
 * @return array
 */
function ans_tb_item_downloads( $files, $item, $order ) {
    if ( ! is_object( $item ) || ! is_object( $order ) || ! method_exists( $item, 'get_product_id' ) ) {
        return $files;
    }
    $product_id = (int) $item->get_product_id();
    if ( ! $product_id || 'yes' !== get_post_meta( $product_id, '_tc_is_ticket', true ) ) {
        return $files; // not a ticket - leave ordinary downloads alone
    }

    $order_id  = (int) $order->get_id();
    $order_key = method_exists( $order, 'get_order_key' ) ? $order->get_order_key() : '';
    $rows      = array();
    $n         = 0;

    foreach ( ans_tb_order_ticket_links( $order_id ) as $link ) {
        if ( $link['ticket_type_id'] !== $product_id ) {
            continue; // belongs to a different line item
        }
        $n++;
        $label = $link['ticket_code'] ? sprintf( 'Ticket %s', $link['ticket_code'] ) : sprintf( 'Ticket %d', $n );
        if ( $link['event'] ) {
            $label = $link['event'] . ' - ' . $label;
        }
        $rows[] = array(
            'download_url'        => $link['url'],
            'download_id'         => 'ans-ticket-' . $link['ticket_id'],
            'product_id'          => $product_id,
            'product_name'        => $item->get_name(),
            'product_url'         => '',
            'download_name'       => $label,
            'order_id'            => $order_id,
            'order_key'           => $order_key,
            'downloads_remaining' => '',
            'access_expires'      => '',
            'file'                => array(
                'name' => $label,
                'file' => $link['url'],
            ),
        );
    }

    return $rows;
}
add_filter( 'woocommerce_get_item_downloads', 'ans_tb_item_downloads', 10, 3 );

/**
 * GET /tickera/order-tickets?order_id=N
 *
 * Diagnostic, and the reason it exists is worth stating: the site runs HPOS, so
 * Tickera derives its order_key from a placeholder post row, and nothing in the
 * source proves the resulting key is one the download handler will accept. This
 * route returns the real URLs so they can be fetched and checked, rather than
 * reasoned about. Admin-only.
 */
add_action( 'rest_api_init', function () {
    register_rest_route( ANS_TB_NS, '/tickera/order-tickets', array(
        'methods'             => 'GET',
        'permission_callback' => 'ans_tb_perm',
        'callback'            => function ( $req ) {
            $order_id = (int) $req->get_param( 'order_id' );
            if ( ! $order_id ) {
                return new WP_Error( 'missing_order_id', 'order_id is required.', array( 'status' => 400 ) );
            }
            $order  = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
            $links  = ans_tb_order_ticket_links( $order_id );
            return array(
                'order_id'     => $order_id,
                'order_status' => $order ? $order->get_status() : null,
                'date_paid'    => ( $order && $order->get_date_paid() ) ? $order->get_date_paid()->date( 'Y-m-d H:i' ) : null,
                'count'        => count( $links ),
                'tickets'      => $links,
                'note'         => 'URLs are bearer links - anyone holding one can download that PDF.',
            );
        },
    ) );
} );
