<?php
/**
 * Starter brain, written once on the first activation so the chat is not
 * empty. Every entry is an ordinary entry: edit, turn off or delete it.
 * The facts come from the PlanDose readme.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Starter entries.
 */
final class PNChat_Seed {

	/**
	 * Entries.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function entries() {
		return array(
			array(
				'kind'      => 'answer',
				'title'     => 'Χαιρετισμός',
				'phrasings' => array( 'Γεια σας', 'Γεια', 'Καλημέρα', 'Καλησπέρα', 'Χαίρετε', 'Hello' ),
				'keywords'  => array(),
				'answer'    => 'Γεια σας! Πώς μπορώ να βοηθήσω; Ρωτήστε με για το PharmacyNeeds και το PlanDose.',
			),
			array(
				'kind'      => 'answer',
				'title'     => 'Ευχαριστώ',
				'phrasings' => array( 'Ευχαριστώ', 'Ευχαριστώ πολύ', 'Σας ευχαριστώ', 'Thanks' ),
				'keywords'  => array(),
				'answer'    => 'Παρακαλώ! Αν χρειαστείτε κάτι άλλο, είμαι εδώ.',
			),
			array(
				'kind'      => 'answer',
				'title'     => 'Τι είναι το PlanDose',
				'phrasings' => array( 'Τι είναι το PlanDose;', 'Τι κάνει το PlanDose;', 'Πες μου για το PlanDose', 'Πώς λειτουργεί το PlanDose;', 'Τι είναι το πλάνο δοσολογίας;' ),
				'keywords'  => array(),
				'answer'    => "Το <strong>PlanDose</strong> είναι εργαλείο του PharmacyNeeds για φαρμακεία. Φτιάχνει γρήγορα <strong>εκτυπώσιμα πλάνα δοσολογίας</strong> ανά ασθενή, με ημερολόγιο ημέρα προς ημέρα, όπου ο ασθενής τσεκάρει κάθε δόση στο σπίτι.\n\nΣκοπός του είναι να βοηθά τους ασθενείς να ακολουθούν σωστά τη δοσολογία τους.",
			),
			array(
				'kind'      => 'answer',
				'title'     => 'Ποιοι έχουν πρόσβαση',
				'phrasings' => array( 'Ποιοι μπορούν να χρησιμοποιήσουν το PlanDose;', 'Ποιος έχει πρόσβαση στο PlanDose;', 'Μπορεί μια εταιρεία να χρησιμοποιήσει το PlanDose;', 'Γιατί δεν βλέπω το PlanDose;' ),
				'keywords'  => array(),
				'answer'    => 'Το PlanDose είναι διαθέσιμο <strong>μόνο σε λογαριασμούς φαρμακείων</strong> (Κατηγορία Επιχείρησης = «Φαρμακείο»). Η κατηγορία δηλώνεται στην εγγραφή και μετά την αλλάζει μόνο ο διαχειριστής. Αν είστε φαρμακείο και δεν βλέπετε το εργαλείο, επικοινωνήστε μαζί μας.',
			),
			array(
				'kind'      => 'answer',
				'title'     => 'Free και Pro',
				'phrasings' => array( 'Τι διαφέρει το Free από το Pro;', 'Τι έχει το Pro;', 'Τι περιλαμβάνει η δωρεάν έκδοση;', 'Πόσες εκτυπώσεις έχω τον μήνα;', 'Πώς ενεργοποιώ το Pro;' ),
				'keywords'  => array( 'pro', 'free' ),
				'answer'    => "<ul><li><strong>Free:</strong> δωρεάν, με μηνιαίο όριο εκτυπώσεων.</li><li><strong>Pro:</strong> χωρίς όριο εκτυπώσεων, ετικέτες φαρμάκων για ετικετογράφο και αγγλικό περιβάλλον.</li></ul>Το Pro το ενεργοποιεί το PharmacyNeeds για τον λογαριασμό σας· επικοινωνήστε μαζί μας.",
			),
			array(
				'kind'      => 'answer',
				'title'     => 'Στοιχεία ασθενών',
				'phrasings' => array( 'Αποθηκεύονται τα στοιχεία των ασθενών;', 'Πού πάνε τα δεδομένα του ασθενή;', 'Είναι ασφαλή τα στοιχεία των ασθενών;', 'Τι γίνεται με το απόρρητο;' ),
				'keywords'  => array( 'gdpr' ),
				'answer'    => 'Κανένα στοιχείο του πλάνου (όνομα ασθενή, φάρμακα, δοσολογία, ημέρες, σημειώσεις) <strong>δεν αποθηκεύεται ούτε στέλνεται</strong> στον server. Το πλάνο φτιάχνεται και τυπώνεται εξ ολοκλήρου στον browser του φαρμακοποιού.',
			),
			array(
				'kind'      => 'answer',
				'title'     => 'Υπενθυμίσεις στο κινητό (QR)',
				'phrasings' => array( 'Τι είναι το QR στο φύλλο;', 'Πώς βάζει ο ασθενής υπενθυμίσεις στο κινητό;', 'Υπάρχει ειδοποίηση για τις δόσεις;', 'Χρειάζεται εφαρμογή ο ασθενής;' ),
				'keywords'  => array( 'qr', 'υπενθύμιση' ),
				'answer'    => 'Το τυπωμένο φύλλο έχει QR «Υπενθυμίσεις στο κινητό». Ο ασθενής το σκανάρει με την κάμερα, βλέπει τα φάρμακά του και πατά «Προσθήκη στο ημερολόγιο»: οι δόσεις μπαίνουν στο ημερολόγιο του κινητού του με ειδοποίηση την ώρα κάθε δόσης. <strong>Χωρίς εφαρμογή και χωρίς λογαριασμό.</strong> Το όνομα του ασθενή δεν μπαίνει ποτέ στο QR.',
			),
			array(
				'kind'      => 'block',
				'title'     => 'Ιατρικές συμβουλές',
				'phrasings' => array( 'Τι δόση να πάρω;', 'Ποιο φάρμακο να πάρω;', 'Ποιο φάρμακο είναι καλό για τον πόνο;', 'Μπορώ να πάρω μαζί αυτά τα φάρμακα;', 'Τι παρενέργειες έχει;' ),
				'keywords'  => array( 'παρενέργειες', 'αλληλεπίδραση', 'δοσολογία για' ),
				'answer'    => 'Δεν μπορούμε να απαντήσουμε σε ιατρικές ή φαρμακευτικές ερωτήσεις μέσα από το chat. Απευθυνθείτε στον γιατρό ή τον φαρμακοποιό σας.',
			),
		);
	}

	/**
	 * Writes the starter entries once, only into an empty brain.
	 *
	 * @return void
	 */
	public static function maybe_seed() {
		if ( get_option( 'pnchat_seeded' ) ) {
			return;
		}
		update_option( 'pnchat_seeded', 1, false );
		if ( PNChat_Store::entries() ) {
			return;
		}
		foreach ( self::entries() as $e ) {
			$e['active'] = 1;
			PNChat_Store::save_entry( $e );
		}
	}
}
