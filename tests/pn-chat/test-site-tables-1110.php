<?php
/**
 * 1.11.0: the site search reads what a page shows: shortcodes and blocks
 * run, a table row is one line («Aerolin · Salbutamol · R03AC02»), the data
 * a script turns into a table counts too. «Είναι το Aerolin στη λίστα;»
 * gets the row and the page; a product no page has gets «Όχι» when the
 * question is about a page with a table; the home page gives way to the
 * page about the subject, whose opening is shown.
 */
require __DIR__ . '/lib.php';
pnt_defer( 'pnt_clear_questions' );
wp_set_current_user( 0 );
pnt_keep_entries();
pnt_settings(
	array(
		'site_search' => 1,
		'site_max'    => 3,
		'site_types'  => 'page',
		'synonyms'    => '',
		'topics'      => '',
		'didyoumean'  => 0,
		'ai_chat'     => 0,
	)
);
PNChat_Topics::reset();
PNChat_Store::replace_entries(
	array(
		array(
			'kind'      => 'answer',
			'title'     => 'Λίστα απαγόρευσης εξαγωγής φαρμάκων',
			'phrasings' => array( 'Ποια είναι η λίστα απαγόρευσης εξαγωγής φαρμάκων;' ),
			'keywords'  => array(),
			'answer'    => '<p>Δείτε την ανακοίνωση στην αρχική σελίδα.</p>',
			'active'    => 1,
		),
		array(
			'kind'      => 'answer',
			'title'     => 'Εισπνεόμενα στην απαγόρευση',
			'phrasings' => array( 'Ποια εισπνεόμενα είναι στην απαγόρευση;' ),
			'keywords'  => array(),
			'answer'    => '<p>Seretide και Symbicort.</p>',
			'active'    => 1,
		),
	)
);
PNChat_Brain::matcher( true );

// Pages (as an administrator: the script tag must survive).
add_shortcode(
	'pnt_ban_table',
	function () {
		$rows = array( array( 'Aerolin', 'Salbutamol', 'R03AC02', 'GSK' ), array( 'Seretide', 'Salmeterol', 'R03AK06', 'GSK' ), array( 'Xalatan', 'Latanoprost', 'S01EE01', 'Pfizer' ) );
		for ( $i = 1; $i <= 12; $i++ ) {
			$rows[] = array( 'Σκεύασμα' . $i, 'Ουσία' . $i, 'Q0' . $i, 'Εταιρεία' . $i );
		}
		$h = '<table><tr><th>Όνομα</th><th>Δραστική</th><th>ATC</th><th>Εταιρεία</th></tr>';
		foreach ( $rows as $r ) {
			$h .= '<tr><td>' . implode( '</td><td>', $r ) . '</td></tr>';
		}
		echo '<p>Τυπώθηκε από το shortcode.</p>';
		return $h . '</table>';
	}
);
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
kses_remove_filters();
$made  = array();
$page = function ( $title, $content ) use ( &$made ) {
	$id     = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		)
	);
	$made[] = $id;
	return $id;
};
pnt_defer(
	function () use ( &$made ) {
		foreach ( $made as $id ) {
			wp_delete_post( $id, true );
		}
	}
);
$front_before = array( get_option( 'show_on_front' ), get_option( 'page_on_front' ) );
pnt_defer(
	function () use ( $front_before ) {
		update_option( 'show_on_front', $front_before[0] );
		update_option( 'page_on_front', $front_before[1] );
	}
);
$home  = $page( 'Αρχική PNT', '<p>27 Αυγ 2026</p><h3>Νέα λίστα απαγόρευσης εξαγωγής φαρμάκων ζζπντ</h3><p>Δείτε τη λίστα→</p><h3>Στηρίζουμε το Χαμόγελο του Ζζπντ</h3>' );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home );
$ban   = $page( 'Απαγόρευση εξαγωγών φαρμάκων ζζπντ Αύγουστος', "<!-- wp:paragraph --><p>Νέα λίστα φαρμάκων που δεν εξάγονται.</p><!-- /wp:paragraph -->\n[pnt_ban_table]" );
$data  = $page( 'Ελλείψεις ζζπντ', '<p>Φάρμακα σε έλλειψη.</p><div id="t"></div><script>var ROWS = [{"name":"Ozempicpnt","atc":"A10BJ06","company":"Novo"},{"name":"Trulicitypnt","atc":"A10BJ05","company":"Lilly"},{"name":"Ασπιρίνηπντ","atc":"B01AC06","company":"Bayer"},{"name":"Euthyroxpnt","atc":"H03AA01","company":"Merck"},{"name":"Lyricapnt","atc":"N03AX16","company":"Pfizer"}]; var cfg = {"mode":"table-view","url":"https://example.org/x"};</script>' );
$smile = $page( 'Στηρίζουμε το Χαμόγελο του Ζζπντ', '<p>Χαμόγελο του Ζζπντ</p><p>Το Χαμόγελο του Ζζπντ είναι οργανισμός που στηρίζει παιδιά και οικογένειες σε όλη τη χώρα. Με κάθε παραγγελία προσφέρουμε ένα ποσό.</p><table><tr><td>Γραμμή</td><td>1056</td></tr></table>' );
kses_init_filters();
wp_set_current_user( 0 );
foreach ( $made as $id ) {
	PNChat_Site_Search::index_post( $id );
}
$GLOBALS['post'] = null;

