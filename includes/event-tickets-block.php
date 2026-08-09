<?php
/**
 * Event Tickets — the concert-page ticket picker, as a reusable block.
 *
 * WHAT THIS REPLACES
 * ------------------
 * Concert pages used three separate `[tc_wb_event …]` tables, one per
 * performance, each rendering a row per ticket type with its OWN add-to-cart
 * button. Rivers & Streams had nine buttons on one page and nothing indicated
 * they shared a cart. Jonathan's verdict: "highly confusing."
 *
 * THE MODEL, COPIED FROM THE PACKAGES PICKER
 * ------------------------------------------
 * `includes/season-packages.php` already solved this interaction and is proven
 * in production, so this deliberately mirrors it rather than inventing:
 *   - PHP renders everything and hands JS one JSON payload.
 *   - Boxes at the top; choosing one reveals options below (hidden -> shown).
 *   - Exactly ONE add-to-cart control.
 *   - Submission is the WooCommerce Store API with a probe-then-header nonce.
 * Differences: one date at a time (packages picks N concerts), and quantity is
 * per ticket type rather than one seat count for the whole selection.
 *
 * DATA MODEL — READ THIS BEFORE CHANGING THE QUERY
 * ------------------------------------------------
 * Group by the `_event_name` meta, NOT by product_cat. The packages picker
 * groups by category and is right to, but only the ADULT products were ever
 * filed into concert categories — Student and Youth are Uncategorized. Copying
 * that query here would silently drop two thirds of every concert's tickets.
 *
 * Those categories are also accidentally load-bearing: the packages picker
 * builds its night options from them, so "tidying" Student/Youth into them
 * would start offering student seats as package nights. Leave them alone.
 *
 * Tier comes from `_ans_tier` (adult|student|youth|free), backfilled
 * 2026-08-09 from the SKU suffix. Before that the only signal was a SKU suffix
 * and text after an em-dash in the product name.
 *
 * @package ars-nova-ticketing-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Display order and labels for the tiers. Filterable. */
function ans_et_tiers() {
	return apply_filters(
		'ans_et_tiers',
		array(
			'adult'   => array( 'label' => 'Adult',              'order' => 10 ),
			'student' => array( 'label' => 'Student',            'order' => 20 ),
			'youth'   => array( 'label' => 'Youth (18 & under)', 'order' => 30 ),
			'free'    => array( 'label' => 'Free admission',     'order' => 40 ),
		)
	);
}

/**
 * Resolve which performances to show.
 *
 * Priority: explicit `events` attribute > explicit `event_category` > the
 * event_category term whose `ans_page_id` meta points at the current page.
 * That last path is what makes the block zero-configuration on a project page:
 * drop it in and it finds its own concert.
 *
 * @param array $atts Shortcode/block attributes.
 * @return int[] tc_events post IDs.
 */
function ans_et_resolve_events( $atts ) {
	if ( ! empty( $atts['events'] ) ) {
		return array_values( array_filter( array_map( 'absint', explode( ',', $atts['events'] ) ) ) );
	}

	$term = null;

	if ( ! empty( $atts['event_category'] ) ) {
		$term = is_numeric( $atts['event_category'] )
			? get_term( absint( $atts['event_category'] ), 'event_category' )
			: get_term_by( 'slug', sanitize_title( $atts['event_category'] ), 'event_category' );
	} else {
		$page_id = get_the_ID();

		if ( $page_id ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'event_category',
					'hide_empty' => false,
					'meta_query' => array(
						array(
							'key'   => 'ans_page_id',
							'value' => (string) $page_id,
						),
					),
				)
			);

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$term = $terms[0];
			}
		}
	}

	if ( ! $term || is_wp_error( $term ) ) {
		return array();
	}

	$events = get_posts(
		array(
			'post_type'      => 'tc_events',
			'post_status'    => 'publish',
			'numberposts'    => -1,
			'fields'         => 'ids',
			'tax_query'      => array(
				array(
					'taxonomy' => 'event_category',
					'field'    => 'term_id',
					'terms'    => $term->term_id,
				),
			),
		)
	);

	return is_array( $events ) ? $events : array();
}

