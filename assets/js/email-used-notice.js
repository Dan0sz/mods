/**
 * Replaces EDD's "email already used" browser tooltip with an inline notice, in which "login to your account" opens
 * the checkout's login form (with the email pre-filled as username).
 *
 * @see \Daan\Mods\EmailUsedNotice
 */
( function () {
	const strings = window.daanEmailUsedNotice || {};
	const NOTICE_CLASS = 'daan-email-used-notice';

	/**
	 * Fired by EDD after it checked the email address (edd_check_email), right before it calls reportValidity().
	 */
	document.addEventListener( 'edd:checkout-email-check-complete', function ( event ) {
		const emailField = event.detail && event.detail.emailField;
		if ( ! emailField ) {
			return;
		}

		const wrap = emailField.closest( '.edd-blocks-form__group' ) || emailField.parentElement;
		removeNotice( emailField, wrap );

		const loginButton = document.querySelector( '.edd-blocks__checkout-login' );
		if ( event.detail.code !== 'email_used' || ! loginButton || ! strings.message ) {
			return; // Leave EDD's own behaviour alone.
		}

		// The tooltip can't hold a link: clear it (EDD reports validity next) and show an inline notice instead.
		// EDD still rejects the address server-side if the customer submits anyway.
		emailField.setCustomValidity( '' );

		const notice = document.createElement( 'div' );
		notice.id = 'daan-email-used-notice';
		notice.className = NOTICE_CLASS + ' edd-alert edd-alert-error';
		notice.setAttribute( 'role', 'alert' );

		const link = document.createElement( 'a' );
		link.href = '#';
		link.textContent = strings.linkLabel;
		link.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			openLoginForm( loginButton, emailField.value );
		} );

		const [ before, after ] = strings.message.split( '%s' );
		notice.append( document.createTextNode( before ), link, document.createTextNode( after || '' ) );
		wrap.appendChild( notice );

		emailField.setAttribute( 'aria-invalid', 'true' );
		emailField.setAttribute( 'aria-describedby', notice.id );
	} );

	/**
	 * @param {HTMLInputElement} emailField
	 * @param {HTMLElement}      wrap
	 */
	function removeNotice( emailField, wrap ) {
		wrap.querySelectorAll( '.' + NOTICE_CLASS ).forEach( ( el ) => el.remove() );
		emailField.removeAttribute( 'aria-invalid' );
		if ( emailField.getAttribute( 'aria-describedby' ) === 'daan-email-used-notice' ) {
			emailField.removeAttribute( 'aria-describedby' );
		}
	}

	/**
	 * Switch the checkout to its login form (loaded over AJAX), pre-fill the username and focus the password.
	 *
	 * @param {HTMLButtonElement} loginButton
	 * @param {string}            email
	 */
	function openLoginForm( loginButton, email ) {
		loginButton.click();

		// EDD loads (and may re-render) the login fields over AJAX, which can take a while: keep pre-filling until
		// the customer types something themselves, or 15 seconds have passed.
		const started = Date.now();
		let focused = false;
		let typed = false;
		const markTyped = ( e ) => {
			if ( e.isTrusted && e.target && e.target.name === 'edd_user_login' ) {
				typed = true;
			}
		};
		document.addEventListener( 'input', markTyped, true );
		const timer = setInterval( function () {
			const username = document.querySelector( 'input[name="edd_user_login"]' );
			const password = document.querySelector( 'input[name="edd_user_pass"]' );

			if ( username && username.offsetParent !== null ) {
				if ( email && ! typed && ! username.value ) {
					username.value = email;
				}
				if ( ! focused ) {
					focused = true;
					username.closest( 'fieldset, form, div' ).scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
				if ( password && document.activeElement !== password && ! password.value ) {
					password.focus( { preventScroll: true } );
				}
			}

			if ( typed || Date.now() - started > 15000 ) {
				clearInterval( timer );
				document.removeEventListener( 'input', markTyped, true );
			}
		}, 100 );
	}
} )();
