<?php
/**
 * @package Daan\Mods
 * @author  Daan van den Bergh
 * @url     https://daan.dev
 * @license MIT
 */

namespace Daan\Mods;

use EDD\Pro\Emails\ConditionalTags\Conditions\PaymentMethod;

/**
 * Makes the Payment Method condition (Emails > Insert Conditional Tag) available in subscription emails, e.g. renewal
 * reminders. EDD's condition only works in order emails, because it reads the gateway from the order.
 *
 * Uses the same key, so EDD shows it as the same Payment Method condition, e.g. {if payment_method="stripe"}.
 */
class SubscriptionPaymentMethod {
	/**
	 * Build class.
	 */
	public function __construct() {
		add_action( 'edd_email_conditional_tags_register_conditions', [ $this, 'register' ] );
	}
	
	/**
	 * @param \EDD\Pro\Emails\ConditionalTags\ConditionRegistry $registry
	 *
	 * @return void
	 */
	public function register( $registry ) {
		if ( ! class_exists( PaymentMethod::class ) ) {
			return;
		}
		
		$registry->register(
			new class extends PaymentMethod {
				/**
				 * @var string[]
				 */
				protected $supported_contexts = [ 'subscription' ];
				
				/**
				 * Same as EDD's, but with the subscription's gateway.
				 *
				 * @param int    $object_id
				 * @param object $edd_object The subscription.
				 * @param object $email
				 * @param mixed  $value      Comma-separated gateway slugs, e.g. 'stripe,paypal_commerce'.
				 * @param string $operator   = or !=.
				 * @param mixed  $context
				 *
				 * @return bool
				 */
				public function evaluate( $object_id, $edd_object, $email, $value, $operator = '=', $context = '' ) {
					$value = trim( (string) $value );
					
					if ( ! $value || ! $this->is_context_allowed( $context ) || ! in_array( $operator, $this->supported_operators, true ) ) {
						return false;
					}
					
					$actual = isset( $edd_object->gateway ) ? (string) $edd_object->gateway : '';
					
					if ( ! $actual ) {
						return $operator === '!=';
					}
					
					$has_match = in_array( strtolower( $actual ), array_filter( array_map( 'trim', explode( ',', strtolower( $value ) ) ) ), true );
					
					return $operator === '!=' ? ! $has_match : $has_match;
				}
			}
		);
	}
}
