/**
 * While the checkout shows its login fields (instead of the email field), Complete Order is stopped with a notice, next
 * to the login fields, that says to log in first or check out as a guest. And a failed login's error is scrolled into
 * view.
 *
 * @see \Daan\Mods\CheckoutLogin
 */
( function () {
	const strings = window.daanCheckoutLogin || {};
	const NOTICE_ID = 'daan-checkout-login-notice';

	/**
	 * Runs in the capture phase, so before the gateways' own handlers (e.g. Stripe's, on the button itself).
	 *
	 * @param {Event} event
	 */
	function maybeStopPurchase( event ) {
		const button = event.target.closest && event.target.closest( '#edd-purchase-button' );
		if ( ! button || ( event.type === 'keydown' && event.key !== 'Enter' ) ) {
			return;
		}

		// The legacy checkout can show the login fields next to the email field: then a filled-in email is enough.
		const loginFields = document.getElementById( 'edd_login_fields' );
		const email = document.getElementById( 'edd-email' );
		if ( ! loginFields || ! strings.message || ( email && email.value.trim() ) ) {
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();

		showNotice( loginFields );

		// Point the customer to the first empty field, or else to the Log in button.
		const target = [ ...loginFields.querySelectorAll( 'input[name="edd_user_login"], input[name="edd_user_pass"]' ) ].find( ( input ) => ! input.value ) ||
			loginFields.querySelector( '#edd-user-login-submit input[type="submit"]' );
		loginFields.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		if ( target ) {
			target.focus( { preventScroll: true } );
		}
	}

	/**
	 * Shown where EDD shows a failed login's error (and with the same classes, so EDD replaces it on the next login).
	 *
	 * @param {HTMLElement} loginFields
	 */
	function showNotice( loginFields ) {
		removeNotice();
		loginFields.querySelectorAll( '.edd_errors' ).forEach( ( el ) => el.remove() );

		const notice = document.createElement( 'div' );
		notice.id = NOTICE_ID;
		notice.className = 'edd_errors edd-alert edd-alert-error';
		notice.setAttribute( 'role', 'alert' );

		const link = document.createElement( 'a' );
		link.href = '#';
		link.textContent = strings.linkLabel;
		link.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			removeNotice();

			// Blocks checkout, or the legacy checkout's "Register or checkout as a guest" link.
			const guestButton = document.querySelector( '.edd-blocks__checkout-guest, .edd_checkout_register_login[data-action="checkout_register"]' );
			if ( guestButton ) {
				guestButton.click();
			}
		} );

		const [ before, after ] = strings.message.split( '%s' );
		const text = document.createElement( 'p' );
		text.className = 'edd_error';
		text.append( document.createTextNode( before ), link, document.createTextNode( after || '' ) );
		notice.appendChild( text );

		const submit = loginFields.querySelector( '#edd-user-login-submit' );
		if ( submit ) {
			submit.parentNode.insertBefore( notice, submit );
		} else {
			loginFields.appendChild( notice );
		}
	}

	function removeNotice() {
		const notice = document.getElementById( NOTICE_ID );
		if ( notice ) {
			notice.remove();
		}
	}

	document.addEventListener( 'click', maybeStopPurchase, true );
	document.addEventListener( 'keydown', maybeStopPurchase, true );

	/**
	 * EDD swaps the login fields in and out over AJAX, and inserts a failed login's error into them.
	 */
	new MutationObserver( function ( mutations ) {
		if ( ! document.getElementById( 'edd_login_fields' ) ) {
			removeNotice(); // Logged in, or switched to the guest checkout.
			return;
		}

		mutations.forEach( ( mutation ) => {
			mutation.addedNodes.forEach( ( node ) => {
				if ( node.nodeType !== 1 || node.id === NOTICE_ID || ! node.matches( '.edd_errors' ) || ! node.closest( '#edd_login_fields' ) ) {
					return;
				}

				node.scrollIntoView( { behavior: 'smooth', block: 'center' } );

				const password = document.querySelector( '#edd_login_fields input[name="edd_user_pass"]' );
				if ( password ) {
					password.focus( { preventScroll: true } );
					password.select();
				}
			} );
		} );
	} ).observe( document.body, { childList: true, subtree: true } );
} )();
