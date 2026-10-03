<?php
/**
 * 1.9.0: «Προτάσεις AI». Claude reads a page of the site, a web page, a PDF
 * (address or upload) or the whole site and proposes entries; nothing
 * reaches the chat before approval. Keyword hygiene, similarity notes,
 * duplicates, rejected titles, changed-only, the monthly limit, uses given
 * back when Claude was never asked, the lock, stop, weekly schedule, and the
 * admin approve / merge / reject / bulk handlers. 1.9.1: sources go to
 * Claude as message batches (sent in one step, collected in a later one).
 * Claude's Batches API and the web are faked through pre_http_request.
 */
require __DIR__ . '/lib.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
global $wpdb;
$ct = PNChat_Counter::table();
$pt = PNChat_Learn::table();

pnt_keep_entries();
pnt_settings(
	array(
		'ai_enabled'       => 1,
		'ai_learn_monthly' => 200,
		'ai_learn_weekly'  => 0,
		'topics'           => "ΤεστTool, testtool\nPlanDose, πλάνο δοσολογίας",
	)
);
PNChat_Topics::reset();
update_option( 'pnchat_ai_key', 'sk-ant-test-0000000000000000000000', false );
$month = 'ai_learn:' . gmdate( 'Ym' );
$reset = function () use ( $wpdb, $ct, $pt ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $pt ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s OR k = %s', $ct, 'ai_learn:%', 'learn_lock' ) );
	delete_option( PNChat_Learn::QUEUE );
	delete_option( PNChat_Learn::STATE );
	wp_clear_scheduled_hook( PNChat_Learn::CRON );
	wp_clear_scheduled_hook( PNChat_Learn::WEEKLY );
};
pnt_defer( $reset );
pnt_defer(
	function () {
		delete_option( 'pnchat_ai_key' );
		delete_post_meta_by_key( PNChat_Learn::META );
	}
);
$reset();

// ---- fakes ------------------------------------------------------------------------
$sent    = array(); // Params of every request sent to Claude.
$headers = array(); // Their headers.
$reply   = array(); // Next entries Claude returns.
$fail    = null;    // A WP_Error instead of Claude's answer (on sending).
$web     = array(); // Address => [content-type, body, code].
$batches = array(); // Batch id => [custom_id, entries].
$hold    = false;   // Batches stay «in_progress».
$outcome = 'succeeded';
$cancels = array();
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$sent, &$headers, &$reply, &$fail, &$web, &$batches, &$hold, &$outcome, &$cancels ) {
		$json = function ( $data, $code = 200 ) {
			return array(
				'headers'  => array(),
				'body'     => is_string( $data ) ? $data : wp_json_encode( $data ),
				'response' => array(
					'code'    => $code,
					'message' => 'x',
				),
				'cookies'  => array(),
			);
		};
		$base = PNChat_AI::BATCHES_URL;
		if ( $url === $base ) {
			$body      = json_decode( (string) $args['body'], true );
			$sent[]    = $body['requests'][0]['params'];
			$headers[] = $args['headers'];
			if ( $fail ) {
				return $fail;
			}
			$id             = 'msgbatch_' . count( $batches );
			$batches[ $id ] = array( $body['requests'][0]['custom_id'], $reply );
			return $json(
				array(
					'id'                => $id,
					'processing_status' => 'in_progress',
				)
			);
		}
		if ( preg_match( '#^' . preg_quote( $base, '#' ) . '/(msgbatch_\d+)(/results|/cancel)?$#', $url, $m ) ) {
			$id = $m[1];
			if ( '/cancel' === ( $m[2] ?? '' ) ) {
				$cancels[] = $id;
				return $json( array( 'id' => $id ) );
			}
			if ( '/results' === ( $m[2] ?? '' ) ) {
				$result = 'succeeded' === $outcome
					? array(
						'type'    => 'succeeded',
						'message' => json_decode( pnt_claude_reply( array( 'entries' => $batches[ $id ][1] ) )['body'], true ),
					)
					: array(
						'type'  => $outcome,
						'error' => array(
							'type'  => 'error',
							'error' => array( 'message' => 'fake batch error' ),
						),
					);
				return $json( wp_json_encode( array( 'custom_id' => 'other', 'result' => array( 'type' => 'expired' ) ) ) . "\n" . wp_json_encode( array( 'custom_id' => $batches[ $id ][0], 'result' => $result ) ) . "\n" );
			}
			return $json(
				array(
					'id'                => $id,
					'processing_status' => $hold ? 'in_progress' : 'ended',
					'results_url'       => $hold ? null : $base . '/' . $id . '/results',
				)
			);
		}
		if ( 0 === strpos( $url, 'https://api.anthropic.com/' ) ) {
			return $json( array( 'error' => array( 'message' => 'unexpected call ' . $url ) ), 400 );
		}
		if ( isset( $web[ $url ] ) ) {
			return array(
				'headers'  => array( 'content-type' => $web[ $url ][0] ),
				'body'     => $web[ $url ][1],
				'response' => array(
					'code'    => $web[ $url ][2] ?? 200,
					'message' => 'x',
				),
				'cookies'  => array(),
			);
		}
		return new WP_Error( 'http_request_failed', 'cURL error 6: no network in tests' );
	},
	10,
	3
);
// Sends the next source and, when it went, collects Claude's answer.
function pnt_learn_step() {
	$r = PNChat_Learn::process_next();
	return 'submitted' === $r['status'] ? PNChat_Learn::process_next() : $r;
}
$entry = function ( $title, array $phrasings, array $keywords = array(), $answer = '<p>Απάντηση.</p>' ) {
	return array(
		'title'      => $title,
		'phrasings'  => $phrasings,
		'keywords'   => $keywords,
		'answer'     => $answer,
		'source_url' => '',
	);
};

