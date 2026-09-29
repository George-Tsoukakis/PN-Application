<?php
/**
 * Helpers: Expiry Display
 *
 * Read helpers for displaying expiry dates and days-left badges.
 * These are presentation helpers — they do not change listing status.
 * For lifecycle actions (renew/activate/expire) see includes/jbli-expiry.php.
 *
 * Loaded by jbli-helpers.php.
 *
 * @package JobListings
 * @since   9.9.27
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read the stored expiry date without any side effects.
 *
 * Use this in loops, admin columns, or any read-heavy context where
 * a silent update_post_meta() call would be wasteful.
 * Returns empty string when no expiry date is stored — does NOT create one.
 *
 * @since 9.9.20
 *
 * @param int $jbli_post_id Listing post ID.
 * @return string MySQL datetime string or empty string.
 */
function jbli_get_expiry_date( $jbli_post_id ) {

	$jbli_expires = get_post_meta( absint( $jbli_post_id ), JBLI_META_EXPIRES, true );

	if ( $jbli_expires && false !== strtotime( (string ) $jbli_expires ) ) { return (string) $jbli_expires; }

	return '';

}

/**
 * Get the expiry date, creating it from the post date if missing.
 *
 * NOTE: This function has a side effect — if no expiry date is stored it
 * calls update_post_meta() to persist a computed date. Use
 * jbli_get_expiry_date() for read-only access.
 *
 * @param int $jbli_post_id Listing post ID.
 * @return string MySQL datetime string or empty string.
 */
function jbli_get_or_create_expiry_date( $jbli_post_id ) {

	$jbli_post_id = absint( $jbli_post_id );
	$jbli_expires = get_post_meta( $jbli_post_id, JBLI_META_EXPIRES, true );

	if ( $jbli_expires && false !== strtotime( (string ) $jbli_expires ) ) { return (string) $jbli_expires; }

	$post = get_post( $jbli_post_id );

	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) { return ''; }

	$jbli_created_timestamp = strtotime( $post->post_date );

	if ( false === $jbli_created_timestamp ) { $jbli_created_timestamp = time(); }


	$jbli_expires = wp_date( 'Y-m-d H:i:s', $jbli_created_timestamp + ( 30 * DAY_IN_SECONDS ) );

	update_post_meta( $jbli_post_id, JBLI_META_EXPIRES, $jbli_expires );

	return (string) $jbli_expires;

}

/**
 * Backward-compat alias.
 *
 * @deprecated Use jbli_get_or_create_expiry_date().
 * @param int $jbli_post_id Listing post ID.
 * @return string
 */
function jbli_ensure_expiry_date( $jbli_post_id ) {

	return jbli_get_or_create_expiry_date( $jbli_post_id );

}

/**
 * Return an HTML badge showing days remaining until expiry.
 *
 * @param int $jbli_post_id Listing post ID.
 * @return string HTML badge or empty string.
 */
function jbli_days_left( $jbli_post_id ) {

	$jbli_post_id = absint( $jbli_post_id );
	$jbli_expires = jbli_get_or_create_expiry_date( $jbli_post_id );

	if ( ! $jbli_expires ) { return ''; }

	$jbli_timestamp = strtotime( (string) $jbli_expires );

	if ( false === $jbli_timestamp ) { return ''; }

	$jbli_days = (int) ceil( ( $jbli_timestamp - time() ) / DAY_IN_SECONDS );

	return \JobListings\Formatters\Html::jbli_expiry_badge( $jbli_days );

}

/**
 * Return the formatted expiry date for jbli_display (dd/mm/yyyy).
 *
 * @param int $jbli_post_id Listing post ID.
 * @return string Formatted date or '—'.
 */
function jbli_expiry_date( $jbli_post_id ) {

	$jbli_post_id = absint( $jbli_post_id );
	$jbli_expires = jbli_get_or_create_expiry_date( $jbli_post_id );

	if ( ! $jbli_expires ) { return '—'; }

	$jbli_timestamp = strtotime( (string) $jbli_expires );

	if ( false === $jbli_timestamp ) { return '—'; }

	return function_exists( 'wp_date' )
		? wp_date( 'd/m/Y', $jbli_timestamp )
		: date_i18n( 'd/m/Y', $jbli_timestamp );

}
