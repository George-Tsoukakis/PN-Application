<?php
/**
 * WP Rocket Compatibility
 *
 * @package JobListings
 * @since   9.7.9
 */

defined( 'ABSPATH' ) || exit;

function jbli_rocket_js_exclusions(): array {

	return array(
		/* Current front-end scripts (the old jl.js / job-listing-script names no longer exist). */
		'/job-listings/modules/form/js/jbli-form.js',
		'/job-listings/modules/listings/js/jbli-listings.js',
		'/job-listings/modules/apply/js/jbli-apply.js',
		'jbli-form.js',
		'jbli-listings.js',
		'jbli-apply.js',
		'jbli_data',
		'jbli_loaded',
		/* Address autocomplete on the listing form. */
		'maps.googleapis.com',
		/* jQuery: jbli-form.js depends on it. */
		'/jquery-?[0-9.](.*)(.min|.slim|.slim.min)?.js',
		'jquery-migrate',
	);

}

function jbli_rocket_css_exclusions(): array {

	return array(

		'jbli-tokens.css',
		'jbli-tokens\.css',
		'/job-listings/includes/jbli-tokens.css',

		'jbli-recent.css',
		'jbli-recent\.css',
		'/job-listings/modules/recent/css/jbli-recent.css',

		'jbli-single.css',
		'jbli-single\.css',
		'/job-listings/modules/single/css/jbli-single.css',

		'jbli-listings.css',
		'jbli-listings\.css',
		'/job-listings/modules/listings/css/jbli-listings.css',

		'jbli-form.css',
		'jbli-form\.css',
		'/job-listings/modules/form/css/jbli-form.css',

		'jbli-dashboard.css',
		'jbli-dashboard\.css',
		'/job-listings/modules/dashboard/css/jbli-dashboard.css',

		'jbli_tokens',
		'jbli_recent_style',
		'jbli_single_style',
		'jbli_listings_style',
		'jbli_form_style',
		'jbli_dashboard_style',
		'job-listing-recent-style',
	);

}

/**
 * CSS selectors that must remain in WP Rocket Remove Unused CSS output.
 *
 * File exclusions are not enough for RUCSS, because WP Rocket generates a
 * page-specific "used CSS" file. These selectors protect the recent listings
 * card UI when the homepage cache/preload is rebuilt.
 */
function jbli_rocket_rucss_safelist_selectors(): array {

	return array(
		'.jbli_recent',
		'.jbli_recent *',
		'.jbli_recent_grid',
		'.jbli_recent_grid_cols_2',
		'.jbli_recent_grid_cols_3',
		'.jbli_recent_footer',
		'.jbli_recent_heading',
		'.jbli_recent .jbli_card',
		'.jbli_recent .jbli_card:hover',
		'.jbli_recent .jbli_card_featured',
		'.jbli_recent .jbli_card_accent',
		'.jbli_recent .jbli_card_inner',
		'.jbli_recent .jbli_card_top',
		'.jbli_recent .jbli_card_pharmacy',
		'.jbli_recent .jbli_card_pharmacy strong',
		'.jbli_recent .jbli_badge',
		'.jbli_recent .jbli_badge_blue',
		'.jbli_recent .jbli_card_title',
		'.jbli_recent .jbli_card_title a',
		'.jbli_recent .jbli_card_title_prefix',
		'.jbli_recent .jbli_card_title em',
		'.jbli_recent .jbli_card_meta',
		'.jbli_recent .jbli_card_meta li',
		'.jbli_recent .jbli_card_meta li::before',
		'.jbli_recent .jbli_card_meta li::after',
		'.jbli_recent .jbli_card_salary',
		'.jbli_recent .jbli_card_excerpt',
		'.jbli_recent .jbli_card_footer',
		'.jbli_recent .jbli_card_footer_meta',
		'.jbli_recent .jbli_card_footer_expiry',
		'.jbli_recent .jbli_btn',
		'.jbli_recent .jbli_btn_primary',
		'.jbli_recent .jbli_views',
		'.jbli_recent .jbli_featured_ribbon',
	);

}

if ( ! function_exists( 'jbli_rocket_rucss_safelist' ) )
{
	function jbli_rocket_rucss_safelist( $jbli_safelist ): array {

		$jbli_safelist = is_array( $jbli_safelist ) ? $jbli_safelist : array();

		return jbli_rocket_add_exclusions(
			$jbli_safelist,
			jbli_rocket_rucss_safelist_selectors()
		);

	}
}

