<?php
/**
 * 1.13.0: the conversation's notebook and «Αναφέρεστε…;». A question that
 * names no topic and matches another topic is answered with a button back
 * (clear match) or asked (unsure); an earlier topic of the conversation that
 * answers it is offered; a product name long after a list is asked about
 * («Σε ποια σελίδα αναφέρεστε;»); the visitor's choice answers and teaches;
 * 👍 teaches; «Τι έμαθε αυτή την εβδομάδα».
 */
require __DIR__ . '/lib.php';
global $wpdb;
$lt = PNChat_Lessons::table();
pnt_defer( 'pnt_clear_questions' );
pnt_defer(
	function () use ( $wpdb, $lt ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $lt ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', PNChat_Counter::table(), 'rl:%' ) );
	}
);
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $lt ) );
wp_set_current_user( 0 );
pnt_keep_entries();
pnt_settings(
	array(
		'topics'      => "QR ReBuilder, rebuilder\nPlanDose, πλάνο δοσολογίας",
		'synonyms'    => 'κοστίζει, τιμή, κόστος, δωρεάν',
		'didyoumean'  => 1,
		'learn_auto'  => 3,
		'site_search' => 1,
		'site_types'  => 'page',
		'strictness'  => 'normal',
		'feedback'    => 1,
	)
);
PNChat_Topics::reset();
$rows = array();
foreach ( array(
	array( 'Τι είναι το QR ReBuilder', array( 'Τι είναι το QR ReBuilder;' ) ),
	array( 'QR ReBuilder: κόστος', array( 'Πόσο κοστίζει το QR ReBuilder;' ) ),
	array( 'QR ReBuilder: σαρωτής', array( 'Τι σαρωτή χρειάζομαι για το QR ReBuilder;' ) ),
	array( 'Τι είναι το PlanDose', array( 'Τι είναι το PlanDose;' ) ),
	array( 'PlanDose: εκτύπωση ετικετών', array( 'Πώς εκτυπώνω ετικέτες στο PlanDose;' ) ),
	array( 'PlanDose: κόστος', array( 'Πόσο κοστίζει το PlanDose;' ) ),
	array( 'PlanDose: ώρες λήψης', array( 'Πώς αλλάζω τις ώρες λήψης στο PlanDose;' ) ),
) as $e ) {
	$rows[] = array(
		'kind'      => 'answer',
		'title'     => $e[0],
		'phrasings' => $e[1],
		'keywords'  => array(),
		'answer'    => '<p>' . $e[0] . '.</p>',
		'active'    => 1,
	);
}
PNChat_Store::replace_entries( $rows );
PNChat_Brain::matcher( true );
$id = array();
foreach ( PNChat_Store::entries() as $e ) {
	$id[ $e['title'] ] = (int) $e['id'];
}
$conv = 'memconvaaaaaaaaaaaaaaaaa';
$ask  = function ( array $body ) use ( &$conv ) {
	$body = array_merge( array( 'conv' => $conv ), $body );
	list( $code, $d ) = pnt_rest( '/ask', $body, '203.0.113.' . wp_rand( 2, 250 ) );
	return $d;
};
$opts = function ( $d ) {
	return array_column( (array) ( $d['clarify']['options'] ?? array() ), 'context' );
};
$lesson = function ( $phrase, $entry ) use ( $wpdb, $lt ) {
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE phrase_key = %s AND entry_id = %d', $lt, PNChat_Lessons::key( $phrase ), $entry ), ARRAY_A );
};

// ---- a question of another topic, the conversation's topic named nowhere ----
$d = $ask(
	array(
		'question' => 'Πώς εκτυπώνω ετικέτες;',
		'context'  => 'QR ReBuilder',
	)
);
pnt_same( array( 'unanswered', 'ask' ), array( $d['status'], $d['clarify']['mode'] ?? '' ), 'topic change, unsure: nothing assumed, the chat asks' );
pnt_same( array( 'PlanDose', 'QR ReBuilder' ), $opts( $d ), 'topic change: the likely topic first, then the conversation\'s' );
pnt_same( 'Αναφέρεστε στο «PlanDose» ή στο «QR ReBuilder»;', $d['clarify']['text'] ?? '', 'topic change: «Αναφέρεστε στο «Α» ή στο «Β»;»' );
pnt_same( 'Πώς εκτυπώνω ετικέτες;', $d['clarify']['question'] ?? '', 'topic change: the question to send again' );
pnt_same( array(), $d['didyoumean'], 'topic change: no «Μήπως εννοείτε» next to the question' );

