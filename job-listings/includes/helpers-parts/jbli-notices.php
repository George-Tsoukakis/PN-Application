<?php
/**
 * Helpers: Notices & Redirects
 *
 * Transient-based redirect notices and the login-gate HTML block.
 * Loaded by jbli-helpers.php.
 *
 * @package JobListings
 * @since   9.9.27
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redirect the current user to $jbli_url with a flash notice.
 *
 * Stores the notice in a per-user transient (1 minute TTL) so it
 * survives the redirect and is consumed by jbli_print_transient_notice().
 *
 * @param string $jbli_url     Destination URL.
 * @param string $jbli_message Notice text (plain text, not HTML).
 * @param string $jbli_type    Notice type: 'success' | 'error' | 'warning' | 'info'.
 */
function jbli_redirect_with_notice( $jbli_url, $jbli_message, $jbli_type = 'success' ) {

	$jbli_user_id = get_current_user_id();

	if ( $jbli_user_id > 0 )
	{
		set_transient(
			'jbli_notice_' . $jbli_user_id,
			array( 'msg' => (string) $jbli_message, 'jbli_type' => (string) $jbli_type ),
			MINUTE_IN_SECONDS
		);
	}

	wp_safe_redirect( esc_url_raw( (string) $jbli_url ) );
	exit;

}

/**
 * Consume and return the current user's pending flash notice as HTML.
 *
 * Returns empty string when no notice is pending.
 *
 * @return string Notice HTML or empty string.
 */
function jbli_print_transient_notice() {

	$jbli_user_id = get_current_user_id();

	if ( $jbli_user_id <= 0 ) { return ''; }

	$jbli_data = get_transient( 'jbli_notice_' . $jbli_user_id );

	if ( ! is_array( $jbli_data ) || empty( $jbli_data['msg'] ) ) { return ''; }

	delete_transient( 'jbli_notice_' . $jbli_user_id );

	$jbli_type = ! empty( $jbli_data['jbli_type'] ) ? sanitize_key( (string) $jbli_data['jbli_type'] ) : 'info';

	return jbli_notice( (string) $jbli_data['msg'], $jbli_type );

}

/**
 * Return the login / register gate HTML block.
 *
 * Shown by [new-listing] and [dashboard] when the visitor is not logged in.
 *
 * @since 9.9.17
 * @since 9.9.27 Moved to helpers-parts/jbli-notices.php.
 *
 * @param string $jbli_heading  Block heading text.
 * @param string $jbli_subtitle Explanatory sub-text below the heading.
 * @param string $jbli_icon_svg SVG markup for the icon (raw, already sanitised by caller).
 * @return string HTML string.
 */
function jbli_login_gate_html( string $jbli_heading, string $jbli_subtitle, string $jbli_icon_svg = '' ): string {

	return \JobListings\Formatters\Html::jbli_login_gate( $jbli_heading, $jbli_subtitle, $jbli_icon_svg );

}
