<?php
/**
 * Ars Nova Ticketing Bridge - customer-facing display names for ticket products.
 *
 * WHY THIS EXISTS
 * ---------------
 * A ticket product used to answer four questions in one string:
 *
 *     "Rivers & Streams - Oct 9, Mountain View - Adult"
 *      ^concert            ^date  ^venue         ^tier
 *
 * None of those four are joinable, all four drift independently, and every one
 * of them is ALREADY stored properly somewhere else:
 *
 *     concert  tc_events post title
 *     date     event_date_time  (on the event)
 *     venue    event_location   (on the event)
 *     tier     _ans_tier        (on the product)
 *     link     _event_name      (product -> event)
 *
 * The denormalised name is what let a stale event title print "Season Finale"
 * on a customer's ticket in August 2026 while the event's own date field was
 * correct. It is also why the concert page, the cart and the ticket each show
 * the date a different number of times.
 *
 * So: title is just title, date is just date, venue is just venue, and this
 * file composes them for the customer at the moment of display.
 *
 * IT DELIBERATELY IGNORES THE STORED PRODUCT NAME.
 * That is the whole point, and it is what makes this safe to ship BEFORE the
 * products are renamed. Today the stored name is noisy and we compose over the
 * top of it; after the rename the stored name is just the tier and this file
 * behaves identically. Nothing here needs to change when the data is cleaned,
 * and nothing here parses a name to find out what it means.
 *
 * WHERE IT ACTS
 *   - cart + checkout line item name        (woocommerce_cart_item_name)
 *   - cart + checkout "When / Where" rows    (woocommerce_get_item_data)
 *   - frozen onto the order at purchase time (woocommerce_checkout_create_order_line_item)
 *
 * That last one matters: writing When/Where as ORDER ITEM META means the admin
 * order screen, the order-received page and every WooCommerce email render it
 * natively, with no further filters, and it records what was true on the day of
 * purchase rather than what the event says today.
 *
 * NON-TICKET PRODUCTS ARE LEFT ALONE. Every entry point bails unless the
 * product carries _tc_is_ticket = yes AND resolves to a real event.
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a product id to the four normalised facts, or null.
 *
 * Variations resolve to their parent - a ticket's tier may be a variation, but
 * the event link lives on the parent product.
 *
 * @param int $product_id Product or variation id.
 * @return array|null
 */
function ans_dn_context( $product_id ) {
	$product_id = (int) $product_id;
	if ( $product_id <= 0 ) {
		return null;
	}

	static $cache = array();
	if ( array_key_exists( $product_id, $cache ) ) {
		return $cache[ $product_id ];
	}

	$lookup_id = $product_id;
	if ( 'product_variation' === get_post_type( $lookup_id ) ) {
		$parent = wp_get_post_parent_id( $lookup_id );
		if ( $parent ) {
			$lookup_id = (int) $parent;
		}
	}

	// Only ticket products. Anything else is none of this file's business.
	if ( 'yes' !== get_post_meta( $lookup_id, '_tc_is_ticket', true ) ) {
		$cache[ $product_id ] = null;
		return null;
	}

	$event_id = (int) get_post_meta( $lookup_id, '_event_name', true );
	if ( $event_id <= 0 || ! get_post( $event_id ) ) {
		// A ticket with no resolvable event: leave it exactly as it is rather
		// than render a line item with holes in it.
		$cache[ $product_id ] = null;
		return null;
	}

	$raw_title = get_the_title( $event_id );

	// ans_se_clean_title() strips a trailing " - Oct 9, Mountain View" from a
	// still-denormalised event title. Once titles are clean it is a no-op, so
	// it is correct both before and after the data is tidied.
	$concert = function_exists( 'ans_se_clean_title' )
		? ans_se_clean_title( $raw_title )
		: html_entity_decode( (string) $raw_title, ENT_QUOTES, 'UTF-8' );
	if ( '' === trim( (string) $concert ) ) {
		$concert = html_entity_decode( (string) $raw_title, ENT_QUOTES, 'UTF-8' );
	}

	$ts = function_exists( 'ans_tb_event_ts' ) ? ans_tb_event_ts( $event_id ) : 0;

	$venue_full = html_entity_decode(
		(string) get_post_meta( $event_id, 'event_location', true ),
		ENT_QUOTES,
		'UTF-8'
	);
	$venue_short = ( function_exists( 'ans_sp_place' ) && '' !== $venue_full )
		? ans_sp_place( $venue_full )
		: $venue_full;

	$tier_key = get_post_meta( $product_id, '_ans_tier', true );
	if ( '' === (string) $tier_key && $lookup_id !== $product_id ) {
		$tier_key = get_post_meta( $lookup_id, '_ans_tier', true );
	}
	$tier_key = (string) $tier_key;

	$tier_label = '';
	if ( '' !== $tier_key && function_exists( 'ans_et_tiers' ) ) {
		$tiers = ans_et_tiers();
		if ( isset( $tiers[ $tier_key ]['label'] ) ) {
			$tier_label = (string) $tiers[ $tier_key ]['label'];
		}
	}
	if ( '' === $tier_label && '' !== $tier_key ) {
		$tier_label = ucfirst( $tier_key );
	}

	$ctx = array(
		'product_id' => $product_id,
		'event_id'   => $event_id,
		'concert'    => trim( (string) $concert ),
		'ts'         => (int) $ts,
		'when'       => $ts ? wp_date( 'D, M j, Y · g:i a', $ts ) : '',
		'when_short' => $ts ? wp_date( 'D, M j · g:i a', $ts ) : '',
		'venue'      => trim( $venue_full ),
		'venue_short'=> trim( (string) $venue_short ),
		'tier_key'   => $tier_key,
		'tier_label' => $tier_label,
	);

	$cache[ $product_id ] = $ctx;
	return $ctx;
}

