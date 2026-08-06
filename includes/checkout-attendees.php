<?php
/**
 * Ars Nova Ticketing Bridge — collapse the checkout attendee fields.
 *
 * THE PROBLEM
 * Tickera renders first name / last name / email for EVERY ticket. A
 * five-concert Season Package therefore opens with fifteen empty fields before
 * the patron reaches payment. Almost nobody needs them: the default case is
 * "these are my tickets, send them to me".
 *
 * THE USEFUL CASE
 * Buying for other people — five tickets, four of them emailed to your kids.
 * Tickera supports this natively: with "Show email for ticket owners" on, each
 * attendee is emailed their own ticket. It just shouldn't be the default view.
 *
 * WHAT THIS DOES
 * Hides each ticket's attendee block behind an opt-in link reading
 * "Send this ticket to someone else". Closed: nothing to fill in, ticket goes
 * to the buyer. Open: name and email for that specific ticket.
 *
 * Depends on Tickera → Settings → General:
 *   Show attendee fields ............................. Yes
 *   Show attendee first and last name fields ......... Yes
 *   Show email for ticket owners ..................... Yes
 *   First / last name REQUIRED ....................... No   <- important
 * Required fields inside a collapsed block would make checkout impossible to
 * complete without opening every accordion. If someone flips those back to
 * required, this must be turned off with them.
 *
 * IMPLEMENTATION NOTE
 * WooCommerce's block checkout is React. Anything injected here can be wiped
 * when the tree re-renders (totals updating, address changing). So this never
 * moves nodes — it toggles a class and re-applies itself from a
 * MutationObserver. If it ever fails, the fields simply show as they do now:
 * degraded, not broken.
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.8.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_footer', 'ans_tb_attendee_accordion', 99 );

function ans_tb_attendee_accordion() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return;
    }
    if ( ! apply_filters( 'ans_tb_collapse_attendee_fields', true ) ) {
        return;
    }
    ?>
<style id="ans-attendee-accordion">
.ans-att-hidden{display:none !important}
.ans-att-toggle{display:inline-flex;align-items:center;gap:8px;background:none;border:0;padding:6px 0;margin:2px 0 10px;cursor:pointer;
  font-size:15px;font-weight:600;color:#0e1b3a;text-decoration:underline;text-underline-offset:3px}
.ans-att-toggle:hover{color:#8a6d24}
.ans-att-toggle__sign{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;
  background:#0e1b3a;color:#fff;font-size:14px;line-height:1;font-weight:700;text-decoration:none}
.ans-att-toggle[aria-expanded="true"] .ans-att-toggle__sign{background:#8a6d24}
.ans-att-hint{font-size:13px;color:#4a5570;margin:-4px 0 12px;font-style:italic}
</style>
<script id="ans-attendee-accordion-js">
(function(){
    var CLOSED = 'Send this ticket to someone else';
    var OPEN   = 'Send this ticket to me instead';
    var HINT   = 'Leave this closed and the ticket comes to you. Open it to email this one straight to someone else.';

    function decorate(){
        var wraps = document.querySelectorAll('.wp-block-checkout-owner-fields');
        if (!wraps.length) return;

        Array.prototype.forEach.call(wraps, function(wrap){
            var heads = wrap.querySelectorAll('h5');
            Array.prototype.forEach.call(heads, function(h5){
                if (!/attendee\s*info/i.test(h5.textContent || '')) return;

                var group = h5.parentElement;
                if (!group) return;

                // Already decorated and still intact? leave it alone.
                if (group.getAttribute('data-ans-att') === '1' &&
                    group.previousElementSibling &&
                    group.previousElementSibling.classList.contains('ans-att-toggle')) {
                    return;
                }
                group.setAttribute('data-ans-att', '1');

                // If the patron already typed something, keep it visible.
                var filled = false;
                Array.prototype.forEach.call(group.querySelectorAll('input'), function(i){
                    if (i.value && i.value.trim() !== '') filled = true;
                });

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'ans-att-toggle';
                btn.setAttribute('aria-expanded', filled ? 'true' : 'false');
                btn.innerHTML = '<span class="ans-att-toggle__sign">' + (filled ? '−' : '+') + '</span>' +
                                '<span class="ans-att-toggle__text">' + (filled ? OPEN : CLOSED) + '</span>';

                var hint = document.createElement('p');
                hint.className = 'ans-att-hint';
                hint.textContent = HINT;
                if (filled) hint.classList.add('ans-att-hidden');

                if (!filled) group.classList.add('ans-att-hidden');

                btn.addEventListener('click', function(){
                    var open = group.classList.contains('ans-att-hidden');
                    group.classList.toggle('ans-att-hidden', !open);
                    hint.classList.toggle('ans-att-hidden', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    btn.querySelector('.ans-att-toggle__sign').textContent = open ? '−' : '+';
                    btn.querySelector('.ans-att-toggle__text').textContent = open ? OPEN : CLOSED;
                    if (open) {
                        var f = group.querySelector('input');
                        if (f) f.focus();
                    } else {
                        // Closing means "send to me" — clear so no half-typed
                        // recipient is submitted invisibly.
                        Array.prototype.forEach.call(group.querySelectorAll('input'), function(i){
                            i.value = '';
                            i.dispatchEvent(new Event('input', {bubbles:true}));
                            i.dispatchEvent(new Event('change', {bubbles:true}));
                        });
                    }
                });

                group.parentNode.insertBefore(btn, group);
                group.parentNode.insertBefore(hint, group);
            });
        });
    }

    function boot(){
        decorate();
        var target = document.querySelector('.wc-block-checkout, form.checkout, body');
        if (!target || !window.MutationObserver) return;
        var pending = null;
        new MutationObserver(function(){
            // React re-renders can strip our button; re-apply, debounced.
            clearTimeout(pending);
            pending = setTimeout(decorate, 120);
        }).observe(target, {childList:true, subtree:true});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
</script>
    <?php
}