// An existing entry, for the similarity check.
$existing = PNChat_Store::save_entry(
	array(
		'kind'      => 'answer',
		'title'     => 'Κόστος ΤεστTool',
		'phrasings' => array( 'Πόσο κοστίζει το ΤεστTool;', 'Είναι δωρεάν το ΤεστTool;' ),
		'keywords'  => array(),
		'answer'    => 'Δωρεάν.',
		'active'    => 1,
	)
);
// Two entries that already say «εκτύπωση ετικέτας».
foreach ( array( 'Ετικέτες A', 'Ετικέτες B' ) as $t ) {
	PNChat_Store::save_entry(
		array(
			'kind'      => 'answer',
			'title'     => $t,
			'phrasings' => array( 'Πώς γίνεται η εκτύπωση ετικέτας στο ' . $t . ';' ),
			'keywords'  => array(),
			'answer'    => 'x',
			'active'    => 1,
		)
	);
}
PNChat_Brain::matcher( true );

$post_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Οδηγός ΤεστTool',
		'post_content' => '<p>Το ΤεστTool κάνει εγγραφή φαρμακείου σε δύο βήματα: συμπληρώνετε το ΑΦΜ και πατάτε Αποστολή.</p>',
	)
);
pnt_defer(
	function () use ( $post_id ) {
		wp_delete_post( $post_id, true );
	}
);

// ---- a page of the site -------------------------------------------------------------
$reply = array(
	$entry( 'Εγγραφή στο ΤεστTool', array( 'Πώς κάνω εγγραφή στο ΤεστTool;', 'Πώς γράφομαι στο ΤεστTool;' ), array( 'ΤεστTool', 'εκτύπωση ετικέτας', 'ΑΦΜ εγγραφής' ), '<p>Συμπληρώνετε το ΑΦΜ. <a href="https://evil.example/x">εδώ</a></p>' ),
	$entry( 'Τιμή ΤεστTool', array( 'Πόσο κοστίζει το ΤεστTool;' ) ),
);
pnt_same( 1, PNChat_Learn::enqueue( array( array( 'type' => 'post', 'id' => $post_id ) ), 'Οδηγός' ), 'enqueue: a page' );
pnt_same( 0, PNChat_Learn::enqueue( array( array( 'type' => 'post', 'id' => $post_id ) ), 'Οδηγός' ), 'enqueue: the same page is not queued twice' );
pnt_check( false !== wp_next_scheduled( PNChat_Learn::CRON ), 'enqueue: background reading scheduled' );
$before = PNChat_Counter::get( $month );
$r      = pnt_learn_step();
pnt_same( array( 'done', 2 ), array( $r['status'], $r['added'] ), 'page: read, 2 proposals' );
pnt_same( $before + 1, PNChat_Counter::get( $month ), 'page: counts for the month' );
$req = end( $sent );
pnt_check( false !== strpos( (string) $req['messages'][0]['content'], 'ΑΦΜ και πατάτε Αποστολή' ), 'page: its text is sent' );
pnt_check( false !== strpos( (string) $req['messages'][0]['content'], 'Κόστος ΤεστTool' ), 'page: existing titles are sent (not to repeat them)' );
pnt_check( false !== strpos( (string) $req['system'], 'ΠΟΤΕ το όνομα' ), 'page: the prompt forbids tool names as keywords' );
pnt_same( 16000, $req['max_tokens'] ?? null, 'page: max_tokens 16000' );
pnt_same( PNChat_Learn::post_hash( get_post( $post_id ) ), get_post_meta( $post_id, PNChat_Learn::META, true ), 'page: remembered as read' );

