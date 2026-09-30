<?php
/**
 * PlanDose 1.30.2 — review fixes in the front end, admin, privacy,
 * diagnostics, CLI and activation. TEST-ONLY.
 *
 * - dictionary script: any version but the current one is a 302 to the
 *   current URL, read from the stored hashes (no dictionary built);
 * - English dictionary: untouched default texts are recognised from
 *   Plandose_Settings::default_settings(), in source and translated form;
 * - plandose-guest.css is deferred like plandose.css;
 * - admin screen ids come from the hook suffixes WordPress returns (a
 *   translated «PlanDose» menu title changes them);
 * - capability(): a custom capability keeps its case;
 * - deleting an account removes its print-charge rows (both tables);
 * - privacy policy: the 30-minute / 12-month periods come from the code;
 * - Διαγνωστικά: minimum versions from the plugin header;
 * - CLI: no dead path_to_url() / no-op check_invoices() parameter;
 * - activation goes through the upgrade lock.
 */

require __DIR__ . '/lib.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;

/* ---- 1. dictionary script: stale / random version → 302 ------------------ */

$locale = determine_locale();
$state  = pdt_call( 'Plandose_Frontend', 'i18n_state', array( 'el' ) );
$hash   = $state['hashes']['el'];

$built = 0;
$spy   = static function ( $value ) use ( &$built ) {
	++$built;
	return $value;
};
// build_i18n_en()/build_i18n() read max_plan_days first; count those reads.
add_filter( 'option_plandose_settings', $spy );

$r = Plandose_Frontend::i18n_response( 'el', $locale, '0123456789abcdef' );
pdt_same( 302, $r['status'], 'random v: 302' );
pdt_same( '', $r['body'], 'random v: empty body' );
pdt_check( false !== strpos( $r['headers']['Cache-Control'], 'no-cache' ), 'random v: uncached' );
$q = array();
wp_parse_str( (string) wp_parse_url( $r['headers']['Location'], PHP_URL_QUERY ), $q );
pdt_same( $hash, isset( $q['v'] ) ? $q['v'] : '', 'random v: redirected to the current hash' );
pdt_same( 'plandose_i18n', isset( $q['action'] ) ? $q['action'] : '', 'redirect: same endpoint' );
pdt_same( $locale, isset( $q['locale'] ) ? $q['locale'] : '', 'redirect: same locale' );
remove_filter( 'option_plandose_settings', $spy );

$before = $built;
$r      = Plandose_Frontend::i18n_response( 'el', 'qq_QQ', $hash );
pdt_same( 302, $r['status'], 'made-up locale with the right hash: 302 to the real locale' );

$r = Plandose_Frontend::i18n_response( 'el', $locale, $hash );
pdt_same( 200, $r['status'], 'current v: 200' );
pdt_check( false !== strpos( $r['headers']['Cache-Control'], 'immutable' ), 'current v: long-cached' );
pdt_same( 400, Plandose_Frontend::i18n_response( 'xx', $locale, 'x' )['status'], 'unknown dictionary: 400' );
pdt_same( 403, Plandose_Frontend::i18n_response( 'en', $locale, 'x', '', false )['status'], 'English without Pro: 403' );

// The redirect must not build the dictionary: time many of them.
$t = microtime( true );
for ( $i = 0; $i < 200; $i++ ) {
	Plandose_Frontend::i18n_response( 'el', $locale, 'r' . $i );
}
$per = ( microtime( true ) - $t ) / 200 * 1000;
$t   = microtime( true );
for ( $i = 0; $i < 20; $i++ ) {
	Plandose_Frontend::i18n_response( 'el', $locale, $hash );
}
$full = ( microtime( true ) - $t ) / 20 * 1000;
echo sprintf( "# redirect %.3f ms, full dictionary %.3f ms\n", $per, $full );
pdt_check( $per < $full, 'a redirect costs less than building the dictionary' );

/* ---- 2. English dictionary: default texts from default_settings() ---------- */

$settings_before = get_option( 'plandose_settings', null );
pdt_defer(
	static function () use ( $settings_before ) {
		if ( null === $settings_before ) {
			delete_option( 'plandose_settings' );
		} else {
			update_option( 'plandose_settings', $settings_before );
		}
	}
);

