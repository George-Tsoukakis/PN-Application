<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Σελίδα ρυθμίσεων του QR ReBuilder Pro (Settings API): πρόσβαση, email
 * αποστολέα, ανάλυση GS1, εκτύπωση και στατιστικά χρήσης. Οι έλεγχοι Site
 * Health ζουν στην QRRP_Site_Health.
 *
 * Κανόνας για όλους τους sanitizers: άκυρη υποβολή κρατά την προηγούμενη
 * τιμή και εμφανίζει προειδοποίηση· δεν μετατρέπεται σιωπηλά σε άλλη
 * έγκυρη τιμή. Οι ρυθμίσεις δικαιωμάτων αποτυγχάνουν κλειστά.
 */
final class QRRP_Admin {

	const OPTION_GROUP = 'qrrp_settings_group';
	const PAGE_SLUG    = 'qrrp-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_dependency_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_guest_email_notice' ) );
		add_action( 'admin_post_qrrp_hide_pharmacist_warning', array( __CLASS__, 'hide_pharmacist_warning' ) );
	}

	/**
	 * Η κρίση του Site Health για τη δημιουργία εικόνας, και ως ειδοποίηση στη
	 * σελίδα ρυθμίσεων (ίδιο κείμενο, μία πηγή).
	 */
	public static function maybe_show_dependency_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'toplevel_page_' . self::PAGE_SLUG !== $screen->id ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'QRRP_Site_Health' ) ) {
			return;
		}

		$verdict = QRRP_Site_Health::site_health_verdict( QRRP_Site_Health::image_diagnostics() );

		if ( 'good' === $verdict['status'] ) {
			return;
		}

		/* Η περιγραφή περιέχει ήδη escaped HTML (<p>, <code>). */
		$class = 'critical' === $verdict['status'] ? 'notice-error' : 'notice-warning';

		echo '<div class="notice ' . esc_attr( $class ) . '">'
			. wp_kses_post( $verdict['description'] )
			. '</div>';
	}

	/**
	 * 2.15.3: μετά την αναβάθμιση, email επισκεπτών ενεργό χωρίς επιτρεπτά
	 * domains σημαίνει ότι οι επισκέπτες δεν στέλνουν πια email. Ειδοποίηση στον
	 * Πίνακα Ελέγχου, στα Πρόσθετα και στις ρυθμίσεις, μέχρι να οριστεί λίστα
	 * (ή να κλείσει το email επισκεπτών, ή να υπάρξει δικό σας φίλτρο).
	 */
	public static function maybe_show_guest_email_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'toplevel_page_' . self::PAGE_SLUG ), true ) ) {
			return;
		}

		if (
			! current_user_can( 'manage_options' )
			|| '1' !== get_option( 'qrrp_allow_guest_email', '0' )
			|| qrrp_guest_email_has_recipients()
		) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>QR ReBuilder Pro:</strong> '
			. esc_html__( 'Από την 2.15.3 οι επισκέπτες στέλνουν email μόνο σε domains που ορίζετε. Η λίστα είναι κενή, οπότε το email επισκεπτών είναι προς το παρόν ανενεργό.', 'qr-rebuilder-pro' )
			. ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">'
			. esc_html__( 'Ορισμός επιτρεπτών domains', 'qr-rebuilder-pro' )
			. '</a></p></div>';
	}

	public static function register_menu() {
		add_menu_page(
			__( 'QR ReBuilder Pro — Ρυθμίσεις', 'qr-rebuilder-pro' ),
			__( 'QR ReBuilder Pro', 'qr-rebuilder-pro' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-camera',
			58
		);
	}

	public static function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_qrrp-settings' !== $hook ) {
			return;
		}

		$css_path = QRRP_PLUGIN_DIR . 'assets/css/qrrp-admin-style.css';

		wp_enqueue_style(
			'qrrp-admin-style',
			QRRP_PLUGIN_URL . 'assets/css/qrrp-admin-style.css',
			array(),
			qrrp_asset_version( $css_path )
		);
	}

	/**
	 * Η προεπιλογή του δικαιώματος email, από τη σταθερά του κεντρικού αρχείου.
	 * Fallback 'manage_options' (fail-closed). Ίδια λογική με την
	 * QRRP_Ajax::email_capability().
	 *
	 * @return string Βλ. qrrp_default_email_capability().
	 */
	private static function default_email_capability() {
		return qrrp_default_email_capability();
	}

	public static function register_settings() {
		$settings = array(
			'qrrp_capability'          => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_capability' ),
				'default'           => qrrp_default_tool_capability(),
			),
			'qrrp_tool_page_id'        => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_tool_page_id' ),
				'default'           => 0,
			),
			'qrrp_email_capability'    => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_email_capability' ),
				'default'           => self::default_email_capability(),
			),
			'qrrp_allow_guests'        => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_guest_access' ),
				'default'           => '0',
			),
			'qrrp_allow_guest_email'   => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_guest_email_access' ),
				'default'           => '0',
			),
			'qrrp_allow_guest_manual_entry' => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_guest_manual_entry' ),
				'default'           => '0',
			),
			'qrrp_guest_email_domains' => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_guest_email_domains' ),
				'default'           => '',
			),
			'qrrp_email_from_name'     => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_sender_name' ),
				'default'           => get_bloginfo( 'name' ),
			),
			'qrrp_email_from_address'  => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_sender_email' ),
				'default'           => get_option( 'admin_email' ),
			),
			'qrrp_sn_fallback_length'  => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_sn_fallback_length' ),
				'default'           => qrrp_default_gs1_fallback_length( 'sn' ),
			),
			'qrrp_lot_fallback_length' => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_lot_fallback_length' ),
				'default'           => qrrp_default_gs1_fallback_length( 'lot' ),
			),
			/*
			 * Οι προεπιλογές προέρχονται από τους resolvers του κεντρικού αρχείου, ώστε
			 * να μην αποκλίνουν. Όσο οι αναγνώστες περνούν δική τους προεπιλογή στο
			 * get_option(), η τιμή της register_setting() δεν διαβάζεται.
			 */
			'qrrp_print_barcode_mm'    => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_print_barcode_mm' ),
				'default'           => qrrp_default_print_barcode_mm(),
			),
			'qrrp_print_show_note'     => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_print_toggle' ),
				'default'           => qrrp_default_print_toggle(),
			),
			'qrrp_print_show_meta'     => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_print_toggle' ),
				'default'           => qrrp_default_print_toggle(),
			),
			'qrrp_print_orientation'   => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_print_orientation' ),
				'default'           => qrrp_default_print_orientation(),
			),
		);

		foreach ( $settings as $option_name => $args ) {
			register_setting( self::OPTION_GROUP, $option_name, $args );
		}

		add_settings_section(
			'qrrp_section_access',
			__( 'Πρόσβαση', 'qr-rebuilder-pro' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'qrrp_capability',
			__( 'Ποιος έχει πρόσβαση στο εργαλείο', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'field_capability' ),
			self::PAGE_SLUG,
			'qrrp_section_access',
			array( 'label_for' => 'qrrp_capability' )
		);

		add_settings_field(
			/*
			 * Οι επισκέπτες (qrrp_allow_guests) και το email επισκεπτών δεν έχουν δικό
			 * τους πεδίο: προκύπτουν από τις επιλογές πρόσβασης και email.
			 */
			'qrrp_allow_guest_manual_entry',
			__( 'Χειροκίνητη δημιουργία από επισκέπτες', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'field_allow_guest_manual_entry' ),
			self::PAGE_SLUG,
			'qrrp_section_access'
		);

		add_settings_field(
			'qrrp_email_capability',
			__( 'Ποιος μπορεί να στέλνει email', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'field_email_capability' ),
			self::PAGE_SLUG,
			'qrrp_section_access',
			array( 'label_for' => 'qrrp_email_capability' )
		);

		add_settings_field(
			'qrrp_guest_email_domains',
			__( 'Email επισκεπτών: επιτρεπτά domains', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'field_guest_email_domains' ),
			self::PAGE_SLUG,
			'qrrp_section_access',
			array( 'label_for' => 'qrrp_guest_email_domains' )
		);

		add_settings_field(
			'qrrp_tool_page_id',
			__( 'Σελίδα εργαλείου', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'field_tool_page_id' ),
			self::PAGE_SLUG,
			'qrrp_section_access',
			array( 'label_for' => 'qrrp_tool_page_id' )
		);

		add_settings_section(
			'qrrp_section_email',
			__( 'Email αποστολέα', 'qr-rebuilder-pro' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'qrrp_email_from_name',
			__( 'Όνομα αποστολέα', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'field_email_from_name' ),
			self::PAGE_SLUG,
			'qrrp_section_email',
			array( 'label_for' => 'qrrp_email_from_name' )
		);

		add_settings_field(
			'qrrp_email_from_address',
			__( 'Email αποστολέα', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'field_email_from_address' ),
			self::PAGE_SLUG,
			'qrrp_section_email',
			array( 'label_for' => 'qrrp_email_from_address' )
		);

		add_settings_section(
			'qrrp_section_parsing',
			__( 'Ανάλυση QR χωρίς διαχωριστικό (GS1 Group Separator)', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'section_parsing_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'qrrp_sn_fallback_length',
			__( 'Συνηθισμένο μήκος SN', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'field_sn_fallback_length' ),
			self::PAGE_SLUG,
			'qrrp_section_parsing',
			array( 'label_for' => 'qrrp_sn_fallback_length' )
		);

		add_settings_field(
			'qrrp_lot_fallback_length',
			__( 'Συνηθισμένο μήκος LOT', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'field_lot_fallback_length' ),
			self::PAGE_SLUG,
			'qrrp_section_parsing',
			array( 'label_for' => 'qrrp_lot_fallback_length' )
		);

		add_settings_section(
			'qrrp_section_print',
			__( 'Εκτύπωση', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'section_print_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'qrrp_print_orientation',
			__( 'Προσανατολισμός εκτύπωσης', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'field_print_orientation' ),
			self::PAGE_SLUG,
			'qrrp_section_print',
			array( 'label_for' => 'qrrp_print_orientation' )
		);

		add_settings_field(
			'qrrp_print_barcode_mm',
			__( 'Μέγεθος DataMatrix στην εκτύπωση (mm)', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'field_print_barcode_mm' ),
			self::PAGE_SLUG,
			'qrrp_section_print',
			array( 'label_for' => 'qrrp_print_barcode_mm' )
		);

		add_settings_field(
			'qrrp_print_show_note',
			__( 'Επεξηγηματικό σχόλιο στην εκτύπωση', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'field_print_show_note' ),
			self::PAGE_SLUG,
			'qrrp_section_print'
		);

		add_settings_field(
			'qrrp_print_show_meta',
			__( 'Πεδία PC/SN/LOT/EXP στην εκτύπωση', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'field_print_show_meta' ),
			self::PAGE_SLUG,
			'qrrp_section_print'
		);

		add_settings_section(
			/* Ενότητα μόνο προς ανάγνωση, χωρίς πεδία ή option. */
			'qrrp_section_stats',
			__( 'Χρήση εργαλείου', 'qr-rebuilder-pro' ),
			array( __CLASS__, 'section_stats' ),
			self::PAGE_SLUG
		);
	}

	/**
	 * Τα νούμερα χρήσης: τρέχων μήνας, προηγούμενος και σύνολο, με την
	 * αφετηρία της καταγραφής ώστε το σύνολο να μη διαβάζεται ως «από πάντα».
	 */
	public static function section_stats() {
		$data     = QRRP_Stats::read();
		$current  = QRRP_Stats::current_month();
		$previous = QRRP_Stats::previous_month();
		$month    = isset( $data['months'][ $current ] ) ? $data['months'][ $current ] : array();

		echo '<p class="description">';
		echo esc_html__( 'Επιτυχείς δημιουργίες κωδικού. Κάθε πάτημα «Δημιουργία» που παρήγαγε κωδικό μετράει ξεχωριστά — και οι αναδημιουργίες της ίδιας συσκευασίας.', 'qr-rebuilder-pro' );
		echo '</p>';

		if ( '' === $data['since'] ) {
			echo '<p>' . esc_html__( 'Δεν έχει καταγραφεί ακόμη καμία δημιουργία κωδικού.', 'qr-rebuilder-pro' ) . '</p>';

			return;
		}

		$cards = array(
			array(
				'label' => self::month_label( $current ),
				'value' => isset( $month['ok'] ) ? $month['ok'] : 0,
			),
		);

		/* Ο πρώτος μήνας λειτουργίας δεν έχει προηγούμενο. */
		if ( '' !== $previous && isset( $data['months'][ $previous ] ) ) {
			$cards[] = array(
				'label' => self::month_label( $previous ),
				'value' => $data['months'][ $previous ]['ok'],
			);
		}

		$cards[] = array(
			'label' => __( 'Σύνολο', 'qr-rebuilder-pro' ),
			'value' => $data['total_ok'],
		);

		echo '<div class="qrrp-stats-grid">';

		foreach ( $cards as $card ) {
			echo '<div class="qrrp-stat-card">';
			echo '<span class="qrrp-stat-label">' . esc_html( $card['label'] ) . '</span>';
			echo '<span class="qrrp-stat-value">' . esc_html( number_format_i18n( (int) $card['value'] ) ) . '</span>';
			echo '</div>';
		}

		echo '</div>';

		echo '<table class="qrrp-stats-meta"><tbody>';

		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html(
				sprintf(
					/* translators: %s: the current month, e.g. "Αύγουστος 2026". */
					__( 'Απορρίψεις (επικύρωση/προέλευση) (%s)', 'qr-rebuilder-pro' ),
					self::month_label( $current )
				)
			),
			esc_html( number_format_i18n( isset( $month['rejected'] ) ? (int) $month['rejected'] : 0 ) )
		);

		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html__( 'Καταγραφή από', 'qr-rebuilder-pro' ),
			esc_html( self::month_label( $data['since'] ) )
		);

		echo '</tbody></table>';

		self::render_scan_shape( $data, $current );

		echo '<p class="description">' . esc_html__( 'Καταγράφεται μόνο ο μήνας και ο αριθμός. Ποτέ PC, SN, LOT, EXP, χρήστης ή διεύθυνση.', 'qr-rebuilder-pro' ) . '</p>';
	}

	/**
	 * Το σχήμα των αναγνώσεων — μόνο για τον διαχειριστή, ποτέ δημόσια.
	 * Τα ποσοστά υπολογίζονται εδώ. Δεν εμφανίζεται τίποτα πριν από την πρώτη
	 * καταγεγραμμένη ανάγνωση.
	 *
	 * @param array  $data    Ό,τι επέστρεψε η QRRP_Stats::read().
	 * @param string $current Κλειδί τρέχοντος μήνα.
	 */
	private static function render_scan_shape( array $data, $current ) {
		$totals = isset( $data['totals'] ) && is_array( $data['totals'] ) ? $data['totals'] : array();
		$scans  = isset( $totals['scans'] ) ? (int) $totals['scans'] : 0;

		if ( $scans < 1 ) {
			return;
		}

		$month = isset( $data['months'][ $current ] ) && is_array( $data['months'][ $current ] ) ? $data['months'][ $current ] : array();

		$symbology_total = 0;
		$symbology_month = 0;

		foreach ( QRRP_Stats::SYMBOLOGY_BUCKETS as $bucket ) {
			$symbology_total += isset( $totals[ $bucket ] ) ? (int) $totals[ $bucket ] : 0;
			$symbology_month += isset( $month[ $bucket ] ) ? (int) $month[ $bucket ] : 0;
		}

		$month_scans = isset( $month['scans'] ) ? (int) $month['scans'] : 0;

		echo '<h3>' . esc_html__( 'Σχήμα αναγνώσεων', 'qr-rebuilder-pro' ) . '</h3>';

		echo '<p class="description">';
		echo esc_html__( 'Πόσο δυσκολεύτηκε η ανάγνωση της σάρωσης, πριν καν ζητηθεί κωδικός. Μετράει κάθε προσπάθεια ανάγνωσης ξεχωριστά — και τις επαναλήψεις της ίδιας συσκευασίας.', 'qr-rebuilder-pro' );
		echo '</p>';

		/*
		 * Οι σαρώσεις (endpoint parse) και οι δημιουργίες (endpoint rebuild)
		 * μετρώνται ανεξάρτητα και δεν είναι συγκρίσιμες — το λέει ρητά η οθόνη.
		 */
		echo '<p class="description">';
		echo esc_html__( 'Οι σαρώσεις και οι δημιουργίες μετρώνται ανεξάρτητα: μία σάρωση μπορεί να μη δώσει καμία δημιουργία, ή να δώσει πολλές. Η χειροκίνητη εισαγωγή δίνει δημιουργία χωρίς σάρωση. Οι δύο αριθμοί δεν είναι συγκρίσιμοι μεταξύ τους.', 'qr-rebuilder-pro' );
		echo '</p>';

		$rows = array(
			array( __( 'Προσπάθειες ανάγνωσης', 'qr-rebuilder-pro' ), 'scans' ),
			array( __( 'Χωρίς διαχωριστικά (χρειάστηκε συμπερασμός ορίων)', 'qr-rebuilder-pro' ), 'inferred' ),
			array( __( 'Ζητήθηκε επιβεβαίωση', 'qr-rebuilder-pro' ), 'confirm' ),
			array( __( 'Περισσότερες από μία πιθανές αναγνώσεις', 'qr-rebuilder-pro' ), 'ambiguous' ),
			array( __( 'Εντοπίστηκε επιπλέον AI', 'qr-rebuilder-pro' ), 'extra_ai' ),
			array( __( 'Χαμηλή βεβαιότητα', 'qr-rebuilder-pro' ), 'low' ),
			/*
			 * Διαμέριση κατά identifier. Για μήνες πριν από αυτή τη μέτρηση το άθροισμά
			 * τους είναι μικρότερο από τις προσπάθειες ανάγνωσης· τα ποσοστά τους
			 * υπολογίζονται επί του αθροίσματος και η οθόνη εξηγεί την κάλυψη.
			 */
			array( __( 'Ο σαρωτής έστειλε GS1 DataMatrix identifier (]d2)', 'qr-rebuilder-pro' ), 'symbology_d2' ),
			array( __( 'Ο σαρωτής έστειλε ΑΛΛΟ identifier', 'qr-rebuilder-pro' ), 'symbology_other' ),
			array( __( 'Χωρίς identifier (χειροκίνητη εισαγωγή ή σαρωτής που δεν τον στέλνει)', 'qr-rebuilder-pro' ), 'symbology_none' ),
		);

		echo '<table class="qrrp-stats-meta"><tbody>';

		printf(
			'<tr><th scope="row">&nbsp;</th><td>%s</td><td>%s</td></tr>',
			esc_html( self::month_label( $current ) ),
			esc_html__( 'Σύνολο', 'qr-rebuilder-pro' )
		);

		foreach ( $rows as $row ) {
			list( $label, $bucket ) = $row;

			$month_value  = isset( $month[ $bucket ] ) ? (int) $month[ $bucket ] : 0;
			$total_value  = isset( $totals[ $bucket ] ) ? (int) $totals[ $bucket ] : 0;
			$is_symbology = in_array( $bucket, QRRP_Stats::SYMBOLOGY_BUCKETS, true );

			printf(
				'<tr><th scope="row">%s</th><td>%s</td><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( number_format_i18n( $month_value ) ),
				esc_html( self::with_share( $total_value, $is_symbology ? $symbology_total : $scans, 'scans' === $bucket ) )
			);
		}

		echo '</tbody></table>';

		if ( $symbology_total < $scans || $symbology_month < $month_scans ) {
			echo '<p class="description">';
			printf(
				/* translators: 1: read attempts with a recorded identifier, 2: all read attempts. */
				esc_html__( 'Το identifier του σαρωτή καταγράφεται μόνο από τη στιγμή που προστέθηκε αυτή η μέτρηση, οπότε οι τρεις γραμμές identifier καλύπτουν %1$s από τις %2$s προσπάθειες ανάγνωσης. Τα ποσοστά τους υπολογίζονται επί των σαρώσεων με καταγεγραμμένο identifier.', 'qr-rebuilder-pro' ),
				esc_html( number_format_i18n( $symbology_total ) ),
				esc_html( number_format_i18n( $scans ) )
			);
			echo '</p>';
		}
	}

	/**
	 * «12 (1,4%)»: ο αριθμός με το μερίδιό του. Ο ίδιος ο παρονομαστής δεν
	 * παίρνει ποσοστό.
	 */
	private static function with_share( $value, $scans, $is_denominator ) {
		$value = (int) $value;

		if ( $is_denominator || $scans < 1 ) {
			return number_format_i18n( $value );
		}

		return sprintf(
			/* translators: 1: absolute count, 2: percentage of read attempts. */
			__( '%1$s (%2$s%%)', 'qr-rebuilder-pro' ),
			number_format_i18n( $value ),
			number_format_i18n( $value * 100 / $scans, 1 )
		);
	}

	/**
	 * '2026-08' → «Αύγουστος 2026», μεταφρασμένο μέσω wp_date(). Άκυρο κλειδί
	 * επιστρέφεται αυτούσιο.
	 */
	private static function month_label( $key ) {
		if ( ! QRRP_Stats::is_month_key( $key ) ) {
			return (string) $key;
		}

		preg_match( '/^(\d{4})-(\d{2})\z/', (string) $key, $m );

		$timestamp = gmmktime( 12, 0, 0, (int) $m[2], 1, (int) $m[1] );

		if ( false === $timestamp ) {
			return (string) $key;
		}

		return wp_date( 'F Y', $timestamp );
	}

	/**
	 * Οι επισκέπτες προκύπτουν από την επιλογή πρόσβασης: μόνο το «Ελεύθερο
	 * για όλους» (read) τους ανοίγει.
	 */
	private static function guests_from_access_choice() {
		return 'read' === self::posted_scalar( 'qrrp_capability' ) ? '1' : '0';
	}

	private static function is_settings_submission() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by core in options.php before sanitize callbacks run.
		return isset( $_POST['option_page'] )
			&& is_scalar( $_POST['option_page'] )
			&& self::OPTION_GROUP === (string) wp_unslash( $_POST['option_page'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private static function posted_scalar( $name ) {
		if ( ! self::is_settings_submission() ) {
			return '';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by core; the caller compares against fixed values.
		if ( isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] ) ) {
			return trim( (string) wp_unslash( $_POST[ $name ] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return '';
	}

	/** Υποβλήθηκε η τρέχουσα φόρμα πρόσβασης (κρυφό qrrp_access_ui); */
	private static function new_access_ui_submitted() {
		return '2' === self::posted_scalar( 'qrrp_access_ui' );
	}

	/**
	 * Τιμή μιας ρύθμισης από την τρέχουσα υποβολή, για να διαβάσει ένας
	 * sanitizer μια αδελφή ρύθμιση της ίδιας αποθήκευσης (π.χ. το email
	 * επισκεπτών εξαρτάται από την πρόσβαση). Διαβάζεται από το $_POST ώστε το
	 * αποτέλεσμα να μην εξαρτάται από τη σειρά αποθήκευσης των options.
	 *
	 * Καλείται μόνο από sanitize callbacks: σε υποβολή του options.php ο
	 * πυρήνας έχει ήδη ελέγξει nonce και δικαίωμα. Εκτός τέτοιας υποβολής
	 * επιστρέφεται η αποθηκευμένη τιμή. Η τιμή επιστρέφεται ακατέργαστη· την
	 * κανονικοποιεί ο καλών.
	 *
	 * @param string $option_name Όνομα ρύθμισης/πεδίου.
	 * @param mixed  $default     Προεπιλογή αν δεν υπάρχει αποθηκευμένη τιμή.
	 * @return mixed
	 */
	private static function submitted_value( $option_name, $default = '' ) {
		/* Στη φόρμα πρόσβασης οι επισκέπτες δεν υποβάλλονται· προκύπτουν. */
		if ( 'qrrp_allow_guests' === $option_name && self::new_access_ui_submitted() ) {
			return self::guests_from_access_choice();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability verified by core in wp-admin/options.php before this sanitize callback runs.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the calling register_setting() callback, which knows the expected type.
		if ( self::is_settings_submission() && isset( $_POST[ $option_name ] ) && is_scalar( $_POST[ $option_name ] ) ) {
			return wp_unslash( $_POST[ $option_name ] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return get_option( $option_name, $default );
	}

	private static function add_setting_warning( $code, $message ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION_GROUP,
				$code,
				$message,
				'warning'
			);
		}
	}

	/**
	 * Ρητή σελίδα εργαλείου. Το 0 σημαίνει αυτόματη ανίχνευση. Γίνεται δεκτή
	 * μόνο δημοσιευμένη σελίδα.
	 *
	 * @param mixed $value Η υποβληθείσα τιμή.
	 * @return int
	 */
	public static function sanitize_tool_page_id( $value ) {
		/*
		 * 2.15.7: χωρίς δημοσιευμένες σελίδες η wp_dropdown_pages() δεν τυπώνει
		 * πεδίο, οπότε το options.php περνά null. Δεν είναι άκυρη επιλογή: η τιμή
		 * κρατιέται σιωπηλά (πριν: προειδοποίηση σε κάθε αποθήκευση, που έκρυβε
		 * και το «Οι ρυθμίσεις αποθηκεύτηκαν»).
		 */
		if ( null === $value ) {
			return qrrp_tool_page_id();
		}

		if ( is_scalar( $value ) ) {
			$raw = trim( (string) $value );

			/*
			 * Το '0' αναγνωρίζεται από τη μορφή του, πριν από κάθε cast: (int) 'abc'
			 * είναι επίσης 0 και θα έσβηνε σιωπηλά τη ρητή επιλογή.
			 */
			if ( '0' === $raw ) {
				return 0;
			}

			if ( preg_match( '/^[1-9][0-9]*$/', $raw ) ) {
				$id = (int) $raw;

				/*
				 * 2.15.2: η ήδη αποθηκευμένη τιμή (π.χ. σελίδα που αποδημοσιεύτηκε και
				 * φαίνεται ως ξεχωριστή επιλογή) κρατιέται σιωπηλά· την προειδοποίηση
				 * τη δείχνει ήδη το πεδίο, ώστε να μην επαναλαμβάνεται σε κάθε αποθήκευση.
				 */
				if ( $id === qrrp_tool_page_id() ) {
					return $id;
				}

				if ( 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
					return $id;
				}
			}
		}

		self::add_setting_warning(
			'qrrp_invalid_tool_page',
			__( 'Η σελίδα εργαλείου δεν είναι δημοσιευμένη σελίδα. Η προηγούμενη επιλογή διατηρήθηκε.', 'qr-rebuilder-pro' )
		);

		return qrrp_tool_page_id();
	}

	public static function field_tool_page_id() {
		$selected = qrrp_tool_page_id();

		/*
		 * Η wp_dropdown_pages() τυπώνει το show_option_none χωρίς escaping, οπότε
		 * η μεταφρασμένη συμβολοσειρά περνά από esc_html__().
		 */
		$dropdown = wp_dropdown_pages(
			array(
				'name'              => 'qrrp_tool_page_id',
				'id'                => 'qrrp_tool_page_id',
				'selected'          => absint( $selected ),
				'show_option_none'  => esc_html__( '— Αυτόματη ανίχνευση —', 'qr-rebuilder-pro' ),
				'option_none_value' => 0,
				'post_status'       => 'publish',
				'echo'              => 0,
			)
		);

		/*
		 * 2.15.2: αν η αποθηκευμένη σελίδα δεν είναι πια δημοσιευμένη, η λίστα δεν
		 * τη δείχνει και ο browser θα έστελνε σιωπηλά «Αυτόματη ανίχνευση» στην
		 * επόμενη αποθήκευση. Μένει επιλεγμένη ως ξεχωριστή επιλογή, με σαφή
		 * ένδειξη· ο sanitizer κρατά την τιμή μέχρι να διαλέξει ο διαχειριστής.
		 */
		$unpublished = $selected > 0 && ! ( 'page' === get_post_type( $selected ) && 'publish' === get_post_status( $selected ) );

		if ( $unpublished && is_string( $dropdown ) && '' !== $dropdown ) {
			$title = get_the_title( $selected );
			$label = sprintf(
				/* translators: %s: page title or ID. */
				__( '%s (μη δημοσιευμένη — επιλέξτε άλλη)', 'qr-rebuilder-pro' ),
				'' !== $title ? $title : '#' . $selected
			);
			$extra    = '<option value="' . esc_attr( (string) $selected ) . '" selected="selected">' . esc_html( $label ) . '</option>';
			$dropdown = preg_replace_callback(
				'/<select[^>]*>/',
				static function ( $m ) use ( $extra ) {
					return $m[0] . $extra;
				},
				$dropdown,
				1
			);
		}

		if ( is_string( $dropdown ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup from wp_dropdown_pages(); the added option is escaped above.
			echo $dropdown;
		}

		if ( $unpublished ) {
			?>
			<p class="description qrrp-field-warning">
				<?php esc_html_e( 'Η ρυθμισμένη σελίδα εργαλείου δεν είναι πλέον δημοσιευμένη. Επιλέξτε μια δημοσιευμένη σελίδα ή «Αυτόματη ανίχνευση» και αποθηκεύστε.', 'qr-rebuilder-pro' ); ?>
			</p>
			<?php
		}
		?>
		<p class="description">
			<?php
			echo wp_kses_post(
				__( 'Η σελίδα που περιέχει το shortcode. Όταν οριστεί ρητά, ο αποκλεισμός της από full-page cache και ο σύνδεσμος στο email γίνονται βέβαιοι αντί για εικασία — και δουλεύουν ακόμη κι όταν η σελίδα είναι φτιαγμένη με page builder.', 'qr-rebuilder-pro' )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Δικαίωμα χρήσης του εργαλείου, fail-closed.
	 *
	 *   read | edit_posts | manage_options | qrrp_pharmacist → δεκτές
	 *   read_members («Μόνο συνδεδεμένοι»)                  → read (χωρίς επισκέπτες)
	 *   άκυρη τιμή                     → κρατιέται η αποθηκευμένη, με προειδοποίηση
	 *   άκυρη και αποθηκευμένη άκυρη   → manage_options
	 *
	 * Η λίστα αποδεκτών τιμών ζει στο κεντρικό αρχείο (κοινή με την QRRP_Ajax).
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function sanitize_capability( $value ) {
		if ( is_scalar( $value ) && 'read_members' === trim( (string) $value ) ) {
			return 'read';
		}

		if ( qrrp_is_allowed_tool_capability( $value ) ) {
			return sanitize_key( trim( (string) $value ) );
		}

		self::add_setting_warning(
			'qrrp_invalid_capability',
			__( 'Η τιμή για το δικαίωμα χρήσης του εργαλείου δεν ήταν έγκυρη και αγνοήθηκε. Η προηγούμενη ρύθμιση διατηρήθηκε.', 'qr-rebuilder-pro' )
		);

		$stored = get_option( 'qrrp_capability', null );

		if ( qrrp_is_allowed_tool_capability( $stored ) ) {
			return sanitize_key( trim( (string) $stored ) );
		}

		return 'manage_options';
	}

	/**
	 * Δικαίωμα αποστολής email.
	 *
	 *   free                → '' (ίδιο με το εργαλείο· ανοίγει και το email επισκεπτών)
	 *   ''                  → ρητή επιλογή «ίδιο με το εργαλείο»
	 *   edit_posts | manage_options | qrrp_pharmacist → δεκτές
	 *   οτιδήποτε άλλο      → κρατιέται η αποθηκευμένη, με προειδοποίηση·
	 *                         αν κι εκείνη είναι άκυρη, η ασφαλής προεπιλογή
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function sanitize_email_capability( $value ) {
		if ( is_scalar( $value ) && 'free' === trim( (string) $value ) ) {
			return '';
		}

		$allowed = qrrp_allowed_email_capabilities();

		if ( is_scalar( $value ) && self::is_valid_capability_choice( $value, $allowed, true ) ) {
			return sanitize_key( (string) $value );
		}

		self::add_setting_warning(
			'qrrp_invalid_email_capability',
			__( 'Η τιμή για το δικαίωμα αποστολής email δεν ήταν έγκυρη και αγνοήθηκε. Η προηγούμενη ρύθμιση διατηρήθηκε.', 'qr-rebuilder-pro' )
		);

		$stored = get_option( 'qrrp_email_capability', null );

		if ( is_scalar( $stored ) && self::is_valid_capability_choice( $stored, $allowed, true ) ) {
			return sanitize_key( (string) $stored );
		}

		return self::default_email_capability();
	}

	/**
	 * Είναι η τιμή θεμιτή επιλογή δικαιώματος; Το κενό γίνεται δεκτό μόνο όταν
	 * η ακατέργαστη τιμή ήταν κενή — όχι όταν την άδειασε η sanitize_key()
	 * (π.χ. μη ASCII σκουπίδι), γιατί το κενό σημαίνει «ίδιο με το εργαλείο».
	 *
	 * @param mixed $value       Ακατέργαστη τιμή (scalar).
	 * @param array $allowed     Οι μη κενές αποδεκτές τιμές.
	 * @param bool  $allow_empty Αν το ρητό κενό είναι θεμιτή επιλογή.
	 * @return bool
	 */
	private static function is_valid_capability_choice( $value, array $allowed, $allow_empty ) {
		$raw = trim( (string) $value );

		if ( '' === $raw ) {
			return (bool) $allow_empty;
		}

		return in_array( sanitize_key( $raw ), $allowed, true );
	}

	/**
	 * Πρόσβαση επισκεπτών: από τη φόρμα πρόσβασης προκύπτει από την επιλογή
	 * «Ελεύθερο για όλους»· αλλιώς (π.χ. update_option από κώδικα) ισχύει η
	 * ρητή τιμή. Επιτρέπεται μόνο όταν το δικαίωμα χρήσης είναι 'read'.
	 *
	 * @param mixed $value
	 * @return string '1' | '0'
	 */
	public static function sanitize_guest_access( $value ) {
		if ( self::new_access_ui_submitted() ) {
			$value = self::guests_from_access_choice();
		}

		$enabled = is_scalar( $value ) && '1' === (string) $value;

		if ( ! $enabled ) {
			return '0';
		}

		$capability = self::sanitize_capability(
			self::submitted_value( 'qrrp_capability', qrrp_default_tool_capability() )
		);

		if ( 'read' !== $capability ) {
			self::add_setting_warning(
				'qrrp_guest_access_disabled',
				__( 'Η πρόσβαση επισκεπτών χωρίς σύνδεση απενεργοποιήθηκε, επειδή υπάρχει μόνο όταν η πρόσβαση είναι «Ελεύθερο για όλους».', 'qr-rebuilder-pro' )
			);
			return '0';
		}

		return '1';
	}

	/**
	 * 2.15.3: λίστα domains, ένα ανά γραμμή. 2.15.4: όσα απορρίπτονται (άκυρα ή
	 * πάνω από 50) αναφέρονται στον διαχειριστή αντί να χάνονται σιωπηλά. Μία
	 * φορά ανά αίτημα: το WordPress μπορεί να καλέσει τον sanitizer δύο φορές.
	 */
	public static function sanitize_guest_email_domains( $value ) {
		static $reported = false;

		$rejected = array();
		$webmail  = array();
		$domains  = qrrp_parse_email_domains( is_scalar( $value ) ? (string) $value : '', $rejected );
		$domains  = qrrp_without_webmail_domains( $domains, $webmail );

		if ( ! $reported && function_exists( 'add_settings_error' ) && ( array() !== $rejected || array() !== $webmail ) ) {
			$reported = true;

			if ( array() !== $rejected ) {
				add_settings_error(
					'qrrp_guest_email_domains',
					'qrrp_guest_email_domains_rejected',
					sprintf(
						/* translators: %s: comma-separated list of rejected entries. */
						__( 'Email επισκεπτών: αγνοήθηκαν όσα δεν είναι έγκυρα domains (ή περισσεύουν πάνω από 50): %s', 'qr-rebuilder-pro' ),
						implode( ', ', array_map( 'sanitize_text_field', array_slice( $rejected, 0, 20 ) ) )
					),
					'warning'
				);
			}

			/* 2.15.5: δημόσιες υπηρεσίες email δεν αποθηκεύονται· θα άνοιγαν το email επισκεπτών σε όλους. */
			if ( array() !== $webmail ) {
				add_settings_error(
					'qrrp_guest_email_domains',
					'qrrp_guest_email_domains_webmail',
					sprintf(
						/* translators: %s: comma-separated list of removed public email domains, e.g. gmail.com. */
						__( 'Email επισκεπτών: αφαιρέθηκαν δημόσιες υπηρεσίες email, γιατί θα επέτρεπαν σε οποιονδήποτε επισκέπτη να στέλνει email από το site σας σε όλους τους χρήστες τους: %s. Βάλτε μόνο το domain του φαρμακείου σας.', 'qr-rebuilder-pro' ),
						implode( ', ', array_map( 'sanitize_text_field', array_slice( $webmail, 0, 20 ) ) )
					),
					'warning'
				);
			}
		}

		return implode( "\n", $domains );
	}

	/**
	 * Email από επισκέπτες: από τη φόρμα προκύπτει από την επιλογή «Ελεύθερο
	 * για όλους» στο email. Ενεργοποιείται μόνο μαζί με την πρόσβαση επισκεπτών.
	 *
	 * @param mixed $value
	 * @return string '1' | '0'
	 */
	public static function sanitize_guest_email_access( $value ) {
		if ( self::new_access_ui_submitted() ) {
			$value = 'free' === self::posted_scalar( 'qrrp_email_capability' ) ? '1' : '0';
		}

		$enabled = is_scalar( $value ) && '1' === (string) $value;

		if ( ! $enabled ) {
			return '0';
		}

		$capability = self::sanitize_capability(
			self::submitted_value( 'qrrp_capability', qrrp_default_tool_capability() )
		);
		$guests_allowed = '1' === (string) self::submitted_value( 'qrrp_allow_guests', '0' );

		if ( 'read' !== $capability || ! $guests_allowed ) {
			self::add_setting_warning(
				'qrrp_guest_email_disabled',
				__( 'Η αποστολή email από επισκέπτες απενεργοποιήθηκε, επειδή ισχύει μόνο όταν η πρόσβαση είναι «Ελεύθερο για όλους».', 'qr-rebuilder-pro' )
			);
			return '0';
		}

		return '1';
	}

	/** Checkbox: ό,τι δεν είναι ρητά '1' (και το null όταν λείπει) σημαίνει κλειστό. */
	public static function sanitize_guest_manual_entry( $value ) {
		return is_scalar( $value ) && '1' === (string) $value ? '1' : '0';
	}

	public static function sanitize_sender_name( $value ) {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		if ( '' === $value ) {
			$value = sanitize_text_field( (string) get_bloginfo( 'name' ) );
		}

		return QRRP_Text::truncate( $value, 150 );
	}

	public static function sanitize_sender_email( $value ) {
		$email = is_scalar( $value ) ? sanitize_email( (string) $value ) : '';

		if ( is_email( $email ) ) {
			return $email;
		}

		$fallback = sanitize_email( (string) get_option( 'admin_email' ) );

		self::add_setting_warning(
			'qrrp_invalid_sender_email',
			__( 'Το email αποστολέα δεν ήταν έγκυρο. Χρησιμοποιήθηκε το admin email του WordPress.', 'qr-rebuilder-pro' )
		);

		return is_email( $fallback ) ? $fallback : '';
	}

	/**
	 * Clamp για εμφάνιση (όχι για αποθήκευση): πάντα αριθμός μέσα στο εύρος.
	 * absint() όπως ο QRRP_GS1_Parser::option_length(), ώστε να συμφωνούν.
	 *
	 * @param mixed $value
	 * @return int
	 */
	public static function clamp_gs1_length( $value ) {
		$range = qrrp_gs1_length_range();
		$value = is_scalar( $value ) ? absint( $value ) : 0;

		return min( (int) $range['max'], max( (int) $range['min'], $value ) );
	}

	/**
	 * Έγκυρο μήκος GS1 fallback ή null. Τα boolean απορρίπτονται ρητά.
	 *
	 * @param mixed $value
	 * @return int|null
	 */
	private static function valid_gs1_length( $value ) {
		$range = qrrp_gs1_length_range();

		if ( is_bool( $value ) || ! is_scalar( $value ) ) {
			return null;
		}

		$raw = trim( (string) $value );

		if ( 1 !== preg_match( '/^[0-9]+$/', $raw ) ) {
			return null;
		}

		$number = (int) $raw;

		if ( $number < (int) $range['min'] || $number > (int) $range['max'] ) {
			return null;
		}

		return $number;
	}

	/**
	 * Κοινός sanitizer για τα μήκη SN/LOT: έγκυρη τιμή αποθηκεύεται, άκυρη
	 * κρατά την προηγούμενη με προειδοποίηση. Οι δύο wrappers υπάρχουν επειδή
	 * ο callback της register_setting() δεν μαθαίνει το όνομα της ρύθμισης.
	 *
	 * @param mixed  $value  Η υποβληθείσα τιμή.
	 * @param string $option Το όνομα της ρύθμισης.
	 * @param string $field  'sn' ή 'lot'.
	 * @param string $label  Ετικέτα για το μήνυμα.
	 * @return int
	 */
	private static function sanitize_gs1_length_for( $value, $option, $field, $label ) {
		$range     = qrrp_gs1_length_range();
		$submitted = self::valid_gs1_length( $value );

		if ( null !== $submitted ) {
			return $submitted;
		}

		/* Άκυρη αποθηκευμένη τιμή → κεντρική προεπιλογή, όχι το πάτωμα του εύρους. */
		$stored   = self::valid_gs1_length( get_option( $option, false ) );
		$previous = ( null !== $stored ) ? $stored : (int) qrrp_default_gs1_fallback_length( $field );

		self::add_setting_warning(
			'qrrp_invalid_' . $field . '_fallback_length',
			sprintf(
				/* translators: 1: setting label, 2: minimum, 3: maximum, 4: value kept. */
				__( 'Το «%1$s» δέχεται ακέραιο από %2$d ως %3$d. Η τιμή που δώσατε δεν είναι έγκυρη και δεν αποθηκεύτηκε — διατηρήθηκε η προηγούμενη (%4$d).', 'qr-rebuilder-pro' ),
				$label,
				(int) $range['min'],
				(int) $range['max'],
				$previous
			)
		);

		return $previous;
	}

	public static function sanitize_sn_fallback_length( $value ) {
		return self::sanitize_gs1_length_for(
			$value,
			'qrrp_sn_fallback_length',
			'sn',
			__( 'Τυπικό μήκος σειριακού αριθμού (SN)', 'qr-rebuilder-pro' )
		);
	}

	public static function sanitize_lot_fallback_length( $value ) {
		return self::sanitize_gs1_length_for(
			$value,
			'qrrp_lot_fallback_length',
			'lot',
			__( 'Τυπικό μήκος παρτίδας (LOT)', 'qr-rebuilder-pro' )
		);
	}

	/**
	 * Πλάτος του κωδικού στην εκτύπωση (mm). Εύρος και προεπιλογή από το
	 * κεντρικό αρχείο· η μορφή ελέγχεται πριν από το cast (όχι absint, που θα
	 * έκανε το -5 έγκυρο 5). Άκυρη υποβολή κρατά την προηγούμενη τιμή.
	 *
	 * @param mixed $value Ακατέργαστη τιμή φόρμας.
	 * @return int
	 */
	public static function sanitize_print_barcode_mm( $value ) {
		if ( qrrp_print_barcode_mm_is_valid( $value ) ) {
			return qrrp_clamp_print_barcode_mm( (int) trim( (string) $value ) );
		}

		self::add_setting_warning(
			'qrrp_invalid_print_barcode_mm',
			__( 'Το μέγεθος του κωδικού δεν ήταν έγκυρος αριθμός χιλιοστών. Η προηγούμενη τιμή διατηρήθηκε.', 'qr-rebuilder-pro' )
		);

		return qrrp_print_barcode_mm();
	}

	/**
	 * Checkbox χωρίς hidden πεδίο: όταν ξετσεκαριστεί το options.php περνά null,
	 * που πρέπει να γίνει '0'. Διαφέρει σκόπιμα από τον resolver ανάγνωσης, που
	 * για κατεστραμμένη τιμή δίνει την προεπιλογή.
	 *
	 * @param mixed $value Ακατέργαστη τιμή φόρμας ('1' ή null).
	 * @return string '1' | '0'
	 */
	public static function sanitize_print_toggle( $value ) {
		return '1' === trim( (string) ( is_scalar( $value ) ? $value : '' ) ) ? '1' : '0';
	}

	/**
	 * Προσανατολισμός εκτύπωσης· οι επιτρεπτές τιμές ζουν στο κεντρικό αρχείο.
	 * Άκυρη υποβολή κρατά την προηγούμενη τιμή.
	 *
	 * @param mixed $value Ακατέργαστη τιμή φόρμας.
	 * @return string
	 */
	public static function sanitize_print_orientation( $value ) {
		if ( qrrp_print_orientation_is_valid( $value ) ) {
			return sanitize_key( trim( (string) $value ) );
		}

		self::add_setting_warning(
			'qrrp_invalid_print_orientation',
			__( 'Ο προσανατολισμός εκτύπωσης δεν ήταν έγκυρη επιλογή. Η προηγούμενη τιμή διατηρήθηκε.', 'qr-rebuilder-pro' )
		);

		return qrrp_print_orientation();
	}

	/**
	 * Επιλογή πρόσβασης:
	 *   read            → «Ελεύθερο για όλους» (και επισκέπτες)
	 *   read_members    → «Μόνο συνδεδεμένοι χρήστες» (read χωρίς επισκέπτες)
	 *   qrrp_pharmacist → «Μόνο φαρμακοποιοί»
	 *   manage_options  → «Μόνο διαχειριστές»
	 * Η παλιά τιμή edit_posts εμφανίζεται μόνο αν είναι ήδη αποθηκευμένη, ώστε
	 * μια αποθήκευση να μην την αλλάξει σιωπηλά.
	 */
	public static function field_capability() {
		$value  = self::sanitize_capability( get_option( 'qrrp_capability', qrrp_default_tool_capability() ) );
		$guests = '1' === get_option( 'qrrp_allow_guests', '0' );

		$members_only = ( 'read' === $value && ! $guests );
		$shown        = $members_only ? 'read_members' : $value;
		?>
		<input type="hidden" name="qrrp_access_ui" value="2" />
		<select name="qrrp_capability" id="qrrp_capability">
			<option value="read" <?php selected( $shown, 'read' ); ?>><?php esc_html_e( 'Ελεύθερο για όλους (και χωρίς σύνδεση)', 'qr-rebuilder-pro' ); ?></option>
			<option value="read_members" <?php selected( $shown, 'read_members' ); ?>><?php esc_html_e( 'Μόνο συνδεδεμένοι χρήστες', 'qr-rebuilder-pro' ); ?></option>
			<option value="qrrp_pharmacist" <?php selected( $value, 'qrrp_pharmacist' ); ?>><?php esc_html_e( 'Μόνο φαρμακοποιοί', 'qr-rebuilder-pro' ); ?></option>
			<option value="manage_options" <?php selected( $value, 'manage_options' ); ?>><?php esc_html_e( 'Μόνο διαχειριστές', 'qr-rebuilder-pro' ); ?></option>
			<?php if ( 'edit_posts' === $value ) : ?>
				<option value="edit_posts" <?php selected( $value, 'edit_posts' ); ?>><?php esc_html_e( 'Συνεργάτες και άνω (παλαιότερη ρύθμιση)', 'qr-rebuilder-pro' ); ?></option>
			<?php endif; ?>
		</select>
		<p class="description">
			<?php esc_html_e( '«Ελεύθερο για όλους»: το εργαλείο λειτουργεί για οποιονδήποτε, ακόμη και χωρίς λογαριασμό. «Μόνο συνδεδεμένοι χρήστες»: οποιοσδήποτε έχει συνδεθεί με λογαριασμό του site. «Μόνο φαρμακοποιοί»: μόνο συνδεδεμένοι λογαριασμοί με Κατηγορία «Φαρμακείο» (από τη φόρμα εγγραφής). Οι διαχειριστές έχουν πάντα πρόσβαση.', 'qr-rebuilder-pro' ); ?>
		</p>
		<?php
		self::render_pharmacist_registration_warning( $value );
	}

	/**
	 * Επιλογή δικαιώματος email: free / qrrp_pharmacist / manage_options. Μια
	 * παλαιότερη αποθηκευμένη τιμή εμφανίζεται με το πραγματικό της όνομα, ώστε
	 * η οθόνη να δείχνει ό,τι ισχύει.
	 */
	public static function field_email_capability() {
		$value       = self::sanitize_email_capability( get_option( 'qrrp_email_capability', self::default_email_capability() ) );
		$guest_email = '1' === get_option( 'qrrp_allow_guest_email', '0' );

		$tool   = qrrp_tool_capability();
		$legacy = '';

		if ( $guest_email ) {
			$shown = 'free';
		} elseif ( in_array( $value, array( 'manage_options', 'qrrp_pharmacist' ), true ) ) {
			$shown = $value;
		} elseif ( '' === $value && in_array( $tool, array( 'manage_options', 'qrrp_pharmacist' ), true ) ) {
			$shown = $tool;
		} else {
			$shown  = $value;
			$legacy = ( '' === $value )
				? __( 'Όλοι οι συνδεδεμένοι χρήστες (παλαιότερη ρύθμιση)', 'qr-rebuilder-pro' )
				: __( 'Συνεργάτες και άνω (παλαιότερη ρύθμιση)', 'qr-rebuilder-pro' );
		}
		?>
		<select name="qrrp_email_capability" id="qrrp_email_capability">
			<option value="free" <?php selected( $shown, 'free' ); ?>><?php esc_html_e( 'Ελεύθερο για όλους (και χωρίς σύνδεση)', 'qr-rebuilder-pro' ); ?></option>
			<option value="qrrp_pharmacist" <?php selected( $shown, 'qrrp_pharmacist' ); ?>><?php esc_html_e( 'Μόνο φαρμακοποιοί', 'qr-rebuilder-pro' ); ?></option>
			<option value="manage_options" <?php selected( $shown, 'manage_options' ); ?>><?php esc_html_e( 'Μόνο διαχειριστές', 'qr-rebuilder-pro' ); ?></option>
			<?php if ( '' !== $legacy ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" selected='selected'><?php echo esc_html( $legacy ); ?></option>
			<?php endif; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Ποιος μπορεί να στέλνει τον κωδικό με email. Δεν μπορεί να είναι πιο ανοιχτό από την πρόσβαση στο εργαλείο. Με «Ελεύθερο για όλους» οι ανώνυμοι επισκέπτες στέλνουν μόνο στα domains της λίστας «Email επισκεπτών: επιτρεπτά domains».', 'qr-rebuilder-pro' ); ?>
		</p>
		<?php
		self::render_pharmacist_registration_warning( $shown );
	}

	public static function field_allow_guest_manual_entry() {
		$value = get_option( 'qrrp_allow_guest_manual_entry', '0' );
		?>
		<label>
			<input
				type="checkbox"
				name="qrrp_allow_guest_manual_entry"
				value="1"
				<?php checked( $value, '1' ); ?>
			/>
			<?php esc_html_e( 'Να επιτρέπεται σε μη συνδεδεμένους επισκέπτες να δημιουργούν κωδικό χειροκίνητα, χωρίς σάρωση', 'qr-rebuilder-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Ο επισκέπτης πληκτρολογεί PC, SN, LOT και EXP και επιβεβαιώνει ότι τα διάβασε από τη συσκευασία. Ισχύει μόνο όταν η πρόσβαση είναι «Ελεύθερο για όλους». Αν το απενεργοποιήσετε, η χειροκίνητη δημιουργία και η αλλαγή τιμών που δεν προκύπτουν από τη σάρωση μένουν μόνο για όσους έχουν συνδεθεί.', 'qr-rebuilder-pro' ); ?>
		</p>
		<p class="description">
			<strong><?php esc_html_e( 'Όριο:', 'qr-rebuilder-pro' ); ?></strong>
			<?php esc_html_e( 'ο server δεν μπορεί να αποδείξει ότι μια «σάρωση» έγινε με πραγματικό σαρωτή. Ένας επισκέπτης με τεχνικές γνώσεις μπορεί να στείλει δικά του δεδομένα ως σάρωση. Γι\' αυτό ό,τι δημιουργεί επισκέπτης σημειώνεται πάντα ως «Δηλωμένο από τον χρήστη». Για πραγματικό έλεγχο, κρατήστε το εργαλείο για συνδεδεμένους χρήστες.', 'qr-rebuilder-pro' ); ?>
		</p>
		<?php
	}

	/** 2.15.3: domains στα οποία μπορεί να στείλει email ένας επισκέπτης. */
	public static function field_guest_email_domains() {
		$value = get_option( 'qrrp_guest_email_domains', '' );
		$value = is_scalar( $value ) ? (string) $value : '';
		?>
		<textarea class="regular-text" rows="3" id="qrrp_guest_email_domains" name="qrrp_guest_email_domains" placeholder="pharmacyneeds.gr"><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'Ένα domain ανά γραμμή, π.χ. pharmacyneeds.gr. Ισχύει μόνο όταν το email είναι «Ελεύθερο για όλους». Κενό = οι επισκέπτες δεν στέλνουν email πουθενά. Κάθε διεύθυνση δέχεται έως 3 email το 24ωρο από επισκέπτες.', 'qr-rebuilder-pro' ); ?>
			<?php if ( ! qrrp_guest_email_webmail_allowed() ) : ?>
				<?php esc_html_e( 'Δημόσιες υπηρεσίες email (gmail.com, outlook.com κ.λπ.) δεν γίνονται δεκτές.', 'qr-rebuilder-pro' ); ?>
			<?php endif; ?>
		</p>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: emails per logged-in user per 24 hours, 2: emails from one logged-in user to the same address per 24 hours. */
					__( 'Η λίστα δεν αφορά τους συνδεδεμένους χρήστες. Εκείνοι στέλνουν έως %1$d email το 24ωρο ο καθένας και έως %2$d στην ίδια διεύθυνση· οι διαχειριστές εξαιρούνται.', 'qr-rebuilder-pro' ),
					qrrp_user_email_daily_limit(),
					qrrp_user_email_per_recipient_limit()
				)
			);
			?>
		</p>
		<?php
		$qrrp_ignored_webmail = array();
		qrrp_without_webmail_domains( qrrp_parse_email_domains( $value ), $qrrp_ignored_webmail );
		?>
		<?php if ( array() !== $qrrp_ignored_webmail ) : ?>
			<p class="description qrrp-field-warning">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: comma-separated list of ignored public email domains, e.g. gmail.com. */
						__( 'Από την 2.15.5 αγνοούνται δημόσιες υπηρεσίες email της λίστας (%s): θα επέτρεπαν σε οποιονδήποτε επισκέπτη να στέλνει email από το site σας σε όλους τους χρήστες τους. Αποθηκεύστε ξανά τις ρυθμίσεις για να αφαιρεθούν.', 'qr-rebuilder-pro' ),
						implode( ', ', $qrrp_ignored_webmail )
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( array() === qrrp_guest_email_domains() && '1' === get_option( 'qrrp_allow_guest_email', '0' ) ) : ?>
			<p class="description" style="color:#b32d2e">
				<?php esc_html_e( 'Το email επισκεπτών είναι ενεργό αλλά η λίστα είναι κενή: από την 2.15.3 οι επισκέπτες δεν μπορούν να στείλουν email μέχρι να προσθέσετε domains.', 'qr-rebuilder-pro' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * 2.15.3: προειδοποίηση όταν το «Μόνο φαρμακοποιοί» στηρίζεται σε αυτο-δήλωση
	 * με ανοιχτή εγγραφή χρηστών και χωρίς δικό σας έλεγχο (φίλτρο qrrp_is_pharmacist).
	 */
	private static function render_pharmacist_registration_warning( $capability ) {
		$open = is_multisite()
			? in_array( get_site_option( 'registration', 'none' ), array( 'user', 'all' ), true )
			: (bool) get_option( 'users_can_register' );

		if (
			'qrrp_pharmacist' !== $capability
			|| ! $open
			|| has_filter( 'qrrp_is_pharmacist' )
			|| get_user_meta( get_current_user_id(), 'qrrp_hide_pharmacist_warning', true )
		) {
			return;
		}

		$hide_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=qrrp_hide_pharmacist_warning' ),
			'qrrp_hide_pharmacist_warning'
		);
		?>
		<p class="description" style="color:#b32d2e">
			<strong><?php esc_html_e( 'Προσοχή:', 'qr-rebuilder-pro' ); ?></strong>
			<?php esc_html_e( 'η εγγραφή χρηστών είναι ανοιχτή και το «Φαρμακείο» το δηλώνει ο ίδιος ο χρήστης στη φόρμα εγγραφής. Οποιοσδήποτε εγγραφεί ως «Φαρμακείο» παίρνει αυτό το δικαίωμα. Για επαληθευμένους λογαριασμούς, συνδέστε το φίλτρο qrrp_is_pharmacist με την έγκριση λογαριασμών του site.', 'qr-rebuilder-pro' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Αν εγκρίνετε κάθε λογαριασμό χειροκίνητα πριν μπορέσει να συνδεθεί, η προειδοποίηση δεν σας αφορά.', 'qr-rebuilder-pro' ); ?>
			<a href="<?php echo esc_url( $hide_url ); ?>"><?php esc_html_e( 'Απόκρυψη', 'qr-rebuilder-pro' ); ?></a>
		</p>
		<?php
	}

	/**
	 * 2.15.4: κρύβει την προειδοποίηση για τον τρέχοντα διαχειριστή (user meta).
	 * Αλλάζει μόνο την εμφάνιση· ο έλεγχος πρόσβασης μένει ίδιος.
	 */
	public static function hide_pharmacist_warning() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.', 'qr-rebuilder-pro' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'qrrp_hide_pharmacist_warning' );

		update_user_meta( get_current_user_id(), 'qrrp_hide_pharmacist_warning', '1' );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public static function field_email_from_name() {
		$value = get_option( 'qrrp_email_from_name', get_bloginfo( 'name' ) );
		?>
		<input type="text" class="regular-text" id="qrrp_email_from_name" name="qrrp_email_from_name" maxlength="150" value="<?php echo esc_attr( $value ); ?>" />
		<?php
	}

	public static function field_email_from_address() {
		$value = get_option( 'qrrp_email_from_address', get_option( 'admin_email' ) );
		?>
		<input type="email" class="regular-text" id="qrrp_email_from_address" name="qrrp_email_from_address" maxlength="254" value="<?php echo esc_attr( $value ); ?>" />
		<?php
	}

	public static function section_parsing_intro() {
		?>
		<p class="description">
			<?php esc_html_e( 'Όταν ένας κωδικός δεν περιέχει τον αόρατο χαρακτήρα διαχωρισμού GS1, το plugin δοκιμάζει τα επιτρεπτά όρια για SN και LOT και επιλέγει την πιο συνεπή ερμηνεία. Αν προκύψει μία μοναδική, πλήρης και έγκυρη λύση, η ανάλυση συνεχίζει χωρίς υποχρεωτική επιβεβαίωση. Χειροκίνητος έλεγχος ζητείται μόνο όταν υπάρχει πραγματική αμφιβολία ή πρόβλημα εγκυρότητας. Αν υπάρχει πραγματικός Group Separator, αυτός έχει πάντα προτεραιότητα.', 'qr-rebuilder-pro' ); ?>
		</p>
		<?php
	}

	public static function section_print_intro() {
		/*
		 * Με «Αυτόματο» η εκτύπωση δεν προσαρμόζεται· ακολουθεί τον οδηγό του
		 * εκτυπωτή. Γι' αυτό το κείμενο παραπέμπει στον προσανατολισμό.
		 */
		echo '<p class="description">' . esc_html__( 'Ρυθμίσεις για τη λειτουργία «Εκτύπωση». Το φυσικό μέγεθος του DataMatrix ορίζεται εδώ ώστε να ταιριάζει στον ετικετογράφο σας (π.χ. μικρότερο για DYMO/μικρές ετικέτες, μεγαλύτερο για μεγάλη ετικέτα Zebra). Αν η ετικέτα βγαίνει κομμένη ή σε δύο κομμάτια, δοκιμάστε «Οριζόντια» στον προσανατολισμό.', 'qr-rebuilder-pro' ) . '</p>';
	}

	/**
	 * Η οθόνη διαβάζει τον resolver που χρησιμοποιεί και η εκτύπωση, και το
	 * min/max του input έρχεται από το ίδιο εύρος.
	 */
	public static function field_print_barcode_mm() {
		$value = qrrp_print_barcode_mm();
		$range = qrrp_print_barcode_mm_range();
		?>
		<input type="number" min="<?php echo esc_attr( (string) $range['min'] ); ?>" max="<?php echo esc_attr( (string) $range['max'] ); ?>" step="1" id="qrrp_print_barcode_mm" name="qrrp_print_barcode_mm" value="<?php echo esc_attr( (string) $value ); ?>" class="small-text" /> mm
		<p class="description">
			<?php esc_html_e( 'Πλάτος του κωδικού στην εκτύπωση. Προτεινόμενο 20–40mm. Για μικρές ετικέτες (π.χ. DYMO 450) βάλτε μικρότερο, π.χ. 18–22mm· για τη μεγάλη ετικέτα του Zebra μπορείτε 30–40mm. Πολύ μικρό μέγεθος μπορεί να μη σκαναρίζεται.', 'qr-rebuilder-pro' ); ?>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: the minimum width in millimetres below which the barcode is never shrunk. */
				esc_html__( 'Σε στενή ετικέτα ο κωδικός σμικρύνεται όσο χρειάζεται για να χωρέσει, αλλά ποτέ κάτω από %s mm — κάτω από εκεί σταματά και ξεχειλίζει ορατά, ώστε να μη βγει ετικέτα που φαίνεται σωστή αλλά δεν σαρώνεται.', 'qr-rebuilder-pro' ),
				esc_html( number_format_i18n( qrrp_print_barcode_floor_mm() ) )
			);
			?>
		</p>
		<?php self::render_x_dimension_table( qrrp_print_barcode_mm() ); ?>
		<?php
	}

	/**
	 * Το πλάτος module (X-dimension) για τα συνήθη μεγέθη συμβόλου, στο
	 * ρυθμισμένο πλάτος εκτύπωσης. Το μέγεθος του συμβόλου εξαρτάται από τα
	 * δεδομένα, οπότε δίνεται πίνακας αντί για έναν αριθμό. Ο διαιρέτης
	 * περιλαμβάνει το quiet zone (qrrp_total_print_modules()).
	 *
	 * @param int $width_mm Το ρυθμισμένο πλάτος εκτύπωσης, σε mm.
	 */
	private static function render_x_dimension_table( $width_mm ) {
		/* Τα μεγέθη που παρατηρούνται με πραγματικά φαρμακευτικά δεδομένα. */
		$sizes = array( 18, 20, 22, 24, 26, 32 );
		$quiet = 4;
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: the configured print width in millimetres. */
				esc_html__( 'Στα %s mm, το πλάτος κάθε module (X-dimension) εξαρτάται από το μέγεθος του συμβόλου, το οποίο αλλάζει ανάλογα με τα δεδομένα της συσκευασίας:', 'qr-rebuilder-pro' ),
				esc_html( number_format_i18n( (int) $width_mm ) )
			);
			?>
		</p>
		<ul style="margin:0 0 1em 1.5em;list-style:disc;">
			<?php foreach ( $sizes as $cols ) : ?>
				<?php
				$total = qrrp_total_print_modules( $cols, $quiet );
				$x     = qrrp_x_dimension_mm( $width_mm, $total );

				if ( null === $x ) {
					continue;
				}
				?>
				<li>
					<?php
					printf(
						/* translators: 1: symbol size in modules, 2: X-dimension in millimetres. */
						esc_html__( '%1$s modules → %2$s mm ανά module', 'qr-rebuilder-pro' ),
						esc_html( $cols . '×' . $cols ),
						esc_html( number_format_i18n( $x, 3 ) )
					);
					?>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="description">
			<?php esc_html_e( 'Οι προδιαγραφές GS1 για ρυθμιζόμενα προϊόντα υγείας ορίζουν ελάχιστο 0,254 mm και στόχο 0,380 mm ανά module. Πιο πλατύ module σημαίνει καθαρότερη εκτύπωση και ευκολότερη σάρωση.', 'qr-rebuilder-pro' ); ?>
		</p>
		<?php
	}

	/** Η οθόνη διαβάζει τον resolver, όπως η field_print_barcode_mm(). */
	public static function field_print_orientation() {
		$value = qrrp_print_orientation();
		?>
		<select name="qrrp_print_orientation" id="qrrp_print_orientation">
			<option value="landscape" <?php selected( $value, 'landscape' ); ?>><?php esc_html_e( 'Οριζόντια (συνιστάται για ετικέτες)', 'qr-rebuilder-pro' ); ?></option>
			<option value="portrait" <?php selected( $value, 'portrait' ); ?>><?php esc_html_e( 'Κάθετα', 'qr-rebuilder-pro' ); ?></option>
			<option value="auto" <?php selected( $value, 'auto' ); ?>><?php esc_html_e( 'Αυτόματο (όπως ο εκτυπωτής)', 'qr-rebuilder-pro' ); ?></option>
		</select>
		<?php
		/* Το «Αυτόματο» αφήνει τον οδηγό του εκτυπωτή να αποφασίσει· το κείμενο το λέει. */
		?>
		<p class="description"><?php esc_html_e( 'Για φαρδιές-κοντές ετικέτες (π.χ. Zebra) η «Οριζόντια» χωράει τα πάντα σε μία σελίδα και είναι η ασφαλής επιλογή. Το «Αυτόματο» δεν ορίζει προσανατολισμό: ακολουθεί τον οδηγό του εκτυπωτή, οπότε αν εκείνος είναι δηλωμένος κάθετος η ετικέτα μπορεί να βγει κομμένη ή σε δύο κομμάτια.', 'qr-rebuilder-pro' ); ?></p>
		<?php
	}

	public static function field_print_show_note() {
		$value = qrrp_print_show_note();
		?>
		<label>
			<input type="checkbox" name="qrrp_print_show_note" value="1" <?php checked( $value, '1' ); ?> />
			<?php esc_html_e( 'Εμφάνιση του επεξηγηματικού σχολίου («Αντιγράψτε το…») κάτω από το GS1', 'qr-rebuilder-pro' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Απενεργοποιήστε το σε πολύ μικρές ετικέτες για να μη γεμίζει ο χώρος.', 'qr-rebuilder-pro' ); ?></p>
		<?php
	}

	public static function field_print_show_meta() {
		$value = qrrp_print_show_meta();
		?>
		<label>
			<input type="checkbox" name="qrrp_print_show_meta" value="1" <?php checked( $value, '1' ); ?> />
			<?php esc_html_e( 'Εμφάνιση των πεδίων PC/SN/LOT/EXP και ημερομηνίας στην εκτύπωση', 'qr-rebuilder-pro' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Το DataMatrix και το GS1 ήδη περιέχουν αυτά τα στοιχεία· απενεργοποιήστε τα για μικρές ετικέτες.', 'qr-rebuilder-pro' ); ?></p>
		<?php
	}

	public static function field_sn_fallback_length() {
		$value = self::clamp_gs1_length( get_option( 'qrrp_sn_fallback_length', qrrp_default_gs1_fallback_length( 'sn' ) ) );
		$range = qrrp_gs1_length_range();
		?>
		<input type="number" min="<?php echo esc_attr( $range['min'] ); ?>" max="<?php echo esc_attr( $range['max'] ); ?>" id="qrrp_sn_fallback_length" name="qrrp_sn_fallback_length" value="<?php echo esc_attr( $value ); ?>" class="small-text" />
		<?php esc_html_e( 'χαρακτήρες', 'qr-rebuilder-pro' ); ?>
		<?php
	}

	public static function field_lot_fallback_length() {
		$value = self::clamp_gs1_length( get_option( 'qrrp_lot_fallback_length', qrrp_default_gs1_fallback_length( 'lot' ) ) );
		$range = qrrp_gs1_length_range();
		?>
		<input type="number" min="<?php echo esc_attr( $range['min'] ); ?>" max="<?php echo esc_attr( $range['max'] ); ?>" id="qrrp_lot_fallback_length" name="qrrp_lot_fallback_length" value="<?php echo esc_attr( $value ); ?>" class="small-text" />
		<?php esc_html_e( 'χαρακτήρες', 'qr-rebuilder-pro' ); ?>
		<?php
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$view = QRRP_PLUGIN_DIR . 'admin/views/settings-page.php';

		if ( file_exists( $view ) && is_readable( $view ) ) {
			require $view;
			return;
		}

		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'Το αρχείο προβολής των ρυθμίσεων δεν βρέθηκε.', 'qr-rebuilder-pro' ) .
		'</p></div>';
	}
}

QRRP_Admin::init();
