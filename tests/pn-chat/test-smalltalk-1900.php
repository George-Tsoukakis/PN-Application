<?php
/**
 * 1.9.0: small talk. «ωραίο tool», «οκ», «καληνύχτα» get a natural reply in
 * the topic of the conversation and are not logged; «δεν με βοήθησες» is
 * logged as «Δεν βοήθησε» with the e-mail form; a trained entry still wins;
 * a question that starts with praise stays a question.
 */
require __DIR__ . '/lib.php';
global $wpdb;
$ct = PNChat_Counter::table();
pnt_defer( 'pnt_clear_questions' );
pnt_defer(
	function () use ( $wpdb, $ct ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', $ct, 'rl:%' ) );
	}
);
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', $ct, 'rl:%' ) );
wp_set_current_user( 0 );
pnt_keep_entries();
pnt_settings( array( 'topics' => "QR ReBuilder, qr rebuilder, qrrebuilder" ) );
PNChat_Topics::reset();

// ---- detection -----------------------------------------------------------------
$cases = array(
	'ωραία tool είναι'               => 'praise',
	'Ωραίο!'                         => 'praise',
	'τέλειο, μπράβο'                 => 'praise',
	'χαίρομαι που το βρήκα, τέλειο'  => 'praise',
	'nice'                           => 'praise',
	'οκ'                             => 'ok',
	'Εντάξει, κατάλαβα'              => 'ok',
	'ευχαριστώ πολύ'                 => 'ok',
	'καληνύχτα'                      => 'bye',
	'δεν με βοήθησες'                => 'complaint',
	'ωραίο αλλά δεν δουλεύει'        => 'complaint',
	'ωραία, πώς το χρησιμοποιώ'      => '',
	'Ωραίο, μπορώ να το δοκιμάσω'    => '',
	'ωραίο είναι δωρεάν;'            => '',
	'τι κάνει το QR ReBuilder'       => '',
	'ωραία, τι κοστίζει'             => '',
	'ok pws to katevazw'             => '',
	'ωραία εφαρμογή αλλά θέλω να μάθω αν δουλεύει σε κινητό με android' => '',
);
foreach ( $cases as $text => $kind ) {
	pnt_same( $kind, PNChat_Smalltalk::detect( $text ), 'detect «' . $text . '»' );
}

// ---- a question that starts with a remark is searched without it ---------------
$strip = array(
	'Ωραία, τι κάνει;'            => 'τι κάνει;',
	'οκ ευχαριστώ, πόσο κοστίζει;' => 'πόσο κοστίζει;',
	'Τέλεια! Πώς το κατεβάζω;'    => 'Πώς το κατεβάζω;',
	'ωραία εφαρμογή'              => 'ωραία εφαρμογή',
	'όχι δωρεάν έκδοση'           => 'όχι δωρεάν έκδοση',
);
foreach ( $strip as $text => $want ) {
	pnt_same( $want, PNChat_Smalltalk::strip_lead( $text ), 'strip_lead «' . $text . '»' );
}

// ---- REST: answer, then praise in its topic ----------------------------------
$qr = PNChat_Store::save_entry(
	array(
		'kind'      => 'answer',
		'title'     => 'Τι είναι το QR ReBuilder',
		'phrasings' => array( 'Τι είναι το QR ReBuilder;', 'Τι κάνει το QR ReBuilder;' ),
		'keywords'  => array( 'QR ReBuilder' ),
		'answer'    => 'Το QR ReBuilder φτιάχνει ξανά QR codes συνταγών.',
		'active'    => 1,
	)
);
PNChat_Brain::matcher( true );
pnt_clear_questions();

list( $st, $a ) = pnt_rest( '/ask', array( 'question' => 'Τι είναι το QR ReBuilder;' ) );
pnt_same( 'answered', $a['status'] ?? null, 'ask: QR ReBuilder answered' );
pnt_same( 'QR ReBuilder', $a['topic'] ?? null, 'ask: topic QR ReBuilder' );
$before = count( PNChat_Store::all_questions() );

