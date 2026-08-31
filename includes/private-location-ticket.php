<?php
/**
 * Make the TICKET print the private venue address.
 *
 * event-private-location.php added the private-location field, and the
 * confirmation email honours it. The ticket does not: it prints the public
 * event_location, so an event whose public line promises the address will come
 * with the ticket prints that promise on the ticket instead of the address.
 *
 * WHY THIS IS FILTERS AND NOT A DESIGNER ELEMENT
 *
 * event-private-location.php also registers a "Venue Address" Designer element,
 * behind a function_exists( '\Tickera\tickera_register_template_element' )
 * guard. PHP's function_exists() does not resolve a leading backslash on a
 * namespaced function, so that guard cannot pass and the block returns before
 * registering.
 *
 * That is left alone here deliberately, because an element would not solve this
 * anyway: Tickera's Ticket Designer resolves the venue in
 * TC_Ticket_Designer_Fields::resolve_ticket_data() with a direct
 * get_post_meta( $event_id, 'event_location' ) call and does not consult the
 * element registry. Placing one would also mean hand-editing a ticket template
 * that every event shares.
 *
 * Filtering needs none of that, and it repairs tickets that have ALREADY been
 * issued, because Tickera renders the PDF at download time rather than storing
 * it at purchase.
 *
 * An event with an empty private-location box behaves exactly as it did before
 * this file existed. That empty box is the off switch.
 *
 * @package ArsNovaTicketingBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The event behind a ticket instance.
 *
 * Mirrors how Tickera's own elements resolve it. The instance carries event_id
 * directly, which is cheaper and more reliable than walking back through the
 * ticket type - and note that the instance's post_parent is the ORDER, not the
 * event, which is the trap this function exists to avoid repeating.
 *
 * @param int $ticket_instance_id tc_tickets_instances post ID.
 * @param int $ticket_type_id     Ticket-type product ID, when known.
 * @return int Event ID, or 0.
 */
function ans_tbpl_event_for_instance( $ticket_instance_id, $ticket_type_id = 0 ) {

	$ticket_instance_id = (int) $ticket_instance_id;
	$event_id           = 0;

	if ( $ticket_instance_id ) {
		$event_id = (int) get_post_meta( $ticket_instance_id, 'event_id', true );
	}

	if ( $event_id || ! class_exists( '\Tickera\TC_Ticket' ) ) {
		return $event_id;
	}

	$type_id = (int) $ticket_type_id;

	if ( ! $type_id && $ticket_instance_id && class_exists( '\Tickera\TC_Ticket_Instance' ) ) {
		$instance = new \Tickera\TC_Ticket_Instance( $ticket_instance_id );
		if ( isset( $instance->details->ticket_type_id ) ) {
			$type_id = (int) $instance->details->ticket_type_id;
		}
	}

	if ( ! $type_id ) {
		return 0;
	}

	// Under Bridge for WooCommerce the stored id is a WooCommerce product; this
	// filter maps it to the real Tickera ticket-type id. Skipping it is how the
	// event lookup comes back empty on WooCommerce-created orders.
	if ( function_exists( 'tickera_apply_filters' ) ) {
		$type_id = (int) tickera_apply_filters( 'tickera_ticket_type_id', $type_id );
	}

	$tc = new \Tickera\TC_Ticket();

	return (int) $tc->get_ticket_event( $type_id );
}

/**
 * The private address for an event, or '' when it has none.
 *
 * @param int $event_id Event ID.
 * @return string
 */
function ans_tbpl_private_location( $event_id ) {

	$event_id = (int) $event_id;

	if ( ! $event_id || ! defined( 'ANS_PRIVATE_LOCATION_META' ) ) {
		return '';
	}

	return trim( (string) get_post_meta( $event_id, ANS_PRIVATE_LOCATION_META, true ) );
}

/* -------------------------------------------------------------------------
 * 1. The new Ticket Designer
 *
 * resolve_ticket_data() hands us the whole field array AND the ticket instance
 * id, so the event resolves exactly - no guessing, no shared state.
 * ---------------------------------------------------------------------- */

add_filter(
	'tickera_ticket_designer_ticket_data',
	function ( $data, $ticket_instance_id ) {

		if ( ! is_array( $data ) ) {
			return $data;
		}

		$private = ans_tbpl_private_location( ans_tbpl_event_for_instance( $ticket_instance_id ) );

		if ( '' !== $private ) {
			$data['venue_name'] = $private;
		}

		return $data;
	},
	20,
	2
);

/* -------------------------------------------------------------------------
 * 2. The classic ticket template
 *
 * tc_event_location_element passes only the string - no event, no instance - so
 * the event has to be identified from the value itself. This is deliberately
 * conservative: it swaps ONLY when exactly one event carries a private address
 * and that event's public location is an exact match for what arrived. Two
 * events sharing a public location, or none matching, and it does nothing.
 *
 * Both template paths are covered because which renderer a site uses depends on
 * its ticket template, and a filter that never fires costs nothing.
 * ---------------------------------------------------------------------- */

add_filter(
	'tickera_event_location_element',
	function ( $location ) {

		if ( ! defined( 'ANS_PRIVATE_LOCATION_META' ) ) {
			return $location;
		}

		$incoming = trim( html_entity_decode( wp_strip_all_tags( (string) $location ), ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $incoming ) {
			return $location;
		}

		$event_ids = get_posts(
			array(
				'post_type'      => 'tc_events',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => ANS_PRIVATE_LOCATION_META,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$matches = array();

		foreach ( $event_ids as $event_id ) {

			$public = trim(
				html_entity_decode(
					(string) get_post_meta( $event_id, 'event_location', true ),
					ENT_QUOTES,
					'UTF-8'
				)
			);

			if ( '' === $public || $public !== $incoming ) {
				continue;
			}

			$private = ans_tbpl_private_location( $event_id );

			if ( '' !== $private ) {
				$matches[ $private ] = true;
			}
		}

		if ( 1 !== count( $matches ) ) {
			return $location;
		}

		return key( $matches );
	},
	20,
	1
);

/* -------------------------------------------------------------------------
 * 3. The frozen order line
 *
 * display-names.php freezes a "Where" row onto the line item at purchase, taken
 * from the PUBLIC location, and that row is what the order-summary table in the
 * confirmation email shows. Without this, that row and the ticket box above it
 * can disagree, because the box resolves the private address and the row does
 * not.
 *
 * This runs at priority 20, after ans_dn_freeze_line_item at 10, and corrects
 * the frozen copy only. It deliberately does NOT filter ans_dn_rows: those rows
 * also render in the cart and at checkout, where the address must stay private
 * because nobody has bought anything yet.
 * ---------------------------------------------------------------------- */

add_action(
	'woocommerce_checkout_create_order_line_item',
	function ( $item, $key, $values ) {

		unset( $key, $values );

		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return;
		}

		$event_id = (int) $item->get_meta( '_ans_event_id', true );

		if ( ! $event_id ) {
			return;
		}

		$private = ans_tbpl_private_location( $event_id );

		if ( '' === $private ) {
			return;
		}

		$item->update_meta_data( 'Where', $private );
	},
	20,
	3
);
