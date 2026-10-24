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
use EDD\Pro\Taxes\VAT\Utility;
use EDD\Pro\Taxes\VAT\Validator;

/**
 * Keeps EDD's "Validate & Apply Reverse Charge" button (Orders > Edit Order > VAT, since EDD 3.7.0), but without the
 * refund: it re-checks the VAT number and, if it's valid, marks the order as reverse charged. The tax is never refunded
 * through the payment gateway, so that stays a manual decision.
 *
 * EDD's button always refunds, whatever "Auto-Refund Tax on Delayed Validation" is set to. Its automatic retries do
 * respect that setting, so this runs EDD's own retry with the setting off.
 *
 * EDD only shows the button for orders in its delayed validation (since EDD 3.7.0). This also shows it for older orders
 * which still have tax charged, while their VAT number should have been reverse charged.
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
	 * EDD's description of the button.
	 */
	const DESCRIPTION = 'Re-checks the VAT number with the validation service. If it is valid and the reverse charge applies, the tax is refunded through the payment gateway.';
	
	/**
	 * Build class.
	 */
	public function __construct() {
		// Before EDD's own handler (priority 10), which then never runs.
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'validate' ], 9 );
		add_filter( 'gettext_easy-digital-downloads', [ $this, 'change_texts' ], 10, 2 );
		// After EDD Pro's VAT section (priority 10).
		add_filter( 'edd_get_order_details_sections', [ $this, 'add_button' ], 20 );
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
		
		// E.g. by EDD's automatic retries, which only mark the order as reverse charged.
		if ( edd_get_order_meta( $order_id, VAT::META_VALIDATION_STATUS, true ) === VAT::STATUS_VALIDATED ) {
			if ( ! edd_get_order_meta( $order_id, '_edd_payment_vat_reverse_charged', true ) ) {
				wp_send_json_error( [ 'message' => 'This VAT number was already validated, but the reverse charge doesn\'t apply for this order. Tax remains charged.' ] );
			}
			
			if ( ! (float) $order->tax ) {
				wp_send_json_error( [ 'message' => 'This VAT number was already validated and the reverse charge applied. There\'s nothing left to change.' ] );
			}
			
			$this->send_reverse_charged( $order, 'VAT number was already validated.' );
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
			$this->send_reverse_charged( $order, 'VAT number validated.' );
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
	 * Moves the tax into the subtotal and tells the admin.
	 *
	 * @param \EDD\Orders\Order $order
	 * @param string            $validated What happened to the VAT number.
	 *
	 * @return void
	 */
	private function send_reverse_charged( $order, $validated ) {
		$moved = $this->move_tax_to_subtotal( $order );
		
		if ( is_wp_error( $moved ) ) {
			wp_send_json_success( [ 'message' => $validated . ' Reverse charge applied. ' . $moved->get_error_message() . ' So its tax wasn\'t changed: correct it manually.', 'reload' => true ] );
		}
		
		wp_send_json_success( [ 'message' => $validated . ' Reverse charge applied.', 'reload' => true ] );
	}
	
	/**
	 * Makes the order look like it was reverse charged at checkout: the total stays the same, because the advertised
	 * price includes VAT (Plugin::remove_item_price_filter()). So the tax becomes part of the subtotal, of the order,
	 * its items and their adjustments (e.g. the one-time discount, a negative fee).
	 *
	 * @param \EDD\Orders\Order $order
	 *
	 * @return true|\WP_Error An error if the order has refunds, which would no longer add up, or if saving failed.
	 */
	private function move_tax_to_subtotal( $order ) {
		global $wpdb;
		
		if ( edd_get_orders( [ 'parent' => $order->id, 'type' => 'refund', 'number' => 1 ] ) ) {
			return new \WP_Error( 'refunds', 'This order has refunds.' );
		}
		
		$updates = [];
		
		foreach ( $order->get_items() as $item ) {
			foreach ( $item->get_fees() as $fee ) {
				$updates[] = [ 'edd_update_order_adjustment', $fee ];
			}
			
			$updates[] = [ 'edd_update_order_item', $item ];
		}
		
		foreach ( $order->get_fees() as $fee ) {
			// Order fees also include the items' fees.
			if ( $fee->object_type === 'order' ) {
				$updates[] = [ 'edd_update_order_adjustment', $fee ];
			}
		}
		
		$updates[] = [ 'edd_update_order', $order ];
		
		// All or nothing: otherwise the order no longer adds up.
		$wpdb->query( 'START TRANSACTION' );
		
		foreach ( $updates as [ $update, $object ] ) {
			if ( ! $this->move_tax( $update, $object ) ) {
				$wpdb->query( 'ROLLBACK' );
				
				return new \WP_Error( 'failed', 'Saving the order failed.' );
			}
		}
		
		$wpdb->query( 'COMMIT' );
		
		edd_insert_payment_note( $order->id, sprintf( 'VAT reverse charged after a delayed check: the tax (%s) is now part of the subtotal. The total didn\'t change.', edd_currency_filter( edd_format_amount( $order->tax ), $order->currency ) ) );
		
		return true;
	}
	
	/**
	 * @param callable $update E.g. edd_update_order_item().
	 * @param object   $object An order, order item or order adjustment.
	 *
	 * @return bool
	 */
	private function move_tax( $update, $object ) {
		if ( ! (float) $object->tax ) {
			return true;
		}
		
		return $update( $object->id, [ 'subtotal' => (float) $object->subtotal + (float) $object->tax, 'tax' => 0 ] ) !== false;
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
	 * Adds the button to the VAT section, for orders EDD doesn't show it for.
	 *
	 * @param array $sections
	 *
	 * @return array
	 */
	public function add_button( $sections ) {
		foreach ( $sections as &$section ) {
			if ( ( $section[ 'id' ] ?? '' ) !== 'vat' || ! is_callable( $section[ 'callback' ] ?? null ) ) {
				continue;
			}
			
			$callback              = $section[ 'callback' ];
			$section[ 'callback' ] = function ( $order ) use ( $callback ) {
				ob_start();
				call_user_func( $callback, $order );
				$html   = ob_get_clean();
				$button = $this->needs_button( $order ) ? $this->get_button( $order ) : '';
				// Above the Location Evidence, where EDD shows it.
				$pos = strpos( $html, '<h4>' );
				
				echo $pos === false ? $html . $button : substr_replace( $html, $button, $pos, 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			};
		}
		
		return $sections;
	}
	
	/**
	 * Orders which never entered EDD's delayed validation, e.g. from before EDD 3.7.0, but still have tax charged, while
	 * their VAT number might be reverse charged.
	 *
	 * @param \EDD\Orders\Order $order
	 *
	 * @return bool
	 */
	private function needs_button( $order ) {
		if ( ! class_exists( Utility::class ) || ! $order || ! (float) $order->tax || ! Utility::is_paid_order( $order ) ) {
			return false;
		}
		
		// EDD shows its own button, or the order was resolved.
		if ( edd_get_order_meta( $order->id, VAT::META_VALIDATION_STATUS, true ) ) {
			return false;
		}
		
		return edd_get_order_meta( $order->id, '_edd_payment_vat_number', true ) && Utility::can_reverse_charge_vat( $order->get_address()->country );
	}
	
	/**
	 * EDD's button: its script sends it to self::validate().
	 *
	 * @param \EDD\Orders\Order $order
	 *
	 * @return string
	 */
	private function get_button( $order ) {
		ob_start();
		?>
		<div class="edd-form-group edd-order-details__vat-validation">
			<button type="button" class="button button-secondary edd-vat-validate-apply" data-order-id="<?php echo esc_attr( $order->id ); ?>">
				<?php echo esc_html( $this->change_texts( '', 'Validate & Apply Reverse Charge' ) ); ?>
			</button>
			<p class="description">
				<?php echo esc_html( $this->change_texts( '', self::DESCRIPTION ) ); ?>
			</p>
		</div>
		<?php
		return ob_get_clean();
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
		
		if ( $text === self::DESCRIPTION ) {
			return 'Re-checks the VAT number with the validation service. If it is valid and the reverse charge applies, the order is marked as reverse charged. The tax isn\'t refunded: refund it manually, if needed.';
		}
		
		return $translation;
	}
}