// Reading.
$text = PNChat_Site_Search::plain_text( get_post( $ban ) );
pnt_check( false !== strpos( $text, 'Aerolin · Salbutamol · R03AC02 · GSK' ), 'reading: a shortcode table is read, one row per line with « · »' );
pnt_check( false !== strpos( $text, 'Τυπώθηκε από το shortcode.' ), 'reading: what a shortcode prints is read too' );
pnt_check( false !== strpos( $text, 'Νέα λίστα φαρμάκων' ) && false === strpos( $text, 'wp:paragraph' ), 'reading: blocks are rendered, their comments left out' );
pnt_same( 16, count( PNChat_Site_Search::rows_of( get_post( $ban ) ) ), 'reading: rows_of() gives the table rows (header + 15)' );
pnt_same( $text, get_post_meta( $ban, PNChat_Site_Search::META_TEXT, true ), 'reading: the text is kept with the index' );
pnt_check( (int) get_post_meta( $ban, PNChat_Site_Search::META_READ, true ) > time() - 60, 'reading: the time it was read is kept' );
pnt_check( null === $GLOBALS['post'], 'reading: the current post is put back after the shortcodes ran' );
pnt_check( is_callable( $page ), 'reading: WordPress globals the shortcodes change ($page) are put back' );
$rows = PNChat_Site_Search::rows_of( get_post( $data ) );
pnt_check( in_array( 'Ozempicpnt · A10BJ06 · Novo', $rows, true ), 'reading: data a script turns into a table becomes rows' );
pnt_check( in_array( 'Ασπιρίνηπντ · B01AC06 · Bayer', $rows, true ), 'reading: \\u escapes in the data are decoded' );
pnt_check( 0 === count( preg_grep( '/table-view|example\.org/', $rows ) ), 'reading: a script\'s settings are not rows' );
pnt_same( 5, count( $rows ), 'reading: five records, five rows' );
$plain = PNChat_Site_Search::plain_text( get_post( $smile ) );
pnt_check( false !== strpos( $plain, 'Γραμμή · 1056' ), 'reading: an inline HTML table is read the same way' );

// Stale text: a page changed without save_post (an import) is read again.
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2030-01-01 00:00:00' ), array( 'ID' => $smile ) );
clean_post_cache( $smile );
add_filter( 'pnchat_site_render', '__return_false' );
pnt_check( false !== strpos( PNChat_Site_Search::plain_text( get_post( $smile ) ), 'οργανισμός' ), 'reading: a page changed since it was read is read again, not taken from the meta' );
remove_filter( 'pnchat_site_render', '__return_false' );

// Lookup in the chat.
list( $code, $d ) = pnt_rest( '/ask', array( 'question' => 'ποια είναι η νέα λίστα απαγόρευσης; περιέχει το aerolin;' ) );
$html             = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_same( 200, $code, 'chat: asked' );
pnt_check( false !== strpos( $html, 'Ναι — το «Aerolin» υπάρχει στη σελίδα' ) && false !== strpos( $html, 'Aerolin · Salbutamol · R03AC02 · GSK' ), 'chat: «περιέχει το aerolin;» → «Ναι» with the row of the table' );
pnt_check( false !== strpos( $html, esc_url( get_permalink( $ban ) ) ), 'chat: with the link of the page' );
pnt_same( 'site', $d['items'][0]['kind'], 'chat: the row comes first, before the trained answer' );

