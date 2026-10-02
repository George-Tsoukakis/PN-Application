<?php
/**
 * PN Chat matcher tests. No WordPress needed:  php pn-chat/tests/matcher-test.php
 */
define( 'PNCHAT_TESTING', 1 );
$plugin = dirname( __DIR__ ) . '/pn-chat';
require $plugin . '/includes/class-pnchat-text.php';
require $plugin . '/includes/class-pnchat-matcher.php';

$fails = 0;
$count = 0;
function check( $label, $cond, $extra = '' ) {
	global $fails, $count;
	++$count;
	if ( $cond ) {
		echo "PASS $label\n";
	} else {
		++$fails;
		echo "FAIL $label $extra\n";
	}
}
function ids( array $r ) {
	return array_map( function ( $i ) { return $i['id']; }, $r['items'] );
}

$entries = array(
	array( 'id' => 1, 'kind' => 'answer', 'title' => 'Τι είναι το PlanDose', 'phrasings' => array( 'Τι είναι το PlanDose;', 'Τι κάνει το PlanDose;', 'Πες μου για το PlanDose' ), 'keywords' => array(), 'answer' => 'Εργαλείο για πλάνα δοσολογίας.' ),
	array( 'id' => 2, 'kind' => 'answer', 'title' => 'Κόστος', 'phrasings' => array( 'Πόσο κοστίζει το PlanDose;', 'Τιμή συνδρομής Pro' ), 'keywords' => array( 'τιμοκατάλογος' ), 'answer' => 'Free και Pro.' ),
	array( 'id' => 3, 'kind' => 'answer', 'title' => 'Εκτύπωση', 'phrasings' => array( 'Πώς εκτυπώνω ένα πλάνο;', 'Πώς τυπώνω το φύλλο του ασθενή;' ), 'keywords' => array(), 'answer' => 'Πατήστε Εκτύπωση.' ),
	array( 'id' => 4, 'kind' => 'answer', 'title' => 'Όριο Free', 'phrasings' => array( 'Πόσες εκτυπώσεις έχω τον μήνα στο Free;' ), 'keywords' => array( 'μηνιαίο όριο' ), 'answer' => '30 τον μήνα.' ),
	array( 'id' => 5, 'kind' => 'answer', 'title' => 'Τιμολόγια', 'phrasings' => array( 'Πού βρίσκω τα τιμολόγιά μου;' ), 'keywords' => array( 'τιμολόγιο' ), 'answer' => 'Στην κάρτα.' ),
	array( 'id' => 9, 'kind' => 'block', 'title' => 'Ιατρική συμβουλή', 'phrasings' => array( 'Τι δόση να πάρω;', 'Ποιο φάρμακο να πάρω για τον πόνο;' ), 'keywords' => array( 'παρενέργειες' ), 'answer' => 'Δεν μπορούμε να απαντήσουμε.' ),
	array( 'id' => 10, 'kind' => 'answer', 'title' => 'Χαιρετισμός', 'phrasings' => array( 'Γεια σας', 'Καλημέρα' ), 'keywords' => array(), 'answer' => 'Γεια!' ),
);
$syn = PNChat_Matcher::parse_synonyms( "κοστίζει, τιμή, κόστος, χρέωση, πόσο κάνει\nεκτυπώνω = τυπώνω = εκτύπωση = print" );
$m   = new PNChat_Matcher( $entries, $syn, 0.5, 3 );

$r = $m->ask( 'Τι είναι το PlanDose;' );
check( 'exact question', 'answered' === $r['status'] && array( 1 ) === ids( $r ), json_encode( $r['parts'], JSON_UNESCAPED_UNICODE ) );

$r = $m->ask( 'ti einai to plandose' );
check( 'greeklish', array( 1 ) === ids( $r ), json_encode( ids( $r ) ) );

$r = $m->ask( 'τι ειναι το plandose' );
check( 'no accents, latin brand', array( 1 ) === ids( $r ) );

$r = $m->ask( 'Καλησπέρα, ήθελα να ρωτήσω τι είναι αυτό το PlanDose που έχετε;' );
check( 'polite long question', in_array( 1, ids( $r ), true ), json_encode( $r['parts'], JSON_UNESCAPED_UNICODE ) );

$r = $m->ask( 'πόσο κάνει;' );
check( 'synonym phrase -> cost', array( 2 ) === ids( $r ), json_encode( $r, JSON_UNESCAPED_UNICODE ) );

$r = $m->ask( 'ποια είναι η χρέωση για το plandose' );
check( 'synonym word -> cost, not "what is"', array( 2 ) === ids( $r ), json_encode( $r['parts'], JSON_UNESCAPED_UNICODE ) );

$r = $m->ask( 'Πώς τυπώνω πλάνα;' );
check( 'inflection: πλάνα / πλάνο', array( 3 ) === ids( $r ), json_encode( $r['parts'], JSON_UNESCAPED_UNICODE ) );