$d = $ask(
	array(
		'question' => 'Πώς εκτυπώνω ετικέτες;',
		'context'  => 'PlanDose',
		'via'      => 'clarify',
	)
);
pnt_same( array( 'answered', $id['PlanDose: εκτύπωση ετικετών'], 'PlanDose' ), array( $d['status'], $d['entry'], $d['topic'] ), '«Για το «PlanDose»»: the answer in that topic, which becomes the conversation\'s' );
$l = $lesson( 'Πώς εκτυπώνω ετικέτες (PlanDose)', $id['PlanDose: εκτύπωση ετικετών'] );
pnt_check( $l && 1 === (int) $l['clicks'], 'the choice is a lesson (wording with the topic), counted like a tapped button' );
pnt_check( null === $d['clarify'], 'after the choice: no new question' );
$d = $ask( array( 'question' => 'Πώς εκτυπώνω ετικέτες στο PlanDose;' ) );
pnt_check( null === $lesson( 'Πώς εκτυπώνω ετικέτες; (QR ReBuilder)', $id['PlanDose: εκτύπωση ετικετών'] ), 'after the choice: a later answer does not take the settled question for a rephrasing' );

$d = $ask(
	array(
		'question' => 'Πώς εκτυπώνω ετικέτες;',
		'context'  => 'QR ReBuilder',
		'via'      => 'clarify',
	)
);
pnt_same( array( 'unanswered', null ), array( $d['status'], $d['clarify'] ), '«Για το «QR ReBuilder»»: only that topic, nothing found, no question again' );

$d = $ask(
	array(
		'question' => 'Πόσο κοστίζει;',
		'context'  => 'QR ReBuilder',
		'via'      => 'clarify',
	)
);
pnt_same( $id['QR ReBuilder: κόστος'], $d['entry'], 'a chosen topic: a little of the own words is enough («Πόσο κοστίζει;» → its cost)' );

// ---- the notebook: an earlier topic -------------------------------------------
$d = $ask(
	array(
		'question' => 'Και ο σαρωτής;',
		'context'  => 'PlanDose',
		'memo'     => array( 'topics' => array( 'QR ReBuilder', 'PlanDose' ) ),
	)
);
pnt_same( array( 'QR ReBuilder' ), $opts( $d ), 'notebook: an earlier topic that answers is offered' );
pnt_same( array( 'Αναφέρεστε στο «QR ReBuilder»;', 'Ναι, για το «QR ReBuilder»' ), array( $d['clarify']['text'] ?? '', $d['clarify']['options'][0]['label'] ?? '' ), 'notebook: «Αναφέρεστε στο «QR ReBuilder»;» [Ναι, για…]' );
$d = $ask(
	array(
		'question' => 'Και ο σαρωτής;',
		'context'  => 'PlanDose',
	)
);
pnt_check( ! in_array( 'QR ReBuilder', $opts( $d ), true ), 'notebook: a topic the conversation never had is not offered' );
$memo = pnt_call(
	'PNChat_Rest',
	'memo',
	( function () {
		$r = new WP_REST_Request( 'POST', '/x' );
		$r->set_param(
			'memo',
			array(
				'topics' => array( 'PlanDose', '<b>Χάκερ</b>', array( 'x' ), 'PlanDose' ),
				'tables' => array( 999999, 'abc' ),
			)
		);
		return $r;
	} )()
);
pnt_same( array( 'topics' => array( 'PlanDose' ), 'tables' => array() ), $memo, 'notebook: only known topics and public pages are kept' );

$sw = PNChat_Rest::clarify_options( 'switch', 'Πώς;', array( 'QR ReBuilder' ), 'PlanDose' );
pnt_same( array( 'Πήγαμε στο θέμα «PlanDose». Αν ρωτάτε για κάτι άλλο:', 'Για το «QR ReBuilder»' ), array( $sw['text'], $sw['options'][0]['label'] ), 'clear change of topic: answered, with a button back' );

