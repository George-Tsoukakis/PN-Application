<?php
/**
 * Front-end page cost for a pharmacist. TEST-ONLY.
 *
 * - one subscriptions SELECT per page (the Pro status is cached per
 *   request and per user), not one per should_render()/assets()/footer;
 * - the dictionaries are no longer inlined in PlandoseConfig: the page
 *   names content-versioned dictionary scripts (English for Pro only),
 *   whose hash is cached so a page does not build them;
 * - Plandose_Frontend::i18n_response(): type, cache headers, stale
 *   versions uncached, 304, unknown dictionary.
 */

require __DIR__ . '/lib.php';

global $wpdb;

$table = Plandose_Subscriptions::table_name();

$free = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$pro  = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$wpdb->insert( $table, array( 'user_id' => $pro, 'status' => 'pro', 'sub_end_date' => '2099-12-31' ) );

$settings_before = get_option( 'plandose_settings' );
$versions_before = get_option( Plandose_Frontend::I18N_OPTION, null );
pdt_defer(
	static function () use ( $settings_before, $versions_before ) {
		update_option( 'plandose_settings', $settings_before );
		if ( null === $versions_before ) {
			delete_option( Plandose_Frontend::I18N_OPTION );
		} else {
			update_option( Plandose_Frontend::I18N_OPTION, $versions_before, true );
		}
	}
);

// The tool must render here whatever the test site's settings say.
$settings                  = is_array( $settings_before ) ? $settings_before : array();
$settings['enabled']       = 1;
$settings['display_scope'] = 'everywhere';
update_option( 'plandose_settings', $settings );

/**
 * One front-end page for $user_id, the hooks in their real order.
 *
 * @return array{selects: int, before: string, loader: array, footer: string}
 */
function pdt_page( $user_id ) {
	global $wpdb;

	$GLOBALS['wp_scripts'] = null;
	wp_set_current_user( $user_id );

	$table   = Plandose_Subscriptions::table_name();
	$selects = 0;
	$count   = static function ( $sql ) use ( $table, &$selects ) {
		if ( preg_match( '/^\s*SELECT\b/i', $sql ) && false !== strpos( $sql, $table ) ) {
			++$selects;
		}
		return $sql;
	};
	add_filter( 'query', $count );

	Plandose_Frontend::maybe_send_nocache_headers(); // template_redirect.
	Plandose_Frontend::assets();                     // wp_enqueue_scripts.
	ob_start();
	Plandose_Frontend::render_modal();               // wp_footer.
	$footer = ob_get_clean();

	remove_filter( 'query', $count );

	$before = wp_scripts()->get_data( 'plandose-loader', 'before' );
	$loader = wp_scripts()->get_data( 'plandose-loader', 'data' );
	$json   = preg_match( '/var PlandoseLoader = (\{.*\});/s', (string) $loader, $m ) ? json_decode( $m[1], true ) : null;

	return array(
		'selects' => $selects,
		'before'  => is_array( $before ) ? implode( "\n", $before ) : (string) $before,
		'loader'  => is_array( $json ) ? $json : array(),
		'footer'  => $footer,
	);
}

delete_option( Plandose_Frontend::I18N_OPTION );

$f = pdt_page( $free );
$p = pdt_page( $pro );
wp_set_current_user( 0 );

// ---- 10: Pro status read once per page and per user -------------------------------
pdt_check( false !== strpos( $f['footer'], 'plandose-trigger' ), 'Free pharmacy page: the tool is rendered' );
pdt_check( $f['selects'] <= 1, 'Free pharmacy page: at most 1 subscriptions SELECT (got ' . $f['selects'] . ')' );
pdt_check( $p['selects'] <= 1, 'Pro pharmacy page: at most 1 subscriptions SELECT (got ' . $p['selects'] . ')' );
pdt_check( $p['selects'] >= 1, '… and the Pro page did read the new user\'s status (cache keyed by user)' );
pdt_check( false !== strpos( $p['before'], '"isPro":"1"' ), 'Pro page: isPro true after a Free page in the same request' );
pdt_check( false !== strpos( $f['before'], '"isPro":""' ), 'Free page: isPro false' );

