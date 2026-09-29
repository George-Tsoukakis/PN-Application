<?php
/**
 * No-Cache Headers
 *
 * @package JobListings
 * @since   9.7.9
 */

defined( 'ABSPATH' ) || exit;

function jbli_send_no_cache_headers(): void {

	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) )
	{
		return;
	}

	if ( ! jbli_is_no_cache_page() )
	{
		return;
	}

	/* Page caches (WP Rocket etc.) would otherwise store the form with a nonce that expires. */
	defined( 'DONOTCACHEPAGE' ) || define( 'DONOTCACHEPAGE', true );

	if ( headers_sent() )
	{
		return;
	}

	nocache_headers();

	header(
		'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0',
		true
	);

	header(
		'Pragma: no-cache',
		true
	);

	header(
		'Expires: Wed, 11 Jan 1984 05:00:00 GMT',
		true
	);

	header(
		'X-Robots-Tag: noarchive',
		true
	);

}

add_action( 'template_redirect', 'jbli_send_no_cache_headers', 1 );

function jbli_is_no_cache_page(): bool {

	if ( ! is_page() )
	{
		return false;
	}


	$post = get_queried_object();

	if ( ! $post instanceof WP_Post ) { return false; }

	$jbli_page_ids = array_filter( array(
		(int) get_option( 'jbli_form_page_id', 0 ),
		(int) get_option( 'jbli_dashboard_page_id', 0 ),
	) );

	if ( in_array( (int) $post->ID, $jbli_page_ids, true ) ) { return true; }

	/* Any page carrying [new-listing] or [dashboard], whatever its slug. */
	if ( function_exists( 'jbli_required_asset_modules' ) && array_intersect( array( 'form', 'dashboard' ), jbli_required_asset_modules() ) ) { return true; }

	return in_array( $post->post_name, jbli_no_cache_slugs(), true );

}

function jbli_no_cache_slugs(): array {

	$jbli_defaults = array( 'dashboard', 'diaxeirisi-aggelion', 'nea-aggelia', );

	$jbli_slugs =
		apply_filters(
			'jbli_no_cache_slugs',
			$jbli_defaults
		);

	if ( ! is_array( $jbli_slugs ) )
	{
		return $jbli_defaults;
	}

	return array_values(
		array_unique(
			array_filter(
				array_map(
					'sanitize_title',
					$jbli_slugs
				)
			)
		)
	);

}
