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
 *   2. Nobody has to get that setting wrong for the export to be wrong — it is
 *      wrong when left alone, which is the state every fresh export screen
 *      starts in.
 *
 * ⚠️ CORRECTED 2026-09-07, same session, before this shipped to LIVE. An earlier
 * version of this file claimed the dropdown could not express a working value:
 * that `class.fields.php:67` offers only Tickera-native names (`order_paid`,
 * `order_cancelled`, …) while `:510` compares a WooCommerce status, so nothing
 * but "Any" could ever match. **That is false.**
 * `bridge-for-woocommerce.php:434` already replaces the whole option list via
 * this same `tc_csv_payment_statuses` filter (`modify_csv_payment_statuses()`,
 * `:3028`) with `any / wc-completed / wc-processing / wc-on-hold / wc-cancelled
 * / wc-pending / wc-refunded`. Picking "Completed" by hand always worked.
 *
 * The claim was drawn from reading `csv-export` on its own and never checking
 * who else hooked the same filter — the same mistake this branch made about
 * Checkinera an hour earlier, where the validation lived in a different plugin
 * entirely. It was caught by running the code on staging and noticing the
 * dropdown returned options this file had not written.
 *
 * So the fix is narrower than first described, and this file no longer touches
 * the dropdown at all: the bridge's list is already correct and a filter of ours
 * at the same priority would be silently discarded. What remains — and what is
 * worth fixing in code rather than in a setting — is that the DEFAULT is unsafe,
 * `csv-export` is third-party code a Tickera update would overwrite, and a door
 * list should not depend on a human remembering a dropdown.
 *
 * ── What this does ─────────────────────────────────────────────────────────
 *
 * Intercepts the export at priority 0 — before `csv-export`'s own handler at the
 * default 10 — and replaces an absent, empty or "Any" selection with the paid
 * statuses. `$_POST` is what the add-on reads (`index.php:410`), so this is the
 * only place it can be corrected.
 *
 * An explicit non-"Any" choice is left alone, so someone auditing voided tickets
 * can still pick Cancelled or Refunded from the bridge's dropdown and get them.
 *
 * Verify after any Tickera update: an export for event 6643 must return the
 * paid count, not the paid count plus the voided ones.
 *
 * @package ArsNovaTicketingBridge
 * @since   1.20.0 (dropdown filter removed in 1.20.1 — see correction above)
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
