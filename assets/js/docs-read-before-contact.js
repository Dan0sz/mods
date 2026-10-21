/**
 * Remembers which of WP Help Scout Docs' suggested articles a visitor clicked, fills the contact form's hidden field
 * with them, and tells Plausible: once per article per session on click, and once per article when the form is sent
 * anyway (i.e. the article didn't answer the question).
 *
 * @see \Daan\Mods\DocsReadBeforeContact
 */
( function () {
	const config = window.daanDocsReadBeforeContact || {};
	const STORAGE_KEY = 'daan-docs-read-before-contact';

	/**
	 * Clicked articles, url => title, in the order they were clicked. Kept in sessionStorage, so a reload (or an article
	 * opened in the same tab) doesn't lose them.
	 */
	const read = new Map( load() );

	function load() {
		try {
			const stored = JSON.parse( window.sessionStorage.getItem( STORAGE_KEY ) );

			return Array.isArray( stored ) ? stored : [];
		} catch ( e ) {
			return [];
		}
	}

	function save() {
		try {
			window.sessionStorage.setItem( STORAGE_KEY, JSON.stringify( [ ...read ] ) );
		} catch ( e ) {
			// Private mode or blocked storage: the articles are still remembered on this page.
		}
	}

	/**
	 * One line, because WPForms strips line breaks from hidden fields.
	 */
	function fillFields() {
		const value = [ ...read ].map( ( [ url, title ] ) => title + ' (' + url + ')' ).join( ' | ' );

		document.querySelectorAll( config.fieldSelector ).forEach( ( input ) => {
			input.value = value;
		} );
	}

	/**
	 * @param {string} name
	 * @param {Object} props
	 */
	function track( name, props ) {
		if ( typeof window.plausible === 'function' ) {
			window.plausible( name, { props } );
		}
	}

	/**
	 * Clicks and middle clicks (both open the article in a new tab).
	 *
	 * @param {MouseEvent} event
	 */
	function onClick( event ) {
		if ( event.type === 'auxclick' && event.button !== 1 ) {
			return;
		}

		const link = event.target.closest && event.target.closest( '.wp-help-scout-docs-form-results a' );
		if ( ! link || read.has( link.href ) ) {
			return;
		}

		const title = link.textContent.trim();
		read.set( link.href, title );
		save();
		fillFields();
		track( config.clickEvent, { article: title } );
	}

	/**
	 * @param {HTMLFormElement} form
	 */
	function onSent( form ) {
		if ( ! read.size || ! form.querySelector( config.fieldSelector ) ) {
			return;
		}

		read.forEach( ( title ) => track( config.contactEvent, { article: title } ) );
		read.clear();
		save();
	}

	if ( ! config.fieldSelector ) {
		return;
	}

	document.addEventListener( 'click', onClick, true );
	document.addEventListener( 'auxclick', onClick, true );

	// Articles clicked before a reload.
	fillFields();

	// AJAX forms: only once WPForms says the form was sent, so a failed validation doesn't count.
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'wpformsAjaxSubmitSuccess', ( event ) => onSent( event.target ) );
	}

	// Forms without AJAX reload the page after sending.
	document.addEventListener( 'submit', ( event ) => {
		if ( ! event.defaultPrevented && ! event.target.classList.contains( 'wpforms-ajax-form' ) ) {
			onSent( event.target );
		}
	} );
} )();