list( , $d ) = pnt_rest( '/ask', array( 'question' => 'ποια ειναι η λιστα απαγορευσης εξαγωγων ζζπντ ? το fortimel ειναι ?' ) );
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false !== strpos( $html, 'Όχι — το «Fortimel» δεν υπάρχει στη σελίδα' ) && false !== strpos( $html, 'Απαγόρευση εξαγωγών φαρμάκων ζζπντ Αύγουστος' ), 'chat: a product no page has, asked about the list → «Όχι», naming the list page' );
pnt_check( false !== strpos( $html, '16 γραμμές' ), 'chat: says how many rows were checked' );

list( , $d ) = pnt_rest( '/ask', array( 'question' => 'Είναι το aerolim στην απαγόρευση;' ) );
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false !== strpos( $html, '«Aerolin»' ) && false !== strpos( $html, 'R03AC02' ), 'chat: a typo («aerolim») finds the row, named as the page writes it' );

list( , $d ) = pnt_rest( '/ask', array( 'question' => 'R03AK06' ) );
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false !== strpos( $html, 'Seretide' ) && false === strpos( $html, 'Aerolin' ), 'chat: a code matches only exactly (R03AK06 is not R03AC02)' );
pnt_same( 'site', $d['status'], 'chat: an answer from a table counts as answered from the site' );

list( , $d ) = pnt_rest( '/ask', array( 'question' => 'Είναι το Seretide στην απαγόρευση εισπνεόμενα;' ) );
$kinds       = array_column( $d['items'], 'kind' );
pnt_check( ! in_array( 'site', $kinds, true ), 'chat: a name the trained answer already gives is not looked up again' );

list( , $d ) = pnt_rest( '/ask', array( 'question' => 'υπάρχει το Lyricapnt σε έλλειψη;' ) );
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false !== strpos( $html, 'Lyricapnt · N03AX16 · Pfizer' ), 'chat: a row from a script\'s data is found' );

list( , $d ) = pnt_rest( '/ask', array( 'question' => 'Θέλω να δω τη σελίδα για iphonepnt' ) );
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false === strpos( $html, 'Όχι — ' ), 'chat: no «Όχι» when the question is not about a page with a table' );

// 1.12.1: a conversation about the list. «Το Fortimel είναι;» on its own
// names no list: it is looked up in the page the conversation is about.
list( , $d ) = pnt_rest( '/ask', array( 'question' => 'το fortimel ειναι;' ) );
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false === strpos( $html, 'Όχι — ' ), 'conversation: without context, no «Όχι» for a bare «το fortimel είναι;»' );
list( , $d ) = pnt_rest( '/ask', array( 'question' => 'ποια ειναι η λιστα απαγορευσης εξαγωγων ζζπντ' ) );
pnt_same( $ban, $d['sctx'] ?? 0, 'conversation: an answer about the list gives its page as context (sctx)' );
$list_entry  = (int) ( $d['entry'] ?? 0 );
list( , $d ) = pnt_rest(
	'/ask',
	array(
		'question' => 'το fortimel ειναι;',
		'sctx'     => $ban,
		'prev'     => $list_entry,
	)
);
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false !== strpos( $html, 'Όχι — το «Fortimel» δεν υπάρχει στη σελίδα' ) && false !== strpos( $html, 'ζζπντ Αύγουστος' ), 'conversation: «το fortimel είναι;» after the list → «Όχι», in that list' );
pnt_same( $ban, $d['sctx'] ?? 0, 'conversation: the list stays the context' );
pnt_same( '', $d['intro'] ?? null, 'conversation: no «Δεν έχω έτοιμη απάντηση» before a table answer' );
list( , $d ) = pnt_rest( '/ask', array( 'question' => 'και το xalatan;', 'sctx' => $ban ) );
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false !== strpos( $html, 'Ναι — το «Xalatan» υπάρχει' ) && false !== strpos( $html, 'Xalatan · Latanoprost' ), 'conversation: «και το xalatan;» → «Ναι» and its row' );
list( , $d ) = pnt_rest( '/ask', array( 'question' => 'το fortimel ειναι;', 'prev' => $list_entry ) );
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false !== strpos( $html, 'Όχι — το «Fortimel»' ), 'conversation: the previous trained answer alone also gives the list (its page by title)' );
list( , $d ) = pnt_rest( '/ask', array( 'question' => 'το fortimel ειναι;', 'sctx' => $smile ) );
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false === strpos( $html, 'Όχι — ' ), 'conversation: a context page without a table gives no «Όχι»' );