wp_set_current_user( $pro );
pdt_same( true, Plandose_Frontend::current_user_is_pro(), 'current_user_is_pro(): Pro' );
wp_set_current_user( $free );
pdt_same( false, Plandose_Frontend::current_user_is_pro(), 'current_user_is_pro(): recomputed after the user changes' );
wp_set_current_user( 0 );
pdt_same( false, Plandose_Frontend::current_user_is_pro(), 'current_user_is_pro(): no user' );

// ---- 11: no dictionary inline; dictionary scripts named instead --------------------
foreach ( array( 'Free' => $f, 'Pro' => $p ) as $label => $page ) {
	pdt_check( false !== strpos( $page['before'], 'var PlandoseConfig' ), $label . ': PlandoseConfig still inline' );
	pdt_check( false === strpos( $page['before'], 'modalSubtitle' ) && false === strpos( $page['before'], 'rxDropHelp' ), $label . ': the dictionary is not inlined' );
	pdt_check( false !== strpos( $page['before'], '"disclaimer"' ), $label . ': the pharmacy\'s printed texts stay inline' );
	pdt_check( strlen( $page['before'] ) < 4000, $label . ': inline config is small (' . strlen( $page['before'] ) . ' bytes)' );
}

$urls = static function ( $page ) {
	$out = array();
	foreach ( isset( $page['loader']['i18n'] ) ? (array) $page['loader']['i18n'] : array() as $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$out[ $q['lang'] ?? '?' ] = $q;
	}
	return $out;
};
$fu = $urls( $f );
$pu = $urls( $p );
pdt_same( array( 'el' ), array_keys( $fu ), 'Free: only the Greek dictionary is requested' );
pdt_same( array( 'el', 'en' ), array_keys( $pu ), 'Pro: Greek and English' );
pdt_same( Plandose_Frontend::I18N_ACTION, $fu['el']['action'] ?? '', 'dictionary URL: admin-ajax action' );
pdt_same( determine_locale(), $fu['el']['locale'] ?? '', 'dictionary URL: carries the locale' );
pdt_check( 1 === preg_match( '/^[0-9a-f]{16}$/', $fu['el']['v'] ?? '' ), 'dictionary URL: versioned by a content hash' );
pdt_same( $fu['el']['v'], $pu['el']['v'] ?? '', 'the same Greek URL for Free and Pro (one browser cache entry)' );

// ---- eager path (plandose_lazy_load = false): the dictionaries run before state.js --
add_filter( 'plandose_lazy_load', '__return_false' );
$GLOBALS['wp_scripts'] = null;
wp_set_current_user( $pro );
Plandose_Frontend::assets();
remove_filter( 'plandose_lazy_load', '__return_false' );
$ws = wp_scripts();
$ws->all_deps( array( 'plandose-app' ) );
$order = array_values( $ws->to_do );
pdt_check( false !== array_search( 'plandose-i18n', $order, true ) && array_search( 'plandose-i18n', $order, true ) < array_search( 'plandose-state', $order, true ), 'eager: Greek dictionary before state.js' );
pdt_check( false !== array_search( 'plandose-i18n-en', $order, true ) && array_search( 'plandose-i18n-en', $order, true ) < array_search( 'plandose-state', $order, true ), 'eager, Pro: English dictionary before state.js' );
pdt_same( null, isset( $ws->registered['plandose-i18n'] ) ? $ws->registered['plandose-i18n']->ver : 'missing', 'eager: no ?ver= on the content-versioned URL' );
pdt_same( $fu['el']['v'], ( static function ( $src ) { parse_str( (string) wp_parse_url( $src, PHP_URL_QUERY ), $q ); return $q['v'] ?? ''; } )( $ws->registered['plandose-i18n']->src ), 'eager: the same Greek URL as the lazy loader' );
$eager_before = implode( "\n", (array) $ws->get_data( 'plandose-state', 'before' ) );
pdt_check( false !== strpos( $eager_before, 'var PlandoseConfig' ) && false === strpos( $eager_before, 'modalSubtitle' ), 'eager: PlandoseConfig inline without the dictionary' );
$GLOBALS['wp_scripts'] = null;
wp_set_current_user( $free );
add_filter( 'plandose_lazy_load', '__return_false' );
Plandose_Frontend::assets();
remove_filter( 'plandose_lazy_load', '__return_false' );
pdt_check( wp_script_is( 'plandose-i18n', 'enqueued' ) && ! wp_script_is( 'plandose-i18n-en', 'enqueued' ), 'eager, Free: no English dictionary' );
wp_set_current_user( 0 );
$GLOBALS['wp_scripts'] = null;

