<?php
/**
 * 1.8.0: REST endpoints (e-mail states, failures, texts with %, page
 * address) and the AI in the chat (refusal guard, personal details, source,
 * daily limit). Claude is faked through pre_http_request.
 */
require __DIR__ . '/lib.php';
global $wpdb;
$ct = PNChat_Counter::table();
pnt_defer( 'pnt_clear_questions' );
pnt_defer(
	function () use ( $wpdb, $ct ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s OR k LIKE %s', $ct, 'rl:%', 'ai_chat:%' ) );
	}
);
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', $ct, 'rl:%' ) );
wp_set_current_user( 0 );

$mails = array();
add_filter(
	'pre_wp_mail',
	function ( $r, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	5,
	2
);

// ---- e-mail ------------------------------------------------------------------
pnt_clear_questions();
list( $st, $a ) = pnt_rest( '/ask', array( 'question' => 'Πού βρίσκεται το κατάστημα στη Λάρισα;', 'page' => home_url( '/contact/?email=v@x.gr&token=abc#top' ) ) );
pnt_same( 200, $st, 'ask: 200' );
pnt_same( 'unanswered', $a['status'] ?? null, 'ask: unanswered (nothing trained about it)' );
$q = PNChat_Store::question( (int) $a['id'] );
pnt_same( home_url( '/contact/' ), $q['page_url'], 'ask: page stored without query string or fragment' );
pnt_same( '', PNChat_Rest::page_url( 'https://evil.example/page/' ), 'page_url: another site is not stored' );

list( $st, $e ) = pnt_rest( '/email', array( 'id' => $a['id'], 'token' => $a['token'], 'email' => 'v@example.test' ) );
pnt_same( 200, $st, 'email: 200' );
pnt_same( 1, count( $mails ), 'email: the administrator is notified once' );
pnt_check( false !== strpos( (string) ( $e['message'] ?? '' ), 'v@example.test' ), 'email: {email} filled in the thanks' );
list( $st ) = pnt_rest( '/email', array( 'id' => $a['id'], 'token' => $a['token'], 'email' => 'v@example.test' ) );
pnt_same( array( 200, 1 ), array( $st, count( $mails ) ), 'email again (double tap): ok, no second notification' );
list( $st ) = pnt_rest( '/email', array( 'id' => $a['id'], 'token' => $a['token'], 'email' => 'other@example.test' ) );
pnt_same( array( 200, 2 ), array( $st, count( $mails ) ), 'email changed: stored and notified' );
list( $st ) = pnt_rest( '/email', array( 'id' => $a['id'], 'token' => 'wrong', 'email' => 'x@example.test' ) );
pnt_same( 404, $st, 'email with a wrong token: 404' );

// A replied question gets a new e-mail: open again.
PNChat_Store::update_question( (int) $a['id'], array( 'status' => 'replied' ) );
pnt_rest( '/email', array( 'id' => $a['id'], 'token' => $a['token'], 'email' => 'third@example.test' ) );
pnt_same( 'unanswered', PNChat_Store::question( (int) $a['id'] )['status'], 'email on a replied question: open again' );
PNChat_Store::update_question( (int) $a['id'], array( 'status' => 'trained' ) );
pnt_rest( '/email', array( 'id' => $a['id'], 'token' => $a['token'], 'email' => 'fourth@example.test' ) );
pnt_same( 'trained', PNChat_Store::question( (int) $a['id'] )['status'], 'email on a trained question: stays trained (in «Περιμένουν e-mail»)' );
PNChat_Store::update_question( (int) $a['id'], array( 'status' => 'blocked' ) );
list( $st ) = pnt_rest( '/email', array( 'id' => $a['id'], 'token' => $a['token'], 'email' => 'fifth@example.test' ) );
pnt_same( 400, $st, 'email on a refused question: 400' );

// The database refuses the update: the visitor is told, not thanked.
PNChat_Store::update_question( (int) $a['id'], array( 'status' => 'unanswered' ) );
$breaker  = function ( $sql ) {
	return 0 === stripos( ltrim( $sql ), 'UPDATE' ) && false !== strpos( $sql, 'pnchat_questions' ) ? 'UPDATE no_such_table_pnchat SET x = 1' : $sql;
};
add_filter( 'query', $breaker );
$suppress = $wpdb->suppress_errors( true );
list( $st ) = pnt_rest( '/email', array( 'id' => $a['id'], 'token' => $a['token'], 'email' => 'sixth@example.test' ) );
$wpdb->suppress_errors( $suppress );
remove_filter( 'query', $breaker );
pnt_same( 500, $st, 'email the database refused: 500, not «Ευχαριστούμε»' );

// ---- feedback ----------------------------------------------------------------
list( , $hi ) = pnt_rest( '/ask', array( 'question' => 'Γεια σας' ) );
pnt_same( 'answered', $hi['status'] ?? null, 'ask «Γεια σας»: answered' );
add_filter( 'query', $breaker );
$suppress = $wpdb->suppress_errors( true );
list( $st ) = pnt_rest( '/feedback', array( 'id' => $hi['id'], 'token' => $hi['token'], 'helpful' => false ) );
$wpdb->suppress_errors( $suppress );
remove_filter( 'query', $breaker );
pnt_same( 500, $st, 'feedback the database refused: 500' );
list( $st, $fb ) = pnt_rest( '/feedback', array( 'id' => $hi['id'], 'token' => $hi['token'], 'helpful' => false ) );
pnt_same( array( 200, 'unhelpful' ), array( $st, PNChat_Store::question( (int) $hi['id'] )['status'] ), 'feedback 👎: unhelpful' );

// ---- texts with % --------------------------------------------------------------
pnt_same( 'Έκπτωση 10% για «Χ»', PNChat_Settings::fill( 'Έκπτωση 10% για «{question}»', 'question', 'Χ' ), 'fill: a % in the text is fine' );
pnt_same( 'Για το «Χ» 100%', PNChat_Settings::fill( 'Για το «%s» 100%%', 'question', 'Χ' ), 'fill: old %s and %% texts still work' );
pnt_same( 'a %s b', PNChat_Settings::fill( 'a {question} b', 'question', '%s' ), 'fill: the value is not filled again' );
pnt_settings( array( 'partial' => 'Για «{question}» 50% %d %1$s δεν ξέρουμε', 'site_search' => 0 ) );
list( $st, $p ) = pnt_rest( '/ask', array( 'question' => 'Τι είναι το PlanDose και πού είναι η Λάρισα;' ) );
pnt_same( 200, $st, 'partial answer with %, %d and %1$s in its text: no crash (was ValueError)' );
pnt_same( 'partial', $p['status'] ?? null, 'partial answer: status partial' );

// Settings migration of the old default texts.
$s                 = get_option( PNChat_Settings::OPTION );
$s['email_thanks'] = 'Ευχαριστούμε! Θα σας απαντήσουμε σύντομα στο %s.';
$s['partial']      = 'Δικό μου κείμενο %s';
update_option( PNChat_Settings::OPTION, $s, false );
update_option( 'pnchat_settings_version', 6, false );
PNChat_Settings::migrate();
pnt_same( 'Ευχαριστούμε! Θα σας απαντήσουμε σύντομα στο {email}.', PNChat_Settings::value( 'email_thanks' ), 'migration: the old default text gets {email}' );
pnt_same( 'Δικό μου κείμενο %s', PNChat_Settings::value( 'partial' ), 'migration: a text the admin wrote is kept' );

// notify_email empty = the current admin e-mail.
pnt_settings( array( 'notify_email' => '' ) );
pnt_same( get_option( 'admin_email' ), PNChat_Settings::notify_address(), 'notify: empty setting = admin e-mail' );
PNChat_Settings::save( PNChat_Settings::sanitize( PNChat_Settings::get() ) );
pnt_same( '', PNChat_Settings::value( 'notify_email' ), 'notify: saving the settings keeps it empty (follows a new admin e-mail)' );

// ---- AI in the chat ------------------------------------------------------------
pnt_settings( array( 'ai_chat' => 1, 'site_search' => 1, 'ai_chat_daily' => 50, 'notify_email' => '' ) );
update_option( 'pnchat_ai_key', 'sk-ant-test-0000000000000000000000', false );
pnt_defer(
	function () {
		delete_option( 'pnchat_ai_key' );
	}
);
$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Παράδοση παραγγελιών στη Θεσσαλονίκη',
		'post_content' => 'Η παράδοση παραγγελιών στη Θεσσαλονίκη γίνεται την επόμενη εργάσιμη. Η παράδοση είναι δωρεάν για παραγγελίες πάνω από 30 ευρώ.',
	)
);
$dose_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Δοσολογία παρακεταμόλης για παιδιά',
		'post_content' => 'Η δοσολογία της παρακεταμόλης για παιδιά εξαρτάται από το βάρος. Συνήθης δοσολογία παρακεταμόλης ανά κιλό.',
	)
);
pnt_defer(
	function () use ( $page_id, $dose_id ) {
		wp_delete_post( $page_id, true );
		wp_delete_post( $dose_id, true );
	}
);
pnt_check( (bool) PNChat_Site_Search::search( 'παράδοση παραγγελιών Θεσσαλονίκη' ), 'site search finds the delivery page' );

