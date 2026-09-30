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
