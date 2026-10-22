<?php
/**
 * @package Daan\Mods
 * @author  Daan van den Bergh
 * @url     https://daan.dev
 * @license MIT
 */

namespace Daan\Mods;

/**
 * Adds "Quick: all unpaid referrals through last month" to the Date range of AffiliateWP's Pay Affiliates screen
 * (Payouts > Pay Affiliates), so affiliates are paid up to and including the last day of the previous month.
 *
 * AffiliateWP has no filter for these presets, so a script adds the option and teaches the form's Alpine store about it.
 */
class PayAffiliatesThroughLastMonth {
	/**
	 * Build class.
	 */
	public function __construct() {
		// Fires at the end of the Pay Affiliates screen, so the script only loads there.
		add_action( 'affwp_new_payout_end', [ $this, 'enqueue_script' ] );
	}

	/**
	 * Load the script in the footer, after AffiliateWP registered its Alpine store.
	 *
	 * @return void
	 */
	public function enqueue_script() {
		$plugin_file = dirname( __DIR__ ) . '/daan-mods.php';
		$version     = get_file_data( $plugin_file, [ 'Version' => 'Version' ] )['Version'];

		wp_enqueue_script( 'daan-mods-pay-affiliates-through-last-month', plugins_url( 'assets/js/pay-affiliates-through-last-month.js', $plugin_file ), [], $version, true );
		wp_localize_script(
			'daan-mods-pay-affiliates-through-last-month',
			'daanPayAffiliatesThroughLastMonth',
			[
				'label' => __( 'Quick: all unpaid referrals through last month', 'daan-mods' ),
			]
		);
	}
}