$sent   = array();
$answer = null;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$sent, &$answer ) {
		if ( false === strpos( $url, 'api.anthropic.com' ) ) {
			return $pre;
		}
		$sent[] = json_decode( $args['body'], true );
		return $answer;
	},
	10,
	3
);
$answer = pnt_claude_reply(
	array(
		'found' => true,
		'note'  => 'ok',
		'entry' => array(
			'title'      => 'Παράδοση',
			'phrasings'  => array( 'Πότε έρχεται η παραγγελία;' ),
			'keywords'   => array(),
			'answer'     => '<p>Την επόμενη εργάσιμη. <a href="https://evil.example/">εδώ</a></p>',
			'source_url' => 'https://evil.example/fake/',
		),
	)
);
list( $st, $r ) = pnt_rest( '/ask', array( 'question' => 'Παράδοση παραγγελιών Θεσσαλονίκη; maria.k@gmail.com 694 123 4567 01019012345' ), '198.51.100.9' );
pnt_same( 'ai', $r['status'] ?? null, 'AI: answers from the delivery page' );
$user_text = (string) ( $sent[0]['messages'][0]['content'] ?? '' );
pnt_check( false === strpos( $user_text, 'maria.k@gmail.com' ) && false !== strpos( $user_text, '[e-mail]' ), 'AI: the e-mail typed in the question is not sent' );
pnt_check( false === strpos( $user_text, '694 123 4567' ) && false !== strpos( $user_text, '[τηλέφωνο]' ), 'AI: the phone number is not sent' );
pnt_check( false === strpos( $user_text, '01019012345' ), 'AI: the ΑΜΚΑ is not sent' );
pnt_check( false !== strpos( (string) ( $sent[0]['system'] ?? '' ), 'ιατρική συμβουλή' ), 'AI: the prompt refuses medical questions' );
$html = (string) ( $r['items'][0]['html'] ?? '' );
pnt_check( false === strpos( $html, 'evil.example' ), 'AI: links to other sites removed' );
pnt_check( false !== strpos( $html, (string) get_permalink( $page_id ) ), 'AI: an invented source is replaced by the page that was sent' );
pnt_same( 1, PNChat_AI::chat_used_today(), 'AI: one call used today' );
$logged = PNChat_Store::question( (int) $r['id'] );
pnt_check( false !== strpos( $logged['question'], 'maria.k@gmail.com' ), 'AI: the site\'s own log keeps the question as typed' );

