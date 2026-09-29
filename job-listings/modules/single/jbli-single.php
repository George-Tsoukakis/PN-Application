<?php
/**
 * Module: Single Listing Template Override
 *
 * Loads the plugin's own single-listing template for job_listing posts,
 * unless the active theme provides a CPT-specific override file:
 *   single-job_listing.php
 *
 * Generic theme files (jbli-single.php, index.php) are intentionally ignored
 * because they produce unstyled output for this CPT. Only a deliberately
 * crafted CPT template in the theme should take precedence.
 *
 * Priority 99 ensures it runs after theme and other plugin overrides.
 *
 * @package JobListings
 * @since   9.5.2
 */

defined( 'ABSPATH' ) || exit;

/**
 * Override the template for single job_listing posts.
 *
 * @since  9.5.2
 * @param  string $jbli_template Path to the template WordPress would use.
 * @return string Path to the correct single template.
 */
function jbli_override_single_template( $jbli_template ) {

	$jbli_template = (string) $jbli_template;

	if ( ! is_singular( JBLI_CPT ) ) { return $jbli_template; }


	$jbli_theme_specific = locate_template( 'single-' . JBLI_CPT . '.php' );

	if ( $jbli_theme_specific ) { return $jbli_theme_specific; }


	$jbli_plugin_template = (string) JBLI_DIR . 'modules/single/jbli-single-template.php';

	return is_readable( $jbli_plugin_template )
		? $jbli_plugin_template
		: $jbli_template;

}

add_filter( 'template_include', 'jbli_override_single_template', 99 );

/**
 * Redirect visitors away from unpublished listings.
 *
 * Runs on template_redirect, before any output. (It used to run inside the
 * template after get_header(), where the redirect could no longer be sent.)
 * Owners go to their dashboard with a notice; everyone else to the home page.
 *
 * @return void
 */
function jbli_single_redirect_unpublished() {

	if ( ! is_singular( JBLI_CPT ) || current_user_can( 'manage_options' ) ) { return; }

	$post = get_queried_object();

	if ( ! $post instanceof WP_Post || 'publish' === $post->post_status ) { return; }

	if ( is_user_logged_in() && (int) get_current_user_id() === (int) $post->post_author && function_exists( 'jbli_redirect_with_notice' ) )
	{
		$jbli_dash_url = function_exists( 'jbli_get_dashboard_url' ) ? jbli_get_dashboard_url() : home_url( '/dashboard/' );

		jbli_redirect_with_notice(
			$jbli_dash_url,
			__( 'Αυτή η αγγελία δεν είναι δημοσιευμένη. Μπορείτε να τη διαχειριστείτε από εδώ.', 'job-listings' ),
			'warning'
		);
	}

	wp_safe_redirect( home_url( '/' ) );
	exit;

}

add_action( 'template_redirect', 'jbli_single_redirect_unpublished', 5 );