list( $st, $r ) = pnt_rest( '/ask', array( 'question' => 'ωραία tool είναι', 'context' => 'QR ReBuilder' ) );
pnt_same( 200, $st, 'praise: 200' );
pnt_same( 'smalltalk', $r['status'] ?? null, 'praise: small talk, not «δεν έχουμε πληροφορίες»' );
pnt_check( false !== strpos( (string) ( $r['intro'] ?? '' ), 'QR ReBuilder' ), 'praise: the reply names the topic («' . ( $r['intro'] ?? '' ) . '»)' );
pnt_same( true, $r['show_suggestions'] ?? null, 'praise: suggestions shown again' );
pnt_same( false, $r['ask_email'] ?? null, 'praise: no e-mail form' );
pnt_same( 'QR ReBuilder', $r['topic'] ?? null, 'praise: the topic is kept for the next question' );
pnt_same( $before, count( PNChat_Store::all_questions() ), 'praise: not logged as unanswered' );

list( , $r ) = pnt_rest( '/ask', array( 'question' => 'ωραίο' ) );
pnt_same( PNChat_Settings::get()['smalltalk_praise'], $r['intro'] ?? null, 'praise without a topic: the plain text' );

list( , $r ) = pnt_rest( '/ask', array( 'question' => 'καληνύχτα', 'context' => 'QR ReBuilder' ) );
pnt_same( array( 'smalltalk', false ), array( $r['status'] ?? null, $r['show_suggestions'] ?? null ), 'bye: small talk, no suggestions' );

// A follow-up question after praise still uses the topic.
PNChat_Store::save_entry(
	array(
		'kind'      => 'answer',
		'title'     => 'Κόστος QR ReBuilder',
		'phrasings' => array( 'Είναι δωρεάν το QR ReBuilder;', 'Πόσο κοστίζει το QR ReBuilder;' ),
		'keywords'  => array(),
		'answer'    => 'Είναι δωρεάν.',
		'active'    => 1,
	)
);
PNChat_Brain::matcher( true );
list( , $r ) = pnt_rest( '/ask', array( 'question' => 'Τέλεια! Είναι δωρεάν;', 'context' => 'QR ReBuilder' ) );
pnt_same( 'answered', $r['status'] ?? null, 'praise + question: answered in the topic' );

// ---- complaint ----------------------------------------------------------------
list( , $r ) = pnt_rest( '/ask', array( 'question' => 'δεν με βοήθησες', 'context' => 'QR ReBuilder' ) );
pnt_same( array( 'smalltalk', true ), array( $r['status'] ?? null, $r['ask_email'] ?? null ), 'complaint: reply and e-mail form' );
$q = PNChat_Store::question( (int) ( $r['id'] ?? 0 ) );
pnt_same( 'unhelpful', $q['status'] ?? null, 'complaint: logged as «Δεν βοήθησε»' );
list( $st ) = pnt_rest( '/email', array( 'id' => $r['id'], 'token' => $r['token'], 'email' => 'v@example.test' ) );
pnt_same( 200, $st, 'complaint: the e-mail is accepted' );

// ---- settings texts, trained entry wins ----------------------------------------
pnt_settings( array( 'smalltalk_ok' => 'Ok από τις ρυθμίσεις' ) );
list( , $r ) = pnt_rest( '/ask', array( 'question' => 'οκ' ) );
pnt_same( 'Ok από τις ρυθμίσεις', $r['intro'] ?? null, 'ok: the text from Ρυθμίσεις' );

PNChat_Store::save_entry(
	array(
		'kind'      => 'answer',
		'title'     => 'Ευχαριστώ',
		'phrasings' => array( 'Ευχαριστώ', 'Ευχαριστώ πολύ' ),
		'keywords'  => array(),
		'answer'    => 'Εμείς ευχαριστούμε!',
		'active'    => 1,
	)
);
PNChat_Brain::matcher( true );
list( , $r ) = pnt_rest( '/ask', array( 'question' => 'ευχαριστώ πολύ' ) );
pnt_same( 'answered', $r['status'] ?? null, 'trained «Ευχαριστώ» still answers' );

// ---- brain export carries the texts --------------------------------------------
$b = PNChat_Brain::export( false );
pnt_same( 'Ok από τις ρυθμίσεις', $b['settings']['smalltalk_ok'] ?? null, 'brain export: small-talk texts included' );

pnt_done();