// Medical advice, or close to a refusal: never sent to the AI. Questions
// about the site's tools that mention medicines still go (1.8.1).
foreach ( array( 'παρακεταμόλη για παιδιά πόση δοσολογία ανά κιλό', 'Πόσα χάπια ντεπόν την ημέρα;', 'posa xapia depon', 'παρενεργειες ibuprofen', '500mg ή 1000mg;', 'Το παιδί έχει πυρετό', 'Τι δόση παίρνω από το depon;', 'Ποια δόση παρακεταμόλης βάζω στο PlanDose;', 'Ποιο φάρμακο για τον πόνο;', 'δοσολογία αντιβίωσης', 'Μπορώ να πάρω αντιβίωση με αλκοόλ;', 'Φάρμακο για τον βήχα' ) as $mq ) {
	pnt_check( PNChat_AI::is_medical( $mq ), "medical: «{$mq}»" );
}
foreach ( array( 'Τι είναι το PlanDose;', 'Πώς βρίσκω φαρμακείο;', 'Είναι δωρεάν για φαρμακεία;', 'Πότε γίνεται η παράδοση στη Θεσσαλονίκη;', 'Πώς εκτυπώνω ετικέτες φαρμάκων στο PlanDose;', 'Πώς φτιάχνω πλάνο δοσολογίας;', 'ftiaxno plano dosologias', 'Μπορώ να βάλω σιρόπι στο PlanDose;', 'Πώς προσθέτω φάρμακο στο πλάνο;', 'Πόσα φάρμακα χωράνε σε ένα πλάνο;', 'QR για φάρμακα', 'Πού μπορώ να πάρω το Pro;', 'Είμαι ο Ιωσήφ από το φαρμακείο' ) as $mq ) {
	pnt_check( ! PNChat_AI::is_medical( $mq ), "not medical (tool or other): «{$mq}»" );
}
pnt_check( ! pnt_call( 'PNChat_Rest', 'near_block', 'Πώς εκτυπώνω ετικέτες φαρμάκων στο PlanDose;' ), 'AI guard: the PlanDose label question may reach the AI (was refused in 1.8.0)' );
$sent           = array();
list( $st, $r ) = pnt_rest( '/ask', array( 'question' => 'παρακεταμόλη για παιδιά πόση δοσολογία ανά κιλό' ), '198.51.100.13' );
pnt_same( 0, count( $sent ), 'AI: a medical question is never sent (status ' . ( $r['status'] ?? '?' ) . ', the page about it is shown instead)' );
pnt_same( 'site', $r['status'] ?? null, 'AI: the medical question still gets the site\'s page, without AI' );

