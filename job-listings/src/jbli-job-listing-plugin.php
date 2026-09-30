<?php

namespace JobListings;

use JobListings\Controllers\ListingsController;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap singleton.
 */
final class Plugin {

	/**
	 * @var self|null
	 */
	private static $jbli_instance = null;

	/**
	 * @var ListingsController|null
	 */
	private $jbli_listings_controller = null;

	/**
	 * @return self
	 */
	public static function jbli_instance() {

		if ( null === self::$jbli_instance ) { self::$jbli_instance = new self(); }

		return self::$jbli_instance;

	}

	/**
	 * Boot hooks. Call once from the main plugin file after constants + autoload.
	 *
	 * @return void
	 */
	public function jbli_boot() {

		add_action( 'plugins_loaded', array( $this, 'jbli_load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'jbli_check_version' ), 20 );

		register_activation_hook( JBLI_FILE, array( $this, 'jbli_activate' ) );
		register_deactivation_hook( JBLI_FILE, array( $this, 'jbli_deactivate' ) );

	}

	/**
	 * Discard accidental output during bootstrap.
	 *
	 * @param string $jbli_context Where the output came from.
	 * @param string $jbli_output  Captured output.
	 * @return void
	 */
	public static function jbli_discard_unexpected_output( $jbli_context, $jbli_output ) {

		if ( '' === $jbli_output ) { return; }

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG )
		{
			error_log(
				sprintf(
					'[Job Listings] Discarded unexpected output during %s (%d chars): %s',
					$jbli_context,
					strlen( (string) $jbli_output ),
					trim( wp_strip_all_tags( (string) $jbli_output ) )
				)
			);
		}

	}

	/**
	 * Load legacy procedural includes (until fully migrated).
	 *
	 * @return void
	 */
	public function jbli_load_legacy_files() {

		$jbli_files = array(
			'includes/jbli-helpers.php',
			'includes/jbli-storage.php',
			'includes/jbli-capabilities.php',
			'includes/jbli-post-type.php',
			'includes/jbli-taxonomies.php',
			'includes/jbli-expiry.php',
			'includes/jbli-assets.php',
			'includes/jbli-view-counter.php',
			'includes/jbli-featured.php',
			'includes/jbli-rocket-compat.php',
			'includes/jbli-cache-invalidation.php',
			'includes/jbli-no-cache-headers.php',
			'includes/compat/jbli-job-listing-oop-bridges.php',
			'modules/form/jbli-form.php',
			'modules/dashboard/jbli-dashboard.php',
			'modules/listings/jbli-listings.php',
			'modules/single/jbli-single.php',
			'modules/recent/jbli-recent-listings.php',
			'modules/apply/jbli-apply.php',
		);

		/*
		 * 9.9.60: admin screens, settings, cache page and the URL importer are
		 * only loaded for wp-admin requests (admin-ajax.php and admin-post.php
		 * included), not for every visitor page view.
		 */
		if ( is_admin() )
		{
			$jbli_files = array_merge( $jbli_files, array(
				'includes/jbli-admin-columns.php',
				'modules/admin/jbli-admin-panel.php',
				'modules/admin/jbli-settings.php',
				'modules/admin/jbli-cache.php',
				'modules/import/jbli-import.php',
			) );
		}

		ob_start();

		foreach ( $jbli_files as $jbli_file ) {

			$jbli_path = JBLI_DIR . $jbli_file;

			if ( is_readable( $jbli_path ) )
			{
				require_once $jbli_path;
				continue;
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { error_log( 'Job Listings missing file: ' . $jbli_path ); }

		}

		$jbli_output = (string) ob_get_clean();
		self::jbli_discard_unexpected_output( 'file loading', $jbli_output );

	}

	/**
	 * @return ListingsController
	 */
	public function jbli_listings() {

		if ( null === $this->jbli_listings_controller ) { $this->jbli_listings_controller = new ListingsController(); }

		return $this->jbli_listings_controller;

	}

	/**
	 * @return void
	 */
	public function jbli_load_textdomain() {

		load_plugin_textdomain(
			'job-listings',
			false,
			dirname( JBLI_BASENAME ) . '/languages'
		);

	}

	/**
	 * Version check / lightweight migrations.
	 *
	 * @return void
	 */
	public function jbli_check_version() {

		static $jbli_ran = false;

		if ( $jbli_ran ) { return; }

		$jbli_ran = true;

		$jbli_stored_version = (string) get_option( 'jbli_version', '' );

		if ( JBLI_VERSION === $jbli_stored_version ) { return; }

		ob_start();

		if ( function_exists( 'jbli_maybe_install_storage' ) ) { jbli_maybe_install_storage(); }

		$jbli_output = (string) ob_get_clean();
		self::jbli_discard_unexpected_output( 'version check', $jbli_output );

		/* 9.9.57: recorded after the storage update, so a failed update is retried on the next request. */
		update_option( 'jbli_version', JBLI_VERSION, false );
		update_option( 'jbli_asset_bust', time(), false );

		/* 9.9.57: an update may change slugs/rewrites; refresh them once the CPT is registered. */
		add_action( 'init', static function () { flush_rewrite_rules( false ); }, 999 );

		if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); }

		/* WP Rocket reads rocket_cache_reject_uri only when it regenerates its config. */
		if ( function_exists( 'flush_rocket_htaccess' ) ) { flush_rocket_htaccess(); }

		if ( function_exists( 'rocket_generate_config_file' ) ) { rocket_generate_config_file(); }

	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public function jbli_activate() {

		ob_start();

		if ( function_exists( 'jbli_register_post_type' ) ) { jbli_register_post_type(); }

		if ( function_exists( 'jbli_register_taxonomies' ) ) { jbli_register_taxonomies(); }

		update_option( 'jbli_version', JBLI_VERSION, false );
		update_option( 'jbli_asset_bust', time(), false );

		if ( function_exists( 'jbli_maybe_install_storage' ) ) { jbli_maybe_install_storage(); }

		if ( function_exists( 'jbli_schedule_cron' ) ) { jbli_schedule_cron(); }

		if ( function_exists( 'flush_rewrite_rules' ) ) { flush_rewrite_rules( false ); }

		$jbli_output = (string) ob_get_clean();
		self::jbli_discard_unexpected_output( 'activation', $jbli_output );

	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public function jbli_deactivate() {

		ob_start();

		if ( function_exists( 'wp_clear_scheduled_hook' ) )
		{
			wp_clear_scheduled_hook( 'jbli_hourly_cron' );
			wp_clear_scheduled_hook( 'jbli_daily_reminder_cron' );
			wp_clear_scheduled_hook( 'jbli_apply_purge_cron' );
		}

		if ( function_exists( 'flush_rewrite_rules' ) ) { flush_rewrite_rules( false ); }

		$jbli_output = (string) ob_get_clean();
		self::jbli_discard_unexpected_output( 'deactivation', $jbli_output );

	}

}
