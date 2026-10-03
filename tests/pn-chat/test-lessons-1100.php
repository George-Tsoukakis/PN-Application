<?php
/**
 * 1.10.0: learning from conversations. «Μήπως εννοείτε…;» buttons, a tapped
 * button answers and teaches; a wording confirmed in 3 conversations is added
 * on its own unless it is medical, close to a refusal or changes a test; the
 * administrator adds, rejects or undoes; rephrasings wait for approval; the
 * test set; what visitors ask and the chat does not know; unknown words as
 * synonyms; answers marked 👎.
 */
require __DIR__ . '/lib.php';
global $wpdb;
$ct = PNChat_Counter::table();
$lt = PNChat_Lessons::table();
pnt_defer( 'pnt_clear_questions' );
pnt_defer(
	function () use ( $wpdb, $ct, $lt ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', $ct, 'rl:%' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $lt ) );
		delete_option( PNChat_Lessons::TESTS );
		delete_option( 'pnchat_ignored_words' );
		delete_transient( 'pnchat_tests_result' );
	}
);
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $lt ) );
delete_option( PNChat_Lessons::TESTS );
wp_set_current_user( 0 );
pnt_keep_entries();
pnt_settings(
	array(
		'topics'          => 'QR ReBuilder, rebuilder',
		'synonyms'        => 'κοστίζει, τιμή, κόστος, δωρεάν',
		'didyoumean'      => 1,
		'learn_auto'      => 3,
		'fallback_button' => 1,
		'strictness'      => 'normal',
		'related_max'     => 3,
	)
);
PNChat_Topics::reset();
$rows = array();
foreach ( array(
	array( 'answer', 'Τι είναι το QR ReBuilder', array( 'Τι είναι το QR ReBuilder;' ) ),
	array( 'answer', 'QR ReBuilder: κόστος', array( 'Κοστίζει το QR ReBuilder;' ) ),
	array( 'answer', 'QR ReBuilder: scanner', array( 'Τι scanner χρειάζομαι για το QR ReBuilder;' ) ),
	array( 'answer', 'Αποστολή παραγγελιών', array( 'Πού στέλνετε τις παραγγελίες;', 'Κάνετε αποστολές;' ) ),
	array( 'block', 'Ιατρικές συμβουλές', array( 'Τι φάρμακο να πάρω για τον πόνο;', 'Πόσα χάπια να πάρω;' ) ),
) as $e ) {
	$rows[] = array(
		'kind'      => $e[0],
		'title'     => $e[1],
		'phrasings' => $e[2],
		'keywords'  => array(),
		'answer'    => '<p>' . $e[1] . '</p>',
		'active'    => 1,
	);
}
PNChat_Store::replace_entries( $rows );
PNChat_Brain::matcher( true );
$id = array();
foreach ( PNChat_Store::entries() as $e ) {
	$id[ $e['title'] ] = (int) $e['id'];
}
$ask = function ( array $body ) use ( $wpdb, $ct ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', $ct, 'rl:%' ) );
	list( , $d ) = pnt_rest( '/ask', $body );
	return $d;
};
$tap = function ( array $u, $title, $conv ) use ( $ask, $id ) {
	foreach ( (array) $u['didyoumean'] as $d ) {
		if ( $d['id'] === $id[ $title ] ) {
			return $ask(
				array(
					'question'   => $d['text'],
					'conv'       => $conv,
					'via'        => 'didyoumean',
					'pick'       => $d['id'],
					'from'       => $u['id'],
					'from_token' => $u['token'],
				)
			);
		}
	}
	return null;
};
$lesson = function ( $phrase, $title ) use ( $wpdb, $lt, $id ) {
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE phrase_key = %s AND entry_id = %d', $lt, PNChat_Lessons::key( $phrase ), $id[ $title ] ), ARRAY_A );
};

