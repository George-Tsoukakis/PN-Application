<?php
/**
 * Listings: Card Data & Rendering
 *
 * Fetches display data for listing cards and renders them.
 * The bulk-fetch helper (jbli_bulk_fetch_terms) reduces taxonomy
 * queries from 2×N (one per post) to 2 total for the whole page.
 *
 * Loaded by jbli-listings.php.
 *
 * @package JobListings
 * @since   9.9.30
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bulk-fetch taxonomy terms for a set of post IDs.
 *
 * Returns a map of [ post_id => ['cats' => [...], 'jbli_nomoi' => [...]] ].
 * Uses wp_get_object_terms() — one query per taxonomy for all posts
 * instead of one wp_get_post_terms() per post.
 *
 * @param int[] $jbli_post_ids
 * @return array<int, array{cats: string[], nomoi: string[]}>
 */
function jbli_bulk_fetch_terms( array $jbli_post_ids ): array {

$jbli_result = array();

	foreach ( $jbli_post_ids as $jbli_id ) {

		$jbli_result[ $jbli_id ] = array( 'cats' => array(), 'jbli_nomoi' => array() );

	}

	if ( empty( $jbli_post_ids ) ) { return $jbli_result; }


	$jbli_cat_terms = wp_get_object_terms( $jbli_post_ids, 'job_category', array( 'fields' => 'all_with_object_id' ) );

	if ( ! is_wp_error( $jbli_cat_terms ) )
	{
		foreach ( $jbli_cat_terms as $jbli_term ) {if ( isset( $jbli_result[ $jbli_term->object_id ] ) )
	{

				$jbli_result[ $jbli_term->object_id ]['cats'][] = $jbli_term->name;

			}


		}
	}


	$jbli_nomos_terms = wp_get_object_terms( $jbli_post_ids, 'job_nomos', array( 'fields' => 'all_with_object_id' ) );

	if ( ! is_wp_error( $jbli_nomos_terms ) )
	{
		foreach ( $jbli_nomos_terms as $jbli_term ) {if ( isset( $jbli_result[ $jbli_term->object_id ] ) )
	{

				$jbli_result[ $jbli_term->object_id ]['jbli_nomoi'][] = $jbli_term->name;

			}


		}
	}


	return $jbli_result;

}

/**
 * Fetch display data for a single listing card.
 *
 * Delegates to JobListings\Models\Listing when available.
 *
 * @param int   $jbli_post_id Listing post ID.
 * @param array $jbli_terms   Optional pre-fetched terms: ['cats' => [...], 'jbli_nomoi' => [...]]
 *                       When provided, skips per-post wp_get_post_terms() calls.
 *                       Pass results from jbli_bulk_fetch_terms() for efficiency.
 * @return array
 */
function jbli_get_card_data( $jbli_post_id, array $jbli_terms = array() ) {

$jbli_post_id = absint( $jbli_post_id );

	if ( class_exists( '\JobListings\Models\Listing' ) )
	{
		$jbli_listing = \JobListings\Models\Listing::jbli_from_id(
			$jbli_post_id,
			! empty( $jbli_terms ) ? $jbli_terms : null
		);

	if ( $jbli_listing ) { return $jbli_listing->jbli_to_card_array(); }


	}


	$jbli_meta = get_post_meta( $jbli_post_id );

	if ( isset( $jbli_terms['cats'], $jbli_terms['jbli_nomoi'] ) )
	{
		$jbli_cats  = $jbli_terms['cats'];
		$jbli_nomoi = $jbli_terms['jbli_nomoi'];
	}

	else
	{
		$jbli_cats = wp_get_post_terms( $jbli_post_id, 'job_category', array( 'fields' => 'names' ) );
		$jbli_cats = ! is_wp_error( $jbli_cats ) && is_array( $jbli_cats ) ? $jbli_cats : array();

		$jbli_nomoi = wp_get_post_terms( $jbli_post_id, 'job_nomos', array( 'fields' => 'names' ) );
		$jbli_nomoi = ! is_wp_error( $jbli_nomoi ) && is_array( $jbli_nomoi ) ? $jbli_nomoi : array();
	}


	return array(
		'jbli_id'          => $jbli_post_id,
		'jbli_position'    => isset( $jbli_meta[ JBLI_META_POSITION ][0] )      ? (string) $jbli_meta[ JBLI_META_POSITION ][0]      : '',
		'pharmacy'         => isset( $jbli_meta[ JBLI_META_PHARMACY_NAME ][0] ) ? (string) $jbli_meta[ JBLI_META_PHARMACY_NAME ][0] : '',
		'jbli_salary'      => isset( $jbli_meta[ JBLI_META_SALARY ][0] )        ? (string) $jbli_meta[ JBLI_META_SALARY ][0]        : '',
		'jbli_type'        => isset( $jbli_meta[ JBLI_META_TYPE ][0] )          ? (string) $jbli_meta[ JBLI_META_TYPE ][0]          : '',
		'cats'             => $jbli_cats,
		'jbli_nomoi'       => $jbli_nomoi,
		'jbli_is_featured' => ! empty( $jbli_meta[ JBLI_META_FEATURED ][0] ) && 1 === (int) $jbli_meta[ JBLI_META_FEATURED ][0],
		'jbli_views'       => isset( $jbli_meta[ JBLI_META_VIEWS ][0] )         ? absint( $jbli_meta[ JBLI_META_VIEWS ][0] )        : 0,
	);

}

/**
 * Render a single listing card by including the card partial template.
 *
 * @param array $jbli_card Card data from jbli_get_card_data().
 */
function jbli_render_listing_card( $jbli_card ) {

$jbli_card = is_array( $jbli_card ) ? $jbli_card : array();

	$jbli_id          = (int)    ( $jbli_card['jbli_id']          ?? 0 );
	$jbli_position    = (string) ( $jbli_card['jbli_position']    ?? '' );
	$jbli_pharmacy    = (string) ( $jbli_card['pharmacy']    ?? '' );
	$jbli_salary      = (string) ( $jbli_card['jbli_salary']      ?? '' );
	$jbli_type        = (string) ( $jbli_card['jbli_type']        ?? '' );
	$jbli_cats        = is_array( $jbli_card['cats']  ?? null ) ? $jbli_card['cats']  : array();
	$jbli_nomoi       = is_array( $jbli_card['jbli_nomoi'] ?? null ) ? $jbli_card['jbli_nomoi'] : array();
	$jbli_is_featured = ! empty( $jbli_card['jbli_is_featured'] );
	$jbli_views       = (int) ( $jbli_card['jbli_views'] ?? 0 );
	$jbli_days_left   = (string) ( $jbli_card['jbli_days_left'] ?? ( function_exists( 'jbli_days_left' ) ? jbli_days_left( $jbli_id ) : '' ) );

	include __DIR__ . '/../jbli-listing-card.php';

}

/**
 * Render listing cards HTML for a WP_Query result.
 *
 * Outputs a .jbli_cards grid with all matching cards, plus a pagination
 * data div consumed by the AJAX pagination JS.
 * Returns empty-state HTML when no posts match.
 *
 * @param WP_Query $jbli_query  The listings query.
 * @param int      $jbli_paged  Current page number.
 * @return string HTML string.
 */
function jbli_render_cards_html( $jbli_query, $jbli_paged = 1 ) {

	if ( function_exists( 'jbli_plugin' ) )
	{
		return jbli_plugin()->jbli_listings()->jbli_render_cards_html( $jbli_query, $jbli_paged );
	}

	return '';

}