// ---- the hash is cached: a page does not build the dictionaries -------------------
$strings = 0;
$gettext = static function ( $translation, $text, $domain ) use ( &$strings ) {
	if ( 'plandose' === $domain ) {
		++$strings;
	}
	return $translation;
};
add_filter( 'gettext', $gettext, 10, 3 );
pdt_call( 'Plandose_Frontend', 'i18n_scripts', true );
$cached_cost = $strings;
delete_option( Plandose_Frontend::I18N_OPTION );
$strings = 0;
pdt_call( 'Plandose_Frontend', 'i18n_scripts', true );
$miss_cost = $strings;
remove_filter( 'gettext', $gettext, 10 );
pdt_same( 0, $cached_cost, 'hash cached: no dictionary string built on a page' );
pdt_check( $miss_cost > 100, 'cache miss: the dictionaries are built once to hash them (' . $miss_cost . ' strings)' );

// Settings embedded in the strings change the version (and the inline texts).
$s2               = $settings;
$s2['disclaimer'] = 'Δοκιμαστική σημείωση 1300.';
update_option( 'plandose_settings', $s2 );
$after = pdt_call( 'Plandose_Frontend', 'i18n_scripts', false );
parse_str( (string) wp_parse_url( $after['scripts']['plandose-i18n'], PHP_URL_QUERY ), $q2 );
pdt_check( $q2['v'] !== $fu['el']['v'], 'a changed disclaimer gives the dictionary a new URL' );
pdt_same( 'Δοκιμαστική σημείωση 1300.', $after['site']['disclaimer'] ?? '', '… and the new disclaimer inline' );
update_option( 'plandose_settings', $settings );