$source_disclaimer = 'Το πλάνο αυτό είναι βοήθημα υπενθύμισης δοσολογίας και δεν αντικαθιστά την οδηγία ιατρού ή φαρμακοποιού. Σε περίπτωση αμφιβολίας συμβουλευτείτε τον φαρμακοποιό σας.';
$translate         = static function ( $translation, $text ) use ( $source_disclaimer ) {
	return $text === $source_disclaimer ? 'ZZ translated disclaimer' : $translation;
};

$en_disclaimer = static function ( $value ) {
	$s               = get_option( 'plandose_settings', array() );
	$s               = is_array( $s ) ? $s : array();
	$s['disclaimer'] = $value;
	update_option( 'plandose_settings', $s );
	$d = pdt_call( 'Plandose_Frontend', 'build_i18n_en', 30 );
	return $d['disclaimer'];
};

$english = 'This plan is a dosage reminder aid and does not replace the guidance of a doctor or pharmacist. If in doubt, consult your pharmacist.';
add_filter( 'gettext_plandose', $translate, 10, 2 );
pdt_same( $english, $en_disclaimer( $source_disclaimer ), 'Greek source default → English default (under a translation)' );
pdt_same( $english, $en_disclaimer( 'ZZ translated disclaimer' ), 'translated default → English default' );
pdt_same( 'Δική μας σημείωση.', $en_disclaimer( 'Δική μας σημείωση.' ), 'custom text kept verbatim' );
remove_filter( 'gettext_plandose', $translate, 10 );
pdt_same( $english, $en_disclaimer( $source_disclaimer ), 'Greek source default → English default' );
$src = (string) file_get_contents( PLANDOSE_PATH . 'includes/class-plandose-frontend.php' );
pdt_check( false === strpos( $src, 'Ευχαριστούμε που εμπιστευτήκατε' ) && false === strpos( $src, 'βοήθημα υπενθύμισης' ), 'frontend.php no longer repeats the Greek default texts' );
pdt_check( false === strpos( $src, "/* translators: %d: number of hours between doses. */\n\t\t\t/* translators:" ), 'no orphan translators comment' );
pdt_check( 1 === preg_match( '/rxUnreadText[^\n]*\n?/', $src ) && 2 === substr_count( $src, 'appends the closing »' ), 'rxUnreadText: both translators comments explain the missing »' );

/* ---- 5. plandose-guest.css deferred ---------------------------------------- */

$tag = Plandose_Frontend::defer_main_stylesheet( '<link rel="stylesheet">', 'plandose-guest', 'https://x.test/g.css', 'all' );
pdt_check( false !== strpos( $tag, 'rel="preload" id="plandose-guest-css"' ) && false !== strpos( $tag, '<noscript>' ), 'plandose-guest.css is preloaded with a noscript fallback' );
pdt_same( '<link rel="stylesheet">', Plandose_Frontend::defer_main_stylesheet( '<link rel="stylesheet">', 'plandose-trigger', 'https://x.test/t.css', 'all' ), 'plandose-trigger.css still blocks (styles the button)' );
add_filter( 'plandose_defer_stylesheet', '__return_false' );
pdt_same( '<link rel="stylesheet">', Plandose_Frontend::defer_main_stylesheet( '<link rel="stylesheet">', 'plandose-guest', 'https://x.test/g.css', 'all' ), 'the opt-out filter covers it too' );
remove_filter( 'plandose_defer_stylesheet', '__return_false' );

/* ---- 7. capability() keeps case -------------------------------------------- */

$cap_filter = null;
$set_cap    = static function ( $cap ) use ( &$cap_filter ) {
	if ( $cap_filter ) {
		remove_filter( 'plandose_manage_capability', $cap_filter );
	}
	$cap_filter = static function () use ( $cap ) {
		return $cap;
	};
	add_filter( 'plandose_manage_capability', $cap_filter );
	return Plandose_Admin::capability();
};

pdt_same( 'Manage_PlanDose', $set_cap( 'Manage_PlanDose' ), 'custom capability keeps its case' );
pdt_same( 'manage-plandose', $set_cap( 'manage-plandose' ), 'hyphen kept' );
pdt_same( 'badcap', $set_cap( "bad cap!\n" ), 'unusable characters dropped' );
pdt_same( 'manage_options', $set_cap( ' !! ' ), 'nothing left: default' );
pdt_same( 'manage_options', $set_cap( array( 'x' ) ), 'not a string: default' );