// 1.13.1: two lists of the same subject. The newest goes first, a month the
// question names wins, a name only in the older list says so.
wp_set_current_user( $admin->ID );
$old_list = '<p>Η λίστα του Μαΐου.</p><table><tr><th>Όνομα</th><th>ATC</th></tr><tr><td>Aerolin</td><td>R03AC02</td></tr><tr><td>Lantuspnt</td><td>A10AE04</td></tr>';
for ( $i = 1; $i <= 10; $i++ ) {
	$old_list .= '<tr><td>Παλιό' . $i . '</td><td>P' . $i . '</td></tr>';
}
$may = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Απαγόρευση εξαγωγών φαρμάκων ζζπντ Μάιος',
		'post_content' => $old_list . '</table>',
		'post_date'    => '2026-05-20 10:00:00',
	)
);
$made[] = $may;
wp_update_post(
	array(
		'ID'        => $ban,
		'post_date' => '2026-08-27 10:00:00',
	)
);
wp_set_current_user( 0 );
PNChat_Site_Search::index_post( $may );
PNChat_Site_Search::index_post( $ban );
list( , $d ) = pnt_rest( '/ask', array( 'question' => 'ειναι το fortimel στην απαγορευση εξαγωγων ζζπντ;' ) );
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false !== strpos( $html, 'Όχι — το «Fortimel» δεν υπάρχει στη σελίδα <strong>«Απαγόρευση εξαγωγών φαρμάκων ζζπντ Αύγουστος»' ), 'two lists: «Όχι» about the newest one' );
list( , $d ) = pnt_rest( '/ask', array( 'question' => 'ειναι το fortimel στην απαγορευση εξαγωγων ζζπντ του Μαΐου;' ) );
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false !== strpos( $html, 'σελίδα <strong>«Απαγόρευση εξαγωγών φαρμάκων ζζπντ Μάιος»' ), 'two lists: «…του Μαΐου» → the list of May' );
list( , $d ) = pnt_rest( '/ask', array( 'question' => 'ειναι το Lantuspnt στην απαγορευση εξαγωγων ζζπντ;' ) );
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false !== strpos( $html, 'Όχι — το «Lantuspnt» δεν υπάρχει στη σελίδα <strong>«Απαγόρευση εξαγωγών φαρμάκων ζζπντ Αύγουστος»' ) && false !== strpos( $html, 'Υπήρχε στην παλαιότερη σελίδα <strong>«Απαγόρευση εξαγωγών φαρμάκων ζζπντ Μάιος»' ), 'two lists: only in the older one → «Όχι» for the newest, «Υπήρχε στην παλαιότερη»' );
pnt_check( false === strpos( $html, 'Ναι — ' ), 'two lists: no «Ναι» for a name only in the older list' );
list( , $d ) = pnt_rest( '/ask', array( 'question' => 'ειναι το Lantuspnt στην απαγορευση εξαγωγων ζζπντ του Μαΐου;' ) );
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false !== strpos( $html, 'Ναι — το «Lantuspnt» υπάρχει στη σελίδα <strong>«Απαγόρευση εξαγωγών φαρμάκων ζζπντ Μάιος»' ), 'two lists: asked about May, found in May → «Ναι»' );
list( , $d ) = pnt_rest( '/ask', array( 'question' => "27 Αυγ 2026\nΝέα λίστα απαγόρευσης εξαγωγής φαρμάκων ζζπντ\nσε αυτην την λιστα ειναι το aerolin ?" ) );
$html        = implode( ' ', array_column( $d['items'], 'html' ) );
pnt_check( false !== strpos( $html, 'Ναι — το «Aerolin» υπάρχει στη σελίδα <strong>«Απαγόρευση εξαγωγών φαρμάκων ζζπντ Αύγουστος»' ), 'pasted «27 Αυγ 2026 …»: the August list first' );
pnt_check( false === strpos( (string) $d['message'], 'δεν έχουμε πληροφορίες' ), 'pasted text: the leftover words get no «δεν έχουμε πληροφορίες»' );
$m = new ReflectionMethod( 'PNChat_Site_Search', 'months' );
$m->setAccessible( true );
pnt_same( array( array( 5 ), array( 5 ), array( 8 ), array( 8 ), array() ), array( $m->invoke( null, 'Μάιος' ), $m->invoke( null, 'του Μαΐου' ), $m->invoke( null, '27 Αυγ' ), $m->invoke( null, 'Αυγούστου' ), $m->invoke( null, 'δεκάδες φάρμακα' ) ), 'months: any form of a month, not words that start like one' );

