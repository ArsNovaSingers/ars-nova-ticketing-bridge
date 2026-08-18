<?php
/**
 * Ars Nova Ticketing Bridge — [ans_season_packages]
 *
 * The season-packages / subscriptions purchase page.
 *
 * DESIGN NOTE — read before changing anything here.
 * This shortcode deliberately does NOT create a bundle product, a container
 * product, or any new purchasable object. It is a *guided way to add ordinary
 * ticket products to the cart*. The patron picks a tier and their nights, and
 * we add the same real Tickera ticket products they'd get by browsing concert
 * pages one at a time.
 *
 * That is the whole point:
 *   - Tickera issues real per-event tickets, PDFs and check-in codes, because
 *     nothing about the line items is unusual.
 *   - Checkout is the ordinary path, already proven.
 *   - Pricing is handled entirely by the cart discount rules. This file does
 *     not calculate or apply a single discount.
 *
 * Anything that replaces this with a container product re-opens
 * Tickera_Wiki_01 trap #1 (orders not shaped the way Tickera expects generate
 * no tickets, silently).
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Parent product_cat term whose children are the packageable concerts. */
if ( ! defined( 'ANS_PKG_PARENT_TERM' ) ) {
    define( 'ANS_PKG_PARENT_TERM', 86 );
}

add_shortcode( 'ans_season_packages', 'ans_pkg_render' );

/**
 * Tier definitions. Prices are display-only — the cart discount rules are what
 * actually charge the patron. Keep these in step with the rules, and with the
 * ANS 26-27 Ticket Pricing sheet, which is the source of truth for both.
 */
function ans_pkg_tiers() {
    return apply_filters( 'ans_pkg_tiers', array(
        'flex3' => array(
            'key'    => 'flex3',
            'name'   => 'Flex Pass',
            'pick'   => 3,
            'price'  => 102,
            'per'    => 34,
            'save'   => '15% off',
            'blurb'  => 'Choose any three concerts. Mix and match to suit your schedule.',
        ),
        'season5' => array(
            'key'    => 'season5',
            'name'   => 'Season Package',
            'pick'   => 5,
            'price'  => 160,
            'per'    => 32,
            'save'   => '20% off',
            'blurb'  => 'All five mainstage concerts, with your choice of night for each.',
        ),
        'circle' => array(
            'key'    => 'circle',
            'name'   => 'Nova Circle',
            'pick'   => 5,
            'price'  => 300,
            'per'    => 0,
            'save'   => 'Premium tier',
            'blurb'  => 'All five concerts plus reserved premium seating, guest artist events and priority renewal.',
            'extra_sku' => 'ANS-PKG-CIRCLE-FEE',
        ),
    ) );
}

/**
 * The packageable concerts, each with its performances.
 *
 * Reads the product_cat children of ANS_PKG_PARENT_TERM — so adding a
 * performance means categorising one product, not editing this file.
 *
 * @return array [ ['term_id','name','performances'=>[['id','date_ts','when','where','price']]] ]
 */
function ans_pkg_concerts() {
    if ( ! taxonomy_exists( 'product_cat' ) ) {
        return array();
    }
    $terms = get_terms( array(
        'taxonomy'   => 'product_cat',
        'parent'     => (int) ANS_PKG_PARENT_TERM,
        'hide_empty' => false,
    ) );
    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return array();
    }

    $out = array();
    foreach ( $terms as $t ) {
        $product_ids = get_posts( array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'fields'         => 'ids',
            'tax_query'      => array( array(
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => (int) $t->term_id,
            ) ),
        ) );

        $perfs = array();
        foreach ( $product_ids as $pid ) {
            $event_id = (int) get_post_meta( $pid, '_event_name', true );
            $ts       = 0;
            $where    = '';
            if ( $event_id ) {
                // MUST go through ans_tb_local_ts(): the stored value is a naive
                // site-local wall clock and WordPress runs PHP in UTC, so a bare
                // strtotime() here printed every performance 6-7 hours early on
                // this page. See the helper's docblock in the main plugin file.
                $ts    = ans_tb_event_ts( $event_id );
                $where = (string) get_post_meta( $event_id, 'event_location', true );
            }
            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
            if ( ! $product || ! $product->is_purchasable() ) {
                continue;
            }
            $perfs[] = array(
                'id'      => $pid,
                'date_ts' => $ts,
                'when'    => $ts ? wp_date( 'D, M j · g:i a', $ts ) : get_the_title( $pid ),
                'where'   => function_exists( 'ans_sp_place' ) ? ans_sp_place( $where ) : $where,
                'price'   => (float) $product->get_regular_price(),
            );
        }
        if ( empty( $perfs ) ) {
            continue;
        }
        usort( $perfs, function ( $a, $b ) {
            return $a['date_ts'] <=> $b['date_ts'];
        } );

        $out[] = array(
            'term_id'      => (int) $t->term_id,
            'name'         => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
            'first_ts'     => $perfs[0]['date_ts'],
            'performances' => $perfs,
        );
    }

    usort( $out, function ( $a, $b ) {
        return $a['first_ts'] <=> $b['first_ts'];
    } );
    return $out;
}

