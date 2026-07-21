/*
 * MP Lead Intake — wysyłka formularza przez "1 AJAX".
 * Wanilia JS, bez zależności. Wysyła dane do admin-ajax.php i pokazuje wynik.
 */
( function () {
	'use strict';

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form.classList || ! form.classList.contains( 'mp-lead-form' ) ) {
			return;
		}
		e.preventDefault();

		if ( typeof window.MP_LEAD_INTAKE === 'undefined' ) {
			return;
		}

		var msg = form.querySelector( '.mp-form-msg' );
		var btn = form.querySelector( 'button[type="submit"]' );

		var data = new URLSearchParams( new FormData( form ) );
		data.append( 'action', window.MP_LEAD_INTAKE.action );

		if ( btn ) {
			btn.disabled = true;
		}
		if ( msg ) {
			msg.className = 'mp-form-msg';
			msg.textContent = 'Wysyłanie…';
		}

		fetch( window.MP_LEAD_INTAKE.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: data.toString()
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( res && res.success ) {
					form.reset();
					if ( msg ) {
						msg.className = 'mp-form-msg mp-ok';
						msg.textContent = ( res.data && res.data.message ) ? res.data.message : 'Dziękujemy! Zapytanie wysłane.';
					}
				} else {
					if ( msg ) {
						msg.className = 'mp-form-msg mp-err';
						msg.textContent = ( res && res.data && res.data.message ) ? res.data.message : 'Wystąpił błąd. Spróbuj ponownie.';
					}
				}
			} )
			.catch( function () {
				if ( msg ) {
					msg.className = 'mp-form-msg mp-err';
					msg.textContent = 'Błąd połączenia. Spróbuj ponownie.';
				}
			} )
			.finally( function () {
				if ( btn ) {
					btn.disabled = false;
				}
			} );
	} );
} )();