// A translation file's time is part of the fingerprint.
$lang_dir = WP_LANG_DIR . '/plugins';
$mo       = $lang_dir . '/plandose-zz_ZZ.mo';
wp_mkdir_p( $lang_dir );
$fp_none = pdt_call( 'Plandose_Frontend', 'i18n_fingerprint', 'zz_ZZ' );
file_put_contents( $mo, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test only.
pdt_defer( static function () use ( $mo ) { @unlink( $mo ); } ); // phpcs:ignore
$fp_mo = pdt_call( 'Plandose_Frontend', 'i18n_fingerprint', 'zz_ZZ' );
pdt_check( $fp_none !== $fp_mo, 'a new translation file changes the fingerprint' );
pdt_check( pdt_call( 'Plandose_Frontend', 'i18n_fingerprint', 'zz_ZZ' ) !== pdt_call( 'Plandose_Frontend', 'i18n_fingerprint', 'en_US' ), 'so does the locale' );

// The option stays bounded.
for ( $i = 0; $i < 10; $i++ ) {
	add_filter( 'plandose_i18n_fingerprint', $fpf = static function () use ( $i ) { return 'x' . $i; } );
	pdt_call( 'Plandose_Frontend', 'i18n_scripts', false );
	remove_filter( 'plandose_i18n_fingerprint', $fpf );
}
pdt_check( count( get_option( Plandose_Frontend::I18N_OPTION ) ) <= Plandose_Frontend::I18N_KEEP, 'the hash option keeps at most ' . Plandose_Frontend::I18N_KEEP . ' fingerprints' );

// ---- the dictionary script ------------------------------------------------------------
$state = pdt_call( 'Plandose_Frontend', 'i18n_state', array( 'el', 'en' ) );
foreach ( array( 'el', 'en' ) as $lang ) {
	$r = Plandose_Frontend::i18n_response( $lang, $state['locale'], $state['hashes'][ $lang ], '', true );
	pdt_same( 200, $r['status'], "$lang: 200" );
	pdt_same( 'application/javascript; charset=UTF-8', $r['headers']['Content-Type'], "$lang: JavaScript" );
	pdt_same( ( 'en' === $lang ? 'private' : 'public' ) . ', max-age=31536000, immutable', $r['headers']['Cache-Control'], "$lang: cached for a year, immutable (English per browser only)" );
	pdt_same( '"' . $state['hashes'][ $lang ] . '"', $r['headers']['ETag'] ?? '', "$lang: ETag = version" );
	$prefix = 'window.PlandoseI18n=window.PlandoseI18n||{};window.PlandoseI18n.' . $lang . '=';
	pdt_check( 0 === strpos( $r['body'], $prefix ), "$lang: assigns window.PlandoseI18n.$lang" );
	$dict = json_decode( rtrim( substr( $r['body'], strlen( $prefix ) ), ";\n" ), true );
	pdt_check( is_array( $dict ) && count( $dict ) > 100 && isset( $dict['modalTitle'], $dict['disclaimer'] ), "$lang: the whole dictionary" );
	if ( 'en' === $lang ) {
		pdt_same( 'Create Dosage Plan', $dict['modalTitle'] ?? '', 'en: English strings' );
	} else {
		pdt_same( 'Δημιουργία Πλάνου Δοσολογίας', $dict['modalTitle'] ?? '', 'el: Greek strings' );
	}
	$r304 = Plandose_Frontend::i18n_response( $lang, $state['locale'], $state['hashes'][ $lang ], '"' . $state['hashes'][ $lang ] . '"', true );
	pdt_same( 304, $r304['status'], "$lang: If-None-Match → 304" );
	pdt_same( '', $r304['body'], "$lang: 304 has no body" );
}

// The English dictionary (the Pro-only language toggle) is refused to anyone not allowed it.
$no_en = Plandose_Frontend::i18n_response( 'en', $state['locale'], $state['hashes']['en'] );
pdt_same( 403, $no_en['status'], 'en without permission: 403' );
pdt_check( false === strpos( $no_en['body'], 'Create Dosage Plan' ), 'en without permission: no dictionary in the body' );
pdt_check( false !== strpos( $no_en['headers']['Cache-Control'], 'no-cache' ), 'en without permission: not cacheable' );
$el_guest = Plandose_Frontend::i18n_response( 'el', $state['locale'], $state['hashes']['el'] );
pdt_same( 200, $el_guest['status'], 'el needs no permission' );

$stale = Plandose_Frontend::i18n_response( 'el', $state['locale'], '0000000000000000' );
// 1.30.2: an uncached redirect to the current URL, not an uncached build.
pdt_same( 302, $stale['status'], 'stale version: redirected' );
pdt_check( false !== strpos( (string) $stale['headers']['Location'], 'v=' . $state['hashes']['el'] ), 'stale version: to the current hash' );
pdt_check( false !== strpos( $stale['headers']['Cache-Control'], 'no-cache' ) && ! isset( $stale['headers']['ETag'] ), 'stale version: not cached' );

$none = Plandose_Frontend::i18n_response( 'el', $state['locale'], '' );
pdt_check( false !== strpos( $none['headers']['Cache-Control'], 'no-cache' ), 'no version: not cached' );

$bad = Plandose_Frontend::i18n_response( 'fr', $state['locale'], $state['hashes']['el'] );
pdt_same( 400, $bad['status'], 'unknown dictionary: 400' );
pdt_check( false !== strpos( $bad['headers']['Cache-Control'], 'no-cache' ), 'unknown dictionary: not cached' );

// Content that changed under the same URL (a filter, a hot-fixed string) is
// served uncached, never pinned for a year under the old version.
$tweak = static function ( $translation, $text, $domain ) {
	return ( 'plandose' === $domain && 'Πίσω' === $text ) ? 'Πίσω!' : $translation;
};
add_filter( 'gettext', $tweak, 10, 3 );
$changed = Plandose_Frontend::i18n_response( 'el', $state['locale'], $state['hashes']['el'] );
remove_filter( 'gettext', $tweak, 10 );
pdt_check( false !== strpos( $changed['body'], 'Πίσω!' ) && false !== strpos( $changed['headers']['Cache-Control'], 'no-cache' ), 'changed content under an old version: served fresh, uncached' );

// An unusable locale parameter is ignored, not passed to switch_to_locale().
$weird = Plandose_Frontend::i18n_response( 'el', '../../x', $state['hashes']['el'] );
pdt_same( 302, $weird['status'], 'malformed locale: ignored (redirected to the current locale, 1.30.2)' );
pdt_check( false !== strpos( (string) $weird['headers']['Location'], 'locale=' . rawurlencode( $state['locale'] ) ), 'malformed locale: redirect names the real one' );

pdt_same( true, has_action( 'wp_ajax_' . Plandose_Frontend::I18N_ACTION ) && has_action( 'wp_ajax_nopriv_' . Plandose_Frontend::I18N_ACTION ), 'endpoint registered for users and guests' );

pdt_done();