/**
 * Ticket products for one event, tier-ordered.
 *
 * Non-purchasable products are skipped. That is not paranoia: a ticket product
 * whose parent tc_events post is a draft is silently non-purchasable, with no
 * error surfaced anywhere (ticketing HANDOFF §1b). Skipping keeps a dead option
 * out of the UI instead of letting someone add an unbuyable ticket.
 *
 * @param int $event_id tc_events post ID.
 * @return array
 */
function ans_et_tickets_for_event( $event_id ) {
	$product_ids = get_posts(
		array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_query'  => array(
				'relation' => 'AND',
				array(
					'key'   => '_tc_is_ticket',
					'value' => 'yes',
				),
				array(
					'key'   => '_event_name',
					'value' => (string) $event_id,
				),
			),
		)
	);

	if ( empty( $product_ids ) || ! function_exists( 'wc_get_product' ) ) {
		return array();
	}

	$tiers = ans_et_tiers();
	$rows  = array();

	foreach ( $product_ids as $pid ) {
		$product = wc_get_product( $pid );

		if ( ! $product || ! $product->is_purchasable() ) {
			continue;
		}

		$tier = get_post_meta( $pid, '_ans_tier', true );
		$tier = $tier ? $tier : 'adult';

		$price = (float) $product->get_price();

		$rows[] = array(
			'id'      => (int) $pid,
			'tier'    => $tier,
			'label'   => isset( $tiers[ $tier ] ) ? $tiers[ $tier ]['label'] : $product->get_name(),
			'price'   => $price,
			'price_h' => $price > 0 ? html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES, 'UTF-8' ) : 'Free',
			'order'   => isset( $tiers[ $tier ] ) ? $tiers[ $tier ]['order'] : 99,
		);
	}

	usort(
		$rows,
		function ( $a, $b ) {
			return $a['order'] <=> $b['order'];
		}
	);

	return $rows;
}

/**
 * Assemble the payload: one entry per performance, in date order.
 *
 * @param int[] $event_ids tc_events post IDs.
 * @return array
 */
function ans_et_performances( $event_ids ) {
	$out = array();

	foreach ( $event_ids as $event_id ) {
		$tickets = ans_et_tickets_for_event( $event_id );

		$raw_date = get_post_meta( $event_id, 'event_date_time', true );
		$stamp    = $raw_date ? strtotime( $raw_date ) : 0;

		$out[] = array(
			'event'    => (int) $event_id,
			'stamp'    => $stamp,
			'day'      => $stamp ? date_i18n( 'D', $stamp ) : '',
			'date'     => $stamp ? date_i18n( 'M j', $stamp ) : get_the_title( $event_id ),
			'time'     => $stamp ? date_i18n( get_option( 'time_format' ), $stamp ) : '',
			'venue'    => (string) get_post_meta( $event_id, 'event_location', true ),
			'tickets'  => $tickets,
			'sold_out' => empty( $tickets ),
		);
	}

	usort(
		$out,
		function ( $a, $b ) {
			return $a['stamp'] <=> $b['stamp'];
		}
	);

	return $out;
}

/**
 * Render.
 *
 * @param array $atts Attributes.
 * @return string
 */