add_filter( 'rocket_rucss_safelist', 'jbli_rocket_rucss_safelist' );

if ( ! function_exists( 'jbli_rocket_add_exclusions' ) )
{
	function jbli_rocket_add_exclusions( array $jbli_exclusions, array $jbli_items ): array {

		return array_values(
			array_unique(
				array_filter(
					array_merge( $jbli_exclusions, $jbli_items )
				)
			)
		);

	}
}

if ( ! function_exists( 'jbli_rocket_delay_exclusions' ) )
{
	function jbli_rocket_delay_exclusions( array $jbli_exclusions ): array {

		return jbli_rocket_add_exclusions( $jbli_exclusions, jbli_rocket_js_exclusions() );

	}
}

add_filter( 'rocket_delay_js_exclusions', 'jbli_rocket_delay_exclusions' );

if ( ! function_exists( 'jbli_rocket_exclude_defer_js' ) )
{
	function jbli_rocket_exclude_defer_js( array $jbli_exclusions ): array {

		return jbli_rocket_add_exclusions( $jbli_exclusions, jbli_rocket_js_exclusions() );

	}
}

add_filter( 'rocket_exclude_defer_js', 'jbli_rocket_exclude_defer_js' );

if ( ! function_exists( 'jbli_rocket_exclude_js' ) )
{
	function jbli_rocket_exclude_js( array $jbli_exclusions ): array {

		return jbli_rocket_add_exclusions(
			$jbli_exclusions,
			array( 'jbli-form.js', 'jbli-listings.js', 'jbli-apply.js', 'maps.googleapis.com' )
		);

	}
}

add_filter( 'rocket_exclude_js', 'jbli_rocket_exclude_js' );

if ( ! function_exists( 'jbli_rocket_exclude_css' ) )
{
	function jbli_rocket_exclude_css( array $jbli_exclusions ): array {

		return jbli_rocket_add_exclusions( $jbli_exclusions, jbli_rocket_css_exclusions() );

	}
}

add_filter( 'rocket_exclude_css',       'jbli_rocket_exclude_css' );
add_filter( 'rocket_async_css_exclusions', 'jbli_rocket_exclude_css' );

if ( ! function_exists( 'jbli_rocket_reject_uris' ) )
{
	function jbli_rocket_reject_uris( array $jbli_uris ): array {

		$jbli_dash_url = function_exists( 'jbli_get_dashboard_url' ) ? jbli_get_dashboard_url() : home_url( '/dashboard/' );

		$jbli_form_url = function_exists( 'jbli_get_form_page_url' ) ? jbli_get_form_page_url() : home_url( '/nea-aggelia/' );

		$jbli_dash_path = rtrim( (string) wp_parse_url( $jbli_dash_url, PHP_URL_PATH ), '/' ) ?: '/dashboard';
		$jbli_form_path = rtrim( (string) wp_parse_url( $jbli_form_url, PHP_URL_PATH ), '/' ) ?: '/nea-aggelia';

		return jbli_rocket_add_exclusions(
			$jbli_uris,
			array( $jbli_dash_path, $jbli_dash_path . '/(.*)', $jbli_form_path, $jbli_form_path . '/(.*)', )
		);

	}
}

add_filter( 'rocket_cache_reject_uri', 'jbli_rocket_reject_uris' );

if ( ! function_exists( 'jbli_rocket_can_purge' ) )
{
	function jbli_rocket_can_purge(): bool {

		static $jbli_done = false;

		if ( $jbli_done ) { return false; }
		$jbli_done = true;
		return true;

	}
}

if ( ! function_exists( 'jbli_rocket_purge_plugin_pages' ) )
{
	function jbli_rocket_purge_plugin_pages(): void {

		if ( ! function_exists( 'rocket_clean_post' ) ) { return; }

		if ( ! jbli_rocket_can_purge( ) ) { return; }

		/*
		 * Purge every page that shows listings, whatever its slug and however it
		 * was built. (Only the "aggelies" slug used to be purged, so on other
		 * slugs or page-builder pages a new listing stayed invisible until the
		 * cache expired.)
		 */
		foreach ( jbli_listing_page_ids() as $jbli_page_id ) { rocket_clean_post( $jbli_page_id ); }

		if ( function_exists( 'rocket_clean_home' ) ) { rocket_clean_home(); }


		$jbli_front_page_id = (int) get_option( 'page_on_front' );

		if ( $jbli_front_page_id > 0 )
		{
			$jbli_front = get_post( $jbli_front_page_id );

			if ( $jbli_front instanceof WP_Post && has_shortcode( $jbli_front->post_content, 'recent-listings' ) )
			{
				rocket_clean_post( $jbli_front_page_id );
			}
		}

	}
}