$r = $m->ask( 'Τι είναι το PlanDose και πόσο κοστίζει;' );
check( 'combined answer (two parts)', array( 1, 2 ) === ids( $r ) && 'answered' === $r['status'], json_encode( $r, JSON_UNESCAPED_UNICODE ) );

$r = $m->ask( 'Τι είναι το PlanDose; Πώς εκτυπώνω; Πού είναι τα τιμολόγια;' );
check( 'three parts combined', array( 1, 3, 5 ) === ids( $r ), json_encode( ids( $r ) ) );

$r = $m->ask( 'Θέλω τιμολόγιο' );
check( 'keyword hit', array( 5 ) === ids( $r ) );

$r = $m->ask( 'Ποιο είναι το μηνιαίο όριο;' );
check( 'keyword phrase', array( 4 ) === ids( $r ), json_encode( $r['parts'], JSON_UNESCAPED_UNICODE ) );

$r = $m->ask( 'Τι καιρό θα κάνει αύριο στην Αθήνα;' );
check( 'off-topic -> unanswered', 'unanswered' === $r['status'] && ! $r['items'], json_encode( $r, JSON_UNESCAPED_UNICODE ) );

$r = $m->ask( 'Ποια είναι η πρωτεύουσα της Γαλλίας;' );
check( 'off-topic 2 -> unanswered', 'unanswered' === $r['status'] );

$r = $m->ask( 'Τι δόση να πάρω από το depon;' );
check( 'blocked question', 'blocked' === $r['status'] && array( 9 ) === ids( $r ), json_encode( $r, JSON_UNESCAPED_UNICODE ) );

$r = $m->ask( 'Έχει παρενέργειες;' );
check( 'blocked keyword', 'blocked' === $r['status'] );

$r = $m->ask( 'Τι είναι το PlanDose και τι δόση να πάρω;' );
check( 'answer + refusal combined', array( 1, 9 ) === ids( $r ) && 'answered' === $r['status'], json_encode( $r, JSON_UNESCAPED_UNICODE ) );

$r = $m->ask( 'Τι είναι το PlanDose και πόσα κιλά είναι ένας ελέφαντας;' );
check( 'partial: one part unknown', 'partial' === $r['status'] && array( 1 ) === ids( $r ) && 1 === count( $r['unmatched'] ), json_encode( $r, JSON_UNESCAPED_UNICODE ) );

$r = $m->ask( 'Γεια σας' );
check( 'greeting', array( 10 ) === ids( $r ), json_encode( $r, JSON_UNESCAPED_UNICODE ) );

$r = $m->ask( 'pos ektyponw' );
check( 'greeklish print', array( 3 ) === ids( $r ), json_encode( $r['parts'], JSON_UNESCAPED_UNICODE ) );

$r = $m->ask( 'πως εκτιπωνω' );
check( 'typo ι/υ', array( 3 ) === ids( $r ) );

$r = $m->ask( '' );
check( 'empty', 'unanswered' === $r['status'] );

$m1 = new PNChat_Matcher( $entries, $syn, 0.5, 1 );
$r  = $m1->ask( 'Τι είναι το PlanDose; Πώς εκτυπώνω;' );
check( 'max_items caps answers', 1 === count( $r['items'] ) );

$m0 = new PNChat_Matcher( array(), array() );
check( 'empty brain', 'unanswered' === $m0->ask( 'Τι είναι το PlanDose;' )['status'] );

check( 'parse_synonyms ignores single words', 2 === count( $syn ) && 1 === count( PNChat_Matcher::parse_synonyms( "a\nb, c" ) ) );


// Speed: a big brain still answers fast.
$big = $entries;
for ( $i = 100; $i < 700; $i++ ) {
	$big[] = array( 'id' => $i, 'kind' => 'answer', 'title' => "Θέμα $i", 'phrasings' => array( "Ερώτηση νούμερο $i για το προϊόν λεξη$i", "Πώς ρυθμίζω την επιλογή ρυθμιση$i στο λογαριασμό;" ), 'keywords' => array( "κλειδί$i" ), 'answer' => 'x' );
}
$mb = new PNChat_Matcher( $big, $syn, 0.5, 3 );
$t0 = microtime( true );
$r  = $mb->ask( 'Καλησπέρα, θα ήθελα να μάθω τι είναι το PlanDose και πώς εκτυπώνω ένα πλάνο για τον ασθενή μου σήμερα;' );
$ms = ( microtime( true ) - $t0 ) * 1000;
check( 'big brain answers correctly', array( 1, 3 ) === ids( $r ), json_encode( ids( $r ) ) );
check( 'big brain is fast (< 1500 ms)', $ms < 1500, round( $ms ) . ' ms' );
echo '   (' . round( $ms ) . " ms for 700 entries)\n";

echo "\n$count checks, $fails failed\n";
exit( $fails ? 1 : 0 );