function ans_pkg_styles() {
    static $done = false;
    if ( $done ) {
        return '';
    }
    $done = true;
    return '<style id="ans-season-packages">
.ans-pkg{--ans-navy:#0e1b3a;--ans-gold:#c7a24a;--ans-cream:#f5f1e8;max-width:1080px;margin:0 auto}
.ans-pkg__tiers{display:flex;gap:20px;flex-wrap:wrap;margin:0 0 40px}
.ans-pkg__tier{flex:1 1 260px;border:2px solid rgba(14,27,58,.16);border-radius:14px;padding:26px 24px;cursor:pointer;background:#fff;transition:border-color .15s,box-shadow .15s}
.ans-pkg__tier:hover{border-color:var(--ans-gold)}
.ans-pkg__tier.is-on{border-color:var(--ans-navy);box-shadow:0 6px 26px rgba(14,27,58,.13)}
.ans-pkg__tier h3{font-size:23px;margin:0 0 6px;color:var(--ans-navy)}
.ans-pkg__price{font-size:34px;font-weight:700;color:var(--ans-navy);margin:0 0 2px}
.ans-pkg__per{font-size:13px;letter-spacing:1.5px;text-transform:uppercase;color:#8a6d24;margin:0 0 12px}
.ans-pkg__blurb{font-size:15px;line-height:1.6;color:#3a4560;margin:0}
.ans-pkg__step{font-size:13px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;color:#8a6d24;margin:0 0 14px}
.ans-pkg__concerts{display:flex;flex-direction:column;gap:14px;margin:0 0 28px}
.ans-pkg__concert{border:2px solid rgba(14,27,58,.14);border-radius:12px;padding:18px 20px;background:#fff}
.ans-pkg__concert.is-on{border-color:var(--ans-navy);background:rgba(14,27,58,.035)}
.ans-pkg__concert.is-off{opacity:.55}
.ans-pkg__ctitle{display:flex;align-items:center;gap:12px;font-size:20px;font-weight:700;color:var(--ans-navy);margin:0}
.ans-pkg__ctitle input{width:20px;height:20px;accent-color:#0e1b3a;flex:0 0 auto}
.ans-pkg__nights{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 0 32px}
.ans-pkg__night{display:inline-flex;align-items:center;gap:7px;border:1px solid rgba(14,27,58,.22);border-radius:30px;padding:7px 15px;font-size:14px;cursor:pointer;color:#25304a}
.ans-pkg__night:hover{border-color:var(--ans-gold)}
.ans-pkg__night input{accent-color:#0e1b3a}
.ans-pkg__night.is-on{background:var(--ans-navy);border-color:var(--ans-navy);color:#fff}
.ans-pkg__where{opacity:.72;font-size:13px}
.ans-pkg__bar{position:sticky;bottom:0;background:var(--ans-cream);border-top:2px solid rgba(14,27,58,.14);padding:18px 22px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;border-radius:12px 12px 0 0}
.ans-pkg__count{font-size:16px;color:#25304a;flex:1 1 auto;margin:0}
.ans-pkg__count strong{color:var(--ans-navy)}
.ans-pkg__seats{display:flex;align-items:center;gap:9px;font-size:14px;color:#25304a}
.ans-pkg__seats select{padding:7px 10px;border-radius:8px;border:1px solid rgba(14,27,58,.28)}
.ans-pkg__go{background:var(--ans-navy);color:#fff;border:0;font-size:14px;font-weight:700;letter-spacing:1px;text-transform:uppercase;padding:14px 34px;border-radius:40px;cursor:pointer}
.ans-pkg__go:hover:not(:disabled){background:var(--ans-gold);color:var(--ans-navy)}
.ans-pkg__go:disabled{opacity:.42;cursor:not-allowed}
.ans-pkg__note{font-size:14px;color:#3a4560;margin:18px 0 0;font-style:italic}
.ans-pkg__err{color:#7a1f2b;font-size:14px;margin:10px 0 0}
.ans-pkg__empty{font-size:17px;color:#3a4560;font-style:italic}
@media (max-width:781px){.ans-pkg__nights{margin-left:0}.ans-pkg__bar{flex-direction:column;align-items:stretch}.ans-pkg__go{width:100%}}
</style>';
}

/** [ans_season_packages] */
function ans_pkg_render( $atts ) {
    $a = shortcode_atts( array(
        'cart_url'   => '',
        'empty_text' => 'Season packages will be available here shortly.',
    ), $atts, 'ans_season_packages' );

    $concerts = ans_pkg_concerts();
    if ( empty( $concerts ) ) {
        return '<div class="ans-pkg"><p class="ans-pkg__empty">' . esc_html( $a['empty_text'] ) . '</p></div>';
    }

    $tiers = ans_pkg_tiers();

    // Nova Circle needs its membership product to exist; hide the tier otherwise.
    foreach ( $tiers as $k => $t ) {
        if ( empty( $t['extra_sku'] ) ) {
            continue;
        }
        $extra_id = function_exists( 'ans_tb_product_id_by_sku' ) ? ans_tb_product_id_by_sku( $t['extra_sku'] ) : 0;
        if ( ! $extra_id ) {
            unset( $tiers[ $k ] );
            continue;
        }
        $tiers[ $k ]['extra_id'] = (int) $extra_id;
    }

    $cart_url = $a['cart_url'] ? $a['cart_url'] : ( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '/cart/' );

    $payload = array(
        'tiers'    => array_values( $tiers ),
        'concerts' => $concerts,
        'cartUrl'  => $cart_url,
        'restUrl'  => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
    );

    ob_start();
    echo ans_pkg_styles(); // phpcs:ignore WordPress.Security.EscapeOutput
    ?>
<div class="ans-pkg" id="ans-pkg-app">
    <p class="ans-pkg__step">Step 1 — choose your package</p>
    <div class="ans-pkg__tiers">
        <?php foreach ( $tiers as $t ) : ?>
        <div class="ans-pkg__tier" data-tier="<?php echo esc_attr( $t['key'] ); ?>" role="button" tabindex="0">
            <h3><?php echo esc_html( $t['name'] ); ?></h3>
            <p class="ans-pkg__price">$<?php echo esc_html( number_format_i18n( $t['price'] ) ); ?></p>
            <p class="ans-pkg__per">
                <?php echo esc_html( $t['per'] ? '$' . $t['per'] . ' per concert · ' . $t['save'] : $t['save'] ); ?>
            </p>
            <p class="ans-pkg__blurb"><?php echo esc_html( $t['blurb'] ); ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <div id="ans-pkg-picker" hidden>
        <p class="ans-pkg__step" id="ans-pkg-steptext">Step 2 — choose your concerts and nights</p>
        <div class="ans-pkg__concerts">
            <?php foreach ( $concerts as $c ) : ?>
            <div class="ans-pkg__concert" data-term="<?php echo esc_attr( $c['term_id'] ); ?>">
                <label class="ans-pkg__ctitle">
                    <input type="checkbox" class="ans-pkg__pick" value="<?php echo esc_attr( $c['term_id'] ); ?>">
                    <span><?php echo esc_html( $c['name'] ); ?></span>
                </label>
                <div class="ans-pkg__nights">
                    <?php foreach ( $c['performances'] as $i => $p ) : ?>
                    <label class="ans-pkg__night<?php echo 0 === $i ? ' is-on' : ''; ?>">
                        <input type="radio"
                               name="night-<?php echo esc_attr( $c['term_id'] ); ?>"
                               value="<?php echo esc_attr( $p['id'] ); ?>"
                               <?php checked( 0, $i ); ?>>
                        <span><?php echo esc_html( $p['when'] ); ?></span>
                        <?php if ( $p['where'] ) : ?>
                        <span class="ans-pkg__where"><?php echo esc_html( $p['where'] ); ?></span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="ans-pkg__bar">
            <p class="ans-pkg__count" id="ans-pkg-count"></p>
            <label class="ans-pkg__seats">Seats
                <select id="ans-pkg-seats">
                    <?php for ( $s = 1; $s <= 8; $s++ ) : ?>
                    <option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <button class="ans-pkg__go" id="ans-pkg-go" disabled>Add to cart</button>
        </div>
        <p class="ans-pkg__err" id="ans-pkg-err" hidden></p>
        <p class="ans-pkg__note">Your package discount is applied automatically in the cart. Each concert is a real ticket — you'll receive one per performance.</p>
    </div>
</div>
<script>
(function(){
    var D = <?php echo wp_json_encode( $payload ); ?>;
    var app = document.getElementById('ans-pkg-app');
    if (!app) return;
    var picker = document.getElementById('ans-pkg-picker');
    var countEl = document.getElementById('ans-pkg-count');
    var stepEl = document.getElementById('ans-pkg-steptext');
    var goBtn = document.getElementById('ans-pkg-go');
    var errEl = document.getElementById('ans-pkg-err');
    var seatsEl = document.getElementById('ans-pkg-seats');
    var tier = null;

    function tierByKey(k){ return D.tiers.filter(function(t){ return t.key === k; })[0]; }
    function picks(){ return Array.prototype.slice.call(app.querySelectorAll('.ans-pkg__pick:checked')); }

    function paintNights(){
        Array.prototype.forEach.call(app.querySelectorAll('.ans-pkg__night'), function(l){
            var r = l.querySelector('input');
            l.classList.toggle('is-on', !!(r && r.checked));
        });
    }

    function sync(){
        if (!tier) return;
        var n = picks().length;
        var need = tier.pick;
        var full = n >= need;

        Array.prototype.forEach.call(app.querySelectorAll('.ans-pkg__concert'), function(c){
            var box = c.querySelector('.ans-pkg__pick');
            var on = box.checked;
            c.classList.toggle('is-on', on);
            // When the tier is satisfied, grey out (but never disable) the rest.
            c.classList.toggle('is-off', full && !on);
        });

        countEl.innerHTML = '<strong>' + n + ' of ' + need + '</strong> concerts chosen' +
            (n === need ? ' — ' + tier.name + ', $' + tier.price : '');
        goBtn.disabled = (n !== need);
        paintNights();
    }

    Array.prototype.forEach.call(app.querySelectorAll('.ans-pkg__tier'), function(el){
        function choose(){
            tier = tierByKey(el.getAttribute('data-tier'));
            Array.prototype.forEach.call(app.querySelectorAll('.ans-pkg__tier'), function(o){
                o.classList.toggle('is-on', o === el);
            });
            picker.hidden = false;
            var all = tier.pick >= D.concerts.length;
            Array.prototype.forEach.call(app.querySelectorAll('.ans-pkg__pick'), function(b){
                b.checked = all;
            });
            stepEl.textContent = all
                ? 'Step 2 — choose a night for each concert'
                : 'Step 2 — choose any ' + tier.pick + ' concerts, and a night for each';
            sync();
            picker.scrollIntoView({behavior:'smooth', block:'start'});
        }
        el.addEventListener('click', choose);
        el.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(); }
        });
    });

    app.addEventListener('change', function(e){
        if (e.target.classList.contains('ans-pkg__pick')) sync();
        if (e.target.type === 'radio') paintNights();
    });

    goBtn.addEventListener('click', async function(){
        if (!tier) return;
        errEl.hidden = true;
        goBtn.disabled = true;
        var original = goBtn.textContent;
        goBtn.textContent = 'Adding…';
        try {
            var qty = parseInt(seatsEl.value, 10) || 1;
            var ids = picks().map(function(b){
                var wrap = b.closest('.ans-pkg__concert');
                var r = wrap.querySelector('input[type=radio]:checked');
                return parseInt(r.value, 10);
            });
            if (tier.extra_id) ids.push(parseInt(tier.extra_id, 10));

            var probe = await fetch(D.restUrl + 'cart', {credentials:'same-origin'});
            var nonce = probe.headers.get('Nonce') || probe.headers.get('X-WC-Store-API-Nonce');

            for (var i = 0; i < ids.length; i++) {
                var res = await fetch(D.restUrl + 'cart/add-item', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type':'application/json', 'Nonce': nonce},
                    body: JSON.stringify({id: ids[i], quantity: qty})
                });
                if (!res.ok) throw new Error('Could not add one of the tickets (product ' + ids[i] + ').');
            }
            window.location.href = D.cartUrl;
        } catch (err) {
            errEl.textContent = err.message + ' Please try again, or add the concerts individually from the concert pages.';
            errEl.hidden = false;
            goBtn.disabled = false;
            goBtn.textContent = original;
        }
    });
})();
</script>
    <?php
    return ob_get_clean();
}
