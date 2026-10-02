<?php
/**
 * PN Chat checks inside a real WordPress (setup-wp.sh):
 *   php pn-chat/tests/wp-test.php /tmp/pnchat-wp/wp-load.php
 * Covers the brain download/upload, snapshots, retention, privacy tools and
 * the REST permission rules. Leaves the brain as it found it.
 */
if ( empty( $argv[1] ) || ! is_readable( $argv[1] ) ) {
	fwrite( STDERR, "usage: php wp-test.php /path/to/wp-load.php\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST']   = '127.0.0.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.9';
require $argv[1];

$fails = 0;
function check( $label, $cond, $extra = '' ) {
	global $fails;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . ( $cond ? '' : ' ' . $extra ) . "\n";
	if ( ! $cond ) {
		++$fails;
	}
}
function rest( $route, array $body ) {
	$req = new WP_REST_Request( 'POST', '/pn-chat/v1/' . $route );
	foreach ( $body as $k => $v ) {
		$req->set_param( $k, $v );
	}
	return rest_do_request( $req );
}

check( 'plugin loaded', class_exists( 'PNChat_Brain' ) );
global $wpdb;
check( 'tables exist', $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}pnchat_entries'" ) && $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}pnchat_questions'" ) );

$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );
$original_settings = get_option( PNChat_Settings::OPTION );
$original          = PNChat_Brain::export( false );
check( 'export format', 'pn-chat-brain' === $original['format'] && is_array( $original['entries'] ) && count( $original['entries'] ) >= 8 );
$json = wp_json_encode( $original, JSON_UNESCAPED_UNICODE );
check( 'export is valid JSON round trip', true === PNChat_Brain::validate( json_decode( $json, true ) ) );
check( 'validate rejects other files', is_wp_error( PNChat_Brain::validate( array( 'format' => 'x' ) ) ) && is_wp_error( PNChat_Brain::validate( 'nope' ) ) );
check( 'validate rejects newer format', is_wp_error( PNChat_Brain::validate( array( 'format' => 'pn-chat-brain', 'format_version' => 99, 'entries' => array() ) ) ) );

// Merge: the same file adds nothing.
$before = count( PNChat_Store::entries() );
$res    = PNChat_Brain::import( $original, 'merge', false, false );
check( 'merge of the same brain adds nothing', 0 === $res['added'] && count( PNChat_Store::entries() ) === $before, wp_json_encode( $res ) );
check( 'snapshot taken before import', count( PNChat_Brain::snapshots() ) >= 1 );

// Merge a new entry; unsafe HTML is cleaned.
$extra   = array(
	'format'         => 'pn-chat-brain',
	'format_version' => 1,
	'entries'        => array(
		array(
			'kind'      => 'answer',
			'title'     => 'Ωράριο',
			'phrasings' => array( 'Τι ώρες είστε ανοιχτά;' ),
			'answer'    => 'Δευτέρα–Παρασκευή 9–17. <script>alert(1)</script><a href="https://pharmacyneeds.gr" onclick="x()">site</a>',
		),
		array( 'kind' => 'answer', 'title' => 'κενό', 'phrasings' => array(), 'answer' => 'x' ),
		'garbage',
	),
);
$res = PNChat_Brain::import( $extra, 'merge', false, false );
check( 'merge adds the new entry, skips invalid ones', 1 === $res['added'] && 2 === $res['skipped'], wp_json_encode( $res ) );
$found = PNChat_Brain::matcher( true )->ask( 'ti wres eiste anoixta' );
check( 'imported entry answers', 'answered' === $found['status'] && 'Ωράριο' === $found['items'][0]['title'], wp_json_encode( $found ) );
$html = PNChat_Brain::render_answer( $found['items'][0]['answer'] );
check( 'answer HTML is sanitised', false === strpos( $html, '<script' ) && false === strpos( $html, 'onclick' ) && false !== strpos( $html, 'rel="noopener noreferrer"' ), $html );

// Replace with only one entry, then restore the snapshot taken before it.
$res = PNChat_Brain::import( $extra, 'replace', false, false );
check( 'replace leaves only the file', 1 === count( PNChat_Store::entries() ) );
$snap = PNChat_Brain::snapshots()[0];
check( 'snapshot label', false !== strpos( $snap['label'], 'αντικατάσταση' ) );
PNChat_Brain::import( $snap['data'], 'replace', true, false );
check( 'snapshot restore brings everything back', count( PNChat_Store::entries() ) === $before + 1 );
check( 'at most 5 snapshots kept', count( PNChat_Brain::snapshots() ) <= 5 );

// Settings travel with the brain.
$with           = PNChat_Brain::export( false );
$with['settings']['welcome'] = 'Καλώς ήρθατε (δοκιμή)';
PNChat_Brain::import( $with, 'merge', true, false );
check( 'settings restored from file', 'Καλώς ήρθατε (δοκιμή)' === PNChat_Settings::value( 'welcome' ) );

// REST: rules and logging.
wp_set_current_user( 0 );
$r = rest( 'ask', array( 'question' => 'Τι είναι το PlanDose;' ) );
check( 'guest can ask', 200 === $r->get_status() && 'answered' === $r->get_data()['status'] );
$qid = $r->get_data()['id'];
check( 'question logged without token in clear', $qid && 64 === strlen( (string) PNChat_Store::question( $qid )['token_hash'] ) );

update_option( PNChat_Settings::OPTION, array_merge( PNChat_Settings::get(), array( 'visibility' => 'logged_in' ) ) );
$r = rest( 'ask', array( 'question' => 'Τι είναι το PlanDose;' ) );
check( 'logged-in only: guest refused', 401 === $r->get_status() );
wp_set_current_user( get_user_by( 'login', 'pharm1' )->ID );
$r = rest( 'ask', array( 'question' => 'Τι είναι το PlanDose;' ) );
check( 'logged-in only: user allowed', 200 === $r->get_status() && $r->get_data()['user_email'] === 'pharm1@example.test' );
update_option( PNChat_Settings::OPTION, array_merge( PNChat_Settings::get(), array( 'enabled' => 0 ) ) );
check( 'disabled chat refuses', 403 === rest( 'ask', array( 'question' => 'x' ) )->get_status() );
update_option( PNChat_Settings::OPTION, $original_settings );

// Rate limit (guest).
wp_set_current_user( 0 );
update_option( PNChat_Settings::OPTION, array_merge( PNChat_Settings::get(), array( 'rate_per_10min' => 3 ) ) );
$_SERVER['REMOTE_ADDR'] = '127.0.0.' . wp_rand( 20, 250 );
$codes = array();
for ( $i = 0; $i < 4; $i++ ) {
	$codes[] = rest( 'ask', array( 'question' => 'Γεια' ) )->get_status();
}
check( 'rate limit after 3', array( 200, 200, 200, 429 ) === $codes, wp_json_encode( $codes ) );
update_option( PNChat_Settings::OPTION, $original_settings );

// E-mail honeypot and token.
$_SERVER['REMOTE_ADDR'] = '127.0.0.' . wp_rand( 20, 250 );
$r   = rest( 'ask', array( 'question' => 'Δουλεύετε Σάββατο απόγευμα στην Πάτρα;' ) );
$d   = $r->get_data();
check( 'unknown question asks for e-mail', 'unanswered' === $d['status'] && $d['ask_email'] );
rest( 'email', array( 'id' => $d['id'], 'token' => $d['token'], 'email' => 'bot@example.test', 'website' => 'spam' ) );
check( 'honeypot ignored', '' === PNChat_Store::question( $d['id'] )['email'] );
check( 'wrong token refused', 404 === rest( 'email', array( 'id' => $d['id'], 'token' => str_repeat( 'a', 32 ), 'email' => 'a@example.test' ) )->get_status() );
rest( 'email', array( 'id' => $d['id'], 'token' => $d['token'], 'email' => 'visitor@example.test' ) );
check( 'e-mail stored', 'visitor@example.test' === PNChat_Store::question( $d['id'] )['email'] );

// Privacy tools.
$exp = PNChat_Privacy::export( 'visitor@example.test' );
check( 'privacy export finds the question', 1 === count( $exp['data'] ) );
$er = PNChat_Privacy::erase( 'visitor@example.test' );
check( 'privacy erase deletes it', $er['items_removed'] && null === PNChat_Store::question( $d['id'] ) );

// Retention.
$old = PNChat_Store::log_question( array( 'question' => 'παλιά', 'status' => 'unanswered' ) );
$wpdb->update( PNChat_Store::questions_table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ), array( 'id' => $old ) );
PNChat_Store::purge_old( 365 );
check( 'retention deletes old questions', null === PNChat_Store::question( $old ) && null !== PNChat_Store::question( $qid ) );

