<?php
/**
 * @package Daan\Mods
 * @author  Daan van den Bergh
 * @url     https://daan.dev
 * @license MIT
 */

namespace Daan\Mods;

/**
 * A friendlier "email already used" message at checkout, with a link that opens the checkout's login form.
 *
 * EDD shows the message as the email field's custom validity (a browser tooltip), which can't contain a link. So the
 * text is rewritten for everywhere EDD uses it (incl. the server-side error on purchase), and on the checkout a script
 * replaces the tooltip with an inline notice containing the link.
 */
class EmailUsedNotice {
	/**
	 * EDD's original messages (both spellings are used).
	 */
	const ORIGINAL_MESSAGES = [
		'Email already used. Login or use a different email to complete your purchase.',
		'Email already used. Log in or use a different email to complete your purchase.',
	];

	/**
	 * Build class.
	 */
	public function __construct() {
		add_filter( 'gettext_easy-digital-downloads', [ $this, 'rewrite_message' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_script' ] );
	}

	/**
	 * Plain-text version of the message, e.g. for the error shown after submitting the purchase form.
	 *
	 * @param string $translation
	 * @param string $text
	 *
	 * @return string
	 */
	public function rewrite_message( $translation, $text ) {
		if ( ! in_array( $text, self::ORIGINAL_MESSAGES, true ) ) {
			return $translation;
		}

		return __( 'The email you entered is already in use by another account. Either login to your account or enter a different email to complete your purchase.', 'daan-mods' );
	}

	/**
	 * Load the inline notice (with the login link) on the checkout.
	 *
	 * @return void
	 */
	public function enqueue_script() {
		if ( ! function_exists( 'edd_is_checkout' ) || ! edd_is_checkout() ) {
			return;
		}

		$plugin_file = dirname( __DIR__ ) . '/daan-mods.php';
		$version     = get_file_data( $plugin_file, [ 'Version' => 'Version' ] )['Version'];

		wp_enqueue_script( 'daan-mods-email-used-notice', plugins_url( 'assets/js/email-used-notice.js', $plugin_file ), [], $version, true );
		wp_localize_script(
			'daan-mods-email-used-notice',
			'daanEmailUsedNotice',
			[
				/* translators: %s: "login to your account" link. */
				'message'   => __( 'The email you entered is already in use by another account. Either %s or enter a different email to complete your purchase.', 'daan-mods' ),
				'linkLabel' => __( 'login to your account', 'daan-mods' ),
			]
		);
	}
}
