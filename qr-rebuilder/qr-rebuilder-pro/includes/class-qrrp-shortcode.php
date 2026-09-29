<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Το front-end εργαλείο [qr_rebuilder_pro]: έλεγχος πρόσβασης, CSS/JS,
 * δεδομένα προς το qrrp-app.js και το HTML σάρωσης/ανάλυσης/αναδημιουργίας.
 */
final class QRRP_Shortcode {

	/**
	 * Η κλειδωμένη θέση (άρνηση πρόσβασης) παίρνει το #qrrp-app, με το οποίο τα
	 * θέματα τοποθετούν το εργαλείο· το data-qrrp-state="denied" δηλώνει ότι
	 * εργαλείο δεν υπάρχει.
	 */
	const TOOL_SLOT_ATTRS = ' id="qrrp-app" data-qrrp-state="denied"';

	/** Δημόσιο alias· η τιμή ζει στην QRRP_Tool_Page. */
	const SHORTCODE_TAG = QRRP_Tool_Page::SHORTCODE_TAG;

	/**
	 * Αν το εργαλείο (ή η κλειδωμένη θέση) αποδόθηκε ήδη στο κύριο περιεχόμενο.
	 * Το markup έχει σταθερά ids, οπότε δεύτερο αντίγραφο θα ήταν ανενεργό.
	 */
	private static $rendered = false;

	public static function init() {
		add_shortcode( QRRP_Tool_Page::SHORTCODE_TAG, array( __CLASS__, 'render' ) );

		/* Πριν από κάθε έξοδο, ώστε το nocache_headers() να προλάβει τα headers. */
		add_action( 'template_redirect', array( __CLASS__, 'maybe_prevent_cache_early' ) );

		/* Το stylesheet στο <head> όταν η σελίδα είναι γνωστό ότι έχει το εργαλείο (χωρίς FOUC). */
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_styles_early' ) );

		/* 2.15.2: URL με ?qrrp_token (bearer secret) δεν διαρρέει σε Referer ούτε ευρετηριάζεται. */
		add_action( 'template_redirect', array( __CLASS__, 'protect_token_request' ), 0 );
	}

