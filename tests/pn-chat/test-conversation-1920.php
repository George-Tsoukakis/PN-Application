<?php
/**
 * 1.9.2: the conversation. Topics from title prefixes («eΔΑΠΥ: …») keep a
 * follow-up on its subject (the AI's «tools only» check does not change);
 * «Και πώς την ακυρώνω;» reads the previous answer; «Πόσο κοστίζει;» is
 * accepted when every own word is in the entry; «Σχετικές ερωτήσεις» after
 * an answer; the friendly «don't know» with the e-mail form behind a button;
 * learning from a rephrased question («Μάλλον εννοούσε»).
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
wp_set_current_user( 0 );
pnt_keep_entries();
pnt_settings(
	array(
		'topics'          => "QR ReBuilder, rebuilder\nΚοινότητα Viber, viber",
		'synonyms'        => "κοστίζει, τιμή, κόστος, χρέωση, πόσο κάνει, δωρεάν\nσβήνω, διαγράφω, διαγραφή, ακυρώνω, ακύρωση",
		'related_max'     => 3,
		'fallback_button' => 1,
		'strictness'      => 'normal',
		'fallback'        => PNChat_Settings::defaults()['fallback'],
	)
);
PNChat_Topics::reset();

$entries = array(
	array( 'Τι είναι το QR ReBuilder', array( 'Τι είναι το QR ReBuilder;' ) ),
	array( 'QR ReBuilder: κόστος', array( 'Κοστίζει το QR ReBuilder;', 'Είναι δωρεάν το QR ReBuilder;' ) ),
	array( 'QR ReBuilder: scanner', array( 'Τι scanner χρειάζομαι για το QR ReBuilder;' ) ),
	array( 'Κοινότητα Viber: Κόστος συμμετοχής', array( 'Έχει κόστος η συμμετοχή στην Κοινότητα Viber;' ) ),
	array( 'Κοινότητα Viber: Ένταξη', array( 'Πώς μπαίνω στην Κοινότητα Viber;' ) ),
	array( 'eΔΑΠΥ: Άνοιγμα περιόδου υποβολής', array( 'Πώς ανοίγω περίοδο υποβολής στο eΔΑΠΥ;' ) ),
	array( 'eΔΑΠΥ: Ακύρωση ανοίγματος περιόδου', array( 'Πώς ακυρώνω το άνοιγμα περιόδου στο eΔΑΠΥ;' ) ),
	array( 'eΔΑΠΥ: Δημιουργία και ακύρωση χρήστη', array( 'Πώς φτιάχνω νέο χρήστη στο eΔΑΠΥ;', 'Πώς καταργώ χρήστη στο eΔΑΠΥ;' ) ),
	array( 'eΔΑΠΥ: Πιστοποίηση φαρμακείου', array( 'Πώς κάνω εγγραφή φαρμακείου στο eΔΑΠΥ;' ) ),
	array( 'eΔΑΠΥ: Επικοινωνία', array( 'Σε ποιο email γράφω για πρόβλημα στο eΔΑΠΥ;' ) ),
	array( 'Γενόσημα: τι είναι', array( 'Τι είναι τα γενόσημα φάρμακα;' ) ),
	array( 'Γενόσημα: ασφάλεια', array( 'Είναι ασφαλή τα γενόσημα;' ) ),
	array( 'Εγγραφή στην PharmacyNeeds', array( 'Πώς κάνω εγγραφή στην PharmacyNeeds;', 'Πώς ανοίγω λογαριασμό;' ) ),
	array( 'Χαιρετισμός', array( 'Γεια σας' ) ),
);
$rows = array();
foreach ( $entries as $e ) {
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
PNChat_Topics::reset();
$id = array();
foreach ( PNChat_Store::entries() as $e ) {
	$id[ $e['title'] ] = (int) $e['id'];
}

// ---- topics from title prefixes --------------------------------------------------
$names = array_column( PNChat_Topics::conversation(), 'name' );
pnt_check( in_array( 'eΔΑΠΥ', $names, true ) && in_array( 'Γενόσημα', $names, true ), 'topics: «eΔΑΠΥ: …» and «Γενόσημα: …» titles make conversation topics' );
pnt_check( 1 === count( array_keys( $names, 'QR ReBuilder', true ) ) && 1 === count( array_keys( $names, 'Κοινότητα Viber', true ) ), 'topics: a prefix that is already a settings topic is not added twice' );
pnt_check( ! in_array( 'Εγγραφή στην PharmacyNeeds', $names, true ), 'topics: a title without prefix makes none' );
pnt_same( 'Γενόσημα', PNChat_Topics::of_entry( $id['Γενόσημα: ασφάλεια'] ), 'topics: an entry belongs to its title prefix' );
pnt_check( ! PNChat_AI::about_tools( 'Είναι ασφαλή τα γενόσημα;' ), 'AI guard unchanged: a title prefix does not make generics a «tool» the AI may answer about' );

// ---- follow-ups ------------------------------------------------------------------------
function pnt_conv( array $turns, array &$state ) {
	global $wpdb;
	$out = array();
	foreach ( $turns as $t ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', PNChat_Counter::table(), 'rl:%' ) );
		$body = array(
			'question' => is_array( $t ) ? $t[0] : $t,
			'context'  => $state['topic'] ?? '',
			'prev'     => $state['prev'] ?? 0,
			'seen'     => $state['seen'] ?? array(),
			'conv'     => $state['conv'] ?? '',
			'via'      => is_array( $t ) ? $t[1] : '',
		);
		list( , $d ) = pnt_rest( '/ask', $body );
		if ( ! empty( $d['topic'] ) ) {
			$state['topic'] = $d['topic'];
		}
		if ( ! empty( $d['entry'] ) ) {
			$state['prev']   = $d['entry'];
			$state['seen'][] = $d['entry'];
		}
		$out[] = $d;
	}
	return $out;
}
$title = function ( $d ) {
	return 'answered' === ( $d['status'] ?? '' ) ? (string) ( $d['items'][0]['title'] ?? '' ) : (string) ( $d['status'] ?? '' );
};

$st = array();
$r  = pnt_conv( array( 'Τι είναι το QR ReBuilder;', 'Είναι δωρεάν;', 'Τι scanner χρειάζομαι;' ), $st );
pnt_same( array( 'Τι είναι το QR ReBuilder', 'QR ReBuilder: κόστος', 'QR ReBuilder: scanner' ), array_map( $title, $r ), 'follow-ups: QR ReBuilder, then «Είναι δωρεάν;» and «Τι scanner χρειάζομαι;» stay on it' );

$st = array();
$r  = pnt_conv( array( 'Πώς ανοίγω περίοδο υποβολής στο eΔΑΠΥ;', 'Και πώς την ακυρώνω;' ), $st );
pnt_same( 'eΔΑΠΥ', $r[0]['topic'] ?? '', 'follow-ups: the eΔΑΠΥ answer carries its topic (from the title prefix)' );
pnt_same( 'eΔΑΠΥ: Ακύρωση ανοίγματος περιόδου', $title( $r[1] ), 'follow-ups: «Και πώς την ακυρώνω;» reads the previous answer (not «ακύρωση χρήστη»)' );

$st = array();
$r  = pnt_conv( array( 'Πώς μπαίνω στην Κοινότητα Viber;', 'Πόσο κοστίζει;' ), $st );
pnt_same( 'Κοινότητα Viber: Κόστος συμμετοχής', $title( $r[1] ), 'follow-ups: «Πόσο κοστίζει;» in the Viber conversation (every own word is in the entry)' );

$st = array();
$r  = pnt_conv( array( 'Πώς ανοίγω περίοδο υποβολής στο eΔΑΠΥ;', 'Πώς κάνω εγγραφή φαρμακείου;' ), $st );
pnt_same( 'eΔΑΠΥ: Πιστοποίηση φαρμακείου', $title( $r[1] ), 'follow-ups: in eΔΑΠΥ, «εγγραφή φαρμακείου» is the eΔΑΠΥ one, not the site sign-up' );
$r = pnt_conv( array( 'Γεια σας' ), $st );
pnt_same( 'Χαιρετισμός', $title( $r[0] ), 'follow-ups: a trained phrase of no topic is still answered in any conversation' );

$st = array();
$r  = pnt_conv( array( 'Τι είναι το QR ReBuilder;', 'Έχει εφαρμογή για iPhone;' ), $st );
pnt_check( 'answered' !== ( $r[1]['status'] ?? '' ), 'follow-ups: a vague question is not given the topic\'s general entry' );
$st = array();
$r  = pnt_conv( array( 'Τι είναι το QR ReBuilder;', 'Είναι ασφαλή τα γενόσημα;' ), $st );
pnt_same( array( 'Γενόσημα: ασφάλεια', 'Γενόσημα' ), array( $title( $r[1] ), $r[1]['topic'] ?? '' ), 'follow-ups: naming another topic changes topic' );

// ---- related questions ------------------------------------------------------------------
$st = array();
$r  = pnt_conv( array( 'Πώς ανοίγω περίοδο υποβολής στο eΔΑΠΥ;' ), $st );
$rel = array_column( $r[0]['related'] ?? array(), 'text' );
pnt_same( 3, count( $rel ), 'related: three questions under the answer' );
pnt_same( 'Πώς ακυρώνω το άνοιγμα περιόδου στο eΔΑΠΥ;', $rel[0] ?? '', 'related: the closest first (cancelling the period)' );
pnt_check( ! in_array( 'Πώς ανοίγω περίοδο υποβολής στο eΔΑΠΥ;', $rel, true ) && ! array_diff( $rel, array( 'Πώς ακυρώνω το άνοιγμα περιόδου στο eΔΑΠΥ;', 'Πώς φτιάχνω νέο χρήστη στο eΔΑΠΥ;', 'Πώς κάνω εγγραφή φαρμακείου στο eΔΑΠΥ;', 'Σε ποιο email γράφω για πρόβλημα στο eΔΑΠΥ;' ) ), 'related: only other eΔΑΠΥ entries, not the answered one' );
$r2 = pnt_conv( array( array( $rel[0], 'chip' ) ), $st );
pnt_same( 'eΔΑΠΥ: Ακύρωση ανοίγματος περιόδου', $title( $r2[0] ), 'related: tapping one answers it' );
pnt_check( ! in_array( 'Πώς ανοίγω περίοδο υποβολής στο eΔΑΠΥ;', array_column( $r2[0]['related'], 'text' ), true ), 'related: entries already shown in the conversation are not offered again' );
$st = array();
$r  = pnt_conv( array( 'Τι είναι το QR ReBuilder;' ), $st );
pnt_check( array() !== $r[0]['related'] && ! array_diff( array_column( $r[0]['related'], 'id' ), array( $id['QR ReBuilder: κόστος'], $id['QR ReBuilder: scanner'] ) ), 'related: by settings topic when the title has no prefix' );
$r = pnt_conv( array( 'Γεια σας' ), $st );
pnt_same( array(), $r[0]['related'], 'related: none for an entry of no topic' );
pnt_settings( array( 'related_max' => 0 ) );
$st = array();
$r  = pnt_conv( array( 'Τι είναι το QR ReBuilder;' ), $st );
pnt_same( array(), $r[0]['related'], 'related: 0 in Ρυθμίσεις turns them off' );
pnt_settings( array( 'related_max' => 3 ) );

// ---- «don't know» -------------------------------------------------------------------------
pnt_clear_questions();
$st = array();
$r  = pnt_conv( array( 'Τι καιρό κάνει αύριο στη Θεσσαλονίκη;' ), $st );
pnt_same( array( 'unanswered', false, true, true ), array( $r[0]['status'], $r[0]['ask_email'], $r[0]['email_button'], $r[0]['show_suggestions'] ), 'don\'t know: no form, a «Θέλω απάντηση από άνθρωπο» button and the suggested questions' );
pnt_check( false !== strpos( (string) $r[0]['message'], 'Θέλω απάντηση από άνθρωπο' ), 'don\'t know: the friendlier text' );
list( $code ) = pnt_rest( '/email', array( 'id' => $r[0]['id'], 'token' => $r[0]['token'], 'email' => 'v@example.test' ) );
pnt_same( 200, $code, 'don\'t know: the button\'s form sends the e-mail as before' );
pnt_settings( array( 'fallback_button' => 0 ) );
$r = pnt_conv( array( 'Τι καιρό κάνει αύριο στη Θεσσαλονίκη;' ), $st );
pnt_same( array( true, false ), array( $r[0]['ask_email'], $r[0]['email_button'] ), 'don\'t know: with the setting off the form shows at once (as before 1.9.2)' );
pnt_settings( array( 'fallback_button' => 1 ) );

// Settings saved with the old default text get the new one; a text of the site's own stays.
$saved             = get_option( PNChat_Settings::OPTION );
$saved['fallback'] = 'Δεν έχουμε πληροφορίες για το συγκεκριμένο ερώτημα. Αφήστε το e-mail σας και θα σας απαντήσουμε σύντομα.';
update_option( PNChat_Settings::OPTION, $saved, false );
update_option( 'pnchat_settings_version', 7, false );
PNChat_Settings::migrate();
pnt_check( 0 === strpos( (string) PNChat_Settings::value( 'fallback' ), 'Δεν έχω ακόμα απάντηση' ), 'migration: the old default «don\'t know» text is updated' );
$saved['fallback'] = 'Δικό μας κείμενο.';
update_option( PNChat_Settings::OPTION, $saved, false );
update_option( 'pnchat_settings_version', 7, false );
PNChat_Settings::migrate();
pnt_same( 'Δικό μας κείμενο.', PNChat_Settings::value( 'fallback' ), 'migration: a text the site wrote stays' );

// A brain file from an older version brings the old text: it is updated on upload too.
$brain                         = PNChat_Brain::export( false );
$brain['settings']['fallback'] = 'Δεν έχουμε πληροφορίες για το συγκεκριμένο ερώτημα. Αφήστε το e-mail σας και θα σας απαντήσουμε σύντομα.';
$brain['settings']['partial']  = 'Δικό μας «{question}».';
PNChat_Brain::import( $brain, 'merge', true );
pnt_check( 0 === strpos( (string) PNChat_Settings::value( 'fallback' ), 'Δεν έχω ακόμα απάντηση' ) && 'Δικό μας «{question}».' === PNChat_Settings::value( 'partial' ), 'brain upload: an old default text gets the new one, the site\'s own text stays' );

// ---- learning from a rephrased question ----------------------------------------------------
pnt_clear_questions();
$conv = 'pntconversation00001';
$st   = array( 'conv' => $conv );
$r    = pnt_conv( array( 'Τι είναι το QR ReBuilder;', 'Θέλει μηχάνημα για το σκανάρισμα;', 'Τι scanner χρειάζομαι για το QR ReBuilder;' ), $st );
pnt_same( 'unanswered', $r[1]['status'], 'learning: the visitor\'s first wording is not understood' );
$q = PNChat_Store::question( (int) $r[1]['id'] );
pnt_same( $id['QR ReBuilder: scanner'], (int) $q['hint_entry'], 'learning: after the rephrased question was answered, the first one points to that entry' );
pnt_check( '' !== $q['conv'] && $conv !== $q['conv'] && false === strpos( (string) $q['conv'], $conv ), 'learning: the conversation id is stored hashed' );
pnt_same( 1, (int) PNChat_Store::question_counts()['hint'], 'learning: listed under «💡 Μάλλον εννοούσαν»' );

$st = array( 'conv' => 'pntconversation00002' );
$r  = pnt_conv( array( 'Θέλει μηχάνημα για το σκανάρισμα;', array( 'Τι scanner χρειάζομαι για το QR ReBuilder;', 'chip' ) ), $st );
pnt_same( 0, (int) PNChat_Store::question( (int) $r[0]['id'] )['hint_entry'], 'learning: a tapped suggestion is no evidence' );
$st = array( 'conv' => 'pntconversation00003' );
$r  = pnt_conv( array( 'Θέλει μηχάνημα για το σκανάρισμα;' ), $st );
$st = array( 'conv' => 'pntconversation00004' );
pnt_conv( array( 'Τι scanner χρειάζομαι για το QR ReBuilder;' ), $st );
pnt_same( 0, (int) PNChat_Store::question( (int) $r[0]['id'] )['hint_entry'], 'learning: another conversation is no evidence' );
$wpdb->update( PNChat_Store::questions_table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'id' => (int) $r[0]['id'] ) );
$st = array( 'conv' => 'pntconversation00003' );
pnt_conv( array( 'Τι scanner χρειάζομαι για το QR ReBuilder;' ), $st );
pnt_same( 0, (int) PNChat_Store::question( (int) $r[0]['id'] )['hint_entry'], 'learning: an hour later is no evidence' );

// The administrator adds it with one click.
$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );
add_filter(
	'wp_redirect',
	function () {
		throw new Exception( 'redirect' );
	}
);
$hinted              = PNChat_Store::hint_previous( '', 1 );
$_GET                = array();
$_REQUEST            = array(
	'id'       => (string) $q['id'],
	'do'       => 'hint',
	'_wpnonce' => wp_create_nonce( 'pnchat_question' ),
);
try {
	PNChat_Admin::handle_question();
} catch ( Exception $e ) {
	unset( $e );
}
$_REQUEST = array();
$scanner  = PNChat_Store::entry( $id['QR ReBuilder: scanner'] );
pnt_check( in_array( 'Θέλει μηχάνημα για το σκανάρισμα; (QR ReBuilder)', $scanner['phrasings'], true ), 'learning: «Πρόσθεσε την ερώτηση εκεί» adds the visitor\'s wording, with its topic' );
pnt_same( 'trained', PNChat_Store::question( (int) $q['id'] )['status'], 'learning: the question is marked trained' );
pnt_same( false, $hinted, 'learning: no conversation, no hint' );
wp_set_current_user( 0 );
PNChat_Brain::matcher( true );
$st = array( 'conv' => 'pntconversation00005' );
$r  = pnt_conv( array( 'Τι είναι το QR ReBuilder;', 'Θέλει μηχάνημα για το σκανάρισμα;' ), $st );
pnt_same( 'QR ReBuilder: scanner', $title( $r[1] ), 'learning: next time the first wording is understood' );

pnt_done();
