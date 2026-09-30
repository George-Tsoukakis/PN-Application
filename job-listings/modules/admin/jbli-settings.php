<?php
/**
 * Module: Settings
 *
 * @package JobListings
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'jbli_register_settings_page' ) )
{
	function jbli_register_settings_page() {

		add_submenu_page(
			'jbli_admin_panel',
			__( 'Ρυθμίσεις', 'job-listings' ),
			__( 'Ρυθμίσεις', 'job-listings' ),
			'manage_options',
			'jbli_settings',
			'jbli_render_settings_page'
		);

	}
}

add_action( 'admin_menu', 'jbli_register_settings_page', 14 );

if ( ! function_exists( 'jbli_render_settings_page' ) )
{
	function jbli_render_settings_page() {

		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Unauthorized', 'job-listings' ), 403 ); }
		include __DIR__ . '/jbli-settings-template.php';

	}
}
