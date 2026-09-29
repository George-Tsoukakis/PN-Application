<?php
/**
 * Featured Listings
 *
 * @package JobListings
 * @since   9.7.9
 */

defined( 'ABSPATH' ) || exit;

function jbli_is_featured( int $jbli_post_id ): bool {

	static $jbli_cache = array();

	if ( false === wp_cache_get( 'jbli_featured_' . $jbli_post_id, 'job-listings' ) )
	{
		unset( $jbli_cache[ $jbli_post_id ] );
	}

	if ( isset( $jbli_cache[ $jbli_post_id ] ) ) { return $jbli_cache[ $jbli_post_id ]; }

	$jbli_value = 1 === (int) get_post_meta( $jbli_post_id, JBLI_META_FEATURED, true );

	$jbli_cache[ $jbli_post_id ] = $jbli_value;

	wp_cache_set( 'jbli_featured_' . $jbli_post_id, 1, 'job-listings', HOUR_IN_SECONDS );

	return $jbli_value;

}

/**
 * Invalidate the jbli_is_featured() static cache for a specific listing.
 * Called after a toggle so same-request reads return the updated state.
 *
 * @param int $jbli_post_id
 */
function jbli_featured_invalidate_cache( int $jbli_post_id ): void {

	wp_cache_delete( 'jbli_featured_' . absint( $jbli_post_id ), 'job-listings' );

}

function jbli_featured_row_action( array $jbli_actions, WP_Post $post ): array {

	if ( JBLI_CPT !== $post->post_type || ! current_user_can( 'manage_options' ) )
	{
		return $jbli_actions;
	}

	$jbli_actions['job_featured'] =
		sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				jbli_featured_toggle_url(
					$post->ID
				)
			),
			esc_html(
				jbli_featured_toggle_label(
					$post->ID
				)
			)
		);

	return $jbli_actions;
}

add_filter( 'post_row_actions', 'jbli_featured_row_action', 20, 2 );

function jbli_featured_toggle_label( int $jbli_post_id ): string {

	return jbli_is_featured(
		$jbli_post_id
	)
		? __(
			'Αφαίρεση Featured',
			'job-listings'
		)
		: __(
			'Ορισμός Featured',
			'job-listings'
		);
}

function jbli_featured_toggle_url( int $jbli_post_id ): string {

	return wp_nonce_url(

		add_query_arg(
			array( 'action' => 'jbli_toggle_featured', 'post_id' => absint( $jbli_post_id ), ),

			admin_url( 'admin-post.php' )
		),

		'jbli_featured_' . absint( $jbli_post_id )
	);
}

function jbli_handle_toggle_featured(): void {

	if ( ! current_user_can( 'manage_options' ) )
	{
		wp_die( esc_html__( 'Unauthorized', 'job-listings' ),
			403
		);
	}

	$jbli_post_id = absint( $_GET['post_id'] ?? 0 );

	$jbli_nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );

	if ( ! jbli_featured_is_valid_request( $jbli_post_id, $jbli_nonce ) )
	{
		wp_die( esc_html__( 'Invalid request', 'job-listings' ), 400 );
	}

	$jbli_current 	 	= jbli_is_featured( $jbli_post_id );

	$jbli_new_value 	= $jbli_current ? 0 : 1;

	if ( $jbli_current !== (bool) $jbli_new_value )
	{

		update_post_meta( $jbli_post_id, JBLI_META_FEATURED, $jbli_new_value );

		wp_cache_delete( $jbli_post_id, 'post_meta' );

		jbli_featured_invalidate_cache( $jbli_post_id );

		if ( function_exists( 'jbli_sync_listing_storage' ) )
		{

			jbli_sync_listing_storage( $jbli_post_id );
		}

		do_action( 'jbli_featured_changed', $jbli_post_id, $jbli_new_value );
	}

	$jbli_redirect = wp_get_referer();

	if ( ! $jbli_redirect ) 
	{

		$jbli_redirect = admin_url( 'edit.php?post_type=' . JBLI_CPT );
	}

	wp_safe_redirect( $jbli_redirect );

	exit;

}

add_action( 'admin_post_jbli_toggle_featured', 'jbli_handle_toggle_featured' );

function jbli_featured_is_valid_request( int $jbli_post_id,string $jbli_nonce ): bool {

	if ( ! $jbli_post_id ) 
	{
		return false;
	}

	if ( ! wp_verify_nonce( $jbli_nonce, 'jbli_featured_' . $jbli_post_id ) )
	{
		return false;
	}

	$post =
		get_post(
			$jbli_post_id
		);

	return (
		$post instanceof WP_Post &&
		JBLI_CPT ===
		$post->post_type
	);
}

function jbli_add_featured_column( array $jbli_columns ): array {

	$jbli_new = array();

	foreach ( $jbli_columns as $jbli_key => $jbli_label )
	{

		$jbli_new[ $jbli_key ] = $jbli_label;

		if ( 'cb' === $jbli_key ) 
		{

			$jbli_new['job_featured_col'] =
				__(
					'Featured',
					'job-listings'
				);
		}
	}

	return $jbli_new;
}

add_filter( 'manage_' . JBLI_CPT . '_posts_columns', 'jbli_add_featured_column', 20 );

function jbli_featured_column_content( string $jbli_column, int $jbli_post_id ): void {

	if ( 'job_featured_col' !== $jbli_column )
	{
		return;
	}

	echo esc_html(

		jbli_is_featured(
			$jbli_post_id
		)

		? __(
			'Ναι',
			'job-listings'
		)

		: __(
			'Όχι',
			'job-listings'
		)
	);
}

add_action( 'manage_' . JBLI_CPT . '_posts_custom_column', 'jbli_featured_column_content', 10, 2 );

function jbli_featured_sortable_column( array $jbli_columns ): array {

	$jbli_columns[ 'job_featured_col' ] = 'job_featured';

	return $jbli_columns;
}

add_filter( 'manage_edit-' . JBLI_CPT . '_sortable_columns', 'jbli_featured_sortable_column' );

function jbli_featured_admin_orderby( WP_Query $jbli_query ): void {

	if ( ! is_admin() || ! $jbli_query->is_main_query() )
	{
		return;
	}

	if ( JBLI_CPT !== $jbli_query->get( 'post_type' ) )
	{
		return;
	}

	if ( 'job_featured' !== $jbli_query->get( 'orderby' ) )
	{
		return;
	}

	$jbli_query->set( 'meta_key', JBLI_META_FEATURED );

	$jbli_query->set( 'orderby', 'meta_value_num' );
}

add_action( 'pre_get_posts', 'jbli_featured_admin_orderby' );