function ans_et_render( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'events'         => '',
			'event_category' => '',
			'heading'        => 'Choose your night',
			'cart_url'       => '',
			'empty_text'     => 'Tickets for this program are not on sale yet.',
		),
		is_array( $atts ) ? $atts : array(),
		'ans_event_tickets'
	);

	$performances = ans_et_performances( ans_et_resolve_events( $atts ) );

	if ( empty( $performances ) ) {
		return '<div class="ans-et ans-et--empty"><p>' . esc_html( $atts['empty_text'] ) . '</p></div>';
	}

	$cart_url = $atts['cart_url'] ? $atts['cart_url'] : ( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '/cart/' );

	$payload = array(
		'performances' => $performances,
		'cartUrl'      => $cart_url,
		'restUrl'      => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
	);

	$uid = 'ans-et-' . wp_generate_uuid4();

	ob_start();
	?>
	<div class="ans-et" id="<?php echo esc_attr( $uid ); ?>" data-ans-et>
		<?php if ( $atts['heading'] ) : ?>
			<h2 class="ans-et__heading"><?php echo esc_html( $atts['heading'] ); ?></h2>
		<?php endif; ?>

		<div class="ans-et__dates" role="group" aria-label="Choose a performance">
			<?php foreach ( $performances as $p ) : ?>
				<button
					type="button"
					class="ans-et__date<?php echo $p['sold_out'] ? ' is-unavailable' : ''; ?>"
					data-event="<?php echo esc_attr( $p['event'] ); ?>"
					<?php echo $p['sold_out'] ? 'disabled aria-disabled="true"' : ''; ?>
				>
					<span class="ans-et__date-day"><?php echo esc_html( $p['day'] ); ?></span>
					<span class="ans-et__date-date"><?php echo esc_html( $p['date'] ); ?></span>
					<?php if ( $p['time'] ) : ?>
						<span class="ans-et__date-time"><?php echo esc_html( $p['time'] ); ?></span>
					<?php endif; ?>
					<?php if ( $p['venue'] ) : ?>
						<span class="ans-et__date-venue"><?php echo esc_html( $p['venue'] ); ?></span>
					<?php endif; ?>
					<?php if ( $p['sold_out'] ) : ?>
						<span class="ans-et__date-flag">Not on sale</span>
					<?php endif; ?>
				</button>
			<?php endforeach; ?>
		</div>

		<div class="ans-et__panel" hidden>
			<p class="ans-et__panel-for"></p>
			<div class="ans-et__rows"></div>

			<div class="ans-et__bar">
				<p class="ans-et__total" aria-live="polite"></p>
				<button type="button" class="ans-et__go" disabled>Add to cart</button>
			</div>

			<p class="ans-et__err" role="alert" hidden></p>
		</div>

		<script type="application/json" class="ans-et__data"><?php echo wp_json_encode( $payload ); ?></script>
	</div>
	<?php
	return (string) ob_get_clean();
}

add_shortcode( 'ans_event_tickets', 'ans_et_render' );

/**
 * Assets. Registered always, enqueued only when the block/shortcode is present,
 * so a page without tickets does not carry the JS.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		$base = plugin_dir_url( dirname( __FILE__ ) );

		wp_register_style( 'ans-event-tickets', $base . 'assets/css/event-tickets.css', array(), ANS_TB_VERSION );
		wp_register_script( 'ans-event-tickets', $base . 'assets/js/event-tickets.js', array(), ANS_TB_VERSION, true );

		if ( is_singular() ) {
			$post = get_post();

			if ( $post && ( has_shortcode( $post->post_content, 'ans_event_tickets' ) || has_block( 'ans/event-tickets', $post ) ) ) {
				wp_enqueue_style( 'ans-event-tickets' );
				wp_enqueue_script( 'ans-event-tickets' );
			}
		}
	}
);

/**
 * Register the block.
 *
 * Server-rendered: `save` returns null in the editor script and the front end
 * is produced by ans_et_render(). That keeps ONE implementation of the markup
 * — the alternative, saving markup into post content, would mean every future
 * change needed a block-recovery pass across every page using it, which is the
 * exact per-page churn this is meant to end.
 */
add_action(
	'init',
	function () {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$base = plugin_dir_url( dirname( __FILE__ ) );

		wp_register_script(
			'ans-event-tickets-editor',
			$base . 'assets/js/event-tickets-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			ANS_TB_VERSION,
			true
		);

		register_block_type(
			'ans/event-tickets',
			array(
				'api_version'     => 2,
				'title'           => 'Event Tickets',
				'category'        => 'widgets',
				'icon'            => 'tickets-alt',
				'editor_script'   => 'ans-event-tickets-editor',
				'render_callback' => 'ans_et_render',
				'attributes'      => array(
					'events'         => array( 'type' => 'string', 'default' => '' ),
					'event_category' => array( 'type' => 'string', 'default' => '' ),
					'heading'        => array( 'type' => 'string', 'default' => 'Choose your night' ),
					'cart_url'       => array( 'type' => 'string', 'default' => '' ),
					'empty_text'     => array( 'type' => 'string', 'default' => 'Tickets for this program are not on sale yet.' ),
				),
			)
		);
	}
);
