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

// Leave the brain as it was.
wp_set_current_user( $admin->ID );
PNChat_Brain::import( $original, 'replace', true, false );
update_option( PNChat_Settings::OPTION, $original_settings );
delete_option( PNChat_Brain::SNAPSHOTS );
check( 'brain restored', count( PNChat_Store::entries() ) === $before );

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit( $fails ? 1 : 0 );
