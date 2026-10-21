<?php
/**
 * @package Daan\Mods
 * @author  Daan van den Bergh
 * @url     https://daan.dev
 * @license MIT
 */

namespace Daan\Mods;

/**
 * Which suggested docs did a visitor read before contacting me anyway?
 *
 * WP Help Scout Docs' Form Integration suggests docs below the contact form's subject field. When a visitor clicks one
 * (or more) and still sends the form, those articles didn't answer the question. A script fills a hidden WPForms
 * field (CSS class daan-docs-clicked) with the clicked articles, so the ticket shows them.
 *
 * Kept out of WP Help Scout Docs itself, because that's sold: this is specific to daan.dev's contact form.
 */
class DocsReadBeforeContact {
	/**
	 * WP Help Scout Docs' Form Integration script.
	 */
	const FORM_INTEGRATION_HANDLE = 'wp-help-scout-docs-form-integration';

	/**
	 * Build class.
	 */
	public function __construct() {
		// Before the footer scripts are printed (priority 20), but after forms in the content enqueued Form Integration.
		// jQuery: for WPForms' wpformsAjaxSubmitSuccess event.
		add_action( 'wp_footer', [ $this, 'enqueue_script' ], 5 );
	}

	/**
	 * Load the script wherever Form Integration is loaded.
	 *
	 * @return void
	 */
	public function enqueue_script() {
		if ( ! wp_script_is( self::FORM_INTEGRATION_HANDLE ) ) {
			return;
		}

		$plugin_file = dirname( __DIR__ ) . '/daan-mods.php';
		$version     = get_file_data( $plugin_file, [ 'Version' => 'Version' ] )['Version'];

		wp_enqueue_script( 'daan-mods-docs-read-before-contact', plugins_url( 'assets/js/docs-read-before-contact.js', $plugin_file ), [ 'jquery' ], $version, true );
		wp_localize_script(
			'daan-mods-docs-read-before-contact',
			'daanDocsReadBeforeContact',
			[
				'fieldSelector' => '.daan-docs-clicked input[type="hidden"]',
			]
		);
	}
}
