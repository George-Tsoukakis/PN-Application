<?php
/**
 * 1.8.0: privacy export paging, brain «replace» all-or-nothing, questions
 * backup with AI drafts.
 */
require __DIR__ . '/lib.php';
global $wpdb;
$t = PNChat_Store::questions_table();
pnt_defer( 'pnt_clear_questions' );

// ---- privacy export / erase: 700 questions of one e-mail -------------------
pnt_clear_questions();
for ( $i = 0; $i < 700; $i++ ) {
	PNChat_Store::log_question( array( 'question' => "q$i", 'status' => 'unanswered' ) );
}
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET email = %s', $t, 'v@example.test' ) );
PNChat_Store::log_question( array( 'question' => 'someone else', 'status' => 'unanswered' ) );

$seen = array();
$page = 1;
do {
	$r = PNChat_Privacy::export( 'v@example.test', $page );
	foreach ( $r['data'] as $item ) {
		$seen[ $item['item_id'] ] = true;
	}
	++$page;
} while ( ! $r['done'] && $page < 50 );
pnt_same( 700, count( $seen ), 'exporter: all 700 questions, each once, over pages' );
pnt_same( 8, $page - 1, 'exporter: 8 pages of 100 (the last one short)' );

$calls = 0;
do {
	$r = PNChat_Privacy::erase( 'v@example.test', ++$calls );
} while ( ! $r['done'] && $calls < 50 );
pnt_same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email = %s', $t, 'v@example.test' ) ), 'eraser: every question of the e-mail deleted' );
pnt_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) ), 'eraser: the other visitor\'s question stays' );

// ---- brain «replace» -------------------------------------------------------
pnt_keep_entries();
$before = count( PNChat_Store::entries() );
pnt_check( $before > 0, "the site has entries ($before)" );
$snaps = count( PNChat_Brain::snapshots() );

$r = PNChat_Brain::import(
	array(
		'format'         => 'pn-chat-brain',
		'format_version' => 1,
		'entries'        => array( array( 'title' => 'no question, no answer' ), 'junk' ),
	),
	'replace'
);
pnt_check( is_wp_error( $r ), 'replace with no valid entry: error' );
pnt_same( $before, count( PNChat_Store::entries() ), 'replace with no valid entry: entries untouched' );
pnt_same( $snaps, count( PNChat_Brain::snapshots() ), 'replace with no valid entry: no needless snapshot' );

// One of three inserts fails in the database: nothing may change.
$file = array(
	'format'         => 'pn-chat-brain',
	'format_version' => 1,
	'entries'        => array(
		array( 'phrasings' => array( 'Α ερώτηση' ), 'answer' => 'Α' ),
		array( 'phrasings' => array( 'Β ερώτηση' ), 'answer' => 'FAILME' ),
		array( 'phrasings' => array( 'Γ ερώτηση' ), 'answer' => 'Γ' ),
	),
);
$titles_before = wp_list_pluck( PNChat_Store::entries(), 'title' );
$breaker       = function ( $q ) {
	return ( 0 === stripos( ltrim( $q ), 'INSERT' ) && false !== strpos( $q, 'FAILME' ) ) ? 'INSERT INTO no_such_table_pnchat VALUES (1)' : $q;
};
add_filter( 'query', $breaker );
$suppress = $wpdb->suppress_errors( true );
$r        = PNChat_Brain::import( $file, 'replace' );
$wpdb->suppress_errors( $suppress );
remove_filter( 'query', $breaker );
pnt_check( is_wp_error( $r ), 'replace with a failing insert: error' );
pnt_same( $titles_before, wp_list_pluck( PNChat_Store::entries(), 'title' ), 'replace with a failing insert: the previous entries are all back' );

// The same on a table without transactions (MyISAM): restored by hand.
$e_table = PNChat_Store::entries_table();
$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $e_table ) );
pnt_defer(
	function () use ( $wpdb, $e_table ) {
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $e_table ) );
	}
);
add_filter( 'query', $breaker );
$suppress = $wpdb->suppress_errors( true );
$r        = PNChat_Brain::import( $file, 'replace' );
$wpdb->suppress_errors( $suppress );
remove_filter( 'query', $breaker );
pnt_check( is_wp_error( $r ), 'MyISAM, failing insert: error' );
pnt_same( $titles_before, wp_list_pluck( PNChat_Store::entries(), 'title' ), 'MyISAM, failing insert: the previous entries are all back' );
$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $e_table ) );

$r = PNChat_Brain::import( $file, 'replace' );
pnt_check( is_array( $r ) && 3 === $r['added'], 'replace with a good file: 3 entries' );
pnt_same( 3, count( PNChat_Store::entries() ), 'replace with a good file: exactly the file\'s entries' );

// Merge with one bad entry: the good ones are added, the bad one skipped.
$r = PNChat_Brain::import(
	array(
		'format'         => 'pn-chat-brain',
		'format_version' => 1,
		'entries'        => array( array( 'phrasings' => array( 'Δ ερώτηση' ), 'answer' => 'Δ' ), array( 'title' => 'bad' ) ),
	),
	'merge',
	false
);
pnt_check( is_array( $r ) && 1 === $r['added'] && 1 === $r['skipped'], 'merge: 1 added, 1 skipped' );

// A snapshot of an empty brain can be restored (to empty); a file cannot.
$empty = array( 'format' => 'pn-chat-brain', 'format_version' => 1, 'entries' => array() );
pnt_check( is_wp_error( PNChat_Brain::import( $empty, 'replace' ) ), 'empty file: refused' );
pnt_check( 4 === count( PNChat_Store::entries() ), 'empty file: entries untouched' );
$r = PNChat_Brain::import( $empty, 'replace', true, false, true );
pnt_check( is_array( $r ) && 0 === count( PNChat_Store::entries() ), 'empty snapshot: restored to an empty brain' );

// ---- questions backup keeps the AI draft and the page ----------------------
pnt_clear_questions();
$draft = array(
	'found'   => true,
	'entry'   => array(
		'title'     => 'Τίτλος AI',
		'phrasings' => array( 'ερώτηση ai' ),
		'keywords'  => array(),
		'answer'    => '<p>Απάντηση <script>x</script>AI</p>',
	),
	'sources' => array(
		array(
			'title' => 'Σελίδα',
			'url'   => home_url( '/page/' ),
		),
	),
	'note'    => 'σημείωση',
);
PNChat_Store::log_question(
	array(
		'question' => 'ερώτηση ai',
		'status'   => 'ai',
		'draft'    => $draft,
		'page_url' => home_url( '/page/' ),
	)
);
$export = json_decode( wp_json_encode( PNChat_Brain::export( true ) ), true );
pnt_same( 'Τίτλος AI', $export['questions'][0]['draft']['entry']['title'] ?? null, 'download: the AI draft travels with its question' );
pnt_same( home_url( '/page/' ), $export['questions'][0]['page_url'] ?? null, 'download: the page of the question too' );

pnt_clear_questions();
$r = PNChat_Brain::import( $export, 'merge', false, true );
$q = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i', $t ), ARRAY_A );
$d = PNChat_Store::draft_of( $q );
pnt_same( 'ai', $q['status'] ?? null, 'upload: status «ai» restored' );
pnt_check( $d && 'Τίτλος AI' === $d['entry']['title'], 'upload: the draft is back, so «Έλεγχος και έγκριση» works' );
pnt_check( $d && false === strpos( $d['entry']['answer'], '<script' ), 'upload: the draft answer is cleaned like an entry' );
pnt_same( home_url( '/page/' ), $q['page_url'] ?? null, 'upload: page restored' );

pnt_done();