// Subject pages: the home page gives way, the opening is shown.
$r = PNChat_Site_Search::search( 'τι είναι το χαμόγελο του ζζπντ', 3 );
pnt_same( $smile, $r ? $r[0]['id'] : 0, 'search: the page about the subject comes before the home page' );
pnt_check( $r && 0 === strpos( $r[0]['snippet'], 'Το Χαμόγελο του Ζζπντ είναι οργανισμός' ), 'search: its opening sentences are shown, not a heading' );
pnt_check( ! in_array( $home, array_column( $r, 'id' ), true ), 'search: the home page (one line about it) is left out' );
$look = PNChat_Site_Search::lookup( 'τι είναι το χαμόγελο του ζζπντ' );
pnt_same( array(), $look['found'], 'lookup: a word in a page title is a subject, not a row to look up' );

// Re-reading.
update_post_meta( $ban, PNChat_Site_Search::META_READ, 1000 );
pnt_same( 1, PNChat_Site_Search::refresh_some( 2000, 10 ), 'refresh: a page unread since before the time is read again' );
pnt_check( (int) get_post_meta( $ban, PNChat_Site_Search::META_READ, true ) > 2000, 'refresh: its read time is updated' );
wp_clear_scheduled_hook( PNChat_Site_Search::REFRESH );
PNChat_Site_Search::request_refresh();
pnt_check( (bool) wp_next_scheduled( PNChat_Site_Search::REFRESH ) && (int) get_option( PNChat_Site_Search::REFRESH ) > 0, 'refresh: a request is scheduled in the background' );
update_option( PNChat_Site_Search::REFRESH, time() + 5, false );
PNChat_Site_Search::run_refresh();
pnt_check( false === get_option( PNChat_Site_Search::REFRESH ), 'refresh: when every page is read again the request ends' );
wp_clear_scheduled_hook( PNChat_Site_Search::REFRESH );
pnt_check( has_action( 'tablepress_event_saved_table', array( 'PNChat_Site_Search', 'request_refresh' ) ) > 0, 'refresh: a saved TablePress table asks for it' );

// Unpublished: the text goes with the index.
wp_update_post(
	array(
		'ID'          => $data,
		'post_status' => 'draft',
	)
);
PNChat_Site_Search::index_post( $data );
pnt_same( '', get_post_meta( $data, PNChat_Site_Search::META_TEXT, true ), 'index: a page no longer public loses its saved text' );

// The administrator's preview.
wp_set_current_user( $admin->ID );
$_GET = array(
	'peek'      => (string) $ban,
	'peek_word' => 'Xalatan',
);
ob_start();
pnt_call( 'PNChat_Admin', 'site_peek' );
$out = ob_get_clean();
pnt_check( false !== strpos( $out, '16 γραμμές πινάκων' ) && false !== strpos( $out, 'Xalatan · Latanoprost · S01EE01 · Pfizer' ), 'admin: «Τι διαβάζει από μια σελίδα» shows the rows and the row with the word' );
$_GET['peek_word'] = 'Fortimel';
ob_start();
pnt_call( 'PNChat_Admin', 'site_peek' );
$out = ob_get_clean();
pnt_check( false !== strpos( $out, 'δεν υπάρχει σε ό,τι διαβάζει' ), 'admin: a word not read is reported, with the likely reason' );
$_GET = array();
wp_set_current_user( 0 );

pnt_done();
