<?php
/**
 * View Counter
 *
 * @package JobListings
 * @since   9.7.9
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the client IP for view de-duplication.
 *
 * Forwarding headers are only believed when the request really comes from a
 * proxy: Cloudflare's published ranges (CF-Connecting-IP) or a private /
 * loopback address such as a local nginx or load balancer (X-Real-IP,
 * X-Forwarded-For). Otherwise anyone could send a random header on each
 * request and inflate the view count. The 'jbli_trusted_proxy' filter can
 * mark other proxies as trusted.
 *
 * @return string
 */
function jbli_get_client_ip(): string {

	$jbli_remote = sanitize_text_field( wp_unslash( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );

	if ( ! filter_var( $jbli_remote, FILTER_VALIDATE_IP ) ) { return 'unknown'; }

	$jbli_from_cloudflare = jbli_ip_in_ranges( $jbli_remote, jbli_cloudflare_ranges() );
	$jbli_from_private    = ! filter_var( $jbli_remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	$jbli_trusted         = (bool) apply_filters( 'jbli_trusted_proxy', $jbli_from_cloudflare || $jbli_from_private, $jbli_remote );

	if ( ! $jbli_trusted ) { return $jbli_remote; }

	$jbli_keys = $jbli_from_cloudflare
		? array( 'HTTP_CF_CONNECTING_IP' )
		: array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' );

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

	return $jbli_remote;

}

/**
 * Cloudflare edge ranges (https://www.cloudflare.com/ips/).
 *
 * @return string[]
 */
function jbli_cloudflare_ranges(): array {

	return (array) apply_filters( 'jbli_cloudflare_ranges', array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
		'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
		'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
		'2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	) );

}

/**
 * Whether an IP falls inside any of the given CIDR ranges (IPv4 or IPv6).
 *
 * @param string   $jbli_ip     IP address.
 * @param string[] $jbli_ranges CIDR ranges.
 * @return bool
 */
function jbli_ip_in_ranges( string $jbli_ip, array $jbli_ranges ): bool {

	$jbli_ip_bin = @inet_pton( $jbli_ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( false === $jbli_ip_bin ) { return false; }

	foreach ( $jbli_ranges as $jbli_range ) {

		list( $jbli_subnet, $jbli_bits ) = array_pad( explode( '/', (string) $jbli_range, 2 ), 2, null );

		$jbli_subnet_bin = @inet_pton( (string) $jbli_subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $jbli_subnet_bin || strlen( $jbli_subnet_bin ) !== strlen( $jbli_ip_bin ) ) { continue; }

		$jbli_bits  = null === $jbli_bits ? strlen( $jbli_ip_bin ) * 8 : (int) $jbli_bits;
		$jbli_bytes = intdiv( $jbli_bits, 8 );
		$jbli_rest  = $jbli_bits % 8;

		if ( substr( $jbli_ip_bin, 0, $jbli_bytes ) !== substr( $jbli_subnet_bin, 0, $jbli_bytes ) ) { continue; }

		if ( 0 === $jbli_rest ) { return true; }

		$jbli_mask = ( 0xFF << ( 8 - $jbli_rest ) ) & 0xFF;

		if ( ( ord( $jbli_ip_bin[ $jbli_bytes ] ) & $jbli_mask ) === ( ord( $jbli_subnet_bin[ $jbli_bytes ] ) & $jbli_mask ) ) { return true; }

	}

	return false;

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
