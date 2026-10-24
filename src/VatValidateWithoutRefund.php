<?php
/**
 * @package Daan\Mods
 * @author  Daan van den Bergh
 * @url     https://daan.dev
 * @license MIT
 */

namespace Daan\Mods;

use EDD\Pro\Taxes\VAT\Order as VAT;
use EDD\Pro\Taxes\VAT\PendingValidation;
use EDD\Pro\Taxes\VAT\Validator;

/**
 * Keeps EDD's "Validate & Apply Reverse Charge" button (Orders > Edit Order > VAT, since EDD 3.7.0), but without the
 * refund: it re-checks the VAT number and, if it's valid, marks the order as reverse charged. The tax is never refunded
 * through the payment gateway, so that stays a manual decision.
 *
 * EDD's button always refunds, whatever "Auto-Refund Tax on Delayed Validation" is set to. Its automatic retries do
 * respect that setting, so this runs EDD's own retry with the setting off.
 */
class VatValidateWithoutRefund {
	/**
	 * EDD's AJAX action for the button. @see \EDD\Pro\Admin\Orders\ValidateVAT
	 */
	const ACTION = 'edd_validate_vat_apply_reverse';
	
	/**
	 * EDD's nonce for the button. @see \EDD\Pro\Admin\Orders\ValidateVAT::NONCE
	 */
	const NONCE = 'edd_vat_validate_review';
	
	/**
	 * Build class.
	 */
	public function __construct() {
		// Before EDD's own handler (priority 10), which then never runs.
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'validate' ], 9 );
		add_filter( 'gettext_easy-digital-downloads', [ $this, 'change_texts' ], 10, 2 );
	}
	
	/**
	 * @return void
	 */
	public function validate() {
		if ( ! class_exists( PendingValidation::class ) ) {
			return;
		}
		
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed. Please reload the page and try again.', 'easy-digital-downloads' ) ], 403 );
		}
		
		$order_id = isset( $_POST[ 'order_id' ] ) ? absint( $_POST[ 'order_id' ] ) : 0;
		
		if ( ! $order_id || ! current_user_can( 'edit_shop_payments', $order_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'easy-digital-downloads' ) ], 403 );
		}
		
		$order = edd_get_order( $order_id );
		
		if ( ! $order ) {
			wp_send_json_error( [ 'message' => __( 'Invalid order.', 'easy-digital-downloads' ) ] );
		}
		
		if ( edd_get_order_meta( $order_id, VAT::META_VALIDATION_STATUS, true ) === VAT::STATUS_VALIDATED ) {
			wp_send_json_error( [ 'message' => 'This VAT number was already validated. Refund the tax manually, if needed.' ] );
		}
		
		$vat_number = edd_get_order_meta( $order_id, '_edd_payment_vat_number', true );
		$country    = $order->get_address()->country;
		
		if ( ! $vat_number || ! $country ) {
			wp_send_json_error( [ 'message' => __( 'No VAT number is stored on this order.', 'easy-digital-downloads' ) ] );
		}
		
		// Checked first, so an unavailable service leaves the order as it is, instead of failing EDD's retry.
		$result = Validator::check_vat( $vat_number, $country );
		
		if ( $result->is_service_unavailable() ) {
			wp_send_json_error( [ 'message' => __( 'The VAT validation service is currently unavailable. Please try again later.', 'easy-digital-downloads' ) ] );
		}
		
		// EDD's retry only acts on orders which are pending validation. It validates again, stores the result, adds an
		// order note and, if the reverse charge applies, marks the order as reverse charged.
		$previous = edd_get_order_meta( $order_id, VAT::META_VALIDATION_STATUS, true );
		
		edd_update_order_meta( $order_id, VAT::META_VALIDATION_STATUS, VAT::STATUS_PENDING );
		add_filter( 'edd_get_option_edd_vat_auto_refund', '__return_false' );
		PendingValidation::revalidate( $order_id, PendingValidation::MAX_ATTEMPTS );
		remove_filter( 'edd_get_option_edd_vat_auto_refund', '__return_false' );
		
		$status = edd_get_order_meta( $order_id, VAT::META_VALIDATION_STATUS, true );
		
		// The retry stopped early, e.g. because the order isn't paid: it isn't pending anymore.
		if ( $status === VAT::STATUS_PENDING ) {
			$this->restore_status( $order_id, $previous );
		}
		
		if ( $status === VAT::STATUS_VALIDATED && edd_get_order_meta( $order_id, '_edd_payment_vat_reverse_charged', true ) ) {
			wp_send_json_success( [ 'message' => 'VAT number validated and reverse charge applied. The tax wasn\'t refunded: refund it manually, if needed.', 'reload' => true ] );
		}
		
		if ( $status === VAT::STATUS_VALIDATED ) {
			wp_send_json_success( [ 'message' => __( 'The VAT number is valid, but the reverse charge does not apply for this order. Tax remains charged.', 'easy-digital-downloads' ), 'reload' => true ] );
		}
		
		if ( $status === VAT::STATUS_INVALID ) {
			wp_send_json_error( [ 'message' => __( 'The VAT number could not be validated and appears to be invalid.', 'easy-digital-downloads' ), 'reload' => true ] );
		}
		
		// E.g. the service became unavailable after all, or the order isn't paid.
		wp_send_json_error( [ 'message' => 'The VAT number couldn\'t be validated. Check the order notes.', 'reload' => true ] );
	}
	
	/**
	 * @param int    $order_id
	 * @param string $status
	 *
	 * @return void
	 */
	private function restore_status( $order_id, $status ) {
		if ( $status ) {
			edd_update_order_meta( $order_id, VAT::META_VALIDATION_STATUS, $status );
			
			return;
		}
		
		edd_delete_order_meta( $order_id, VAT::META_VALIDATION_STATUS );
	}
	
	/**
	 * Describe what the button does now.
	 *
	 * @param string $translation
	 * @param string $text
	 *
	 * @return string
	 */
	public function change_texts( $translation, $text ) {
		if ( $text === 'Validate & Apply Reverse Charge' ) {
			return 'Validate & Apply Reverse Charge (no refund)';
		}
		
		if ( $text === 'Re-checks the VAT number with the validation service. If it is valid and the reverse charge applies, the tax is refunded through the payment gateway.' ) {
			return 'Re-checks the VAT number with the validation service. If it is valid and the reverse charge applies, the order is marked as reverse charged. The tax isn\'t refunded: refund it manually, if needed.';
		}
		
		return $translation;
	}
}