// ---- a list long ago -------------------------------------------------------------
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
$table = '<table><tr><th>Όνομα</th><th>ATC</th></tr>';
for ( $i = 1; $i <= 12; $i++ ) {
	$table .= '<tr><td>Σκεύασμαμνμ' . $i . '</td><td>M0' . $i . '</td></tr>';
}
$list = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Λίστα μνμ απαγόρευσης',
		'post_content' => '<p>Η λίστα.</p>' . $table . '</table>',
	)
);
pnt_defer(
	function () use ( $list ) {
		wp_delete_post( $list, true );
	}
);
PNChat_Site_Search::index_post( $list );
$d = $ask(
	array(
		'question' => 'το fortimel ειναι;',
		'memo'     => array( 'tables' => array( $list ) ),
	)
);
pnt_same( array( 'Αναφέρεστε στη σελίδα «Λίστα μνμ απαγόρευσης»;', $list ), array( $d['clarify']['text'] ?? '', $d['clarify']['options'][0]['sctx'] ?? 0 ), 'a product long after a list: «Αναφέρεστε στη σελίδα…;»' );
$d    = $ask(
	array(
		'question' => 'το fortimel ειναι;',
		'sctx'     => $list,
		'via'      => 'clarify',
	)
);
$html = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false !== strpos( $html, 'Όχι — το «Fortimel» δεν υπάρχει στη σελίδα' ), '«Ναι, στη σελίδα…»: looked up in that list' );
$d = $ask(
	array(
		'question' => 'το fortimel ειναι;',
		'sctx'     => $list,
		'memo'     => array( 'tables' => array( $list ) ),
	)
);
pnt_check( null === $d['clarify'], 'the list being discussed now: answered, not asked' );
$d = $ask( array( 'question' => 'το fortimel ειναι;' ) );
pnt_check( null === $d['clarify'], 'no list in the conversation: nothing to ask about' );

// ---- 👍 teaches ----------------------------------------------------------------------
$thumb = function ( $q, $ctx, $c ) use ( &$conv, $ask ) {
	$conv = $c;
	$d    = $ask(
		array(
			'question' => $q,
			'context'  => $ctx,
		)
	);
	pnt_rest(
		'/feedback',
		array(
			'id'      => $d['id'],
			'token'   => $d['token'],
			'helpful' => true,
		)
	);
	return $d;
};
$d = $thumb( 'Πόσο κοστίζει;', 'PlanDose', 'thumbconvaaaaaaaaaaaaaaa' );
pnt_same( $id['PlanDose: κόστος'], $d['entry'], '👍: the follow-up was answered in its topic' );
$l = $lesson( 'Πόσο κοστίζει (PlanDose)', $id['PlanDose: κόστος'] );
pnt_check( $l && 1 === (int) $l['clicks'] && 'pending' === $l['status'], '👍: the wording (with the topic) is a lesson, counted as a confirmation' );
$thumb( 'Πόσο κοστίζει;', 'PlanDose', 'thumbconvbbbbbbbbbbbbbbb' );
$thumb( 'Πόσο κοστίζει;', 'PlanDose', 'thumbconvccccccccccccccc' );
$l = $lesson( 'Πόσο κοστίζει (PlanDose)', $id['PlanDose: κόστος'] );
pnt_same( 'auto', $l['status'] ?? '', '👍 by 3 different visitors: learned on its own (same checks as the buttons)' );
$thumb( 'Τι είναι το PlanDose;', '', 'thumbconvddddddddddddddd' );
pnt_check( null === $lesson( 'Τι είναι το PlanDose;', $id['Τι είναι το PlanDose'] ), '👍 on a wording the entry already has: nothing to learn' );

// ---- «Τι έμαθε αυτή την εβδομάδα» ---------------------------------------------------
$w = PNChat_Lessons::week_summary( 7 );
pnt_check( $w['questions'] >= 10 && $w['answered'] >= 4 && $w['unknown'] >= 3, 'week: questions, answered and not known are counted' );
pnt_check( $w['learned_auto'] >= 1 && $w['new_wordings'] >= 2, 'week: what it learned on its own and the new wordings' );
wp_set_current_user( $admin->ID );
ob_start();
PNChat_Admin::week_box();
$out = ob_get_clean();
pnt_check( false !== strpos( $out, 'Έμαθε μόνο του' ) && false !== strpos( $out, 'ερωτήσεις' ), 'week: the box says it in words' );
PNChat_Admin::init(); // Hooked in wp-admin only.
pnt_check( has_action( 'wp_dashboard_setup', array( 'PNChat_Admin', 'dashboard' ) ) > 0, 'week: a box on the WordPress Dashboard' );
ob_start();
PNChat_Admin::page_lessons();
$out = ob_get_clean();
pnt_check( false !== strpos( $out, 'Αυτή την εβδομάδα' ), 'week: also at the top of «Μάθηση»' );
wp_set_current_user( 0 );

pnt_done();
