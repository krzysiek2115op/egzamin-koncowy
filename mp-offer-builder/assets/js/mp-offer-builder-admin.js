/**
 * Ekran budowy oferty (Krok 4.5): wiersze pozycji dodawane dynamicznie,
 * wyszukiwanie produktów przez własny endpoint AJAX (mp_offer_builder_search_products,
 * Krok 4.5 — wc_get_products(), nie wewnętrzna akcja WC-core), i wysyłka do
 * ISTNIEJĄCEGO mp_offer_builder_submit (Krok 2, class-mp-offer-builder-ajax.php —
 * NIETKNIĘTY). Dane wstrzyknięte przez wp_localize_script(): window.mpOfferBuilder.
 */
( function () {
	'use strict';

	function generateUuid() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = ( Math.random() * 16 ) | 0;
			var v = 'x' === c ? r : ( r & 0x3 ) | 0x8;
			return v.toString( 16 );
		} );
	}

	function addItemRow( data ) {
		var body = document.getElementById( 'mp-ob-items-body' );
		if ( ! body ) {
			return;
		}
		var row = document.createElement( 'tr' );
		row.innerHTML =
			'<td><input type="text" class="mp-ob-item-search regular-text" autocomplete="off" />' +
			'<div class="mp-ob-item-results"></div></td>' +
			'<td><input type="number" class="mp-ob-item-product-id small-text" min="1" required="required" /></td>' +
			'<td><input type="number" class="mp-ob-item-variation-id small-text" min="1" /></td>' +
			'<td><input type="number" class="mp-ob-item-qty small-text" min="1" value="1" required="required" /></td>' +
			'<td><button type="button" class="button mp-ob-remove-item">' + mpOfferBuilder.i18n.removeItem + '</button></td>';
		body.appendChild( row );

		// Prefill przy edycji istniejącej oferty. Guard na product_id, bo handler
		// przycisku "Dodaj pozycję" wołałby addItemRow z obiektem Event.
		if ( data && typeof data === 'object' && typeof data.product_id !== 'undefined' ) {
			if ( data.name ) {
				row.querySelector( '.mp-ob-item-search' ).value = data.name;
			}
			row.querySelector( '.mp-ob-item-product-id' ).value = data.product_id;
			if ( data.variation_id ) {
				row.querySelector( '.mp-ob-item-variation-id' ).value = data.variation_id;
			}
			if ( data.qty ) {
				row.querySelector( '.mp-ob-item-qty' ).value = data.qty;
			}
		}
	}

	function renderSearchResults( products, resultsBox, row ) {
		resultsBox.innerHTML = '';
		products.forEach( function ( product ) {
			var option = document.createElement( 'div' );
			option.className = 'mp-ob-item-result';
			option.textContent = product.name + ' (#' + product.id + ')';
			option.addEventListener( 'click', function () {
				row.querySelector( '.mp-ob-item-search' ).value = product.name;
				row.querySelector( '.mp-ob-item-product-id' ).value = product.id;
				resultsBox.innerHTML = '';
			} );
			resultsBox.appendChild( option );
		} );
	}

	function searchProducts( term, resultsBox, row ) {
		if ( ! term ) {
			resultsBox.innerHTML = '';
			return;
		}
		var url = mpOfferBuilder.ajaxUrl
			+ '?action=' + encodeURIComponent( mpOfferBuilder.searchAction )
			+ '&mp_ob_nonce=' + encodeURIComponent( mpOfferBuilder.nonce )
			+ '&term=' + encodeURIComponent( term );

		fetch( url, { credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json && json.success && Array.isArray( json.data ) ) {
					renderSearchResults( json.data, resultsBox, row );
				}
			} );
	}

	function collectItems() {
		var rows  = document.querySelectorAll( '#mp-ob-items-body tr' );
		var items = [];
		rows.forEach( function ( row ) {
			var productId   = row.querySelector( '.mp-ob-item-product-id' ).value;
			var variationId = row.querySelector( '.mp-ob-item-variation-id' ).value;
			var qty         = row.querySelector( '.mp-ob-item-qty' ).value;
			if ( ! productId || ! qty ) {
				return;
			}
			var item = { product_id: parseInt( productId, 10 ), qty: parseInt( qty, 10 ) };
			if ( variationId ) {
				item.variation_id = parseInt( variationId, 10 );
			}
			items.push( item );
		} );
		return items;
	}

	function collectClient( form ) {
		var nameEl = document.getElementById( 'mp-ob-client-name' );
		if ( ! nameEl || nameEl.readOnly ) {
			// Draft z Kroku 2.5 dostarcza dane klienta ze snapshotu — nie wysyłamy
			// pól tylko-do-odczytu ponownie (Dział 1 dociąga je z offer_id).
			return {};
		}
		return {
			name: nameEl.value,
			email: document.getElementById( 'mp-ob-client-email' ).value,
			nip: document.getElementById( 'mp-ob-client-nip' ).value,
			country: document.getElementById( 'mp-ob-client-country' ).value,
		};
	}

	function onSubmit( event ) {
		event.preventDefault();

		var form    = event.target;
		var message = document.getElementById( 'mp-ob-form-message' );
		var offerId = parseInt( form.querySelector( '[name="offer_id"]' ).value, 10 ) || 0;

		var payload = {
			input: {
				client: collectClient( form ),
				items: collectItems(),
				wariant: form.querySelector( '[name="wariant"]' ).value,
				lang: form.querySelector( '[name="lang"]' ).value,
			},
			request_id: generateUuid(),
		};
		if ( offerId > 0 ) {
			payload.offer_id = offerId;
		}

		message.textContent = '';

		var url = mpOfferBuilder.ajaxUrl
			+ '?action=' + encodeURIComponent( mpOfferBuilder.submitAction )
			+ '&mp_ob_nonce=' + encodeURIComponent( mpOfferBuilder.nonce );

		fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( payload ),
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					window.location.href = mpOfferBuilder.listUrl;
					return;
				}
				// Kontrakt AJAX celowo nie ujawnia błędów pól (class-mp-offer-builder-ajax.php,
				// NIETKNIĘTY) — komunikat ogólny + trace_id do odszukania w logu BD-2.
				var traceId = json && json.data && json.data.trace_id ? ' (trace_id: ' + json.data.trace_id + ')' : '';
				message.textContent = mpOfferBuilder.i18n.genericError + traceId;
			} )
			.catch( function () {
				message.textContent = mpOfferBuilder.i18n.genericError;
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var addButton = document.getElementById( 'mp-ob-add-item' );
		if ( addButton ) {
			// Zawinięte, żeby obiekt Event z kliknięcia nie trafił jako prefill.
			addButton.addEventListener( 'click', function () {
				addItemRow();
			} );
		}

		// Wypełnij pozycje przy edycji istniejącej oferty (JSON osadzony w PHP).
		var prefillEl = document.getElementById( 'mp-ob-prefill-items' );
		if ( prefillEl ) {
			try {
				var prefill = JSON.parse( prefillEl.textContent );
				if ( prefill && prefill.length ) {
					prefill.forEach( function ( item ) {
						addItemRow( item );
					} );
				}
			} catch ( e ) {} // eslint-disable-line no-empty
		}

		var itemsBody = document.getElementById( 'mp-ob-items-body' );
		if ( itemsBody ) {
			itemsBody.addEventListener( 'input', function ( event ) {
				if ( event.target.classList.contains( 'mp-ob-item-search' ) ) {
					var row = event.target.closest( 'tr' );
					searchProducts( event.target.value, row.querySelector( '.mp-ob-item-results' ), row );
				}
			} );
			itemsBody.addEventListener( 'click', function ( event ) {
				if ( event.target.classList.contains( 'mp-ob-remove-item' ) ) {
					event.target.closest( 'tr' ).remove();
				}
			} );
		}

		var form = document.getElementById( 'mp-offer-builder-form' );
		if ( form ) {
			form.addEventListener( 'submit', onSubmit );
		}
	} );
} )();
