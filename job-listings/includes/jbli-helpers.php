<?php
/**
 * Helpers
 *
 * Entry point for plugin utilities. Defines all JBLI_META_* constants (must
 * exist before any sub-file runs), loads the existing domain sub-files
 * (permissions, sanitizers, formatters, urls), then loads the new focused
 * helpers sub-files.
 *
 * Sub-file layout (original — unchanged):
 *   jbli-permissions.php          — role/account-type checks, ownership.
 *   jbli-sanitizers.php           — phone, lat, lng sanitization.
 *   jbli-formatters.php           — labels, badges, notices, allowed HTML.
 *   jbli-urls.php                 — dashboard/form page URL helpers.
 *
 * Sub-file layout (new — extracted from jbli-helpers.php in 9.9.27):
 *   helpers-parts/jbli-strings.php        — strlen, substr, normalize_text.
 *   helpers-parts/jbli-terms.php          — get_terms cache + invalidation hooks.
 *   helpers-parts/jbli-expiry-display.php — get_expiry_date, days_left, expiry_date.
 *   helpers-parts/jbli-notices.php        — redirect_with_notice, print_transient_notice, login_gate_html.
 *   helpers-parts/jbli-slugs.php          — greek_to_slug, build_post_slug.
 *
 * @package JobListings
 * @since   9.6.6
 */

defined( 'ABSPATH' ) || exit;

defined( 'JBLI_META_POSITION' )      || define( 'JBLI_META_POSITION',      'jbli_position' );
defined( 'JBLI_META_PHARMACY_NAME' ) || define( 'JBLI_META_PHARMACY_NAME', 'jbli_pharmacy_name' );
defined( 'JBLI_META_ADDRESS' )       || define( 'JBLI_META_ADDRESS',       'jbli_address' );
defined( 'JBLI_META_LAT' )           || define( 'JBLI_META_LAT',           'jbli_lat' );
defined( 'JBLI_META_LNG' )           || define( 'JBLI_META_LNG',           'jbli_lng' );
defined( 'JBLI_META_TYPE' )          || define( 'JBLI_META_TYPE',          'jbli_type' );
defined( 'JBLI_META_SALARY' )        || define( 'JBLI_META_SALARY',        'jbli_salary' );
defined( 'JBLI_META_CONTACT_PHONE' ) || define( 'JBLI_META_CONTACT_PHONE', 'jbli_contact_phone' );
defined( 'JBLI_META_EXPIRES' )       || define( 'JBLI_META_EXPIRES',       'jbli_expires' );
defined( 'JBLI_META_EXPIRED' )       || define( 'JBLI_META_EXPIRED',       'jbli_expired' );
defined( 'JBLI_META_REMINDER_SENT' ) || define( 'JBLI_META_REMINDER_SENT', 'jbli_reminder_sent' );
defined( 'JBLI_META_FEATURED' )      || define( 'JBLI_META_FEATURED',      'jbli_featured' );
defined( 'JBLI_META_VIEWS' )         || define( 'JBLI_META_VIEWS',         'jbli_views' );
defined( 'JBLI_META_EMAIL' )         || define( 'JBLI_META_EMAIL',         'jbli_email' );

foreach ( array( 'permissions', 'sanitizers', 'formatters', 'urls' ) as $jbli_sub ) {

	$jbli_sub_path = __DIR__ . '/jbli-' . $jbli_sub . '.php';

	if ( is_readable( $jbli_sub_path ) )
	{
		require_once $jbli_sub_path;
	} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[Job Listings] Missing helpers sub-file: ' . $jbli_sub_path );
	}

}

unset( $jbli_sub, $jbli_sub_path );

require_once __DIR__ . '/helpers-parts/jbli-strings.php';
require_once __DIR__ . '/helpers-parts/jbli-terms.php';
require_once __DIR__ . '/helpers-parts/jbli-expiry-display.php';
require_once __DIR__ . '/helpers-parts/jbli-notices.php';
require_once __DIR__ . '/helpers-parts/jbli-slugs.php';
require_once __DIR__ . '/helpers-parts/jbli-media.php';
