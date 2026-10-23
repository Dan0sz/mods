/**
 * The Delete Account block's button is only enabled once its checkbox is checked. Without JavaScript, the checkbox's
 * required attribute does the same.
 *
 * @see \Daan\Mods\DeleteAccount
 */
document.querySelectorAll( '.wp-block-daan-delete-account form' ).forEach( ( form ) => {
	const checkbox = form.querySelector( 'input[name="daan_delete_account_confirm"]' );
	const button = form.querySelector( '.daan-delete-account-button' );
	const toggle = () => ( button.disabled = ! checkbox.checked );

	checkbox.addEventListener( 'change', toggle );
	toggle();
} );
