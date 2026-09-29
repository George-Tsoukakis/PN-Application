<?php
/**
 * Listings: Filters & Query Builder
 *
 * Parses and validates filter values from a request array, then builds
 * the WP_Query. These two concerns live together because the query is
 * a direct consumer of the validated filter values.
 *
 * Loaded by jbli-listings.php.
 *
 * @package JobListings
 * @since   9.9.30
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'jbli_parse_filters' ) )
{
	/**
	 * Parse and validate filter values from a request array.
	 *
	 * Validates taxonomy terms against cached term lists to avoid a DB query
	 * on every filter request.
	 *
	 * @param array $jbli_src Raw input array (e.g. $_GET or $_POST).
	 * @return array{f_nomos: int, f_cat: int, f_type: string, f_salary: string,
	 *               f_search: string, per_page: int, paged: int}
	 */
	function jbli_parse_filters( $jbli_src ) {

		$jbli_src = is_array( $jbli_src ) ? $jbli_src : array();

		$jbli_f_nomos  = absint( $jbli_src['job_nomos'] ?? 0 );
		$jbli_f_cat    = absint( $jbli_src['job_cat'] ?? 0 );
		$jbli_f_type   = sanitize_key( wp_unslash( $jbli_src['job_type'] ?? '' ) );
		$jbli_f_salary = sanitize_key( wp_unslash( $jbli_src['job_salary'] ?? '' ) );
		$jbli_f_search = sanitize_text_field( wp_unslash( $jbli_src['job_s'] ?? '' ) );
		$jbli_per_page = absint( $jbli_src['per_page'] ?? 0 );
		$jbli_paged    = max( 1, absint( $jbli_src['paged'] ?? 1 ) );

		$jbli_default_per_page = (int) apply_filters( 'jbli_listings_per_page', 12 );
		$jbli_default_per_page = max( 1, min( 50, $jbli_default_per_page ) );

		$jbli_per_page = $jbli_per_page > 0
			? max( 1, min( 50, $jbli_per_page ) )
			: $jbli_default_per_page;

		$jbli_f_search = trim( $jbli_f_search );

		if ( function_exists( 'jbli_strlen' ) && function_exists( 'jbli_substr' ) )
		{
			if ( jbli_strlen( $jbli_f_search ) > 80 ) { $jbli_f_search = jbli_substr( $jbli_f_search, 0, 80 ); }
		} elseif ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {

			if ( mb_strlen( $jbli_f_search, 'UTF-8' ) > 80 )
			{
				$jbli_f_search = mb_substr( $jbli_f_search, 0, 80, 'UTF-8' );
			}
		} else {
			$jbli_f_search = substr( $jbli_f_search, 0, 80 );
		}


		if ( $jbli_f_nomos )
		{
			$jbli_nomos_terms = function_exists( 'jbli_get_terms' ) ? jbli_get_terms( 'job_nomos' ) : array();
			$jbli_valid_nomos = array_map(
				function ( $jbli_t ) {

					return (int) $jbli_t->term_id;
				},
				array_filter(
					(array) $jbli_nomos_terms,
					function ( $jbli_t ) {

						return $jbli_t instanceof WP_Term;

					}

				)
			);

			if ( ! in_array( $jbli_f_nomos, $jbli_valid_nomos, true ) ) { $jbli_f_nomos = 0; }
		}

		if ( $jbli_f_cat )
		{
			$jbli_cat_terms  = function_exists( 'jbli_get_terms' ) ? jbli_get_terms( 'job_category' ) : array();
			$jbli_valid_cats = array_map(
				function ( $jbli_t ) {

					return (int) $jbli_t->term_id;
				},
				array_filter(
					(array) $jbli_cat_terms,
					function ( $jbli_t ) {

						return $jbli_t instanceof WP_Term;

					}

				)
			);

			if ( ! in_array( $jbli_f_cat, $jbli_valid_cats, true ) ) { $jbli_f_cat = 0; }
		}

		$jbli_type_options = function_exists( 'jbli_type_options' ) ? jbli_type_options() : array();

		if ( $jbli_f_type && ! array_key_exists( $jbli_f_type, (array ) $jbli_type_options ) ) { $jbli_f_type = ''; }

		$jbli_salary_options = function_exists( 'jbli_salary_options' ) ? jbli_salary_options() : array();

		if ( $jbli_f_salary && ! array_key_exists( $jbli_f_salary, (array ) $jbli_salary_options ) )
		{
			$jbli_f_salary = '';
		}

		return array(
			'f_nomos'  => $jbli_f_nomos,
			'f_cat'    => $jbli_f_cat,
			'f_type'   => $jbli_f_type,
			'f_salary' => $jbli_f_salary,
			'f_search' => $jbli_f_search,
			'per_page' => $jbli_per_page,
			'paged'    => $jbli_paged,
		);

	}
}

