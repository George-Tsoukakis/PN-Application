<?php
/**
 * Checks pharmacyneeds-brain.json together with the starter brain:
 * every phrasing must be answered by its own entry, and sample questions
 * written differently must reach the expected entry.
 *   php check-brain.php
 */
define( 'ABSPATH', __DIR__ . '/' );
$p = dirname( __DIR__ ) . '/pn-chat/includes/';
require $p . 'class-pnchat-text.php';
require $p . 'class-pnchat-matcher.php';
function esc_html( $s ) { return $s; }
function wp_kses_post( $s ) { return $s; }
function __( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function home_url( $p = '' ) { return 'https://pharmacyneeds.gr' . $p; }
class PNChat_Store {
	public static function save_entry() {}
	public static function entries() { return array(); }
	public static function lines( $t ) { return array_values( array_filter( array_map( 'trim', preg_split( '/\R/u', (string) $t ) ) ) ); }
}
require $p . 'class-pnchat-seed.php';
require $p . 'class-pnchat-topics.php';

$brain   = json_decode( file_get_contents( __DIR__ . '/pharmacyneeds-brain.json' ), true );
$entries = array();
$id      = 0;
// Same as Εγκέφαλος → Φόρτωση → Προσθήκη: an entry with the same kind, title
// and first question as an existing one is skipped.
$key  = fn( $e ) => $e['kind'] . '|' . PNChat_Text::fold( $e['title'] ) . '|' . PNChat_Text::fold( $e['phrasings'][0] ?? '' );
$seen = array();
foreach ( array_merge( PNChat_Seed::entries(), $brain['entries'] ) as $e ) {
	if ( isset( $seen[ $key( $e ) ] ) ) {
		continue;
	}
	$seen[ $key( $e ) ] = true;
	$e['id']            = ++$id;
	$entries[]          = $e;
}
$titles = array_column( $entries, 'title', 'id' );
$syn    = PNChat_Matcher::parse_synonyms( "κοστίζει, τιμή, κόστος, χρέωση, πόσο κάνει, δωρεάν\nεκτυπώνω, τυπώνω, εκτύπωση, print\nφαρμακείο, φαρμακοποιός\nπρόβλημα, σφάλμα, λάθος, error\nλογαριασμός, εγγραφή, προφίλ\nλειτουργεί, δουλεύει" );
// Subjects as in PNChat_Brain::subject_terms(): the default topics, synonyms applied.
$canon = array();
foreach ( $syn as $g ) {
	$first = str_replace( ' ', '_', PNChat_Text::fold( $g[0] ) );
	foreach ( $g as $w ) {
		$f = PNChat_Text::fold( $w );
		if ( false === strpos( $f, ' ' ) ) {
			$canon[ $f ] = $first;
		}
	}
}
$subjects = array();
foreach ( PNChat_Topics::parse( "QR ReBuilder, rebuilder, datamatrix, gs1\nPlanDose, πλάνο δοσολογίας, πλάνα δοσολογίας, pro\nΚοινότητα Viber, viber\nΕλλείψεις ΕΟΦ, ελλείψεις, έλλειψη, εοφ\nΥπολογισμός αποθέματος, απόθεμα" ) as $t ) {
	foreach ( $t['terms'] as $term ) {
		$subjects[] = array_map( fn( $w ) => $canon[ $w ] ?? $w, $term );
	}
}
$m      = new PNChat_Matcher( $entries, $syn, 0.5, 3, $subjects );
$fails  = 0;

foreach ( $entries as $e ) {
	foreach ( $e['phrasings'] as $q ) {
		$r   = $m->ask( $q );
		$got = array_map( fn( $i ) => $i['id'], $r['items'] );
		if ( ! in_array( $e['id'], $got, true ) ) {
			++$fails;
			echo "CONFLICT «{$q}» ({$e['title']}) -> " . implode( ', ', array_map( fn( $i ) => $titles[ $i ], $got ) ) . " [{$r['status']}]\n";
		}
	}
}

$samples = array(
	'ti einai i pharmacyneeds'                          => 'Τι είναι η PharmacyNeeds',
	'τι εργαλεια εχετε για φαρμακεια'                   => 'Ποια εργαλεία έχετε',
	'πως κανω εγγραφη στο plandose'                      => 'PlanDose: πώς ξεκινάω',
	'τελειωσαν οι εκτυπωσεις του μηνα'                   => 'PlanDose: όριο εκτυπώσεων Free',
	'μπορω να ξανατυπωσω το ιδιο πλανο;'                 => 'PlanDose: εκτύπωση ξανά',
	'μπορω να βαλω τη συνταγη με επικολληση;'             => 'PlanDose: επικόλληση συνταγής',
	'pws ananewnw ti syndromi'                           => 'PlanDose: Pro (ενεργοποίηση και ανανέωση)',
	'τι κανει το qr rebuilder'                           => 'Τι είναι το QR ReBuilder',
	'Τι είναι το QR-REBUILDER;'                          => 'Τι είναι το QR ReBuilder',
	'qr rebuilder'                                       => 'Τι είναι το QR ReBuilder',
	'Τι είναι το QR;'                                    => 'QR: QR ReBuilder ή QR του PlanDose;',
	'Για τι χρησιμεύει το QR στο φύλλο του PlanDose;'    => 'PlanDose: QR υπενθυμίσεις στο κινητό',
	'πως βαζει ο ασθενης υπενθυμιση στο κινητο'          => 'PlanDose: QR υπενθυμίσεις στο κινητό',
	'ο κωδικος datamatrix ειναι σκισμενος δεν σκαναρεται' => 'QR ReBuilder: χαλασμένος κωδικός',
	'γιατι δεν μπορω να στειλω email απο το qr'           => 'QR ReBuilder: αποστολή με e-mail',
	'το depon ειναι σε ελλειψη;'                         => 'Φάρμακα σε έλλειψη (ΕΟΦ)',
	'αποκατασταθηκε σημαινει οτι το εχει το φαρμακειο;'   => 'Ελλείψεις: τι σημαίνει «Αποκαταστάθηκε»',
	'ποσους μηνες με καλυπτει το αποθεμα μου'            => 'Υπολογισμός αποθέματος',
	'τι ειναι το υψηλο ρισκο ληξης'                      => 'Υπολογισμός αποθέματος: ρίσκο λήξης',
	'πως μπαινω στην ομαδα viber'                        => 'Κοινότητα Viber: πώς γίνομαι μέλος',
	'ποσα μελη εχει η κοινοτητα viber'                   => 'Κοινότητα Viber φαρμακείων',
	'θελω να επικοινωνησω μαζι σας'                      => 'Επικοινωνία',
	'απαγορευση εξαγωγων αυγουστος'                      => 'Απαγόρευση εξαγωγών φαρμάκων (Αύγουστος 2026)',
	'ψαχνω ενα φαρμακο που λειπει για ασθενη'            => 'Ψάχνω φάρμακο που λείπει',
	'τι δοση να παρει ο ασθενης απο το depon'            => 'Ιατρικές συμβουλές για ασθενείς',
);
foreach ( $samples as $q => $want ) {
	$r   = $m->ask( $q );
	$got = array_map( fn( $i ) => $i['title'], $r['items'] );
	$ok  = in_array( $want, $got, true );
	if ( ! $ok ) {
		++$fails;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . "«{$q}» -> " . ( $got ? implode( ' + ', $got ) : '(' . $r['status'] . ')' ) . "\n";
}
foreach ( array( 'τι καιρο θα κανει αυριο', 'ποια ειναι η πρωτευουσα της ιταλιας', 'ποσο κανει ενα αυτοκινητο' ) as $q ) {
	$r = $m->ask( $q );
	if ( 'unanswered' !== $r['status'] ) {
		++$fails;
	}
	echo ( 'unanswered' === $r['status'] ? 'PASS ' : 'FAIL ' ) . "off-topic «{$q}» -> {$r['status']}\n";
}
echo $fails ? "\n$fails problems\n" : "\nall good\n";
exit( $fails ? 1 : 0 );
