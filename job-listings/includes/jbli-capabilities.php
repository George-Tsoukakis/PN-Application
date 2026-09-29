<?php
/**
 * Capabilities
 *
 * Runtime capability grants and ownership enforcement for Job Listings.
 *
 * @package JobListings
 * @since   9.7.9
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return all capabilities used by the job_listing CPT.
 *
 * @return array
 */
function jbli_all_caps() {

	static $jbli_caps = null;

	if ( null !== $jbli_caps ) { return $jbli_caps; }

	$jbli_caps = array_fill_keys(
		array(
			'edit_job_listing',
			'read_job_listing',
			'delete_job_listing',

			'edit_job_listings',
			'edit_others_job_listings',

			'publish_job_listings',
			'read_private_job_listings',

			'delete_job_listings',
			'delete_private_job_listings',
			'delete_published_job_listings',
			'delete_others_job_listings',

			'edit_private_job_listings',
			'edit_published_job_listings',

			'create_job_listings',
		),
		true
	);

	return $jbli_caps;

}

/**
 * Return the capabilities a pharmacy/pharmacist user needs.
 *
 * @return array
 */
function jbli_pharmacist_caps() {

	static $jbli_caps = null;

	if ( null !== $jbli_caps ) { return $jbli_caps; }

	$jbli_caps = array_fill_keys(
		array(
			'read',

			'read_job_listing',

			'edit_job_listing',
			'edit_job_listings',
			'edit_published_job_listings',

			'publish_job_listings',

			'delete_job_listing',
			'delete_job_listings',
			'delete_published_job_listings',

			'create_job_listings',
		),
		true
	);

	return $jbli_caps;

}

/**
 * Check whether a WP_User object represents a pharmacy/pharmacist account.
 *
 * @param WP_User $jbli_user User object.
 * @return bool
 */
function jbli_user_is_pharmacist( $jbli_user ) {

	if ( ! $jbli_user instanceof WP_User || empty( $jbli_user->ID ) ) { return false; }

	if ( function_exists( 'jbli_is_pharmacist_type' ) && function_exists( 'jbli_get_account_type' ) && jbli_is_pharmacist_type( jbli_get_account_type( (int) $jbli_user->ID ) ) )
	{
		return true;
	}

	if ( function_exists( 'jbli_pharmacist_roles' ) )
	{
		$jbli_roles = jbli_pharmacist_roles();
	} else {
		$jbli_roles = array( 'pharmacist', 'farmakopios', 'pharmacy', 'farmakeio', );
	}

	return (bool) array_intersect( $jbli_roles, (array) $jbli_user->roles );

}

/**
 * Full admin/moderator access.
 *
 * @param WP_User $jbli_user User object.
 * @return bool
 */
function jbli_user_has_full_listing_access( $jbli_user ) {

	if ( ! $jbli_user instanceof WP_User || empty( $jbli_user->ID ) ) { return false; }

	return (
		user_can( $jbli_user, 'manage_options' ) ||
		user_can( $jbli_user, 'edit_others_posts' )
	);

}

/**
 * Check whether a user owns a job listing.
 *
 * @param int $jbli_post_id Post ID.
 * @param int $jbli_user_id User ID.
 * @return bool
 */
function jbli_user_owns_listing( $jbli_post_id, $jbli_user_id ) {

	$jbli_post_id = absint( $jbli_post_id );
	$jbli_user_id = absint( $jbli_user_id );

	if ( $jbli_post_id <= 0 || $jbli_user_id <= 0 ) { return false; }

	$post = get_post( $jbli_post_id );

	if ( ! $post instanceof WP_Post ) { return false; }

	if ( ! defined( 'JBLI_CPT' ) || JBLI_CPT !== $post->post_type ) { return false; }

	return (int) $post->post_author === (int) $jbli_user_id;

}

/**
 * Grant runtime capabilities.
 *
 * WordPress roles are not permanently modified.
 *
 * @param array   $jbli_allcaps All user capabilities.
 * @param array   $jbli_caps    Required capabilities.
 * @param array   $jbli_args    Capability arguments.
 * @param WP_User $jbli_user    User object.
 * @return array
 */
