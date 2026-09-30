<?php
/**
 * Helpers: Taxonomy Term Cache
 *
 * Cached get_terms() wrapper and cache-invalidation hooks.
 * Loaded by jbli-helpers.php.
 *
 * @package JobListings
 * @since   9.9.27
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get taxonomy terms with object-cache layer.
 *
 * Terms are cached for 1 hour. Cache is invalidated automatically when
 * terms are created, updated, or deleted.
 *
 * @param string $jbli_taxonomy Taxonomy slug.
 * @return WP_Term[]|array
 */
function jbli_get_terms( $jbli_taxonomy ) {

	$jbli_taxonomy  = sanitize_key( $jbli_taxonomy );
	$jbli_cache_key = 'terms_' . $jbli_taxonomy;
	$jbli_cached    = wp_cache_get( $jbli_cache_key, 'job-listings' );

	if ( false !== $jbli_cached ) { return is_array( $jbli_cached ) ? $jbli_cached : array(); }

	$jbli_terms = get_terms( array(
		'taxonomy'   => $jbli_taxonomy,
		'hide_empty' => false,
		'orderby'    => 'name',
		'order'      => 'ASC',
	) );

	if ( is_wp_error( $jbli_terms ) ) { $jbli_terms = array(); }

	wp_cache_set( $jbli_cache_key, $jbli_terms, 'job-listings', HOUR_IN_SECONDS );

	return is_array( $jbli_terms ) ? $jbli_terms : array();

}

/**
 * Invalidate the cached terms for a taxonomy on any term change.
 *
 * @param int    $jbli_term_id  Term ID (unused — required by hook signature).
 * @param int    $jbli_tt_id    Term taxonomy ID (unused).
 * @param string $jbli_taxonomy Taxonomy slug.
 */
function jbli_clear_terms_cache( $jbli_term_id = 0, $jbli_tt_id = 0, $jbli_taxonomy = '' ) {

	unset( $jbli_term_id, $jbli_tt_id );

	$jbli_taxonomy = sanitize_key( $jbli_taxonomy );

	if ( '' !== $jbli_taxonomy )
	{
		wp_cache_delete( 'terms_' . $jbli_taxonomy, 'job-listings' );
		return;
	}

	if ( function_exists( 'wp_cache_flush_group' ) ) { wp_cache_flush_group( 'job-listings' ); }

}

add_action( 'created_term', 'jbli_clear_terms_cache', 10, 3 );
add_action( 'edited_term',  'jbli_clear_terms_cache', 10, 3 );
add_action( 'delete_term',  'jbli_clear_terms_cache', 10, 3 );
