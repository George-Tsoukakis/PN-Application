<?php
/**
 * Module: Public Listings
 *
 * Shortcode: [listings]
 *
 * Thin entry point that delegates to JobListings\Controllers\ListingsController.
 *
 * @package JobListings
 * @since   9.6.1
 */

defined( 'ABSPATH' ) || exit;

$jbli_filters_file = __DIR__ . '/listings-parts/jbli-filters.php';
$jbli_cards_file   = __DIR__ . '/listings-parts/jbli-cards.php';

if ( is_readable( $jbli_filters_file ) ) { require_once $jbli_filters_file; }

if ( is_readable( $jbli_cards_file ) ) { require_once $jbli_cards_file; }

unset( $jbli_filters_file, $jbli_cards_file );

if ( ! function_exists( 'jbli_render_listings' ) )
{
	/**
	 * Render the [listings] shortcode.
	 *
	 * @return string HTML output.
	 */
	function jbli_render_listings() {

		return jbli_plugin()->jbli_listings()->jbli_render_shortcode();

	}
}

add_shortcode( 'listings', 'jbli_render_listings' );

if ( ! function_exists( 'jbli_ajax_filter' ) )
{
	/**
	 * Handle AJAX filter requests for the listings grid.
	 *
	 * @return void
	 */
	function jbli_ajax_filter() {

		jbli_plugin()->jbli_listings()->jbli_handle_ajax_filter();

	}
}

add_action( 'wp_ajax_jbli_filter',        'jbli_ajax_filter' );
add_action( 'wp_ajax_nopriv_jbli_filter', 'jbli_ajax_filter' );
