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

// ---- AI training assistant (Claude API mocked, no network) ------------------------
wp_set_current_user( $admin->ID );
delete_option( 'pnchat_ai_key' );
delete_option( PNChat_AI::USAGE_OPTION );
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
check( 'AI: wrong key explained', is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'API key' ) );
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
remove_filter( 'pre_http_request', $fake, 10 );
wp_delete_post( $page_id, true );
delete_option( 'pnchat_ai_key' );
delete_option( PNChat_AI::USAGE_OPTION );
update_option( PNChat_Settings::OPTION, $original_settings );

// Leave the brain as it was.
wp_set_current_user( $admin->ID );
PNChat_Brain::import( $original, 'replace', true, false );
update_option( PNChat_Settings::OPTION, $original_settings );
delete_option( PNChat_Brain::SNAPSHOTS );
check( 'brain restored', count( PNChat_Store::entries() ) === $before );

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit( $fails ? 1 : 0 );
