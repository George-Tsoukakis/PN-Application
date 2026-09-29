<?php
/**
 * View Counter
 *
 * @package JobListings
 * @since   9.7.9
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the real client IP, respecting Cloudflare and common reverse proxies.
 *
 * Priority: CF-Connecting-IP → X-Real-IP → X-Forwarded-For → REMOTE_ADDR.
 * Only validated IPs are returned — falls back to 'unknown' if none pass.
 *
 * @return string
 */
function jbli_get_client_ip(): string {

	$jbli_keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR', );

	foreach ( $jbli_keys as $jbli_key ) {

		if ( empty( $_SERVER[ $jbli_key ] ) ) { continue; }

		$jbli_value = sanitize_text_field( wp_unslash( (string) $_SERVER[ $jbli_key ] ) );

		if ( 'HTTP_X_FORWARDED_FOR' === $jbli_key )
		{
			$jbli_parts = explode( ',', $jbli_value );
			$jbli_value = trim( $jbli_parts[0] );
		}

		if ( filter_var( $jbli_value, FILTER_VALIDATE_IP ) ) { return $jbli_value; }

	}

	return 'unknown';

}

function jbli_get_visitor_hash(): string {

	static $jbli_hash = null;

	if ( null !== $jbli_hash ) { return $jbli_hash; }

	$jbli_ip = jbli_get_client_ip();

	$jbli_user = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	$jbli_hash = substr( hash_hmac( 'sha256', $jbli_ip . '|' . $jbli_user, wp_salt( 'nonce' ) ), 0, 16 );

	return $jbli_hash;

}

function jbli_track_view(): void {

	if ( is_admin() || ! is_singular( JBLI_CPT ) )
	{
		return;
	}

	$jbli_post_id = absint(
		get_queried_object_id()
	);

	if ( ! $jbli_post_id || JBLI_CPT !== get_post_type( $jbli_post_id ) )
	{
		return;
	}

	if ( current_user_can( 'manage_options' ) ) { return; }


	if ( is_user_logged_in() && (int) get_current_user_id() === (int) get_post_field( 'post_author', $jbli_post_id ) )
	{
		return;
	}

	if ( wp_doing_ajax() || wp_doing_cron() )
	{
		return;
	}

	if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) )
	{


		$jbli_ua = strtolower(
			wp_unslash(
				$_SERVER['HTTP_USER_AGENT']
			)
		);


		$jbli_bots = array(
			'googlebot',
			'bingbot',
			'yandexbot',
			'ahrefsbot',
			'semrushbot',
			'mj12bot',
			'duckduckbot',
			'slurp',
			'crawler',
			'spider',
		);

		foreach ( $jbli_bots as $jbli_bot ) {

			if ( false !== strpos( $jbli_ua, $jbli_bot ) )
			{
				return;
			}

		}
	}

	$jbli_transient_key =
		jbli_view_transient_key(
			$jbli_post_id
		);

	if ( false !== get_transient( $jbli_transient_key ) )
	{
		return;
	}

	set_transient(
		$jbli_transient_key,
		true,
		6 * HOUR_IN_SECONDS
	);

	$jbli_current =
		jbli_get_views(
			$jbli_post_id
		);

	$jbli_new_count = $jbli_current + 1;

	update_post_meta( (int) $jbli_post_id, JBLI_META_VIEWS, $jbli_new_count );

	wp_cache_delete( $jbli_post_id, 'post_meta' );


	jbli_get_views( $jbli_post_id, true );

}

add_action( 'template_redirect', 'jbli_track_view', 20 );

function jbli_view_transient_key(
	int $jbli_post_id
): string {

	return
		'jbli_view_'
		. $jbli_post_id
		. '_'
		. jbli_get_visitor_hash();
}

/**
 * Get listing views.
 *
 * @param int  $jbli_post_id       Listing post ID.
 * @param bool $jbli_force_refresh Bypass static/object cache and re-read from DB.
 *                            Pass true after updating the meta (e.g. in track_view).
 * @return int
 */
function jbli_get_views( int $jbli_post_id, bool $jbli_force_refresh = false ): int {

	static $jbli_cache = array();

	$jbli_post_id = absint( $jbli_post_id );

	if ( ! $jbli_force_refresh && isset( $jbli_cache[ $jbli_post_id ] ) ) { return $jbli_cache[ $jbli_post_id ]; }

	if ( $jbli_force_refresh ) { wp_cache_delete( 'jbli_views_' . $jbli_post_id, 'job-listings' ); }

	$jbli_value = max( 0, (int) get_post_meta( $jbli_post_id, JBLI_META_VIEWS, true ) );

	$jbli_cache[ $jbli_post_id ] = $jbli_value;


	wp_cache_set( 'jbli_views_' . $jbli_post_id, $jbli_value, 'job-listings', HOUR_IN_SECONDS );

	return $jbli_value;

}

function jbli_views_label(
	int $jbli_post_id
): string {

	$jbli_views =
		jbli_get_views(
			$jbli_post_id
		);

	if ( 0 === $jbli_views ) {
		return '';
	}

	return sprintf(
		'<span class="jbli_views" title="%s">%s %s</span>',
		esc_attr__(
			'Προβολές',
			'job-listings'
		),
		esc_html(
			number_format_i18n(
				$jbli_views
			)
		),
		esc_html__(
			'προβολές',
			'job-listings'
		)
	);
}