	/**
	 * Όταν το URL φέρει ?qrrp_token: Referrer-Policy no-referrer (ό,τι φορτωθεί
	 * πριν το JS αφαιρέσει το token από τη γραμμή διευθύνσεων δεν το στέλνει ως
	 * Referer) και noindex (header και meta robots). Κρίνεται η παρουσία της
	 * παραμέτρου, όχι η εγκυρότητα, όπως στο response_is_shareable().
	 */
	public static function protect_token_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Ελέγχεται μόνο η παρουσία της παραμέτρου.
		if ( is_admin() || ! isset( $_GET['qrrp_token'] ) ) {
			return;
		}

		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Robots-Tag: noindex, nofollow', false );
		}

		if ( function_exists( 'wp_robots_no_robots' ) ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		}
	}

	/**
	 * Singular σελίδα με το εργαλείο (βλ. QRRP_Tool_Page::post_has_tool()): η
	 * απόκριση σημαίνεται ως μη cacheable πριν ξεκινήσει η έξοδος.
	 */
	public static function maybe_prevent_cache_early() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! ( $post instanceof WP_Post ) || ! QRRP_Tool_Page::post_has_tool( $post ) ) {
			return;
		}

		self::prevent_page_cache();
	}

	/**
	 * Stylesheet στο <head> για singular σελίδα με το εργαλείο. Η κλήση μέσα στη
	 * render() μένει ως εφεδρεία για ό,τι η ανίχνευση δεν βλέπει.
	 */
	public static function maybe_enqueue_styles_early() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		if ( QRRP_Tool_Page::post_has_tool( get_queried_object() ) ) {
			self::enqueue_styles();
		}
	}

	/** Το ίδιο δικαίωμα που επιβάλλει η QRRP_Ajax (fail closed σε άκυρες τιμές). */
	private static function configured_capability() {
		return qrrp_tool_capability();
	}

	private static function guest_access_enabled() {
		return '1' === get_option( 'qrrp_allow_guests', '0' )
			&& 'read' === self::configured_capability();
	}

	private static function can_use_tool() {
		if ( is_user_logged_in() ) {
			return current_user_can( self::configured_capability() );
		}

		return self::guest_access_enabled();
	}

	/** Η απόφαση του server (QRRP_Ajax)· αν λείπει η κλάση, χωρίς κουμπί email. */
	private static function can_send_email() {
		if ( class_exists( 'QRRP_Ajax' ) && is_callable( array( 'QRRP_Ajax', 'can_send_email' ) ) ) {
			return QRRP_Ajax::can_send_email();
		}

		return false;
	}

	/**
	 * Επιτρέπεται η απόκριση να σερβιριστεί από κοινόχρηστο page cache;
	 *
	 * Για ανώνυμους το nonce είναι ίδιο για όλους (uid 0, κενό token) και ζει
	 * έως 24 ώρες, οπότε ένα cached αντίγραφο είναι ασφαλές αν το page cache
	 * ζει λιγότερο από 12 ώρες. Όχι, όταν:
	 *  - ο χρήστης είναι συνδεδεμένος (nonce δεμένο με το session του)·
	 *  - το nonce_user_logged_out δίνει uid ανά ανώνυμη συνεδρία·
	 *  - υπάρχει ?qrrp_token (το HTML φέρει στοιχεία ενός παραλήπτη· κρίνεται
	 *    η παρουσία, όχι η εγκυρότητα).
	 *
	 * @return bool
	 */
	private static function response_is_shareable() {
		if ( is_user_logged_in() ) {
			return false;
		}

		if ( 0 !== (int) apply_filters( 'nonce_user_logged_out', 0, QRRP_Ajax::NONCE_ACTION ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Ελέγχεται μόνο η παρουσία της παραμέτρου.
		return ! isset( $_GET['qrrp_token'] );
	}

	/**
	 * DONOTCACHEPAGE + nocache_headers() όταν η απόκριση δεν μοιράζεται.
	 *
	 * @return bool true όταν η απόκριση σημάνθηκε.
	 */
	private static function prevent_page_cache() {
		if ( self::response_is_shareable() ) {
			return false;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Third-party convention honoured by WP Super Cache, W3 Total Cache, LiteSpeed and others; a prefixed name would be ignored.
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! headers_sent() ) {
			nocache_headers();
		}

		return true;
	}

	/** Μόνο CSS — και για τις οθόνες άρνησης, όπου το JS (και το nonce) δεν φορτώνεται. */
	private static function enqueue_styles() {
		$css_path = QRRP_PLUGIN_DIR . 'assets/css/qrrp-style.css';

		wp_enqueue_style(
			'qrrp-style',
			QRRP_PLUGIN_URL . 'assets/css/qrrp-style.css',
			array(),
			qrrp_asset_version( $css_path )
		);
	}

	private static function enqueue_assets() {
		$app_js_path    = QRRP_PLUGIN_DIR . 'assets/js/qrrp-app.js';
		$app_js_version = qrrp_asset_version( $app_js_path );

		self::enqueue_styles();

		wp_enqueue_script(
			'qrrp-app',
			QRRP_PLUGIN_URL . 'assets/js/qrrp-app.js',
			array(),
			$app_js_version,
			true
		);

		$print_barcode_range = qrrp_print_barcode_mm_range();

		/*
		 * Όρια και ρυθμίσεις εκτύπωσης έρχονται από τις κεντρικές συναρτήσεις·
		 * το JS δεν κρατά δικά του αντίγραφα.
		 *
		 * Η wp_localize_script() μετατρέπει κάθε βαθμωτή τιμή πρώτου επιπέδου σε
		 * συμβολοσειρά: οι αριθμοί φτάνουν ως "24", το true ως "1" και το false
		 * ως "". Το qrrp-app.js τα μετατρέπει ρητά (parseInt, σύγκριση με '0'/'1').
		 */
		wp_localize_script(
			'qrrp-app',
			'QRRP',
			array(
				'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
				'nonce'                 => wp_create_nonce( QRRP_Ajax::NONCE_ACTION ),
				'todayISO'              => wp_date( 'Y-m-d' ),
				'canSendEmail'          => self::can_send_email(),
				/* Εισαγωγή PC/SN/LOT/EXP χωρίς σάρωση· ο server το επιβάλλει ανεξάρτητα. */
				'canManualEntry'        => qrrp_manual_entry_allowed(),
				'prefill'               => self::get_prefill_fields(),
				'maxRawLength'          => qrrp_max_raw_bytes(),
				/* Αναμονή (ms) πριν την αυτόματη υποβολή σάρωσης χωρίς Enter/Tab· το JS την περιορίζει σε 80–2000. */
				'scannerIdleMs'         => (int) apply_filters( 'qrrp_scanner_idle_ms', 300 ),
				'printBarcodeMm'        => qrrp_print_barcode_mm(),
				'printBarcodeMmMin'     => $print_barcode_range['min'],
				'printBarcodeMmMax'     => $print_barcode_range['max'],
				'printBarcodeMmDefault' => qrrp_default_print_barcode_mm(),
				/* Φυσικό κατώτατο όριο σαρωσιμότητας (≠ Min, που είναι όριο ρύθμισης). */
				'printBarcodeMmFloor'   => qrrp_print_barcode_floor_mm(),
				'printShowNote'         => qrrp_print_show_note(),
				'printShowMeta'         => qrrp_print_show_meta(),
				'printOrientation'      => qrrp_print_orientation(),
				'i18n'                  => array(
					'requestTimeout'          => __( 'Ο server δεν απάντησε εγκαίρως. Δοκιμάστε ξανά — αν είχατε ζητήσει αποστολή email, ελέγξτε πρώτα αν έφτασε, γιατί μπορεί να στάλθηκε παρότι διακόπηκε η αναμονή.', 'qr-rebuilder-pro' ),
					'accessDenied'            => __( 'Ο server αρνήθηκε το αίτημα — πιθανότατα έληξε η σύνδεσή σας ή άλλαξαν τα δικαιώματά σας. Ανανεώστε τη σελίδα και συνδεθείτε ξανά.', 'qr-rebuilder-pro' ),
					/* translators: {status} is replaced in JS by the HTTP status code. */
					'serverHttpError'         => __( 'Σφάλμα του server (HTTP {status}). Δοκιμάστε ξανά σε λίγο.', 'qr-rebuilder-pro' ),
					'staleNonce'              => __( 'Η σελίδα είναι παλιά και το διακριτικό ασφαλείας της έληξε. Κάντε σκληρή ανανέωση (Ctrl+F5 ή Cmd+Shift+R) και δοκιμάστε ξανά — μια απλή ανανέωση μπορεί να επιστρέψει το ίδιο αποθηκευμένο αντίγραφο.', 'qr-rebuilder-pro' ),
					'contestedPrompt'         => __( 'Ποια τιμή δείχνει η συσκευασία;', 'qr-rebuilder-pro' ),
					'contestedValueRejected'  => __( 'Η επιλεγμένη τιμή δεν έγινε δεκτή από το πεδίο. Συμπληρώστε την χειροκίνητα από τη συσκευασία.', 'qr-rebuilder-pro' ),
					'expiryNotReadable'       => __( 'Η ημερομηνία λήξης δεν μπόρεσε να συμπληρωθεί αυτόματα. Συμπληρώστε την από τη συσκευασία.', 'qr-rebuilder-pro' ),
					'scanMergedAfterPause'    => __( 'Ο σαρωτής έκανε παύση στη μέση της σάρωσης και τα δύο τμήματα ενώθηκαν. Ελέγξτε SN και LOT στη συσκευασία.', 'qr-rebuilder-pro' ),
					'userDeclaredNote'        => __( 'Δηλωμένο από τον χρήστη: τα στοιχεία δόθηκαν από επισκέπτη και η προέλευσή τους δεν επαληθεύεται.', 'qr-rebuilder-pro' ),
					'expiryDayZero'           => __( 'Η λήξη δεν έχει ημέρα (ΗΗ=00): ισχύει έως το τέλος του μήνα και διατηρείται έτσι στον νέο κωδικό.', 'qr-rebuilder-pro' ),
					'expiryDayZeroShort'      => __( 'χωρίς ημέρα – έως το τέλος του μήνα', 'qr-rebuilder-pro' ),
					'scanUnverified'          => __( 'Η σάρωση ήταν πολύ σύνθετη για να επαληθευτεί αυτόματα. Ελέγξτε τα στοιχεία στη συσκευασία.', 'qr-rebuilder-pro' ),
					'manualOverrideFields'    => __( 'Τα παρακάτω δεν προκύπτουν από τη σάρωση:', 'qr-rebuilder-pro' ),
					'manualOverrideCheck'     => __( 'Επιβεβαιώστε τα από το τυπωμένο HRI της συσκευασίας.', 'qr-rebuilder-pro' ),
					'manualOverrideGeneric'   => __( 'Οι τιμές που ζητάτε δεν προκύπτουν από τη σάρωση. Επιβεβαιώστε τις από το τυπωμένο HRI της συσκευασίας.', 'qr-rebuilder-pro' ),
					'chooseContested'         => __( 'Διαλέξτε πρώτα ποια τιμή δείχνει η συσκευασία.', 'qr-rebuilder-pro' ),
					'customerLabel'           => __( 'Πελάτης', 'qr-rebuilder-pro' ),
					'printDateLabel'          => __( 'Ημ/νία εκτύπωσης', 'qr-rebuilder-pro' ),
					'printDateShortLabel'     => __( 'Ημ/νία', 'qr-rebuilder-pro' ),
					'outputStale'             => __( 'Η επαλήθευση του ήδη δημιουργημένου κωδικού έληξε ή δεν αντιστοιχεί στα τρέχοντα στοιχεία. Δημιουργήστε ξανά τον κωδικό.', 'qr-rebuilder-pro' ),
					'missingFields'         => __( 'Λείπουν υποχρεωτικά πεδία.', 'qr-rebuilder-pro' ),
					'invalidExpiry'         => __( 'Μη έγκυρη ημερομηνία λήξης.', 'qr-rebuilder-pro' ),
					'emailSent'             => __( 'Το email στάλθηκε με επιτυχία.', 'qr-rebuilder-pro' ),
					'emailFailed'           => __( 'Η αποστολή email απέτυχε.', 'qr-rebuilder-pro' ),
					'fieldsChanged'         => __( 'Αλλάξατε τα στοιχεία — πατήστε «Αναδημιουργία» για να ενημερωθεί το GS1 DataMatrix.', 'qr-rebuilder-pro' ),
					'prefilledFromLink'     => __( 'Τα στοιχεία φορτώθηκαν από τον σύνδεσμο. Πατήστε «Δημιουργία νέου GS1 DataMatrix».', 'qr-rebuilder-pro' ),
					'invalidGs1Data'        => __( 'Μη έγκυρα δεδομένα GS1.', 'qr-rebuilder-pro' ),
					'analyzingGs1'          => __( 'Ανάλυση GS1…', 'qr-rebuilder-pro' ),
					'parseFailed'           => __( 'Η ανάλυση των GS1 δεδομένων απέτυχε.', 'qr-rebuilder-pro' ),
					'serverCommFailed'      => __( 'Αποτυχία επικοινωνίας με τον server.', 'qr-rebuilder-pro' ),
					'warningsLabel'         => __( 'Προειδοποιήσεις:', 'qr-rebuilder-pro' ),
					'xDimensionBelowGs1Minimum' => __(
						'Το GS1 DataMatrix δημιουργήθηκε, αλλά στο επιλεγμένο πλάτος εκτύπωσης το X-dimension είναι κάτω από το ελάχιστο GS1.',
						'qr-rebuilder-pro'
					),
					'xDimensionMinimumAdvice' => __(
						'Ελάχιστο X-dimension: {minimum_x} mm. Για αυτό το σύμβολο χρησιμοποιήστε πλάτος τουλάχιστον {minimum_width} mm.',
						'qr-rebuilder-pro'
					),
					'confirmLowConfidence'  => __( 'Επιβεβαιώστε πρώτα ότι ελέγξατε τα πεδία χαμηλής βεβαιότητας.', 'qr-rebuilder-pro' ),
					'validatingAndBuilding' => __( 'Έλεγχος δεδομένων και δημιουργία GS1 DataMatrix…', 'qr-rebuilder-pro' ),
					'manualEntryConfirm'    => __( 'Δημιουργία χωρίς σάρωση: τα στοιχεία δεν προέρχονται από σάρωση. Επιβεβαιώστε ότι τα PC, SN, LOT και EXP τα διαβάσατε από την ίδια τη συσκευασία.', 'qr-rebuilder-pro' ),
					'confirmFromPackage'    => __( 'Τα διάβασα από τη συσκευασία — συνέχεια', 'qr-rebuilder-pro' ),
					'gs1CheckFailed'        => __( 'Τα δεδομένα δεν πέρασαν τον έλεγχο GS1.', 'qr-rebuilder-pro' ),
					'datamatrixCreated'     => __( 'Το νέο GS1 DataMatrix δημιουργήθηκε με επιτυχία.', 'qr-rebuilder-pro' ),
					/* translators: {fields} is replaced in JavaScript with field labels, e.g. "SN, LOT". */
					'manualChangeFields'    => __( 'Χειροκίνητη αλλαγή: {fields} (δηλώθηκε από τον χρήστη, όχι από σάρωση).', 'qr-rebuilder-pro' ),
					'manualEntryNote'       => __( 'Χειροκίνητη καταχώριση: οι τιμές δηλώθηκαν από τον χρήστη, όχι από σάρωση.', 'qr-rebuilder-pro' ),
					'scanUnverifiedNote'    => __( 'Μη επαληθευμένη ανάγνωση: οι τιμές επιβεβαιώθηκαν από τον χρήστη.', 'qr-rebuilder-pro' ),
					'datamatrixFailed'      => __( 'Η δημιουργία του GS1 DataMatrix απέτυχε.', 'qr-rebuilder-pro' ),
					'createFirst'           => __( 'Δημιουργήστε πρώτα το νέο GS1 DataMatrix.', 'qr-rebuilder-pro' ),
					'imageSaveFailed'       => __( 'Η αποθήκευση της εικόνας απέτυχε.', 'qr-rebuilder-pro' ),
					'printBlocked'          => __( 'Το παράθυρο εκτύπωσης αποκλείστηκε από τον browser.', 'qr-rebuilder-pro' ),
					'gs1Copied'             => __( 'Τα GS1 δεδομένα αντιγράφηκαν. Επικολλήστε στο πρόγραμμα με Ctrl+V.', 'qr-rebuilder-pro' ),
					'copyFailed'            => __( 'Η αντιγραφή απέτυχε.', 'qr-rebuilder-pro' ),
					'noEmailPermission'     => __( 'Δεν έχετε δικαίωμα αποστολής email από αυτό το εργαλείο.', 'qr-rebuilder-pro' ),
					'enterValidEmail'       => __( 'Εισάγετε μία έγκυρη διεύθυνση email.', 'qr-rebuilder-pro' ),
					'validatedDataChanged'  => __( 'Τα επικυρωμένα GS1 δεδομένα άλλαξαν. Δημιουργήστε ξανά το GS1 DataMatrix.', 'qr-rebuilder-pro' ),
					'sendingEmail'          => __( 'Αποστολή email…', 'qr-rebuilder-pro' ),
					'rebuildNote'           => __( 'Αντιγράψτε το και επικολλήστε το στη «Χειροκίνητη εισαγωγή» του QR ReBuilder Pro για να αναδημιουργηθεί ο κωδικός. Το [GS] είναι ο διαχωριστής των πεδίων.', 'qr-rebuilder-pro' ),
					'printImageFailed'      => __( 'Η εικόνα του κωδικού δεν φορτώθηκε, οπότε η εκτύπωση ακυρώθηκε — μια ετικέτα χωρίς DataMatrix δεν έχει καμία αξία. Δοκιμάστε ξανά.', 'qr-rebuilder-pro' ),
					'printSettingsMissing'  => __( 'Οι ρυθμίσεις εκτύπωσης δεν φορτώθηκαν, οπότε η εκτύπωση ακυρώθηκε — μια ετικέτα με μέγεθος κωδικού που δεν επιλέξατε δεν είναι αξιόπιστη. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.', 'qr-rebuilder-pro' ),
					'confirmExpired'        => __( 'Το προϊόν έχει λήξει. Επιβεβαιώστε πρώτα ότι θέλετε να συνεχίσετε.', 'qr-rebuilder-pro' ),
				),
			)
		);
	}

	/**
	 * Λύνει server-side το ?qrrp_token του συνδέσμου email σε pc/sn/lot/exp
	 * (read-only prefill· η αναδημιουργία επικυρώνει ξανά με nonce). Το token
	 * δεν καταναλώνεται εδώ, γιατί link scanners ανοίγουν τους συνδέσμους πριν
	 * από τον παραλήπτη· αποσύρεται από την QRRP_Ajax::rebuild().
	 *
	 * @return array|null
	 */
	private static function get_prefill_fields() {
		if ( ! isset( $_GET['qrrp_token'] ) || ! is_scalar( $_GET['qrrp_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}

		$token = sanitize_key( (string) wp_unslash( $_GET['qrrp_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}

		/* Μία πηγή για την ανάγνωση του token· αν λείπει, απλώς χωρίς prefill. */
		if ( ! class_exists( 'QRRP_Mailer' ) || ! is_callable( array( 'QRRP_Mailer', 'lookup_rebuild_token' ) ) ) {
			return null;
		}

		$data = QRRP_Mailer::lookup_rebuild_token( $token );

		if ( ! is_array( $data ) ) {
			return null;
		}

		return array(
			'pc'    => isset( $data['pc'] ) ? (string) $data['pc'] : '',
			'sn'    => isset( $data['sn'] ) ? (string) $data['sn'] : '',
			'lot'   => isset( $data['lot'] ) ? (string) $data['lot'] : '',
			'exp'   => isset( $data['exp'] ) ? (string) $data['exp'] : '',
			'token' => $token,
		);
	}

	/**
	 * Εφεδρική διεύθυνση του εργαλείου για τον σύνδεσμο του email. Γράφεται μόνο
	 * όταν η αποθηκευμένη είναι κενή ή άλλου origin — όχι «αν άλλαξε», που με
	 * δύο URLs θα έγραφε σε κάθε προβολή.
	 */
	private static function remember_tool_page_url() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		if ( self::stored_tool_page_url_is_usable() ) {
			return;
		}

		$permalink = get_permalink();

		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return;
		}

		$permalink = esc_url_raw( $permalink );

		if ( '' !== $permalink ) {
			update_option( 'qrrp_tool_page_url', $permalink, false );
		}
	}

	/** Η αποθηκευμένη διεύθυνση είναι ακόμη στο ίδιο origin (ίδιος έλεγχος με τον Mailer); */
	private static function stored_tool_page_url_is_usable() {
		$stored = get_option( 'qrrp_tool_page_url', '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return false;
		}

		return QRRP_Url::is_same_site( $stored );
	}

	/**
	 * Η κάρτα για όποιον δεν μπορεί να χρησιμοποιήσει το εργαλείο: 'login'
	 * (μη συνδεδεμένος) ή 'capability' (συνδεδεμένος χωρίς δικαίωμα — εκεί δεν
	 * προσφέρεται «Σύνδεση», γιατί δεν λύνει τίποτα).
	 *
	 * @param string $variant 'login' | 'capability'.
	 * @return string Το panel, χωρίς εξωτερικό wrapper.
	 */
	private static function access_notice( $variant ) {
		if ( 'qrrp_pharmacist' === self::configured_capability() ) {
			return self::pharmacy_gate( 'login' === $variant );
		}

		if ( 'login' === $variant ) {
			return self::notice_panel(
				'qrrp-notice-login',
				self::notice_icon( 'lock' ),
				__( 'Απαιτείται Σύνδεση ή Δημιουργία Λογαριασμού', 'qr-rebuilder-pro' ),
				__( 'Το QR ReBuilder Pro είναι διαθέσιμο μόνο σε συνδεδεμένους χρήστες. Συνδεθείτε στον λογαριασμό σας ή δημιουργήστε έναν νέο λογαριασμό και επιστρέψτε σε αυτή τη σελίδα για να χρησιμοποιήσετε το εργαλείο.', 'qr-rebuilder-pro' ),
				self::login_button()
			);
		}

		return self::notice_panel(
			'qrrp-notice-capability',
			self::notice_icon( 'shield' ),
			__( 'Ο λογαριασμός σας δεν έχει πρόσβαση στο εργαλείο', 'qr-rebuilder-pro' ),
			__( 'Είστε συνδεδεμένοι, αλλά ο ρόλος σας δεν περιλαμβάνει το δικαίωμα χρήσης του εργαλείου QR ReBuilder Pro. Επικοινωνήστε με τον διαχειριστή της ιστοσελίδας.', 'qr-rebuilder-pro' ),
			''
		);
	}

	/**
	 * Η κάρτα «Μόνο για φαρμακεία» (δικαίωμα qrrp_pharmacist).
	 *
	 * Επισκέπτης: τι χρειάζεται, τρία βήματα και «Σύνδεση / Εγγραφή».
	 * Συνδεδεμένος χωρίς την κατηγορία: εξήγηση και, αν υπάρχει διεύθυνση
	 * επικοινωνίας, κουμπί «Επικοινωνία». Διευθύνσεις: login_url() / contact_url().
	 *
	 * @param bool $guest Μη συνδεδεμένος επισκέπτης.
	 * @return string Το panel, χωρίς εξωτερικό wrapper.
	 */
	private static function pharmacy_gate( $guest ) {
		$pill = '<span class="qrrp-gate-pill">' . esc_html__( 'Κατηγορία Επιχείρησης: Φαρμακείο', 'qr-rebuilder-pro' ) . '</span>';

		if ( $guest ) {
			$url   = self::login_url();
			$icon  = '<path d="M12 3l7 3v6c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6l7-3z"></path><path d="M12 9v6M9 12h6"></path>';
			$title = __( 'Συνδεθείτε για να χρησιμοποιήσετε το εργαλείο', 'qr-rebuilder-pro' );
			$text  = sprintf(
				/* translators: %s: the «Κατηγορία Επιχείρησης: Φαρμακείο» badge. */
				esc_html__( 'Για να κάνετε χρήση του QR-Rebuilder πρέπει να έχετε λογαριασμό με %s. Η εγγραφή είναι δωρεάν.', 'qr-rebuilder-pro' ),
				$pill
			);
			$steps = '<ol class="qrrp-gate-steps">' .
				/* translators: %s: the button label «Σύνδεση / Εγγραφή», in bold. */
				'<li><span class="qrrp-gate-num">1</span><span>' . sprintf( esc_html__( 'Πατήστε %s.', 'qr-rebuilder-pro' ), '<b>' . esc_html__( '«Σύνδεση / Εγγραφή»', 'qr-rebuilder-pro' ) . '</b>' ) . '</span></li>' .
				/* translators: %s: «Κατηγορία Επιχείρησης: Φαρμακείο», in bold. */
				'<li><span class="qrrp-gate-num">2</span><span>' . sprintf( esc_html__( 'Αν δεν έχετε λογαριασμό, κάντε εγγραφή και επιλέξτε %s.', 'qr-rebuilder-pro' ), '<b>' . esc_html__( 'Κατηγορία Επιχείρησης: Φαρμακείο', 'qr-rebuilder-pro' ) . '</b>' ) . '</span></li>' .
				'<li><span class="qrrp-gate-num">3</span><span>' . esc_html__( 'Επιστρέψτε σε αυτή τη σελίδα — το εργαλείο θα είναι διαθέσιμο αμέσως.', 'qr-rebuilder-pro' ) . '</span></li>' .
			'</ol>';
			$actions = '<a class="qrrp-btn qrrp-btn-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Σύνδεση / Εγγραφή →', 'qr-rebuilder-pro' ) . '</a>' .
				'<span class="qrrp-gate-note">' . esc_html__( 'Έχετε ήδη λογαριασμό φαρμακείου; Απλώς συνδεθείτε.', 'qr-rebuilder-pro' ) . '</span>';
			$modifier = 'qrrp-notice-login';
		} else {
			$url   = self::contact_url();
			$icon  = '<circle cx="12" cy="8" r="4"></circle><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"></path>';
			$title = __( 'Ο λογαριασμός σας δεν είναι καταχωρισμένος ως Φαρμακείο', 'qr-rebuilder-pro' );
			$text  = sprintf(
				/* translators: %s: the «Κατηγορία Επιχείρησης: Φαρμακείο» badge. */
				esc_html__( 'Το QR-Rebuilder είναι διαθέσιμο μόνο σε λογαριασμούς με %s. Αν είστε φαρμακείο, επικοινωνήστε μαζί μας για να ενημερωθεί η κατηγορία του λογαριασμού σας.', 'qr-rebuilder-pro' ),
				$pill
			);
			$steps    = '';
			$actions  = ( '' !== $url )
				? '<a class="qrrp-btn qrrp-btn-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Επικοινωνία →', 'qr-rebuilder-pro' ) . '</a>'
				: '';
			$modifier = 'qrrp-notice-capability qrrp-gate--account';
		}

		return '<div class="qrrp-panel qrrp-denied qrrp-gate ' . esc_attr( $modifier ) . '">' .
				'<div class="qrrp-gate-head">' .
					'<div class="qrrp-gate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . $icon . '</svg></div>' .
					'<div><span class="qrrp-gate-kicker">' . esc_html__( 'Μόνο για φαρμακεία', 'qr-rebuilder-pro' ) . '</span>' .
					'<h3 class="qrrp-notice-title qrrp-gate-title">' . esc_html( $title ) . '</h3></div>' .
				'</div>' .
				/* $text: διαφυγμένο κείμενο + το pill (esc_html εσωτερικά). */
				'<p class="qrrp-notice-text qrrp-gate-text">' . $text . '</p>' .
				$steps .
				( '' !== $actions ? '<div class="qrrp-notice-actions qrrp-gate-actions">' . $actions . '</div>' : '' ) .
			'</div>';
	}

	/**
	 * Η δήλωση ευθύνης — μία πηγή για εργαλείο και κλειδωμένη θέση. Δεν
	 * κρύβεται ποτέ και δεν τυπώνεται στην ετικέτα (η εκτύπωση έχει δικό της HTML).
	 */
	private static function disclaimer_markup() {
		return '<div class="qrrp-disclaimer">' .
			'<p><strong>' . esc_html__( 'Η χρήση του εργαλείου γίνεται με ευθύνη του χρήστη.', 'qr-rebuilder-pro' ) . '</strong> ' .
			esc_html__( 'Το εργαλείο δημιουργεί GS1 DataMatrix με βάση τα στοιχεία που εμφανίζονται στην οθόνη. Πριν από την εκτύπωση ή την αποστολή, βεβαιωθείτε ότι τα PC, SN, LOT και EXP συμφωνούν με τα στοιχεία της συσκευασίας.', 'qr-rebuilder-pro' ) . '</p>' .
			'<p>' . esc_html__( 'Κάθε χειροκίνητη αλλαγή πρέπει να ελέγχεται προσεκτικά, καθώς τυχόν λανθασμένα στοιχεία θα οδηγήσουν στη δημιουργία λανθασμένου κωδικού.', 'qr-rebuilder-pro' ) . '</p>' .
			'<p>' . esc_html__( 'Το εργαλείο δεν πραγματοποιεί έλεγχο ούτε επιβεβαιώνει τη γνησιότητα του φαρμάκου.', 'qr-rebuilder-pro' ) . '</p>' .
		'</div>';
	}

	/**
	 * Οι δημόσιοι μετρητές, και το αυτόνομο qrrp-usage.js που τους ανανεώνει
	 * (διαβάζει μόνο το δημόσιο usage.json· φορτώνεται και στην κλειδωμένη θέση).
	 */
	private static function usage_markup() {
		$html = QRRP_Stats::public_markup();

		if ( '' !== $html && false !== strpos( $html, 'data-qrrp-usage-src' ) ) {
			$path = QRRP_PLUGIN_DIR . 'assets/js/qrrp-usage.js';

			wp_enqueue_script(
				'qrrp-usage',
				QRRP_PLUGIN_URL . 'assets/js/qrrp-usage.js',
				array(),
				qrrp_asset_version( $path ),
				true
			);
		}

		return $html;
	}

	/**
	 * Η διεύθυνση σύνδεσης, με επιστροφή στη σελίδα του εργαλείου (redirect_to,
	 * μόνο σε singular). Προτιμά δημοσιευμένη σελίδα «login», αλλιώς wp_login_url().
	 * Φίλτρο: qrrp_login_url (και το παλαιότερο qrrp_pharmacy_login_url).
	 *
	 * @return string
	 */
	private static function login_url() {
		$redirect = '';

		if ( is_singular() ) {
			$permalink = get_permalink();

			if ( is_string( $permalink ) && '' !== $permalink ) {
				$redirect = $permalink;
			}
		}

		/* Σελίδα «login» του site (π.χ. φόρμα εγγραφής), αν υπάρχει· αλλιώς η κανονική σύνδεση του WP. */
		$page = get_page_by_path( 'login' );

		if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
			/* 2.15.2: permalink, ώστε να δουλεύει με plain permalinks και WPML/Polylang. */
			$url = self::page_permalink( $page, '/login/' );
			$url = ( '' !== $redirect ) ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url ) : $url;
		} else {
			$url = ( '' !== $redirect ) ? wp_login_url( $redirect ) : wp_login_url();
		}
		$url = (string) apply_filters( 'qrrp_login_url', $url, $redirect );

		return (string) apply_filters( 'qrrp_pharmacy_login_url', $url );
	}

	/**
	 * Η διεύθυνση επικοινωνίας της κάρτας φαρμακείων. Εξ ορισμού η σελίδα
	 * «contact», μόνο αν είναι δημοσιευμένη· αλλιώς κενή και το κουμπί δεν εμφανίζεται.
	 * Φίλτρο: qrrp_contact_url (και το παλαιότερο qrrp_pharmacy_contact_url).
	 *
	 * @return string
	 */
	private static function contact_url() {
		$page = get_page_by_path( 'contact' );
		$url  = ( $page instanceof WP_Post && 'publish' === $page->post_status ) ? self::page_permalink( $page, '/contact/' ) : '';
		$url  = (string) apply_filters( 'qrrp_contact_url', $url );

		return (string) apply_filters( 'qrrp_pharmacy_contact_url', $url );
	}

	/** Permalink της σελίδας· αν δεν βγει, η παλιά σταθερή διαδρομή. */
	private static function page_permalink( WP_Post $page, $fallback_path ) {
		$url = get_permalink( $page );

		return ( is_string( $url ) && '' !== $url ) ? $url : home_url( $fallback_path );
	}

	private static function login_button() {
		return '<a class="qrrp-btn qrrp-btn-primary" href="' . esc_url( self::login_url() ) . '">' .
			esc_html__( 'Σύνδεση', 'qr-rebuilder-pro' ) .
		'</a>';
	}

	/**
	 * Panel μηνύματος, χωρίς εξωτερικό wrapper: ο καλών το βάζει μέσα σε
	 * .qrrp-wrapper (εκεί δηλώνονται οι μεταβλητές CSS). $icon και $action_html
	 * είναι εσωτερικά παραγόμενο HTML· $title και $text ακατέργαστο κείμενο.
	 */
	private static function notice_panel( $modifier, $icon, $title, $text, $action_html ) {
		$actions = ( '' !== $action_html )
			? '<div class="qrrp-notice-actions">' . $action_html . '</div>'
			: '';

		return '<div class="qrrp-panel qrrp-denied ' . esc_attr( $modifier ) . '">' .
				'<div class="qrrp-notice-icon" aria-hidden="true">' . $icon . '</div>' .
				'<div class="qrrp-notice-body">' .
					'<h3 class="qrrp-notice-title">' . esc_html( $title ) . '</h3>' .
					'<p class="qrrp-notice-text">' . esc_html( $text ) . '</p>' .
					$actions .
				'</div>' .
			'</div>';
	}

	/** Εσωτερικά SVG ('lock' | 'shield' | 'info')· το currentColor ακολουθεί την παραλλαγή. */
	private static function notice_icon( $name ) {
		$paths = array(
			'lock'   => '<rect x="5" y="11" width="14" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path>',
			'shield' => '<path d="M12 3l7 3v6c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6l7-3z"></path><path d="M9.5 12.5l1.8 1.8 3.2-3.6"></path>',
			'info'   => '<circle cx="12" cy="12" r="9"></circle><path d="M12 11v5"></path><path d="M12 8h.01"></path>',
		);

		$path = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['info'];

		return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . $path . '</svg>';
	}

	/**
	 * Εκτελέσεις του shortcode που δεν είναι το σώμα της σελίδας: excerpts,
	 * meta περιγραφές SEO μέσα στο wp_head, feeds. Εκεί το εργαλείο δεν έχει
	 * νόημα, και δεν πρέπει να «καταναλώσει» την απόδοση του πραγματικού σώματος.
	 *
	 * @return bool
	 */
	private static function is_auxiliary_context() {
		$auxiliary = is_feed()
			|| doing_action( 'wp_head' )
			|| doing_filter( 'get_the_excerpt' )
			|| doing_filter( 'the_excerpt' );

		return (bool) apply_filters( 'qrrp_shortcode_is_auxiliary_context', $auxiliary );
	}

	/**
	 * Είναι αυτή η απόδοση το κύριο περιεχόμενο; Μόνο τότε σημειώνεται ως
	 * «αποδόθηκε». Σε singular σελίδα εκτός main loop (πρόωρο φιλτράρισμα
	 * περιεχομένου, page builders) το εργαλείο αποδίδεται χωρίς σημείωση, ώστε
	 * μια απορριπτόμενη έξοδος να μην κρύψει το πραγματικό σώμα. Ένα builder
	 * που το αποδίδει δύο φορές εκτός loop ρυθμίζεται με το φίλτρο
	 * qrrp_shortcode_is_primary_context.
	 *
	 * @return bool
	 */
	private static function is_primary_context() {
		$primary = ! ( is_singular() && ! in_the_loop() );

		return (bool) apply_filters( 'qrrp_shortcode_is_primary_context', $primary );
	}

	/**
	 * Δεύτερο αντίγραφο στην ίδια σελίδα: συντάκτες βλέπουν εξήγηση, οι
	 * υπόλοιποι τίποτα. Χωρίς φίλτρο παράκαμψης: θα επανέφερε διπλά ids.
	 *
	 * @return string
	 */
	private static function duplicate_notice() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		self::enqueue_styles();

		return '<div class="qrrp-wrapper qrrp-wrapper-notice">' .
			self::notice_panel(
				'qrrp-notice-info',
				self::notice_icon( 'info' ),
				__( 'Το εργαλείο εμφανίζεται ήδη σε αυτή τη σελίδα', 'qr-rebuilder-pro' ),
				__( 'Το δεύτερο shortcode αγνοήθηκε, επειδή δύο αντίγραφα του εργαλείου στην ίδια σελίδα δεν μπορούν να λειτουργήσουν ταυτόχρονα. Το μήνυμα το βλέπουν μόνο όσοι μπορούν να επεξεργαστούν τη σελίδα.', 'qr-rebuilder-pro' ),
				''
			) .
		'</div>';
	}

	public static function render( $atts = array() ) {
		unset( $atts );

		if ( self::is_auxiliary_context() ) {
			return '';
		}

		if ( self::$rendered ) {
			return self::duplicate_notice();
		}

		if ( self::is_primary_context() ) {
			self::$rendered = true;
		}

		if ( ! self::can_use_tool() ) {
			self::enqueue_styles();

			/*
			 * Κλειδωμένη θέση: κάρτα + δήλωση ευθύνης + μετρητές, στη θέση του
			 * εργαλείου. Οι μετρητές μπαίνουν σκόπιμα και εδώ — ο επισκέπτης
			 * χωρίς λογαριασμό είναι αυτός που πρέπει να τους δει. Η σελίδα αυτή
			 * μπαίνει στο page cache για ανώνυμους, οπότε ο αριθμός ανανεώνεται
			 * από το qrrp-usage.js, όχι από τον server.
			 */
			return '<div class="qrrp-wrapper qrrp-slot"' . self::TOOL_SLOT_ATTRS . '>'
				. self::access_notice( is_user_logged_in() ? 'capability' : 'login' )
				. self::disclaimer_markup()
				. self::usage_markup()
				. '</div>';
		}

		self::remember_tool_page_url();

		self::prevent_page_cache();

		$can_send_email = self::can_send_email();

		self::enqueue_assets();

		ob_start();
		?>
		<div class="qrrp-wrapper" id="qrrp-app">

			<div class="qrrp-loading-indicator" id="qrrp-loading-indicator" role="status" aria-live="polite" hidden>
				<span class="qrrp-spinner" aria-hidden="true"></span>
				<span id="qrrp-loading-text"><?php esc_html_e( 'Επεξεργασία…', 'qr-rebuilder-pro' ); ?></span>
			</div>

			<?php
			/* Έξω από τα panels, ώστε τα μηνύματα να φαίνονται και όταν τα panels είναι κρυφά. */
			?>
			<div id="qrrp-status" class="qrrp-status" aria-hidden="true"></div>
			<?php
			/*
			 * 2.15.3: live regions πάντα στη σελίδα (όχι display:none όταν είναι κενές),
			 * ώστε οι screen readers να ανακοινώνουν αξιόπιστα. Τα σφάλματα ως alert.
			 */
			?>
			<div id="qrrp-status-live" class="qrrp-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
			<div id="qrrp-alert-live" class="qrrp-sr-only" role="alert" aria-atomic="true"></div>

			<div class="qrrp-panel qrrp-scan-panel">
				<div class="qrrp-panel-heading">
					<span class="qrrp-step-badge" aria-hidden="true">1</span>
					<h3><?php esc_html_e( 'Σάρωση GS1 DataMatrix', 'qr-rebuilder-pro' ); ?></h3>
				</div>

				<div class="qrrp-hw-scanner">
					<div class="qrrp-hw-scanner-body">
						<label for="qrrp-hw-input"><?php esc_html_e( 'Σάρωση με εξωτερικό scanner', 'qr-rebuilder-pro' ); ?></label>
<?php
					/*
					 * Το maxlength μετρά χαρακτήρες, ο server bytes· για το GS1 charset 82
					 * (ASCII) ταυτίζονται, και τον τελευταίο λόγο τον έχει ο server. Το
					 * σχόλιο είναι έξω από το <input>, ώστε οι εσοχές του να μη βγαίνουν στο HTML.
					 */
?>
						<input
							type="text"
							id="qrrp-hw-input"
							maxlength="<?php echo esc_attr( (string) qrrp_max_raw_bytes() ); ?>"
							autocomplete="off"
							autocapitalize="off"
							spellcheck="false"
							placeholder="<?php esc_attr_e( 'Κάντε κλικ εδώ και σαρώστε τον κωδικό…', 'qr-rebuilder-pro' ); ?>"
						/>
						<p class="qrrp-hw-hint"><?php esc_html_e( 'Κάντε κλικ στο πεδίο και σαρώστε τον κωδικό με το scanner σας. Τα δεδομένα θα αναλυθούν αυτόματα μόλις ολοκληρωθεί η σάρωση.', 'qr-rebuilder-pro' ); ?></p>
					</div>
				</div>

				<div class="qrrp-manual-toggle-row">
					<button
						type="button"
						class="qrrp-link-btn"
						id="qrrp-manual-toggle"
						aria-controls="qrrp-manual-entry"
						aria-expanded="false"
					><?php esc_html_e( 'Χειροκίνητη εισαγωγή', 'qr-rebuilder-pro' ); ?></button>
				</div>

				<div id="qrrp-manual-entry" class="qrrp-manual-entry" hidden>
					<label for="qrrp-manual-input"><?php esc_html_e( 'Επικόλληση / πληκτρολόγηση δεδομένων GS1', 'qr-rebuilder-pro' ); ?></label>

					<textarea
						id="qrrp-manual-input"
						rows="2"
						maxlength="<?php echo esc_attr( (string) qrrp_max_raw_bytes() ); ?>"
						autocapitalize="off"
						spellcheck="false"
						placeholder="<?php esc_attr_e( 'π.χ. 0108006540718100172803311026062921YN68XRRFDZP', 'qr-rebuilder-pro' ); ?>"
					></textarea>

					<div class="qrrp-manual-actions">
						<p class="qrrp-hw-hint"><?php esc_html_e( 'Δέχεται GS1 με [GS] ή συνεχόμενο κείμενο QR χωρίς [GS].', 'qr-rebuilder-pro' ); ?></p>

						<button type="button" class="qrrp-btn qrrp-btn-primary" id="qrrp-manual-submit"><?php esc_html_e( 'Ανάλυση', 'qr-rebuilder-pro' ); ?></button>
					</div>
				</div>
			</div>

			<div class="qrrp-panel qrrp-results-panel" id="qrrp-results-panel" hidden>
				<div class="qrrp-panel-heading">
					<span class="qrrp-step-badge" aria-hidden="true">2</span>
					<h3><?php esc_html_e( 'Πληροφορίες GS1 DataMatrix', 'qr-rebuilder-pro' ); ?></h3>
				</div>

				<div id="qrrp-warnings" class="qrrp-warnings" role="alert" hidden></div>

				<div id="qrrp-manual-entry-note" class="qrrp-manual-entry-note" hidden>
					<strong><?php esc_html_e( 'Χειροκίνητη δημιουργία.', 'qr-rebuilder-pro' ); ?></strong>
					<?php esc_html_e( 'Συμπληρώστε τα PC, SN, LOT και EXP όπως είναι τυπωμένα στη συσκευασία και πατήστε «Δημιουργία». Θα σας ζητηθεί επιβεβαίωση ότι τα διαβάσατε από την ίδια τη συσκευασία.', 'qr-rebuilder-pro' ); ?>
				</div>

				<div id="qrrp-ambiguous-confirm" class="qrrp-ambiguous-confirm" hidden>
					<label>
						<input type="checkbox" id="qrrp-ambiguous-checkbox" />
						<?php esc_html_e( 'Η ανάλυση χρειάστηκε χειροκίνητη επιβεβαίωση. Επιβεβαιώνω ότι έλεγξα το πεδίο που επισημαίνεται στην προειδοποίηση και ότι τα στοιχεία είναι σωστά.', 'qr-rebuilder-pro' ); ?>
					</label>
				</div>

				<?php
				/*
				 * Χωριστή επιβεβαίωση από την παραπάνω: εκείνη αφορά την ανάγνωση,
				 * αυτή το ότι το προϊόν έχει λήξει.
				 */
				?>
				<div id="qrrp-expiry-confirm" class="qrrp-expiry-confirm" hidden>
					<label>
						<input type="checkbox" id="qrrp-expiry-checkbox" />
						<?php esc_html_e( 'Το προϊόν έχει λήξει. Επιβεβαιώνω ότι γνωρίζω ότι η ημερομηνία λήξης έχει παρέλθει και θέλω να συνεχίσω.', 'qr-rebuilder-pro' ); ?>
					</label>
				</div>

				<div class="qrrp-field-group" id="qrrp-raw-original-group">
					<label for="qrrp-raw-original"><?php esc_html_e( 'Αρχικό GS1', 'qr-rebuilder-pro' ); ?></label>
					<textarea id="qrrp-raw-original" rows="2" readonly></textarea>
				</div>

				<div class="qrrp-fields-grid">
					<div class="qrrp-field-group">
						<label for="qrrp-field-pc"><?php esc_html_e( 'PC (Product Code / GTIN)', 'qr-rebuilder-pro' ); ?></label>
						<input type="text" id="qrrp-field-pc" data-field="PC" maxlength="128" autocapitalize="off" spellcheck="false" />
					</div>

					<div class="qrrp-field-group">
						<label for="qrrp-field-sn"><?php esc_html_e( 'SN (Serial Number)', 'qr-rebuilder-pro' ); ?></label>
						<input type="text" id="qrrp-field-sn" data-field="SN" maxlength="128" autocapitalize="off" spellcheck="false" />
					</div>

					<div class="qrrp-field-group">
						<label for="qrrp-field-lot"><?php esc_html_e( 'LOT (Batch Number)', 'qr-rebuilder-pro' ); ?></label>
						<input type="text" id="qrrp-field-lot" data-field="LOT" maxlength="128" autocapitalize="off" spellcheck="false" />
					</div>

					<div class="qrrp-field-group">
						<label for="qrrp-field-exp"><?php esc_html_e( 'EXP (Ημερομηνία λήξης)', 'qr-rebuilder-pro' ); ?></label>
						<input type="date" id="qrrp-field-exp" data-field="EXP" />
					</div>
				</div>

				<div class="qrrp-panel-heading qrrp-panel-heading-sub">
					<span class="qrrp-step-badge" aria-hidden="true">3</span>
					<h3><?php esc_html_e( 'Στοιχεία πελάτη & εκτύπωσης', 'qr-rebuilder-pro' ); ?></h3>
				</div>

				<div class="qrrp-fields-grid">
					<div class="qrrp-field-group">
						<label for="qrrp-customer-name"><?php esc_html_e( 'Όνομα πελάτη', 'qr-rebuilder-pro' ); ?></label>
						<input
							type="text"
							id="qrrp-customer-name"
							maxlength="200"
							autocomplete="name"
							placeholder="<?php esc_attr_e( 'π.χ. Παπαδόπουλος Γιώργος', 'qr-rebuilder-pro' ); ?>"
						/>
					</div>

					<div class="qrrp-field-group">
						<label for="qrrp-print-date"><?php esc_html_e( 'Ημερομηνία εκτύπωσης', 'qr-rebuilder-pro' ); ?></label>
						<input type="date" id="qrrp-print-date" />
					</div>
				</div>

				<div class="qrrp-actions">
					<button type="button" class="qrrp-btn qrrp-btn-primary" id="qrrp-regenerate"><?php esc_html_e( 'Δημιουργία νέου GS1 DataMatrix', 'qr-rebuilder-pro' ); ?></button>
					<button type="button" class="qrrp-btn" id="qrrp-rescan"><?php esc_html_e( 'Νέα σάρωση', 'qr-rebuilder-pro' ); ?></button>
				</div>
			</div>

			<div class="qrrp-panel qrrp-output-panel" id="qrrp-output-panel" hidden>
				<div class="qrrp-panel-heading">
					<span class="qrrp-step-badge" aria-hidden="true">4</span>
					<h3><?php esc_html_e( 'Πληροφορίες νέου GS1 DataMatrix', 'qr-rebuilder-pro' ); ?></h3>
				</div>

				<div class="qrrp-output-flex">
					<div id="qrrp-qr-output" class="qrrp-qr-output"></div>

					<div class="qrrp-output-summary">
						<p><strong>PC:</strong> <span id="qrrp-summary-pc"></span></p>
						<p><strong>SN:</strong> <span id="qrrp-summary-sn"></span></p>
						<p><strong>LOT:</strong> <span id="qrrp-summary-lot"></span></p>
						<p><strong>EXP:</strong> <span id="qrrp-summary-exp"></span></p>
						<p><strong><?php esc_html_e( 'Πελάτης:', 'qr-rebuilder-pro' ); ?></strong> <span id="qrrp-summary-customer"></span></p>
						<p><strong><?php esc_html_e( 'Ημ/νία εκτύπωσης:', 'qr-rebuilder-pro' ); ?></strong> <span id="qrrp-summary-printdate"></span></p>
						<p class="qrrp-summary-provenance" id="qrrp-summary-provenance" hidden></p>
					</div>
				</div>

				<div class="qrrp-actions">
					<button type="button" class="qrrp-btn" id="qrrp-download-qr"><?php esc_html_e( 'Αποθήκευση εικόνας', 'qr-rebuilder-pro' ); ?></button>
					<button type="button" class="qrrp-btn" id="qrrp-print-qr"><?php esc_html_e( 'Εκτύπωση', 'qr-rebuilder-pro' ); ?></button>
					<button type="button" class="qrrp-btn" id="qrrp-copy-raw"><?php esc_html_e( 'Αντιγραφή GS1 για Ctrl+V', 'qr-rebuilder-pro' ); ?></button>
				</div>

				<div class="qrrp-email-box"<?php if ( ! $can_send_email ) : ?> hidden<?php endif; ?>>
					<div class="qrrp-email-heading">
						<div class="qrrp-email-heading-text">
							<h4><?php esc_html_e( 'Αποστολή νέου GS1 DataMatrix μέσω email', 'qr-rebuilder-pro' ); ?></h4>
							<p><?php esc_html_e( 'Στείλτε το νέο GS1 DataMatrix μαζί με όλα τα στοιχεία του.', 'qr-rebuilder-pro' ); ?></p>
						</div>
					</div>

					<div class="qrrp-email-contents" aria-label="<?php esc_attr_e( 'Περιεχόμενα email', 'qr-rebuilder-pro' ); ?>">
						<span>✓ <?php esc_html_e( 'Εικόνα PNG', 'qr-rebuilder-pro' ); ?></span>
						<span>✓ <?php esc_html_e( 'PC / SN / LOT / EXP', 'qr-rebuilder-pro' ); ?></span>
						<span>✓ <?php esc_html_e( 'GS1', 'qr-rebuilder-pro' ); ?></span>
					</div>

					<label for="qrrp-email-input"><?php esc_html_e( 'Email παραλήπτη', 'qr-rebuilder-pro' ); ?></label>

					<div class="qrrp-email-row">
						<input
							type="email"
							id="qrrp-email-input"
							maxlength="254"
							autocomplete="email"
							inputmode="email"
							placeholder="<?php esc_attr_e( 'email@example.com', 'qr-rebuilder-pro' ); ?>"
						/>

						<button type="button" class="qrrp-btn qrrp-btn-primary" id="qrrp-send-email"><?php esc_html_e( 'Αποστολή email', 'qr-rebuilder-pro' ); ?></button>
					</div>

					<p class="qrrp-email-note">
						<?php esc_html_e( 'Η εικόνα αποστέλλεται ως αρχείο PNG (με λευκό φόντο) και μπορεί να αποθηκευτεί ή να εκτυπωθεί απευθείας από τον παραλήπτη.', 'qr-rebuilder-pro' ); ?>
					</p>
				</div>

			</div>

			<?php
			/* Δήλωση ευθύνης: έξω από τα panels, ώστε να μη σβήνει με το «Νέα σάρωση». */
			?>
			<?php echo self::disclaimer_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Κάθε κείμενο περνά από esc_html__() μέσα στη disclaimer_markup(). ?>

			<?php
			/* Μετρητές μετά τη δήλωση ευθύνης, που πρέπει να διαβαστεί πρώτη· ίδιο μπλοκ με την κλειδωμένη θέση. */
			echo self::usage_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Εσωτερικά παραγόμενο HTML· κάθε τιμή περνά από esc_html() μέσα στην public_markup().
			?>

		</div>
		<?php

		return ob_get_clean();
	}
}

QRRP_Shortcode::init();