/**
 * The line-item headline: "Rivers & Streams · Adult".
 *
 * Date and venue are NOT folded in here - they are their own rows, so they can
 * be read, styled and frozen onto the order separately.
 *
 * @param array $ctx Context from ans_dn_context().
 * @return string Plain text, unescaped.
 */
function ans_dn_headline( $ctx ) {
	$out = $ctx['concert'];
	if ( '' !== $ctx['tier_label'] ) {
		$out .= ' · ' . $ctx['tier_label'];
	}
	return apply_filters( 'ans_dn_headline', $out, $ctx );
}

/**
 * Pull the href out of WooCommerce's already-linked item name, if there is one.
 *
 * The cart template hands this filter `<a href="...">Name</a>`, while the
 * checkout review table hands it bare text. Rather than guess which context we
 * are in, keep whatever link arrived and re-wrap the composed name in it.
 *
 * @param string $html Incoming item name markup.
 * @return string href or ''.
 */
function ans_dn_href( $html ) {
	if ( false === strpos( (string) $html, '<a ' ) ) {
		return '';
	}
	if ( preg_match( '/<a\b[^>]*\bhref=(["\'])(.*?)\1/i', (string) $html, $m ) ) {
		return $m[2];
	}
	return '';
}

/**
 * Cart and checkout line-item name.
 *
 * @param string $name      Existing name markup.
 * @param array  $cart_item Cart item.
 * @param string $key       Cart item key.
 * @return string
 */
function ans_dn_cart_item_name( $name, $cart_item = array(), $key = '' ) {
	$pid = 0;
	if ( ! empty( $cart_item['variation_id'] ) ) {
		$pid = (int) $cart_item['variation_id'];
	} elseif ( ! empty( $cart_item['product_id'] ) ) {
		$pid = (int) $cart_item['product_id'];
	}

	$ctx = ans_dn_context( $pid );
	if ( ! $ctx ) {
		return $name;
	}

	$headline = esc_html( ans_dn_headline( $ctx ) );
	$href     = ans_dn_href( $name );

	return $href
		? '<a href="' . esc_url( $href ) . '">' . $headline . '</a>'
		: $headline;
}
add_filter( 'woocommerce_cart_item_name', 'ans_dn_cart_item_name', 10, 3 );

/**
 * The When/Where rows WooCommerce renders under the item name in cart and
 * checkout.
 *
 * @param array $data      Existing item data rows.
 * @param array $cart_item Cart item.
 * @return array
 */
function ans_dn_item_data( $data, $cart_item ) {
	$pid = 0;
	if ( ! empty( $cart_item['variation_id'] ) ) {
		$pid = (int) $cart_item['variation_id'];
	} elseif ( ! empty( $cart_item['product_id'] ) ) {
		$pid = (int) $cart_item['product_id'];
	}

	$ctx = ans_dn_context( $pid );
	if ( ! $ctx ) {
		return $data;
	}

	foreach ( ans_dn_rows( $ctx ) as $label => $value ) {
		$data[] = array(
			'key'     => $label,
			'value'   => $value,
			'display' => $value,
		);
	}

	return $data;
}
add_filter( 'woocommerce_get_item_data', 'ans_dn_item_data', 10, 2 );