$block_id = PNChat_Store::save_entry(
	array(
		'kind'      => 'block',
		'title'     => 'Δοσολογίες',
		'phrasings' => array( 'Ποια είναι η δοσολογία του φαρμάκου;', 'Πόση δόση να δώσω;' ),
		'keywords'  => array(),
		'answer'    => 'Δεν απαντάμε σε ιατρικά θέματα.',
		'active'    => 1,
	)
);
pnt_defer(
	function () use ( $block_id ) {
		PNChat_Store::delete_entry( $block_id );
	}
);
PNChat_Brain::matcher( true );
// No medical word, but close to the refusal «Μπορώ να πάρω μαζί αυτά τα φάρμακα;».
add_filter( 'pnchat_ai_medical_terms', '__return_empty_array' );
add_filter( 'pnchat_ai_medicine_words', '__return_empty_array' );
$sent           = array();
list( $st, $r ) = pnt_rest( '/ask', array( 'question' => 'Παράδοση στη Θεσσαλονίκη: ποια δόση να δώσω;' ), '198.51.100.10' );
remove_filter( 'pnchat_ai_medical_terms', '__return_empty_array' );
remove_filter( 'pnchat_ai_medicine_words', '__return_empty_array' );
pnt_same( 0, count( $sent ), 'AI: a question close to a refusal is never sent (status ' . ( $r['status'] ?? '?' ) . ')' );

// Claude certainly not reached: the day's call is given back.
$answer = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to api.anthropic.com port 443' );
$before = PNChat_AI::chat_used_today();
pnt_rest( '/ask', array( 'question' => 'Πότε γίνεται η παράδοση στη Θεσσαλονίκη για παραγγελίες;' ), '198.51.100.11' );
pnt_same( $before, PNChat_AI::chat_used_today(), 'AI: no connection (cURL 7) does not use the daily limit' );
$answer = new WP_Error( 'http_request_not_executed', 'User has blocked requests through HTTP.' );
pnt_rest( '/ask', array( 'question' => 'Πότε γίνεται η παράδοση παραγγελιών στη Θεσσαλονίκη;' ), '198.51.100.14' );
pnt_same( $before, PNChat_AI::chat_used_today(), 'AI: a request WordPress blocked does not use the daily limit' );
// A time-out may come after Anthropic did the work: it keeps counting (1.8.1).
$answer = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 90001 milliseconds with 0 bytes received' );
pnt_rest( '/ask', array( 'question' => 'Θεσσαλονίκη παράδοση παραγγελιών πότε;' ), '198.51.100.15' );
pnt_same( $before + 1, PNChat_AI::chat_used_today(), 'AI: a time-out uses the daily limit (it may have been charged)' );
$answer = new WP_Error( 'http_request_failed', 'cURL error 56: Recv failure: Connection reset by peer' );
pnt_rest( '/ask', array( 'question' => 'Παράδοση Θεσσαλονίκη παραγγελίες;' ), '198.51.100.16' );
pnt_same( $before + 2, PNChat_AI::chat_used_today(), 'AI: a lost answer uses the daily limit' );
$before = PNChat_AI::chat_used_today();
$answer = pnt_claude_reply( array(), 500 );
pnt_rest( '/ask', array( 'question' => 'Η παράδοση παραγγελιών Θεσσαλονίκη πόσο κοστίζει;' ), '198.51.100.12' );
pnt_same( $before, PNChat_AI::chat_used_today(), 'AI: an HTTP 500 does not use the daily limit either' );

// ---- per-visitor limit and the proxy header -----------------------------------
pnt_settings( array( 'rate_per_10min' => 3, 'ai_chat' => 0 ) );
$codes = array();
for ( $i = 0; $i < 5; $i++ ) {
	list( $codes[] ) = pnt_rest( '/ask', array( 'question' => 'Γεια σας' ), '192.0.2.50' );
}
pnt_same( array( 200, 200, 200, 429, 429 ), $codes, 'rate limit: 3 per visitor, then 429' );
list( $other ) = pnt_rest( '/ask', array( 'question' => 'Γεια σας' ), '192.0.2.51' );
pnt_same( 200, $other, 'rate limit: another visitor is not affected' );
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
pnt_same( '10.0.0.1', PNChat_Rest::client_ip(), 'client_ip: REMOTE_ADDR without PNCHAT_IP_HEADER' );

pnt_done();