// ---- «Μήπως εννοείτε…;» -------------------------------------------------------------
$s = PNChat_Lessons::suggestions( 'Πρέπει να πληρώσω;', 'QR ReBuilder' );
pnt_check( count( $s ) >= 2 && count( $s ) <= 3, 'suggestions: two or three close entries (' . count( $s ) . ')' );
pnt_same( array(), PNChat_Lessons::suggestions( 'Τι καιρό κάνει αύριο;' ), 'suggestions: none when nothing is close' );
pnt_same( array(), PNChat_Lessons::suggestions( 'Τι χάπια να πάρω για τον πονοκέφαλο;' ), 'suggestions: none when a refusal is close' );
$s = PNChat_Lessons::suggestions( 'Τι μηχάνημα scanner θέλει;', 'QR ReBuilder' );
pnt_same( $id['QR ReBuilder: scanner'], $s[0]['id'] ?? 0, 'suggestions: in a conversation the question\'s own words come first (not the topic\'s general entry)' );

$u = $ask( array( 'question' => 'Πρέπει να πληρώσω;', 'context' => 'QR ReBuilder', 'conv' => 'pntlesson00000000001' ) );
pnt_same( array( 'unanswered', true, false ), array( $u['status'], $u['email_button'], $u['show_suggestions'] ), 'response: still «don\'t know» with the human button; the FAQ stays closed under the buttons' );
pnt_same( PNChat_Settings::value( 'didyoumean_text' ), $u['message'], 'response: «Μήπως εννοείτε» text instead of the plain «don\'t know»' );
pnt_check( in_array( $id['QR ReBuilder: κόστος'], array_column( $u['didyoumean'], 'id' ), true ), 'response: the cost entry is offered' );
pnt_same( implode( ',', array_column( $u['didyoumean'], 'id' ) ), PNChat_Store::question( (int) $u['id'] )['offered'], 'response: the offered entries are kept with the question' );

// ---- a tap answers and teaches ----------------------------------------------------------------
$c = $tap( $u, 'QR ReBuilder: κόστος', 'pntlesson00000000001' );
pnt_same( array( 'answered', 'QR ReBuilder: κόστος' ), array( $c['status'], $c['items'][0]['title'] ), 'tap: answers the chosen entry' );
$l = $lesson( 'Πρέπει να πληρώσω; (QR ReBuilder)', 'QR ReBuilder: κόστος' );
pnt_same( array( 'pending', 1, 1 ), array( $l['status'] ?? '', (int) ( $l['clicks'] ?? 0 ), (int) ( $l['seen'] ?? 0 ) ), 'tap: the visitor\'s wording (with its topic) is a lesson for that entry' );
pnt_same( $id['QR ReBuilder: κόστος'], (int) PNChat_Store::question( (int) $u['id'] )['hint_entry'], 'tap: the question shows «Μάλλον εννοούσε»' );
$c2 = $tap( $u, 'QR ReBuilder: κόστος', 'pntlesson00000000001' );
pnt_same( 1, (int) $lesson( 'Πρέπει να πληρώσω; (QR ReBuilder)', 'QR ReBuilder: κόστος' )['clicks'], 'tap: the same conversation counts once' );

$bad = $ask(
	array(
		'question'   => 'Κοστίζει το QR ReBuilder;',
		'conv'       => 'pntlesson00000000009',
		'via'        => 'didyoumean',
		'pick'       => $id['Αποστολή παραγγελιών'],
		'from'       => $u['id'],
		'from_token' => $u['token'],
	)
);
pnt_same( 'QR ReBuilder: κόστος', $bad['items'][0]['title'] ?? '', 'tap: an entry that was not offered is not taken (the question is answered normally)' );
$bad = $ask(
	array(
		'question'   => 'Κοστίζει το QR ReBuilder;',
		'conv'       => 'pntlesson00000000009',
		'via'        => 'didyoumean',
		'pick'       => $id['QR ReBuilder: κόστος'],
		'from'       => $u['id'],
		'from_token' => 'wrong',
	)
);
pnt_same( 1, (int) $lesson( 'Πρέπει να πληρώσω; (QR ReBuilder)', 'QR ReBuilder: κόστος' )['clicks'], 'tap: a wrong token teaches nothing' );
$u9 = $ask( array( 'question' => 'Πρέπει να πληρώσω;', 'context' => 'QR ReBuilder', 'conv' => 'pntlesson00000000010' ) );
$tap( $u9, 'QR ReBuilder: κόστος', 'pntlesson00000000011' );
pnt_same( 1, (int) $lesson( 'Πρέπει να πληρώσω; (QR ReBuilder)', 'QR ReBuilder: κόστος' )['clicks'], 'tap: from another conversation it answers but teaches nothing' );

