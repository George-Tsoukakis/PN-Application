<?php
/**
 * OOP bridge helpers.
 *
 * Thin procedural wrappers that forward to JobListings\* classes.
 *
 * @package JobListings
 * @since   9.9.39
 */

defined( 'ABSPATH' ) || exit;

use JobListings\Plugin;
use JobListings\Models\Listing;
use JobListings\Support\View;

if ( ! function_exists( 'jbli_view' ) )
{
	function jbli_view( $jbli_name, array $jbli_data = array() ) {

		return View::jbli_make()->jbli_render( (string) $jbli_name, $jbli_data );

	}
}

if ( ! function_exists( 'jbli_plugin' ) )
{
	function jbli_plugin() {

		return Plugin::jbli_instance();

	}
}

if ( ! function_exists( 'jbli_model' ) )
{
	function jbli_model( $jbli_post_id, ?array $jbli_terms = null ) {

		return Listing::jbli_from_id( absint( $jbli_post_id ), $jbli_terms );

	}
}
