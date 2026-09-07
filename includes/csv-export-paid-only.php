<?php
/**
 * Keep the Tickera CSV attendee export off tickets nobody paid for.
 *
 * ── The problem, measured on LIVE 2026-09-07 ────────────────────────────────
 *
 * Tickera mints a ticket instance when the ORDER is created, not when it is
 * paid. A failed or refunded checkout therefore leaves real, sequential ticket
 * codes behind. For the 12 September house concert that was 57 codes against a
 * 50-seat living room: 36 on completed orders, 16 on cancelled, 5 on refunded.
 *
 * Three of the four places those codes could surface already discard them:
 *
 *   - the door scanner  — Tickera gates check-in on `tickera_order_is_paid`,
 *                         which bridge-for-woocommerce answers true only for
 *                         WooCommerce `completed` and `processing`;
 *   - the Checkinera in-app attendee list — restricted to `post_status=publish`,
 *                         and the bridge trashes instances when an order is
 *                         cancelled or refunded;
 *   - Tickera's PDF attendee export — same `tickera_order_is_paid` gate.
 *
 * The Tickera CSV Export add-on (`csv-export`, 1.3.6) is the exception, and it
 * is the one a staff member actually takes to the door. Two faults compound:
 *
 *   1. `index.php:415` defaults the order-status filter to `['any']` when the
 *      saved setting is absent — and it is absent on this site. `'any'` then
 *      widens the query to `post_status => ['trash','publish','draft']`
 *      (`index.php:456-458`), deliberately pulling the trashed instances back
 *      in, and the per-row test at `:510` short-circuits on `'any'`. Result:
 *      57 rows, not 36.
 *
 *   2. The dropdown cannot be used to fix it. `class.fields.php:67` offers only
 *      Tickera-native values (`order_paid`, `order_cancelled`, …), while in
 *      Bridge mode the value compared at `:510` is the WooCommerce status
 *      (`class.resource.php:229` sets it from `$wc_order->get_status()`, i.e.
 *      `completed`). Nothing but "Any" matches anything, so selecting "Paid"
 *      produces an EMPTY export. There is no correct setting to tell staff to
 *      choose.
 *
 * That second point is why this lives here rather than in a settings change:
 * it is a plugin bug, and `csv-export` is third-party code that a Tickera
 * update would overwrite. Both fixes below are additive hooks on our side.
 *
 * ── What this does ─────────────────────────────────────────────────────────
 *
 * 1. Rewrites the dropdown to the WooCommerce statuses that actually match, so
 *    a deliberate choice works. Someone auditing voided tickets can still pick
 *    Cancelled or Refunded and get them.
 *
 * 2. Intercepts the export at priority 0 — before `csv-export`'s own handler at
 *    the default 10 — and replaces an absent, empty or "Any" selection with the
 *    paid statuses. An explicit non-"Any" choice is left alone, so the audit
 *    path above still works. `$_POST` is what the add-on reads
 *    (`index.php:410`), so this is the only place it can be corrected.
 *
 * Verify after any Tickera update: an export for event 6643 must return the
 * paid count, not the paid count plus the voided ones.
 *
 * @package ArsNovaTicketingBridge
 * @since   1.20.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The statuses that mean "this seat was actually bought".
 *
 * `wc-completed` / `wc-processing` mirror bridge-for-woocommerce's own
 * `tc_modify_order_is_paid()`, so the CSV agrees with the door scanner by
 * construction rather than by coincidence. `order_paid` is Tickera-native and
 * unused on this site today (`tc_orders` is empty) — carried so the export
 * stays correct if a native order ever exists.
 */
function ans_csv_paid_order_statuses() {
    return apply_filters(
        'ans_csv_export_paid_statuses',
        [ 'wc-completed', 'wc-processing', 'order_paid' ]
    );
}

/**
 * Replace the export screen's order-status options with values that match what
 * the add-on actually compares against in Bridge mode.
 */
add_filter( 'tc_csv_payment_statuses', 'ans_csv_export_status_options' );
function ans_csv_export_status_options( $statuses ) {
    return [
        'wc-completed'  => __( 'Completed (paid)', 'ans-tb' ),
        'wc-processing' => __( 'Processing (paid)', 'ans-tb' ),
        'wc-refunded'   => __( 'Refunded (ticket voided)', 'ans-tb' ),
        'wc-cancelled'  => __( 'Cancelled (ticket voided)', 'ans-tb' ),
        'wc-failed'     => __( 'Failed payment', 'ans-tb' ),
        'wc-pending'    => __( 'Pending payment', 'ans-tb' ),
        'any'           => __( 'Any — includes unpaid and voided tickets', 'ans-tb' ),
    ];
}

/**
 * Force a safe order-status filter before csv-export reads $_POST.
 *
 * Priority 0 so this runs ahead of the add-on's own handler on the same action.
 * Deliberately does NOT stop the export or alter anything else about it.
 */
add_action( 'wp_ajax_tc_export_attendee_list', 'ans_csv_force_paid_statuses', 0 );
function ans_csv_force_paid_statuses() {

    $paid = ans_csv_paid_order_statuses();

    // An escape hatch that returns [] disables this guard entirely.
    if ( empty( $paid ) ) {
        return;
    }

    $selected = isset( $_POST['tc_limit_order_type'] )
        ? array_filter( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['tc_limit_order_type'] ) ) )
        : [];

    // Absent, empty, or the unsafe "Any" — substitute the paid set.
    // A deliberate status choice is respected, so voided-ticket audits still work.
    if ( empty( $selected ) || in_array( 'any', $selected, true ) ) {
        $_POST['tc_limit_order_type'] = $paid;
    }
}