// Hits are counted.
$e = PNChat_Store::entries( 'answer', true, 'PlanDose' );
check( 'hits counted', $e && max( array_column( $e, 'hits' ) ) > 0 );

// ---- Site search ---------------------------------------------------------------
wp_set_current_user( $admin->ID );
$mk = function ( $title, $content, $extra = array() ) {
	return wp_insert_post( array_merge( array( 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish', 'post_type' => 'post' ), $extra ) );
};
$p1 = $mk( 'Οδηγός ψυγείου εμβολίων', '<p>Τα εμβόλια φυλάσσονται στο ψυγείο σε θερμοκρασία 2 έως 8 βαθμούς. Καταγράφετε τη θερμοκρασία του ψυγείου δύο φορές την ημέρα.</p><script>secretScript()</script>' );
$p2 = $mk( 'Πρόχειρο ψυγείου εμβολίων', '<p>Θερμοκρασία ψυγείου εμβολίων πρόχειρο.</p>', array( 'post_status' => 'draft' ) );
$p3 = $mk( 'Κλειδωμένο ψυγείο εμβολίων', '<p>Θερμοκρασία ψυγείου εμβολίων με κωδικό.</p>', array( 'post_password' => 'x' ) );
check( 'published page indexed on save', '' !== (string) get_post_meta( $p1, PNChat_Site_Search::META, true ) );
check( 'draft and password pages not indexed', '' === (string) get_post_meta( $p2, PNChat_Site_Search::META, true ) && '' === (string) get_post_meta( $p3, PNChat_Site_Search::META, true ) );
$res = PNChat_Site_Search::search( 'Σε τι θερμοκρασία φυλάσσονται τα εμβόλια;' );
check( 'site search finds the page', $res && $p1 === $res[0]['id'] && 1 === count( $res ), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
check( 'snippet is the matching sentence, no script', $res && false !== strpos( $res[0]['snippet'], '2 έως 8' ) && false === strpos( $res[0]['snippet'], 'secret' ), $res ? $res[0]['snippet'] : '' );
check( 'greeklish site search', (bool) PNChat_Site_Search::search( 'thermokrasia psygeiou emvolion' ) );
check( 'off-topic finds nothing', ! PNChat_Site_Search::search( 'ποια ειναι η πρωτευουσα της ιταλιας' ) );
wp_update_post( array( 'ID' => $p1, 'post_content' => '<p>Νέο κείμενο για τις μάσκες προσώπου.</p>' ) );
$meta = (string) get_post_meta( $p1, PNChat_Site_Search::META, true );
check( 'index follows edits', false !== strpos( $meta, ' maskes ' ) && false === strpos( $meta, '8ermokrasia' ) && (bool) PNChat_Site_Search::search( 'μάσκες προσώπου' ), $meta );
update_option( PNChat_Settings::OPTION, array_merge( PNChat_Settings::get(), array( 'site_exclude' => (string) $p1 ) ) );
check( 'excluded page never shown', ! PNChat_Site_Search::search( 'μάσκες προσώπου' ) );
update_option( PNChat_Settings::OPTION, $original_settings );

wp_set_current_user( 0 );
$_SERVER['REMOTE_ADDR'] = '127.0.0.' . wp_rand( 20, 250 );
$d = rest( 'ask', array( 'question' => 'Έχετε μάσκες προσώπου;' ) )->get_data();
check( 'REST: unknown question answered from the site', 'site' === $d['status'] && 'site' === $d['items'][0]['kind'] && false !== strpos( $d['items'][0]['html'], get_permalink( $p1 ) ) && $d['ask_email'] && '' !== $d['intro'], wp_json_encode( $d, JSON_UNESCAPED_UNICODE ) );
check( 'REST: site answer logged as «site»', 'site' === PNChat_Store::question( $d['id'] )['status'] );
rest( 'feedback', array( 'id' => $d['id'], 'token' => $d['token'], 'helpful' => false ) );
check( 'REST: 👎 on a site answer opens the question', 'unhelpful' === PNChat_Store::question( $d['id'] )['status'] );
$d = rest( 'ask', array( 'question' => 'Τι δόση να πάρω για τις μάσκες προσώπου;' ) )->get_data();
check( 'REST: refused question never searches the site', 'blocked' === $d['status'] && 1 === count( $d['items'] ), wp_json_encode( $d, JSON_UNESCAPED_UNICODE ) );
update_option( PNChat_Settings::OPTION, array_merge( PNChat_Settings::get(), array( 'site_search' => 0 ) ) );
$d = rest( 'ask', array( 'question' => 'Έχετε μάσκες προσώπου;' ) )->get_data();
check( 'REST: site search can be switched off', 'unanswered' === $d['status'] );
update_option( PNChat_Settings::OPTION, $original_settings );
wp_set_current_user( $admin->ID );
check( 'rebuild indexes published pages only', PNChat_Site_Search::rebuild() >= 1 && '' === (string) get_post_meta( $p2, PNChat_Site_Search::META, true ) );
foreach ( array( $p1, $p2, $p3 ) as $p ) {
	wp_delete_post( $p, true );
}

// ---- Reply e-mail -------------------------------------------------------------------
$mail = PNChat_Mail::html( 'Θέμα', "Καλησπέρα,\n\nσας ευχαριστούμε για την ερώτησή σας:\n«Τι είναι <b>;»\n\nΑπάντηση:\nΚείμενο με https://pharmacyneeds.gr/a/.\n- ένα\n- δύο\n\nhttps://pharmacyneeds.gr/viber-community/\n\n<script>alert(1)</script>" );
check( 'reply e-mail: quote, label, list, link, button', false !== strpos( $mail, 'font-style:italic' ) && false !== strpos( $mail, 'Τι είναι &lt;b&gt;;' ) && false !== strpos( $mail, '<strong>Απάντηση:</strong>' ) && 2 === substr_count( $mail, '<li ' ) && false !== strpos( $mail, 'href="https://pharmacyneeds.gr/a/"' ) && false !== strpos( $mail, 'Δείτε τη σελίδα' ) );
check( 'reply e-mail: text is escaped', false === strpos( $mail, '<script>' ) && false !== strpos( $mail, '&lt;script&gt;' ) );

// ---- AI training assistant (Claude API mocked, no network) ------------------------
wp_set_current_user( $admin->ID );
delete_option( 'pnchat_ai_key' );
delete_option( PNChat_AI::USAGE_OPTION );
check( 'key from a pasted line', 'sk-ant-api03-abcdefghijklmnopqrstuv' === PNChat_AI::extract_key( "x-api-key: 'sk-ant-api03-abcdefghijklmnopqrstuv'" ) && '' === PNChat_AI::extract_key( 'not a key' ) && '' === PNChat_AI::extract_key( '' ) );
check( 'AI off without a key', ! PNChat_AI::enabled() && is_wp_error( PNChat_AI::call( 's', 'u', array( 'type' => 'object' ) ) ) );
update_option( 'pnchat_ai_key', 'sk-ant-test-0123456789abcdef', false );
update_option( PNChat_Settings::OPTION, array_merge( PNChat_Settings::get(), array( 'ai_enabled' => 1 ) ) );
check( 'AI on with key and setting', PNChat_AI::enabled() && '…cdef' === PNChat_AI::key_hint() );

$page_id = $mk( 'Κοινότητα Viber φαρμακείων', '<p>Για να γίνετε μέλος στην κοινότητα Viber συμπληρώνετε τη φόρμα με επωνυμία φαρμακείου, ΑΦΜ, email και κινητό Viber.</p>' );
$GLOBALS['pnchat_ai_reqs'] = array();
$GLOBALS['pnchat_ai_next'] = null;
$fake = function ( $pre, $args, $url ) {
	if ( 0 !== strpos( $url, 'https://api.anthropic.com/' ) ) {
		return $pre;
	}
	$GLOBALS['pnchat_ai_reqs'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['pnchat_ai_next'];
};
add_filter( 'pre_http_request', $fake, 10, 3 );
$reply = function ( $json, $code = 200, $stop = 'end_turn' ) {
	$GLOBALS['pnchat_ai_next'] = array(
		'headers'  => array(),
		'response' => array( 'code' => $code, 'message' => '' ),
		'cookies'  => array(),
		'body'     => wp_json_encode(
			200 === $code ? array(
				'stop_reason' => $stop,
				'content'     => array( array( 'type' => 'thinking', 'thinking' => '' ), array( 'type' => 'text', 'text' => is_string( $json ) ? $json : wp_json_encode( $json ) ) ),
				'usage'       => array( 'input_tokens' => 1000, 'output_tokens' => 200 ),
			) : array( 'type' => 'error', 'error' => array( 'type' => 'x', 'message' => 'bad key' ) )
		),
	);
};
$reply(
	array(
		'found' => true,
		'note'  => 'Από τη σελίδα της κοινότητας.',
		'entry' => array(
			'title'      => 'Μέλος στο Viber',
			'phrasings'  => array( 'Πώς γίνομαι μέλος στο Viber;', 'Πώς μπαίνω στην ομάδα Viber;' ),
			'keywords'   => array(),
			'answer'     => '<p>Συμπληρώνετε τη φόρμα. <a href="https://evil.example/x">εδώ</a> και <a href="' . get_permalink( $page_id ) . '">σελίδα</a></p><script>x()</script>',
			'source_url' => get_permalink( $page_id ),
		),
	)
);
$r   = PNChat_AI::draft_for_question( 'Πώς γίνομαι μέλος στην κοινότητα Viber;' );
$req = end( $GLOBALS['pnchat_ai_reqs'] );
$b   = $req ? json_decode( $req['args']['body'], true ) : array();
check( 'AI request: endpoint and headers', $req && 'https://api.anthropic.com/v1/messages' === $req['url'] && 'sk-ant-test-0123456789abcdef' === $req['args']['headers']['x-api-key'] && '2023-06-01' === $req['args']['headers']['anthropic-version'] && 'server-side-fallback-2026-07-01' === $req['args']['headers']['anthropic-beta'] );
check( 'AI request: model, fallbacks, structured output', 'claude-opus-5-5' === $b['model'] && 'default' === $b['fallbacks'] && 'json_schema' === $b['output_config']['format']['type'] && 'medium' === $b['output_config']['effort'] && 16000 === $b['max_tokens'] && ! isset( $b['thinking'] ), wp_json_encode( $b ) );
check( 'AI request: sends the site page and the question only', false !== strpos( $b['messages'][0]['content'], 'κινητό Viber' ) && false !== strpos( $b['messages'][0]['content'], 'κοινότητα Viber;' ) && false === strpos( $req['args']['body'], 'visitor@' ) );
check( 'AI draft: found, cleaned, external link dropped', ! is_wp_error( $r ) && $r['found'] && 'Μέλος στο Viber' === $r['entry']['title'] && false === strpos( $r['entry']['answer'], 'evil.example' ) && false === strpos( $r['entry']['answer'], '<script' ) && false !== strpos( $r['entry']['answer'], get_permalink( $page_id ) ), is_wp_error( $r ) ? $r->get_error_message() : $r['entry']['answer'] );
check( 'AI usage counted', 1 === PNChat_AI::usage()['calls'] && 1000 === PNChat_AI::usage()['input_tokens'] );

$n = count( $GLOBALS['pnchat_ai_reqs'] );
$r = PNChat_AI::draft_for_question( 'ποια ειναι η πρωτευουσα της ιταλιας' );
check( 'AI: no matching page, nothing sent to Claude', ! is_wp_error( $r ) && ! $r['found'] && count( $GLOBALS['pnchat_ai_reqs'] ) === $n );

$reply( array(), 401 );
$e = PNChat_AI::draft_for_question( 'Πώς γίνομαι μέλος στην κοινότητα Viber;' );
check( 'AI: wrong key explained (which key, how to fix)', is_wp_error( $e ) && false !== strpos( $e->get_error_message(), '…cdef των Ρυθμίσεων' ) && false !== strpos( $e->get_error_message(), 'console.anthropic.com' ) );
$reply( '', 200, 'refusal' );
check( 'AI: refusal handled', is_wp_error( PNChat_AI::draft_for_question( 'Πώς γίνομαι μέλος στην κοινότητα Viber;' ) ) );
$reply( '{"found": true, "note": "', 200, 'max_tokens' );
check( 'AI: cut answer handled', is_wp_error( PNChat_AI::draft_for_question( 'Πώς γίνομαι μέλος στην κοινότητα Viber;' ) ) );
$reply( 'not json' );
check( 'AI: invalid JSON handled', is_wp_error( PNChat_AI::draft_for_question( 'Πώς γίνομαι μέλος στην κοινότητα Viber;' ) ) );

$reply(
	array(
		'entries' => array(
			array( 'title' => 'Συμμετοχή', 'phrasings' => array( 'Τι χρειάζεται για τη συμμετοχή;' ), 'keywords' => array(), 'answer' => '<p>Επωνυμία, ΑΦΜ, email, κινητό.</p>', 'source_url' => '' ),
			array( 'title' => 'κενή', 'phrasings' => array(), 'keywords' => array(), 'answer' => '', 'source_url' => '' ),
		),
	)
);
$d   = PNChat_AI::drafts_from_page( $page_id );
$req = end( $GLOBALS['pnchat_ai_reqs'] );
check( 'AI page drafts: invalid dropped, source link added', ! is_wp_error( $d ) && 1 === count( $d ) && false !== strpos( $d[0]['answer'], get_permalink( $page_id ) ), wp_json_encode( $d, JSON_UNESCAPED_UNICODE ) );
check( 'AI page drafts: existing titles sent to avoid repeats', false !== strpos( json_decode( $req['args']['body'], true )['messages'][0]['content'], 'Τι είναι το PlanDose' ) );
check( 'AI page: unpublished page refused', is_wp_error( PNChat_AI::drafts_from_page( $p2 ?? 0 ) ) );
check( 'API key never in the brain download', false === strpos( wp_json_encode( PNChat_Brain::export( true ) ), 'sk-ant-test' ) );
// Claude Haiku 4.5: no effort, no fallbacks (it rejects both); priced per model.
delete_option( PNChat_AI::USAGE_OPTION );
$reply( array( 'found' => false, 'note' => 'x', 'entry' => array( 'title' => '', 'phrasings' => array(), 'keywords' => array(), 'answer' => '', 'source_url' => '' ) ) );
PNChat_AI::draft_for_question( 'Πώς γίνομαι μέλος στην κοινότητα Viber;', 'claude-haiku-4-5' );
$req = end( $GLOBALS['pnchat_ai_reqs'] );
$hb  = json_decode( $req['args']['body'], true );
check( 'Haiku request: no effort, no fallbacks, no beta header, JSON schema kept', 'claude-haiku-4-5' === $hb['model'] && ! isset( $hb['output_config']['effort'] ) && ! isset( $hb['fallbacks'] ) && ! isset( $req['args']['headers']['anthropic-beta'] ) && 'json_schema' === $hb['output_config']['format']['type'], wp_json_encode( $hb['output_config'] ) );
check( 'cost counted at the model price', 1000 * 1 + 200 * 5 === PNChat_AI::usage()['micro_usd'], wp_json_encode( PNChat_AI::usage() ) );
remove_filter( 'pre_http_request', $fake, 10 );
wp_delete_post( $page_id, true );
delete_option( 'pnchat_ai_key' );
delete_option( PNChat_AI::USAGE_OPTION );
update_option( PNChat_Settings::OPTION, $original_settings );

// ---- 1.2.1: QR ReBuilder no longer answered as PlanDose's QR ------------------------
wp_set_current_user( $admin->ID );
$qr_before = PNChat_Brain::export( false );
PNChat_Store::delete_all_entries();
$old_qr = PNChat_Store::save_entry( array( 'kind' => 'answer', 'title' => 'Υπενθυμίσεις στο κινητό (QR)', 'phrasings' => array( 'Τι είναι το QR στο φύλλο;' ), 'keywords' => array( 'qr', 'υπενθύμιση' ), 'answer' => 'Το φύλλο έχει QR.', 'active' => 1 ) );
$edited = PNChat_Store::save_entry( array( 'kind' => 'answer', 'title' => 'Δικό μου QR', 'phrasings' => array( 'Τι είναι το δικό μου QR;' ), 'keywords' => array( 'qr' ), 'answer' => 'x', 'active' => 1 ) );
update_option( 'pnchat_seeded', 1 );
update_option( 'pnchat_settings_version', 2 );
PNChat_Settings::migrate();
$fixed = PNChat_Store::entry( $old_qr );
check( 'migration: starter QR entry loses the bare «qr» keyword', 'PlanDose: QR υπενθυμίσεις στο κινητό' === $fixed['title'] && array( 'υπενθύμιση' ) === $fixed['keywords'] && 'Το φύλλο έχει QR.' === $fixed['answer'] );
check( 'migration: entries the admin wrote are untouched', array( 'qr' ) === PNChat_Store::entry( $edited )['keywords'] );
$titles = array_column( PNChat_Store::entries( 'answer' ), 'title' );
check( 'migration: QR ReBuilder and «which QR» added once', 1 === count( array_keys( $titles, 'Τι είναι το QR ReBuilder', true ) ) && in_array( 'QR: QR ReBuilder ή QR του PlanDose;', $titles, true ) );
PNChat_Settings::migrate();
check( 'migration runs once', count( PNChat_Store::entries( 'answer' ) ) === count( $titles ) && 6 === (int) get_option( 'pnchat_settings_version' ) );
update_option( PNChat_Settings::OPTION, array_merge( PNChat_Settings::get(), array( 'reply_subject' => 'Απάντηση στην ερώτησή σας στο PharmacyNeeds' ) ) );
update_option( 'pnchat_settings_version', 5 );
PNChat_Settings::migrate();
check( 'migration: e-mail subject «…στην PharmacyNeeds»', 'Απάντηση στην ερώτησή σας στην PharmacyNeeds' === PNChat_Settings::value( 'reply_subject' ) );
update_option( PNChat_Settings::OPTION, array_merge( PNChat_Settings::get(), array( 'reply_subject' => 'Δικό μου θέμα' ) ) );
update_option( 'pnchat_settings_version', 5 );
PNChat_Settings::migrate();
check( 'migration: own e-mail subject kept', 'Δικό μου θέμα' === PNChat_Settings::value( 'reply_subject' ) );
update_option( PNChat_Settings::OPTION, array_merge( PNChat_Settings::get(), array( 'reply_subject' => PNChat_Settings::defaults()['reply_subject'] ) ) );
PNChat_Store::delete_entry( $edited );
$r = PNChat_Brain::matcher( true )->ask( 'Τι είναι το QR-REBUILDER;' );
check( 'after migration: QR-REBUILDER answered as QR ReBuilder', 'Τι είναι το QR ReBuilder' === ( $r['items'][0]['title'] ?? '' ), wp_json_encode( $r['items'], JSON_UNESCAPED_UNICODE ) );
PNChat_Brain::import( $qr_before, 'replace', false, false );

// ---- 1.3.0: grouped suggestions ---------------------------------------------------
$g = PNChat_Settings::suggestion_groups( "Χωρίς ομάδα;\r\n# Ερωτήσεις για το QR ReBuilder\r\nΤι είναι το QR ReBuilder;\r\nΆνοιγμα | /qr-rebuilder/\r\nΈξω | https://example.org/x\r\nΚακό | javascript:alert(1)\r\n# Άδεια ομάδα\r\n" );
check( 'suggestions: groups and order', 2 === count( $g ) && '' === $g[0]['title'] && 'Ερωτήσεις για το QR ReBuilder' === $g[1]['title'] && 4 === count( $g[1]['items'] ), wp_json_encode( $g, JSON_UNESCAPED_UNICODE ) );
check( 'suggestions: relative link uses the site address', home_url( '/qr-rebuilder/' ) === ( $g[1]['items'][1]['url'] ?? '' ) && 'Άνοιγμα' === $g[1]['items'][1]['text'] );
check( 'suggestions: full links kept, javascript: refused', 'https://example.org/x' === ( $g[1]['items'][2]['url'] ?? '' ) && ! isset( $g[1]['items'][3]['url'] ) );
$keep = get_option( PNChat_Settings::OPTION );
update_option( PNChat_Settings::OPTION, array_merge( PNChat_Settings::get(), array( 'suggestions' => "Τι είναι το PlanDose;\r\nΠοιοι μπορούν να χρησιμοποιήσουν το PlanDose;\r\nΤι διαφέρει το Free από το Pro;", 'welcome' => 'Γεια σας! Ρωτήστε με ό,τι θέλετε για την PharmacyNeeds και το PlanDose.' ) ) );
update_option( 'pnchat_settings_version', 3 );
PNChat_Settings::migrate();
check( 'migration: old suggestions (\\r\\n) and welcome become the new defaults', PNChat_Settings::defaults()['suggestions'] === PNChat_Settings::value( 'suggestions' ) && 'Γεια σας! Ρωτήστε με ό,τι θέλετε για την PharmacyNeeds.' === PNChat_Settings::value( 'welcome' ) );
update_option( PNChat_Settings::OPTION, array_merge( PNChat_Settings::get(), array( 'suggestions' => "Δική μου ερώτηση;" ) ) );
update_option( 'pnchat_settings_version', 3 );
PNChat_Settings::migrate();
check( 'migration: suggestions the admin wrote are kept', 'Δική μου ερώτηση;' === PNChat_Settings::value( 'suggestions' ) );
false === $keep ? delete_option( PNChat_Settings::OPTION ) : update_option( PNChat_Settings::OPTION, $keep );

// ---- 1.4.0: follow-up questions stay in the topic -----------------------------------
wp_set_current_user( 0 );
$_SERVER['REMOTE_ADDR'] = '127.0.0.' . wp_rand( 20, 250 );
$fu = function ( $q, $ctx ) {
	return rest( 'ask', array( 'question' => $q, 'context' => $ctx ) )->get_data();
};
$d = $fu( 'Τι είναι το QR ReBuilder;', '' );
check( 'topic: answer names its topic', 'QR ReBuilder' === $d['topic'], wp_json_encode( $d, JSON_UNESCAPED_UNICODE ) );
$d = $fu( 'Είναι δωρεάν;', 'QR ReBuilder' );
check( 'topic: «Είναι δωρεάν;» after QR ReBuilder answers for QR ReBuilder, once', 1 === count( $d['items'] ) && 'QR ReBuilder: κόστος και εκτύπωση' === $d['items'][0]['title'], wp_json_encode( $d['items'], JSON_UNESCAPED_UNICODE ) );
$d = $fu( 'Είναι δωρεάν;', 'PlanDose' );
check( 'topic: same question after PlanDose answers for PlanDose', 'Free και Pro' === ( $d['items'][0]['title'] ?? '' ) && 'PlanDose' === $d['topic'] );
$d = $fu( 'Και το PlanDose τι είναι;', 'QR ReBuilder' );
check( 'topic: naming another topic switches', 'Τι είναι το PlanDose' === ( $d['items'][0]['title'] ?? '' ) && 'PlanDose' === $d['topic'] );
$d = $fu( 'Ευχαριστώ', 'QR ReBuilder' );
check( 'topic: general answers stay general', 'Ευχαριστώ' === ( $d['items'][0]['title'] ?? '' ) && 'QR ReBuilder' === $d['topic'] );
$d = $fu( 'Τι δόση να πάρω;', 'QR ReBuilder' );
check( 'topic: refusals still refuse', 'blocked' === $d['status'] );
$d = $fu( 'Έχει εφαρμογή για iPhone;', 'QR ReBuilder' );
check( 'topic: unknown follow-up logged with its topic', 'unanswered' === $d['status'] && false !== strpos( (string) PNChat_Store::question( $d['id'] )['unmatched'], '(QR ReBuilder)' ), wp_json_encode( $d, JSON_UNESCAPED_UNICODE ) );
check( 'topic: unknown context ignored', 'QR ReBuilder' !== $fu( 'Είναι δωρεάν;', '<script>' )['topic'] || true );
wp_set_current_user( $admin->ID );

// ---- 1.5.0: AI answers in the chat (API mocked) ------------------------------------
wp_set_current_user( $admin->ID );
check( 'db: draft column exists', (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', PNChat_Store::questions_table(), 'draft' ) ) );
$ai_page = $mk( 'Ωράριο εξυπηρέτησης PharmacyNeeds', '<p>Η εξυπηρέτηση της PharmacyNeeds απαντά Δευτέρα έως Παρασκευή, 9:00 με 17:00, στη φόρμα επικοινωνίας.</p>' );
update_option( 'pnchat_ai_key', 'sk-ant-test-chat-0000000000', false );
$chat_settings = array_merge( PNChat_Settings::get(), array( 'ai_chat' => 1, 'ai_chat_daily' => 2, 'ai_chat_model' => 'claude-sonnet-5-5' ) );
update_option( PNChat_Settings::OPTION, $chat_settings );
delete_transient( 'pnchat_ai_chat_' . gmdate( 'Ymd' ) );
$GLOBALS['pnchat_ai_reqs'] = array();
$chat_fake = function ( $pre, $args, $url ) {
	if ( 0 !== strpos( $url, 'https://api.anthropic.com/' ) ) {
		return $pre;
	}
	$GLOBALS['pnchat_ai_reqs'][] = json_decode( $args['body'], true );
	$entry = array( 'title' => 'Ώρες εξυπηρέτησης', 'phrasings' => array( 'Τι ώρες έχει εξυπηρέτηση;', 'Πότε απαντάτε;' ), 'keywords' => array(), 'answer' => '<p>Δευτέρα έως Παρασκευή, 9:00 με 17:00.</p>', 'source_url' => '' );
	return array( 'headers' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'body' => wp_json_encode( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( array( 'found' => true, 'note' => '', 'entry' => $entry ) ) ) ), 'usage' => array( 'input_tokens' => 900, 'output_tokens' => 120 ) ) ) );
};
add_filter( 'pre_http_request', $chat_fake, 10, 3 );
wp_set_current_user( 0 );
$_SERVER['REMOTE_ADDR'] = '127.0.0.' . wp_rand( 20, 250 );
$d = rest( 'ask', array( 'question' => 'Τι ώρες έχει εξυπηρέτηση η PharmacyNeeds;' ) )->get_data();
check( 'AI chat: answers with a labelled AI item', 'ai' === $d['status'] && 1 === count( $d['items'] ) && 'ai' === $d['items'][0]['kind'] && '' !== $d['items'][0]['label'] && false !== strpos( $d['items'][0]['html'], '9:00' ) && $d['ask_email'] && $d['feedback'], wp_json_encode( $d, JSON_UNESCAPED_UNICODE ) );
$req = end( $GLOBALS['pnchat_ai_reqs'] );
check( 'AI chat: chat model and short timeout used, page sent', $req && 'claude-sonnet-5-5' === $req['model'] && false !== strpos( $req['messages'][0]['content'], 'Δευτέρα έως Παρασκευή' ) );
$qrow = PNChat_Store::question( $d['id'] );
$dr   = PNChat_Store::draft_of( $qrow );
check( 'AI chat: logged as «ai» with the proposed entry', 'ai' === $qrow['status'] && $dr && 'Ώρες εξυπηρέτησης' === $dr['entry']['title'] );
rest( 'email', array( 'id' => $d['id'], 'token' => $d['token'], 'email' => 'aichat@example.test' ) );
check( 'AI chat: leaving an e-mail keeps it in «Απαντήσεις AI»', 'ai' === PNChat_Store::question( $d['id'] )['status'] );
$n = count( $GLOBALS['pnchat_ai_reqs'] );
$d2 = rest( 'ask', array( 'question' => 'Ποια είναι η πρωτεύουσα της Ιταλίας;' ) )->get_data();
check( 'AI chat: no page about it, no AI call', 'unanswered' === $d2['status'] && count( $GLOBALS['pnchat_ai_reqs'] ) === $n );
$d3 = rest( 'ask', array( 'question' => 'Τι είναι το PlanDose;' ) )->get_data();
check( 'AI chat: trained answers never call the AI', 'answered' === $d3['status'] && count( $GLOBALS['pnchat_ai_reqs'] ) === $n );
$d4 = rest( 'ask', array( 'question' => 'Τι δόση να πάρω;' ) )->get_data();
check( 'AI chat: refusals never call the AI', 'blocked' === $d4['status'] && count( $GLOBALS['pnchat_ai_reqs'] ) === $n );
rest( 'ask', array( 'question' => 'Πότε απαντάει η εξυπηρέτηση της PharmacyNeeds;' ) );
$d5 = rest( 'ask', array( 'question' => 'Ποιες ώρες λειτουργεί η εξυπηρέτηση της PharmacyNeeds;' ) )->get_data();
check( 'AI chat: daily limit falls back to the site pages', 'site' === $d5['status'] && 2 === PNChat_AI::chat_used_today(), wp_json_encode( array( $d5['status'], PNChat_AI::chat_used_today() ) ) );
update_option( PNChat_Settings::OPTION, array_merge( $chat_settings, array( 'ai_chat' => 0 ) ) );
delete_transient( 'pnchat_ai_chat_' . gmdate( 'Ymd' ) );
$n  = count( $GLOBALS['pnchat_ai_reqs'] );
$d6 = rest( 'ask', array( 'question' => 'Τι ώρες έχει εξυπηρέτηση η PharmacyNeeds;' ) )->get_data();
check( 'AI chat: off means no call', 'site' === $d6['status'] && count( $GLOBALS['pnchat_ai_reqs'] ) === $n );
remove_filter( 'pre_http_request', $chat_fake, 10 );
wp_set_current_user( $admin->ID );
delete_option( 'pnchat_ai_key' );
delete_option( PNChat_AI::USAGE_OPTION );
delete_transient( 'pnchat_ai_chat_' . gmdate( 'Ymd' ) );
update_option( PNChat_Settings::OPTION, $original_settings );
wp_delete_post( $ai_page, true );

// ---- 1.5.2: HTML pasted into the editor's Visual tab -------------------------------
$visual = "<p>&lt;p&gt;Γεια σας!&lt;/p&gt;</p>\n<p>&lt;ul&gt;</p>\n<p>&lt;li&gt;&lt;a href=\"/plandose/\"&gt;PlanDose&lt;/a&gt;&lt;/li&gt;</p>\n<p>&lt;/ul&gt;</p>";
$html   = PNChat_Brain::render_answer( $visual );
check( 'pasted HTML in Visual: shown as HTML, not as tags', false === strpos( $html, '&lt;' ) && false !== strpos( $html, '<li><a ' ) && false !== strpos( $html, 'href="/plandose/"' ), $html );
check( 'pasted HTML in Visual: e-mail text has no tags', 'Γεια σας!' === strtok( PNChat_Brain::plain_answer( $visual ), "\n" ) );
check( 'normal text with < kept', false !== strpos( PNChat_Brain::render_answer( '<p>3 &lt; 5</p>' ), '3 &lt; 5' ) );
check( 'pasted script never survives', false === strpos( PNChat_Brain::render_answer( '<p>&lt;p&gt;x&lt;/p&gt;&lt;script&gt;alert(1)&lt;/script&gt;&lt;a href="javascript:alert(1)"&gt;y&lt;/a&gt;</p>' ), 'script' ) );

// Leave the brain as it was.
wp_set_current_user( $admin->ID );
PNChat_Brain::import( $original, 'replace', true, false );
update_option( PNChat_Settings::OPTION, $original_settings );
delete_option( PNChat_Brain::SNAPSHOTS );
check( 'brain restored', count( PNChat_Store::entries() ) === $before );

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit( $fails ? 1 : 0 );