$props = PNChat_Learn::pending();
pnt_same( 2, count( $props ), 'proposals: 2 waiting' );
$p1 = $props[0];
pnt_same( array( 'ΑΦΜ εγγραφής' ), $p1['entry']['keywords'], 'hygiene: topic name and a phrase of 2 entries taken off, the specific one kept' );
pnt_check( false !== strpos( $p1['note'], '«ΤεστTool»' ) && false !== strpos( $p1['note'], '«εκτύπωση ετικέτας»' ), 'hygiene: both are explained in the note' );
pnt_check( false === strpos( $p1['entry']['answer'], 'evil.example' ) && false !== strpos( $p1['entry']['answer'], 'εδώ' ), 'links to other sites are taken off (text kept)' );
pnt_same( (string) get_permalink( $post_id ), $p1['source_url'], 'proposal keeps its source page' );
$p2 = $props[1];
pnt_same( (int) $existing, (int) $p2['similar_id'], 'similar: «Τιμή ΤεστTool» points to «Κόστος ΤεστTool»' );
pnt_check( false !== strpos( $p2['note'], 'Κόστος ΤεστTool' ), 'similar: explained in the note' );
$rev = PNChat_Learn::review( PNChat_Brain::clean_entry( $entry( 'Χρέωση', array( 'Πόσο κοστίζει το ΤεστTool;', 'Είναι δωρεάν το ΤεστTool;', 'Πώς γίνεται η εκτύπωση ετικέτας στο Ετικέτες A;' ) ) ) );
pnt_same( (int) $existing, $rev['similar_id'], 'similar: the entry most questions land on, not the one a stray question hits' );
pnt_check( false !== strpos( implode( ' ', $rev['notes'] ), '«Πώς γίνεται η εκτύπωση ετικέτας στο Ετικέτες A;» ταιριάζει ήδη με τη γνώση «Ετικέτες A»' ), 'similar: the stray question is named on its own' );

