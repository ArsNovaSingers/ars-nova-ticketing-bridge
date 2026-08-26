<?php
/**
 * Ars Nova Ticketing Bridge - a venue address the website never shows.
 *
 * WHY THIS EXISTS
 * ---------------
 * Tickera has ONE location field, `event_location`, and it does two jobs: the public
 * event page prints it, and so does the ticket. That is fine for a church. It is not
 * fine for the September house concert, which happens in somebody's home.
 *
 * So that event carries the placeholder "Private residence, Boulder - address provided
 * with your ticket", and the consequence is that the ticket says exactly that too. A
 * patron holding a valid ticket has no way to find out where to go. Verified on Live
 * 2026-08-26: the public event page renders event_location verbatim, so putting the
 * real street address in that field would publish a private home on an indexable page
 * (PROJECT_RULES section 8).
 *
 * This adds a SECOND location, read only by things the buyer sees privately - the
 * ticket PDF and the confirmation email. Nothing public reads it.
 *
 * FALLBACK IS THE WHOLE DESIGN
 * ----------------------------
 * ans_tb_event_location() returns the private value when there is one and
 * event_location otherwise. That means the new ticket element can replace the stock
 * Event Location element on the ONE shared template and 20 of the 21 events render
 * exactly as they do today. Only the house concert changes.
 *
 * HOW THE TICKET DESIGNER FINDS THIS
 * ----------------------------------
 * Source-verified in tickera/includes/addons/ticket-designer/includes/
 * class-tc-ticket-designer-fields.php. The canvas designer's Field dropdown is a
 * curated hardcoded list PLUS an auto-discovered group built from
 * $GLOBALS['tickera_template_elements'] - i.e. anything registered through
 * tickera_register_template_element(). Registered elements appear under "Add-on
 * Fields" with the key el_<element_name>, and their value at PDF time comes from
 * calling the class's own ticket_content(). The classic row/column designer reads the
 * same registry.
 *
 * Two constraints that are easy to trip over:
 *   1. element_name must not collide with core_element_names() (Tickera's 18 tc_*
 *      classes) or it is skipped as a duplicate of the curated entry.
 *   2. element_name must NOT contain qr, barcode, logo, image, map, google or sponsor.
 *      Those substrings mark an element "visual" and it is dropped from the dropdown.
 *      This is why the element is named ans_venue_address_element and not, say,
 *      ans_venue_map_element.
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.18.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Post meta key holding the private address. Never rendered by a public template. */
const ANS_PRIVATE_LOCATION_META = 'ans_private_location';

/**
 * The address to show someone who has actually bought a ticket.
 *
 * @param int $event_id tc_events post ID.
 * @return string Private address when set, otherwise the public event_location.
 */
function ans_tb_event_location( $event_id ) {
	$event_id = (int) $event_id;
	if ( ! $event_id ) {
		return '';
	}
	$private = trim( (string) get_post_meta( $event_id, ANS_PRIVATE_LOCATION_META, true ) );
	if ( '' !== $private ) {
		return $private;
	}
	return trim( (string) get_post_meta( $event_id, 'event_location', true ) );
}

/**
 * True when this event is holding a private address.
 *
 * @param int $event_id tc_events post ID.
 * @return bool
 */
function ans_tb_has_private_location( $event_id ) {
	return '' !== trim( (string) get_post_meta( (int) $event_id, ANS_PRIVATE_LOCATION_META, true ) );
}

/* -------------------------------------------------------------------------
 * The admin field
 * ---------------------------------------------------------------------- */

add_action( 'add_meta_boxes', function () {
	if ( ! post_type_exists( 'tc_events' ) ) {
		return;
	}
	add_meta_box(
		'ans_private_location_box',
		'Private address (ticket & email only)',
		'ans_tb_private_location_box',
		'tc_events',
		'normal',
		'high'
	);
} );

/**
 * Render the meta box.
 *
 * @param WP_Post $post Event post.
 * @return void
 */
function ans_tb_private_location_box( $post ) {
	wp_nonce_field( 'ans_private_location_save', 'ans_private_location_nonce' );
	$value  = (string) get_post_meta( $post->ID, ANS_PRIVATE_LOCATION_META, true );
	$public = (string) get_post_meta( $post->ID, 'event_location', true );
	?>
	<p style="margin-top:0;">
		Shown <strong>only</strong> on the ticket PDF and in the confirmation email.
		Never appears on the website.
	</p>
	<textarea name="<?php echo esc_attr( ANS_PRIVATE_LOCATION_META ); ?>"
		rows="3" style="width:100%;"
		placeholder="e.g. 1234 Maple Street, Boulder, CO 80302"><?php echo esc_textarea( $value ); ?></textarea>
	<p class="description">
		Leave this empty for ordinary venues — the ticket then uses the public Event
		Location, which is currently:
		<em><?php echo $public ? esc_html( $public ) : 'not set'; ?></em>
	</p>
	<?php
}

add_action( 'save_post_tc_events', function ( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['ans_private_location_nonce'] ) ) {
		return; // field not on this screen - do not wipe the value
	}
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ans_private_location_nonce'] ) ), 'ans_private_location_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$raw = isset( $_POST[ ANS_PRIVATE_LOCATION_META ] )
		? sanitize_textarea_field( wp_unslash( $_POST[ ANS_PRIVATE_LOCATION_META ] ) )
		: '';
	if ( '' === trim( $raw ) ) {
		delete_post_meta( $post_id, ANS_PRIVATE_LOCATION_META );
	} else {
		update_post_meta( $post_id, ANS_PRIVATE_LOCATION_META, $raw );
	}
} );

/* -------------------------------------------------------------------------
 * The Ticket Designer element
 * ---------------------------------------------------------------------- */

