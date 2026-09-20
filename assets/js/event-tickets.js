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

		// The sold-out escape hatch.
		var reqWrap = root.querySelector( '.ans-et__request' );
		var reqOpen = root.querySelector( '.ans-et__request-open' );
		var reqForm = root.querySelector( '.ans-et__request-form' );
		var reqLead = root.querySelector( '.ans-et__request-lead' );
		var reqMsg  = root.querySelector( '.ans-et__request-msg' );
		var reqTier = null;   // which sold-out tier the request is about

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

				/*
				 * A finished tier gets NO stepper at all.
				 *
				 * Not a disabled one — none. Disabling still invites the click
				 * and still has to be enforced somewhere else; removing the
				 * control makes adding a sold-out ticket impossible rather than
				 * merely refused. The server still refuses it too, because a
				 * front end is never the place a limit actually lives.
				 */
				if ( t.sold_out ) {
					row.className += ' is-soldout';

					var gone = document.createElement( 'div' );
					gone.className = 'ans-et__row-gone';
					gone.textContent = 'Sold out';

					row.appendChild( label );
					row.appendChild( gone );
					rowsEl.appendChild( row );
					return;
				}

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
					// Never let the stepper exceed what is actually left. 20 stays
					// the ceiling when nothing is capped (remaining === null).
					var ceiling = ( null === t.remaining || undefined === t.remaining ) ? 20 : Math.min( 20, t.remaining );
					var next = Math.max( 0, Math.min( ceiling, ( qty[ t.id ] || 0 ) + delta ) );
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
			paintRequest();

			errEl.hidden = true;
			panel.hidden = false;
		}

		/**
		 * Show the escape hatch only when this night actually has a gone tier.
		 *
		 * Resets the form every time the night changes — otherwise a half-typed
		 * request from one performance silently submits against another.
		 */
		function paintRequest() {
			if ( ! reqWrap ) {
				return;
			}

			var gone = current.tickets.filter( function ( t ) { return t.sold_out; } );

			reqWrap.hidden = gone.length === 0;
			if ( reqForm ) {
				reqForm.hidden = true;
			}
			if ( reqMsg ) {
				reqMsg.textContent = '';
			}
			if ( reqOpen ) {
				reqOpen.hidden = false;
			}

			if ( ! gone.length || ! reqLead ) {
				return;
			}

			var names = gone.map( function ( t ) { return t.label; } );
			var all   = gone.length === current.tickets.length;

			reqLead.textContent = all
				? 'This performance is sold out.'
				: names.join( ' and ' ) + ( names.length > 1 ? ' tickets are' : ' tickets are' ) + ' sold out for this performance.';

			reqLead.textContent += ' If you need a seat, ask us — we read every request.';

			// Default the request to the first gone tier on this night.
			reqTier = gone[ 0 ];
		}

		root.querySelectorAll( '.ans-et__date' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				select( parseInt( btn.getAttribute( 'data-event' ), 10 ), btn );
			} );
		} );

		if ( reqOpen && reqForm ) {
			reqOpen.addEventListener( 'click', function () {
				reqForm.hidden = false;
				reqOpen.hidden = true;
				var first = reqForm.querySelector( 'input[name="name"]' );
				if ( first ) {
					first.focus();
				}
			} );
		}

		if ( reqForm ) {
			reqForm.addEventListener( 'submit', function ( ev ) {
				ev.preventDefault();

				if ( ! current || ! reqTier ) {
					return;
				}

				var send = reqForm.querySelector( '.ans-et__request-send' );
				var val  = function ( n ) {
					var el = reqForm.querySelector( '[name="' + n + '"]' );
					return el ? el.value : '';
				};

				send.disabled = true;
				send.textContent = 'Sending…';
				reqMsg.textContent = '';

				fetch( D.requestUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						nonce:      D.requestNonce,
						event_id:   current.event,
						product_id: reqTier.id,
						qty:        parseInt( val( 'qty' ), 10 ) || 1,
						name:       val( 'name' ),
						email:      val( 'email' ),
						message:    val( 'message' ),
						website:    val( 'website' )
					} )
				} )
					.then( function ( r ) {
						return r.json().then( function ( d ) {
							// A REST WP_Error still returns JSON with a message.
							// Show what the server actually said, not a shrug.
							if ( ! r.ok ) {
								throw new Error( d && d.message ? d.message : 'That request could not be sent.' );
							}
							return d;
						} );
					} )
					.then( function ( d ) {
						reqForm.hidden = true;
						reqMsg.textContent = d.message || 'Thank you — your request has gone to our box office.';
						reqMsg.className = 'ans-et__request-msg is-ok';
					} )
					.catch( function ( e ) {
						reqMsg.textContent = e.message;
						reqMsg.className = 'ans-et__request-msg is-bad';
						send.disabled = false;
						send.textContent = 'Send request';
					} );
			} );
		}

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
									/*
									 * Carry the SERVER's reason, not just the tier
									 * name. Until 1.29.0 this threw item.label and
									 * the catch printed "Please try again", so a
									 * buyer hitting a real stock limit was told to
									 * retry something that could never succeed.
									 */
									return r.json().then( function ( d ) {
										var why = d && d.message ? d.message : '';
										var err = new Error( why || ( '“' + item.label + '” could not be added.' ) );
										err.tier = item.label;
										throw err;
									} );
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
						? 'Added ' + added.join( ', ' ) + '. ' + e.message + ' Your cart holds the tickets that succeeded.'
						: e.message;
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