// ---- three visitors: added on its own ------------------------------------------------------------
foreach ( array( 'pntlesson00000000002', 'pntlesson00000000003' ) as $conv ) {
	$u = $ask( array( 'question' => 'Πρέπει να πληρώσω;', 'context' => 'QR ReBuilder', 'conv' => $conv ) );
	$tap( $u, 'QR ReBuilder: κόστος', $conv );
}
$l = $lesson( 'Πρέπει να πληρώσω; (QR ReBuilder)', 'QR ReBuilder: κόστος' );
pnt_same( array( 'auto', 3 ), array( $l['status'], (int) $l['clicks'] ), 'auto: confirmed by three visitors, added on its own' );
pnt_check( in_array( 'Πρέπει να πληρώσω; (QR ReBuilder)', PNChat_Store::entry( $id['QR ReBuilder: κόστος'] )['phrasings'], true ), 'auto: the wording is now one of the entry\'s' );
$d = $ask( array( 'question' => 'Πρέπει να πληρώσω;', 'context' => 'QR ReBuilder', 'conv' => 'pntlesson00000000004' ) );
pnt_same( 'QR ReBuilder: κόστος', $d['items'][0]['title'] ?? $d['status'], 'auto: the next visitor gets the answer at once' );
pnt_same( 'trained', PNChat_Store::question( (int) $u['id'] )['status'], 'auto: the questions it came from are closed' );
pnt_check( in_array( 'Πρέπει να πληρώσω; (QR ReBuilder)', array_column( PNChat_Lessons::tests(), 'q' ), true ), 'auto: it joins the test set' );

// ---- safety ------------------------------------------------------------------------------------
$conv = 'pntlesson00000000020';
pnt_check( '' !== PNChat_Lessons::why_not( 'Πόσα depon να πάρω την ημέρα;', $id['QR ReBuilder: κόστος'] ), 'safety: a medical wording is never added on its own' );
pnt_check( '' !== PNChat_Lessons::why_not( 'τι να πάρω για τον πόνο στο κεφάλι', $id['Αποστολή παραγγελιών'] ), 'safety: a wording close to a refusal is never added on its own' );
PNChat_Lessons::add_test( 'Πού στέλνετε τις παραγγελίες;', '', $id['Αποστολή παραγγελιών'], 'test' );
pnt_check( false !== strpos( PNChat_Lessons::why_not( 'Πού στέλνετε τις παραγγελίες', $id['QR ReBuilder: scanner'] ), 'άλλη γνώση' ) || false !== strpos( PNChat_Lessons::why_not( 'Πού στέλνετε τις παραγγελίες', $id['QR ReBuilder: scanner'] ), 'δοκιμών' ), 'safety: a wording that would take a question from another entry is refused' );
pnt_settings( array( 'learn_auto' => 0 ) );
foreach ( array( 'pntlesson00000000030', 'pntlesson00000000031', 'pntlesson00000000032' ) as $cv ) {
	$u = $ask( array( 'question' => 'Θέλει ειδικό εξοπλισμό;', 'context' => 'QR ReBuilder', 'conv' => $cv ) );
	$tap( $u, 'QR ReBuilder: scanner', $cv );
}
$l = $lesson( 'Θέλει ειδικό εξοπλισμό; (QR ReBuilder)', 'QR ReBuilder: scanner' );
pnt_same( array( 'pending', 3 ), array( $l['status'] ?? '', (int) ( $l['clicks'] ?? 0 ) ), 'setting 0: nothing is added on its own' );
pnt_settings( array( 'learn_auto' => 3 ) );

