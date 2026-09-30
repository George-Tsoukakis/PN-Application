<?php
/**
 * Sanitizers
 *
 * Input sanitization helpers for phone, coordinates, and text.
 * Extracted from jbli-helpers.php for maintainability.
 *
 * @package JobListings
 * @since   9.9.17
 */

defined( 'ABSPATH' ) || exit;

function jbli_sanitize_phone( $jbli_phone ) {

	$jbli_phone = (string) $jbli_phone;
	$jbli_phone = preg_replace(
		'/[^\d+\s\-\(\)]/',
		'',
		$jbli_phone
	);

	return is_string( $jbli_phone ) ? trim( $jbli_phone ) : '';

}

function jbli_sanitize_lat( $jbli_value ) {

	return jbli_sanitize_coordinate( $jbli_value, -90, 90 );

}

function jbli_sanitize_lng( $jbli_value ) {

	return jbli_sanitize_coordinate( $jbli_value, -180, 180 );

}

function jbli_sanitize_coordinate( $jbli_value, $jbli_min, $jbli_max ) {

	$jbli_value = (string) $jbli_value;
	$jbli_min   = (float) $jbli_min;
	$jbli_max   = (float) $jbli_max;

	$jbli_value = trim( $jbli_value );

	if ( '' === $jbli_value || ! is_numeric( $jbli_value ) ) { return ''; }

	$jbli_float = (float) $jbli_value;

	if ( $jbli_float < $jbli_min || $jbli_float > $jbli_max ) { return ''; }

	return number_format( $jbli_float, 6, '.', '' );

}

/**
 * Sanitize a salary / job-type option key.
 *
 * Like sanitize_key() but keeps "+", so the built-in "2200+" survives when
 * the admin saves the options (sanitize_key() turned it into "2200" and
 * existing listings lost their label and filter match). 9.9.57.
 *
 * @param string $jbli_key Raw key.
 * @return string Lowercase a-z, 0-9, "_", "-", "+".
 */
function jbli_sanitize_option_key( $jbli_key ) {

	$jbli_key = strtolower( trim( (string) $jbli_key ) );

	return (string) preg_replace( '/[^a-z0-9_\-+]/', '', $jbli_key );

}