function jbli_grant_caps( $jbli_allcaps, $jbli_caps, $jbli_args, $jbli_user ) {

	unset( $jbli_caps, $jbli_args );

	if ( ! $jbli_user instanceof WP_User || empty( $jbli_user->ID ) ) { return $jbli_allcaps; }

	if ( ! empty( $jbli_allcaps['manage_options'] ) || ! empty( $jbli_allcaps['edit_others_posts'] ) )
	{
		return $jbli_allcaps + jbli_all_caps();
	}

	if ( jbli_user_is_pharmacist( $jbli_user ) ) { return $jbli_allcaps + jbli_pharmacist_caps(); }

	return $jbli_allcaps;

}

add_filter( 'user_has_cap', 'jbli_grant_caps', 10, 4 );

/**
 * Enforce ownership for edit/read/delete actions on job listings.
 *
 * @param array $jbli_caps    Required capabilities.
 * @param string $jbli_cap    Requested capability.
 * @param int    $jbli_user_id User ID.
 * @param array  $jbli_args   Capability arguments.
 * @return array
 */
function jbli_map_ownership_cap( $jbli_caps, $jbli_cap, $jbli_user_id, $jbli_args ) {

	if ( ! in_array( $jbli_cap, array( 'edit_post', 'delete_post', 'read_post' ), true ) ) { return $jbli_caps; }

	$jbli_post_id = isset( $jbli_args[0] ) ? absint( $jbli_args[0] ) : 0;

	if ( $jbli_post_id <= 0 ) { return $jbli_caps; }

	$post = get_post( $jbli_post_id );

	if ( ! $post instanceof WP_Post ) { return $jbli_caps; }

	if ( ! defined( 'JBLI_CPT' ) || JBLI_CPT !== $post->post_type ) { return $jbli_caps; }

	$jbli_user = get_userdata( $jbli_user_id );

	if ( ! $jbli_user instanceof WP_User ) { return array( 'do_not_allow' ); }

	if ( jbli_user_has_full_listing_access( $jbli_user ) ) { return $jbli_caps; }

	if ( ! jbli_user_is_pharmacist( $jbli_user ) ) { return array( 'do_not_allow' ); }

	if ( ! jbli_user_owns_listing( $jbli_post_id, $jbli_user_id ) ) { return array( 'do_not_allow' ); }

	if ( 'delete_post' === $jbli_cap ) { return array( 'delete_job_listing' ); }

	if ( 'edit_post' === $jbli_cap ) { return array( 'edit_job_listing' ); }

	if ( 'read_post' === $jbli_cap )
	{
		if ( 'private' === $post->post_status ) { return array( 'read_job_listing' ); }

		return array( 'read' );
	}

	return $jbli_caps;

}

add_filter( 'map_meta_cap', 'jbli_map_ownership_cap', 10, 4 );

/**
 * Prevent unauthorized listing editing in wp-admin.
 *
 * @return void
 */
function jbli_block_admin_edit_access() {

	if ( ! is_admin( ) || wp_doing_ajax() ) { return; }

	$jbli_action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	$jbli_post_id = isset( $_GET['jbli_post'] ) ? absint( $_GET['jbli_post'] ) : 0;

	if ( 'edit' !== $jbli_action || $jbli_post_id <= 0 ) { return; }

	$post = get_post( $jbli_post_id );

	if ( ! $post instanceof WP_Post ) { return; }

	if ( ! defined( 'JBLI_CPT' ) || JBLI_CPT !== $post->post_type ) { return; }

	if ( current_user_can( 'edit_post', $jbli_post_id ) ) { return; }

	wp_die(
		esc_html__( 'Δεν έχετε δικαίωμα επεξεργασίας αυτής της αγγελίας.', 'job-listings' ),
		esc_html__( 'Μη επιτρεπτή πρόσβαση', 'job-listings' ),
		array( 'response' => 403 )
	);

}

add_action( 'admin_init', 'jbli_block_admin_edit_access' );