$editor = pdt_user( array(), 'editor' );
get_role( 'editor' )->add_cap( 'Manage_PlanDose' );
pdt_defer(
	static function () {
		get_role( 'editor' )->remove_cap( 'Manage_PlanDose' );
	}
);
$set_cap( 'Manage_PlanDose' );
wp_set_current_user( $editor );
pdt_check( current_user_can( Plandose_Admin::capability() ), 'an editor granted Manage_PlanDose passes the PlanDose check' );
wp_set_current_user( 0 );
remove_filter( 'plandose_manage_capability', $cap_filter );

/* ---- 6. admin screen ids follow a translated menu title -------------------- */

$admin = pdt_user( array(), 'administrator' );
wp_set_current_user( $admin );
$menu_title = static function ( $translation, $text ) {
	return 'PlanDose' === $text ? 'ΠλανΝτόουζ' : $translation;
};
add_filter( 'gettext_plandose', $menu_title, 10, 2 );
$GLOBALS['menu']             = array();
$GLOBALS['submenu']          = array();
$GLOBALS['admin_page_hooks'] = array();
$GLOBALS['_registered_pages'] = array();
Plandose_Admin::admin_menu();
remove_filter( 'gettext_plandose', $menu_title, 10 );

$ids      = Plandose_Admin::admin_screen_ids();
$settings = get_plugin_page_hookname( 'plandose-settings', 'plandose' );
pdt_check( 0 !== strpos( $settings, 'plandose_page_' ), 'translated title: WordPress no longer uses plandose_page_ (' . $settings . ')' );
foreach ( array( 'plandose', 'plandose-subscriptions', 'plandose-settings', 'plandose-diagnostics', 'plandose-audit' ) as $slug ) {
	$hook = get_plugin_page_hookname( $slug, 'plandose' === $slug ? '' : 'plandose' );
	pdt_check( in_array( $hook, $ids, true ), 'screen id known: ' . $hook );
}
wp_dequeue_style( 'plandose-admin' );
Plandose_Admin::admin_assets( $settings );
pdt_check( wp_style_is( 'plandose-admin', 'enqueued' ), 'admin CSS loads on the translated Settings screen' );
wp_dequeue_style( 'plandose-admin' );
Plandose_Admin::admin_assets( 'toplevel_page_other-plugin' );
pdt_check( ! wp_style_is( 'plandose-admin', 'enqueued' ), '… and not on another plugin\'s screen' );
wp_set_current_user( 0 );

/* ---- 8. deleted account: print-charge rows go too -------------------------- */

$gone = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
Plandose_Print_Charges::write_charge( $gone, md5( 'pdt-token-' . $gone ) );
Plandose_Print_Charges::record_request( $gone, md5( 'pdt-req-' . $gone ), md5( 'pdt-token-' . $gone ), Plandose_Print_Charges::KIND_CHARGE );
$count = static function ( $table, $id ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};
pdt_same( 1, $count( Plandose_Print_Charges::table_name(), $gone ), 'charge row written' );
pdt_same( 1, $count( Plandose_Print_Charges::requests_table_name(), $gone ), 'request row written' );
wp_delete_user( $gone );
pdt_same( 0, $count( Plandose_Print_Charges::table_name(), $gone ), 'account deleted: charge rows removed' );
pdt_same( 0, $count( Plandose_Print_Charges::requests_table_name(), $gone ), 'account deleted: request rows removed' );

/* ---- 11. privacy policy periods come from the code ------------------------- */

pdt_same( '30 λεπτά', Plandose_Privacy::receipt_ttl_text(), 'receipt TTL (1800 s) → «30 λεπτά»' );
pdt_same( '12 μήνες', Plandose_Privacy::print_log_retention_text(), 'print log retention (365 days) → «12 μήνες»' );
$psrc = (string) file_get_contents( PLANDOSE_PATH . 'includes/class-plandose-privacy.php' );
pdt_check( false === strpos( $psrc, 'για 30 λεπτά' ) && false === strpos( $psrc, 'για 12 μήνες' ), 'no hard-coded periods in the policy text' );

require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
set_current_screen( 'dashboard' );
$policy = static function () {
	Plandose_Privacy::add_privacy_policy_content();
};
if ( class_exists( 'WP_Privacy_Policy_Content' ) ) {
	add_action( 'admin_init', $policy );
	do_action( 'admin_init' );
	remove_action( 'admin_init', $policy );
	$prop = new ReflectionProperty( 'WP_Privacy_Policy_Content', 'policy_content' );
	$prop->setAccessible( true );
	$text = wp_json_encode( $prop->getValue(), JSON_UNESCAPED_UNICODE );
	pdt_check( false !== strpos( (string) $text, 'για 30 λεπτά ένα τεχνικό' ) && false !== strpos( (string) $text, 'για 12 μήνες ένα ιστορικό' ), 'policy text shows the derived periods' );
}
$GLOBALS['current_screen'] = null;