if ( ! function_exists( 'jbli_rocket_purge_on_save' ) )
{
	function jbli_rocket_purge_on_save( int $jbli_post_id ): void {

		if ( wp_is_post_revision( $jbli_post_id ) || wp_is_post_autosave( $jbli_post_id ) ) { return; }

		if ( JBLI_CPT !== get_post_type( $jbli_post_id ) ) { return; }

		if ( function_exists( 'rocket_clean_post' ) ) { rocket_clean_post( $jbli_post_id ); }
		jbli_rocket_purge_plugin_pages();

	}
}

add_action( 'save_post_' . JBLI_CPT, 'jbli_rocket_purge_on_save' );

if ( ! function_exists( 'jbli_rocket_purge_listings' ) )
{
	function jbli_rocket_purge_listings( string $jbli_new_status, string $jbli_old_status, WP_Post $post ): void {

		if ( JBLI_CPT !== $post->post_type || $jbli_new_status === $jbli_old_status ) { return; }
		jbli_rocket_purge_plugin_pages();

	}
}

add_action( 'transition_post_status', 'jbli_rocket_purge_listings', 10, 3 );

function jbli_rocket_purge_on_expiry(): void {

	jbli_rocket_purge_plugin_pages();

}

add_action( 'jbli_expired', 'jbli_rocket_purge_on_expiry' );

function jbli_rocket_purge_on_renewal(): void {

	jbli_rocket_purge_plugin_pages();

}

add_action( 'jbli_renewed', 'jbli_rocket_purge_on_renewal' );

function jbli_rocket_purge_on_activation(): void {

	jbli_rocket_purge_plugin_pages();

}

add_action( 'jbli_activated', 'jbli_rocket_purge_on_activation' );

function jbli_rocket_purge_on_deactivation(): void {

	jbli_rocket_purge_plugin_pages();

}

add_action( 'jbli_deactivated', 'jbli_rocket_purge_on_deactivation' );

function jbli_rocket_purge_on_featured_change( int $jbli_post_id, int $jbli_new_value ): void {

	jbli_rocket_purge_plugin_pages();

}

add_action( 'jbli_featured_changed', 'jbli_rocket_purge_on_featured_change', 10, 2 );

/**
 * IDs of published pages that render listings ([listings], [recent-listings],
 * [dashboard], [new-listing]), found in post content or in post meta, where
 * page builders such as Elementor store their content.
 *
 * Cached for 12 hours; the cache is dropped whenever a page is saved.
 *
 * @return int[]
 */
function jbli_listing_page_ids(): array {

	$jbli_cached = get_transient( 'jbli_listing_page_ids' );

	if ( is_array( $jbli_cached ) ) { return array_map( 'intval', $jbli_cached ); }

	global $wpdb;

	$jbli_ids = array();

	foreach ( array( '[listings', '[recent-listings', '[dashboard', '[new-listing' ) as $jbli_tag ) {

		$jbli_like = '%' . $wpdb->esc_like( $jbli_tag ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$jbli_found = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			  WHERE p.post_status = 'publish'
			    AND p.post_type NOT IN ( 'revision', 'nav_menu_item', %s )
			    AND ( p.post_content LIKE %s
			          OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_value LIKE %s ) )",
			JBLI_CPT,
			$jbli_like,
			$jbli_like
		) );

		$jbli_ids = array_merge( $jbli_ids, array_map( 'intval', (array) $jbli_found ) );

	}

	$jbli_ids = array_values( array_unique( $jbli_ids ) );

	set_transient( 'jbli_listing_page_ids', $jbli_ids, 12 * HOUR_IN_SECONDS );

	return $jbli_ids;

}

add_action( 'save_post_page', static function () { delete_transient( 'jbli_listing_page_ids' ); } );