pnt_same( null, PNChat_Lessons::record( 'Στείλτε μου στο maria.k@gmail.com το κόστος', $id['QR ReBuilder: κόστος'], 'pntlesson00000000050', 'click' ), 'safety: a wording with an e-mail (or phone, AMKA) is never a lesson' );

// ---- the administrator decides -----------------------------------------------------------------
pnt_check( PNChat_Lessons::decide( (int) $l['id'], 'add' ), 'decide: add' );
pnt_check( in_array( 'Θέλει ειδικό εξοπλισμό; (QR ReBuilder)', PNChat_Store::entry( $id['QR ReBuilder: scanner'] )['phrasings'], true ), 'decide: the wording is in the entry' );
pnt_check( PNChat_Lessons::decide( (int) $l['id'], 'undo' ), 'decide: undo' );
pnt_check( ! in_array( 'Θέλει ειδικό εξοπλισμό; (QR ReBuilder)', PNChat_Store::entry( $id['QR ReBuilder: scanner'] )['phrasings'], true ) && ! in_array( 'Θέλει ειδικό εξοπλισμό; (QR ReBuilder)', array_column( PNChat_Lessons::tests(), 'q' ), true ), 'decide: undo takes it out of the entry and the test set' );
$u = $ask( array( 'question' => 'Θέλει ειδικό εξοπλισμό;', 'context' => 'QR ReBuilder', 'conv' => 'pntlesson00000000033' ) );
$tap( $u, 'QR ReBuilder: scanner', 'pntlesson00000000033' );
pnt_same( 'rejected', $lesson( 'Θέλει ειδικό εξοπλισμό; (QR ReBuilder)', 'QR ReBuilder: scanner' )['status'], 'decide: an undone wording is not learned again' );

// ---- rephrasing: waits for approval ----------------------------------------------------------------
$conv = 'pntlesson00000000040';
$u    = $ask( array( 'question' => 'Πώς μου τα φέρνετε σπίτι;', 'conv' => $conv ) );
$ask( array( 'question' => 'Κάνετε αποστολές;', 'conv' => $conv ) );
$l = $lesson( 'Πώς μου τα φέρνετε σπίτι;', 'Αποστολή παραγγελιών' );
pnt_same( array( 'pending', 0, 1 ), array( $l['status'] ?? '', (int) ( $l['clicks'] ?? -1 ), (int) ( $l['seen'] ?? 0 ) ), 'rephrase: a lesson without taps, which never goes in on its own' );

// ---- test set ---------------------------------------------------------------------------------------
$n = count( PNChat_Lessons::tests() );
PNChat_Lessons::add_test( 'Πού στέλνετε τις παραγγελίες;', '', $id['Αποστολή παραγγελιών'], 'test' );
pnt_same( $n, count( PNChat_Lessons::tests() ), 'tests: the same test only once' );
PNChat_Lessons::add_test( 'Τι κάνει το QR ReBuilder;', '', $id['QR ReBuilder: scanner'], 'test' );
$r = PNChat_Lessons::run_tests();
pnt_same( array( $n + 1, 1 ), array( $r['total'], count( $r['fail'] ) ), 'tests: run, with the failing one listed' );
pnt_same( 'Τι είναι το QR ReBuilder', reset( $r['fail'] )['got'], 'tests: a failure says what was answered instead' );
PNChat_Lessons::remove_test( (int) array_key_first( $r['fail'] ) );
pnt_same( 0, count( PNChat_Lessons::run_tests()['fail'] ), 'tests: removed' );

