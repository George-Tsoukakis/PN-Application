<?php
/**
 * Module: Settings & Cache
 *
 * Provides a dedicated admin page for clearing all plugin-related caches
 * (transients, object cache, rewrite rules, WP Rocket, asset version bust).
 *
 * @package JobListings
 * @since   9.9.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'jbli_register_cache_page' ) )
{

		function jbli_register_cache_page() {

		add_submenu_page(
			'jbli_admin_panel',
			__( 'Εκκαθάριση Cache', 'job-listings' ),
			__( 'Cache', 'job-listings' ),
			'manage_options',
			'jbli_cache',
			'jbli_render_cache_page'
		);

	}
}

add_action( 'admin_menu', 'jbli_register_cache_page', 15 );

if ( ! function_exists( 'jbli_cache_handle_action' ) )
{

		function jbli_cache_handle_action() {

		if ( ! isset( $_POST['jbli_cache_action'] ) || 'clear' !== sanitize_key( wp_unslash( $_POST['jbli_cache_action'] ) ) )
		{
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Unauthorized', 'job-listings' ), 403 ); }

		$jbli_nonce = sanitize_text_field(
			wp_unslash( $_POST['jbli_cache_nonce'] ?? '' )
		);

		if ( ! wp_verify_nonce( $jbli_nonce, 'jbli_cache_clear' ) )
		{
			wp_die( esc_html__( 'Security check failed', 'job-listings' ), 403 );
		}


		$jbli_raw_targets 	= isset( $_POST['jbli_cache_targets'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['jbli_cache_targets'] ) ) : array( 'all' );

		$jbli_cleared 		= jbli_do_clear_cache( $jbli_raw_targets );

		wp_safe_redirect(
			add_query_arg(
				array( 'page'    => 'jbli_cache', 'cleared' => rawurlencode( implode( ',', $jbli_cleared ) ), ),
				admin_url( 'admin.php' )
			)
		);

		exit;

	}
}

add_action( 'admin_init', 'jbli_cache_handle_action' );

if ( ! function_exists( 'jbli_do_clear_cache' ) )
{

	/**
	 * Execute cache clearing for the requested targets.
	 *
	 * @param  string[] $jbli_targets  Target keys or array containing 'all'.
	 * @return string[]           List of targets that were actually cleared.
	 */
	function jbli_do_clear_cache( $jbli_targets ) {

		$jbli_targets = is_array( $jbli_targets ) ? $jbli_targets : array();

		$jbli_all = array( 'jbli_transients', 'object_cache', 'rewrite_rules', 'rocket', 'asset_bust', );

		if ( empty( $jbli_targets ) || in_array( 'all', $jbli_targets, true ) ) { $jbli_targets = $jbli_all; }

		$jbli_cleared = array();

		foreach ( $jbli_targets as $jbli_target ) {

			switch ( $jbli_target ) {


				case 'jbli_transients':
					jbli_delete_jbli_transients();

					if ( function_exists( 'jbli_recent_flush_listings_cache' ) )
					{
						jbli_recent_flush_listings_cache();
					} 
					else 
					{
						update_option( 'jbli_recent_cache_version', max( time(), (int) get_option( 'jbli_recent_cache_version', 1 ) + 1 ), false );
					}

					$jbli_cleared[] = 'jbli_transients';
					break;


				case 'object_cache':
					if ( function_exists( 'wp_cache_flush' ) ) { wp_cache_flush(); }
					$jbli_cleared[] = 'object_cache';
					break;


				case 'rewrite_rules':
					flush_rewrite_rules( false );
					$jbli_cleared[] = 'rewrite_rules';
					break;


				case 'rocket':
					if ( function_exists( 'rocket_clean_domain' ) )
					{
						rocket_clean_domain();
						$jbli_cleared[] = 'rocket';
					}

					break;


				case 'asset_bust':
					$jbli_bust = (int) get_option( 'jbli_asset_bust', 0 );
					update_option( 'jbli_asset_bust', $jbli_bust + 1, false );
					$jbli_cleared[] = 'asset_bust';
					break;
			}

		}

		return $jbli_cleared;

	}
}

if ( ! function_exists( 'jbli_delete_jbli_transients' ) )
{

	function jbli_delete_jbli_transients() {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '\_transient\_jbli\_%'
			    OR option_name LIKE '\_transient\_timeout\_jbli\_%'"
		);

		if ( function_exists( 'jbli_clear_terms_cache' ) ) { jbli_clear_terms_cache(); }

	}
}

if ( ! function_exists( 'jbli_render_cache_page' ) )
{

	function jbli_render_cache_page() {

		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Unauthorized', 'job-listings' ), 403 ); }

		$jbli_bust             = (int) get_option( 'jbli_asset_bust', 0 );
		$jbli_has_rocket       = function_exists( 'rocket_clean_domain' );
		$jbli_has_object_cache = wp_using_ext_object_cache();

		$jbli_cleared_raw = sanitize_text_field(
			wp_unslash( $_GET['cleared'] ?? '' )
		);

		$jbli_cleared = $jbli_cleared_raw
			? array_map( 'sanitize_key', explode( ',', rawurldecode( $jbli_cleared_raw ) ) )
			: array();

		include __DIR__ . '/jbli-cache-template.php';

	}
}

if ( ! function_exists( 'jbli_cache_target_labels' ) )
{

	/**
	 * Human-readable labels for each cache target.
	 *
	 * @return array<string,string>
	 */
	function jbli_cache_target_labels() {

		return array(
			'jbli_transients' => __( 'Plugin Transients', 'job-listings' ),
			'object_cache'    => __( 'Object Cache', 'job-listings' ),
			'rewrite_rules'   => __( 'Rewrite Rules', 'job-listings' ),
			'rocket'          => __( 'WP Rocket', 'job-listings' ),
			'asset_bust'      => __( 'CSS / JS Assets', 'job-listings' ),
		);

	}
}

if ( ! function_exists( 'jbli_cache_clear_form' ) )
{

	/**
	 * Output a small inline form that clears a single target.
	 *
	 * @param string $jbli_target  One of the recognised target keys.
	 * @param string $jbli_label   Button label.
	 * @param string $jbli_class   Extra CSS classes on the <button>.
	 */
	function jbli_cache_clear_form(
		$jbli_target,
		$jbli_label,
		$jbli_class = ''
	) {

		$jbli_html = jbli_view(
			'partials.cache-clear-form',
			array(
				'jbli_target' => sanitize_key( $jbli_target ),
				'jbli_label'  => (string) $jbli_label,
				'jbli_class'  => (string) $jbli_class,
				'jbli_nonce'  => wp_create_nonce( 'jbli_cache_clear' ),
				'jbli_url'    => admin_url( 'admin.php?page=jbli_cache' ),
			)
		);
		echo $jbli_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML form component.
	}
}