/**
 * Register a "Venue Address" element with Tickera.
 *
 * Deferred to init so Tickera has loaded its base class. Registering earlier is a
 * fatal, registering later misses the designer's field discovery.
 */
add_action( 'init', function () {

	if ( ! class_exists( '\Tickera\TC_Ticket_Template_Elements' ) ) {
		return; // Tickera not active - nothing to register against
	}
	if ( ! function_exists( '\Tickera\tickera_register_template_element' ) ) {
		return;
	}
	if ( class_exists( 'ANS_Venue_Address_Element' ) ) {
		return; // already registered this request
	}

	/**
	 * Prints the private address when the event has one, else the public location.
	 *
	 * element_name is deliberately ans_venue_address_element: it must avoid Tickera's
	 * 18 core names AND must not contain qr/barcode/logo/image/map/google/sponsor,
	 * any of which would get it filtered out of the designer as a "visual" element.
	 */
	class ANS_Venue_Address_Element extends \Tickera\TC_Ticket_Template_Elements {

		public $element_name = 'ans_venue_address_element';
		public $element_title = 'Venue Address';
		public $font_awesome_icon = '<i class="fa fa-home"></i>';

		public function on_creation() {
			$this->element_title = 'Venue Address';
		}

		public function advanced_admin_element_settings() {
			ob_start();
			if ( method_exists( $this, 'get_att_fonts' ) ) {
				$this->get_att_fonts();
			}
			if ( method_exists( $this, 'get_font_colors' ) ) {
				$this->get_font_colors();
			}
			if ( method_exists( $this, 'get_font_sizes' ) ) {
				$this->get_font_sizes();
			}
			if ( method_exists( $this, 'get_font_style' ) ) {
				$this->get_font_style();
			}
			if ( method_exists( $this, 'get_default_text_value' ) ) {
				$this->get_default_text_value( '1234 Maple Street, Boulder, CO 80302' );
			}
			return ob_get_clean();
		}

		/**
		 * The value Tickera prints on the PDF.
		 *
		 * Resolves the event the same way Tickera's own location element does: from the
		 * ticket instance's ticket_type_id, not from the instance's post_parent (which
		 * is the ORDER, not the event).
		 *
		 * @param int|false $ticket_instance_id tc_tickets_instances ID.
		 * @param int|false $ticket_type_id     Ticket-type product ID.
		 * @return string
		 */
		public function ticket_content( $ticket_instance_id = false, $ticket_type_id = false ) {

			$event_id = 0;

			if ( $ticket_instance_id ) {
				// The instance carries event_id directly - cheaper and more reliable
				// than walking back through the ticket type.
				$event_id = (int) get_post_meta( (int) $ticket_instance_id, 'event_id', true );

				if ( ! $event_id && class_exists( '\Tickera\TC_Ticket' ) ) {
					$instance = new \Tickera\TC_Ticket( (int) $ticket_instance_id );
					$ticket   = new \Tickera\TC_Ticket();
					if ( isset( $instance->details->ticket_type_id ) ) {
						$event_id = (int) $ticket->get_ticket_event( $instance->details->ticket_type_id );
					}
				}
			} elseif ( $ticket_type_id && class_exists( '\Tickera\TC_Ticket' ) ) {
				$ticket   = new \Tickera\TC_Ticket( (int) $ticket_type_id );
				$event_id = (int) $ticket->get_ticket_event( (int) $ticket_type_id );
			}

			if ( ! $event_id ) {
				// Designer preview with no ticket in hand.
				return '1234 Maple Street, Boulder, CO 80302';
			}

			return ans_tb_event_location( $event_id );
		}
	}

	\Tickera\tickera_register_template_element( 'ANS_Venue_Address_Element', 'Venue Address' );
}, 20 );

/* -------------------------------------------------------------------------
 * REST: read and set the private address without wp-admin
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', function () {

	register_rest_route( ANS_TB_NS, '/event/(?P<id>\d+)/private-location', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'ans_tb_perm',
			'callback'            => function ( $req ) {
				$id = (int) $req['id'];
				if ( 'tc_events' !== get_post_type( $id ) ) {
					return new WP_Error( 'not_event', 'Not a Tickera event.', array( 'status' => 404 ) );
				}
				return array(
					'event_id'         => $id,
					'title'            => get_the_title( $id ),
					'public_location'  => (string) get_post_meta( $id, 'event_location', true ),
					'private_location' => (string) get_post_meta( $id, ANS_PRIVATE_LOCATION_META, true ),
					'ticket_prints'    => ans_tb_event_location( $id ),
					'note'             => 'public_location is shown on the website. private_location is shown ONLY on the ticket and in the confirmation email.',
				);
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => 'ans_tb_perm',
			'callback'            => function ( $req ) {
				$id = (int) $req['id'];
				if ( 'tc_events' !== get_post_type( $id ) ) {
					return new WP_Error( 'not_event', 'Not a Tickera event.', array( 'status' => 404 ) );
				}
				$value = sanitize_textarea_field( (string) $req->get_param( 'private_location' ) );

				if ( '' === trim( $value ) ) {
					delete_post_meta( $id, ANS_PRIVATE_LOCATION_META );
				} else {
					update_post_meta( $id, ANS_PRIVATE_LOCATION_META, $value );
				}

				return array(
					'ok'               => true,
					'event_id'         => $id,
					'private_location' => (string) get_post_meta( $id, ANS_PRIVATE_LOCATION_META, true ),
					'ticket_prints'    => ans_tb_event_location( $id ),
					'warning'          => 'Read-backs on this site can be served stale by the object cache. Clear caches before trusting a verification read.',
				);
			},
		),
	) );
} );
