<?php
/**
 * @package Daan\Mods
 * @author  Daan van den Bergh
 * @url     https://daan.dev
 * @license MIT
 */

namespace Daan\Mods;

/**
 * Clearer checkout for customers who (try to) log in.
 *
 * When a customer clicks "Log in" at checkout, EDD replaces the email field with the login fields. If they then click
 * Complete Order without logging in (e.g. because the error of a failed login went unnoticed), there's no email address
 * to send along, and they get EDD's "Enter an email" error. So on the checkout a script:
 * - stops Complete Order while the login fields are shown, with a notice that says to log in first or check out as a
 *   guest,
 * - scrolls a failed login's error into view.
 * And "Enter an email" is rewritten for the cases that still get it.
 */
class CheckoutLogin {
	/**
	 * EDD's original message.
	 */
	const ORIGINAL_MESSAGE = 'Enter an email';

	/**
	 * Build class.
	 */
	public function __construct() {
		add_filter( 'gettext_easy-digital-downloads', [ $this, 'rewrite_message' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_script' ] );
	}

	/**
	 * @param string $translation
	 * @param string $text
	 *
	 * @return string
	 */
	public function rewrite_message( $translation, $text ) {
		if ( $text !== self::ORIGINAL_MESSAGE ) {
			return $translation;
		}

		return __( 'We need your email address to complete your purchase. Please enter it, or log in to your account.', 'daan-mods' );
	}

	/**
	 * Load the script on the checkout.
	 *
	 * @return void
	 */
	public function enqueue_script() {
		if ( ! function_exists( 'edd_is_checkout' ) || ! edd_is_checkout() ) {
			return;
		}

		$plugin_file = dirname( __DIR__ ) . '/daan-mods.php';
		$version     = get_file_data( $plugin_file, [ 'Version' => 'Version' ] )['Version'];

		wp_enqueue_script( 'daan-mods-checkout-login', plugins_url( 'assets/js/checkout-login.js', $plugin_file ), [], $version, true );
		wp_localize_script(
			'daan-mods-checkout-login',
			'daanCheckoutLogin',
			[
				/* translators: %s: "check out as a guest" link. */
				'message'   => __( "You're not logged in yet. Log in to your account above first, or %s.", 'daan-mods' ),
				'linkLabel' => __( 'check out as a guest', 'daan-mods' ),
			]
		);
	}
}
