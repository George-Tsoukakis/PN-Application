<?php
/**
 * Plugin Name:       QR ReBuilder Pro
 * Plugin URI:        https://pharmacyneeds.gr
 * Update URI:        https://pharmacyneeds.gr/qr-rebuilder-pro/
 * Description:       Σάρωση, ανάλυση και αναδημιουργία GS1 DataMatrix με αυτόματη εξαγωγή των πεδίων PC, SN, LOT και EXP.
 * Version:           2.16.0
 * Requires at least: 6.1
 * Requires PHP:      8.2
 * Author:            PharmacyNeeds
 * Author URI:        https://pharmacyneeds.gr
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       qr-rebuilder-pro
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Σταθερές, κοινοί helpers, φόρτωση components και hooks. Ό,τι διαβάζεται και
 * στο frontend δεν ζει στην QRRP_Admin, που φορτώνεται μόνο σε is_admin().
 */

if ( ! defined( 'QRRP_VERSION' ) ) {
	define( 'QRRP_VERSION', '2.16.0' );
}

if ( ! defined( 'QRRP_PLUGIN_FILE' ) ) {
	define( 'QRRP_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'QRRP_PLUGIN_DIR' ) ) {
	define( 'QRRP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'QRRP_PLUGIN_URL' ) ) {
	define( 'QRRP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/*
 * Δικαίωμα email όταν λείπει η γραμμή του option (βλ. qrrp_default_email_capability()).
 * 2.16.0: εγκεκριμένοι φαρμακοποιοί, όχι αυτο-δηλωμένοι (spam relay).
 */
if ( ! defined( 'QRRP_DEFAULT_EMAIL_CAPABILITY' ) ) {
	define( 'QRRP_DEFAULT_EMAIL_CAPABILITY', 'qrrp_verified_pharmacist' );
}

/** Cache-busting έκδοση ενός asset: το mtime του, αλλιώς η έκδοση του plugin. */
function qrrp_asset_version( $path ) {
	if ( ! is_string( $path ) || ! file_exists( $path ) ) {
		return QRRP_VERSION;
	}

	$mtime = filemtime( $path );

	return ( false !== $mtime ) ? (string) $mtime : QRRP_VERSION;
}

/**
 * Μέγιστο μήκος GS1 σε bytes για AJAX, DataMatrix, Mailer και JS (απορρίπτουν,
 * δεν κόβουν). Ο parser κρατά σκόπιμα δικό του αντίγραφο.
 */
function qrrp_max_raw_bytes() {
	return 4096;
}

/** Τυπικό μήκος SN / LOT χωρίς διαχωριστικά ('sn' ή 'lot'). Ο parser έχει δικό του αντίγραφο. */
function qrrp_default_gs1_fallback_length( $field ) {
	return ( 'lot' === $field ) ? 6 : 8;
}

/** Επιτρεπτό εύρος του ίδιου μήκους (sanitizer και min/max της φόρμας). */
function qrrp_gs1_length_range() {
	return array(
		'min' => 1,
		'max' => 20,
	);
}

/* Global helpers πρώτα· οι κλάσεις με τη σειρά των εξαρτήσεών τους. */
require_once QRRP_PLUGIN_DIR . 'includes/functions-access.php';
require_once QRRP_PLUGIN_DIR . 'includes/functions-print.php';
require_once QRRP_PLUGIN_DIR . 'includes/functions-upgrade.php';

require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-gs1-parser.php';
require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-datamatrix.php';
require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-text.php';
require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-url.php';
/* Πριν από Mailer και Shortcode: ορίζει το SHORTCODE_TAG και την ανίχνευση σελίδας εργαλείου. */
require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-tool-page.php';
/* Πάντα, όχι μόνο σε admin: γράφεται από την QRRP_Ajax στο frontend. */
require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-stats.php';
require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-tokens.php';
require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-mailer.php';
/* Πριν από την Ajax, που καλεί QRRP_Provenance::evaluate() σε κάθε αναδημιουργία. */
require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-provenance.php';
require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-rate-limiter.php';
require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-ajax.php';
require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-shortcode.php';

if ( is_admin() ) {
	require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-site-health.php';
	require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-admin.php';
	require_once QRRP_PLUGIN_DIR . 'includes/class-qrrp-vendor-check.php';
}

/**
 * Μεταφράσεις από το /languages του plugin (η just-in-time φόρτωση κοιτά μόνο
 * το wp-content/languages). Στο 'init': νωρίτερα, το WP 6.7+ βγάζει notice.
 */
function qrrp_load_textdomain() {
	load_plugin_textdomain( 'qr-rebuilder-pro', false, dirname( plugin_basename( QRRP_PLUGIN_FILE ) ) . '/languages' );
}

/** 2.15.2: απενεργοποίηση — αφαιρεί το προγραμματισμένο sweep των temp PNG. */
function qrrp_deactivate_plugin() {
	wp_clear_scheduled_hook( QRRP_Mailer::SWEEP_CRON_HOOK );
}

/* Hooks. */
add_filter( 'user_has_cap', 'qrrp_grant_pharmacist_cap', 10, 4 );
register_activation_hook( QRRP_PLUGIN_FILE, 'qrrp_activate_plugin' );
register_deactivation_hook( QRRP_PLUGIN_FILE, 'qrrp_deactivate_plugin' );
add_action( 'admin_init', 'qrrp_run_upgrade_maintenance' );
add_action( 'init', 'qrrp_load_textdomain', 1 );
/* 2.15.2: σβήνει τα temp PNG επιτυχών αποστολών email (βλ. QRRP_Mailer::send()). */
add_action( QRRP_Mailer::SWEEP_CRON_HOOK, array( 'QRRP_Mailer', 'run_scheduled_sweep' ) );
