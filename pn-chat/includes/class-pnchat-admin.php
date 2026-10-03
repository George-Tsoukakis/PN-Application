<?php
/**
 * Administration: training (answers, synonyms, test box), refusals, the
 * question log with e-mail replies, the brain download/upload, settings.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screens and their form handlers.
 */
final class PNChat_Admin {

	const DEFAULT_BLOCK = 'Δεν μπορούμε να απαντήσουμε σε αυτό το ερώτημα.';

	/**
	 * Capability for every PN Chat screen.
	 *
	 * @return string
	 */
	public static function capability() {
		return (string) apply_filters( 'pnchat_capability', 'manage_options' );
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'save_entry', 'delete_entry', 'toggle_entry', 'save_synonyms', 'question', 'bulk_questions', 'send_reply', 'export', 'import', 'snapshot', 'save_settings', 'site_reindex', 'ai_draft', 'ai_page', 'learn_read', 'learn_site', 'learn_control', 'proposal', 'proposals_bulk', 'lesson', 'lesson_group', 'lesson_word', 'tests_run', 'test_add', 'test_remove', 'index_now', 'index_refresh', 'index_media' ) as $a ) {
			add_action( 'admin_post_pnchat_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/**
	 * Menu.
	 *
	 * @return void
	 */
	public static function menu() {
		$cap    = self::capability();
		$counts = PNChat_Store::question_counts();
		// Waiting for an administrator: e-mails to answer and AI answers to review.
		$todo   = (int) $counts['email'] + (int) ( $counts['ai'] ?? 0 );
		$props  = PNChat_Learn::count_pending();
		$lessons = PNChat_Lessons::count_pending();
		$bubble = function ( $n ) {
			return $n > 0 ? ' <span class="awaiting-mod count-' . (int) $n . '"><span class="pending-count">' . (int) $n . '</span></span>' : '';
		};
		$badge  = $bubble( $todo );

		add_menu_page( 'PN Chat', 'PN Chat' . $bubble( $todo + $props + $lessons ), $cap, 'pn-chat', array( __CLASS__, 'page_training' ), 'dashicons-format-chat', 58 );
		add_submenu_page( 'pn-chat', 'Εκπαίδευση', 'Εκπαίδευση', $cap, 'pn-chat', array( __CLASS__, 'page_training' ) );
		add_submenu_page( 'pn-chat', 'Μάθηση από τις συζητήσεις', 'Μάθηση' . $bubble( $lessons ), $cap, 'pn-chat-lessons', array( __CLASS__, 'page_lessons' ) );
		add_submenu_page( 'pn-chat', 'Προτάσεις AI', 'Προτάσεις AI' . $bubble( $props ), $cap, 'pn-chat-learn', array( __CLASS__, 'page_learn' ) );
		add_submenu_page( 'pn-chat', 'Ερωτήματα', 'Ερωτήματα' . $badge, $cap, 'pn-chat-questions', array( __CLASS__, 'page_questions' ) );
		add_submenu_page( 'pn-chat', 'Απαγορεύσεις', 'Απαγορεύσεις', $cap, 'pn-chat-blocks', array( __CLASS__, 'page_blocks' ) );
		add_submenu_page( 'pn-chat', 'Εγκέφαλος (αντίγραφο)', 'Εγκέφαλος', $cap, 'pn-chat-brain', array( __CLASS__, 'page_brain' ) );
		add_submenu_page( 'pn-chat', 'Έλεγχος ευρετηρίου', 'Έλεγχος ευρετηρίου', $cap, 'pn-chat-index', array( __CLASS__, 'page_index' ) );
		add_submenu_page( 'pn-chat', 'Ρυθμίσεις', 'Ρυθμίσεις', $cap, 'pn-chat-settings', array( __CLASS__, 'page_settings' ) );
	}

	/**
	 * Admin CSS on our screens.
	 *
	 * @param string $hook Screen hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'pn-chat' ) ) {
			return;
		}
		wp_enqueue_style( 'pn-chat-admin', PNCHAT_URL . 'assets/css/pn-chat-admin.css', array(), PNCHAT_VERSION );
	}

	/* ------------------------------------------------------------------ */
	/* helpers                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Stops unless the user may manage the chat and the nonce is right.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'pn-chat' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * An AI request may take a few minutes.
	 *
	 * @return void
	 */
	private static function long_request() {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- waits for the Claude API.
		}
	}

	/**
	 * Redirects back with a message.
	 *
	 * @param string              $page Page slug.
	 * @param string              $msg  Message code.
	 * @param array<string,mixed> $args More query args.
	 * @return never
	 */
	private static function back( $page, $msg, array $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page, 'pnchat_msg' => $msg ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * POST value, unslashed.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function post( $key ) {
		// Raw on purpose: each caller sanitises for its field (text, textarea, wp_kses for answers).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every caller ran guard() first.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/**
	 * GET value, sanitised.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function get( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen parameters.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	/**
	 * Message after a redirect.
	 *
	 * @return void
	 */
	private static function notices() {
		$msg = self::get( 'pnchat_msg' );
		$n   = absint( self::get( 'n' ) );
		$map = array(
			'saved'        => array( 'success', 'Η γνώση αποθηκεύτηκε. Ο βοηθός την ξέρει από τώρα.' ),
			'block_saved'  => array( 'success', 'Η απαγόρευση αποθηκεύτηκε.' ),
			'deleted'      => array( 'success', 'Διαγράφηκε.' ),
			'toggled'      => array( 'success', 'Η κατάσταση άλλαξε.' ),
			'synonyms'     => array( 'success', 'Τα συνώνυμα και τα θέματα αποθηκεύτηκαν.' ),
			'phrasing'     => array( 'success', 'Η ερώτηση προστέθηκε στη γνώση. Ο βοηθός θα την απαντά από τώρα.' ),
			'dismissed'    => array( 'success', 'Η ερώτηση αγνοήθηκε.' ),
			'q_deleted'    => array( 'success', sprintf( 'Διαγράφηκαν %d ερωτήσεις.', $n ) ),
			'q_dismissed'  => array( 'success', sprintf( 'Αγνοήθηκαν %d ερωτήσεις.', $n ) ),
			'replied'      => array( 'success', 'Το e-mail στάλθηκε.' ),
			'mail_failed'  => array( 'error', 'Το e-mail ΔΕΝ στάλθηκε. Ελέγξτε τις ρυθμίσεις αποστολής e-mail του WordPress (π.χ. SMTP plugin).' ),
			'bad_email'    => array( 'error', 'Η ερώτηση δεν έχει σωστό e-mail.' ),
			'empty'        => array( 'error', 'Συμπληρώστε τουλάχιστον μία ερώτηση ή λέξη-κλειδί και την απάντηση.' ),
			'empty_phr'    => array( 'error', 'Δεν αποθηκεύτηκε: γράψτε τουλάχιστον μία ερώτηση στο πεδίο «Ερωτήσεις» (ή μια λέξη-κλειδί). Ό,τι είχατε γράψει κρατήθηκε.' ),
			'empty_ans'    => array( 'error', 'Δεν αποθηκεύτηκε: το πεδίο «Απάντηση» είναι κενό. Γράψτε την απάντηση που θα δίνει ο βοηθός. Ό,τι είχατε γράψει κρατήθηκε.' ),
			'empty_both'   => array( 'error', 'Δεν αποθηκεύτηκε: γράψτε τουλάχιστον μία ερώτηση και την απάντηση. Ό,τι είχατε γράψει κρατήθηκε.' ),
			'empty_reply'  => array( 'error', 'Γράψτε την απάντηση.' ),
			'imported'     => array( 'success', sprintf( 'Ο εγκέφαλος φορτώθηκε: προστέθηκαν %d γνώσεις.', $n ) ),
			'import_error' => array( 'error', 'Το αρχείο δεν φορτώθηκε: ' . sanitize_text_field( self::get( 'err' ) ) ),
			'restored'     => array( 'success', 'Ο εγκέφαλος επανήλθε από το αντίγραφο.' ),
			'settings'     => array( 'success', 'Οι ρυθμίσεις αποθηκεύτηκαν.' ),
			'key_ok'       => array( 'success', 'Οι ρυθμίσεις αποθηκεύτηκαν. Το νέο API key δοκιμάστηκε και δουλεύει.' ),
			'key_bad'      => array( 'error', 'Οι ρυθμίσεις αποθηκεύτηκαν, αλλά το API key ΔΕΝ άλλαξε: αυτό που επικολλήθηκε δεν μοιάζει με κλειδί (ξεκινά με sk-ant-). Αντιγράψτε μόνο το κλειδί.' ),
			'key_rejected' => array( 'error', 'Οι ρυθμίσεις αποθηκεύτηκαν, αλλά το API key ΔΕΝ αποθηκεύτηκε: η Anthropic το απέρριψε (λάθος ή ακυρωμένο). Φτιάξτε νέο στο console.anthropic.com → API keys.' ),
			'key_dead'     => array( 'error', 'Οι ρυθμίσεις αποθηκεύτηκαν, αλλά η Anthropic απορρίπτει ' . PNChat_AI::key_source() . ' (λάθος ή ακυρωμένο). Βάλτε νέο κλειδί από το console.anthropic.com → API keys.' ),
			'not_found'    => array( 'error', 'Δεν βρέθηκε.' ),
			'save_failed'  => array( 'error', 'Η αλλαγή ΔΕΝ αποθηκεύτηκε: η βάση δεδομένων δεν τη δέχτηκε. Δοκιμάστε ξανά· αν επαναληφθεί, δείτε το αρχείο σφαλμάτων του server.' ),
			'replied_unsaved' => array( 'error', 'Το e-mail στάλθηκε, αλλά η ερώτηση δεν σημειώθηκε ως απαντημένη (σφάλμα βάσης δεδομένων).' ),
			'ai_saved'     => array( 'success', sprintf( 'Αποθηκεύτηκαν %d γνώσεις από την πρόταση του AI.', $n ) ),
			'ai_partial'   => array( 'error', sprintf( 'Αποθηκεύτηκαν %1$d γνώσεις· %2$d ΔΕΝ αποθηκεύτηκαν (η βάση δεδομένων τις αρνήθηκε). Ζητήστε ξανά πρόταση για τη σελίδα.', $n, absint( self::get( 'failed' ) ) ) ),
			'ai_error'     => array( 'error', 'AI: ' . sanitize_text_field( self::get( 'err' ) ) ),
			'ai_expired'   => array( 'error', 'Η πρόταση του AI έληξε (κρατιέται 1 ώρα). Ζητήστε τη ξανά.' ),
			'learn_queued' => array( 'success', sprintf( 'Μπήκαν %d πηγές για διάβασμα. Στέλνονται στο Claude στο παρασκήνιο και οι προτάσεις εμφανίζονται παρακάτω, συνήθως σε λίγα λεπτά. Μπορείτε να κλείσετε τη σελίδα.', $n ) ),
			'learn_none'   => array( 'success', sprintf( 'Δεν υπάρχει κάτι νέο να διαβαστεί: %d σελίδες δεν άλλαξαν από την τελευταία φορά.', $n ) ),
			'learn_dupe'   => array( 'error', 'Αυτή η πηγή περιμένει ήδη να διαβαστεί.' ),
			'learn_bad'    => array( 'error', 'Γράψτε μια διεύθυνση που ξεκινά με http:// ή https://, ή επιλέξτε ένα PDF.' ),
			'learn_stopped' => array( 'success', sprintf( 'Το διάβασμα σταμάτησε· %d πηγές δεν διαβάστηκαν.', $n ) ),
			'learn_step'   => array( 'success', sprintf( 'Ήρθε η απάντηση του Claude: %d νέες προτάσεις.', $n ) ),
			'learn_sent'   => array( 'success', 'Η πηγή στάλθηκε στο Claude. Η απάντηση έρχεται συνήθως σε λίγα λεπτά· η σελίδα ανανεώνεται μόνη της.' ),
			'learn_waiting' => array( 'success', 'Το Claude διαβάζει ακόμα. Ξαναδείτε σε λίγα λεπτά.' ),
			'learn_busy'   => array( 'error', 'Το AI διαβάζει ήδη μια πηγή στο παρασκήνιο. Περιμένετε λίγο.' ),
			'learn_limit'  => array( 'error', 'Έφτασε το όριο του μήνα για διάβασμα με AI (Ρυθμίσεις → AI). Οι υπόλοιπες πηγές περιμένουν.' ),
			'approved'     => array( 'success', 'Η γνώση εγκρίθηκε. Ο βοηθός την ξέρει από τώρα.' ),
			'lesson_added' => array( 'success', 'Η διατύπωση προστέθηκε στη γνώση και στο σετ δοκιμών.' ),
			'lesson_rejected' => array( 'success', 'Δεν θα προταθεί ξανά.' ),
			'lesson_undone' => array( 'success', 'Η διατύπωση αφαιρέθηκε από τη γνώση και δεν θα ξαναμάθει μόνη της.' ),
			'test_added'   => array( 'success', 'Η δοκιμή προστέθηκε.' ),
			'merged'       => array( 'success', 'Οι ερωτήσεις προστέθηκαν στην υπάρχουσα γνώση.' ),
			'rejected'     => array( 'success', 'Η πρόταση απορρίφθηκε. Δεν θα ξαναπροταθεί.' ),
			'p_approved'   => array( 'success', sprintf( 'Εγκρίθηκαν %d προτάσεις.', $n ) ),
			'p_rejected'   => array( 'success', sprintf( 'Απορρίφθηκαν %d προτάσεις.', $n ) ),
			'index_now'    => array( 'success', sprintf( 'Διαβάστηκαν τώρα %d σελίδες.', $n ) ),
			'index_refresh' => array( 'success', 'Όλες οι σελίδες θα ξαναδιαβαστούν στο παρασκήνιο, 40 ανά λεπτό. Η αναζήτηση συνεχίζει κανονικά στο μεταξύ.' ),
			'media_queued' => array( 'success', sprintf( 'Μπήκαν %d PDF για διάβασμα με AI. Οι προτάσεις γνώσεων θα εμφανιστούν στις «Προτάσεις AI», συνήθως σε λίγα λεπτά.', $n ) ),
			'media_none'   => array( 'success', 'Δεν μπήκε κανένα PDF: έχουν ήδη διαβαστεί ή είναι ήδη στην ουρά.' ),
			'ai_off'       => array( 'error', 'Το AI δεν είναι ενεργό ή δεν έχει API key (Ρυθμίσεις → AI).' ),
			'reindexed'    => array( 'success', sprintf( 'Η ενημέρωση του ευρετηρίου ξεκίνησε: %d σελίδες τώρα, οι υπόλοιπες στο παρασκήνιο (40 ανά λεπτό).', $n ) ),
		);
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $map[ $msg ][0] ), esc_html( $map[ $msg ][1] ) );
		}
		$warn = get_transient( 'pnchat_warn_' . get_current_user_id() );
		if ( is_array( $warn ) && $warn ) {
			delete_transient( 'pnchat_warn_' . get_current_user_id() );
			echo '<div class="notice notice-warning"><p><strong>Προσοχή στην εκπαίδευση:</strong></p><ul class="pnchat-list">';
			foreach ( $warn as $w ) {
				echo '<li>' . esc_html( (string) $w ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	/**
	 * Hidden form fields: action and nonce.
	 *
	 * @param string $action admin-post action without the prefix.
	 * @return void
	 */
	private static function form_fields( $action ) {
		echo '<input type="hidden" name="action" value="pnchat_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'pnchat_' . $action );
	}

	/**
	 * Link to an admin-post action with a nonce.
	 *
	 * @param string              $action Action without the prefix.
	 * @param array<string,mixed> $args   Args.
	 * @return string
	 */
	private static function action_url( $action, array $args ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'pnchat_' . $action ), $args ), admin_url( 'admin-post.php' ) ), 'pnchat_' . $action );
	}

	/**
	 * Date in the site's time zone.
	 *
	 * @param string|null $gmt MySQL GMT date.
	 * @return string
	 */
	private static function date( $gmt ) {
		if ( ! $gmt ) {
			return '';
		}
		return (string) get_date_from_gmt( (string) $gmt, 'd/m/Y H:i' );
	}

	/* ------------------------------------------------------------------ */
	/* training (answers) and refusals                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Εκπαίδευση.
	 *
	 * @return void
	 */
	public static function page_training() {
		self::entries_page( 'answer' );
	}

	/**
	 * Απαγορεύσεις.
	 *
	 * @return void
	 */
	public static function page_blocks() {
		self::entries_page( 'block' );
	}

	/**
	 * List, form and (for answers) the test box and the synonyms.
	 *
	 * @param string $kind 'answer' or 'block'.
	 * @return void
	 */
	private static function entries_page( $kind ) {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$page  = 'answer' === $kind ? 'pn-chat' : 'pn-chat-blocks';
		$edit  = absint( self::get( 'edit' ) );
		$isnew = '1' === self::get( 'new' );
		$from  = absint( self::get( 'from_question' ) );

		echo '<div class="wrap pnchat-admin">';
		if ( 'answer' === $kind ) {
			echo '<h1 class="wp-heading-inline">Εκπαίδευση του βοηθού</h1>';
		} else {
			echo '<h1 class="wp-heading-inline">Απαγορευμένες ερωτήσεις</h1>';
		}
		if ( ! $edit && ! $isnew ) {
			echo ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=' . $page . '&new=1' ) ) . '">' . ( 'answer' === $kind ? 'Νέα γνώση' : 'Νέα απαγόρευση' ) . '</a>';
		}
		echo '<hr class="wp-header-end">';
		self::notices();

		if ( $edit || $isnew ) {
			$entry = $edit ? PNChat_Store::entry( $edit ) : null;
			if ( $edit && ( ! $entry || $entry['kind'] !== $kind ) ) {
				echo '<div class="notice notice-error"><p>Δεν βρέθηκε.</p></div></div>';
				return;
			}
			self::entry_form( $kind, $entry, $from ? PNChat_Store::question( $from ) : null );
			echo '</div>';
			return;
		}

		if ( 'answer' === $kind ) {
			echo '<p class="pnchat-intro">Εδώ «μαθαίνετε» στον βοηθό τι να απαντά. Κάθε <strong>γνώση</strong> έχει τις ερωτήσεις με τις οποίες μπορεί να ρωτηθεί (όσο περισσότερες διατυπώσεις, τόσο καλύτερα) και την απάντηση. Ο βοηθός απαντά <strong>μόνο</strong> από αυτές τις γνώσεις· ό,τι δεν ξέρει το καταγράφει στα <a href="' . esc_url( admin_url( 'admin.php?page=pn-chat-questions' ) ) . '">Ερωτήματα</a>, για να το απαντήσετε και να τον εκπαιδεύσετε. Αν μια ερώτηση έχει πολλά θέματα, απαντά συνδυαστικά.</p>';
			self::test_box();
			self::ai_box();
		} else {
			echo '<p class="pnchat-intro">Ερωτήσεις στις οποίες ο βοηθός <strong>δεν</strong> απαντά (π.χ. ιατρικές συμβουλές, δοσολογίες, τιμές φαρμάκων). Όταν τις αναγνωρίσει, δίνει μόνο το μήνυμα που ορίζετε. Τις «εκπαιδεύετε» όπως τις γνώσεις: με διατυπώσεις και λέξεις-κλειδιά. Αν μια ερώτηση ταιριάζει και σε γνώση και σε απαγόρευση, υπερισχύει η απαγόρευση.</p>';
		}

		$search  = self::get( 's' );
		$entries = PNChat_Store::entries( $kind, false, $search );
		echo '<form method="get" class="pnchat-search"><input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Αναζήτηση…"> <button class="button">Αναζήτηση</button></form>';

		echo '<table class="widefat striped pnchat-table"><thead><tr>';
		echo '<th>' . ( 'answer' === $kind ? 'Γνώση' : 'Απαγόρευση' ) . '</th><th>Ερωτήσεις / λέξεις-κλειδιά</th><th class="num">Χρήσεις</th><th>Κατάσταση</th><th></th></tr></thead><tbody>';
		if ( ! $entries ) {
			echo '<tr><td colspan="5">' . ( '' !== $search ? 'Δεν βρέθηκε τίποτα.' : ( 'answer' === $kind ? 'Δεν υπάρχουν ακόμα γνώσεις. Πατήστε «Νέα γνώση».' : 'Δεν υπάρχουν απαγορεύσεις.' ) ) . '</td></tr>';
		}
		foreach ( $entries as $e ) {
			$edit_url = admin_url( 'admin.php?page=' . $page . '&edit=' . $e['id'] );
			echo '<tr class="' . ( $e['active'] ? '' : 'pnchat-inactive' ) . '">';
			echo '<td><a class="row-title" href="' . esc_url( $edit_url ) . '">' . esc_html( $e['title'] ) . '</a><div class="pnchat-preview">' . esc_html( wp_trim_words( PNChat_Brain::plain_answer( $e['answer'] ), 18 ) ) . '</div></td>';
			echo '<td>';
			$shown = array_slice( $e['phrasings'], 0, 3 );
			foreach ( $shown as $p ) {
				echo '<div>«' . esc_html( $p ) . '»</div>';
			}
			if ( count( $e['phrasings'] ) > 3 ) {
				echo '<div class="description">+' . ( count( $e['phrasings'] ) - 3 ) . ' ακόμα</div>';
			}
			if ( $e['keywords'] ) {
				echo '<div class="pnchat-keywords">' . esc_html( implode( ' · ', $e['keywords'] ) ) . '</div>';
			}
			echo '</td>';
			echo '<td class="num">' . (int) $e['hits'] . '</td>';
			echo '<td>' . ( $e['active'] ? '<span class="pnchat-pill pnchat-pill--on">Ενεργή</span>' : '<span class="pnchat-pill">Ανενεργή</span>' ) . '</td>';
			echo '<td class="pnchat-actions"><a href="' . esc_url( $edit_url ) . '">Επεξεργασία</a> | ';
			echo '<a href="' . esc_url( self::action_url( 'toggle_entry', array( 'id' => $e['id'] ) ) ) . '">' . ( $e['active'] ? 'Απενεργοποίηση' : 'Ενεργοποίηση' ) . '</a> | ';
			echo '<a class="pnchat-danger" href="' . esc_url( self::action_url( 'delete_entry', array( 'id' => $e['id'] ) ) ) . '" onclick="return confirm(\'Διαγραφή;\');">Διαγραφή</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( 'answer' === $kind ) {
			self::synonyms_box();
		}
		echo '</div>';
	}

	/**
	 * Add / edit form.
	 *
	 * @param string                   $kind  'answer' or 'block'.
	 * @param array<string,mixed>|null $entry Entry being edited.
	 * @param array<string,mixed>|null $from  Question being trained on.
	 * @return void
	 */
	private static function entry_form( $kind, $entry, $from ) {
		$is_block  = 'block' === $kind;
		$page      = $is_block ? 'pn-chat-blocks' : 'pn-chat';
		$phrasings = $entry ? $entry['phrasings'] : array();
		$from_text = $from ? (string) $from['question'] : '';
		if ( $from ) {
			// The unanswered part is the real question to learn.
			$parts = PNChat_Store::lines( (string) $from['unmatched'] );
			foreach ( $parts ? $parts : array( $from_text ) as $p ) {
				$phrasings[] = $p;
			}
		}
		$answer   = $entry ? $entry['answer'] : ( $is_block ? self::DEFAULT_BLOCK : '' );
		$active   = $entry ? (int) $entry['active'] : 1;
		$title    = $entry['title'] ?? '';
		$keywords = $entry['keywords'] ?? array();

		// Values of a save that was refused (missing field): shown again.
		$kept      = get_transient( 'pnchat_form_' . get_current_user_id() );
		$kept_used = false;
		if ( is_array( $kept ) && ( $kept['kind'] ?? '' ) === $kind && (int) ( $kept['id'] ?? 0 ) === (int) ( $entry['id'] ?? 0 ) ) {
			delete_transient( 'pnchat_form_' . get_current_user_id() );
			$title     = (string) $kept['title'];
			$phrasings = (array) $kept['phrasings'];
			$keywords  = (array) $kept['keywords'];
			$answer    = (string) $kept['answer'];
			$active    = (int) $kept['active'];
			$kept_used = true;
		}

		// A draft written by the AI from the site's pages, to review.
		$ai = ( ! $entry && ! $is_block ) ? self::ai_stash_get( self::get( 'ai' ) ) : null;
		if ( '' !== self::get( 'ai' ) && null === $ai && ! $entry ) {
			echo '<div class="notice notice-error inline"><p>Η πρόταση του AI έληξε (κρατιέται 1 ώρα). Ζητήστε τη ξανά.</p></div>';
		}
		// The answer the AI gave in the chat, stored with the question.
		$draft = ( ! $ai && ! $entry && ! $is_block && $from && ! $kept_used ) ? PNChat_Store::draft_of( $from ) : null;
		if ( $draft ) {
			$ai = array(
				'type'   => 'question',
				'result' => array(
					'found'   => true,
					'entry'   => $draft['entry'],
					'sources' => (array) ( $draft['sources'] ?? array() ),
					'note'    => 'Αυτή την απάντηση έδωσε το AI στον επισκέπτη στο chat. Με την αποθήκευση γίνεται γνώση και απαντιέται από εδώ και πέρα χωρίς AI.',
				),
			);
		}
		if ( $ai && 'question' === ( $ai['type'] ?? '' ) ) {
			$r = $ai['result'];
			if ( ! empty( $r['found'] ) ) {
				$title     = (string) $r['entry']['title'];
				$phrasings = array_values( array_unique( array_merge( $phrasings, (array) $r['entry']['phrasings'] ) ) );
				$keywords  = (array) $r['entry']['keywords'];
				$answer    = (string) $r['entry']['answer'];
				echo '<div class="notice notice-warning inline pnchat-ai-notice"><p><strong>✨ Πρόταση του AI από τις σελίδες του site.</strong> Διαβάστε την και διορθώστε ό,τι χρειάζεται <strong>πριν</strong> την αποθηκεύσετε: ο βοηθός θα τη λέει αυτούσια.</p>';
			} else {
				echo '<div class="notice notice-error inline pnchat-ai-notice"><p><strong>Το AI δεν βρήκε απάντηση στις σελίδες του site.</strong> Γράψτε την απάντηση εσείς.</p>';
			}
			if ( '' !== (string) $r['note'] ) {
				echo '<p>' . esc_html( (string) $r['note'] ) . '</p>';
			}
			if ( $r['sources'] ) {
				echo '<p>Σελίδες που διάβασε: ';
				$links = array();
				foreach ( $r['sources'] as $src ) {
					$links[] = '<a href="' . esc_url( $src['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $src['title'] ) . '</a>';
				}
				echo implode( ', ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			}
			echo '</div>';
		}

		echo '<h2>' . ( $entry ? 'Επεξεργασία' : ( $is_block ? 'Νέα απαγόρευση' : 'Νέα γνώση' ) ) . '</h2>';
		if ( $from ) {
			echo '<div class="notice notice-info inline"><p>Εκπαίδευση από την ερώτηση: <strong>«' . esc_html( $from_text ) . '»</strong>';
			if ( ! empty( $from['email'] ) ) {
				echo '<br>Ο επισκέπτης άφησε e-mail (' . esc_html( (string) $from['email'] ) . '). Μετά την αποθήκευση μπορείτε να του στείλετε την απάντηση.';
			}
			echo '</p></div>';
		}

		if ( ! $entry && ! $is_block && $from && ! $ai && PNChat_AI::enabled() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pnchat-ai-form">';
			self::form_fields( 'ai_draft' );
			echo '<input type="hidden" name="from_question" value="' . (int) $from['id'] . '">';
			echo '<button class="button button-secondary pnchat-ai-button">✨ Πρόταση με AI από τις σελίδες του site</button> <span class="description">Το AI διαβάζει τις σχετικές σελίδες του site και γράφει μια πρόταση· τίποτα δεν αποθηκεύεται χωρίς εσάς.</span>';
			echo '</form>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pnchat-form">';
		self::form_fields( 'save_entry' );
		echo '<input type="hidden" name="kind" value="' . esc_attr( $kind ) . '">';
		echo '<input type="hidden" name="id" value="' . (int) ( $entry['id'] ?? 0 ) . '">';
		echo '<input type="hidden" name="from_question" value="' . (int) ( $from['id'] ?? 0 ) . '">';

		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="pnchat-title">Τίτλος</label></th><td><input id="pnchat-title" name="title" type="text" class="regular-text" value="' . esc_attr( $title ) . '" placeholder="' . ( $is_block ? 'π.χ. Ιατρικές συμβουλές' : 'π.χ. Τι είναι το PlanDose' ) . '"><p class="description">Για εσάς· ' . ( $is_block ? 'δεν εμφανίζεται στον επισκέπτη.' : 'εμφανίζεται πάνω από την απάντηση όταν ο βοηθός απαντά συνδυαστικά. Αν μείνει κενό, μπαίνει η πρώτη ερώτηση.' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="pnchat-phr">Ερωτήσεις</label></th><td><textarea id="pnchat-phr" name="phrasings" rows="7" class="large-text">' . esc_textarea( implode( "\n", $phrasings ) ) . '</textarea>';
		echo '<p class="description">Μία ανά γραμμή: όλοι οι τρόποι που μπορεί να ρωτήσει κάποιος. ' . ( $is_block ? 'π.χ. «Τι δόση να πάρω;», «Ποιο φάρμακο είναι καλό για τον πόνο;»' : 'π.χ. «Τι είναι το PlanDose;», «Τι κάνει το PlanDose;», «Πες μου για το PlanDose».' ) . ' Τόνοι, κεφαλαία, ορθογραφία και Greeklish δεν χρειάζονται ξεχωριστά.</p></td></tr>';

		echo '<tr><th scope="row"><label for="pnchat-kw">Λέξεις-κλειδιά</label></th><td><textarea id="pnchat-kw" name="keywords" rows="3" class="large-text">' . esc_textarea( implode( "\n", $keywords ) ) . '</textarea>';
		echo '<p class="description">Προαιρετικό. Μία λέξη ή φράση ανά γραμμή· όταν υπάρχει στην ερώτηση, ' . ( $is_block ? 'η ερώτηση απαγορεύεται' : 'ο βοηθός δίνει αυτή την απάντηση' ) . ' (π.χ. ' . ( $is_block ? '«παρενέργειες», «δοσολογία»' : '«τιμολόγιο», «μηνιαίο όριο»' ) . '). Χρησιμοποιήστε τις με φειδώ.</p></td></tr>';

		echo '<tr><th scope="row"><label for="pnchat-answer">' . ( $is_block ? 'Μήνυμα προς τον επισκέπτη' : 'Απάντηση' ) . '</label></th><td>';
		if ( $is_block ) {
			echo '<textarea id="pnchat-answer" name="answer" rows="3" class="large-text">' . esc_textarea( $answer ) . '</textarea>';
		} else {
			wp_editor(
				$answer,
				'pnchat-answer',
				array(
					'textarea_name' => 'answer',
					'textarea_rows' => 8,
					'media_buttons' => false,
					'teeny'         => true,
					'quicktags'     => array( 'buttons' => 'strong,em,link,ul,ol,li' ),
					'tinymce'       => array( 'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo' ),
				)
			);
			echo '<p class="description">Επιτρέπονται έντονα, πλάγια, λίστες και σύνδεσμοι.</p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">Κατάσταση</th><td><label><input type="checkbox" name="active" value="1" ' . checked( 1, $active, false ) . '> Ενεργή</label></td></tr>';
		echo '</table>';
		submit_button( $entry ? 'Αποθήκευση' : ( $is_block ? 'Αποθήκευση απαγόρευσης' : 'Αποθήκευση γνώσης' ) );
		echo ' <a class="button-link" href="' . esc_url( admin_url( 'admin.php?page=' . ( $from ? 'pn-chat-questions' : $page ) ) ) . '">Άκυρο</a>';
		echo '</form>';

		if ( $from && ! $is_block ) {
			self::add_to_existing_box( $from );
		}
	}

	/**
	 * "Add this question to an existing entry" (training without a new entry).
	 *
	 * @param array<string,mixed> $q Question row.
	 * @return void
	 */
	private static function add_to_existing_box( array $q ) {
		$entries = PNChat_Store::entries( 'answer' );
		if ( ! $entries ) {
			return;
		}
		$best    = PNChat_Brain::matcher()->rank( (string) $q['question'] );
		$best_id = $best ? (int) $best[0]['id'] : 0;
		echo '<h2>…ή προσθέστε την ερώτηση σε γνώση που υπάρχει</h2>';
		echo '<p class="description">Αν η απάντηση υπάρχει ήδη αλλά ο βοηθός δεν κατάλαβε την ερώτηση, προσθέστε την ως νέα διατύπωση.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pnchat-inline-form">';
		self::form_fields( 'question' );
		echo '<input type="hidden" name="id" value="' . (int) $q['id'] . '"><input type="hidden" name="do" value="add_to">';
		echo '<select name="entry_id">';
		foreach ( $entries as $e ) {
			echo '<option value="' . (int) $e['id'] . '" ' . selected( $best_id, $e['id'], false ) . '>' . esc_html( $e['title'] ) . '</option>';
		}
		echo '</select> ';
		submit_button( 'Προσθήκη', 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The "try it" box.
	 *
	 * @return void
	 */
	private static function test_box() {
		$q = self::get( 'test' );
		echo '<div class="pnchat-card"><h2>Δοκιμή</h2><p class="description">Γράψτε μια ερώτηση για να δείτε τι θα απαντούσε ο βοηθός (δεν καταγράφεται). Τα ποσοστά είναι βαθμός ομοιότητας με τις εκπαιδευμένες ερωτήσεις, όχι πιθανότητα σωστής απάντησης.</p>';
		echo '<form method="get" class="pnchat-inline-form"><input type="hidden" name="page" value="pn-chat"><input type="search" name="test" class="regular-text" value="' . esc_attr( $q ) . '" placeholder="π.χ. Τι είναι το PlanDose και πόσο κοστίζει;"> <button class="button button-primary">Δοκιμή</button></form>';
		if ( '' !== $q ) {
			$r      = PNChat_Brain::matcher( true )->ask( $q );
			$labels = array(
				'answered'   => 'Απαντά',
				'partial'    => 'Απαντά εν μέρει',
				'blocked'    => 'Απαγορεύεται',
				'unanswered' => 'Δεν ξέρει (ζητά e-mail)',
			);
			echo '<div class="pnchat-test-result"><p><strong>Αποτέλεσμα:</strong> ' . esc_html( $labels[ $r['status'] ] ?? $r['status'] ) . '</p>';
			foreach ( $r['items'] as $i ) {
				echo '<div class="pnchat-test-item ' . ( 'block' === $i['kind'] ? 'is-block' : '' ) . '"><strong>' . esc_html( $i['title'] ) . '</strong> <span class="description">(ομοιότητα ' . esc_html( number_format_i18n( $i['score'] * 100 ) ) . '%)</span><div>' . wp_kses( PNChat_Brain::render_answer( $i['answer'] ), PNChat_Brain::allowed_html() ) . '</div></div>';
			}
			if ( $r['unmatched'] ) {
				echo '<p>Χωρίς απάντηση: «' . esc_html( implode( '», «', $r['unmatched'] ) ) . '»</p>';
			}
			foreach ( PNChat_Rest::table_rows( $r, $q ) as $tr ) {
				echo '<div class="pnchat-test-item is-site"><strong>Από πίνακα του site</strong><div>' . wp_kses_post( $tr['html'] ) . '</div></div>';
			}
			$site = PNChat_Rest::site_results( $r, $q );
			if ( $site ) {
				echo '<p><strong>Από το site:</strong></p>';
				foreach ( $site as $sr ) {
					echo '<div class="pnchat-test-item is-site"><strong>' . esc_html( $sr['title'] ) . '</strong> <span class="description">(ομοιότητα ' . esc_html( number_format_i18n( $sr['score'] * 100 ) ) . '%)</span><div>' . wp_kses_post( PNChat_Site_Search::render( $sr ) ) . '</div></div>';
				}
			} elseif ( in_array( $r['status'], array( 'unanswered', 'partial' ), true ) && PNChat_Settings::value( 'site_search' ) ) {
				echo '<p class="description">Δεν βρέθηκε ούτε σελίδα του site.</p>';
			}
			echo '<details><summary>Πώς αποφάσισε</summary>';
			foreach ( $r['parts'] as $p ) {
				echo '<p><em>«' . esc_html( $p['text'] ) . '»</em></p><ol>';
				foreach ( $p['top'] as $c ) {
					echo '<li>' . esc_html( $c['title'] ) . ( 'block' === $c['kind'] ? ' [απαγόρευση]' : '' ) . ' — ' . esc_html( number_format_i18n( $c['score'] * 100 ) ) . '%</li>';
				}
				if ( ! $p['top'] ) {
					echo '<li>καμία σχετική γνώση</li>';
				}
				echo '</ol>';
			}
			echo '<p class="description">Απαντά από ' . esc_html( number_format_i18n( PNChat_Settings::threshold() * 100 ) ) . '% και πάνω (Ρυθμίσεις → Αυστηρότητα).</p></details></div>';
		}
		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* AI training assistant                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Keeps an AI draft for the current admin for an hour.
	 *
	 * @param array<string,mixed> $data Draft.
	 * @return string Key.
	 */
	private static function ai_stash( array $data ) {
		$key = strtolower( wp_generate_password( 10, false ) );
		set_transient( 'pnchat_ai_' . get_current_user_id() . '_' . $key, $data, HOUR_IN_SECONDS );
		return $key;
	}

	/**
	 * A stored AI draft of the current admin.
	 *
	 * @param string $key Key.
	 * @return array<string,mixed>|null
	 */
	private static function ai_stash_get( $key ) {
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			return null;
		}
		$d = get_transient( 'pnchat_ai_' . get_current_user_id() . '_' . $key );
		return is_array( $d ) ? $d : null;
	}

	/**
	 * The AI card on the training screen.
	 *
	 * @return void
	 */
	private static function ai_box() {
		echo '<div class="pnchat-card pnchat-ai-card"><h2>✨ AI βοηθός εκπαίδευσης</h2>';
		if ( ! PNChat_AI::enabled() ) {
			echo '<p>Το AI διαβάζει σελίδες του site σας και γράφει έτοιμες γνώσεις, που εγκρίνετε εσείς πριν αποθηκευτούν. Αυτό δεν αλλάζει το δημόσιο chat: εκεί το AI απαντά μόνο αν ενεργοποιηθεί χωριστά το «AI και μέσα στο chat». Ενεργοποιήστε το από τις <a href="' . esc_url( admin_url( 'admin.php?page=pn-chat-settings#pnchat-ai' ) ) . '">Ρυθμίσεις</a>.</p></div>';
			return;
		}
		echo '<p class="description">Το AI διαβάζει μόνο σελίδες του δικού σας site. Ό,τι γράψει το βλέπετε και το εγκρίνετε εσείς· τίποτα δεν αποθηκεύεται μόνο του.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pnchat-inline-form">';
		self::form_fields( 'ai_draft' );
		echo '<label for="pnchat-ai-q"><strong>Απάντηση σε ερώτηση:</strong></label> <input id="pnchat-ai-q" type="text" name="question" class="regular-text" required placeholder="π.χ. Πώς γίνομαι μέλος στην κοινότητα Viber;"> ';
		echo '<button class="button">✨ Πρόταση</button></form>';

		$posts = get_posts(
			array(
				'post_type'      => PNChat_Site_Search::post_types(),
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => 300,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$excluded = PNChat_Site_Search::excluded_ids();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pnchat-inline-form" style="margin-top:12px">';
		self::form_fields( 'ai_page' );
		echo '<label for="pnchat-ai-page"><strong>Γνώσεις από σελίδα:</strong></label> <select id="pnchat-ai-page" name="post_id" required><option value="">— επιλέξτε σελίδα ή άρθρο —</option>';
		foreach ( $posts as $p ) {
			if ( in_array( (int) $p->ID, $excluded, true ) ) {
				continue;
			}
			echo '<option value="' . (int) $p->ID . '">' . esc_html( get_the_title( $p ) ) . '</option>';
		}
		echo '</select> <button class="button">✨ Φτιάξε γνώσεις</button></form>';
		echo '<p class="description">Οι γνώσεις από σελίδες, διευθύνσεις και PDF πηγαίνουν στις <a href="' . esc_url( admin_url( 'admin.php?page=pn-chat-learn' ) ) . '">Προτάσεις AI</a> για έγκριση. Εκεί μπορείτε να ζητήσετε να διαβαστεί και όλο το site. Κόστος έως τώρα: ' . esc_html( self::ai_cost_text() ) . '.</p></div>';
	}

	/**
	 * Running cost in words.
	 *
	 * @return string
	 */
	private static function ai_cost_text() {
		$u     = PNChat_AI::usage();
		$calls = (int) ( $u['calls'] ?? 0 );
		if ( ! $calls ) {
			return 'καμία κλήση ακόμα';
		}
		$text = sprintf( '%d κλήσεις, %s tokens εισόδου, %s tokens εξόδου', $calls, number_format_i18n( (int) ( $u['input_tokens'] ?? 0 ) ), number_format_i18n( (int) ( $u['output_tokens'] ?? 0 ) ) );
		if ( isset( $u['micro_usd'] ) ) {
			$text .= sprintf( ' (περίπου $%s σε τιμές καταλόγου', number_format_i18n( $u['micro_usd'] / 1e6, 2 ) );
			$text .= ! empty( $u['unpriced'] ) ? sprintf( '· %d κλήσεις με άλλο μοντέλο δεν μετρήθηκαν)', (int) $u['unpriced'] ) : ')';
		}
		return $text;
	}

	/**
	 * Drafts one entry for a question.
	 *
	 * @return void
	 */
	public static function handle_ai_draft() {
		self::guard( 'pnchat_ai_draft' );
		self::long_request();
		$from     = absint( self::post( 'from_question' ) );
		$q        = $from ? PNChat_Store::question( $from ) : null;
		$question = $q ? (string) $q['question'] : sanitize_text_field( self::post( 'question' ) );
		if ( $q ) {
			$parts = PNChat_Store::lines( (string) $q['unmatched'] );
			if ( $parts ) {
				$question = implode( ' ', $parts );
			}
		}
		$back = $from ? array( 'new' => 1, 'from_question' => $from ) : array( 'new' => 1 );
		if ( '' === trim( $question ) ) {
			self::back( 'pn-chat', 'empty', $back );
		}
		$r = PNChat_AI::draft_for_question( $question );
		if ( is_wp_error( $r ) ) {
			self::back( 'pn-chat', 'ai_error', array_merge( $back, array( 'err' => $r->get_error_message() ) ) );
		}
		if ( ! $q ) {
			// A question typed here becomes the first phrasing.
			$r['entry']['phrasings'] = array_values( array_unique( array_merge( array( $question ), (array) ( $r['entry']['phrasings'] ?? array() ) ) ) );
		}
		$key = self::ai_stash(
			array(
				'type'   => 'question',
				'result' => $r,
			)
		);
		self::back( 'pn-chat', '', array_merge( $back, array( 'ai' => $key ) ) );
	}

	/**
	 * «Γνώσεις από σελίδα»: the page goes to the reading queue.
	 *
	 * @return void
	 */
	public static function handle_ai_page() {
		self::guard( 'pnchat_ai_page' );
		$id = absint( self::post( 'post_id' ) );
		if ( ! PNChat_Site_Search::searchable( get_post( $id ) ) ) {
			self::back( 'pn-chat', 'ai_error', array( 'err' => 'Η σελίδα δεν είναι δημόσια ή είναι εξαιρεμένη από την αναζήτηση.' ) );
		}
		$n = PNChat_Learn::enqueue(
			array(
				array(
					'type' => 'post',
					'id'   => $id,
				),
			),
			(string) get_the_title( $id )
		);
		self::back( 'pn-chat-learn', $n ? 'learn_queued' : 'learn_dupe', array( 'n' => $n ) );
	}

	/* ------------------------------------------------------------------ */
	/* AI proposals («Προτάσεις AI»)                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Προτάσεις AI: what to read, progress, proposals waiting for approval.
	 *
	 * @return void
	 */
	public static function page_learn() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'pn-chat' ), 403 );
		}
		$queue = PNChat_Learn::queue();
		$sent  = PNChat_Learn::sent();
		$state = PNChat_Learn::state();
		$live  = ( $queue && '' === $state['paused'] ) || $sent;
		if ( $live ) {
			// The reading goes on in the background; the page follows it.
			PNChat_Learn::schedule();
			// Reloads every 20 s to show new proposals, but never while one
			// is being edited.
			echo '<script>(function(){var dirty=false;document.addEventListener("input",function(){dirty=true;});setInterval(function(){var a=document.activeElement;if(!dirty&&!(a&&/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName))){location.reload();}},20000);})();</script>';
		}
		echo '<div class="wrap pnchat-admin"><h1>✨ Προτάσεις AI</h1>';
		self::notices();
		echo '<p class="pnchat-intro">Το AI (Claude) διαβάζει σελίδες του site σας, μια διεύθυνση ή ένα PDF (π.χ. εγχειρίδιο) και <strong>προτείνει</strong> γνώσεις. Οι προτάσεις περιμένουν εδώ: <strong>τίποτα δεν φτάνει στο chat πριν πατήσετε «Έγκριση»</strong>. Το chat συνεχίζει να απαντά χωρίς AI, μόνο από τις εγκεκριμένες γνώσεις.</p>';

		if ( ! PNChat_Learn::available() ) {
			echo '<div class="notice notice-info inline"><p>Για να διαβάζει το AI, βάλτε API key της Anthropic και ενεργοποιήστε το AI στις <a href="' . esc_url( admin_url( 'admin.php?page=pn-chat-settings#pnchat-ai' ) ) . '">Ρυθμίσεις</a>.</p></div>';
		} else {
			echo '<div class="pnchat-card"><h2>Τι να διαβάσει</h2>';
			echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pnchat-inline-form">';
			self::form_fields( 'learn_read' );
			echo '<label for="pnchat-learn-url"><strong>Διεύθυνση:</strong></label> <input id="pnchat-learn-url" type="url" name="url" class="regular-text" placeholder="https://… σελίδα ή PDF"> ';
			echo '<label for="pnchat-learn-pdf"><strong>ή PDF:</strong></label> <input id="pnchat-learn-pdf" type="file" name="pdf" accept="application/pdf,.pdf"> ';
			echo '<button class="button button-primary">✨ Διάβασε</button></form>';
			echo '<p class="description">PDF έως 20 MB και έως 100 σελίδες περίπου. Το AI διαβάζει και πίνακες και εικόνες του PDF.</p>';

			$count = (int) PNChat_Site_Search::count();
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pnchat-inline-form" style="margin-top:12px">';
			self::form_fields( 'learn_site' );
			echo '<button class="button">✨ Διάβασε όλο το site' . ( $count ? ' (' . (int) $count . ' σελίδες)' : '' ) . '</button> ';
			echo '<label><input type="checkbox" name="changed_only" value="1" checked> μόνο όσες είναι νέες ή άλλαξαν από την τελευταία φορά</label></form>';
			printf(
				'<p class="description">Αυτόν τον μήνα διαβάστηκαν %1$d από %2$d πηγές (όριο στις Ρυθμίσεις). Κόστος AI έως τώρα: %3$s.</p></div>',
				(int) PNChat_Learn::used_this_month(),
				(int) PNChat_Learn::monthly_limit(),
				esc_html( self::ai_cost_text() )
			);
		}

		self::learn_progress( $queue, $sent, $state );
		self::proposals_list();
		echo '</div>';
	}

	/**
	 * Progress of the reading and its errors.
	 *
	 * @param array<int,array<string,mixed>> $queue Waiting jobs.
	 * @param array<int,array<string,mixed>> $sent  Sources at Claude.
	 * @param array<string,mixed>            $state Progress.
	 * @return void
	 */
	private static function learn_progress( array $queue, array $sent, array $state ) {
		if ( ! $queue && ! $sent && ! $state['total'] ) {
			return;
		}
		$busy = $queue || $sent;
		echo '<div class="pnchat-card"><h2>' . ( $busy ? 'Διάβασμα σε εξέλιξη' : 'Τελευταίο διάβασμα' ) . '</h2>';
		printf(
			'<p><strong>%1$s</strong><br>Διαβάστηκαν %2$d από %3$d πηγές · %4$d νέες προτάσεις%5$s.</p>',
			esc_html( (string) $state['label'] ),
			(int) $state['done'],
			(int) $state['total'],
			(int) $state['proposals'],
			$state['skipped'] ? ' · ' . (int) $state['skipped'] . ' παραλείφθηκαν (υπάρχουν ήδη ή τις είχατε απορρίψει)' : ''
		);
		if ( $sent ) {
			echo '<p>Στάλθηκαν στο Claude και περιμένουν απάντηση: <strong>' . esc_html( implode( ' · ', array_map( fn( $b ) => (string) $b['label'], $sent ) ) ) . '</strong></p>';
			echo '<p class="description">Το Claude τα διαβάζει στους δικούς του servers, συνήθως σε λίγα λεπτά (το πολύ 24 ώρες). Το site ελέγχει κάθε λεπτό, όσο έχει επισκέψεις· μπορείτε να κλείσετε τη σελίδα.</p>';
		}
		if ( $busy ) {
			$total = max( 1, (int) $state['total'] );
			echo '<progress max="' . (int) $total . '" value="' . (int) $state['done'] . '" style="width:100%;max-width:600px"></progress>';
			if ( 'limit' === $state['paused'] ) {
				echo '<p class="pnchat-warn">Σταμάτησε: έφτασε το όριο του μήνα (Ρυθμίσεις → «Όριο διαβάσματος ανά μήνα»). Οι υπόλοιπες ' . count( $queue ) . ' πηγές περιμένουν.</p>';
			} elseif ( 'off' === $state['paused'] ) {
				echo '<p class="pnchat-warn">Σταμάτησε: το AI είναι απενεργοποιημένο ή δεν έχει API key.</p>';
			} else {
				echo '<p class="description">Η σελίδα ανανεώνεται μόνη της.</p>';
			}
			if ( $queue ) {
				$next = array_map( array( 'PNChat_Learn', 'job_label' ), array_slice( $queue, 0, 5 ) );
				echo '<p class="description">Επόμενες: ' . esc_html( implode( ' · ', $next ) ) . ( count( $queue ) > 5 ? ' …' : '' ) . '</p>';
			}
			echo '<p><a class="button" href="' . esc_url( self::action_url( 'learn_control', array( 'do' => 'step' ) ) ) . '">Συνέχεια τώρα</a> ';
			echo '<a class="button" href="' . esc_url( self::action_url( 'learn_control', array( 'do' => 'stop' ) ) ) . '" onclick="return confirm(\'Να σταματήσει το διάβασμα; Οι προτάσεις που έγιναν μένουν.\')">Διακοπή</a></p>';
			echo '<p class="description">Αν δεν προχωρά (π.χ. το WP-Cron είναι κλειστό στον server), πατήστε «Συνέχεια τώρα»: ελέγχει αμέσως αν απάντησε το Claude και στέλνει την επόμενη πηγή.</p>';
		}
		if ( $state['errors'] ) {
			echo '<details><summary>Πηγές που δεν διαβάστηκαν (' . count( $state['errors'] ) . ')</summary><ul class="pnchat-list">';
			foreach ( $state['errors'] as $e ) {
				echo '<li><strong>' . esc_html( (string) $e['source'] ) . '</strong>: ' . esc_html( (string) $e['error'] ) . '</li>';
			}
			echo '</ul></details>';
		}
		echo '</div>';
	}

	/**
	 * The proposals waiting for approval.
	 *
	 * @return void
	 */
	private static function proposals_list() {
		$total = PNChat_Learn::count_pending();
		$per   = 20;
		$paged = max( 1, absint( self::get( 'paged' ) ) );
		echo '<h2>Προτάσεις που περιμένουν έγκριση (' . (int) $total . ')</h2>';
		if ( ! $total ) {
			echo '<p>Δεν υπάρχουν προτάσεις. Ζητήστε από το AI να διαβάσει μια σελίδα, μια διεύθυνση ή ένα PDF.</p>';
			return;
		}
		echo '<p class="pnchat-intro">Διαβάστε κάθε πρόταση και διορθώστε ό,τι χρειάζεται. «Έγκριση» = γίνεται γνώση και ο βοηθός την απαντά από τώρα. «Απόρριψη» = δεν θα ξαναπροταθεί.</p>';
		echo '<form id="pnchat-bulk" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pnchat-inline-form">';
		self::form_fields( 'proposals_bulk' );
		echo '<input type="hidden" name="paged" value="' . (int) $paged . '">';
		echo '<label><input type="checkbox" onclick="document.querySelectorAll(\'.pnchat-pick\').forEach(function(c){c.checked=this.checked}.bind(this))"> Όλες σε αυτή τη σελίδα</label> ';
		echo '<button class="button" name="do" value="approve">✔ Έγκριση επιλεγμένων</button> ';
		echo '<button class="button" name="do" value="reject" onclick="return confirm(\'Να απορριφθούν οι επιλεγμένες;\')">✖ Απόρριψη επιλεγμένων</button></form>';

		foreach ( PNChat_Learn::pending( $paged, $per ) as $p ) {
			$id = (int) $p['id'];
			$e  = $p['entry'];
			echo '<div class="pnchat-card pnchat-ai-draft" id="pnchat-p-' . (int) $id . '">';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			self::form_fields( 'proposal' );
			echo '<input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="paged" value="' . (int) $paged . '">';
			echo '<p><label><input type="checkbox" class="pnchat-pick" name="ids[]" value="' . (int) $id . '" form="pnchat-bulk"> </label>';
			echo '<input type="text" name="title" class="large-text" style="max-width:640px;font-weight:600" value="' . esc_attr( (string) ( $e['title'] ?? '' ) ) . '" aria-label="Τίτλος"></p>';
			$from = '' !== (string) $p['source_url']
				? '<a href="' . esc_url( (string) $p['source_url'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) $p['source_title'] ) . '</a>'
				: esc_html( (string) $p['source_title'] );
			echo '<p class="description">Από: ' . $from . ' · ' . esc_html( self::date( (string) $p['created_at'] ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			$notes = PNChat_Store::lines( (string) $p['note'] );
			if ( $notes ) {
				echo '<ul class="pnchat-list pnchat-warn">';
				foreach ( $notes as $n ) {
					echo '<li>' . esc_html( $n ) . '</li>';
				}
				echo '</ul>';
			}
			echo '<div class="pnchat-ai-cols"><div>';
			echo '<p class="description"><label for="pnchat-pq-' . (int) $id . '">Ερωτήσεις (μία ανά γραμμή)</label></p><textarea id="pnchat-pq-' . (int) $id . '" name="phrasings" rows="7" class="large-text">' . esc_textarea( implode( "\n", (array) ( $e['phrasings'] ?? array() ) ) ) . '</textarea>';
			echo '<p class="description"><label for="pnchat-pk-' . (int) $id . '">Λέξεις-κλειδιά (προαιρετικά, μία ανά γραμμή)</label></p><textarea id="pnchat-pk-' . (int) $id . '" name="keywords" rows="2" class="large-text">' . esc_textarea( implode( "\n", (array) ( $e['keywords'] ?? array() ) ) ) . '</textarea>';
			echo '</div><div>';
			echo '<p class="description">Απάντηση όπως θα φαίνεται</p><div class="pnchat-test-item">' . wp_kses( PNChat_Brain::render_answer( (string) ( $e['answer'] ?? '' ) ), PNChat_Brain::allowed_html() ) . '</div>';
			echo '<details><summary>Διόρθωση απάντησης (HTML)</summary><textarea name="answer" rows="8" class="large-text code">' . esc_textarea( (string) ( $e['answer'] ?? '' ) ) . '</textarea></details>';
			echo '</div></div><p>';
			echo '<button class="button button-primary" name="do" value="approve">✔ Έγκριση</button> ';
			$similar = $p['similar_id'] ? PNChat_Store::entry( (int) $p['similar_id'] ) : null;
			if ( $similar ) {
				echo '<button class="button" name="do" value="merge">+ Προσθήκη των ερωτήσεων στη «' . esc_html( (string) $similar['title'] ) . '»</button> ';
			}
			echo '<button class="button button-link-delete" name="do" value="reject">✖ Απόρριψη</button></p>';
			echo '</form></div>';
		}
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<p>';
			for ( $i = 1; $i <= $pages; $i++ ) {
				echo $i === $paged ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( admin_url( 'admin.php?page=pn-chat-learn&paged=' . $i ) ) . '">' . (int) $i . '</a> ';
			}
			echo '</p>';
		}
	}

	/**
	 * «Διάβασε»: a web address or an uploaded PDF goes to the queue.
	 *
	 * @return void
	 */
	public static function handle_learn_read() {
		self::guard( 'pnchat_learn_read' );
		$jobs  = array();
		$label = array();
		// The temporary path comes from PHP; the name is cleaned in store_upload().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checked the nonce.
		$f = isset( $_FILES['pdf'] ) && is_array( $_FILES['pdf'] ) ? $_FILES['pdf'] : null;
		if ( $f && UPLOAD_ERR_NO_FILE !== (int) $f['error'] ) {
			if ( UPLOAD_ERR_OK !== (int) $f['error'] ) {
				$err = in_array( (int) $f['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true )
					? 'Το PDF ξεπερνά το όριο ανεβάσματος του server (' . size_format( wp_max_upload_size() ) . ').'
					: 'Το PDF δεν ανέβηκε (σφάλμα ' . (int) $f['error'] . ').';
				self::back( 'pn-chat-learn', 'ai_error', array( 'err' => $err ) );
			}
			$job = PNChat_Learn::store_upload( (string) $f['tmp_name'], (string) $f['name'] );
			if ( is_wp_error( $job ) ) {
				self::back( 'pn-chat-learn', 'ai_error', array( 'err' => $job->get_error_message() ) );
			}
			$jobs[]  = $job;
			$label[] = $job['name'];
		}
		$url = esc_url_raw( trim( self::post( 'url' ) ), array( 'http', 'https' ) );
		if ( '' !== $url ) {
			$jobs[]  = array(
				'type' => 'url',
				'url'  => $url,
			);
			$label[] = $url;
		}
		if ( ! $jobs ) {
			self::back( 'pn-chat-learn', 'learn_bad' );
		}
		$n = PNChat_Learn::enqueue( $jobs, implode( ' · ', $label ) );
		self::back( 'pn-chat-learn', $n ? 'learn_queued' : 'learn_dupe', array( 'n' => $n ) );
	}

	/**
	 * «Διάβασε όλο το site».
	 *
	 * @return void
	 */
	public static function handle_learn_site() {
		self::guard( 'pnchat_learn_site' );
		$changed = '1' === self::post( 'changed_only' );
		$r       = PNChat_Learn::enqueue_site( $changed, $changed ? 'Σελίδες του site που άλλαξαν' : 'Όλο το site' );
		if ( $r['added'] ) {
			self::back( 'pn-chat-learn', 'learn_queued', array( 'n' => $r['added'] ) );
		}
		self::back( 'pn-chat-learn', 'learn_none', array( 'n' => $r['unchanged'] ) );
	}

	/**
	 * «Συνέχεια τώρα» and «Διακοπή».
	 *
	 * @return void
	 */
	public static function handle_learn_control() {
		self::guard( 'pnchat_learn_control' );
		if ( 'stop' === self::get( 'do' ) ) {
			self::back( 'pn-chat-learn', 'learn_stopped', array( 'n' => PNChat_Learn::stop() ) );
		}
		self::long_request();
		$r = PNChat_Learn::process_next();
		PNChat_Learn::schedule();
		$map = array(
			'done'      => array( 'learn_step', array( 'n' => $r['added'] ) ),
			'submitted' => array( 'learn_sent', array() ),
			'waiting'   => array( 'learn_waiting', array() ),
			'error' => array( 'ai_error', array( 'err' => $r['error'] ) ),
			'busy'  => array( 'learn_busy', array() ),
			'limit' => array( 'learn_limit', array() ),
			'off'   => array( 'ai_error', array( 'err' => 'Το AI είναι απενεργοποιημένο ή δεν έχει API key (Ρυθμίσεις).' ) ),
		);
		$go  = $map[ $r['status'] ] ?? array( '', array() );
		self::back( 'pn-chat-learn', $go[0], $go[1] );
	}

	/**
	 * Approve, merge or reject one proposal.
	 *
	 * @return void
	 */
	public static function handle_proposal() {
		self::guard( 'pnchat_proposal' );
		$id    = absint( self::post( 'id' ) );
		$args  = array( 'paged' => max( 1, absint( self::post( 'paged' ) ) ) );
		$p     = PNChat_Learn::proposal( $id );
		if ( ! $p || 'pending' !== $p['status'] ) {
			self::back( 'pn-chat-learn', 'not_found', $args );
		}
		$phrasings = PNChat_Store::lines( sanitize_textarea_field( self::post( 'phrasings' ) ) );
		$do        = self::post( 'do' );
		if ( 'reject' === $do ) {
			$ok = PNChat_Learn::set_status( $id, 'rejected' );
			self::back( 'pn-chat-learn', $ok ? 'rejected' : 'save_failed', $args );
		}
		if ( 'merge' === $do ) {
			$saved = PNChat_Learn::merge( $id, $phrasings );
			if ( ! $saved ) {
				self::back( 'pn-chat-learn', 'save_failed', $args );
			}
			self::warn_conflicts( $saved, $phrasings );
			self::back( 'pn-chat-learn', 'merged', $args );
		}
		$answer = wp_kses( PNChat_Brain::unescape_pasted_html( self::post( 'answer' ) ), PNChat_Brain::allowed_html() );
		$entry  = array(
			'kind'      => 'answer',
			'title'     => sanitize_text_field( self::post( 'title' ) ),
			'phrasings' => $phrasings,
			'keywords'  => PNChat_Store::lines( sanitize_textarea_field( self::post( 'keywords' ) ) ),
			'answer'    => $answer,
			'active'    => 1,
		);
		if ( ! $phrasings || '' === trim( wp_strip_all_tags( $answer ) ) ) {
			self::back( 'pn-chat-learn', $phrasings ? 'empty_ans' : 'empty_phr', $args );
		}
		$saved = PNChat_Learn::approve( $id, $entry );
		if ( ! $saved ) {
			self::back( 'pn-chat-learn', 'save_failed', $args );
		}
		self::warn_conflicts( $saved, $phrasings );
		self::back( 'pn-chat-learn', 'approved', $args );
	}

	/**
	 * Approve or reject the ticked proposals as they are.
	 *
	 * @return void
	 */
	public static function handle_proposals_bulk() {
		self::guard( 'pnchat_proposals_bulk' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked it.
		$ids    = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array();
		$reject = 'reject' === self::post( 'do' );
		$n      = 0;
		$warn   = array();
		foreach ( array_unique( $ids ) as $id ) {
			if ( $reject ) {
				$p = PNChat_Learn::proposal( $id );
				if ( $p && 'pending' === $p['status'] && PNChat_Learn::set_status( $id, 'rejected' ) ) {
					++$n;
				}
				continue;
			}
			$saved = PNChat_Learn::approve( $id );
			if ( $saved ) {
				++$n;
				$e             = PNChat_Store::entry( $saved );
				$warn[ $saved ] = $e ? (array) $e['phrasings'] : array();
			}
		}
		foreach ( $warn as $id => $phrasings ) {
			self::warn_conflicts( $id, $phrasings );
		}
		self::back( 'pn-chat-learn', $reject ? 'p_rejected' : 'p_approved', array( 'n' => $n ) );
	}

	/* ------------------------------------------------------------------ */
	/* learning from conversations («Μάθηση»)                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Μάθηση: what the conversations taught, what waits for a decision,
	 * what visitors ask and the chat does not know, and the test set.
	 *
	 * @return void
	 */
	public static function page_lessons() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'pn-chat' ), 403 );
		}
		$s    = PNChat_Settings::get();
		$auto = (int) $s['learn_auto'];
		echo '<div class="wrap pnchat-admin"><h1>Μάθηση από τις συζητήσεις</h1>';
		self::notices();
		echo '<p class="pnchat-intro">Ο βοηθός μαθαίνει <strong>πώς ρωτάνε</strong> οι επισκέπτες, χωρίς AI· οι <strong>απαντήσεις</strong> μένουν αυτές που γράψατε εσείς. Όταν δεν είναι σίγουρος δείχνει «Μήπως εννοείτε…;»: το κουμπί που πατά ο επισκέπτης λέει τι εννοούσε. ';
		echo $auto > 0
			? 'Μια διατύπωση που επιβεβαίωσαν <strong>' . (int) $auto . ' διαφορετικοί επισκέπτες</strong> μπαίνει μόνη της στη γνώση, αν δεν είναι ιατρική, δεν είναι κοντά σε απαγόρευση και δεν αλλάζει καμία απάντηση του σετ δοκιμών· την αναιρείτε όποτε θέλετε. Όλα τα άλλα περιμένουν εσάς.'
			: 'Η αυτόματη μάθηση είναι κλειστή (Ρυθμίσεις): όλα περιμένουν εσάς.';
		echo '</p>';

		self::lessons_pending();
		self::lessons_unknown();
		self::lessons_words();
		self::lessons_fixing();
		self::lessons_auto();
		self::lessons_tests();
		echo '</div>';
	}

	/**
	 * Lessons waiting for a decision.
	 *
	 * @return void
	 */
	private static function lessons_pending() {
		$rows = PNChat_Lessons::by_status( 'pending', 100 );
		echo '<div class="pnchat-card"><h2>Περιμένουν έγκριση (' . count( $rows ) . ')</h2>';
		if ( ! $rows ) {
			echo '<p class="description">Τίποτα ακόμα. Εδώ έρχονται διατυπώσεις επισκεπτών που επιβεβαιώθηκαν από λιγότερους επισκέπτες, ή που η αυτόματη μάθηση δεν πέρασε για λόγο ασφαλείας.</p></div>';
			return;
		}
		echo '<table class="widefat striped pnchat-table"><thead><tr><th>Ο επισκέπτης έγραψε</th><th>Εννοούσε</th><th class="num">Επισκέπτες</th><th>Ενέργειες</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$e = PNChat_Store::entry( (int) $r['entry_id'] );
			echo '<tr><td><strong>' . esc_html( (string) $r['phrase'] ) . '</strong>' . ( '' !== (string) $r['note'] ? '<div class="description">' . esc_html( (string) $r['note'] ) . '</div>' : '' ) . '</td>';
			echo '<td>' . ( $e ? '<a href="' . esc_url( admin_url( 'admin.php?page=pn-chat&edit=' . (int) $e['id'] ) ) . '">' . esc_html( (string) $e['title'] ) . '</a>' : '—' ) . '</td>';
			echo '<td class="num">' . (int) $r['seen'] . ( (int) $r['clicks'] ? '<div class="description">' . (int) $r['clicks'] . ' με κλικ</div>' : '' ) . '</td>';
			echo '<td><a class="button button-primary button-small" href="' . esc_url( self::action_url( 'lesson', array( 'id' => (int) $r['id'], 'do' => 'add' ) ) ) . '">✔ Πρόσθεσε</a> ';
			echo '<a class="button button-small" href="' . esc_url( self::action_url( 'lesson', array( 'id' => (int) $r['id'], 'do' => 'reject' ) ) ) . '">✖ Όχι</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * What visitors ask and the chat does not know, in groups.
	 *
	 * @return void
	 */
	private static function lessons_unknown() {
		$groups = PNChat_Lessons::unanswered_groups( 30, 10 );
		echo '<div class="pnchat-card"><h2>Τι ρωτάνε και δεν ξέρει (30 ημέρες)</h2>';
		if ( ! $groups ) {
			echo '<p class="description">Δεν υπάρχουν αναπάντητες ερωτήσεις με κοινά θέματα.</p></div>';
			return;
		}
		echo '<p class="description">Οι αναπάντητες ερωτήσεις, σε ομάδες με κοινή λέξη, οι πιο συχνές πρώτα. «Νέα γνώση» ανοίγει γνώση με αυτές τις ερωτήσεις έτοιμες· γράφετε μόνο την απάντηση.</p>';
		foreach ( $groups as $g ) {
			echo '<details class="pnchat-group"><summary><strong>' . esc_html( $g['word'] ) . '</strong> — ' . (int) $g['count'] . ' ερωτήσεις</summary><ul class="pnchat-list">';
			foreach ( $g['questions'] as $q ) {
				echo '<li>' . esc_html( $q ) . '</li>';
			}
			echo '</ul><p><a class="button button-primary button-small" href="' . esc_url( self::action_url( 'lesson_group', array( 'ids' => implode( ',', $g['ids'] ), 'do' => 'entry' ) ) ) . '">+ Νέα γνώση με αυτές</a> ';
			echo '<a class="button button-small" href="' . esc_url( self::action_url( 'lesson_group', array( 'ids' => implode( ',', $g['ids'] ), 'do' => 'dismiss' ) ) ) . '">Αγνόηση</a></p></details>';
		}
		echo '</div>';
	}

	/**
	 * Unknown words that look like known ones.
	 *
	 * @return void
	 */
	private static function lessons_words() {
		$words = PNChat_Lessons::word_suggestions();
		if ( ! $words ) {
			return;
		}
		echo '<div class="pnchat-card"><h2>Λέξεις που δεν ξέρει</h2><p class="description">Λέξεις από αναπάντητες ερωτήσεις (3+ φορές) που δεν υπάρχουν σε καμία γνώση. Αν σημαίνει κάτι που ο βοηθός ξέρει (π.χ. «χρεώνετε» = «κόστος»), γράψτε τη λέξη και πατήστε «Ίδια λέξη»: μπαίνει στα συνώνυμα.</p>';
		echo '<table class="widefat striped pnchat-table"><thead><tr><th>Έγραψαν</th><th class="num">Φορές</th><th>Π.χ.</th><th>Σημαίνει το ίδιο με</th></tr></thead><tbody>';
		foreach ( $words as $w ) {
			echo '<tr><td><strong>' . esc_html( $w['word'] ) . '</strong></td><td class="num">' . (int) $w['count'] . '</td><td class="description">' . esc_html( $w['example'] ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pnchat-inline-form">';
			self::form_fields( 'lesson_word' );
			echo '<input type="hidden" name="word" value="' . esc_attr( $w['word'] ) . '"><input type="text" name="like" class="small-text" style="width:140px" value="' . esc_attr( $w['like'] ) . '" placeholder="π.χ. κόστος" aria-label="Σημαίνει το ίδιο με"> ';
			echo '<button class="button button-small" name="do" value="add">= Ίδια λέξη</button> <button class="button button-small" name="do" value="ignore">Αγνόηση</button></form></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Entries visitors marked 👎.
	 *
	 * @return void
	 */
	private static function lessons_fixing() {
		$list = PNChat_Lessons::needs_fixing();
		if ( ! $list ) {
			return;
		}
		echo '<div class="pnchat-card"><h2>Χρειάζονται διόρθωση</h2><p class="description">Γνώσεις που οι επισκέπτες σημείωσαν «δεν βοήθησε» 2+ φορές (90 ημέρες). Δείτε τι ρώτησαν: ίσως η απάντηση θέλει συμπλήρωση, ή η ερώτηση ήταν για κάτι άλλο.</p><ul class="pnchat-list">';
		foreach ( $list as $f ) {
			echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=pn-chat&edit=' . (int) $f['entry']['id'] ) ) . '"><strong>' . esc_html( (string) $f['entry']['title'] ) . '</strong></a> — 👎 ' . (int) $f['count'] . ': «' . esc_html( implode( '», «', $f['questions'] ) ) . '»</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Wordings learned on their own, with undo.
	 *
	 * @return void
	 */
	private static function lessons_auto() {
		$rows = array_merge( PNChat_Lessons::by_status( 'auto', 50 ), PNChat_Lessons::by_status( 'added', 50 ) );
		if ( ! $rows ) {
			return;
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( (string) $b['updated_at'], (string) $a['updated_at'] );
			}
		);
		echo '<div class="pnchat-card"><h2>Έμαθε</h2><table class="widefat striped pnchat-table"><thead><tr><th>Διατύπωση</th><th>Στη γνώση</th><th>Πώς</th><th>Πότε</th><th></th></tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, 50 ) as $r ) {
			$e = PNChat_Store::entry( (int) $r['entry_id'] );
			echo '<tr><td>' . esc_html( (string) $r['phrase'] ) . '</td><td>' . esc_html( $e ? (string) $e['title'] : '—' ) . '</td>';
			echo '<td>' . ( 'auto' === $r['status'] ? 'μόνο του (' . (int) $r['clicks'] . ' επισκέπτες)' : 'με έγκριση' ) . '</td><td>' . esc_html( self::date( (string) $r['updated_at'] ) ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( self::action_url( 'lesson', array( 'id' => (int) $r['id'], 'do' => 'undo' ) ) ) . '" onclick="return confirm(\'Να αφαιρεθεί η διατύπωση από τη γνώση;\')">Αναίρεση</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * The test set: questions with the entry that must answer them.
	 *
	 * @return void
	 */
	private static function lessons_tests() {
		$tests = PNChat_Lessons::tests();
		$last  = get_transient( 'pnchat_tests_result' );
		echo '<div class="pnchat-card" id="pnchat-tests"><h2>Σετ δοκιμών (' . count( $tests ) . ')</h2>';
		echo '<p class="description">Ερωτήσεις με τη γνώση που πρέπει να τις απαντά. Γεμίζει μόνο του από ό,τι μαθαίνει ο βοηθός· προσθέστε κι εσείς πραγματικές ερωτήσεις. Πριν μπει μόνη της μια διατύπωση, ελέγχεται ότι δεν αλλάζει καμία από αυτές τις απαντήσεις. Τρέξτε το μετά από αλλαγές σε συνώνυμα, αυστηρότητα ή γνώσεις.</p>';
		if ( $tests ) {
			echo '<p><a class="button button-primary" href="' . esc_url( self::action_url( 'tests_run', array() ) ) . '">▶ Τρέξε τις δοκιμές</a></p>';
		}
		if ( is_array( $last ) && $last['total'] ) {
			$ok = (int) $last['total'] - count( $last['fail'] );
			echo '<p><strong>Τελευταία εκτέλεση (' . esc_html( (string) $last['at'] ) . '): ' . (int) $ok . ' / ' . (int) $last['total'] . ' σωστές (' . (int) round( 100 * $ok / $last['total'] ) . '%)</strong></p>';
			if ( $last['fail'] ) {
				echo '<table class="widefat striped pnchat-table"><thead><tr><th>Ερώτηση</th><th>Έπρεπε</th><th>Απάντησε</th><th></th></tr></thead><tbody>';
				foreach ( $last['fail'] as $i => $f ) {
					echo '<tr><td>' . esc_html( $f['test']['q'] . ( '' !== $f['test']['ctx'] ? ' (' . $f['test']['ctx'] . ')' : '' ) ) . '</td><td>' . esc_html( (string) $f['test']['title'] ) . '</td><td>' . esc_html( $f['got'] ) . '</td>';
					echo '<td><a href="' . esc_url( self::action_url( 'test_remove', array( 'i' => (int) $i ) ) ) . '">Αφαίρεση δοκιμής</a></td></tr>';
				}
				echo '</tbody></table>';
			}
		}
		$entries = PNChat_Store::entries( 'answer', true );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pnchat-inline-form" style="margin-top:12px">';
		self::form_fields( 'test_add' );
		echo '<input type="text" name="q" class="regular-text" required placeholder="Ερώτηση επισκέπτη" aria-label="Ερώτηση"> ';
		echo '<select name="entry" required aria-label="Σωστή γνώση"><option value="">— σωστή γνώση —</option>';
		foreach ( $entries as $e ) {
			echo '<option value="' . (int) $e['id'] . '">' . esc_html( (string) $e['title'] ) . '</option>';
		}
		echo '</select> <input type="text" name="ctx" class="small-text" style="width:140px" placeholder="θέμα (προαιρετικό)" aria-label="Θέμα συζήτησης"> <button class="button">+ Δοκιμή</button></form></div>';
	}

	/**
	 * Add, reject or undo a lesson.
	 *
	 * @return void
	 */
	public static function handle_lesson() {
		self::guard( 'pnchat_lesson' );
		$do = self::get( 'do' );
		$ok = PNChat_Lessons::decide( absint( self::get( 'id' ) ), $do );
		$map = array(
			'add'    => 'lesson_added',
			'reject' => 'lesson_rejected',
			'undo'   => 'lesson_undone',
		);
		self::back( 'pn-chat-lessons', $ok ? ( $map[ $do ] ?? 'not_found' ) : 'save_failed' );
	}

	/**
	 * A group of unanswered questions: new entry with them, or dismiss.
	 *
	 * @return void
	 */
	public static function handle_lesson_group() {
		self::guard( 'pnchat_lesson_group' );
		$ids = array_filter( array_map( 'absint', explode( ',', self::get( 'ids' ) ) ) );
		$qs  = array();
		foreach ( $ids as $id ) {
			$q = PNChat_Store::question( $id );
			if ( $q ) {
				$parts = PNChat_Store::lines( (string) $q['unmatched'] );
				$qs[]  = $parts ? $parts[0] : (string) $q['question'];
			}
		}
		if ( ! $qs ) {
			self::back( 'pn-chat-lessons', 'not_found' );
		}
		if ( 'dismiss' === self::get( 'do' ) ) {
			foreach ( $ids as $id ) {
				PNChat_Store::update_question( $id, array( 'status' => 'dismissed' ) );
			}
			self::back( 'pn-chat-lessons', 'q_dismissed', array( 'n' => count( $ids ) ) );
		}
		$uid = get_current_user_id();
		set_transient(
			'pnchat_form_' . $uid,
			array(
				'kind'      => 'answer',
				'id'        => 0,
				'title'     => '',
				'phrasings' => array_values( array_unique( $qs ) ),
				'keywords'  => array(),
				'answer'    => '',
				'active'    => 1,
			),
			HOUR_IN_SECONDS
		);
		// Marked trained once the entry is saved.
		set_transient( 'pnchat_group_' . $uid, $ids, HOUR_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=pn-chat&new=1' ) );
		exit;
	}

	/**
	 * A word suggestion: make it a synonym, or never suggest it again.
	 *
	 * @return void
	 */
	public static function handle_lesson_word() {
		self::guard( 'pnchat_lesson_word' );
		$word = sanitize_text_field( self::post( 'word' ) );
		if ( 'ignore' === self::post( 'do' ) ) {
			$ignored   = (array) get_option( 'pnchat_ignored_words', array() );
			$ignored[] = PNChat_Text::fold( $word );
			update_option( 'pnchat_ignored_words', array_slice( array_values( array_unique( $ignored ) ), -500 ), false );
			self::back( 'pn-chat-lessons', 'dismissed' );
		}
		$ok = PNChat_Lessons::add_synonym( $word, sanitize_text_field( self::post( 'like' ) ) );
		self::back( 'pn-chat-lessons', $ok ? 'synonyms' : 'empty' );
	}

	/**
	 * Runs the test set.
	 *
	 * @return void
	 */
	public static function handle_tests_run() {
		self::guard( 'pnchat_tests_run' );
		$r = PNChat_Lessons::run_tests();
		set_transient(
			'pnchat_tests_result',
			array(
				'total' => $r['total'],
				'fail'  => $r['fail'],
				'at'    => wp_date( 'd/m/Y H:i' ),
			),
			WEEK_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=pn-chat-lessons#pnchat-tests' ) );
		exit;
	}

	/**
	 * Adds a test.
	 *
	 * @return void
	 */
	public static function handle_test_add() {
		self::guard( 'pnchat_test_add' );
		$ok = PNChat_Lessons::add_test( self::post( 'q' ), PNChat_Topics::valid( sanitize_text_field( self::post( 'ctx' ) ) ), absint( self::post( 'entry' ) ), 'χειροκίνητα' );
		self::back( 'pn-chat-lessons', $ok ? 'test_added' : 'empty' );
	}

	/**
	 * Removes a test.
	 *
	 * @return void
	 */
	public static function handle_test_remove() {
		self::guard( 'pnchat_test_remove' );
		PNChat_Lessons::remove_test( absint( self::get( 'i' ) ) );
		delete_transient( 'pnchat_tests_result' );
		self::back( 'pn-chat-lessons', 'deleted' );
	}

	/**
	 * Synonyms editor.
	 *
	 * @return void
	 */
	private static function synonyms_box() {
		echo '<div class="pnchat-card"><h2>Συνώνυμα</h2>';
		echo '<p class="description">Λέξεις που σημαίνουν το ίδιο, μία ομάδα ανά γραμμή, χωρισμένες με κόμμα. π.χ. <code>κοστίζει, τιμή, κόστος, χρέωση, πόσο κάνει</code>. Ισχύουν για όλες τις γνώσεις και τις απαγορεύσεις.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'save_synonyms' );
		echo '<textarea name="synonyms" rows="6" class="large-text code">' . esc_textarea( (string) PNChat_Settings::value( 'synonyms' ) ) . '</textarea>';
		echo '<h2>Θέματα συζήτησης</h2>';
		echo '<p class="description">Ένα θέμα ανά γραμμή: πρώτα το όνομα, μετά άλλες λέξεις που το δηλώνουν, με κόμμα. π.χ. <code>QR ReBuilder, rebuilder, datamatrix</code>. Όταν ο επισκέπτης ρωτά κάτι χωρίς θέμα («Είναι δωρεάν;»), ο βοηθός το καταλαβαίνει για το θέμα της προηγούμενης απάντησης («…το QR ReBuilder;»). Αν ρωτήσει για άλλο θέμα, αλλάζει θέμα.</p>';
		echo '<textarea name="topics" rows="6" class="large-text code">' . esc_textarea( (string) PNChat_Settings::value( 'topics' ) ) . '</textarea>';
		submit_button( 'Αποθήκευση συνωνύμων και θεμάτων', 'secondary' );
		echo '</form></div>';
	}

	/**
	 * Saves an entry.
	 *
	 * @return void
	 */
	public static function handle_save_entry() {
		self::guard( 'pnchat_save_entry' );
		$kind = 'block' === self::post( 'kind' ) ? 'block' : 'answer';
		$page = 'block' === $kind ? 'pn-chat-blocks' : 'pn-chat';
		$id   = absint( self::post( 'id' ) );
		$from = absint( self::post( 'from_question' ) );

		$phrasings = PNChat_Store::lines( sanitize_textarea_field( self::post( 'phrasings' ) ) );
		$keywords  = PNChat_Store::lines( sanitize_textarea_field( self::post( 'keywords' ) ) );
		$answer    = 'block' === $kind ? sanitize_textarea_field( self::post( 'answer' ) ) : wp_kses( PNChat_Brain::unescape_pasted_html( self::post( 'answer' ) ), PNChat_Brain::allowed_html() );
		if ( 'block' === $kind && '' === trim( $answer ) ) {
			$answer = self::DEFAULT_BLOCK;
		}
		$title = sanitize_text_field( self::post( 'title' ) );
		if ( '' === $title ) {
			$title = $phrasings ? $phrasings[0] : ( $keywords ? $keywords[0] : '' );
		}
		$no_q = ! $phrasings && ! $keywords;
		$no_a = '' === trim( wp_strip_all_tags( $answer ) );
		if ( $no_q || $no_a ) {
			// Keep what was typed, and the question it was trained from.
			set_transient(
				'pnchat_form_' . get_current_user_id(),
				array(
					'kind'      => $kind,
					'id'        => $id,
					'title'     => sanitize_text_field( self::post( 'title' ) ),
					'phrasings' => $phrasings,
					'keywords'  => $keywords,
					'answer'    => $answer,
					'active'    => '1' === self::post( 'active' ) ? 1 : 0,
				),
				10 * MINUTE_IN_SECONDS
			);
			$args = $id ? array( 'edit' => $id ) : array( 'new' => 1 );
			if ( $from ) {
				$args['from_question'] = $from;
			}
			self::back( $page, $no_q && $no_a ? 'empty_both' : ( $no_q ? 'empty_phr' : 'empty_ans' ), $args );
		}
		if ( $id ) {
			$old = PNChat_Store::entry( $id );
			if ( ! $old || $old['kind'] !== $kind ) {
				self::back( $page, 'not_found' );
			}
		}

		$saved = PNChat_Store::save_entry(
			array(
				'kind'      => $kind,
				'title'     => $title,
				'phrasings' => $phrasings,
				'keywords'  => $keywords,
				'answer'    => $answer,
				'active'    => '1' === self::post( 'active' ),
			),
			$id
		);

		if ( ! $saved ) {
			// Nothing was stored: keep what was typed and say so.
			set_transient(
				'pnchat_form_' . get_current_user_id(),
				array(
					'kind'      => $kind,
					'id'        => $id,
					'title'     => sanitize_text_field( self::post( 'title' ) ),
					'phrasings' => $phrasings,
					'keywords'  => $keywords,
					'answer'    => $answer,
					'active'    => '1' === self::post( 'active' ) ? 1 : 0,
				),
				10 * MINUTE_IN_SECONDS
			);
			$args = $id ? array( 'edit' => $id ) : array( 'new' => 1 );
			if ( $from ) {
				$args['from_question'] = $from;
			}
			self::back( $page, 'save_failed', $args );
		}

		self::warn_conflicts( $saved, $phrasings );

		// A new entry made from a group of «Τι ρωτάνε και δεν ξέρει».
		$group = $id ? false : get_transient( 'pnchat_group_' . get_current_user_id() );
		if ( is_array( $group ) && 'answer' === $kind ) {
			delete_transient( 'pnchat_group_' . get_current_user_id() );
			foreach ( $group as $gid ) {
				PNChat_Store::update_question( (int) $gid, array( 'status' => 'trained' ) );
			}
		}

		if ( $from ) {
			$q = PNChat_Store::question( $from );
			if ( $q ) {
				PNChat_Store::update_question( $from, array( 'status' => 'block' === $kind ? 'blocked' : 'trained' ) );
				if ( 'answer' === $kind && is_email( (string) $q['email'] ) && empty( $q['replied_at'] ) ) {
					wp_safe_redirect( admin_url( 'admin.php?page=pn-chat-questions&reply=' . $from . '&entry=' . $saved . '&pnchat_msg=saved' ) );
					exit;
				}
			}
			self::back( 'pn-chat-questions', 'block' === $kind ? 'block_saved' : 'saved' );
		}
		self::back( $page, 'block' === $kind ? 'block_saved' : 'saved' );
	}

	/**
	 * Tells the trainer when a phrasing of the saved entry is answered by
	 * another entry (two entries compete for the same question).
	 *
	 * @param int      $id        Saved entry id.
	 * @param string[] $phrasings Its phrasings.
	 * @return void
	 */
	private static function warn_conflicts( $id, array $phrasings ) {
		if ( ! $id ) {
			return;
		}
		$m    = PNChat_Brain::matcher( true );
		$warn = array();
		foreach ( $phrasings as $p ) {
			$r = $m->rank( $p );
			if ( $r && (int) $r[0]['id'] !== (int) $id ) {
				$warn[] = sprintf( 'Η ερώτηση «%1$s» ταιριάζει περισσότερο με «%2$s». Αλλάξτε τη διατύπωση ή ενώστε τις δύο γνώσεις.', $p, $r[0]['title'] );
			}
		}
		if ( $warn ) {
			set_transient( 'pnchat_warn_' . get_current_user_id(), array_slice( $warn, 0, 10 ), 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Deletes an entry.
	 *
	 * @return void
	 */
	public static function handle_delete_entry() {
		self::guard( 'pnchat_delete_entry' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guard() checked it.
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$e  = PNChat_Store::entry( $id );
		if ( ! $e ) {
			self::back( 'pn-chat', 'not_found' );
		}
		$ok = PNChat_Store::delete_entry( $id );
		self::back( 'block' === $e['kind'] ? 'pn-chat-blocks' : 'pn-chat', $ok ? 'deleted' : 'save_failed' );
	}

	/**
	 * Turns an entry on or off.
	 *
	 * @return void
	 */
	public static function handle_toggle_entry() {
		self::guard( 'pnchat_toggle_entry' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guard() checked it.
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$e  = PNChat_Store::entry( $id );
		if ( ! $e ) {
			self::back( 'pn-chat', 'not_found' );
		}
		$e['active'] = $e['active'] ? 0 : 1;
		$ok          = PNChat_Store::save_entry( $e, $id );
		self::back( 'block' === $e['kind'] ? 'pn-chat-blocks' : 'pn-chat', $ok ? 'toggled' : 'save_failed' );
	}

	/**
	 * Saves the synonyms.
	 *
	 * @return void
	 */
	public static function handle_save_synonyms() {
		self::guard( 'pnchat_save_synonyms' );
		$s             = PNChat_Settings::get();
		$s['synonyms'] = self::post( 'synonyms' );
		$s['topics']   = self::post( 'topics' );
		$ok = PNChat_Settings::save( PNChat_Settings::sanitize( $s ) );
		PNChat_Store::bump();
		self::back( 'pn-chat', $ok ? 'synonyms' : 'save_failed' );
	}

	/* ------------------------------------------------------------------ */
	/* questions                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Ερωτήματα.
	 *
	 * @return void
	 */
	public static function page_questions() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$reply = absint( self::get( 'reply' ) );
		echo '<div class="wrap pnchat-admin">';
		if ( $reply ) {
			self::reply_screen( $reply, absint( self::get( 'entry' ) ) );
			echo '</div>';
			return;
		}

		echo '<h1>Ερωτήματα επισκεπτών</h1>';
		self::notices();
		echo '<p class="pnchat-intro">Στο <strong>💡 Μάλλον εννοούσαν</strong> είναι ερωτήσεις που ο βοηθός δεν κατάλαβε, αλλά ο επισκέπτης τις ξαναρώτησε με άλλα λόγια και πήρε απάντηση: με ένα κλικ ο βοηθός μαθαίνει και την πρώτη διατύπωση.</p>';
		echo '<p class="pnchat-intro">Όλες οι ερωτήσεις που έγιναν στο chat. Οι <strong>ανοιχτές</strong> (χωρίς απάντηση, με μερική απάντηση ή «δεν βοήθησε») περιμένουν εσάς: <strong>Εκπαίδευση</strong> για να μάθει ο βοηθός την απάντηση, <strong>Απάντηση με e-mail</strong> αν ο επισκέπτης άφησε e-mail, <strong>Απαγόρευση</strong> για ερωτήσεις που δεν πρέπει να απαντώνται. Στις <strong>Απαντήσεις AI</strong> είναι όσα απάντησε το AI στο chat: με «Έλεγχος και έγκριση» γίνονται γνώσεις.</p>';

		$filter = self::get( 'filter' );
		$filter = '' === $filter ? 'open' : $filter;
		$search = self::get( 's' );
		$paged  = max( 1, absint( self::get( 'paged' ) ) );
		$counts = PNChat_Store::question_counts();
		$tabs   = array(
			'open'       => 'Ανοιχτές',
			'hint'       => '💡 Μάλλον εννοούσαν',
			'email'      => 'Περιμένουν e-mail',
			'ai'         => 'Απαντήσεις AI',
			'answered'   => 'Απαντήθηκαν',
			'site'       => 'Από το site',
			'blocked'    => 'Απαγορευμένες',
			'trained'    => 'Εκπαιδεύτηκαν',
			'replied'    => 'Στάλθηκε e-mail',
			'dismissed'  => 'Αγνοήθηκαν',
			'all'        => 'Όλες',
		);
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $tabs as $k => $label ) {
			$url     = admin_url( 'admin.php?page=pn-chat-questions&filter=' . $k );
			$links[] = '<li><a href="' . esc_url( $url ) . '"' . ( $k === $filter ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . (int) ( $counts[ $k ] ?? 0 ) . ')</span></a>';
		}
		echo implode( ' |</li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.

		echo '<form method="get" class="pnchat-search"><input type="hidden" name="page" value="pn-chat-questions"><input type="hidden" name="filter" value="' . esc_attr( $filter ) . '">';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Αναζήτηση ερώτησης ή e-mail…"> <button class="button">Αναζήτηση</button></form>';

		$per  = 30;
		$data = PNChat_Store::questions( $filter, $search, $paged, $per );
		$st   = PNChat_Store::statuses();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'bulk_questions' );
		echo '<input type="hidden" name="filter" value="' . esc_attr( $filter ) . '">';
		echo '<div class="tablenav top"><select name="bulk"><option value="">Μαζικές ενέργειες</option><option value="dismiss">Αγνόηση</option><option value="delete">Διαγραφή</option></select> <button class="button" onclick="return this.form.bulk.value!==\'delete\'||confirm(\'Διαγραφή των επιλεγμένων;\');">Εφαρμογή</button></div>';
		echo '<table class="widefat striped pnchat-table pnchat-questions"><thead><tr><td class="check-column"><input type="checkbox" onclick="var c=this.checked;this.closest(\'table\').querySelectorAll(\'tbody input[type=checkbox]\').forEach(function(x){x.checked=c;});" aria-label="Επιλογή όλων"></td><th>Ερώτηση</th><th>Κατάσταση</th><th>E-mail</th><th>Ημερομηνία</th><th>Ενέργειες</th></tr></thead><tbody>';
		if ( ! $data['rows'] ) {
			echo '<tr><td colspan="6">Δεν υπάρχουν ερωτήσεις εδώ.</td></tr>';
		}
		foreach ( $data['rows'] as $q ) {
			$id   = (int) $q['id'];
			$open = in_array( $q['status'], PNChat_Store::open_statuses(), true );
			echo '<tr id="q-' . (int) $id . '">';
			echo '<th class="check-column"><input type="checkbox" name="ids[]" value="' . (int) $id . '" aria-label="Επιλογή"></th>';
			echo '<td><div class="pnchat-question">' . esc_html( (string) $q['question'] ) . '</div>';
			if ( '' !== (string) $q['unmatched'] && 'partial' === $q['status'] ) {
				echo '<div class="description">Χωρίς απάντηση: «' . esc_html( implode( '», «', PNChat_Store::lines( (string) $q['unmatched'] ) ) ) . '»</div>';
			}
			$hint = $open && ! empty( $q['hint_entry'] ) ? PNChat_Store::entry( (int) $q['hint_entry'] ) : null;
			if ( $hint ) {
				// The visitor asked again in other words and got this entry.
				echo '<div class="pnchat-hint">💡 Μάλλον εννοούσε: <strong>' . esc_html( (string) $hint['title'] ) . '</strong> (το ξαναρώτησε αλλιώς και πήρε αυτή την απάντηση). ';
				echo '<a class="button button-small" href="' . esc_url( self::action_url( 'question', array( 'id' => $id, 'do' => 'hint', 'filter' => $filter ) ) ) . '">Πρόσθεσε την ερώτηση εκεί</a></div>';
			}
			$qdraft = PNChat_Store::draft_of( $q );
			if ( $qdraft ) {
				echo '<details' . ( 'ai' === $q['status'] ? ' open' : '' ) . '><summary>Η απάντηση του AI στο chat</summary><div class="pnchat-test-item is-site">' . wp_kses( PNChat_Brain::render_answer( (string) $qdraft['entry']['answer'] ), PNChat_Brain::allowed_html() ) . '</div></details>';
			}
			if ( '' !== (string) $q['reply'] ) {
				echo '<details><summary>Η απάντηση που στάλθηκε</summary><div class="pnchat-reply-text">' . nl2br( esc_html( (string) $q['reply'] ) ) . '</div></details>';
			}
			echo '</td>';
			echo '<td><span class="pnchat-pill pnchat-pill--' . esc_attr( (string) $q['status'] ) . '">' . esc_html( $st[ $q['status'] ] ?? (string) $q['status'] ) . '</span></td>';
			echo '<td>' . ( '' !== (string) $q['email'] ? '<a href="mailto:' . esc_attr( (string) $q['email'] ) . '">' . esc_html( (string) $q['email'] ) . '</a>' . ( '' !== (string) $q['name'] ? '<div class="description">' . esc_html( (string) $q['name'] ) . '</div>' : '' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( self::date( (string) $q['created_at'] ) ) . ( $q['replied_at'] ? '<div class="description">απ. ' . esc_html( self::date( (string) $q['replied_at'] ) ) . '</div>' : '' ) . '</td>';
			echo '<td class="pnchat-actions">';
			$acts = array();
			if ( 'blocked' !== $q['status'] ) {
				$acts[] = '<a href="' . esc_url( admin_url( 'admin.php?page=pn-chat&new=1&from_question=' . $id ) ) . '"><strong>' . ( 'ai' === $q['status'] ? '✔ Έλεγχος και έγκριση ως γνώση' : 'Εκπαίδευση' ) . '</strong></a>';
			}
			if ( 'ai' === $q['status'] ) {
				$acts[] = '<a href="' . esc_url( self::action_url( 'question', array( 'id' => $id, 'do' => 'dismiss', 'filter' => $filter ) ) ) . '">Απόρριψη</a>';
			}
			if ( '' !== (string) $q['email'] ) {
				$acts[] = '<a href="' . esc_url( admin_url( 'admin.php?page=pn-chat-questions&reply=' . $id ) ) . '">' . ( $q['replied_at'] ? 'Νέο e-mail' : 'Απάντηση με e-mail' ) . '</a>';
			}
			if ( 'blocked' !== $q['status'] ) {
				$acts[] = '<a href="' . esc_url( admin_url( 'admin.php?page=pn-chat-blocks&new=1&from_question=' . $id ) ) . '">Απαγόρευση</a>';
			}
			if ( $open ) {
				$acts[] = '<a href="' . esc_url( self::action_url( 'question', array( 'id' => $id, 'do' => 'dismiss', 'filter' => $filter ) ) ) . '">Αγνόηση</a>';
			}
			$acts[] = '<a class="pnchat-danger" href="' . esc_url( self::action_url( 'question', array( 'id' => $id, 'do' => 'delete', 'filter' => $filter ) ) ) . '" onclick="return confirm(\'Διαγραφή;\');">Διαγραφή</a>';
			echo implode( ' | ', $acts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			echo '</td></tr>';
		}
		echo '</tbody></table></form>';

		$pages = (int) ceil( $data['total'] / $per );
		if ( $pages > 1 ) {
			echo '<div class="tablenav bottom"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
		echo '</div>';
	}

	/**
	 * Compose an e-mail reply.
	 *
	 * @param int $id       Question id.
	 * @param int $entry_id Entry whose answer prefills the reply.
	 * @return void
	 */
	private static function reply_screen( $id, $entry_id ) {
		$q = PNChat_Store::question( $id );
		echo '<h1>Απάντηση με e-mail</h1>';
		self::notices();
		if ( ! $q || ! is_email( (string) $q['email'] ) ) {
			echo '<div class="notice notice-error"><p>Η ερώτηση δεν βρέθηκε ή δεν έχει e-mail.</p></div>';
			return;
		}
		$s    = PNChat_Settings::get();
		$body = '';
		$e    = $entry_id ? PNChat_Store::entry( $entry_id ) : null;
		if ( ! $e ) {
			// Best trained answer, if the brain knows it by now.
			$r = PNChat_Brain::matcher()->ask( (string) $q['question'] );
			foreach ( $r['items'] as $i ) {
				if ( 'answer' === $i['kind'] ) {
					$body .= PNChat_Brain::plain_answer( $i['answer'] ) . "\n\n";
				}
			}
		} else {
			$body = PNChat_Brain::plain_answer( $e['answer'] ) . "\n\n";
		}
		$greeting = 'Καλησπέρα' . ( '' !== (string) $q['name'] ? ' ' . $q['name'] : '' ) . ",\n\nσας ευχαριστούμε για την ερώτησή σας:\n«" . $q['question'] . "»\n\n";
		$text     = $greeting . $body . "Με εκτίμηση,\n" . get_bloginfo( 'name' );

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=pn-chat-questions' ) ) . '">← Ερωτήματα</a></p>';
		echo '<div class="pnchat-card"><p><strong>Ερώτηση:</strong> ' . esc_html( (string) $q['question'] ) . '</p><p><strong>Προς:</strong> ' . esc_html( (string) $q['email'] ) . ( '' !== (string) $q['name'] ? ' (' . esc_html( (string) $q['name'] ) . ')' : '' ) . '</p>';
		if ( $q['replied_at'] ) {
			echo '<p class="description">Στάλθηκε ήδη απάντηση στις ' . esc_html( self::date( (string) $q['replied_at'] ) ) . '.</p>';
		}
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pnchat-form">';
		self::form_fields( 'send_reply' );
		echo '<input type="hidden" name="id" value="' . (int) $id . '">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="pnchat-subject">Θέμα</label></th><td><input id="pnchat-subject" name="subject" type="text" class="large-text" value="' . esc_attr( (string) $s['reply_subject'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="pnchat-body">Κείμενο</label></th><td><textarea id="pnchat-body" name="body" rows="14" class="large-text">' . esc_textarea( $text ) . '</textarea></td></tr>';
		if ( ! $e && 'trained' !== $q['status'] ) {
			echo '<tr><th scope="row">Εκπαίδευση</th><td><label><input type="checkbox" name="train" value="1"> Μάθε στον βοηθό αυτή την απάντηση (νέα γνώση με την ερώτηση)</label>';
			echo '<p><label for="pnchat-train-answer">Απάντηση για τον βοηθό (χωρίς χαιρετισμούς):</label><textarea id="pnchat-train-answer" name="train_answer" rows="4" class="large-text">' . esc_textarea( trim( $body ) ) . '</textarea></p></td></tr>';
		}
		echo '</table>';
		submit_button( 'Αποστολή e-mail' );
		echo '</form>';
	}

	/**
	 * Sends the e-mail reply.
	 *
	 * @return void
	 */
	public static function handle_send_reply() {
		self::guard( 'pnchat_send_reply' );
		$id = absint( self::post( 'id' ) );
		$q  = PNChat_Store::question( $id );
		if ( ! $q ) {
			self::back( 'pn-chat-questions', 'not_found' );
		}
		if ( ! is_email( (string) $q['email'] ) ) {
			self::back( 'pn-chat-questions', 'bad_email' );
		}
		$subject = sanitize_text_field( self::post( 'subject' ) );
		$body    = sanitize_textarea_field( self::post( 'body' ) );
		if ( '' === trim( $body ) ) {
			self::back( 'pn-chat-questions', 'empty_reply', array( 'reply' => $id ) );
		}

		if ( '1' === self::post( 'train' ) ) {
			$answer = sanitize_textarea_field( self::post( 'train_answer' ) );
			if ( '' !== trim( $answer ) ) {
				$parts = PNChat_Store::lines( (string) $q['unmatched'] );
				$saved = PNChat_Store::save_entry(
					array(
						'kind'      => 'answer',
						'title'     => $parts ? $parts[0] : (string) $q['question'],
						'phrasings' => $parts ? $parts : array( (string) $q['question'] ),
						'keywords'  => array(),
						'answer'    => $answer,
						'active'    => 1,
					)
				);
				self::warn_conflicts( $saved, $parts ? $parts : array( (string) $q['question'] ) );
			}
		}

		$sent = PNChat_Mail::send_reply( (string) $q['email'], '' !== $subject ? $subject : (string) PNChat_Settings::value( 'reply_subject' ), $body );
		if ( ! $sent ) {
			self::back( 'pn-chat-questions', 'mail_failed', array( 'reply' => $id ) );
		}
		$ok = PNChat_Store::update_question(
			$id,
			array(
				'status'     => 'replied',
				'reply'      => $body,
				'replied_at' => current_time( 'mysql', true ),
			)
		);
		self::back( 'pn-chat-questions', $ok ? 'replied' : 'replied_unsaved' );
	}

	/**
	 * One-question actions: dismiss, delete, add as phrasing.
	 *
	 * @return void
	 */
	public static function handle_question() {
		self::guard( 'pnchat_question' );
		// phpcs:disable WordPress.Security.NonceVerification -- guard() checked it.
		$id     = absint( $_REQUEST['id'] ?? 0 );
		$do     = sanitize_key( wp_unslash( (string) ( $_REQUEST['do'] ?? '' ) ) );
		$filter = sanitize_key( wp_unslash( (string) ( $_REQUEST['filter'] ?? 'open' ) ) );
		// phpcs:enable
		$q = PNChat_Store::question( $id );
		if ( ! $q ) {
			self::back( 'pn-chat-questions', 'not_found' );
		}
		if ( 'dismiss' === $do ) {
			$ok = PNChat_Store::update_question( $id, array( 'status' => 'dismissed' ) );
			self::back( 'pn-chat-questions', $ok ? 'dismissed' : 'save_failed', array( 'filter' => $filter ) );
		}
		if ( 'delete' === $do ) {
			$ok = PNChat_Store::delete_questions( array( $id ) );
			self::back( 'pn-chat-questions', $ok ? 'q_deleted' : 'save_failed', array( 'n' => 1, 'filter' => $filter ) );
		}
		if ( 'add_to' === $do || 'hint' === $do ) {
			$entry_id = 'hint' === $do ? (int) $q['hint_entry'] : absint( self::post( 'entry_id' ) );
			$parts    = PNChat_Store::lines( (string) $q['unmatched'] );
			$ok       = true;
			foreach ( $parts ? $parts : array( (string) $q['question'] ) as $p ) {
				$ok = PNChat_Store::add_phrasing( $entry_id, $p ) && $ok;
			}
			if ( ! $ok ) {
				self::back( 'pn-chat-questions', PNChat_Store::entry( $entry_id ) ? 'save_failed' : 'not_found' );
			}
			PNChat_Store::update_question( $id, array( 'status' => 'trained' ) );
			if ( is_email( (string) $q['email'] ) && empty( $q['replied_at'] ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=pn-chat-questions&reply=' . $id . '&entry=' . $entry_id . '&pnchat_msg=phrasing' ) );
				exit;
			}
			self::back( 'pn-chat-questions', 'phrasing' );
		}
		self::back( 'pn-chat-questions', 'not_found' );
	}

	/**
	 * Bulk dismiss / delete.
	 *
	 * @return void
	 */
	public static function handle_bulk_questions() {
		self::guard( 'pnchat_bulk_questions' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked it.
		$ids    = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array();
		$bulk   = self::post( 'bulk' );
		$filter = sanitize_key( self::post( 'filter' ) );
		if ( 'delete' === $bulk ) {
			$ok = PNChat_Store::delete_questions( $ids );
			self::back( 'pn-chat-questions', $ok ? 'q_deleted' : 'save_failed', array( 'n' => count( $ids ), 'filter' => $filter ) );
		}
		if ( 'dismiss' === $bulk ) {
			$n = 0;
			foreach ( $ids as $id ) {
				if ( PNChat_Store::update_question( $id, array( 'status' => 'dismissed' ) ) ) {
					++$n;
				}
			}
			self::back( 'pn-chat-questions', $n === count( $ids ) ? 'q_dismissed' : 'save_failed', array( 'n' => $n, 'filter' => $filter ) );
		}
		self::back( 'pn-chat-questions', '', array( 'filter' => $filter ) );
	}

	/* ------------------------------------------------------------------ */
	/* brain                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Εγκέφαλος.
	 *
	 * @return void
	 */
	public static function page_brain() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$answers = count( PNChat_Store::entries( 'answer' ) );
		$blocks  = count( PNChat_Store::entries( 'block' ) );
		echo '<div class="wrap pnchat-admin"><h1>Εγκέφαλος του βοηθού</h1>';
		self::notices();
		echo '<p class="pnchat-intro">Ο «εγκέφαλος» είναι ό,τι έχει μάθει ο βοηθός: <strong>' . (int) $answers . '</strong> γνώσεις, <strong>' . (int) $blocks . '</strong> απαγορεύσεις, τα συνώνυμα και τα κείμενα του chat. Κατεβάστε τον τακτικά για να μην τον χάσετε· με το ίδιο αρχείο τον επαναφέρετε εδώ ή τον μεταφέρετε σε άλλο site.</p>';

		echo '<div class="pnchat-card"><h2>Λήψη (download)</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'export' );
		echo '<p><label><input type="checkbox" name="with_questions" value="1"> Μαζί και τα ερωτήματα των επισκεπτών</label><br><span class="description">Περιέχουν e-mail επισκεπτών (προσωπικά δεδομένα): φυλάξτε το αρχείο με ασφάλεια.</span></p>';
		submit_button( 'Λήψη εγκεφάλου (.json)', 'primary', 'submit', false );
		echo '</form></div>';

		echo '<div class="pnchat-card"><h2>Επαναφορά / φόρτωση</h2><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'import' );
		echo '<p><input type="file" name="brain" accept=".json,application/json" required></p>';
		echo '<fieldset><p><label><input type="radio" name="mode" value="merge" checked> <strong>Προσθήκη</strong>: κρατά ό,τι υπάρχει και προσθέτει τις γνώσεις που λείπουν</label><br>';
		echo '<label><input type="radio" name="mode" value="replace"> <strong>Αντικατάσταση</strong>: σβήνει τις τωρινές γνώσεις και απαγορεύσεις και φορτώνει του αρχείου</label></p></fieldset>';
		echo '<p><label><input type="checkbox" name="with_settings" value="1" checked> Και τα κείμενα του chat και τα συνώνυμα</label><br>';
		echo '<label><input type="checkbox" name="with_questions" value="1"> Και τα ερωτήματα (αν υπάρχουν στο αρχείο)</label></p>';
		echo '<p class="description">Πριν από κάθε φόρτωση κρατιέται αυτόματα αντίγραφο του τωρινού εγκεφάλου (βλ. παρακάτω).</p>';
		submit_button( 'Φόρτωση', 'secondary', 'submit', false );
		echo '</form></div>';

		echo '<div class="pnchat-card"><h2>Αυτόματα αντίγραφα</h2>';
		$snaps = PNChat_Brain::snapshots();
		if ( ! $snaps ) {
			echo '<p>Δεν υπάρχουν ακόμα. Δημιουργούνται πριν από κάθε φόρτωση αρχείου (κρατιούνται τα ' . (int) PNChat_Brain::MAX_SNAPSHOTS . ' τελευταία).</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Πότε</th><th>Γιατί</th><th>Περιεχόμενο</th><th></th></tr></thead><tbody>';
			foreach ( $snaps as $sn ) {
				$c = $sn['data']['counts'] ?? array();
				echo '<tr><td>' . esc_html( wp_date( 'd/m/Y H:i', (int) $sn['time'] ) ) . '</td><td>' . esc_html( (string) $sn['label'] ) . '</td>';
				echo '<td>' . (int) ( $c['answers'] ?? 0 ) . ' γνώσεις, ' . (int) ( $c['blocks'] ?? 0 ) . ' απαγορεύσεις</td><td class="pnchat-actions">';
				echo '<a href="' . esc_url( self::action_url( 'snapshot', array( 'sid' => $sn['id'], 'do' => 'download' ) ) ) . '">Λήψη</a> | ';
				echo '<a href="' . esc_url( self::action_url( 'snapshot', array( 'sid' => $sn['id'], 'do' => 'restore' ) ) ) . '" onclick="return confirm(\'Να αντικατασταθεί ο τωρινός εγκέφαλος με αυτό το αντίγραφο;\');">Επαναφορά</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div></div>';
	}

	/**
	 * Sends a JSON download and stops.
	 *
	 * @param array<string,mixed> $data     Data.
	 * @param string              $filename File name.
	 * @return never
	 */
	private static function send_json_file( array $data, $filename ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	/**
	 * Download.
	 *
	 * @return void
	 */
	public static function handle_export() {
		self::guard( 'pnchat_export' );
		$with = '1' === self::post( 'with_questions' );
		self::send_json_file( PNChat_Brain::export( $with ), PNChat_Brain::filename( $with ? 'full' : '' ) );
	}

	/**
	 * Upload.
	 *
	 * @return void
	 */
	public static function handle_import() {
		self::guard( 'pnchat_import' );
		// Only tmp_name (checked with is_uploaded_file()), error and size are read.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checked the nonce.
		$file = isset( $_FILES['brain'] ) && is_array( $_FILES['brain'] ) ? $_FILES['brain'] : null;
		if ( ! $file || ! isset( $file['tmp_name'], $file['error'], $file['size'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			self::back( 'pn-chat-brain', 'import_error', array( 'err' => 'δεν ανέβηκε αρχείο' ) );
		}
		if ( (int) $file['size'] > 10 * MB_IN_BYTES ) {
			self::back( 'pn-chat-brain', 'import_error', array( 'err' => 'πολύ μεγάλο αρχείο (έως 10 MB)' ) );
		}
		$raw  = file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.
		$data = json_decode( (string) $raw, true );
		$ok   = PNChat_Brain::validate( $data );
		if ( is_wp_error( $ok ) ) {
			self::back( 'pn-chat-brain', 'import_error', array( 'err' => $ok->get_error_message() ) );
		}
		$res = PNChat_Brain::import( $data, 'replace' === self::post( 'mode' ) ? 'replace' : 'merge', '1' === self::post( 'with_settings' ), '1' === self::post( 'with_questions' ) );
		if ( is_wp_error( $res ) ) {
			self::back( 'pn-chat-brain', 'import_error', array( 'err' => $res->get_error_message() ) );
		}
		self::back( 'pn-chat-brain', 'imported', array( 'n' => $res['added'] ) );
	}

	/**
	 * Snapshot download / restore.
	 *
	 * @return void
	 */
	public static function handle_snapshot() {
		self::guard( 'pnchat_snapshot' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- guard() checked it.
		$sid = isset( $_GET['sid'] ) ? sanitize_key( wp_unslash( (string) $_GET['sid'] ) ) : '';
		$do  = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( (string) $_GET['do'] ) ) : '';
		// phpcs:enable
		$sn = PNChat_Brain::find_snapshot( $sid );
		if ( ! $sn || ! is_array( $sn['data'] ?? null ) ) {
			self::back( 'pn-chat-brain', 'not_found' );
		}
		if ( 'download' === $do ) {
			self::send_json_file( $sn['data'], 'pn-chat-brain-snapshot-' . gmdate( 'Y-m-d-His', (int) $sn['time'] ) . '.json' );
		}
		if ( 'restore' === $do && true === PNChat_Brain::validate( $sn['data'] ) ) {
			// A snapshot of an empty brain may be restored (to empty).
			$res = PNChat_Brain::import( $sn['data'], 'replace', true, false, true );
			if ( is_wp_error( $res ) ) {
				self::back( 'pn-chat-brain', 'import_error', array( 'err' => $res->get_error_message() ) );
			}
			self::back( 'pn-chat-brain', 'restored' );
		}
		self::back( 'pn-chat-brain', 'not_found' );
	}

	/* ------------------------------------------------------------------ */
	/* settings                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Ρυθμίσεις.
	 *
	 * @return void
	 */
	public static function page_settings() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$s = PNChat_Settings::get();
		echo '<div class="wrap pnchat-admin"><h1>Ρυθμίσεις PN Chat</h1>';
		self::notices();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pnchat-form">';
		self::form_fields( 'save_settings' );

		$text = function ( $key, $label, $desc = '' ) use ( $s ) {
			echo '<tr><th scope="row"><label for="pnchat-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input id="pnchat-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="text" class="large-text" value="' . esc_attr( (string) $s[ $key ] ) . '">' . ( $desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : '' ) . '</td></tr>';
		};
		$area = function ( $key, $label, $desc = '', $rows = 3 ) use ( $s ) {
			echo '<tr><th scope="row"><label for="pnchat-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><textarea id="pnchat-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" rows="' . (int) $rows . '" class="large-text">' . esc_textarea( (string) $s[ $key ] ) . '</textarea>' . ( $desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : '' ) . '</td></tr>';
		};
		$select = function ( $key, $label, array $options, $desc = '' ) use ( $s ) {
			echo '<tr><th scope="row"><label for="pnchat-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="pnchat-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
			foreach ( $options as $v => $l ) {
				echo '<option value="' . esc_attr( (string) $v ) . '" ' . selected( (string) $s[ $key ], (string) $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select>' . ( $desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : '' ) . '</td></tr>';
		};
		$check = function ( $key, $label, $desc = '' ) use ( $s ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( 1, (int) $s[ $key ], false ) . '> ' . esc_html( $desc ) . '</label></td></tr>';
		};
		$number = function ( $key, $label, $desc = '' ) use ( $s ) {
			echo '<tr><th scope="row"><label for="pnchat-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input id="pnchat-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="number" min="0" class="small-text" value="' . (int) $s[ $key ] . '">' . ( $desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : '' ) . '</td></tr>';
		};

		echo '<h2>Εμφάνιση</h2><table class="form-table" role="presentation">';
		$check( 'enabled', 'Chat', 'Ενεργό στο site' );
		$select(
			'visibility',
			'Ποιοι το βλέπουν',
			array(
				'all'       => 'Όλοι οι επισκέπτες',
				'logged_in' => 'Μόνο συνδεδεμένοι χρήστες (π.χ. φαρμακεία)',
			)
		);
		$select(
			'placement',
			'Πού εμφανίζεται',
			array(
				'floating'  => 'Κουμπί σε όλες τις σελίδες',
				'shortcode' => 'Μόνο όπου βάλω το shortcode [pn_chat]',
			),
			'Το shortcode [pn_chat] δείχνει το chat μέσα στη σελίδα σε κάθε περίπτωση.'
		);
		$select(
			'position',
			'Θέση κουμπιού',
			array(
				'right' => 'Κάτω δεξιά',
				'left'  => 'Κάτω αριστερά',
			)
		);
		echo '<tr><th scope="row"><label for="pnchat-color">Χρώμα</label></th><td><input id="pnchat-color" name="color" type="color" value="' . esc_attr( (string) $s['color'] ) . '"></td></tr>';
		echo '</table>';

		echo '<h2>Κείμενα</h2><table class="form-table" role="presentation">';
		$text( 'title', 'Τίτλος' );
		$text( 'subtitle', 'Υπότιτλος' );
		$area( 'welcome', 'Καλωσόρισμα' );
		$text( 'placeholder', 'Κείμενο στο πεδίο ερώτησης' );
		$area( 'suggestions', 'Προτεινόμενες ερωτήσεις', 'Μία ανά γραμμή, εμφανίζονται ως κουμπιά κάτω από το καλωσόρισμα. Γραμμή που ξεκινά με # = νέα ομάδα (π.χ. «# Ερωτήσεις για το QR ReBuilder»)· η ομάδα ανοίγει με ένα πάτημα. «Κείμενο | /διεύθυνση/» = κουμπί που ανοίγει σελίδα (π.χ. «Άνοιγμα του QR ReBuilder | /qr-rebuilder/»).', 10 );
		$area( 'fallback', 'Όταν δεν ξέρει την απάντηση', 'Ταιριάζει και σε άσχετες ερωτήσεις («τι καιρό κάνει»): ο βοηθός δεν μπορεί να ξεχωρίσει με σιγουριά το άσχετο από αυτό που δεν έχει μάθει ακόμα.' );
		$check( 'didyoumean', '«Μήπως εννοείτε…;»', 'Όταν δεν είναι σίγουρος, δείχνει έως 3 κοντινές γνώσεις ως κουμπιά (ποτέ κοντά σε απαγόρευση). Ό,τι πατά ο επισκέπτης γίνεται μάθημα (μενού Μάθηση).' );
		$text( 'didyoumean_text', 'Κείμενο πριν τα κουμπιά' );
		$number( 'learn_auto', 'Αυτόματη μάθηση μετά από', 'Τόσοι διαφορετικοί επισκέπτες πρέπει να πατήσουν την ίδια γνώση για την ίδια διατύπωση, για να μπει μόνη της (0 = ποτέ μόνη της, όλα με έγκριση). Προεπιλογή 3.' );
		$check( 'fallback_button', 'Φόρμα e-mail', 'Πίσω από κουμπί «Θέλω απάντηση από άνθρωπο», και ξανανοίγουν οι Συχνές ερωτήσεις (χωρίς τσεκ: η φόρμα εμφανίζεται αμέσως, όπως πριν την 1.9.2)' );
		$number( 'related_max', 'Σχετικές ερωτήσεις', 'Πόσες ερωτήσεις του ίδιου θέματος προτείνει κάτω από κάθε απάντηση (0 = καμία, έως 5). Θέμα είναι το πρώτο μέρος του τίτλου πριν την άνω-κάτω τελεία («eΔΑΠΥ: …») ή ένα από τα Θέματα συζήτησης.' );
		$area( 'partial', 'Όταν ξέρει μόνο ένα μέρος', 'Το {question} γίνεται το μέρος της ερώτησης χωρίς απάντηση. Το σύμβολο % γράφεται κανονικά.' );
		$area( 'unhelpful', 'Όταν πατηθεί 👎', 'Ακολουθεί φόρμα για το e-mail.' );
		$area( 'email_thanks', 'Μετά το e-mail', 'Το {email} γίνεται το e-mail του επισκέπτη.' );
		$text( 'privacy_note', 'Σημείωση κάτω από το πεδίο', 'Προαιρετικό. Αφήστε κενό για να μη φαίνεται τίποτα.' );
		echo '</table>';

		echo '<h2>Συζήτηση (small talk)</h2><p class="description">Σύντομες φράσεις που δεν είναι ερωτήσεις («ωραίο εργαλείο», «οκ», «καληνύχτα», «δεν με βοήθησες») παίρνουν αυτές τις απαντήσεις αντί για «δεν έχουμε πληροφορίες». Δεν καταγράφονται στα Ερωτήματα, εκτός από τα παράπονα. Αν μια γνώση απαντά ήδη τη φράση (π.χ. «Ευχαριστώ»), απαντά η γνώση.</p><table class="form-table" role="presentation">';
		$area( 'smalltalk_praise', 'Έπαινος', 'π.χ. «ωραίο», «τέλειο», «μπράβο». Μετά εμφανίζονται οι Συχνές ερωτήσεις.' );
		$area( 'smalltalk_praise_topic', 'Έπαινος μέσα σε θέμα', 'Όταν η συζήτηση έχει θέμα (π.χ. QR ReBuilder). Το {topic} γίνεται το όνομα του θέματος.' );
		$area( 'smalltalk_ok', 'Επιβεβαίωση', 'π.χ. «οκ», «εντάξει», «κατάλαβα». Μετά εμφανίζονται οι Συχνές ερωτήσεις.' );
		$area( 'smalltalk_bye', 'Αποχαιρετισμός', 'π.χ. «καληνύχτα», «τα λέμε».' );
		$area( 'smalltalk_complaint', 'Παράπονο', 'π.χ. «δεν με βοήθησες». Ακολουθεί φόρμα e-mail και η φράση μπαίνει στα Ερωτήματα ως «Δεν βοήθησε».' );
		echo '</table>';

		echo '<h2>Συμπεριφορά</h2><table class="form-table" role="presentation">';
		$select(
			'strictness',
			'Αυστηρότητα',
			array(
				'loose'  => 'Χαλαρή: απαντά πιο εύκολα, με κίνδυνο λάθους',
				'normal' => 'Κανονική (προτείνεται)',
				'strict' => 'Αυστηρή: απαντά μόνο όταν είναι σίγουρος',
			),
			'Δοκιμάστε την αλλαγή στο «Εκπαίδευση → Δοκιμή».'
		);
		$number( 'max_answers', 'Απαντήσεις μαζί', 'Πόσες γνώσεις συνδυάζει το πολύ σε μία απάντηση (1–5).' );
		$check( 'feedback', '«Σας βοήθησε;»', 'Κουμπιά 👍 / 👎 κάτω από τις απαντήσεις. Το 👎 ζητά e-mail και βάζει την ερώτηση στα ανοιχτά.' );
		$number( 'rate_per_10min', 'Όριο ερωτήσεων', 'Ερωτήσεις ανά επισκέπτη ανά 10 λεπτά (προστασία από κατάχρηση). Πίσω από Cloudflare ή άλλο proxy όλοι οι επισκέπτες φαίνονται με την IP του proxy: ορίστε στο wp-config.php π.χ. define( \'PNCHAT_IP_HEADER\', \'HTTP_CF_CONNECTING_IP\' ); (IP τώρα: ' . PNChat_Rest::client_ip() . ').' );
		echo '</table>';

		echo '<h2>Αναζήτηση στο site</h2><table class="form-table" role="presentation">';
		$check( 'site_search', 'Όταν δεν ξέρει', 'Ψάξε στις σελίδες και τα άρθρα του site και δείξε τα πιο σχετικά (τίτλο, σχετική πρόταση και link)' );
		$text( 'site_types', 'Τι να ψάχνει', 'Τύποι περιεχομένου χωρισμένοι με κόμμα: post = άρθρα, page = σελίδες (π.χ. «post, page» ή και «product»).' );
		$area( 'site_exclude', 'Να μην ψάχνει σε', 'Σελίδες που δεν πρέπει να εμφανίζονται: μία ανά γραμμή, ID ή διεύθυνση (π.χ. /my-account/). Καλάθι, ταμείο και λογαριασμός WooCommerce εξαιρούνται αυτόματα, όπως και οι σελίδες με κωδικό.' );
		$number( 'site_max', 'Πόσες σελίδες', 'Το πολύ πόσες σελίδες δείχνει (1–5).' );
		$area( 'site_intro', 'Κείμενο πριν τις σελίδες' );
		$area( 'site_more', 'Κείμενο μετά τις σελίδες', 'Ακολουθεί φόρμα για το e-mail του επισκέπτη.' );
		echo '</table>';
		echo '<p>Ευρετήριο: <strong>' . (int) PNChat_Site_Search::count() . '</strong> σελίδες. Διαβάζεται ό,τι δείχνει η σελίδα, μαζί με πίνακες, shortcodes και blocks (π.χ. ένας πίνακας με λίστα φαρμάκων): έτσι στο «Είναι το Aerolin στη λίστα;» ο βοηθός δείχνει τη γραμμή του πίνακα. Ενημερώνεται μόνο του όταν αποθηκεύετε μια σελίδα, και κάθε σελίδα ξαναδιαβάζεται μία φορά την εβδομάδα. <a class="button" href="' . esc_url( self::action_url( 'site_reindex', array() ) ) . '">Ενημέρωση τώρα</a> <a href="#pnchat-peek">Τι διαβάζει από μια σελίδα;</a> · <a href="' . esc_url( admin_url( 'admin.php?page=pn-chat-index' ) ) . '">Έλεγχος ευρετηρίου</a></p>';

		echo '<h2 id="pnchat-ai">✨ AI βοηθός εκπαίδευσης (Claude)</h2>';
		echo '<p class="description">Στο wp-admin: το AI διαβάζει σελίδες του site, διευθύνσεις και PDF και προτείνει γνώσεις (μενού «Προτάσεις AI»), που εγκρίνετε εσείς. Στο δημόσιο chat απαντά μόνο αν ενεργοποιήσετε παρακάτω το «AI και μέσα στο chat». Στο Claude στέλνονται σελίδες του δημόσιου site και το κείμενο της ερώτησης· τα πεδία e-mail και ονόματος του επισκέπτη δεν στέλνονται, και e-mail, τηλέφωνα και ΑΜΚΑ γραμμένα μέσα στην ερώτηση αντικαθίστανται πριν την αποστολή. Χρεώνεται ανά χρήση στον λογαριασμό σας στο console.anthropic.com.</p>';
		echo '<table class="form-table" role="presentation">';
		$check( 'ai_enabled', 'AI', 'Ενεργό (κουμπιά «✨ Πρόταση με AI» στην Εκπαίδευση και στα Ερωτήματα)' );
		echo '<tr><th scope="row"><label for="pnchat-ai_key">API key</label></th><td>';
		if ( PNChat_AI::key_from_constant() ) {
			echo '<p>Ορίστηκε στο <code>wp-config.php</code> (<code>PNCHAT_ANTHROPIC_API_KEY</code>, ' . esc_html( PNChat_AI::key_hint() ) . ').</p>';
		} else {
			echo '<input id="pnchat-ai_key" name="ai_key" type="password" class="regular-text" autocomplete="new-password" value="" placeholder="' . esc_attr( '' !== PNChat_AI::api_key() ? 'αποθηκευμένο ' . PNChat_AI::key_hint() . ' — αφήστε κενό για να μείνει' : 'sk-ant-…' ) . '">';
			if ( '' !== PNChat_AI::api_key() ) {
				echo ' <label><input type="checkbox" name="ai_key_delete" value="1"> Διαγραφή κλειδιού</label>';
			}
			echo '<p class="description">Πιο ασφαλές: στο <code>wp-config.php</code> γράψτε <code>define( \'PNCHAT_ANTHROPIC_API_KEY\', \'sk-ant-…\' );</code>. Το κλειδί δεν εμφανίζεται ποτέ ξανά εδώ και δεν μπαίνει στο «Εγκέφαλος».</p>';
		}
		echo '</td></tr>';
		$text( 'ai_model', 'Μοντέλο', 'Προεπιλογή: ' . PNChat_AI::DEFAULT_MODEL . ' (Claude Opus 5.5). Φθηνότερα: claude-sonnet-5-5, claude-haiku-4-5.' );
		$number( 'ai_learn_monthly', 'Όριο διαβάσματος ανά μήνα', 'Το πολύ τόσες πηγές (σελίδες, διευθύνσεις ή PDF) διαβάζει το AI τον μήνα για τις «Προτάσεις AI» (αυτόν τον μήνα: ' . PNChat_Learn::used_this_month() . '). Το διάβασμα γίνεται με το Batch API της Anthropic, στη μισή τιμή: μια σελίδα περίπου 0,01–0,05 $, ένα PDF 40 σελίδων περίπου 0,25–0,75 $ με το προεπιλεγμένο μοντέλο.' );
		$check( 'ai_learn_weekly', 'Κάθε εβδομάδα', 'Ξαναδιάβαζε μόνο του, μία φορά την εβδομάδα, τις σελίδες του site που άλλαξαν ή είναι καινούριες. Οι νέες γνώσεις μπαίνουν στις Προτάσεις· δεν φτάνουν στο chat χωρίς έγκριση.' );
		$check( 'ai_learn_media', 'Νέα PDF', 'Κάθε PDF που ανεβαίνει στα Πολυμέσα διαβάζεται μόνο του και οι γνώσεις του μπαίνουν στις Προτάσεις για έγκριση. Μετράει στο όριο του μήνα (ένα PDF 40 σελίδων περίπου 0,25–0,75 $). Τα παλιά PDF: Έλεγχος ευρετηρίου → «PDF στα Πολυμέσα».' );
		echo '<tr><th scope="row" colspan="2"><h3 style="margin:8px 0 0">AI και μέσα στο chat</h3></th></tr>';
		$check( 'ai_chat', 'Στο chat', 'Όταν δεν υπάρχει γνώση αλλά βρεθούν σχετικές σελίδες, το AI απαντά στον επισκέπτη μόνο από αυτές. Η απάντηση εμφανίζεται ΑΜΕΣΩΣ, με την ετικέτα παρακάτω, χωρίς να την έχει δει άνθρωπος, και μπαίνει στα Ερωτήματα → «Απαντήσεις AI» για έγκριση ως γνώση. Το AI απαντά ΜΟΝΟ σε ερωτήσεις για τα εργαλεία του site (ένα από τα «Θέματα συζήτησης» ή λέξεις όπως εκτύπωση, ετικέτα, πλάνο, λογαριασμός), όχι κοντά σε κάποια Απαγόρευση και χωρίς ιατρικό σήμα (π.χ. «πόσα χάπια», «παρενέργειες», «500mg», «φάρμακο για τον πόνο»)· και το ίδιο το AI δηλώνει αν η ερώτηση ζητά ιατρική συμβουλή, οπότε δεν απαντά. Όλες οι άλλες ερωτήσεις παίρνουν τις σελίδες του site και τη φόρμα e-mail, όπως χωρίς AI.' );
		$number( 'ai_chat_daily', 'Όριο κλήσεων ανά ημέρα', 'Το πολύ τόσες κλήσεις στο AI την ημέρα (σήμερα: ' . PNChat_AI::chat_used_today() . '). Μετράει κάθε κλήση που έφτασε στο Claude, και όταν δεν βρήκε απάντηση· όσες δεν έφτασαν (σφάλμα σύνδεσης ή κλειδιού) δεν μετράνε. Μετά το όριο, ο βοηθός ζητά e-mail όπως πριν. Και έως 10 την ώρα ανά επισκέπτη.' );
		$text( 'ai_chat_model', 'Μοντέλο για το chat', 'Προεπιλογή: claude-opus-5-5 (4 $ / 20 $ ανά εκατομμύριο tokens). Φθηνότερα: claude-sonnet-5-5 (2 $ / 10 $), claude-haiku-4-5 (1 $ / 5 $).' );
		$text( 'ai_chat_label', 'Ετικέτα πάνω από την απάντηση AI' );
		$text( 'ai_chat_wait', 'Κείμενο όσο περιμένει' );
		echo '<tr><th scope="row">Κόστος έως τώρα</th><td>' . esc_html( self::ai_cost_text() ) . '</td></tr>';
		echo '</table>';

		echo '<h2>E-mail</h2><table class="form-table" role="presentation">';
		$check( 'notify_on_email', 'Ειδοποίηση', 'Στείλε μου e-mail όταν ένας επισκέπτης αφήσει e-mail για απάντηση' );
		echo '<tr><th scope="row"><label for="pnchat-notify_email">E-mail ειδοποιήσεων</label></th><td><input id="pnchat-notify_email" name="notify_email" type="email" class="regular-text" value="' . esc_attr( (string) $s['notify_email'] ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '"><p class="description">Κενό = το e-mail διαχειριστή του site (αν αλλάξει εκείνο, ακολουθεί).</p></td></tr>';
		$text( 'reply_subject', 'Θέμα απαντήσεων' );
		echo '</table>';

		echo '<h2>Απόρρητο</h2><table class="form-table" role="presentation">';
		$number( 'retention_days', 'Διατήρηση ερωτημάτων (ημέρες)', 'Τα ερωτήματα (και τα e-mail τους) σβήνονται αυτόματα μετά από τόσες ημέρες. 0 = ποτέ.' );
		$check( 'keep_on_uninstall', 'Απεγκατάσταση', 'Κράτα τον εγκέφαλο και τα ερωτήματα αν διαγραφεί το plugin' );
		echo '</table>';

		submit_button( 'Αποθήκευση ρυθμίσεων' );
		echo '</form>';
		self::site_peek();
		echo '</div>';
	}

	/**
	 * «Τι διαβάζει από μια σελίδα»: the text and table rows the chat reads
	 * from a page, and the rows with a word, to check that a table is read.
	 *
	 * @return void
	 */
	private static function site_peek() {
		$page = self::get( 'peek' );
		$word = self::get( 'peek_word' );
		echo '<h2 id="pnchat-peek">Τι διαβάζει από μια σελίδα</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '#pnchat-peek"><input type="hidden" name="page" value="pn-chat-settings">';
		echo '<p><input name="peek" type="text" class="regular-text" placeholder="Διεύθυνση ή ID σελίδας" value="' . esc_attr( $page ) . '"> <input name="peek_word" type="text" placeholder="Λέξη, π.χ. Aerolin" value="' . esc_attr( $word ) . '"> <button class="button">Δείξε</button></p></form>';
		if ( '' === $page ) {
			return;
		}
		$id   = ctype_digit( $page ) ? (int) $page : url_to_postid( $page );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post ) {
			echo '<p class="pnchat-warn">Δεν βρέθηκε σελίδα με αυτή τη διεύθυνση.</p>';
			return;
		}
		if ( ! PNChat_Site_Search::searchable( $post ) ) {
			echo '<p class="pnchat-warn">Η σελίδα «' . esc_html( get_the_title( $post ) ) . '» δεν είναι στην αναζήτηση (δεν είναι δημοσιευμένη, έχει κωδικό, είναι εξαιρεμένη ή δεν είναι από τους τύπους που ψάχνει).</p>';
			return;
		}
		$text = PNChat_Site_Search::read_text( $post );
		$rows = array_values(
			array_filter(
				explode( "\n", $text ),
				function ( $l ) {
					return false !== strpos( $l, ' · ' );
				}
			)
		);
		echo '<p><strong>' . esc_html( get_the_title( $post ) ) . '</strong>: ' . esc_html( number_format_i18n( mb_strlen( $text ) ) ) . ' χαρακτήρες, ' . count( $rows ) . ' γραμμές πινάκων.</p>';
		if ( '' !== $word ) {
			$w    = ' ' . PNChat_Text::fold( $word ) . ' ';
			$hits = array();
			foreach ( explode( "\n", $text ) as $line ) {
				if ( false !== strpos( ' ' . PNChat_Text::fold( $line ) . ' ', $w ) ) {
					$hits[] = $line;
				}
			}
			echo $hits ? '<p>Γραμμές με «' . esc_html( $word ) . '» (' . count( $hits ) . '):</p><ul class="ul-disc">' : '<p class="pnchat-warn">Η λέξη «' . esc_html( $word ) . '» δεν υπάρχει σε ό,τι διαβάζει ο βοηθός από αυτή τη σελίδα. Αν φαίνεται στη σελίδα, ο πίνακας μάλλον φορτώνεται από αρχείο ή άλλο site (π.χ. Google Sheets) και δεν διαβάζεται· γράψτε τον ως πίνακα μέσα στη σελίδα.</p>';
			foreach ( array_slice( $hits, 0, 20 ) as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo $hits ? '</ul>' : '';
		}
		echo '<details><summary>Όλο το κείμενο</summary><pre style="white-space:pre-wrap;max-height:400px;overflow:auto">' . esc_html( mb_substr( $text, 0, 20000 ) ) . '</pre></details>';
	}

	/**
	 * «Έλεγχος ευρετηρίου»: which pages and posts the chat has read, which
	 * are missing or have too little text, and the PDFs of the Media Library.
	 *
	 * @return void
	 */
	public static function page_index() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'pn-chat' ), 403 );
		}
		$rows   = PNChat_Site_Search::report();
		$by     = array(
			'ok'       => array(),
			'thin'     => array(),
			'old'      => array(),
			'missing'  => array(),
			'excluded' => array(),
		);
		foreach ( $rows as $r ) {
			$by[ $r['status'] ][] = $r;
		}
		$searched = count( $rows ) - count( $by['excluded'] );
		$read     = count( $by['ok'] ) + count( $by['thin'] ) + count( $by['old'] );
		$peek     = function ( $id ) {
			return admin_url( 'admin.php?page=pn-chat-settings&peek=' . (int) $id . '#pnchat-peek' );
		};
		$when     = function ( $ts ) {
			return $ts > 0 ? wp_date( 'j/n/Y H:i', $ts ) : '—';
		};

		echo '<div class="wrap pnchat-admin"><h1>Έλεγχος ευρετηρίου</h1>';
		self::notices();
		if ( empty( PNChat_Settings::value( 'site_search' ) ) ) {
			echo '<p class="pnchat-warn">Η αναζήτηση στο site είναι κλειστή (Ρυθμίσεις → «Αναζήτηση στο site»): ο βοηθός δεν δείχνει σελίδες στο chat.</p>';
		}
		echo '<p class="pnchat-intro">Ψάχνει σε: <strong>' . esc_html( implode( ', ', PNChat_Site_Search::post_types() ) ) . '</strong> (Ρυθμίσεις → «Τι να ψάχνει»).';
		if ( post_type_exists( 'product' ) && ! in_array( 'product', PNChat_Site_Search::post_types(), true ) ) {
			echo ' Τα προϊόντα του καταστήματος δεν διαβάζονται· για να διαβάζονται, προσθέστε «product».';
		}
		echo '</p>';

		echo '<ul class="pnchat-index-sum">';
		echo '<li class="is-ok"><strong>' . (int) $read . ' / ' . (int) $searched . '</strong> διαβάστηκαν</li>';
		echo '<li class="' . ( $by['missing'] ? 'is-bad' : 'is-ok' ) . '"><strong>' . count( $by['missing'] ) . '</strong> λείπουν</li>';
		echo '<li class="' . ( $by['thin'] ? 'is-warn' : 'is-ok' ) . '"><strong>' . count( $by['thin'] ) . '</strong> με πολύ λίγο κείμενο</li>';
		echo '<li><strong>' . count( $by['excluded'] ) . '</strong> εξαιρεμένες</li>';
		echo '</ul>';
		if ( ! $by['missing'] && ! $by['old'] && $searched > 0 ) {
			echo '<p class="pnchat-ok">✅ Όλες οι σελίδες και τα άρθρα που ψάχνει ο βοηθός έχουν διαβαστεί.' . ( $by['thin'] ? ' Ελέγξτε μόνο όσες έχουν πολύ λίγο κείμενο.' : '' ) . '</p>';
		} elseif ( $by['old'] ) {
			$n = count( $by['old'] );
			echo '<p class="pnchat-warn">' . esc_html( 1 === $n ? '1 σελίδα διαβάστηκε με την παλιά έκδοση και ξαναδιαβάζεται' : $n . ' σελίδες διαβάστηκαν με την παλιά έκδοση και ξαναδιαβάζονται' ) . ' στο παρασκήνιο (μαζί με πίνακες και shortcodes). Μέχρι τότε ο βοηθός ψάχνει κανονικά στο κείμενό τους.</p>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( self::action_url( 'index_now', array() ) ) . '">Διάβασε τώρα όσες λείπουν</a> ';
		echo '<a class="button" href="' . esc_url( self::action_url( 'index_refresh', array() ) ) . '">Διάβασέ τα όλα ξανά</a></p>';
		echo '<p class="description">Κάθε σελίδα διαβάζεται μόλις πατήσετε «Δημοσίευση» ή «Ενημέρωση», και ξανά μία φορά την εβδομάδα. Όσες μπήκαν αλλιώς (εισαγωγή) διαβάζονται μέσα στη μέρα.</p>';

		$table = function ( $title, array $list, $note, $cols ) use ( $peek, $when ) {
			echo '<div class="pnchat-card"><h2>' . esc_html( $title ) . ' (' . count( $list ) . ')</h2>';
			if ( '' !== $note ) {
				echo '<p class="description">' . esc_html( $note ) . '</p>';
			}
			if ( ! $list ) {
				echo '<p>Καμία.</p></div>';
				return;
			}
			echo '<table class="widefat striped pnchat-table"><thead><tr><th>Σελίδα</th><th>Τύπος</th>';
			echo in_array( 'chars', $cols, true ) ? '<th class="num">Χαρακτήρες</th><th>Διαβάστηκε</th>' : '';
			echo in_array( 'reason', $cols, true ) ? '<th>Γιατί</th>' : '';
			echo '<th></th></tr></thead><tbody>';
			foreach ( $list as $r ) {
				echo '<tr><td><a href="' . esc_url( (string) get_permalink( $r['id'] ) ) . '" target="_blank" rel="noopener">' . esc_html( $r['title'] ) . '</a></td><td data-label="Τύπος">' . esc_html( $r['type'] ) . '</td>';
				if ( in_array( 'chars', $cols, true ) ) {
					echo '<td class="num" data-label="Χαρακτήρες">' . esc_html( number_format_i18n( $r['chars'] ) ) . '</td><td data-label="Διαβάστηκε">' . esc_html( $when( $r['read_at'] ) ) . '</td>';
				}
				if ( in_array( 'reason', $cols, true ) ) {
					echo '<td data-label="Γιατί">' . esc_html( $r['reason'] ) . '</td>';
				}
				echo '<td><a href="' . esc_url( $peek( $r['id'] ) ) . '">Τι διαβάζει</a> · <a href="' . esc_url( (string) get_edit_post_link( $r['id'] ) ) . '">Επεξεργασία</a></td></tr>';
			}
			echo '</tbody></table></div>';
		};
		$table( '❌ Λείπουν', $by['missing'], 'Δημοσιευμένες αλλά δεν έχουν διαβαστεί ακόμα. Πατήστε «Διάβασε τώρα όσες λείπουν».', array() );
		$table( '⚠️ Πολύ λίγο κείμενο', $by['thin'], 'Λιγότεροι από ' . PNChat_Site_Search::THIN . ' χαρακτήρες: ό,τι δείχνει η σελίδα μάλλον έρχεται από αλλού (εικόνα, αρχείο, Google Sheets, iframe) και δεν διαβάζεται. Δείτε «Τι διαβάζει»· αν λείπει κάτι σημαντικό, γράψτε το ως κείμενο ή πίνακα μέσα στη σελίδα.', array( 'chars' ) );
		$table( '🚫 Εξαιρεμένες', $by['excluded'], 'Δεν εμφανίζονται ποτέ στο chat.', array( 'reason' ) );
		echo '<details class="pnchat-card"><summary><strong>Όλες οι σελίδες και τα άρθρα (' . (int) $searched . ')</strong></summary>';
		$all = array_merge( $by['ok'], $by['thin'], $by['old'], $by['missing'] );
		usort(
			$all,
			function ( $a, $b ) {
				return strcmp( $a['type'] . $a['title'], $b['type'] . $b['title'] );
			}
		);
		$table( 'Διαβάστηκαν', $all, '', array( 'chars' ) );
		echo '</details>';

		self::index_media();
		echo '</div>';
	}

	/**
	 * PDFs of the Media Library on the index screen.
	 *
	 * @return void
	 */
	private static function index_media() {
		$ids = PNChat_Learn::media_pdfs( 500 );
		echo '<div class="pnchat-card" id="pnchat-media"><h2>📄 PDF στα Πολυμέσα (' . count( $ids ) . ')</h2>';
		echo '<p class="description">Τα PDF δεν μπαίνουν στην αναζήτηση του chat. Τα διαβάζει το AI και προτείνει γνώσεις, που εγκρίνετε στις «Προτάσεις AI»· τίποτα δεν φτάνει στο chat χωρίς έγκριση. Κάθε PDF μετράει στο όριο του μήνα (' . (int) PNChat_Learn::used_this_month() . ' από ' . (int) PNChat_Learn::monthly_limit() . ')· ένα PDF 40 σελίδων κοστίζει περίπου 0,25–0,75 $.</p>';
		$auto = ! empty( PNChat_Settings::value( 'ai_learn_media' ) );
		echo '<p>Αυτόματο διάβασμα νέων PDF: <strong>' . ( $auto ? 'ενεργό' : 'ανενεργό' ) . '</strong> (<a href="' . esc_url( admin_url( 'admin.php?page=pn-chat-settings#pnchat-ai' ) ) . '">Ρυθμίσεις → AI → «Νέα PDF»</a>).</p>';
		if ( ! PNChat_Learn::available() ) {
			echo '<p class="pnchat-warn">Το AI δεν είναι ενεργό ή δεν έχει API key: τα PDF δεν μπορούν να διαβαστούν.</p>';
		}
		if ( ! $ids ) {
			echo '<p>Δεν υπάρχουν PDF στα Πολυμέσα.</p></div>';
			return;
		}
		$waiting = array();
		foreach ( PNChat_Learn::queue() as $j ) {
			if ( 'media' === ( $j['type'] ?? '' ) ) {
				$waiting[ (int) $j['id'] ] = true;
			}
		}
		// Sent to Claude, waiting for the answer (ids are unique across posts and files).
		foreach ( PNChat_Learn::sent() as $b ) {
			if ( ! empty( $b['src']['post_id'] ) ) {
				$waiting[ (int) $b['src']['post_id'] ] = true;
			}
		}
		$unread = 0;
		$list   = array();
		foreach ( $ids as $id ) {
			$read = PNChat_Learn::media_read( $id );
			if ( ! $read && ! isset( $waiting[ $id ] ) ) {
				++$unread;
			}
			$list[] = array( $id, $read );
		}
		if ( $unread && PNChat_Learn::available() ) {
			echo '<p><a class="button button-primary" href="' . esc_url( self::action_url( 'index_media', array( 'all' => 1 ) ) ) . '">Διάβασε με AI όσα δεν έχουν διαβαστεί (' . (int) $unread . ')</a></p>';
		}
		echo '<table class="widefat striped pnchat-table"><thead><tr><th>PDF</th><th>Ανέβηκε</th><th class="num">Μέγεθος</th><th>Κατάσταση</th><th></th></tr></thead><tbody>';
		foreach ( $list as $item ) {
			list( $id, $read ) = $item;
			$path = (string) get_attached_file( $id );
			$size = '' !== $path && is_readable( $path ) ? (int) filesize( $path ) : 0;
			if ( isset( $waiting[ $id ] ) ) {
				$state = '⏳ Διαβάζεται';
			} elseif ( $read ) {
				$state = '✅ Διαβάστηκε';
			} else {
				$state = '— Όχι ακόμα';
			}
			echo '<tr><td><a href="' . esc_url( (string) wp_get_attachment_url( $id ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $id ) ) . '</a></td><td data-label="Ανέβηκε">' . esc_html( (string) get_the_date( 'j/n/Y', $id ) ) . '</td><td class="num" data-label="Μέγεθος">' . esc_html( size_format( $size ) ? size_format( $size ) : '—' ) . '</td><td data-label="Κατάσταση">' . esc_html( $state ) . '</td><td>';
			if ( ! isset( $waiting[ $id ] ) && PNChat_Learn::available() ) {
				echo '<a href="' . esc_url( self::action_url( 'index_media', array( 'id' => $id ) ) ) . '">' . ( $read ? 'Διάβασε ξανά' : 'Διάβασε με AI' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * «Διάβασε τώρα όσες λείπουν».
	 *
	 * @return void
	 */
	public static function handle_index_now() {
		self::guard( 'pnchat_index_now' );
		self::back( 'pn-chat-index', 'index_now', array( 'n' => PNChat_Site_Search::index_batch() ) );
	}

	/**
	 * «Διάβασέ τα όλα ξανά»: in the background, the index stays meanwhile.
	 *
	 * @return void
	 */
	public static function handle_index_refresh() {
		self::guard( 'pnchat_index_refresh' );
		PNChat_Site_Search::request_refresh();
		self::back( 'pn-chat-index', 'index_refresh' );
	}

	/**
	 * Reads PDFs of the Media Library with AI: one, or all not read yet.
	 *
	 * @return void
	 */
	public static function handle_index_media() {
		self::guard( 'pnchat_index_media' );
		if ( ! PNChat_Learn::available() ) {
			self::back( 'pn-chat-index', 'ai_off' );
		}
		$id = absint( self::get( 'id' ) );
		$n  = $id
			? PNChat_Learn::enqueue_media( array( $id ), true, 'PDF: ' . get_the_title( $id ) )
			: PNChat_Learn::enqueue_media( PNChat_Learn::media_pdfs( 500 ), false, 'PDF στα Πολυμέσα' );
		self::back( 'pn-chat-index', $n ? 'media_queued' : 'media_none', array( 'n' => $n ) );
	}

	/**
	 * Rebuilds the site index now.
	 *
	 * @return void
	 */
	public static function handle_site_reindex() {
		self::guard( 'pnchat_site_reindex' );
		$n = PNChat_Site_Search::rebuild();
		self::back( 'pn-chat-settings', 'reindexed', array( 'n' => $n ) );
	}

	/**
	 * Saves settings.
	 *
	 * @return void
	 */
	public static function handle_save_settings() {
		self::guard( 'pnchat_save_settings' );
		$current = PNChat_Settings::get();
		$in      = array();
		foreach ( array_keys( PNChat_Settings::defaults() ) as $k ) {
			$in[ $k ] = self::post( $k );
		}
		// The synonyms and topics are edited on the training screen.
		$in['synonyms'] = (string) $current['synonyms'];
		$in['topics']   = (string) $current['topics'];
		$clean          = PNChat_Settings::sanitize( $in );
		if ( ! PNChat_Settings::save( $clean ) ) {
			self::back( 'pn-chat-settings', 'save_failed' );
		}
		$raw = trim( self::post( 'ai_key' ) );
		$key = PNChat_AI::extract_key( $raw );
		$msg = 'settings';
		if ( '1' === self::post( 'ai_key_delete' ) ) {
			delete_option( 'pnchat_ai_key' );
		} elseif ( '' !== $raw && '' === $key ) {
			$msg = 'key_bad';
		} elseif ( '' !== $key ) {
			// A key Anthropic rejects is not saved; the previous one stays.
			$ok  = PNChat_AI::check_key( $key );
			$msg = false === $ok ? 'key_rejected' : ( true === $ok ? 'key_ok' : 'settings' );
			if ( false !== $ok ) {
				update_option( 'pnchat_ai_key', $key, false );
			}
		} elseif ( $clean['ai_enabled'] && '' !== PNChat_AI::api_key() && false === PNChat_AI::check_key( PNChat_AI::api_key() ) ) {
			$msg = 'key_dead';
		}
		if ( $clean['site_types'] !== $current['site_types'] || $clean['site_exclude'] !== $current['site_exclude'] ) {
			// First batch now, the rest in the background.
			PNChat_Site_Search::rebuild();
		}
		PNChat_Store::bump();
		PNChat_Learn::sync_weekly();
		self::back( 'pn-chat-settings', $msg );
	}
}
