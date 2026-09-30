<?php
/**
 * Admin Panel: Registration & Assets
 *
 * Registers the admin menu page and enqueues the panel stylesheet.
 * Loaded by jbli-admin-panel.php.
 *
 * @package JobListings
 * @since   9.9.24
 */

defined( 'ABSPATH' ) || exit;

function jbli_register_admin_panel(): void {

	add_menu_page(
		__( 'Διαχείριση Αγγελιών', 'job-listings' ),
		__( 'Αγγελίες', 'job-listings' ),
		'manage_options',
		'jbli_admin_panel',
		'jbli_render_admin_panel',
		'dashicons-megaphone',
		26
	);

}

add_action( 'admin_menu', 'jbli_register_admin_panel' );

/**
 * Enqueue admin panel stylesheet on panel pages only.
 *
 * @param string $jbli_hook Current admin page hook suffix.
 */
function jbli_admin_panel_assets( string $jbli_hook ): void {

	if ( false === strpos( $jbli_hook, 'jbli_admin_panel' ) && false === strpos( $jbli_hook, 'jbli_cache' ) && false === strpos( $jbli_hook, 'jbli_settings' ) )
	{
		return;
	}

	/* Media Library picker for the form image/video setting. */
	if ( false !== strpos( $jbli_hook, 'jbli_settings' ) && function_exists( 'wp_enqueue_media' ) ) { wp_enqueue_media(); }

	$jbli_css_file = JBLI_DIR . 'modules/admin/jbli-admin-panel.css';
	$jbli_version  = is_readable( $jbli_css_file )
		? JBLI_VERSION . '.' . (string) filemtime( $jbli_css_file )
		: JBLI_VERSION;

	$jbli_tokens_file = JBLI_DIR . 'includes/jbli-tokens.css';
	if ( is_readable( $jbli_tokens_file ) ) {
		wp_enqueue_style(
			'jbli_tokens',
			JBLI_URL . 'includes/jbli-tokens.css',
			array(),
			JBLI_VERSION . '.' . (string) filemtime( $jbli_tokens_file )
		);
	}

	wp_enqueue_style(
		'jbli_admin',
		JBLI_URL . 'modules/admin/jbli-admin-panel.css',
		array( 'jbli_tokens' ),
		$jbli_version
	);


	$jbli_admin_js = JBLI_DIR . 'modules/admin/js/jbli-admin.js';

	if ( is_readable( $jbli_admin_js ) )
	{
		wp_enqueue_script(
			'jbli_admin_js',
			JBLI_URL . 'modules/admin/js/jbli-admin.js',
			array(),
			JBLI_VERSION . '.' . (string) filemtime( $jbli_admin_js ),
			true
		);
	}

}

add_action( 'admin_enqueue_scripts', 'jbli_admin_panel_assets' );