// Nothing reaches the chat.
wp_set_current_user( 0 );
list( , $a ) = pnt_rest( '/ask', array( 'question' => 'Πώς κάνω εγγραφή στο ΤεστTool;' ) );
$titles      = array_map( fn( $e ) => $e['title'], PNChat_Store::entries() );
pnt_check( ! in_array( 'Εγγραφή στο ΤεστTool', $titles, true ) && false === strpos( wp_json_encode( $a['items'] ?? array(), JSON_UNESCAPED_UNICODE ), 'Συμπληρώνετε το ΑΦΜ' ), 'chat: a proposal is not an entry and is not answered before approval' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', $ct, 'rl:%' ) );

// The same page again: same titles are skipped.
PNChat_Learn::enqueue( array( array( 'type' => 'post', 'id' => $post_id ) ), 'Οδηγός' );
$r = pnt_learn_step();
pnt_same( array( 'done', 0 ), array( $r['status'], $r['added'] ), 'again: proposals with the same title are not added twice' );
pnt_same( 2, (int) PNChat_Learn::state()['skipped'], 'again: counted as skipped' );

// ---- the whole site, changed only -----------------------------------------------------------
delete_option( PNChat_Learn::QUEUE );
$site = PNChat_Learn::enqueue_site( true );
$jobs = array_map( array( 'PNChat_Learn', 'job_label' ), PNChat_Learn::queue() );
pnt_check( ! in_array( 'Οδηγός ΤεστTool', $jobs, true ) && $site['unchanged'] >= 1, 'site, changed only: the page read before is left out' );
delete_option( PNChat_Learn::QUEUE );
wp_update_post( array( 'ID' => $post_id, 'post_content' => '<p>Νέο κείμενο: το ΤεστTool κάνει και ανανέωση συνδρομής.</p>' ) );
PNChat_Learn::enqueue_site( true );
$jobs = array_map( array( 'PNChat_Learn', 'job_label' ), PNChat_Learn::queue() );
pnt_check( in_array( 'Οδηγός ΤεστTool', $jobs, true ), 'site, changed only: the edited page is read again' );
$all = PNChat_Learn::enqueue_site( false );
pnt_same( 0, $all['unchanged'], 'site, all pages: nothing left out' );
delete_option( PNChat_Learn::QUEUE );
delete_option( PNChat_Learn::STATE );

// ---- a web page --------------------------------------------------------------------------
$web['https://example.test/odigos'] = array( 'text/html; charset=UTF-8', '<html><head><title>Οδηγός eΔΑΠΥ</title><script>var x="ΚΡΥΦΟ";</script></head><body><nav>Μενού Αρχική Επικοινωνία</nav><main><h1>Υποβολή</h1><p>' . str_repeat( 'Η υποβολή γίνεται από το μενού Υποβολές και θέλει κλειδάριθμο. ', 6 ) . '</p></main><footer>Copyright</footer></body></html>' );
$reply = array( $entry( 'Υποβολή στο eΔΑΠΥ', array( 'Πώς κάνω υποβολή στο eΔΑΠΥ;' ), array(), '<p>Από το μενού Υποβολές. <a href="https://example.test/odigos#b">Οδηγός</a> <a href="https://evil.example/">x</a></p>' ) );
PNChat_Learn::enqueue( array( array( 'type' => 'url', 'url' => 'https://example.test/odigos' ) ), 'url' );
$r   = pnt_learn_step();
$req = end( $sent );
$txt = (string) $req['messages'][0]['content'];
$txt = substr( $txt, 0, (int) strpos( $txt, '</page>' ) ); // The page, without the list of existing titles.
pnt_same( 'done', $r['status'], 'web page: read' );
pnt_check( false !== strpos( $txt, 'κλειδάριθμο' ) && false === strpos( $txt, 'ΚΡΥΦΟ' ) && false === strpos( $txt, 'Επικοινωνία' ) && false === strpos( $txt, 'Copyright' ), 'web page: main text sent, without scripts, menu and footer' );
pnt_check( false !== strpos( $txt, 'Οδηγός eΔΑΠΥ' ), 'web page: its title is sent' );
$p = PNChat_Learn::pending();
$p = end( $p );
pnt_check( false !== strpos( $p['entry']['answer'], 'https://example.test/odigos#b' ) && false === strpos( $p['entry']['answer'], 'evil.example' ), 'web page: links to the source site kept, others taken off' );

// ---- a PDF address -----------------------------------------------------------------------
$pdf                                     = "%PDF-1.4\n% fake test pdf\n" . str_repeat( 'x', 100 );
$web['https://example.test/manual.pdf'] = array( 'application/pdf', $pdf );
$reply                                   = array( $entry( 'Πιστοποίηση παρόχου', array( 'Πώς γίνεται η πιστοποίηση παρόχου στο eΔΑΠΥ;' ) ) );
PNChat_Learn::enqueue( array( array( 'type' => 'url', 'url' => 'https://example.test/manual.pdf' ) ), 'pdf' );
$r     = pnt_learn_step();
$req   = end( $sent );
$block = $req['messages'][0]['content'][0] ?? array();
pnt_same( 'done', $r['status'], 'PDF address: read' );
pnt_same( array( 'document', 'base64', 'application/pdf', base64_encode( $pdf ) ), array( $block['type'] ?? '', $block['source']['type'] ?? '', $block['source']['media_type'] ?? '', $block['source']['data'] ?? '' ), 'PDF address: sent as a document block' );
pnt_same( 'text', $req['messages'][0]['content'][1]['type'] ?? '', 'PDF: the instructions follow the document' );
pnt_same( 24000, $req['max_tokens'] ?? null, 'PDF: max_tokens 24000' );
pnt_check( false !== strpos( (string) $req['system'], 'έως 25 γνώσεις' ), 'PDF: up to 25 entries' );

// ---- 1.12.0: PDFs of the Media Library ---------------------------------------------------
$up   = wp_upload_dir();
$mfile = trailingslashit( $up['path'] ) . 'pnt-media-' . wp_generate_password( 6, false ) . '.pdf';
file_put_contents( $mfile, $pdf );
pnt_settings( array( 'ai_learn_media' => 0 ) );
$mid = wp_insert_attachment(
	array(
		'post_title'     => 'Απόφαση ΕΟΦ PNT',
		'post_mime_type' => 'application/pdf',
		'post_status'    => 'inherit',
	),
	$mfile
);
pnt_defer(
	function () use ( $mid ) {
		wp_delete_attachment( $mid, true );
	}
);
pnt_check( ! in_array( 'media:' . $mid, array_map( fn( $j ) => ( $j['type'] ?? '' ) . ':' . ( $j['id'] ?? '' ), PNChat_Learn::queue() ), true ), 'media: with «Νέα PDF» off, an upload is not read' );
pnt_check( in_array( $mid, PNChat_Learn::media_pdfs(), true ), 'media: the PDF is listed' );
pnt_check( ! PNChat_Learn::media_read( $mid ), 'media: not read yet' );
pnt_settings( array( 'ai_learn_media' => 1 ) );
$mid2 = wp_insert_attachment(
	array(
		'post_title'     => 'Εικόνα PNT',
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
	),
	$mfile
);
pnt_defer(
	function () use ( $mid2 ) {
		wp_delete_post( $mid2, true );
	}
);
pnt_same( array(), array_values( array_filter( PNChat_Learn::queue(), fn( $j ) => 'media' === ( $j['type'] ?? '' ) ) ), 'media: an image is never read' );
do_action( 'add_attachment', $mid );
$q = array_values( array_filter( PNChat_Learn::queue(), fn( $j ) => 'media' === ( $j['type'] ?? '' ) ) );
pnt_same( array( array( 'type' => 'media', 'id' => $mid ) ), $q, 'media: with «Νέα PDF» on, an uploaded PDF is queued' );
pnt_same( 'PDF: Απόφαση ΕΟΦ PNT', PNChat_Learn::job_label( $q[0] ), 'media: its label' );
pnt_same( 0, PNChat_Learn::enqueue_media( array( $mid ), false, 'x' ), 'media: not queued twice' );
$reply = array( $entry( 'Διάρκεια απαγόρευσης PNT', array( 'Πόσο διαρκεί η απαγόρευση PNT;' ) ) );
$r     = pnt_learn_step();
$req   = end( $sent );
$block = $req['messages'][0]['content'][0] ?? array();
pnt_same( 'done', $r['status'], 'media: read' );
pnt_same( array( 'document', base64_encode( $pdf ) ), array( $block['type'] ?? '', $block['source']['data'] ?? '' ), 'media: the file is sent as a document' );
pnt_check( file_exists( $mfile ), 'media: the file stays in the Media Library after reading' );
pnt_check( PNChat_Learn::media_read( $mid ), 'media: marked as read' );
$p = PNChat_Learn::pending();
$p = end( $p );
pnt_check( false !== strpos( $p['entry']['answer'], esc_url( wp_get_attachment_url( $mid ) ) ), 'media: the proposal links to the PDF' );
pnt_same( 0, PNChat_Learn::enqueue_media( array( $mid ), false, 'x' ), 'media: a read PDF is not queued again' );
file_put_contents( $mfile, $pdf . 'changed' );
pnt_check( ! PNChat_Learn::media_read( $mid ), 'media: a changed file counts as not read' );
pnt_same( 1, PNChat_Learn::enqueue_media( array( $mid ), true, 'x' ), '«Διάβασε ξανά»: queued' );
delete_option( PNChat_Learn::QUEUE );
file_put_contents( $mfile, 'not a pdf' );
pnt_check( is_wp_error( PNChat_Learn::media_data( $mid ) ), 'media: a file that is not a PDF is refused' );
pnt_check( is_wp_error( PNChat_Learn::media_data( $existing ) ), 'media: an id that is not a PDF attachment is refused' );
file_put_contents( $mfile, $pdf );
pnt_settings( array( 'ai_learn_media' => 0 ) );

// ---- an uploaded PDF -----------------------------------------------------------------------
$tmp = wp_tempnam( 'pnt' );
file_put_contents( $tmp, $pdf );
$job = PNChat_Learn::store_upload( $tmp, 'Εγχειρίδιο eΔΑΠΥ.pdf' );
pnt_check( is_array( $job ) && file_exists( PNChat_Learn::dir() . '/' . $job['file'] ), 'upload: stored until read' );
pnt_check( file_exists( PNChat_Learn::dir() . '/.htaccess' ) && file_exists( PNChat_Learn::dir() . '/index.php' ), 'upload: the folder is closed to the web' );
file_put_contents( $tmp, 'not a pdf' );
pnt_check( is_wp_error( PNChat_Learn::store_upload( $tmp, 'x.pdf' ) ), 'upload: a file that is not a PDF is refused' );
wp_delete_file( $tmp );
$reply = array( $entry( 'Δημιουργία χρήστη eΔΑΠΥ', array( 'Πώς φτιάχνω χρήστη στο eΔΑΠΥ;' ) ) );
PNChat_Learn::enqueue( array( $job ), $job['name'] );
$r   = pnt_learn_step();
$req = end( $sent );
pnt_same( 'done', $r['status'], 'upload: read' );
pnt_same( base64_encode( $pdf ), $req['messages'][0]['content'][0]['source']['data'] ?? '', 'upload: the file is sent as a document' );
pnt_check( ! file_exists( PNChat_Learn::dir() . '/' . $job['file'] ), 'upload: the file is deleted once read' );

// ---- errors give the month's use back when Claude was never asked ---------------------------
$used = PNChat_Counter::get( $month );
$web['https://example.test/missing'] = array( 'text/html', 'Not found', 404 );
PNChat_Learn::enqueue( array( array( 'type' => 'url', 'url' => 'https://example.test/missing' ) ), 'missing' );
$n = count( $sent );
$r = pnt_learn_step();
pnt_same( 'error', $r['status'], 'HTTP 404: error' );
pnt_same( array( $used, $n ), array( PNChat_Counter::get( $month ), count( $sent ) ), 'HTTP 404: Claude not asked, the use given back' );
$errors = PNChat_Learn::state()['errors'];
pnt_check( false !== strpos( (string) end( $errors )['error'], '404' ), 'HTTP 404: listed with the reason' );

$fail = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
PNChat_Learn::enqueue( array( array( 'type' => 'url', 'url' => 'https://example.test/odigos' ) ), 'again' );
$r = pnt_learn_step();
pnt_same( array( 'error', $used ), array( $r['status'], PNChat_Counter::get( $month ) ), 'no connection to Claude: error, the use given back' );
$fail = null;

// ---- batches -------------------------------------------------------------------------------
$req = end( $sent );
$hdr = end( $headers );
pnt_check( ! isset( $req['fallbacks'] ) && ! isset( $hdr['anthropic-beta'] ) && 'json_schema' === ( $req['output_config']['format']['type'] ?? '' ), 'batch: structured output, no fallbacks (the Batches API rejects them)' );
$direct = PNChat_AI::body( 'σύστημα', 'ερώτηση', array( 'type' => 'object' ) );
pnt_check( 'default' === ( $direct['fallbacks'] ?? '' ) && 'medium' === ( $direct['output_config']['effort'] ?? '' ), 'direct calls (AI in the chat) keep server-side fallbacks and effort' );
$haiku = PNChat_AI::body( 'σύστημα', 'ερώτηση', array( 'type' => 'object' ), 'claude-haiku-4-5' );
pnt_check( ! isset( $haiku['fallbacks'] ) && ! isset( $haiku['output_config']['effort'] ), 'Claude Haiku 4.5: no fallbacks, no effort' );
$usage = PNChat_AI::usage();
$hold  = true;
PNChat_Learn::enqueue( array( array( 'type' => 'url', 'url' => 'https://example.test/manual.pdf' ) ), 'held' );
pnt_same( 'submitted', PNChat_Learn::process_next()['status'], 'batch: the source is sent in one step' );
pnt_same( 0, count( PNChat_Learn::queue() ), 'batch: it left the queue' );
pnt_same( 'waiting', PNChat_Learn::process_next()['status'], 'batch: while Claude reads, the step only checks' );
pnt_same( 1, count( PNChat_Learn::sent() ), 'batch: still waiting' );
wp_clear_scheduled_hook( PNChat_Learn::CRON );
PNChat_Learn::schedule();
$next = (int) wp_next_scheduled( PNChat_Learn::CRON );
pnt_check( $next > time() + 30, 'batch: the next check is in a minute, not seconds' );
$hold = false;
$r    = PNChat_Learn::process_next();
pnt_same( array( 0, 'done' ), array( count( PNChat_Learn::sent() ), $r['status'] ), 'batch: collected once Claude ended' );
$after = PNChat_AI::usage();
pnt_same( 3000, (int) ( $after['micro_usd'] ?? 0 ) - (int) ( $usage['micro_usd'] ?? 0 ), 'batch: cost counted at half price (1000 in + 100 out on Opus 5.5 = $0.006 → $0.003)' );

$used    = PNChat_Counter::get( $month );
$outcome = 'errored';
$reply   = array( $entry( 'Κάτι νέο 1', array( 'Ερώτηση νέα 1;' ) ) );
PNChat_Learn::enqueue( array( array( 'type' => 'url', 'url' => 'https://example.test/odigos' ) ), 'errored' );
$r = pnt_learn_step();
pnt_same( array( 'error', $used ), array( $r['status'], PNChat_Counter::get( $month ) ), 'batch errored: error, not charged so the use is given back' );
pnt_check( false !== strpos( $r['error'], 'fake batch error' ), 'batch errored: the reason is shown' );
$outcome = 'expired';
PNChat_Learn::enqueue( array( array( 'type' => 'url', 'url' => 'https://example.test/odigos' ) ), 'expired' );
$r = pnt_learn_step();
pnt_check( 'error' === $r['status'] && false !== strpos( $r['error'], '24 ώρες' ), 'batch expired: said so' );
$outcome = 'succeeded';

// A step the server killed half-way is reported.
$st            = PNChat_Learn::state();
$st['current'] = array(
	'label' => 'odigies.pdf',
	'at'    => time() - HOUR_IN_SECONDS,
);
update_option( PNChat_Learn::STATE, $st, false );
PNChat_Learn::enqueue( array( array( 'type' => 'url', 'url' => 'https://example.test/odigos' ) ), 'after' );
pnt_learn_step();
$errors = PNChat_Learn::state()['errors'];
$labels = array_column( $errors, 'source' );
pnt_check( in_array( 'odigies.pdf', $labels, true ) && array() === PNChat_Learn::state()['current'], 'interrupted step: listed as not read, with the reason (1.9.0 showed «0 από 1» and nothing else)' );

// At most three sources wait at Claude.
$hold = true;
foreach ( array( 'a', 'b', 'c', 'd' ) as $x ) {
	PNChat_Learn::enqueue( array( array( 'type' => 'url', 'url' => 'https://example.test/odigos?' . $x ) ), $x );
	$web[ 'https://example.test/odigos?' . $x ] = $web['https://example.test/odigos'];
	PNChat_Learn::process_next();
}
pnt_same( array( 3, 1 ), array( count( PNChat_Learn::sent() ), count( PNChat_Learn::queue() ) ), 'batch: at most 3 at Claude, the rest wait' );
$cancels = array();
pnt_same( 4, PNChat_Learn::stop(), 'stop: queued and sent sources dropped' );
pnt_same( 3, count( $cancels ), 'stop: the batches at Claude are cancelled' );
$hold = false;

// ---- monthly limit, lock, AI off, stop -----------------------------------------------------
pnt_settings( array( 'ai_learn_monthly' => PNChat_Counter::get( $month ) ) );
PNChat_Learn::enqueue( array( array( 'type' => 'url', 'url' => 'https://example.test/odigos' ) ), 'limit' );
$n = count( $sent );
$r = PNChat_Learn::process_next();
pnt_same( array( 'limit', 1, 'limit', $n ), array( $r['status'], count( PNChat_Learn::queue() ), PNChat_Learn::state()['paused'], count( $sent ) ), 'monthly limit: stops, the source keeps waiting, Claude not asked' );
pnt_settings( array( 'ai_learn_monthly' => 200 ) );

PNChat_Counter::add( 'learn_lock', 1, 900 );
pnt_same( 'busy', PNChat_Learn::process_next()['status'], 'lock: a second reader waits' );
PNChat_Counter::give_back( 'learn_lock' );

pnt_settings( array( 'ai_enabled' => 0 ) );
pnt_same( 'off', PNChat_Learn::process_next()['status'], 'AI off: nothing is read' );
pnt_settings( array( 'ai_enabled' => 1 ) );

$tmp = wp_tempnam( 'pnt' );
file_put_contents( $tmp, $pdf );
$job2 = PNChat_Learn::store_upload( $tmp, 'b.pdf' );
wp_delete_file( $tmp );
PNChat_Learn::enqueue( array( $job2 ), 'b' );
pnt_same( 2, PNChat_Learn::stop(), 'stop: the waiting sources are dropped' );
pnt_check( ! PNChat_Learn::queue() && ! file_exists( PNChat_Learn::dir() . '/' . $job2['file'] ) && ! wp_next_scheduled( PNChat_Learn::CRON ), 'stop: queue, uploaded file and schedule gone' );

// ---- weekly --------------------------------------------------------------------------------
pnt_settings( array( 'ai_learn_weekly' => 1 ) );
PNChat_Learn::sync_weekly();
pnt_check( false !== wp_next_scheduled( PNChat_Learn::WEEKLY ), 'weekly: scheduled when switched on' );
PNChat_Learn::weekly();
pnt_check( in_array( 'Οδηγός ΤεστTool', array_map( array( 'PNChat_Learn', 'job_label' ), PNChat_Learn::queue() ), true ), 'weekly: changed pages queued' );
PNChat_Learn::stop();
pnt_settings( array( 'ai_learn_weekly' => 0 ) );
PNChat_Learn::sync_weekly();
pnt_check( false === wp_next_scheduled( PNChat_Learn::WEEKLY ), 'weekly: cleared when switched off' );

// ---- approve, merge, reject (admin handlers) ---------------------------------------------------
$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );
add_filter(
	'wp_redirect',
	function () {
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
$find = function ( $title ) {
	foreach ( PNChat_Learn::pending( 1, 100 ) as $p ) {
		if ( $p['entry']['title'] === $title ) {
			return $p;
		}
	}
	return null;
};
$p = $find( 'Εγγραφή στο ΤεστTool' );
pnt_admin_post(
	'proposal',
	array(
		'id'        => (string) $p['id'],
		'do'        => 'approve',
		'title'     => 'Εγγραφή φαρμακείου στο ΤεστTool',
		'phrasings' => "Πώς κάνω εγγραφή στο ΤεστTool;\nΠώς γράφομαι στο ΤεστTool;",
		'keywords'  => 'ΑΦΜ εγγραφής',
		'answer'    => '<p>Συμπληρώνετε το ΑΦΜ και πατάτε Αποστολή.</p>',
	)
);
$p   = PNChat_Learn::proposal( (int) $p['id'] );
$new = PNChat_Store::entry( (int) $p['entry_id'] );
pnt_same( array( 'approved', 'Εγγραφή φαρμακείου στο ΤεστTool' ), array( $p['status'], $new['title'] ?? '' ), 'approve: becomes an entry, with the edits' );
wp_set_current_user( 0 );
list( , $a ) = pnt_rest( '/ask', array( 'question' => 'Πώς κάνω εγγραφή στο ΤεστTool;' ) );
pnt_same( 'answered', $a['status'] ?? '', 'chat: answered after approval (without AI)' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', $ct, 'rl:%' ) );
wp_set_current_user( $admin->ID );

$p = $find( 'Τιμή ΤεστTool' );
pnt_admin_post(
	'proposal',
	array(
		'id'        => (string) $p['id'],
		'do'        => 'merge',
		'phrasings' => "Πόσο κοστίζει το ΤεστTool;\nΤι τιμή έχει το ΤεστTool;",
	)
);
$target = PNChat_Store::entry( (int) $existing );
pnt_same( array( 'Πόσο κοστίζει το ΤεστTool;', 'Είναι δωρεάν το ΤεστTool;', 'Τι τιμή έχει το ΤεστTool;' ), $target['phrasings'], 'merge: new questions added to the similar entry, no duplicates' );
pnt_same( 'merged', PNChat_Learn::proposal( (int) $p['id'] )['status'], 'merge: proposal closed' );

$p = $find( 'Υποβολή στο eΔΑΠΥ' );
pnt_admin_post( 'proposal', array( 'id' => (string) $p['id'], 'do' => 'reject' ) );
pnt_same( 'rejected', PNChat_Learn::proposal( (int) $p['id'] )['status'], 'reject: closed' );
$src = array(
	'title' => 'x',
	'url'   => '',
);
pnt_same( 0, PNChat_Learn::add_proposal( PNChat_Brain::clean_entry( $entry( 'Υποβολή στο eΔΑΠΥ', array( 'Πώς κάνω υποβολή;' ) ) ), $src ), 'reject: the same title is not proposed again' );

$left  = PNChat_Learn::pending( 1, 100 );
$ids   = array_map( fn( $p ) => (string) $p['id'], $left );
$count = count( PNChat_Store::entries() );
pnt_admin_post( 'proposals_bulk', array( 'ids' => $ids, 'do' => 'approve' ) );
pnt_same( array( 0, $count + count( $ids ) ), array( PNChat_Learn::count_pending(), count( PNChat_Store::entries() ) ), 'bulk approve: all ticked become entries' );

// ---- matcher: short keywords only as they are ------------------------------------------------
$mm = new PNChat_Matcher(
	array(
		array(
			'id'        => 1,
			'kind'      => 'answer',
			'title'     => 'ΠΕΔΙ',
			'phrasings' => array( 'Πού δηλώνω το σημείο υποβολής;' ),
			'keywords'  => array( 'ΠΕΔΙ', 'κλειδάριθμος' ),
			'answer'    => 'x',
		),
	)
);
pnt_check( 'answered' !== $mm->ask( 'παρακεταμόλη για παιδιά' )['status'], 'matcher: the keyword «ΠΕΔΙ» does not catch «παιδιά» (it did up to 1.8.3)' );
pnt_same( 'answered', $mm->ask( 'Τι είναι το ΠΕΔΙ;' )['status'], 'matcher: «ΠΕΔΙ» itself still found' );
pnt_same( 'answered', $mm->ask( 'έχασα τον κλειδάριθμο' )['status'], 'matcher: longer keywords still match other endings' );

$_POST = array();
$_REQUEST = array();
pnt_done();
