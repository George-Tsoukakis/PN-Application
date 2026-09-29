<?php
/**
 * Page URL Helpers
 *
 * Single source of truth for frontend page URLs.
 * Extracted from jbli-helpers.php for maintainability.
 *
 * Never use bare home_url('/dashboard/') or home_url('/nea-aggelia/')
 * elsewhere in the plugin — always call these helpers instead.
 *
 * Resolution order for each URL:
 *  1. Stored page ID option  (set via admin / filter)
 *  2. get_page_by_path()     (WordPress slug lookup)
 *  3. Hardcoded fallback     (last resort — site won't break on rename)
 *
 * Results are cached in a 12-hour transient so the DB lookup runs
 * at most twice a day. The transients are flushed whenever the
 * 'jbli_dashboard_page_id' or 'jbli_form_page_id' options change.
 *
 * @package JobListings
 * @since   9.9.17
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the canonical URL of the pharmacist dashboard page.
 *
 * Override via the 'jbli_dashboard_url' filter or by saving the
 * page ID in the 'jbli_dashboard_page_id' option.
 *
 * @since 9.9.17
 * @return string Absolute URL, never empty.
 */
function jbli_get_dashboard_url(): string {

	$jbli_cached = get_transient( 'jbli_dashboard_page_url' );

	if ( false !== $jbli_cached && '' !== $jbli_cached ) { return (string) $jbli_cached; }


	$jbli_page_id = (int) get_option( 'jbli_dashboard_page_id', 0 );

	if ( $jbli_page_id > 0 ) { $jbli_url = (string) get_permalink( $jbli_page_id ); }


	if ( empty( $jbli_url ) )
	{
		$jbli_page = get_page_by_path( 'dashboard' );
		$jbli_url  = $jbli_page instanceof WP_Post
			? (string) get_permalink( $jbli_page )
			: '';
	}


	if ( empty( $jbli_url ) ) { $jbli_url = home_url( '/dashboard/' ); }

	$jbli_url = (string) apply_filters( 'jbli_dashboard_url', $jbli_url );

	set_transient( 'jbli_dashboard_page_url', $jbli_url, 12 * HOUR_IN_SECONDS );

	return $jbli_url;

}

add_action(
	'update_optionjbli_dashboard_page_id',
	static function () {

		delete_transient( 'jbli_dashboard_page_url' );

	}

);

/**
 * Get the canonical URL of the new-listing form page.
 *
 * Override via the 'jbli_form_page_url' filter or by saving the
 * page ID in the 'jbli_form_page_id' option.
 *
 * @since 9.9.17
 * @return string Absolute URL, never empty.
 */
function jbli_get_form_page_url(): string {

	$jbli_cached = get_transient( 'jbli_form_page_url' );

	if ( false !== $jbli_cached && '' !== $jbli_cached ) { return (string) $jbli_cached; }


	$jbli_page_id = (int) get_option( 'jbli_form_page_id', 0 );

	if ( $jbli_page_id > 0 ) { $jbli_url = (string) get_permalink( $jbli_page_id ); }


	if ( empty( $jbli_url ) )
	{
		$jbli_page = get_page_by_path( 'nea-aggelia' );
		$jbli_url  = $jbli_page instanceof WP_Post ? (string) get_permalink( $jbli_page ) : '';
	}


	if ( empty( $jbli_url ) ) { $jbli_url = home_url( '/nea-aggelia/' ); }

	$jbli_url = (string) apply_filters( 'jbli_form_page_url', $jbli_url );

	set_transient( 'jbli_form_page_url', $jbli_url, 12 * HOUR_IN_SECONDS );

	return $jbli_url;

}

add_action( 'update_option_jbli_form_page_id', static function () {
	delete_transient( 'jbli_form_page_url' );
} );
