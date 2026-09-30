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

/**
 * Visitor key for de-duplication: HMAC of the IP (never stored raw).
 *
 * 9.9.58: the User-Agent is no longer part of it — changing the UA from one
 * address produced a new "visitor" on every request.
 *
 * @return string
 */
function jbli_get_visitor_hash(): string {

	static $jbli_hash = null;

	if ( null !== $jbli_hash ) { return $jbli_hash; }

	$jbli_hash = substr( hash_hmac( 'sha256', jbli_get_client_ip(), wp_salt( 'nonce' ) ), 0, 16 );

	return $jbli_hash;

}

/**
 * Transient key: one view per visitor per listing every 6 hours.
 *
 * @param int $jbli_post_id Listing ID.
 * @return string
 */
function jbli_view_transient_key( int $jbli_post_id ): string {

	return 'jbli_view_' . $jbli_post_id . '_' . jbli_get_visitor_hash();

}

/**
 * Whether the User-Agent is a bot, preview fetcher or monitor.
 *
 * @since 9.9.58 (list extended: generic "bot", link previews, WP Rocket preload, monitors)
 * @param string $jbli_ua User-Agent.
 * @return bool
 */
function jbli_is_bot_user_agent( string $jbli_ua ): bool {

	$jbli_ua = strtolower( $jbli_ua );

	if ( '' === trim( $jbli_ua ) ) { return true; }

	$jbli_bots = (array) apply_filters( 'jbli_view_bot_signatures', array(
		'bot', 'crawl', 'spider', 'slurp', 'preview', 'fetch', 'facebookexternalhit', 'whatsapp',
		'telegram', 'viber', 'skype', 'discord', 'wp rocket', 'wp-rocket', 'wordpress', 'uptime',
		'pingdom', 'monitor', 'headless', 'lighthouse', 'pagespeed', 'gtmetrix', 'curl', 'wget',
		'python', 'java/', 'go-http', 'okhttp', 'axios', 'node-fetch', 'httpclient',
	) );

	foreach ( $jbli_bots as $jbli_bot ) {

		if ( '' !== $jbli_bot && false !== strpos( $jbli_ua, strtolower( (string) $jbli_bot ) ) ) { return true; }

	}

	return false;

}

/**
 * AJAX: count one view of a listing.
 *
 * 9.9.58: views were counted in PHP while the page was built, so a page
 * served from WP Rocket / CDN cache never counted, and the cached page kept
 * showing an old number. The listing page now sends a small request after
 * it has been visible for a moment; admin-ajax is never page-cached. Most
 * bots don't run JavaScript, which also keeps them out of the count.
 * The answer carries the current count so the page can show it.
 *
 * No nonce: a nonce inside a cached page expires. The request can only add
 * one view per visitor (IP) per listing every 6 hours.
 *
 * @since 9.9.58
 * @return void
 */
function jbli_ajax_count_view(): void {

	$jbli_post_id = absint( $_POST['post_id'] ?? 0 );

	if ( ! $jbli_post_id || JBLI_CPT !== get_post_type( $jbli_post_id ) || 'publish' !== get_post_status( $jbli_post_id ) )
	{
		wp_send_json_error( null, 400 );
	}

	$jbli_counted = false;
	$jbli_ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	$jbli_skip = current_user_can( 'manage_options' )
		|| ( is_user_logged_in() && (int) get_current_user_id() === (int) get_post_field( 'post_author', $jbli_post_id ) )
		|| jbli_is_bot_user_agent( $jbli_ua );

	if ( ! $jbli_skip )
	{
		$jbli_key = jbli_view_transient_key( $jbli_post_id );

		if ( false === get_transient( $jbli_key ) )
		{
			set_transient( $jbli_key, 1, 6 * HOUR_IN_SECONDS );
			jbli_increment_views( $jbli_post_id );
			$jbli_counted = true;
		}
	}

	nocache_headers();

	wp_send_json_success( array( 'views' => jbli_get_views( $jbli_post_id, true ), 'counted' => $jbli_counted ) );

}

add_action( 'wp_ajax_jbli_view',        'jbli_ajax_count_view' );
add_action( 'wp_ajax_nopriv_jbli_view', 'jbli_ajax_count_view' );

/**
 * Add one view in a single UPDATE, so simultaneous visits are not lost.
 *
 * @since 9.9.58
 * @param int $jbli_post_id Listing ID.
 * @return void
 */
function jbli_increment_views( int $jbli_post_id ): void {

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$jbli_rows = $wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = %s",
		$jbli_post_id,
		JBLI_META_VIEWS
	) );

	if ( ! $jbli_rows ) { add_post_meta( $jbli_post_id, JBLI_META_VIEWS, 1, true ); }

	wp_cache_delete( $jbli_post_id, 'post_meta' );

}

/**
 * Print the view beacon on single listing pages.
 *
 * @since 9.9.58
 * @return void
 */
function jbli_print_view_beacon(): void {

	if ( is_admin() || ! is_singular( JBLI_CPT ) ) { return; }

	$jbli_post_id = absint( get_queried_object_id() );

	if ( ! $jbli_post_id ) { return; }

	$jbli_cfg = array( 'url' => admin_url( 'admin-ajax.php' ), 'id' => $jbli_post_id );
	?>
	<script id="jbli_view_beacon">
	( function ( c ) {
		var sent = false;
		function send() {
			if ( sent || document.visibilityState !== 'visible' || ! window.fetch || ! window.FormData ) { return; }
			sent = true;
			var fd = new FormData();
			fd.append( 'action', 'jbli_view' );
			fd.append( 'post_id', String( c.id ) );
			fetch( c.url, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					if ( ! j || ! j.success || ! j.data ) { return; }
					var n = parseInt( j.data.views, 10 );
					if ( ! n ) { return; }
					var t = n.toLocaleString( 'el-GR' );
					document.querySelectorAll( '[data-jbli_views_count="' + c.id + '"]' ).forEach( function ( el ) { el.textContent = t; } );
				} )
				.catch( function () {} );
		}
		/* Counted after the page has been visible for 2 seconds. */
		function arm() { setTimeout( send, 2000 ); }
		if ( document.visibilityState === 'visible' ) { arm(); }
		else { document.addEventListener( 'visibilitychange', function v() { if ( document.visibilityState === 'visible' ) { document.removeEventListener( 'visibilitychange', v ); arm(); } } ); }
	} )( <?php echo wp_json_encode( $jbli_cfg ); ?> );
	</script>
	<?php

}

add_action( 'wp_footer', 'jbli_print_view_beacon', 50 );

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

	$jbli_value = max( 0, (int) get_post_meta( $jbli_post_id, JBLI_META_VIEWS, true ) );

	$jbli_cache[ $jbli_post_id ] = $jbli_value;

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
		esc_html( _n( 'προβολή', 'προβολές', $jbli_views, 'job-listings' ) )
	);
}
