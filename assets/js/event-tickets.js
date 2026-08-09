/**
 * Event Tickets — front-end behaviour.
 *
 * Deliberately dependency-free and framework-free. It runs on a normal page,
 * well before checkout, and touches nothing WooCommerce renders itself.
 *
 * NOTE FOR ANYONE EXTENDING THIS: do NOT reuse this pattern to inject anything
 * into the WooCommerce BLOCK CHECKOUT. That is what v1.8.3/v1.8.4 did — React
 * re-created the subtree, the observer re-inserted on every re-render, and the
 * page filled with duplicate elements until it had to be rolled back. This file
 * only ever writes into markup that it owns.
 */
( function () {
	'use strict';

	function money( n ) {
		return '$' + n.toFixed( 2 ).replace( /\.00$/, '' );
	}

	function init( root ) {
		var dataEl = root.querySelector( '.ans-et__data' );
		if ( ! dataEl ) {
			return;
		}

		var D;
		try {
			D = JSON.parse( dataEl.textContent );
		} catch ( e ) {
			return;
		}

		var panel   = root.querySelector( '.ans-et__panel' );
		var panelFor= root.querySelector( '.ans-et__panel-for' );
		var rowsEl  = root.querySelector( '.ans-et__rows' );
		var totalEl = root.querySelector( '.ans-et__total' );
		var goBtn   = root.querySelector( '.ans-et__go' );
		var errEl   = root.querySelector( '.ans-et__err' );

		var current = null;   // selected performance
		var qty     = {};     // productId -> quantity

		function byEvent( id ) {
			for ( var i = 0; i < D.performances.length; i++ ) {
				if ( D.performances[ i ].event === id ) {
					return D.performances[ i ];
				}
			}
			return null;
		}

		function totals() {
			var count = 0, sum = 0;

			current.tickets.forEach( function ( t ) {
				var q = qty[ t.id ] || 0;
				count += q;
				sum   += q * t.price;
			} );

			return { count: count, sum: sum };
		}

		function paint() {
			var t = totals();

			totalEl.textContent = t.count === 0
				? 'No tickets selected'
				: t.count + ( t.count === 1 ? ' ticket' : ' tickets' ) + ' · ' + money( t.sum );

			goBtn.disabled = t.count === 0;
		}

		function renderRows() {
			rowsEl.innerHTML = '';
			qty = {};

			current.tickets.forEach( function ( t ) {
				qty[ t.id ] = 0;

				var row = document.createElement( 'div' );
				row.className = 'ans-et__row';

				var label = document.createElement( 'div' );
				label.className = 'ans-et__row-label';
				label.innerHTML = '<span class="ans-et__row-tier"></span><span class="ans-et__row-price"></span>';
				label.querySelector( '.ans-et__row-tier' ).textContent  = t.label;
				label.querySelector( '.ans-et__row-price' ).textContent = t.price_h;

				var stepper = document.createElement( 'div' );
				stepper.className = 'ans-et__stepper';

				var minus  = document.createElement( 'button' );
				minus.type = 'button';
				minus.className = 'ans-et__step';
				minus.textContent = '−';
				minus.setAttribute( 'aria-label', 'Remove one ' + t.label + ' ticket' );

				var out = document.createElement( 'output' );
				out.className = 'ans-et__qty';
				out.textContent = '0';

				var plus  = document.createElement( 'button' );
				plus.type = 'button';
				plus.className = 'ans-et__step';
				plus.textContent = '+';
				plus.setAttribute( 'aria-label', 'Add one ' + t.label + ' ticket' );

				function bump( delta ) {
					var next = Math.max( 0, Math.min( 20, ( qty[ t.id ] || 0 ) + delta ) );
					qty[ t.id ] = next;
					out.textContent = String( next );
					row.classList.toggle( 'is-on', next > 0 );
					paint();
				}

				minus.addEventListener( 'click', function () { bump( -1 ); } );
				plus.addEventListener( 'click', function () { bump( 1 ); } );

				stepper.appendChild( minus );
				stepper.appendChild( out );
				stepper.appendChild( plus );

				row.appendChild( label );
				row.appendChild( stepper );
				rowsEl.appendChild( row );
			} );
		}

		function select( id, btn ) {
			current = byEvent( id );
			if ( ! current ) {
				return;
			}

			root.querySelectorAll( '.ans-et__date' ).forEach( function ( b ) {
				b.classList.toggle( 'is-on', b === btn );
			} );

			panelFor.textContent = [ current.day, current.date, current.time ]
				.filter( Boolean ).join( ' · ' ) + ( current.venue ? ' — ' + current.venue : '' );

			renderRows();
			paint();

			errEl.hidden = true;
			panel.hidden = false;
		}

		root.querySelectorAll( '.ans-et__date' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				select( parseInt( btn.getAttribute( 'data-event' ), 10 ), btn );
			} );
		} );

		/**
		 * Add to cart.
		 *
		 * Store API, same probe-then-header nonce dance the packages picker uses:
		 * GET /cart to obtain the Nonce response header, then POST each item.
		 *
		 * Adds are SEQUENTIAL and stop at the first failure, and the error names
		 * exactly what did and did not make it into the cart. The packages picker
		 * fires its adds without that guard, which can leave a silently partial
		 * cart — the failure mode is inherited from the pattern, not fixed by it.
		 */
		goBtn.addEventListener( 'click', function () {
			var items = current.tickets
				.filter( function ( t ) { return ( qty[ t.id ] || 0 ) > 0; } )
				.map( function ( t ) { return { id: t.id, quantity: qty[ t.id ], label: t.label }; } );

			if ( ! items.length ) {
				return;
			}

			goBtn.disabled = true;
			goBtn.textContent = 'Adding…';
			errEl.hidden = true;

			var added = [];

			fetch( D.restUrl + 'cart', { credentials: 'same-origin' } )
				.then( function ( res ) {
					var nonce = res.headers.get( 'Nonce' ) || res.headers.get( 'X-WC-Store-API-Nonce' );

					return items.reduce( function ( chain, item ) {
						return chain.then( function () {
							return fetch( D.restUrl + 'cart/add-item', {
								method: 'POST',
								credentials: 'same-origin',
								headers: {
									'Content-Type': 'application/json',
									'Nonce': nonce
								},
								body: JSON.stringify( { id: item.id, quantity: item.quantity } )
							} ).then( function ( r ) {
								if ( ! r.ok ) {
									throw new Error( item.label );
								}
								added.push( item.label );
							} );
						} );
					}, Promise.resolve() );
				} )
				.then( function () {
					window.location.href = D.cartUrl;
				} )
				.catch( function ( e ) {
					errEl.textContent = added.length
						? 'Added ' + added.join( ', ' ) + ', but “' + e.message + '” could not be added. Your cart holds the tickets that succeeded.'
						: 'Sorry — those tickets could not be added. Please try again.';
					errEl.hidden = false;
					goBtn.disabled = false;
					goBtn.textContent = 'Add to cart';
				} );
		} );

		paint();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-ans-et]' ).forEach( init );
	} );
}() );
