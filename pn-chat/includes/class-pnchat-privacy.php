<?php
/**
 * WordPress personal-data tools: export and erase a visitor's questions by
 * e-mail, and a paragraph for the privacy policy.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy hooks.
 */
final class PNChat_Privacy {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array<string,mixed> $list Exporters.
	 * @return array<string,mixed>
	 */
	public static function exporters( $list ) {
		$list['pn-chat'] = array(
			'exporter_friendly_name' => 'PN Chat',
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array<string,mixed> $list Erasers.
	 * @return array<string,mixed>
	 */
	public static function erasers( $list ) {
		$list['pn-chat'] = array(
			'eraser_friendly_name' => 'PN Chat',
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/**
	 * Rows per page of the exporter and the eraser.
	 */
	const PAGE = 100;

	/**
	 * One page of the questions of an e-mail (or of the user with that e-mail).
	 *
	 * @param string $email E-mail.
	 * @param int    $page  1-based page.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rows( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		$uid  = $user ? (int) $user->ID : -1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s OR user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d', PNChat_Store::questions_table(), $email, $uid, self::PAGE, ( max( 1, (int) $page ) - 1 ) * self::PAGE ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Exporter: one page per call; WordPress asks for the next page until
	 * «done» (a short page is the last one).
	 *
	 * @param string $email E-mail.
	 * @param int    $page  1-based page.
	 * @return array<string,mixed>
	 */
	public static function export( $email, $page = 1 ) {
		$items = array();
		$rows  = self::rows( (string) $email, (int) $page );
		foreach ( $rows as $r ) {
			$data = array(
				array(
					'name'  => 'Ερώτηση',
					'value' => (string) $r['question'],
				),
				array(
					'name'  => 'Ημερομηνία',
					'value' => (string) $r['created_at'],
				),
				array(
					'name'  => 'E-mail',
					'value' => (string) $r['email'],
				),
				array(
					'name'  => 'Όνομα',
					'value' => (string) $r['name'],
				),
				array(
					'name'  => 'Σελίδα',
					'value' => (string) $r['page_url'],
				),
				array(
					'name'  => 'Απάντηση με e-mail',
					'value' => (string) $r['reply'],
				),
			);
			$items[] = array(
				'group_id'    => 'pn-chat',
				'group_label' => 'Ερωτήσεις στο chat',
				'item_id'     => 'pn-chat-' . (int) $r['id'],
				'data'        => array_values(
					array_filter(
						$data,
						function ( $d ) {
							return '' !== $d['value'];
						}
					)
				),
			);
		}
		return array(
			'data' => $items,
			'done' => count( $rows ) < self::PAGE,
		);
	}

	/**
	 * Eraser: deletes a page of questions per call. Deleted rows leave the
	 * query, so every call reads the first page again.
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page (unused, see above).
	 * @return array<string,mixed>
	 */
	public static function erase( $email, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress passes it.
		$ids = array_map(
			function ( $r ) {
				return (int) $r['id'];
			},
			self::rows( (string) $email, 1 )
		);
		PNChat_Store::delete_questions( $ids );
		return array(
			'items_removed'  => count( $ids ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < self::PAGE,
		);
	}

	/**
	 * Suggested privacy-policy text.
	 *
	 * @return void
	 */
	public static function policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$days = (int) PNChat_Settings::value( 'retention_days' );
		wp_add_privacy_policy_content(
			'PN Chat',
			wp_kses_post(
				'<p>Το chat του site απαντά αυτόματα από πληροφορίες που έχουμε γράψει εμείς' . ( PNChat_Settings::value( 'ai_chat' ) ? '. ' : '· δεν χρησιμοποιεί υπηρεσίες τρίτων ή τεχνητή νοημοσύνη. ' ) . 'Αποθηκεύουμε τις ερωτήσεις που γίνονται, για να βελτιώνουμε τις απαντήσεις, και, αν μας το δώσετε, το e-mail σας για να σας απαντήσουμε. '
				. ( $days > 0 ? 'Σβήνονται αυτόματα μετά από ' . $days . ' ημέρες.' : '' )
				. ' Η διεύθυνση IP δεν αποθηκεύεται· για την προστασία από κατάχρηση κρατιέται μόνο ένα μη αναστρέψιμο αποτύπωμά της (hash), όσο διαρκεί το όριο ερωτήσεων (10 λεπτά έως μία ώρα), και σβήνεται με την επόμενη ερώτηση στο chat ή με τον ωριαίο καθαρισμό.</p>'
				. ( PNChat_Settings::value( 'ai_chat' ) ? '<p>Όταν δεν έχουμε έτοιμη απάντηση, το chat μπορεί να απαντήσει με τη βοήθεια της υπηρεσίας Claude της Anthropic: το κείμενο της ερώτησής σας και σχετικές δημόσιες σελίδες του site στέλνονται στην Anthropic, και η απάντηση σημειώνεται ως αυτόματη. Τα πεδία e-mail και ονόματος της φόρμας δεν στέλνονται· διευθύνσεις e-mail και αριθμοί τηλεφώνου ή ΑΜΚΑ μέσα στο κείμενο αντικαθίστανται πριν την αποστολή. Μη γράφετε στοιχεία ασθενών στο chat. Η αυτόματη απάντηση εμφανίζεται αμέσως και ελέγχεται από ανθρώπους πριν γίνει μόνιμη απάντηση του chat.</p>' : '' )
				. ( PNChat_Settings::value( 'ai_enabled' ) ? '<p>Για να γράφουμε τις απαντήσεις του chat, οι διαχειριστές μας μπορεί να στείλουν το κείμενο μιας ερώτησης (χωρίς τα πεδία e-mail και ονόματος, και με τα e-mail και τα τηλέφωνα μέσα στο κείμενο αντικατεστημένα) μαζί με δημόσιες σελίδες του site στην υπηρεσία Claude της Anthropic. Οι απαντήσεις ελέγχονται από ανθρώπους πριν χρησιμοποιηθούν.</p>' : '' )
			)
		);
	}
}
