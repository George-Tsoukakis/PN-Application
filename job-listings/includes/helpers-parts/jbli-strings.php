<?php
/**
 * Helpers: String Utilities
 *
 * Multibyte-safe string helpers with graceful fallbacks when the mbstring
 * extension is not available. Loaded by jbli-helpers.php.
 *
 * @package JobListings
 * @since   9.9.27
 */

defined( 'ABSPATH' ) || exit;

/**
 * Safe mb_strlen() — falls back to strlen() when mbstring is unavailable.
 *
 * strlen() is not UTF-8 aware but is safe for length-limit checks:
 * it over-counts multi-byte chars, so the limit is stricter, never looser.
 *
 * @param string $jbli_string   Input string.
 * @param string $jbli_encoding Character encoding (used only when mbstring is available).
 * @return int
 */
function jbli_strlen( $jbli_string, $jbli_encoding = 'UTF-8' ) {

	if ( function_exists( 'mb_strlen' ) ) { return mb_strlen( (string) $jbli_string, $jbli_encoding ); }

	return strlen( (string) $jbli_string );

}

/**
 * Safe mb_substr() — falls back to substr() when mbstring is unavailable.
 *
 * @param string   $jbli_string   Input string.
 * @param int      $jbli_start    Start position.
 * @param int|null $jbli_length   Maximum length (null = to end of string).
 * @param string   $jbli_encoding Character encoding (used only when mbstring is available).
 * @return string
 */
function jbli_substr( $jbli_string, $jbli_start, $jbli_length = null, $jbli_encoding = 'UTF-8' ) {

	if ( function_exists( 'mb_substr' ) )
	{
		return mb_substr( (string) $jbli_string, $jbli_start, $jbli_length, $jbli_encoding );
	}

	return null === $jbli_length ? substr( (string) $jbli_string, $jbli_start ) : substr( (string) $jbli_string, $jbli_start, $jbli_length );

}

/**
 * Normalize text for comparisons (trim + lowercase).
 *
 * @param string $jbli_value Input string.
 * @return string
 */
function jbli_normalize_text( $jbli_value ) {

	$jbli_value = trim( (string) $jbli_value );
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $jbli_value, 'UTF-8' ) : strtolower( $jbli_value );

}
