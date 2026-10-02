<?php
/**
 * 1.8.0: site search reads only candidate pages (same results), background
 * re-index, entry topics, early assets, admin messages on failed saves.
 */
require __DIR__ . '/lib.php';
global $wpdb;

// ---- site search: the database prefilter changes nothing --------------------
$words = array( 'παράδοση', 'παραγγελία', 'Θεσσαλονίκη', 'Αθήνα', 'εκτύπωση', 'ετικέτες', 'QR', 'κωδικός', 'λογαριασμός', 'εγγραφή', 'φαρμακείο', 'τιμολόγιο', 'κόστος', 'δωρεάν', 'συνδρομή', 'Viber', 'κοινότητα', 'ελλείψεις', 'ΕΟΦ', 'απόθεμα', 'datamatrix', 'gs1', 'σάρωση', 'κάμερα', 'κινητό' );
mt_srand( 42 );
$ids = array();
for ( $i = 0; $i < 60; $i++ ) {
	$pick = array();
	for ( $j = 0; $j < 12; $j++ ) {
		$pick[] = $words[ mt_rand( 0, count( $words ) - 1 ) ];
	}
	$ids[] = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Δοκιμή ' . $i . ' ' . $pick[0] . ' ' . $pick[1],
			'post_content' => 'Κείμενο για ' . implode( ' και ', $pick ) . '. Περισσότερα για ' . $pick[2] . ' εδώ.',
		)
	);
}
pnt_defer(
	function () use ( $ids ) {
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
);
$queries = array( 'παράδοση στη Θεσσαλονίκη', 'πόσο κοστίζει η συνδρομή', 'εκτυπωση ετικετων', 'paradosi thessaloniki', 'κοινότητα Viber', 'ελλείψεις ΕΟΦ απόθεμα', 'σάρωση datamatrix με κάμερα', 'λογαριασμός φαρμακείου εγγραφή', 'κωδικοί QR', 'τιμολογιο δωρεαν' );
$same    = 0;
$nonempty = 0;
foreach ( $queries as $q ) {
	$fast = PNChat_Site_Search::search( $q, 5 );
	add_filter( 'pnchat_site_search_prefilter', '__return_false' );
	$full = PNChat_Site_Search::search( $q, 5 );
	remove_filter( 'pnchat_site_search_prefilter', '__return_false' );
	$same     += ( $fast === $full ) ? 1 : 0;
	$nonempty += $fast ? 1 : 0;
	if ( $fast !== $full ) {
		echo "       differs: $q\n";
	}
}
pnt_same( count( $queries ), $same, 'search: the prefilter gives exactly the full scan\'s results (' . $nonempty . ' queries with results)' );
pnt_check( $nonempty >= 5, 'search: most test queries find pages' );

$wpdb->queries = array();
$queries_before = $wpdb->num_queries;
$last = '';
add_filter(
	'query',
	$spy = function ( $sql ) use ( &$last ) {
		if ( false !== strpos( $sql, '_pnchat_tokens' ) && false !== strpos( $sql, 'LIKE' ) ) {
			$last = $sql;
		}
		return $sql;
	}
);
PNChat_Site_Search::search( 'κοινότητα Viber', 3 );
remove_filter( 'query', $spy );
pnt_check( '' !== $last, 'search: the database query filters by the question words' );

// ---- re-index in the background ---------------------------------------------------
wp_clear_scheduled_hook( PNChat_Site_Search::CRON_HOOK );
$n = PNChat_Site_Search::rebuild();
pnt_same( PNChat_Site_Search::BATCH, $n, 'rebuild: one batch now (' . PNChat_Site_Search::BATCH . ' pages)' );
pnt_check( (bool) wp_next_scheduled( PNChat_Site_Search::CRON_HOOK ), 'rebuild: the rest is scheduled' );
for ( $i = 0; $i < 20 && PNChat_Site_Search::index_batch() >= PNChat_Site_Search::BATCH; $i++ ) {
	// Run the scheduled batches.
}
pnt_same( array(), PNChat_Site_Search::missing_ids( 10 ), 'rebuild: after the batches every page is indexed' );

// ---- topic of an entry -----------------------------------------------------------
$eid = PNChat_Store::save_entry(
	array(
		'title'     => 'Κόστος PlanDose',
		'phrasings' => array( 'Πόσο κοστίζει το PlanDose;' ),
		'answer'    => 'Δωρεάν. Δείτε και το QR ReBuilder, το datamatrix εργαλείο μας, και το QR ReBuilder Pro.',
		'active'    => 1,
	)
);
pnt_defer(
	function () use ( $eid ) {
		PNChat_Store::delete_entry( $eid );
	}
);
pnt_same( 'PlanDose', PNChat_Topics::of_entry( $eid ), 'topic: from the title and questions, not from products the answer mentions' );

// ---- assets in <head> --------------------------------------------------------------
pnt_settings( array( 'placement' => 'floating', 'enabled' => 1, 'visibility' => 'all' ) );
wp_dequeue_script( 'pn-chat' );
PNChat_Frontend::early();
pnt_check( wp_script_is( 'pn-chat', 'enqueued' ) && wp_style_is( 'pn-chat', 'enqueued' ), 'assets: enqueued on wp_enqueue_scripts for the floating chat' );

// ---- admin: a save the database refused is reported --------------------------------
$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );
$redirect = null;
add_filter(
	'wp_redirect',
	function ( $loc ) use ( &$redirect ) {
		$redirect = $loc;
		throw new Exception( 'redirect' );
	}
);
function pnt_admin_post( $action, array $post ) {
	$_POST                = $post;
	$_REQUEST             = $post;
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'pnchat_' . $action );
	try {
		call_user_func( array( 'PNChat_Admin', 'handle_' . $action ) );
	} catch ( Exception $e ) {
		unset( $e );
	}
}
$entry_post = array(
	'kind'      => 'answer',
	'id'        => '0',
	'title'     => 'Δοκιμή αποτυχίας',
	'phrasings' => 'Ερώτηση αποτυχίας;',
	'keywords'  => '',
	'answer'    => 'Απάντηση',
	'active'    => '1',
);
$breaker  = function ( $sql ) {
	return 0 === stripos( ltrim( $sql ), 'INSERT' ) && false !== strpos( $sql, 'pnchat_entries' ) ? 'INSERT INTO no_such_table_pnchat VALUES (1)' : $sql;
};
add_filter( 'query', $breaker );
$suppress = $wpdb->suppress_errors( true );
pnt_admin_post( 'save_entry', $entry_post );
$wpdb->suppress_errors( $suppress );
remove_filter( 'query', $breaker );
pnt_check( false !== strpos( (string) $redirect, 'pnchat_msg=save_failed' ), 'admin: a refused save says «ΔΕΝ αποθηκεύτηκε» (was «αποθηκεύτηκε»)' );
$kept = get_transient( 'pnchat_form_' . $admin->ID );
pnt_same( 'Ερώτηση αποτυχίας;', $kept['phrasings'][0] ?? null, 'admin: what was typed is kept for the form' );
delete_transient( 'pnchat_form_' . $admin->ID );

pnt_admin_post( 'save_entry', $entry_post );
pnt_check( false !== strpos( (string) $redirect, 'pnchat_msg=saved' ), 'admin: a good save says saved' );
foreach ( PNChat_Store::entries( 'answer', false, 'Δοκιμή αποτυχίας' ) as $e ) {
	PNChat_Store::delete_entry( $e['id'] );
}

pnt_done();
