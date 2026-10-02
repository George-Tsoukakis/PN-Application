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
		foreach ( array( 'save_entry', 'delete_entry', 'toggle_entry', 'save_synonyms', 'question', 'bulk_questions', 'send_reply', 'export', 'import', 'snapshot', 'save_settings', 'site_reindex', 'ai_draft', 'ai_page', 'ai_save' ) as $a ) {
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
		$badge  = $counts['email'] > 0 ? ' <span class="awaiting-mod count-' . (int) $counts['email'] . '"><span class="pending-count">' . (int) $counts['email'] . '</span></span>' : '';

		add_menu_page( 'PN Chat', 'PN Chat' . $badge, $cap, 'pn-chat', array( __CLASS__, 'page_training' ), 'dashicons-format-chat', 58 );
		add_submenu_page( 'pn-chat', 'Εκπαίδευση', 'Εκπαίδευση', $cap, 'pn-chat', array( __CLASS__, 'page_training' ) );
		add_submenu_page( 'pn-chat', 'Ερωτήματα', 'Ερωτήματα' . $badge, $cap, 'pn-chat-questions', array( __CLASS__, 'page_questions' ) );
		add_submenu_page( 'pn-chat', 'Απαγορεύσεις', 'Απαγορεύσεις', $cap, 'pn-chat-blocks', array( __CLASS__, 'page_blocks' ) );
		add_submenu_page( 'pn-chat', 'Εγκέφαλος (αντίγραφο)', 'Εγκέφαλος', $cap, 'pn-chat-brain', array( __CLASS__, 'page_brain' ) );
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
			'synonyms'     => array( 'success', 'Τα συνώνυμα αποθηκεύτηκαν.' ),
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
			'not_found'    => array( 'error', 'Δεν βρέθηκε.' ),
			'ai_saved'     => array( 'success', sprintf( 'Αποθηκεύτηκαν %d γνώσεις από την πρόταση του AI.', $n ) ),
			'ai_error'     => array( 'error', 'AI: ' . sanitize_text_field( self::get( 'err' ) ) ),
			'ai_expired'   => array( 'error', 'Η πρόταση του AI έληξε (κρατιέται 1 ώρα). Ζητήστε τη ξανά.' ),
			'reindexed'    => array( 'success', sprintf( 'Το ευρετήριο του site ενημερώθηκε: %d σελίδες.', $n ) ),
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

		if ( 'answer' === $kind && '' !== self::get( 'ai_review' ) ) {
			self::ai_review_screen( self::get( 'ai_review' ) );
			echo '</div>';
			return;
		}

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
		$kept = get_transient( 'pnchat_form_' . get_current_user_id() );
		if ( is_array( $kept ) && ( $kept['kind'] ?? '' ) === $kind && (int) ( $kept['id'] ?? 0 ) === (int) ( $entry['id'] ?? 0 ) ) {
			delete_transient( 'pnchat_form_' . get_current_user_id() );
			$title     = (string) $kept['title'];
			$phrasings = (array) $kept['phrasings'];
			$keywords  = (array) $kept['keywords'];
			$answer    = (string) $kept['answer'];
			$active    = (int) $kept['active'];
		}

		// A draft written by the AI from the site's pages, to review.
		$ai = ( ! $entry && ! $is_block ) ? self::ai_stash_get( self::get( 'ai' ) ) : null;
		if ( '' !== self::get( 'ai' ) && null === $ai && ! $entry ) {
			echo '<div class="notice notice-error inline"><p>Η πρόταση του AI έληξε (κρατιέται 1 ώρα). Ζητήστε τη ξανά.</p></div>';
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
		echo '<div class="pnchat-card"><h2>Δοκιμή</h2><p class="description">Γράψτε μια ερώτηση για να δείτε τι θα απαντούσε ο βοηθός (δεν καταγράφεται).</p>';
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
				echo '<div class="pnchat-test-item ' . ( 'block' === $i['kind'] ? 'is-block' : '' ) . '"><strong>' . esc_html( $i['title'] ) . '</strong> <span class="description">(' . esc_html( number_format_i18n( $i['score'] * 100 ) ) . '%)</span><div>' . wp_kses( PNChat_Brain::render_answer( $i['answer'] ), PNChat_Brain::allowed_html() ) . '</div></div>';
			}
			if ( $r['unmatched'] ) {
				echo '<p>Χωρίς απάντηση: «' . esc_html( implode( '», «', $r['unmatched'] ) ) . '»</p>';
			}
			$site = PNChat_Rest::site_results( $r, $q );
			if ( $site ) {
				echo '<p><strong>Από το site:</strong></p>';
				foreach ( $site as $sr ) {
					echo '<div class="pnchat-test-item is-site"><strong>' . esc_html( $sr['title'] ) . '</strong> <span class="description">(' . esc_html( number_format_i18n( $sr['score'] * 100 ) ) . '%)</span><div>' . wp_kses_post( PNChat_Site_Search::render( $sr ) ) . '</div></div>';
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
			echo '<p>Το AI διαβάζει σελίδες του site σας και γράφει έτοιμες γνώσεις, που εγκρίνετε εσείς πριν αποθηκευτούν. Το δημόσιο chat δεν χρησιμοποιεί ποτέ AI. Ενεργοποιήστε το από τις <a href="' . esc_url( admin_url( 'admin.php?page=pn-chat-settings#pnchat-ai' ) ) . '">Ρυθμίσεις</a>.</p></div>';
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
		echo '<p class="description">Χρειάζεται 20–60 δευτερόλεπτα. Κόστος έως τώρα: ' . esc_html( self::ai_cost_text() ) . '.</p></div>';
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
		if ( PNChat_AI::DEFAULT_MODEL === PNChat_AI::model() ) {
			// Claude Opus 5.5 list prices: $4 / MTok input, $20 / MTok output,
			// $0.20 / MTok cache reads (cache writes are counted as input here).
			$usd   = ( (int) ( $u['input_tokens'] ?? 0 ) + (int) ( $u['cache_creation_input_tokens'] ?? 0 ) ) * 4 / 1e6
				+ (int) ( $u['output_tokens'] ?? 0 ) * 20 / 1e6
				+ (int) ( $u['cache_read_input_tokens'] ?? 0 ) * 0.2 / 1e6;
			$text .= sprintf( ' (περίπου $%s)', number_format_i18n( $usd, 2 ) );
		}
		return $text;
	}

	/**
	 * Review screen for entries drafted from a page.
	 *
	 * @param string $key Draft key.
	 * @return void
	 */
	private static function ai_review_screen( $key ) {
		$d = self::ai_stash_get( $key );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=pn-chat' ) ) . '">← Εκπαίδευση</a></p>';
		if ( ! $d || 'page' !== ( $d['type'] ?? '' ) ) {
			echo '<div class="notice notice-error"><p>Η πρόταση του AI έληξε (κρατιέται 1 ώρα). Ζητήστε τη ξανά.</p></div>';
			return;
		}
		echo '<h2>✨ Προτάσεις του AI από: <a href="' . esc_url( (string) $d['url'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) $d['title'] ) . '</a></h2>';
		if ( ! $d['entries'] ) {
			echo '<p>Το AI δεν βρήκε στη σελίδα κάτι χρήσιμο που να μην το ξέρει ήδη ο βοηθός.</p>';
			return;
		}
		echo '<p class="pnchat-intro">Διαβάστε κάθε πρόταση. Τσεκάρετε όσες είναι σωστές και πατήστε «Αποθήκευση επιλεγμένων». Μπορείτε να τις διορθώσετε μετά από την Εκπαίδευση.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'ai_save' );
		echo '<input type="hidden" name="key" value="' . esc_attr( $key ) . '">';
		foreach ( $d['entries'] as $i => $e ) {
			echo '<div class="pnchat-card pnchat-ai-draft"><label><input type="checkbox" name="pick[]" value="' . (int) $i . '" checked> <strong>' . esc_html( (string) $e['title'] ) . '</strong></label>';
			echo '<div class="pnchat-ai-cols"><div><p class="description">Ερωτήσεις</p><ul class="pnchat-list">';
			foreach ( (array) $e['phrasings'] as $p ) {
				echo '<li>' . esc_html( (string) $p ) . '</li>';
			}
			echo '</ul>';
			if ( $e['keywords'] ) {
				echo '<div class="pnchat-keywords">' . esc_html( implode( ' · ', (array) $e['keywords'] ) ) . '</div>';
			}
			echo '</div><div><p class="description">Απάντηση</p><div class="pnchat-test-item">' . wp_kses( PNChat_Brain::render_answer( (string) $e['answer'] ), PNChat_Brain::allowed_html() ) . '</div></div></div></div>';
		}
		submit_button( 'Αποθήκευση επιλεγμένων' );
		echo '</form>';
	}

	/**
	 * Drafts one entry for a question.
	 *
	 * @return void
	 */
	public static function handle_ai_draft() {
		self::guard( 'pnchat_ai_draft' );
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
	 * Drafts entries from a page.
	 *
	 * @return void
	 */
	public static function handle_ai_page() {
		self::guard( 'pnchat_ai_page' );
		$id = absint( self::post( 'post_id' ) );
		$r  = PNChat_AI::drafts_from_page( $id );
		if ( is_wp_error( $r ) ) {
			self::back( 'pn-chat', 'ai_error', array( 'err' => $r->get_error_message() ) );
		}
		$key = self::ai_stash(
			array(
				'type'    => 'page',
				'title'   => get_the_title( $id ),
				'url'     => (string) get_permalink( $id ),
				'entries' => $r,
			)
		);
		self::back( 'pn-chat', '', array( 'ai_review' => $key ) );
	}

	/**
	 * Saves the drafts the admin ticked.
	 *
	 * @return void
	 */
	public static function handle_ai_save() {
		self::guard( 'pnchat_ai_save' );
		$d = self::ai_stash_get( self::post( 'key' ) );
		if ( ! $d || 'page' !== ( $d['type'] ?? '' ) ) {
			self::back( 'pn-chat', 'ai_expired' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked it.
		$pick = isset( $_POST['pick'] ) && is_array( $_POST['pick'] ) ? array_map( 'absint', wp_unslash( $_POST['pick'] ) ) : array();
		$n    = 0;
		$warn = array();
		foreach ( $pick as $i ) {
			if ( ! isset( $d['entries'][ $i ] ) ) {
				continue;
			}
			$e = PNChat_Brain::clean_entry( $d['entries'][ $i ] );
			if ( ! $e ) {
				continue;
			}
			$e['active'] = 1;
			$id          = PNChat_Store::save_entry( $e );
			if ( $id ) {
				++$n;
				$warn[ $id ] = $e['phrasings'];
			}
		}
		foreach ( $warn as $id => $phrasings ) {
			self::warn_conflicts( $id, $phrasings );
		}
		delete_transient( 'pnchat_ai_' . get_current_user_id() . '_' . sanitize_key( self::post( 'key' ) ) );
		self::back( 'pn-chat', 'ai_saved', array( 'n' => $n ) );
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
		submit_button( 'Αποθήκευση συνωνύμων', 'secondary' );
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
		$answer    = 'block' === $kind ? sanitize_textarea_field( self::post( 'answer' ) ) : wp_kses( self::post( 'answer' ), PNChat_Brain::allowed_html() );
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

		self::warn_conflicts( $saved, $phrasings );

		if ( $from && $saved ) {
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
		PNChat_Store::delete_entry( $id );
		self::back( 'block' === $e['kind'] ? 'pn-chat-blocks' : 'pn-chat', 'deleted' );
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
		PNChat_Store::save_entry( $e, $id );
		self::back( 'block' === $e['kind'] ? 'pn-chat-blocks' : 'pn-chat', 'toggled' );
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
		PNChat_Settings::save( PNChat_Settings::sanitize( $s ) );
		PNChat_Store::bump();
		self::back( 'pn-chat', 'synonyms' );
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
		echo '<p class="pnchat-intro">Όλες οι ερωτήσεις που έγιναν στο chat. Οι <strong>ανοιχτές</strong> (χωρίς απάντηση, με μερική απάντηση ή «δεν βοήθησε») περιμένουν εσάς: <strong>Εκπαίδευση</strong> για να μάθει ο βοηθός την απάντηση, <strong>Απάντηση με e-mail</strong> αν ο επισκέπτης άφησε e-mail, <strong>Απαγόρευση</strong> για ερωτήσεις που δεν πρέπει να απαντώνται.</p>';

		$filter = self::get( 'filter' );
		$filter = '' === $filter ? 'open' : $filter;
		$search = self::get( 's' );
		$paged  = max( 1, absint( self::get( 'paged' ) ) );
		$counts = PNChat_Store::question_counts();
		$tabs   = array(
			'open'       => 'Ανοιχτές',
			'email'      => 'Περιμένουν e-mail',
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
				$acts[] = '<a href="' . esc_url( admin_url( 'admin.php?page=pn-chat&new=1&from_question=' . $id ) ) . '"><strong>Εκπαίδευση</strong></a>';
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

		$sent = wp_mail( (string) $q['email'], '' !== $subject ? $subject : (string) PNChat_Settings::value( 'reply_subject' ), $body );
		if ( ! $sent ) {
			self::back( 'pn-chat-questions', 'mail_failed', array( 'reply' => $id ) );
		}
		PNChat_Store::update_question(
			$id,
			array(
				'status'     => 'replied',
				'reply'      => $body,
				'replied_at' => current_time( 'mysql', true ),
			)
		);
		self::back( 'pn-chat-questions', 'replied' );
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
			PNChat_Store::update_question( $id, array( 'status' => 'dismissed' ) );
			self::back( 'pn-chat-questions', 'dismissed', array( 'filter' => $filter ) );
		}
		if ( 'delete' === $do ) {
			PNChat_Store::delete_questions( array( $id ) );
			self::back( 'pn-chat-questions', 'q_deleted', array( 'n' => 1, 'filter' => $filter ) );
		}
		if ( 'add_to' === $do ) {
			$entry_id = absint( self::post( 'entry_id' ) );
			$parts    = PNChat_Store::lines( (string) $q['unmatched'] );
			$ok       = true;
			foreach ( $parts ? $parts : array( (string) $q['question'] ) as $p ) {
				$ok = PNChat_Store::add_phrasing( $entry_id, $p ) && $ok;
			}
			if ( ! $ok ) {
				self::back( 'pn-chat-questions', 'not_found' );
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
			PNChat_Store::delete_questions( $ids );
			self::back( 'pn-chat-questions', 'q_deleted', array( 'n' => count( $ids ), 'filter' => $filter ) );
		}
		if ( 'dismiss' === $bulk ) {
			foreach ( $ids as $id ) {
				PNChat_Store::update_question( $id, array( 'status' => 'dismissed' ) );
			}
			self::back( 'pn-chat-questions', 'q_dismissed', array( 'n' => count( $ids ), 'filter' => $filter ) );
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
			PNChat_Brain::import( $sn['data'], 'replace', true, false );
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
		$area( 'suggestions', 'Προτεινόμενες ερωτήσεις', 'Μία ανά γραμμή (έως 6). Εμφανίζονται ως κουμπιά στην αρχή.', 4 );
		$area( 'fallback', 'Όταν δεν ξέρει την απάντηση', 'Ακολουθεί φόρμα για το e-mail του επισκέπτη.' );
		$area( 'partial', 'Όταν ξέρει μόνο ένα μέρος', 'Το %s γίνεται το μέρος της ερώτησης χωρίς απάντηση.' );
		$area( 'unhelpful', 'Όταν πατηθεί 👎', 'Ακολουθεί φόρμα για το e-mail.' );
		$area( 'email_thanks', 'Μετά το e-mail', 'Το %s γίνεται το e-mail του επισκέπτη.' );
		$text( 'privacy_note', 'Σημείωση κάτω από το πεδίο', 'Προαιρετικό. Αφήστε κενό για να μη φαίνεται τίποτα.' );
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
		$number( 'rate_per_10min', 'Όριο ερωτήσεων', 'Ερωτήσεις ανά επισκέπτη ανά 10 λεπτά (προστασία από κατάχρηση).' );
		echo '</table>';

		echo '<h2>Αναζήτηση στο site</h2><table class="form-table" role="presentation">';
		$check( 'site_search', 'Όταν δεν ξέρει', 'Ψάξε στις σελίδες και τα άρθρα του site και δείξε τα πιο σχετικά (τίτλο, σχετική πρόταση και link)' );
		$text( 'site_types', 'Τι να ψάχνει', 'Τύποι περιεχομένου χωρισμένοι με κόμμα: post = άρθρα, page = σελίδες (π.χ. «post, page» ή και «product»).' );
		$area( 'site_exclude', 'Να μην ψάχνει σε', 'Σελίδες που δεν πρέπει να εμφανίζονται: μία ανά γραμμή, ID ή διεύθυνση (π.χ. /my-account/). Καλάθι, ταμείο και λογαριασμός WooCommerce εξαιρούνται αυτόματα, όπως και οι σελίδες με κωδικό.' );
		$number( 'site_max', 'Πόσες σελίδες', 'Το πολύ πόσες σελίδες δείχνει (1–5).' );
		$area( 'site_intro', 'Κείμενο πριν τις σελίδες' );
		$area( 'site_more', 'Κείμενο μετά τις σελίδες', 'Ακολουθεί φόρμα για το e-mail του επισκέπτη.' );
		echo '</table>';
		echo '<p>Ευρετήριο: <strong>' . (int) PNChat_Site_Search::count() . '</strong> σελίδες. Ενημερώνεται μόνο του όταν αποθηκεύετε μια σελίδα. <a class="button" href="' . esc_url( self::action_url( 'site_reindex', array() ) ) . '">Ενημέρωση τώρα</a></p>';

		echo '<h2 id="pnchat-ai">✨ AI βοηθός εκπαίδευσης (Claude)</h2>';
		echo '<p class="description">Μόνο για το wp-admin: το AI διαβάζει σελίδες του site και προτείνει γνώσεις, που εγκρίνετε εσείς. Το δημόσιο chat δεν χρησιμοποιεί AI. Στο Claude στέλνονται μόνο σελίδες του δημόσιου site και η ερώτηση που επιλέγετε· ποτέ e-mail επισκεπτών. Χρεώνεται ανά χρήση στον λογαριασμό σας στο console.anthropic.com.</p>';
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
		$text( 'ai_model', 'Μοντέλο', 'Προεπιλογή: ' . PNChat_AI::DEFAULT_MODEL . ' (Claude Opus 5.5).' );
		echo '<tr><th scope="row">Κόστος έως τώρα</th><td>' . esc_html( self::ai_cost_text() ) . '</td></tr>';
		echo '</table>';

		echo '<h2>E-mail</h2><table class="form-table" role="presentation">';
		$check( 'notify_on_email', 'Ειδοποίηση', 'Στείλε μου e-mail όταν ένας επισκέπτης αφήσει e-mail για απάντηση' );
		echo '<tr><th scope="row"><label for="pnchat-notify_email">E-mail ειδοποιήσεων</label></th><td><input id="pnchat-notify_email" name="notify_email" type="email" class="regular-text" value="' . esc_attr( (string) $s['notify_email'] ) . '"></td></tr>';
		$text( 'reply_subject', 'Θέμα απαντήσεων' );
		echo '</table>';

		echo '<h2>Απόρρητο</h2><table class="form-table" role="presentation">';
		$number( 'retention_days', 'Διατήρηση ερωτημάτων (ημέρες)', 'Τα ερωτήματα (και τα e-mail τους) σβήνονται αυτόματα μετά από τόσες ημέρες. 0 = ποτέ.' );
		$check( 'keep_on_uninstall', 'Απεγκατάσταση', 'Κράτα τον εγκέφαλο και τα ερωτήματα αν διαγραφεί το plugin' );
		echo '</table>';

		submit_button( 'Αποθήκευση ρυθμίσεων' );
		echo '</form></div>';
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
		// The synonyms are edited on the training screen.
		$in['synonyms'] = (string) $current['synonyms'];
		$clean          = PNChat_Settings::sanitize( $in );
		PNChat_Settings::save( $clean );
		$key = trim( self::post( 'ai_key' ) );
		if ( '1' === self::post( 'ai_key_delete' ) ) {
			delete_option( 'pnchat_ai_key' );
		} elseif ( '' !== $key && preg_match( '/^[A-Za-z0-9_\-]{20,300}$/', $key ) ) {
			update_option( 'pnchat_ai_key', $key, false );
		}
		if ( $clean['site_types'] !== $current['site_types'] || $clean['site_exclude'] !== $current['site_exclude'] ) {
			delete_post_meta_by_key( PNChat_Site_Search::META );
			PNChat_Site_Search::schedule();
		}
		PNChat_Store::bump();
		self::back( 'pn-chat-settings', 'settings' );
	}
}
