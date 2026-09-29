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