/**
 * The label => value pairs shown under a ticket, and frozen onto the order.
 *
 * One place, so cart, checkout, admin and email cannot disagree.
 *
 * @param array $ctx Context.
 * @return array
 */
function ans_dn_rows( $ctx ) {
	$rows = array();
	if ( '' !== $ctx['when'] ) {
		$rows['When'] = $ctx['when'];
	}
	if ( '' !== $ctx['venue'] ) {
		$rows['Where'] = $ctx['venue'];
	}
	return apply_filters( 'ans_dn_rows', $rows, $ctx );
}

/**
 * Freeze the composed facts onto the order line item at purchase time.
 *
 * Order item meta renders natively in the admin order screen, on the
 * order-received page and in every WooCommerce email - so this one hook covers
 * all three without another filter. It also records what was true on the day of
 * purchase, which is what a receipt is for; if the event is later moved, the
 * customer's order still says what they bought.
 *
 * @param WC_Order_Item_Product $item   Line item.
 * @param string                $key    Cart item key.
 * @param array                 $values Cart item.
 * @return void
 */
function ans_dn_freeze_line_item( $item, $key, $values ) {
	$pid = 0;
	if ( ! empty( $values['variation_id'] ) ) {
		$pid = (int) $values['variation_id'];
	} elseif ( ! empty( $values['product_id'] ) ) {
		$pid = (int) $values['product_id'];
	}

	$ctx = ans_dn_context( $pid );
	if ( ! $ctx ) {
		return;
	}

	$item->set_name( ans_dn_headline( $ctx ) );

	foreach ( ans_dn_rows( $ctx ) as $label => $value ) {
		$item->add_meta_data( $label, $value, true );
	}

	// Machine-readable, hidden from the customer by the leading underscore.
	$item->add_meta_data( '_ans_event_id', $ctx['event_id'], true );
	$item->add_meta_data( '_ans_event_ts', $ctx['ts'], true );
}
add_action( 'woocommerce_checkout_create_order_line_item', 'ans_dn_freeze_line_item', 10, 3 );

/**
 * GET /ars-nova/v1/tickera/display-preview?product_id=N
 *
 * Renders exactly what a customer would see for a product, without buying it.
 *
 * This exists because there is no safe test gateway on either environment -
 * Stripe is in live mode on staging as well as production, so "just place a
 * test order" would charge a real card. Verification has to be possible without
 * a purchase, or it does not happen.
 *
 * Omit product_id to preview every ticket product at once.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( ANS_TB_NS, '/tickera/display-preview', array(
		'methods'             => 'GET',
		'permission_callback' => 'ans_tb_perm',
		'callback'            => 'ans_dn_preview',
	) );
} );

/**
 * @param WP_REST_Request $req Request.
 * @return array
 */
function ans_dn_preview( $req ) {
	$pid = (int) $req->get_param( 'product_id' );

	if ( $pid > 0 ) {
		$ids = array( $pid );
	} else {
		$ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => (int) ( $req->get_param( 'limit' ) ?: 100 ),
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key'       => '_tc_is_ticket',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value'     => 'yes',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );
	}

	$out     = array();
	$skipped = array();

	foreach ( $ids as $id ) {
		$ctx = ans_dn_context( $id );
		if ( ! $ctx ) {
			$skipped[] = array(
				'product_id' => (int) $id,
				'stored_name'=> get_the_title( $id ),
				'reason'     => 'not a ticket product, or no resolvable event',
			);
			continue;
		}
		$out[] = array(
			'product_id'  => (int) $id,
			'stored_name' => get_the_title( $id ),
			'displays_as' => ans_dn_headline( $ctx ),
			'rows'        => ans_dn_rows( $ctx ),
			'event_id'    => $ctx['event_id'],
			'tier'        => $ctx['tier_key'],
		);
	}

	return array(
		'count'   => count( $out ),
		'items'   => $out,
		'skipped' => $skipped,
		'note'    => 'displays_as + rows are what the customer sees in cart, checkout, order emails and the admin order screen. stored_name is the raw product title and is deliberately not used.',
	);
}
