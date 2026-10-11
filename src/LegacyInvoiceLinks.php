<?php
/**
 * @package Daan\Mods
 * @author  Daan van den Bergh
 * @url     https://daan.dev
 * @license MIT
 */

namespace Daan\Mods;

/**
 * Keeps invoice links in emails sent before EDD 3.7.1 working.
 *
 * Up to EDD 3.7.0, the {edd_invoice} link's invoice hash was md5( order ID . payment key . email ). EDD 3.7.1 replaced it
 * with an HMAC (EDD\Utils\Tokenizer), and the invoice page checks that hash before anything else. So every invoice link
 * in an email sent before the update shows "You do not have permission to view this invoice", even to the order's owner
 * or an admin.
 *
 * For a valid legacy link, this sends a logged-in owner or admin to the order's current invoice link. Logged-out
 * visitors are asked to log in first; guests aren't let in with a legacy hash, because that's exactly what EDD tightened.
 */
class LegacyInvoiceLinks {
	/**
	 * Build class.
	 */
	public function __construct() {
		// Before EDD's own handler (priority 10).
		add_action( 'edd_view_invoice', [ $this, 'maybe_redirect' ], 5 );
	}
	
	/**
	 * @param array $data The request data.
	 *
	 * @return void
	 */
	public function maybe_redirect( $data ) {
		$order = $this->get_order_for_legacy_link( $data );
		
		if ( ! $order ) {
			return;
		}
		
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->get_current_url() ) );
			exit;
		}
		
		if ( current_user_can( 'view_shop_sensitive_data' ) || get_current_user_id() === (int) $order->user_id ) {
			wp_safe_redirect( edd_invoices_get_invoice_url( $order->id, ! empty( $data[ 'home' ] ) ) );
			exit;
		}
		
		// Any other user gets EDD's own "no permission" message.
	}
	
	/**
	 * The order, if the request carries a valid pre-3.7.1 invoice hash for it.
	 *
	 * @param array $data
	 *
	 * @return \EDD\Orders\Order|null
	 */
	private function get_order_for_legacy_link( $data ) {
		$order_id = absint( $data[ 'payment_id' ] ?? 0 );
		$hash     = (string) ( $data[ 'invoice' ] ?? '' );
		
		if ( ! $order_id || ! preg_match( '/^[a-f0-9]{32}$/', $hash ) || ! function_exists( 'edd_invoices_get_invoice_url' ) ) {
			return null;
		}
		
		$order = edd_get_order( $order_id );
		
		if ( ! $order || empty( $order->payment_key ) || empty( $order->email ) ) {
			return null;
		}
		
		return hash_equals( md5( $order->id . $order->payment_key . $order->email ), $hash ) ? $order : null;
	}
	
	/**
	 * @return string
	 */
	private function get_current_url() {
		return home_url( add_query_arg( [] ) );
	}
}