if ( ! function_exists( 'jbli_build_query' ) )
{
	/**
	 * Build a WP_Query from validated filter values.
	 *
	 * Featured listings sort first, while old listings that do not have the
	 * featured meta key are still included and treated as non-featured.
	 * Type and salary are optional meta filters.
	 *
	 * @param array $jbli_filters Validated filters from jbli_parse_filters().
	 * @return WP_Query
	 */
	function jbli_build_query( $jbli_filters ) {

		$jbli_filters = is_array( $jbli_filters ) ? $jbli_filters : array();

		$jbli_default_per_page = (int) apply_filters( 'jbli_listings_per_page', 12 );
		$jbli_default_per_page = max( 1, min( 50, $jbli_default_per_page ) );

		$jbli_per_page = ! empty( $jbli_filters['per_page'] )
			? max( 1, min( 50, absint( $jbli_filters['per_page'] ) ) )
			: $jbli_default_per_page;

		$jbli_meta_query = array( 'relation' => 'AND' );

		if ( ! empty( $jbli_filters['f_type'] ) )
		{
			$jbli_meta_query[] = array(
				'key'     => JBLI_META_TYPE,
				'value'   => sanitize_key( (string) $jbli_filters['f_type'] ),
				'compare' => '=',
			);
		}

		if ( ! empty( $jbli_filters['f_salary'] ) )
		{
			$jbli_meta_query[] = array(
				'key'     => JBLI_META_SALARY,
				'value'   => sanitize_key( (string) $jbli_filters['f_salary'] ),
				'compare' => '=',
			);
		}


		$jbli_meta_query[] = array(
			'relation'        => 'OR',
			'featured_clause' => array(
				'key'       => JBLI_META_FEATURED,
				'compare'   => 'EXISTS',
				'jbli_type' => 'NUMERIC',
			),
			'missing_featured_clause' => array( 'key'     => JBLI_META_FEATURED, 'compare' => 'NOT EXISTS', ),
		);

		$jbli_args = array(
			'post_type'              => JBLI_CPT,
			'post_status'            => 'publish',
			'posts_per_page'         => $jbli_per_page,
			'paged'                  => max( 1, absint( $jbli_filters['paged'] ?? 1 ) ),
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => false,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'meta_query'             => $jbli_meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'orderby'                => array( 'featured_clause' => 'DESC', 'date'            => 'DESC', ),
		);

		$jbli_tax_query = array( 'relation' => 'AND' );

		if ( ! empty( $jbli_filters['f_nomos'] ) )
		{
			$jbli_tax_query[] = array(
				'taxonomy' => 'job_nomos',
				'field'    => 'term_id',
				'terms'    => (int) $jbli_filters['f_nomos'],
			);
		}

		if ( ! empty( $jbli_filters['f_cat'] ) )
		{
			$jbli_tax_query[] = array(
				'taxonomy' => 'job_category',
				'field'    => 'term_id',
				'terms'    => (int) $jbli_filters['f_cat'],
			);
		}

		if ( count( $jbli_tax_query ) > 1 ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$jbli_args['tax_query'] = $jbli_tax_query;
		}

		if ( ! empty( $jbli_filters['f_search'] ) )
		{
			$jbli_args['s'] = sanitize_text_field( (string) $jbli_filters['f_search'] );
		}

		return new WP_Query( $jbli_args );

	}
}
