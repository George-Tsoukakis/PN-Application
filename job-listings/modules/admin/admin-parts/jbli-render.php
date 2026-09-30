<?php
/**
 * Admin Panel: Renderer
 *
 * Builds the WP_Query for the listings table and includes the template.
 * Loaded by jbli-admin-panel.php.
 *
 * @package JobListings
 * @since   9.9.24
 */

defined( 'ABSPATH' ) || exit;

function jbli_render_admin_panel(): void {

	if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Unauthorized', 'job-listings' ), 403 ); }


	$jbli_f_nomos  = absint( $_GET['f_nomos'] ?? 0 );
	$jbli_f_cat    = absint( $_GET['f_cat']   ?? 0 );
	$jbli_f_status = sanitize_key( wp_unslash( $_GET['f_status'] ?? '' ) );
	$jbli_f_s      = sanitize_text_field( wp_unslash( $_GET['f_s'] ?? '' ) );
	$jbli_paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );


	$jbli_f_s = trim( $jbli_f_s );

	if ( function_exists( 'jbli_strlen' ) && jbli_strlen( $jbli_f_s ) > 80 )
	{
		$jbli_f_s = function_exists( 'jbli_substr' )
			? jbli_substr( $jbli_f_s, 0, 80 )
			: substr( $jbli_f_s, 0, 80 );
	}


	if ( $jbli_f_nomos && ! term_exists( $jbli_f_nomos, 'job_nomos' ) ) { $jbli_f_nomos = 0; }

	if ( $jbli_f_cat && ! term_exists( $jbli_f_cat, 'job_category' ) ) { $jbli_f_cat = 0; }

	$jbli_allowed_statuses = array( 'publish', 'draft', 'job-expired', 'pending' );

	if ( $jbli_f_status && ! in_array( $jbli_f_status, $jbli_allowed_statuses, true ) ) { $jbli_f_status = ''; }


	$jbli_stats = jbli_get_admin_stats();


	$jbli_query_args = array(
		'post_type'              => JBLI_CPT,
		'post_status'            => $jbli_f_status ? $jbli_f_status : $jbli_allowed_statuses,
		'posts_per_page'         => 20,
		'paged'                  => $jbli_paged,
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'ignore_sticky_posts'    => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => true,
		'no_found_rows'          => false,
	);

	$jbli_tax_query = array( 'relation' => 'AND' );

	if ( $jbli_f_nomos )
	{
		$jbli_tax_query[] = array( 'taxonomy' => 'job_nomos',    'field' => 'term_id', 'terms' => $jbli_f_nomos );
	}

	if ( $jbli_f_cat )
	{
		$jbli_tax_query[] = array( 'taxonomy' => 'job_category', 'field' => 'term_id', 'terms' => $jbli_f_cat );
	}

	if ( count( $jbli_tax_query ) > 1 ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		$jbli_query_args['tax_query'] = $jbli_tax_query;
	}

	if ( $jbli_f_s ) { $jbli_query_args['s'] = $jbli_f_s; }

	$jbli_query = new WP_Query( $jbli_query_args );


	$jbli_all_nomoi = is_array( jbli_get_terms( 'job_nomos' ) )
		? jbli_get_terms( 'job_nomos' )
		: array();

	$jbli_all_cats = is_array( jbli_get_terms( 'job_category' ) )
		? jbli_get_terms( 'job_category' )
		: array();


	$jbli_template = __DIR__ . '/../jbli-admin-panel-template.php';

	if ( is_readable( $jbli_template ) ) { include $jbli_template; }

	wp_reset_postdata();

}
