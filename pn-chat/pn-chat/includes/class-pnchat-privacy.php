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
	 * Question ids of an e-mail (or of the user with that e-mail).
	 *
	 * @param string $email E-mail.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rows( $email ) {
		global $wpdb;
		$t    = PNChat_Store::questions_table();
		$user = get_user_by( 'email', $email );
		$uid  = $user ? (int) $user->ID : -1;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE email = %s OR user_id = %d ORDER BY id ASC LIMIT 500", $email, $uid ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Exporter.
	 *
	 * @param string $email E-mail.
	 * @return array<string,mixed>
	 */
	public static function export( $email ) {
		$items = array();
		foreach ( self::rows( (string) $email ) as $r ) {
			$items[] = array(
				'group_id'    => 'pn-chat',
				'group_label' => 'Ερωτήσεις στο chat',
				'item_id'     => 'pn-chat-' . (int) $r['id'],
				'data'        => array(
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
						'name'  => 'Απάντηση με e-mail',
						'value' => (string) $r['reply'],
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Eraser: deletes the questions.
	 *
	 * @param string $email E-mail.
	 * @return array<string,mixed>
	 */
	public static function erase( $email ) {
		$ids = array_map(
			function ( $r ) {
				return (int) $r['id'];
			},
			self::rows( (string) $email )
		);
		PNChat_Store::delete_questions( $ids );
		return array(
			'items_removed'  => count( $ids ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < 500,
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
				'<p>Το chat του site απαντά αυτόματα από πληροφορίες που έχουμε γράψει εμείς· δεν χρησιμοποιεί υπηρεσίες τρίτων ή τεχνητή νοημοσύνη. Αποθηκεύουμε τις ερωτήσεις που γίνονται, για να βελτιώνουμε τις απαντήσεις, και, αν μας το δώσετε, το e-mail σας για να σας απαντήσουμε. '
				. ( $days > 0 ? 'Σβήνονται αυτόματα μετά από ' . $days . ' ημέρες.' : '' )
				. ' Η διεύθυνση IP δεν αποθηκεύεται· χρησιμοποιείται προσωρινά, κρυπτογραφημένη, μόνο για προστασία από κατάχρηση.</p>'
			)
		);
	}
}
