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
				'answer'    => 'Γεια σας! Πώς μπορώ να βοηθήσω; Ρωτήστε με για την PharmacyNeeds και το PlanDose.',
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
				'phrasings' => array( 'Τι είναι το PlanDose;', 'Τι κάνει το PlanDose;', 'Πες μου για το PlanDose', 'Πώς λειτουργεί το PlanDose;', 'Πώς δουλεύει το PlanDose;', 'Τι είναι το πλάνο δοσολογίας;' ),
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
				'phrasings' => array( 'Τι διαφέρει το Free από το Pro;', 'Είναι δωρεάν το PlanDose;', 'Πόσο κοστίζει το PlanDose;', 'Τι έχει το Pro;', 'Τι περιλαμβάνει η δωρεάν έκδοση;', 'Πόσες εκτυπώσεις έχω τον μήνα;', 'Πώς ενεργοποιώ το Pro;' ),
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
			self::plandose_qr(),
			self::qr_rebuilder(),
			self::qr_howto(),
			self::qr_cost(),
			self::qr_which(),
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

	/**
	 * The QR printed on PlanDose sheets (patient reminders). 1.0.x had the
	 * bare keyword «qr» here, which sent every QR question (QR ReBuilder too)
	 * to this answer.
	 *
	 * @return array<string,mixed>
	 */
	public static function plandose_qr() {
		return array(
			'kind'      => 'answer',
			'title'     => 'PlanDose: QR υπενθυμίσεις στο κινητό',
			'phrasings' => array( 'Τι είναι το QR στο φύλλο του PlanDose;', 'Τι είναι το QR στο φύλλο;', 'Πώς βάζει ο ασθενής υπενθυμίσεις στο κινητό;', 'Υπάρχει ειδοποίηση για τις δόσεις;', 'Χρειάζεται εφαρμογή ο ασθενής;' ),
			'keywords'  => array( 'υπενθύμιση' ),
			'answer'    => 'Το τυπωμένο φύλλο του <strong>PlanDose</strong> έχει QR «Υπενθυμίσεις στο κινητό». Ο ασθενής το σκανάρει με την κάμερα, βλέπει τα φάρμακά του και πατά «Προσθήκη στο ημερολόγιο»: οι δόσεις μπαίνουν στο ημερολόγιο του κινητού του με ειδοποίηση την ώρα κάθε δόσης. <strong>Χωρίς εφαρμογή και χωρίς λογαριασμό.</strong> Το όνομα του ασθενή δεν μπαίνει ποτέ στο QR.',
		);
	}

	/**
	 * QR ReBuilder, a separate tool (GS1 DataMatrix of medicine packs).
	 *
	 * @return array<string,mixed>
	 */
	public static function qr_rebuilder() {
		return array(
			'kind'      => 'answer',
			'title'     => 'Τι είναι το QR ReBuilder',
			'phrasings' => array( 'Τι είναι το QR ReBuilder;', 'Τι είναι το QR-REBUILDER;', 'Τι κάνει το QR ReBuilder;', 'Πες μου για το QR ReBuilder', 'Τι είναι το GS1 DataMatrix;', 'Εργαλείο για τον κωδικό DataMatrix της συσκευασίας' ),
			'keywords'  => array( 'rebuilder', 'datamatrix' ),
			'answer'    => 'Το <strong>QR ReBuilder</strong> είναι ξεχωριστό εργαλείο της PharmacyNeeds (δεν είναι μέρος του PlanDose). Διαβάζει τον κωδικό <strong>GS1 DataMatrix</strong> των συσκευασιών φαρμάκων, βγάζει τα στοιχεία του (<strong>PC/GTIN</strong>, <strong>SN</strong> σειριακός αριθμός, <strong>LOT</strong> παρτίδα, <strong>EXP</strong> λήξη), τα ελέγχει και φτιάχνει <strong>νέο έγκυρο κωδικό</strong>, έτοιμο για εκτύπωση. Θα το βρείτε <a href="' . esc_url( home_url( '/qr-rebuilder/' ) ) . '">εδώ</a>.',
		);
	}

	/**
	 * How QR ReBuilder is used, with the way to its page. Same title and
	 * first question as in the brain file, so «Προσθήκη» never doubles it.
	 *
	 * @return array<string,mixed>
	 */
	public static function qr_howto() {
		return array(
			'kind'      => 'answer',
			'title'     => 'QR ReBuilder: πώς δουλεύει',
			'phrasings' => array( 'Πώς δουλεύει το QR ReBuilder;', 'Πώς χρησιμοποιώ το QR ReBuilder;', 'Πώς σκανάρω με το QR ReBuilder;', 'Πώς φτιάχνω νέο DataMatrix;', 'Χρειάζομαι scanner;' ),
			'keywords'  => array(),
			'answer'    => '<ol><li>Ανοίξτε το <a href="' . esc_url( home_url( '/qr-rebuilder/' ) ) . '">QR ReBuilder</a>.</li><li>Σκανάρετε τον κωδικό με εξωτερικό barcode scanner, ή επικολλάτε τα δεδομένα με το χέρι.</li><li>Ελέγχετε και διορθώνετε τα πεδία PC, SN, LOT και EXP.</li><li>Πατάτε δημιουργία: ο server ελέγχει ξανά τα στοιχεία και φτιάχνει τον νέο GS1 DataMatrix.</li><li>Τον τυπώνετε μαζί με τα στοιχεία του, αντιγράφετε τα δεδομένα ή τον στέλνετε με e-mail.</li></ol>',
		);
	}

	/**
	 * Cost of QR ReBuilder (same title and first question as in the brain file).
	 *
	 * @return array<string,mixed>
	 */
	public static function qr_cost() {
		return array(
			'kind'      => 'answer',
			'title'     => 'QR ReBuilder: κόστος και εκτύπωση',
			'phrasings' => array( 'Κοστίζει το QR ReBuilder;', 'Είναι δωρεάν το QR ReBuilder;', 'Πόσο κοστίζει το QR ReBuilder;', 'Έχει όριο χρήσης το QR ReBuilder;', 'Τυπώνει το QR ReBuilder σε εκτυπωτή ετικετών;' ),
			'keywords'  => array(),
			'answer'    => 'Το QR ReBuilder <strong>δεν έχει σύστημα συνδρομών ούτε όρια χρήσης</strong>. Ο κωδικός βγαίνει ως εικόνα PNG με λευκό φόντο, κατάλληλη για εκτυπωτές ετικετών, και τυπώνεται μαζί με τα στοιχεία του. <a href="' . esc_url( home_url( '/qr-rebuilder/' ) ) . '">Ανοίξτε το QR ReBuilder</a>.',
		);
	}

	/**
	 * «Τι είναι το QR;»: two different things carry the name.
	 *
	 * @return array<string,mixed>
	 */
	public static function qr_which() {
		return array(
			'kind'      => 'answer',
			'title'     => 'QR: QR ReBuilder ή QR του PlanDose;',
			'phrasings' => array( 'Τι είναι το QR;', 'Πώς δουλεύει το QR;', 'Ποια η διαφορά QR ReBuilder και QR του PlanDose;', 'Έχω ερώτηση για το QR' ),
			'keywords'  => array(),
			'answer'    => 'Ποιο από τα δύο εννοείτε;<ul><li><strong>QR ReBuilder</strong>: εργαλείο που διαβάζει και ξαναφτιάχνει τον κωδικό GS1 DataMatrix των συσκευασιών φαρμάκων. Ρωτήστε «Τι είναι το QR ReBuilder;».</li><li><strong>QR του PlanDose</strong>: ο κωδικός στο τυπωμένο φύλλο δοσολογίας, με τον οποίο ο ασθενής βάζει υπενθυμίσεις στο κινητό. Ρωτήστε «Τι είναι το QR στο φύλλο του PlanDose;».</li></ul>',
		);
	}

	/**
	 * 1.2.1: fixes the 1.0.x starter QR entry, unless an administrator has
	 * changed it, and adds QR ReBuilder and the «which QR?» entries if no
	 * entry with the same title exists.
	 *
	 * @return void
	 */
	public static function upgrade_qr() {
		foreach ( PNChat_Store::entries( 'answer' ) as $e ) {
			if ( 'Υπενθυμίσεις στο κινητό (QR)' === $e['title'] && array( 'qr', 'υπενθύμιση' ) === $e['keywords'] ) {
				$new           = self::plandose_qr();
				$e['title']    = $new['title'];
				$e['keywords'] = $new['keywords'];
				$e['phrasings'] = array_values( array_unique( array_merge( array( 'Τι είναι το QR στο φύλλο του PlanDose;' ), $e['phrasings'] ) ) );
				PNChat_Store::save_entry( $e, $e['id'] );
			}
		}
		// 1.4.0: the PlanDose Free/Pro entry answers «Είναι δωρεάν το PlanDose;».
		foreach ( PNChat_Store::entries( 'answer' ) as $e ) {
			if ( 'Free και Pro' === $e['title'] ) {
				$more = array_diff( array( 'Είναι δωρεάν το PlanDose;', 'Πόσο κοστίζει το PlanDose;' ), $e['phrasings'] );
				if ( $more ) {
					$e['phrasings'] = array_merge( $e['phrasings'], $more );
					PNChat_Store::save_entry( $e, $e['id'] );
				}
			}
		}
		$titles = array_map(
			function ( $e ) {
				return PNChat_Text::fold( $e['title'] );
			},
			PNChat_Store::entries( 'answer' )
		);
		foreach ( array( self::qr_rebuilder(), self::qr_howto(), self::qr_cost(), self::qr_which() ) as $add ) {
			if ( ! in_array( PNChat_Text::fold( $add['title'] ), $titles, true ) ) {
				$add['active'] = 1;
				PNChat_Store::save_entry( $add );
			}
		}
	}
}