/* ---- 12. Διαγνωστικά: minimum versions from the header --------------------- */

$header = get_file_data( PLANDOSE_FILE, array( 'php' => 'Requires PHP', 'wp' => 'Requires at least' ) );
pdt_same( array( 'php' => $header['php'], 'wp' => $header['wp'] ), Plandose_Diagnostics::min_versions(), 'min versions read from the plugin header' );
$env = wp_json_encode( Plandose_Diagnostics::check_environment(), JSON_UNESCAPED_UNICODE );
pdt_check( false !== strpos( (string) $env, '≥ ' . $header['php'] ), 'environment check uses them' );

/* ---- 13. CLI dead code ------------------------------------------------------ */

if ( ! class_exists( 'Plandose_CLI' ) && is_readable( PLANDOSE_PATH . 'includes/class-plandose-cli.php' ) && ! defined( 'WP_CLI' ) ) {
	// Loaded only under WP-CLI; the checks below read the source instead.
	$csrc = (string) file_get_contents( PLANDOSE_PATH . 'includes/class-plandose-cli.php' );
	pdt_check( false === strpos( $csrc, 'function path_to_url' ), 'CLI: unused path_to_url() removed' );
	pdt_check( false !== strpos( $csrc, 'function check_invoices()' ), 'CLI: check_invoices() has no no-op parameter' );
	pdt_check( false === strpos( $csrc, "'key'     => 'user_registration_account_type'" ), 'CLI: account-type meta keys from Plandose_Access' );
} elseif ( class_exists( 'Plandose_CLI' ) ) {
	pdt_check( ! method_exists( 'Plandose_CLI', 'path_to_url' ), 'CLI: unused path_to_url() removed' );
	pdt_same( 0, ( new ReflectionMethod( 'Plandose_CLI', 'check_invoices' ) )->getNumberOfParameters(), 'CLI: check_invoices() has no no-op parameter' );
}

/* ---- 9. activation goes through the upgrade lock --------------------------- */

$version_before = get_option( 'plandose_version' );
pdt_defer(
	static function () use ( $version_before ) {
		delete_option( PLANDOSE_UPGRADE_LOCK );
		delete_transient( PLANDOSE_UPGRADE_BACKOFF );
		update_option( 'plandose_version', false === $version_before ? PLANDOSE_VERSION : $version_before );
	}
);

$ddl   = 0;
$ddspy = static function ( $q ) use ( &$ddl ) {
	if ( is_string( $q ) && preg_match( '/^\s*(CREATE TABLE|ALTER TABLE|SHOW TABLES LIKE)/i', $q ) && false !== stripos( $q, 'plandose' ) ) {
		++$ddl;
	}
	return $q;
};
add_filter( 'query', $ddspy );

delete_option( PLANDOSE_UPGRADE_LOCK );
update_option( 'plandose_version', '0.0.1-pdt' );
pdt_check( Plandose_Lock::claim( PLANDOSE_UPGRADE_LOCK, time() ), 'another request holds the upgrade lock' );
wp_cache_delete( PLANDOSE_UPGRADE_LOCK, 'options' );
plandose_activate();
pdt_same( 0, $ddl, 'activation while another request upgrades: no concurrent dbDelta()' );
pdt_same( '0.0.1-pdt', get_option( 'plandose_version' ), '… the version is left to the lock holder' );

delete_option( PLANDOSE_UPGRADE_LOCK );
wp_cache_delete( PLANDOSE_UPGRADE_LOCK, 'options' );
set_transient( PLANDOSE_UPGRADE_BACKOFF, time(), 600 );
update_option( 'plandose_version', PLANDOSE_VERSION );
plandose_activate();
pdt_check( $ddl > 0, 'activation re-verifies the tables even with the version current and a back-off set' );
pdt_same( PLANDOSE_VERSION, get_option( 'plandose_version' ), 'version recorded' );
pdt_same( false, get_transient( PLANDOSE_UPGRADE_BACKOFF ), 'back-off cleared' );
pdt_same( false, get_option( PLANDOSE_UPGRADE_LOCK ), 'lock released' );
remove_filter( 'query', $ddspy );

pdt_done();