// ---- what the conversations show -----------------------------------------------------------------
pnt_clear_questions();
foreach ( array( 'Πώς κάνω επιστροφή χρημάτων;', 'Θέλω επιστροφή χρημάτων', 'επιστροφη χρηματων πως', 'Θέλω κάτι άλλο εντελώς', 'Δέχεστε χρεώνετε κάρτα;', 'Τι χρεώνετε;', 'Χρεώνετε κάτι;' ) as $q ) {
	$ask( array( 'question' => $q ) );
}
$g  = PNChat_Lessons::unanswered_groups();
$gw = array_column( $g, null, 'word' );
pnt_same( 3, $gw['επιστροφή']['count'] ?? 0, 'groups: «επιστροφή», asked 3 times, is a group' );
pnt_check( ! in_array( 'Θέλω', array_column( $g, 'word' ), true ), 'groups: «θέλω» says nothing and makes no group' );
$w = array_column( PNChat_Lessons::word_suggestions(), null, 'word' );
pnt_check( isset( $w['χρεώνετε'] ) || isset( $w['Χρεώνετε'] ), 'words: «χρεώνετε» (3 questions, in no entry) is listed' );
PNChat_Lessons::add_synonym( 'χρεώνετε', 'κόστος' );
pnt_check( false !== strpos( (string) PNChat_Settings::value( 'synonyms' ), 'δωρεάν, χρεώνετε' ), 'words: added to the group of «κόστος»' );
$d = $ask( array( 'question' => 'Τι χρεώνετε;', 'context' => 'QR ReBuilder' ) );
pnt_same( 'QR ReBuilder: κόστος', $d['items'][0]['title'] ?? $d['status'], 'words: now the question is answered' );

// ---- 👎 ---------------------------------------------------------------------------------------------
foreach ( array( 'Κοστίζει το QR ReBuilder;', 'Πόσο κοστίζει το QR ReBuilder' ) as $q ) {
	$a = $ask( array( 'question' => $q ) );
	pnt_rest(
		'/feedback',
		array(
			'id'      => $a['id'],
			'token'   => $a['token'],
			'helpful' => false,
		)
	);
}
$f = PNChat_Lessons::needs_fixing();
pnt_same( array( 'QR ReBuilder: κόστος', 2 ), array( $f[0]['entry']['title'] ?? '', $f[0]['count'] ?? 0 ), 'needs fixing: an answer marked 👎 twice is listed' );

// ---- admin: a group becomes an entry ------------------------------------------------------------------
$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );
add_filter(
	'wp_redirect',
	function () {
		throw new Exception( 'redirect' );
	}
);
$g        = array( array_column( PNChat_Lessons::unanswered_groups(), null, 'word' )['επιστροφή'] );
$_GET     = array(
	'ids' => implode( ',', $g[0]['ids'] ),
	'do'  => 'entry',
);
$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'pnchat_lesson_group' ) );
try {
	PNChat_Admin::handle_lesson_group();
} catch ( Exception $e ) {
	unset( $e );
}
$form = get_transient( 'pnchat_form_' . $admin->ID );
pnt_check( is_array( $form ) && in_array( 'Πώς κάνω επιστροφή χρημάτων;', $form['phrasings'], true ), 'admin: «Νέα γνώση με αυτές» opens the form with the questions' );
$_POST    = array(
	'kind'      => 'answer',
	'id'        => '0',
	'title'     => 'Επιστροφές',
	'phrasings' => implode( "\n", $form['phrasings'] ),
	'keywords'  => '',
	'answer'    => 'Επιστροφές μέσα σε 14 ημέρες.',
	'active'    => '1',
);
$_REQUEST = array_merge( $_POST, array( '_wpnonce' => wp_create_nonce( 'pnchat_save_entry' ) ) );
delete_transient( 'pnchat_form_' . $admin->ID );
try {
	PNChat_Admin::handle_save_entry();
} catch ( Exception $e ) {
	unset( $e );
}
pnt_same( array( 'trained' ), array_values( array_unique( array_map( fn( $i ) => PNChat_Store::question( $i )['status'], $g[0]['ids'] ) ) ), 'admin: once saved, the group\'s questions are marked trained' );
$_POST    = array();
$_GET     = array();
$_REQUEST = array();
wp_set_current_user( 0 );

pnt_done();
