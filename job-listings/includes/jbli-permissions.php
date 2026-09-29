<?php
/**
 * Permissions
 *
 * User role / account-type checks and listing ownership helpers.
 * Extracted from jbli-helpers.php for maintainability.
 *
 * @package JobListings
 * @since   9.9.17
 */

defined( 'ABSPATH' ) || exit;

/**
 * Account-type strings that identify a pharmacist/pharmacy.
 *
 * Single source of truth — used by both jbli-permissions.php and jbli-capabilities.php.
 *
 * @since 9.9.0
 * @return string[]
 */
function jbli_pharmacist_account_types() {

	return array(
		'pharmacist',
		'farmakopios',
		'φαρμακοποιός',
		'φαρμακοποιος',
		'pharmacy',
		'farmakeio',
		'φαρμακείο',
		'φαρμακειο',
	);

}

/**
 * WordPress role slugs that identify a pharmacist/pharmacy user.
 *
 * Single source of truth — used by both jbli-permissions.php and jbli-capabilities.php.
 *
 * @since 9.9.0
 * @return string[]
 */
function jbli_pharmacist_roles() {

	return array( 'pharmacist', 'farmakopios', 'pharmacy', 'farmakeio', );

}

function jbli_get_account_type( $jbli_user_id ) {

	$jbli_user_id = absint( $jbli_user_id );

	$jbli_keys = array( 'account_type', 'user_registration_account_type', );

	foreach ( $jbli_keys as $jbli_key ) {

		$jbli_value = get_user_meta( $jbli_user_id, $jbli_key, true );

		/* Some registration plugins store a select/radio field as an array. */
		if ( is_array( $jbli_value ) )
		{
			$jbli_value = (string) ( array_values( array_filter( $jbli_value, 'is_scalar' ) )[0] ?? '' );
		}

		if ( is_string( $jbli_value ) && '' !== trim( $jbli_value ) ) { return jbli_normalize_text( $jbli_value ); }

	}

	return '';

}

function jbli_is_pharmacist_type( $jbli_type ) {

	$jbli_type = (string) $jbli_type;

	return in_array( jbli_normalize_text( $jbli_type ), jbli_pharmacist_account_types(), true );

}

function jbli_is_pharmacist() {

	if ( ! is_user_logged_in( ) ) { return false; }

	$jbli_user_id = get_current_user_id();

	if ( jbli_is_pharmacist_type( jbli_get_account_type( $jbli_user_id ) ) ) { return true; }

	$jbli_user = get_userdata( $jbli_user_id );

	if ( ! $jbli_user ) { return false; }

	return (bool) array_intersect( jbli_pharmacist_roles(), (array) $jbli_user->roles );

}

function jbli_is_admin() {

	return current_user_can( 'manage_options' );

}

function jbli_is_listing_owner( $jbli_post_id, $jbli_user_id = 0 ) {

	$jbli_post_id = absint( $jbli_post_id );
	$jbli_user_id = absint( $jbli_user_id );
	$jbli_user_id = $jbli_user_id ?: get_current_user_id();
	$post         = get_post( $jbli_post_id );

	return $post instanceof WP_Post && defined( 'JBLI_CPT' ) && JBLI_CPT === $post->post_type && (int) $post->post_author === (int) $jbli_user_id;

}

function jbli_can_edit_listing( $jbli_post_id ) {

	$jbli_post_id = absint( $jbli_post_id );

	return jbli_is_admin() || ( jbli_is_pharmacist() && jbli_is_listing_owner( $jbli_post_id ) );

}

function jbli_get_pharmacy_name( $jbli_user_id ) {

	$jbli_user_id = absint( $jbli_user_id );
	$jbli_keys    = array( 'jbli_pharmacy_name', 'user_registration_pharmacy_name', 'billing_company', );

	foreach ( $jbli_keys as $jbli_key ) {

		$jbli_name = get_user_meta( $jbli_user_id, $jbli_key, true );

		if ( is_string( $jbli_name ) && '' !== trim( $jbli_name ) ) { return trim( $jbli_name ); }

	}

	$jbli_user = get_userdata( $jbli_user_id );

	return $jbli_user instanceof WP_User ? (string) $jbli_user->display_name : '';

}
