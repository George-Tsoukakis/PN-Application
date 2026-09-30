<?php
/**
 * Admin-side invoice handling: the upload, download and single-invoice
 * delete endpoints, upload validation, the invoice list markup and the
 * wp-admin notices.
 *
 * Disk access lives in Plandose_Invoice_Storage and the legacy Media Library
 * migration in Plandose_Invoice_Migration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Admin_Invoices {

	/**
	 * Upper sanity limit on how many invoices one pharmacy's list may hold.
	 *
	 * Invoices are tax records that the readme, the privacy text and
	 * handle_user_deleted() all promise to keep until an administrator
	 * deletes them, so nothing is ever evicted. This limit is a refusal
	 * instead: past this many (roughly forty
	 * years of monthly invoices) the upload is rejected with a message, and
	 * nothing already stored is touched. It only exists so a runaway script
	 * cannot grow the `invoices` JSON column without bound.
	 */
	const MAX_INVOICES_SANITY_LIMIT = 500;

	/**
	 * How many of the newest invoices render_invoice_links() shows before
	 * folding the rest into a collapsed «Παλαιότερα» list, so a
	 * pharmacy with years of invoices does not blow up the row.
	 */
	const INVOICE_LINKS_VISIBLE = 3;

	/**
	 * Prevents the invoice hooks from being registered more than once.
	 * Defensive, matching the other PlanDose classes (WordPress already
	 * de-duplicates identical static-method callbacks by unique id).
	 *
	 * @var bool
	 */
	private static $initialized = false;

	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss_nginx_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss_upload_size_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_invoice_error_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_invoice_warning_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_nginx_invoice_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_upload_size_notice' ) );
		add_action( 'admin_post_plandose_upload_invoice', array( __CLASS__, 'handle_upload_invoice' ) );
		add_action( 'admin_post_plandose_view_invoice', array( __CLASS__, 'handle_view_invoice' ) );
		add_action( 'admin_post_plandose_delete_invoice', array( __CLASS__, 'handle_delete_invoice' ) );
		add_action( 'admin_post_plandose_delete_deleted_account_invoices', array( __CLASS__, 'handle_delete_deleted_account_invoices' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_legacy_originals_notice' ) );
		add_action( 'admin_post_plandose_delete_legacy_originals', array( __CLASS__, 'handle_delete_legacy_originals' ) );
		add_action( 'admin_post_plandose_delete_legacy_original_uncopied', array( __CLASS__, 'handle_delete_legacy_original_uncopied' ) );
	}

	/**
	 * Non-autoloaded option listing the legacy Media Library
	 * attachments the invoice migration COPIED into protected storage.
	 *
	 * The migration copies, it does not move: the original stays in the
	 * Media Library under wp-content/uploads/YYYY/MM/ with a public URL,
	 * and its attachment ID is gone from the invoice list once the entry
	 * is rewritten. Each copied entry is recorded here so an administrator
	 * can remove
	 * the public originals with «Διαγραφή πρωτοτύπων»
	 * (handle_delete_legacy_originals()) — never automatically.
	 *
	 * Kept in this class, not in Plandose_Invoice_Migration, because the
	 * clean-up outlives the migration: that file can still be deleted once
	 * every site has migrated.
	 *
	 * Shape: array<int attachment_id, array{
	 *     user_id:int, filename:string, migrated_at:int,
	 *     extra?:array<int,array{user_id:int,filename:string}>,
	 *     skip?:string, checked_at?:int, uncopied?:int, removed_at?:int
	 * }> — uncopied (filename '') marks an original whose list entry
	 * was removed before any copy was made; extra holds further protected copies when the same attachment
	 * was listed by more than one pharmacy; skip is the reason code of the
	 * last refused deletion (see legacy_original_reason_label()).
	 */
	const LEGACY_ORIGINALS_OPTION = 'plandose_legacy_originals';

	/** Lock guarding read-modify-write of LEGACY_ORIGINALS_OPTION. */
	const LEGACY_ORIGINALS_LOCK = 'plandose_legacy_originals_lock';

	/** At most this many originals are checked per «Διαγραφή πρωτοτύπων». */
	const LEGACY_ORIGINALS_BATCH = 50;

	/** At most this many originals are listed in the notice. */
	const LEGACY_ORIGINALS_SHOWN = 25;

	public static function render_invoice_error_notice() {
		$admin_id = get_current_user_id();
		$message  = get_transient( 'plandose_invoice_error_' . $admin_id );

		if ( ! $message ) {
			return;
		}

		delete_transient( 'plandose_invoice_error_' . $admin_id );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * One-time warning after an upload accepted into storage that is
	 * not verified private (non-production environments).
	 */
	public static function render_invoice_warning_notice() {
		$admin_id = get_current_user_id();
		$message  = get_transient( 'plandose_invoice_warning_' . $admin_id );

		if ( ! $message ) {
			return;
		}

		delete_transient( 'plandose_invoice_warning_' . $admin_id );
		printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Storage privacy notice on the PlanDose screens, driven by the cached
	 * live probe (Plandose_Invoice_Storage::privacy_status(), never a probe
	 * on page load). Public or unverified storage in production means
	 * uploads are refused, so those notices cannot be dismissed; the
	 * dismissible variant remains only for non-production sites.
	 */
	public static function render_nginx_invoice_notice() {
		if ( ! self::on_plandose_screen() ) {
			return;
		}

		$state = Plandose_Invoice_Storage::privacy_status( false );

		if ( 'private' === $state['status'] ) {
			return;
		}

		// Never checked, and the path alone says the folder is outside the
		// web tree: the first upload will confirm it.
		if ( 'unknown' === $state['status'] && ! Plandose_Invoice_Storage::invoice_dir_is_public() ) {
			return;
		}

		$diagnostics = admin_url( 'admin.php?page=plandose-diagnostics' );
		$production  = Plandose_Invoice_Storage::is_production();
		$link        = sprintf( '<a href="%s">%s</a>', esc_url( $diagnostics ), esc_html__( 'PlanDose → Διαγνωστικά', 'plandose' ) );

		// One line for the problem, one for the fix, then the link.
		if ( 'public' === $state['status'] || ( $production && ! Plandose_Invoice_Storage::unverified_allowed() ) ) {
			if ( 'public' === $state['status'] ) {
				// Refused on every site, test sites included.
				$problem = __( 'PlanDose: ο φάκελος τιμολογίων είναι ανοιχτός στο internet, γι\' αυτό τα νέα τιμολόγια δεν αποθηκεύονται.', 'plandose' );
			} elseif ( 'unknown' === $state['status'] ) {
				$problem = __( 'PlanDose: δεν έχει ελεγχθεί ακόμη αν ο φάκελος τιμολογίων είναι κλειστός για το internet· μέχρι να επιβεβαιωθεί, τα νέα τιμολόγια δεν αποθηκεύονται.', 'plandose' );
			} else {
				$problem = sprintf(
					/* translators: %s: reason */
					__( 'PlanDose: δεν επιβεβαιώθηκε ότι ο φάκελος τιμολογίων είναι κλειστός για το internet (%s), γι\' αυτό τα νέα τιμολόγια δεν αποθηκεύονται.', 'plandose' ),
					Plandose_Invoice_Storage::privacy_reason_label( $state )
				);
			}

			$fix = 'unknown' === $state['status']
				? __( 'Πατήστε «Επανέλεγχος» στο PlanDose → Διαγνωστικά.', 'plandose' )
				: Plandose_Invoice_Storage::refusal_fix( $state );

			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong></p><p>%2$s %3$s</p><p>%4$s</p></div>',
				esc_html( $problem ),
				esc_html__( 'Λύση:', 'plandose' ),
				esc_html( $fix ),
				$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above with esc_url()/esc_html__().
			);
			return;
		}

		if ( get_user_meta( get_current_user_id(), 'plandose_dismissed_nginx_notice', true ) ) {
			return;
		}

		$message = $production
			? __( 'Η ιδιωτικότητα του φακέλου τιμολογίων δεν έχει επιβεβαιωθεί, αλλά τα uploads επιτρέπονται επειδή έχει οριστεί PLANDOSE_ALLOW_UNVERIFIED_INVOICE_DIR. Βεβαιωθείτε ότι ο web server αρνείται την απευθείας πρόσβαση στον φάκελο.', 'plandose' )
			: __( 'Η ιδιωτικότητα του φακέλου τιμολογίων δεν έχει επιβεβαιωθεί. Εδώ (μη παραγωγικό περιβάλλον) αυτό είναι μόνο προειδοποίηση· σε παραγωγή τα uploads θα απορρίπτονταν.', 'plandose' );

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'plandose_dismiss_nginx_notice', '1' ),
			'plandose_dismiss_nginx_notice'
		);

		printf(
			'<div class="notice notice-warning"><p>%s</p><p>%s · <a href="%s">%s</a></p></div>',
			esc_html( $message ),
			$link, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above with esc_url()/esc_html__().
			esc_url( $dismiss_url ),
			esc_html__( 'Το κατάλαβα, μη μου το ξαναδείξεις', 'plandose' )
		);
	}

	/**
	 * Handle the "dismiss" link from render_nginx_invoice_notice().
	 */
	public static function maybe_dismiss_nginx_notice() {
		if ( ! isset( $_GET['plandose_dismiss_nginx_notice'] ) ) {
			return;
		}

		if ( ! current_user_can( Plandose_Admin::capability() ) ) {
			return;
		}

		check_admin_referer( 'plandose_dismiss_nginx_notice' );

		update_user_meta( get_current_user_id(), 'plandose_dismissed_nginx_notice', 1 );

		wp_safe_redirect( remove_query_arg( array( 'plandose_dismiss_nginx_notice', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Warn on the PlanDose admin screens if the configured invoice upload
	 * limit (Settings → Τιμολόγια → "Μέγιστο μέγεθος upload") is larger
	 * than what the server's own PHP configuration will actually accept.
	 *
	 * Without this, an admin can raise `invoice_max_mb` to e.g. 20MB on a
	 * host whose `upload_max_filesize`/`post_max_size` caps uploads at 2MB,
	 * and every upload attempt near that new "limit" will fail with a
	 * generic PHP-level upload error — validate_invoice_upload() never even
	 * gets to run, so the plugin's own error messaging can't explain why.
	 * wp_max_upload_size() is WordPress core's own helper for exactly this:
	 * it already reconciles upload_max_filesize, post_max_size, and any
	 * site-specific filters into the one real effective ceiling.
	 */
	public static function render_upload_size_notice() {
		if ( ! self::on_plandose_screen() ) {
			return;
		}

		$admin_id = get_current_user_id();

		if ( get_user_meta( $admin_id, 'plandose_dismissed_upload_size_notice_' . Plandose_Settings::setting( 'invoice_max_mb', 5 ), true ) ) {
			return;
		}

		$configured_bytes = Plandose_Settings::invoice_max_bytes();
		$server_bytes     = wp_max_upload_size();

		if ( $server_bytes <= 0 || $configured_bytes <= $server_bytes ) {
			return;
		}

		$configured_mb = round( $configured_bytes / ( 1024 * 1024 ), 1 );
		$server_mb     = round( $server_bytes / ( 1024 * 1024 ), 1 );

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'plandose_dismiss_upload_size_notice', '1' ),
			'plandose_dismiss_upload_size_notice'
		);

		printf(
			'<div class="notice notice-warning"><p>%s</p><p><a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: 1: configured limit in MB, 2: actual server limit in MB */
					__( 'Το όριο μεγέθους τιμολογίου στις ρυθμίσεις είναι %1$s MB, αλλά ο server δέχεται μέχρι %2$s MB (βάσει upload_max_filesize/post_max_size). Uploads μεγαλύτερα από %2$s MB θα αποτυγχάνουν σιωπηλά στο επίπεδο του PHP, πριν καν φτάσουν στον έλεγχο του PlanDose — είτε μειώστε το όριο στις ρυθμίσεις στα %2$s MB ή λιγότερο, είτε ζητήστε από τον πάροχο φιλοξενίας να ανεβάσει τα php.ini όρια.', 'plandose' ),
					$configured_mb,
					$server_mb
				)
			),
			esc_url( $dismiss_url ),
			esc_html__( 'Το κατάλαβα, μη μου το ξαναδείξεις', 'plandose' )
		);
	}

	/**
	 * Handle the "dismiss" link from render_upload_size_notice(). Keyed by
	 * the configured MB value at dismiss time, so raising the setting again
	 * later (a genuinely new mismatch) surfaces the notice again instead of
	 * staying silently dismissed forever.
	 */
	public static function maybe_dismiss_upload_size_notice() {
		if ( ! isset( $_GET['plandose_dismiss_upload_size_notice'] ) ) {
			return;
		}

		if ( ! current_user_can( Plandose_Admin::capability() ) ) {
			return;
		}

		check_admin_referer( 'plandose_dismiss_upload_size_notice' );

		update_user_meta(
			get_current_user_id(),
			'plandose_dismissed_upload_size_notice_' . Plandose_Settings::setting( 'invoice_max_mb', 5 ),
			1
		);

		wp_safe_redirect( remove_query_arg( array( 'plandose_dismiss_upload_size_notice', '_wpnonce' ) ) );
		exit;
	}

	public static function on_plandose_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || empty( $screen->id ) ) {
			return false;
		}

		// Match the canonical PlanDose screen IDs exactly, rather than any
		// screen whose id merely contains the substring "plandose" (which
		// could match an unrelated plugin's page). Mirrors the same tightening
		// already used by Plandose_Admin::admin_assets().
		if (
			! class_exists( 'Plandose_Admin' )
			|| ! method_exists( 'Plandose_Admin', 'admin_screen_ids' )
			|| ! in_array( (string) $screen->id, Plandose_Admin::admin_screen_ids(), true )
		) {
			return false;
		}

		return current_user_can( Plandose_Admin::capability() );
	}

	/**
	 * Subscription status/Pro days/invoices are managed entirely from the
	 * PlanDose → Συνδρομές dashboard — intentionally not on the WordPress
	 * Users → Edit User screen, so there's exactly one place to manage
	 * subscriptions instead of two that can drift out of sync.
	 */
	public static function handle_upload_invoice() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Μη επιτρεπτή μέθοδος αιτήματος.', 'plandose' ), '', array( 'response' => 405 ) );
		}

		if ( ! current_user_can( Plandose_Admin::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'plandose_upload_invoice' );

		$user_id = isset( $_POST['user_id'] ) ? self::parse_user_id( wp_unslash( $_POST['user_id'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by parse_user_id() (digits only).

		// Plandose_Admin::can_manage_account() — edit_user, and
		// never one's own or an administrator's account unless one is an
		// administrator.
		if ( $user_id && ! Plandose_Admin::can_manage_account( $user_id ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα να επεξεργαστείτε αυτόν τον χρήστη.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), __( 'Ο χρήστης δεν βρέθηκε.', 'plandose' ), 60 );
		} elseif ( empty( $_FILES['plandose_invoice_file'] ) || ! is_array( $_FILES['plandose_invoice_file'] ) ) {
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), __( 'Δεν επιλέχθηκε αρχείο τιμολογίου.', 'plandose' ), 60 );
		} else {
			self::handle_invoice_upload( $user_id );
		}

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=plandose-subscriptions' ) );
		exit;
	}

	private static function handle_invoice_upload( $user_id ) {
		// PlanDose subscribers are «Φαρμακείο» accounts only. Without
		// this, uploading an invoice for any other account would create a
		// subscription row for it (get_row() below creates one on demand).
		if ( ! Plandose_Access::is_registered_pharmacist( $user_id ) ) {
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), __( 'Ο χρήστης δεν έχει Κατηγορία Επιχείρησης «Φαρμακείο». Ορίστε πρώτα την κατηγορία στο προφίλ του.', 'plandose' ), 60 );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified via check_admin_referer( 'plandose_upload_invoice' ) in the caller, which also confirms this $_FILES entry exists before calling; the upload is validated by validate_invoice_upload() and WordPress upload APIs.
		$file_error = self::validate_invoice_upload( $_FILES['plandose_invoice_file'] );

		if ( is_wp_error( $file_error ) ) {
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), $file_error->get_error_message(), 60 );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in the caller (check_admin_referer); entry existence confirmed there too. The tmp_name/name are checked with wp_check_filetype_and_ext() and sanitize_file_name() below.
		$file     = $_FILES['plandose_invoice_file'];
		// The same (sanitized) name validate_invoice_upload() approved
		// decides the stored extension — the raw «invoice.pdf.» passes
		// validation as «invoice.pdf» but would be stored with none.
		$checked  = sanitize_file_name( wp_unslash( (string) $file['name'] ) );
		$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $checked );
		$ext      = ! empty( $filetype['ext'] ) ? Plandose_Invoice_Storage::clean_extension( $filetype['ext'] ) : '';

		// validate_invoice_upload() already refused a file without a
		// recognised extension; never store one without it.
		if ( '' === $ext ) {
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), __( 'Μη επιτρεπτός τύπος αρχείου τιμολογίου.', 'plandose' ), 60 );
			return;
		}

		$dir = Plandose_Invoice_Storage::invoice_dir();

		if ( is_wp_error( $dir ) ) {
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), $dir->get_error_message(), 60 );
			return;
		}

		// In production, only into storage verified private.
		$allowed = Plandose_Invoice_Storage::upload_allowed();

		if ( is_wp_error( $allowed ) ) {
			Plandose_Admin::audit( 'invoice_upload_refused_storage', $user_id );
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), $allowed->get_error_message(), 60 );
			return;
		}

		$filename = wp_generate_password( 32, false, false ) . ( $ext ? '.' . $ext : '' );
		$filename = wp_unique_filename( $dir, $filename );
		$target   = trailingslashit( $dir ) . $filename;

		// move_uploaded_file() (not WP_Filesystem) is deliberate here: it's
		// the only PHP API that validates $file['tmp_name'] genuinely came
		// from this request's multipart upload (guarded by is_uploaded_file()
		// just before it) rather than being an arbitrary path — the same
		// pairing WordPress core itself uses in wp_handle_upload(). The
		// warning it can emit on failure is what's suppressed below; the
		// failure itself is still caught and logged via log_fs_error().
		if ( ! is_uploaded_file( $file['tmp_name'] ) || ! @move_uploaded_file( $file['tmp_name'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, Generic.PHP.ForbiddenFunctions.Found -- Upload is validated with is_uploaded_file() first; moved into a protected non-web directory.
			Plandose_Invoice_Storage::log_fs_error( 'Saving uploaded invoice file', $target );
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), __( 'Αποτυχία αποθήκευσης του αρχείου τιμολογίου.', 'plandose' ), 60 );
			return;
		}

		Plandose_Invoice_Storage::fs_chmod( $target, 0640, 'chmod on uploaded invoice file' );

		/*
		 * The invoice list is read, extended and written back as a
		 * whole, so two uploads for the same pharmacy at the same moment
		 * (two admins, or a double-submitted form) could each read the old
		 * list and the second write would drop the first file — left on
		 * disk, unknown to the database. The read-modify-write therefore
		 * runs under a per-pharmacy lock.
		 */
		$lock = self::acquire_invoice_list_lock( $user_id );

		if ( false === $lock ) {
			Plandose_Invoice_Storage::fs_delete( $target, 'Rolling back uploaded invoice file: invoice list busy' );
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), __( 'Γίνεται ήδη άλλη αλλαγή στα τιμολόγια αυτού του φαρμακείου. Δοκιμάστε ξανά σε λίγα δευτερόλεπτα.', 'plandose' ), 60 );
			return;
		}

		$over_limit = false;
		$updated    = false;

		try {
			$row      = Plandose_Subscriptions::get_row( $user_id );
			$invoices = Plandose_Subscriptions::decode_invoices( $row ? $row->invoices : '' );

			// Refuse, never evict — see MAX_INVOICES_SANITY_LIMIT.
			if ( count( $invoices ) >= self::MAX_INVOICES_SANITY_LIMIT ) {
				$over_limit = true;
			} else {
				$invoices[] = $filename;
				$updated    = Plandose_Subscriptions::update( $user_id, array( 'invoices' => wp_json_encode( $invoices ) ) );
			}
		} finally {
			self::release_invoice_list_lock( $lock );
		}

		if ( $over_limit ) {
			Plandose_Invoice_Storage::fs_delete( $target, 'Rolling back uploaded invoice file: invoice limit reached' );
			set_transient(
				'plandose_invoice_error_' . get_current_user_id(),
				sprintf(
					/* translators: %d: maximum number of invoices per pharmacy */
					__( 'Το φαρμακείο έχει ήδη %d τιμολόγια, το ανώτατο όριο. Το νέο αρχείο δεν αποθηκεύτηκε και κανένα υπάρχον τιμολόγιο δεν διαγράφηκε. Διαγράψτε χειροκίνητα όσα δεν χρειάζεστε και δοκιμάστε ξανά.', 'plandose' ),
					self::MAX_INVOICES_SANITY_LIMIT
				),
				60
			);
			return;
		}

		if ( ! $updated ) {
			// DB write failed: roll back the just-uploaded file rather than
			// leaving it as an orphan the database never learned about.
			Plandose_Invoice_Storage::fs_delete( $target, 'Rolling back uploaded invoice file after DB update failure' );
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), __( 'Το αρχείο δεν καταχωρήθηκε λόγω σφάλματος βάσης δεδομένων. Δοκιμάστε ξανά.', 'plandose' ), 60 );
			return;
		}

		Plandose_Admin::audit( 'invoice_upload', $user_id, array( 'file' => $filename ) );

		$warning = Plandose_Invoice_Storage::storage_warning();

		if ( '' !== $warning ) {
			set_transient( 'plandose_invoice_warning_' . get_current_user_id(), $warning, 60 );
		}
	}

	/**
	 * How long an invoice-list lock may be held before another request may
	 * take it over (a holder that died mid-request). The critical section
	 * is one SELECT and one UPDATE, so this is generous.
	 */
	const INVOICE_LOCK_TTL = 30;

	/**
	 * Take the per-pharmacy lock that guards the invoice list, waiting up
	 * to about five seconds for a concurrent upload to finish.
	 *
	 * Built on Plandose_Lock (INSERT IGNORE / compare-and-swap), the same
	 * primitive as the print debounce, so exactly one request can hold it.
	 *
	 * Public, because the legacy invoice migration takes the same
	 * lock before it rewrites a pharmacy's list (its own global lock alone
	 * would let an upload landing during a migration batch be
	 * overwritten). Returns the value it claimed with, so the release
	 * deletes the row only while this request still owns it.
	 *
	 * @param int $user_id Pharmacy user ID.
	 * @param int $tries   Attempts, 200 ms apart (default ~5 s).
	 * @return array{name:string,value:int}|false Pass to
	 *         release_invoice_list_lock(), or false if it could not be taken.
	 */
	public static function acquire_invoice_list_lock( $user_id, $tries = 25 ) {
		return self::acquire_named_lock( 'plandose_invoice_lock_' . absint( $user_id ), $tries );
	}

	/**
	 * The body of acquire_invoice_list_lock(), for any short critical
	 * section (also the LEGACY_ORIGINALS_LOCK). Same TTL.
	 *
	 * @param string $name  Lock option name.
	 * @param int    $tries Attempts, 200 ms apart.
	 * @return array{name:string,value:int}|false
	 */
	private static function acquire_named_lock( $name, $tries = 25 ) {
		for ( $attempt = 0; $attempt < max( 1, (int) $tries ); $attempt++ ) {
			$now = time();

			if ( Plandose_Lock::claim( $name, $now ) ) {
				return array(
					'name'  => $name,
					'value' => $now,
				);
			}

			// Read straight from the database: the lock row is written
			// behind WordPress's back, so a cached read — the row's own
			// cache entry, or a 'notoptions' entry saying it does not
			// exist — could be stale.
			wp_cache_delete( $name, 'options' );
			$notoptions = wp_cache_get( 'notoptions', 'options' );

			if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
				unset( $notoptions[ $name ] );
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}

			$held_since = absint( get_option( $name ) );

			if ( $held_since > 0 && ( $now - $held_since ) >= self::INVOICE_LOCK_TTL ) {
				if ( Plandose_Lock::claim_if_unchanged( $name, $held_since, $now ) ) {
					return array(
						'name'  => $name,
						'value' => $now,
					);
				}
			}

			usleep( 200000 );
		}

		return false;
	}

	/**
	 * Release a lock taken by acquire_invoice_list_lock(), only if this
	 * request still owns it.
	 *
	 * @param array|false $lock Return value of acquire_invoice_list_lock().
	 */
	public static function release_invoice_list_lock( $lock ) {
		if ( is_array( $lock ) && isset( $lock['name'], $lock['value'] ) ) {
			Plandose_Lock::release_if_owned( $lock['name'], $lock['value'] );
		}
	}

	/**
	 * Stream an invoice file to an authorized admin. This is the only way
	 * invoice files are ever served — never a direct/public URL.
	 */
	public static function handle_view_invoice() {
		if ( ! current_user_can( Plandose_Admin::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'plandose_view_invoice' );

		$user_id = isset( $_GET['user_id'] ) ? self::parse_user_id( wp_unslash( $_GET['user_id'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by parse_user_id() (digits only).

		// Not absint(): it turns "-1" (and "1abc", "../1") into a valid
		// index, so a malformed link would open some other invoice than
		// the one it named. Only a plain run of digits is accepted.
		$index = isset( $_GET['i'] ) ? self::parse_index( wp_unslash( $_GET['i'] ) ) : -1; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by parse_index() (digits only).

		if ( $index < 0 ) {
			wp_die( esc_html__( 'Το τιμολόγιο δεν βρέθηκε.', 'plandose' ), '', array( 'response' => 400 ) );
		}

		// Invoices carry another user's personal/business data (AFM, phone,
		// pharmacy identity), so viewing one requires being allowed to edit
		// that specific user, not just the general PlanDose capability
		// (the same rule as changing it, can_manage_account()).
		if ( $user_id && ! Plandose_Admin::can_manage_account( $user_id ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα να δείτε τα τιμολόγια αυτού του χρήστη.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		$row      = $user_id ? Plandose_Subscriptions::get_row( $user_id, false ) : null;
		$invoices = $row ? Plandose_Subscriptions::decode_invoices( $row->invoices ) : array();

		if ( ! $invoices || ! isset( $invoices[ $index ] ) ) {
			wp_die( esc_html__( 'Το τιμολόγιο δεν βρέθηκε.', 'plandose' ), '', array( 'response' => 404 ) );
		}

		$entry = $invoices[ $index ];

		if ( is_numeric( $entry ) ) {
			// Legacy entry that maybe_migrate_legacy_invoices() hasn't
			// converted yet (e.g. migration hasn't run on this site, or the
			// underlying attachment was already missing when it ran): an
			// old Media Library attachment ID. Still only ever served
			// through this gated endpoint, never by linking the public
			// attachment URL. Only a real attachment: get_attached_file()
			// reads _wp_attached_file of ANY post ID.
			$path = 'attachment' === get_post_type( (int) $entry ) ? get_attached_file( (int) $entry ) : '';
		} else {
			$path = Plandose_Invoice_Storage::invoice_path( $entry );

			if ( is_wp_error( $path ) ) {
				wp_die( esc_html( $path->get_error_message() ) );
			}
		}

		if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Το αρχείο δεν βρέθηκε στον server.', 'plandose' ) );
		}

		$filetype = wp_check_filetype( $path );
		$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : '';

		if ( '' === $mime || ! in_array( $mime, Plandose_Settings::allowed_invoice_mimes(), true ) ) {
			$mime = 'application/octet-stream';
		}

		$download_name = sanitize_file_name( basename( $path ) );

		// Build an ASCII-only fallback for the legacy filename="" parameter.
		// Non-ASCII bytes (e.g. Greek characters in a migrated attachment's
		// original name) and characters that would break the quoted string
		// are replaced. Modern browsers use the RFC 5987 filename*= parameter
		// below, which carries the full UTF-8 name.
		$ascii_name = preg_replace( '/[^\x20-\x7E]/', '_', $download_name );
		$ascii_name = str_replace( array( '"', '\\' ), '_', (string) $ascii_name );

		if ( '' === $ascii_name ) {
			$ascii_name = 'invoice';
		}

		// Headers must not have been flushed already; otherwise the download
		// would be corrupted by preceding output.
		if ( headers_sent() ) {
			wp_die( esc_html__( 'Δεν είναι δυνατή η έναρξη της λήψης του αρχείου.', 'plandose' ) );
		}

		// Opened before any download header goes out: a file that cannot be
		// read then gets a normal error page, not a truncated «download».
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- local file streamed in chunks; a failed open is handled below.

		if ( false === $handle ) {
			Plandose_Invoice_Storage::log_fs_error( 'Opening invoice file for download', $path );
			wp_die( esc_html__( 'Το αρχείο δεν ήταν δυνατό να διαβαστεί.', 'plandose' ) );
		}

		// zlib output compression would make Content-Length wrong
		// and buffer the whole file; a large file must not hit the time limit.
		if ( function_exists( 'ini_get' ) && ini_get( 'zlib.output_compression' ) ) {
			@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky -- Download only; may be disallowed by the host.
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- May be disabled by the host; harmless then.
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header(
			'Content-Disposition: attachment; filename="' . $ascii_name . '"; filename*=UTF-8\'\'' . rawurlencode( $download_name )
		);
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );

		$file_size = filesize( $path );

		if ( false !== $file_size ) {
			header( 'Content-Length: ' . (string) $file_size );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( function_exists( 'session_write_close' ) ) {
			@session_write_close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- no active session is normal; this only releases the session lock.
		}

		// Log the view only once the file has actually been opened for reading,
		// so a failed fopen() above doesn't leave an audit entry claiming an
		// invoice was viewed when it never opened.
		Plandose_Admin::audit(
			'invoice_view',
			$user_id,
			array(
				'index' => $index,
				'file'  => is_numeric( $entry ) ? 'attachment:' . (int) $entry : basename( (string) $entry ),
			)
		);

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 1024 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- chunked streaming of a local file; WP_Filesystem cannot stream.

			if ( false === $chunk ) {
				Plandose_Invoice_Storage::log_fs_error( 'Reading invoice file for download', $path );
				break;
			}

			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw bytes of a file download (attachment), not HTML.
			flush();

			if ( connection_aborted() ) {
				break;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the streamed local file handle opened above.
		exit;
	}

	/**
	 * Parse an invoice index from a request parameter.
	 *
	 * absint() is wrong for this: absint( '-1' ) is 1 and absint( '2abc' )
	 * is 2, so a malformed value would silently select a different invoice. Only
	 * a non-empty run of ASCII digits (no sign, no spaces, no leading "+")
	 * of sane length is accepted.
	 *
	 * @param mixed $raw Unslashed request value.
	 * @return int The index, or -1 when the value is not a valid index.
	 */
	public static function parse_index( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 6 || ! ctype_digit( $raw ) ) {
			return -1;
		}

		return (int) $raw;
	}

	/**
	 * The same strictness for the user_id parameter. absint()
	 * reads "4/../5" or "4abc" as user 4; such a value here selects nobody
	 * (0), which every handler already refuses.
	 *
	 * @param mixed $raw Unslashed request value.
	 * @return int User ID, or 0 when the value is not a plain positive integer.
	 */
	public static function parse_user_id( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 19 || ! ctype_digit( $raw ) ) {
			return 0;
		}

		return absint( $raw );
	}

	/**
	 * A short fingerprint of one invoice entry, sent along with its
	 * index by the delete button. Indexes shift when another invoice is
	 * removed, so a stale page (or a second admin) must not be able to
	 * delete whatever invoice has meanwhile moved into that slot.
	 *
	 * @param string|int $entry Invoice list entry (filename or legacy ID).
	 * @return string
	 */
	private static function entry_fingerprint( $entry ) {
		return substr( hash( 'sha256', (string) $entry ), 0, 16 );
	}

	/**
	 * Manually delete ONE invoice of an existing pharmacy.
	 *
	 * Nothing evicts invoices automatically, so this is how an
	 * administrator removes an invoice that should not be kept (a wrong
	 * upload, a duplicate). POST only, PlanDose capability plus
	 * can_manage_account() for that pharmacy, a nonce bound to the
	 * pharmacy, and the entry's fingerprint so the index cannot point at a
	 * different invoice than the one the admin clicked. Always audited.
	 *
	 * Deleted accounts are not handled here: their invoices are removed as a
	 * whole by handle_delete_deleted_account_invoices().
	 *
	 * As everywhere, the database changes first and the file is removed
	 * only once the list no longer names it; a legacy Media Library entry
	 * (numeric ID) is only dropped from the list — the attachment is not
	 * deleted here, but it is recorded on the
	 * «πρωτότυπα» list, where an administrator can remove it explicitly.
	 */
	public static function handle_delete_invoice() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Μη επιτρεπτή μέθοδος αιτήματος.', 'plandose' ), '', array( 'response' => 405 ) );
		}

		if ( ! current_user_can( Plandose_Admin::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? self::parse_user_id( wp_unslash( $_POST['user_id'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by parse_user_id() (digits only).

		check_admin_referer( 'plandose_delete_invoice_' . $user_id );

		if ( ! $user_id || ! get_userdata( $user_id ) || ! Plandose_Admin::can_manage_account( $user_id ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα να επεξεργαστείτε αυτόν τον χρήστη.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		$index       = isset( $_POST['i'] ) ? self::parse_index( wp_unslash( $_POST['i'] ) ) : -1; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by parse_index() (digits only).
		$fingerprint = isset( $_POST['f'] ) ? sanitize_key( wp_unslash( $_POST['f'] ) ) : '';
		$error_key   = 'plandose_invoice_error_' . get_current_user_id();
		$referer     = wp_get_referer();
		$back        = $referer ? $referer : admin_url( 'admin.php?page=plandose-subscriptions' );

		$dir = Plandose_Invoice_Storage::invoice_dir();

		if ( is_wp_error( $dir ) ) {
			set_transient( $error_key, $dir->get_error_message(), 60 );
			wp_safe_redirect( $back );
			exit;
		}

		$lock = self::acquire_invoice_list_lock( $user_id );

		if ( false === $lock ) {
			set_transient( $error_key, __( 'Γίνεται ήδη άλλη αλλαγή στα τιμολόγια αυτού του φαρμακείου. Δοκιμάστε ξανά σε λίγα δευτερόλεπτα.', 'plandose' ), 60 );
			wp_safe_redirect( $back );
			exit;
		}

		$removed = null;
		$updated = false;

		try {
			$row      = Plandose_Subscriptions::get_row( $user_id, false );
			$invoices = $row ? Plandose_Subscriptions::decode_invoices( $row->invoices ) : array();

			if ( $index >= 0 && isset( $invoices[ $index ] ) && '' !== $fingerprint && hash_equals( self::entry_fingerprint( $invoices[ $index ] ), $fingerprint ) ) {
				$removed = $invoices[ $index ];
				unset( $invoices[ $index ] );
				$updated = (bool) Plandose_Subscriptions::update( $user_id, array( 'invoices' => wp_json_encode( array_values( $invoices ) ) ) );
			}
		} finally {
			self::release_invoice_list_lock( $lock );
		}

		if ( null === $removed ) {
			set_transient( $error_key, __( 'Το τιμολόγιο δεν βρέθηκε — ίσως άλλαξε η λίστα στο μεταξύ. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.', 'plandose' ), 60 );
			wp_safe_redirect( $back );
			exit;
		}

		if ( ! $updated ) {
			set_transient( $error_key, __( 'Το τιμολόγιο δεν διαγράφηκε λόγω σφάλματος βάσης δεδομένων. Δοκιμάστε ξανά.', 'plandose' ), 60 );
			wp_safe_redirect( $back );
			exit;
		}

		// The same entry could in theory be listed twice (a hand-edited
		// row); its file goes only when nothing references it any more.
		$still_listed = in_array( $removed, $invoices, true );

		if ( ! $still_listed ) {
			if ( is_numeric( $removed ) ) {
				// The public Media Library original stays; keep it on
				// the «πρωτότυπα» list so it can still be removed.
				self::record_uncopied_legacy_original( (int) $removed, $user_id );
			} else {
				Plandose_Invoice_Storage::delete_invoice_files( array( $removed ), $dir );
				self::mark_legacy_copy_removed( (string) $removed );
			}
		}

		Plandose_Admin::audit(
			'invoice_delete',
			$user_id,
			array(
				'index'  => $index,
				'entry'  => is_numeric( $removed ) ? 'attachment:' . (int) $removed : (string) $removed,
				'remain' => count( $invoices ),
			)
		);

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Markup for a pharmacy's invoice list: newest first, the
	 * newest INVOICE_LINKS_VISIBLE as buttons and the rest folded into a
	 * <details> «Παλαιότερα (N)», each with a view link and — for an
	 * existing pharmacy the current admin may manage — a delete button.
	 *
	 * Public so the Subscriptions screen can use it for both the live rows
	 * and the «Τιμολόγια διαγραμμένων λογαριασμών» card. Indexes shown to
	 * the user are 1-based in upload order («Τιμολόγιο 14» is the 14th
	 * uploaded), so numbers stay stable while the list grows.
	 *
	 * @param int   $user_id  Pharmacy user ID.
	 * @param array $invoices Decoded invoice list (Plandose_Subscriptions::decode_invoices()).
	 * @param array $args     { @type bool $allow_delete Show delete buttons (default true;
	 *                        forced off for deleted accounts or without permission). }
	 */
	public static function render_invoice_links( $user_id, array $invoices, array $args = array() ) {
		$user_id  = absint( $user_id );
		$invoices = array_values( $invoices );

		if ( ! $user_id || empty( $invoices ) ) {
			return;
		}

		$allow_delete = ( ! isset( $args['allow_delete'] ) || $args['allow_delete'] )
			&& get_userdata( $user_id )
			&& Plandose_Admin::can_manage_account( $user_id );

		$indexes = array_reverse( array_keys( $invoices ) );
		$visible = array_slice( $indexes, 0, self::INVOICE_LINKS_VISIBLE );
		$older   = array_slice( $indexes, self::INVOICE_LINKS_VISIBLE );

		echo '<span class="pd-invoice-links">';

		foreach ( $visible as $index ) {
			self::render_invoice_link_item( $user_id, $index, $invoices[ $index ], $allow_delete );
		}

		if ( $older ) {
			printf(
				'<details class="pd-invoice-older"><summary>%s</summary>',
				esc_html(
					sprintf(
						/* translators: %d: number of older invoices */
						__( 'Παλαιότερα (%d)', 'plandose' ),
						count( $older )
					)
				)
			);

			foreach ( $older as $index ) {
				self::render_invoice_link_item( $user_id, $index, $invoices[ $index ], $allow_delete );
			}

			echo '</details>';
		}

		echo '</span>';
	}

	/**
	 * One view link (and optional delete form) for render_invoice_links().
	 */
	private static function render_invoice_link_item( $user_id, $index, $entry, $allow_delete ) {
		$view_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'plandose_view_invoice',
					'user_id' => $user_id,
					'i'       => $index,
				),
				admin_url( 'admin-post.php' )
			),
			'plandose_view_invoice'
		);

		/* translators: %d: invoice number in upload order, starting at 1 */
		$label = sprintf( __( 'Τιμολόγιο %d', 'plandose' ), $index + 1 );
		?>
		<span class="pd-invoice-item">
			<a class="button button-small" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $label ); ?></a>
			<?php if ( $allow_delete ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pd-inline-form pd-invoice-delete-form pd-confirm-submit" data-confirm="<?php
					/* translators: %s: invoice label, e.g. "Τιμολόγιο 3" */
					echo esc_attr( sprintf( __( 'Οριστική διαγραφή: «%s»; Το αρχείο σβήνεται από τον server και η ενέργεια δεν αναιρείται.', 'plandose' ), $label ) );
				?>">
					<input type="hidden" name="action" value="plandose_delete_invoice" />
					<input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>" />
					<input type="hidden" name="i" value="<?php echo esc_attr( $index ); ?>" />
					<input type="hidden" name="f" value="<?php echo esc_attr( self::entry_fingerprint( $entry ) ); ?>" />
					<?php wp_nonce_field( 'plandose_delete_invoice_' . $user_id ); ?>
					<button type="submit" class="button-link button-link-delete" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: invoice label */ __( 'Διαγραφή: %s', 'plandose' ), $label ) ); ?>">&times;</button>
				</form>
			<?php endif; ?>
		</span>
		<?php
	}

	/**
	 * Permanently delete the retained invoices of a DELETED account.
	 *
	 * Together with handle_delete_invoice() (one invoice of an
	 * existing pharmacy), the only place invoices are ever deleted on
	 * purpose — never automatically. Refuses outright
	 * for an account that still exists, so it can never be pointed at a
	 * live pharmacy's invoices. Removes the files, the retired subscription
	 * row that listed them, and the identity snapshot kept alongside.
	 */
	public static function handle_delete_deleted_account_invoices() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Μη επιτρεπτή μέθοδος αιτήματος.', 'plandose' ), '', array( 'response' => 405 ) );
		}

		if ( ! current_user_can( Plandose_Admin::capability() ) || ! current_user_can( 'delete_users' ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? self::parse_user_id( wp_unslash( $_POST['user_id'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by parse_user_id() (digits only).

		check_admin_referer( 'plandose_delete_deleted_account_invoices_' . $user_id );

		$back = admin_url( 'admin.php?page=plandose-subscriptions' );

		if ( ! $user_id || get_userdata( $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'plandose_result', 'invalid_user', $back ) );
			exit;
		}

		$dir = Plandose_Invoice_Storage::invoice_dir();

		if ( is_wp_error( $dir ) ) {
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), $dir->get_error_message(), 60 );
			wp_safe_redirect( $back );
			exit;
		}

		// Read and delete under the invoice-list lock, like every
		// other writer of this row (e.g. a migration batch rewriting it).
		$lock = self::acquire_invoice_list_lock( $user_id );

		if ( false === $lock ) {
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), __( 'Γίνεται ήδη άλλη αλλαγή στα τιμολόγια αυτού του φαρμακείου. Δοκιμάστε ξανά σε λίγα δευτερόλεπτα.', 'plandose' ), 60 );
			wp_safe_redirect( $back );
			exit;
		}

		$deleted = false;

		try {
			$row      = Plandose_Subscriptions::get_row( $user_id, false );
			$invoices = $row ? Plandose_Subscriptions::decode_invoices( $row->invoices ) : array();

			if ( $invoices ) {
				$deleted = false !== Plandose_Subscriptions::delete_row( $user_id );
			}
		} finally {
			self::release_invoice_list_lock( $lock );
		}

		if ( ! $invoices ) {
			wp_safe_redirect( add_query_arg( 'plandose_result', 'invalid_user', $back ) );
			exit;
		}

		// The database row goes FIRST, and the files only once it is
		// gone. The other order would leave a row listing files that no
		// longer exist whenever the row delete fails. A file left behind by a failed file delete is at worst
		// an orphan that tools/find-orphan-invoices.php can find.
		// false is a database error; 0 means the row was already gone (a
		// concurrent erasure or a double submit) — carry on with the files.
		if ( ! $deleted ) {
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), __( 'Τα τιμολόγια δεν διαγράφηκαν λόγω σφάλματος βάσης δεδομένων. Δοκιμάστε ξανά.', 'plandose' ), 60 );
			wp_safe_redirect( $back );
			exit;
		}

		Plandose_Invoice_Storage::delete_invoice_files( $invoices, $dir );

		foreach ( $invoices as $entry ) {
			if ( is_numeric( $entry ) ) {
				self::record_uncopied_legacy_original( (int) $entry, $user_id );
			} else {
				self::mark_legacy_copy_removed( (string) $entry );
			}
		}

		Plandose_Admin::forget_deleted_account( $user_id );
		Plandose_Admin::audit( 'deleted_account_invoices_removed', $user_id, array( 'invoices' => count( $invoices ) ) );

		wp_safe_redirect( add_query_arg( 'plandose_result', 'invoices_removed', $back ) );
		exit;
	}

	/**
	 * Whether old invoices still sit in the Media Library, which
	 * makes a «delete data» uninstall keep everything (uninstall.php, same
	 * rule): not yet migrated (numeric IDs in an invoice list), or migrated
	 * with the public original not deleted yet (an original whose file is
	 * already gone does not count).
	 *
	 * @return bool
	 */
	public static function legacy_invoices_block_uninstall() {
		foreach ( self::legacy_originals() as $item ) {
			if ( ! isset( $item['skip'] ) || 'original_missing' !== $item['skip'] ) {
				return true;
			}
		}

		return class_exists( 'Plandose_Invoice_Migration' ) && true === Plandose_Invoice_Migration::has_legacy_entries();
	}

	/**
	 * The recorded legacy originals (LEGACY_ORIGINALS_OPTION),
	 * normalized; malformed entries are dropped.
	 *
	 * @return array<int,array>
	 */
	public static function legacy_originals() {
		$stored = get_option( self::LEGACY_ORIGINALS_OPTION, array() );
		$clean  = array();

		if ( ! is_array( $stored ) ) {
			return $clean;
		}

		foreach ( $stored as $attachment_id => $entry ) {
			$attachment_id = absint( $attachment_id );

			$uncopied = is_array( $entry ) && ! empty( $entry['uncopied'] );

			if ( ! $attachment_id || ! is_array( $entry ) || ( empty( $entry['filename'] ) && ! $uncopied ) ) {
				continue;
			}

			$item = array(
				'user_id'     => isset( $entry['user_id'] ) ? absint( $entry['user_id'] ) : 0,
				'filename'    => isset( $entry['filename'] ) ? (string) $entry['filename'] : '',
				'migrated_at' => isset( $entry['migrated_at'] ) ? absint( $entry['migrated_at'] ) : 0,
			);

			// An original whose invoice-list entry was removed
			// without a protected copy ever being made.
			if ( $uncopied && '' === $item['filename'] ) {
				$item['uncopied']   = 1;
				$item['removed_at'] = isset( $entry['removed_at'] ) ? absint( $entry['removed_at'] ) : 0;
			}

			if ( ! empty( $entry['extra'] ) && is_array( $entry['extra'] ) ) {
				$item['extra'] = array();

				foreach ( $entry['extra'] as $extra ) {
					if ( is_array( $extra ) && ! empty( $extra['filename'] ) ) {
						$item['extra'][] = array(
							'user_id'  => isset( $extra['user_id'] ) ? absint( $extra['user_id'] ) : 0,
							'filename' => (string) $extra['filename'],
						);
					}
				}
			}

			if ( ! empty( $entry['skip'] ) ) {
				$item['skip']       = sanitize_key( (string) $entry['skip'] );
				$item['checked_at'] = isset( $entry['checked_at'] ) ? absint( $entry['checked_at'] ) : 0;
			}

			$clean[ $attachment_id ] = $item;
		}

		return $clean;
	}

	/**
	 * Record attachments the migration has just copied into
	 * protected storage. Called by Plandose_Invoice_Migration
	 * BEFORE the pharmacy's invoice list is rewritten; the list keeps the
	 * attachment IDs until this returns success, and records whose rewrite
	 * then fails are removed again (forget_legacy_originals()).
	 *
	 * Read and written only under LEGACY_ORIGINALS_LOCK, from a fresh read,
	 * so a concurrent «Διαγραφή πρωτοτύπων» cannot drop records added
	 * meanwhile; without the lock nothing is written and false is returned.
	 * After writing, the option is read back from the database and every
	 * record must be there.
	 *
	 * @param array $records List of array{attachment_id:int,user_id:int,filename:string,migrated_at:int}.
	 * @return int|false Number of records added or extended, false when
	 *                   they could not all be stored.
	 */
	public static function record_legacy_originals( array $records ) {
		if ( empty( $records ) ) {
			return 0;
		}

		// Never write without the lock (a concurrent write
		// could drop entries), and report failure instead of pretending.
		$lock = self::acquire_named_lock( self::LEGACY_ORIGINALS_LOCK, 25 );

		if ( false === $lock ) {
			return false;
		}

		$done = 0;

		try {
			$originals = self::legacy_originals();

			foreach ( $records as $record ) {
				$attachment_id = isset( $record['attachment_id'] ) ? absint( $record['attachment_id'] ) : 0;
				$filename      = isset( $record['filename'] ) ? (string) $record['filename'] : '';
				$user_id       = isset( $record['user_id'] ) ? absint( $record['user_id'] ) : 0;

				if ( ! $attachment_id || '' === $filename ) {
					continue;
				}

				if ( isset( $originals[ $attachment_id ] ) && '' === $originals[ $attachment_id ]['filename'] ) {
					unset( $originals[ $attachment_id ] ); // An uncopied record now has a copy.
				}

				if ( ! isset( $originals[ $attachment_id ] ) ) {
					$originals[ $attachment_id ] = array(
						'user_id'     => $user_id,
						'filename'    => $filename,
						'migrated_at' => isset( $record['migrated_at'] ) ? absint( $record['migrated_at'] ) : time(),
					);
					++$done;
					continue;
				}

				// The same attachment listed by another pharmacy too: keep
				// every protected copy, any one of them may verify it.
				$known = array( $originals[ $attachment_id ]['filename'] );

				foreach ( isset( $originals[ $attachment_id ]['extra'] ) ? $originals[ $attachment_id ]['extra'] : array() as $extra ) {
					$known[] = $extra['filename'];
				}

				if ( ! in_array( $filename, $known, true ) ) {
					$originals[ $attachment_id ]['extra'][] = array(
						'user_id'  => $user_id,
						'filename' => $filename,
					);
					++$done;
				}
			}

			if ( $done > 0 ) {
				update_option( self::LEGACY_ORIGINALS_OPTION, $originals, false );
			}

			// Read back from the database, not the cache: every record must
			// really be stored before the caller forgets the attachment IDs.
			// Only this option's entries are dropped — deleting the whole
			// 'alloptions' / 'notoptions' buckets would make every request
			// reload all autoloaded options on a persistent object cache.
			wp_cache_delete( self::LEGACY_ORIGINALS_OPTION, 'options' );

			foreach ( array( 'notoptions', 'alloptions' ) as $bucket ) {
				$cached = wp_cache_get( $bucket, 'options' );

				if ( is_array( $cached ) && array_key_exists( self::LEGACY_ORIGINALS_OPTION, $cached ) ) {
					unset( $cached[ self::LEGACY_ORIGINALS_OPTION ] );
					wp_cache_set( $bucket, $cached, 'options' );
				}
			}

			if ( ! self::legacy_originals_contain( $records ) ) {
				return false;
			}
		} finally {
			self::release_invoice_list_lock( $lock );
		}

		return $done;
	}

	/**
	 * Whether every record (attachment ID + copy filename) is
	 * stored.
	 *
	 * @param array $records See record_legacy_originals().
	 * @return bool
	 */
	public static function legacy_originals_contain( array $records ) {
		$originals = self::legacy_originals();

		foreach ( $records as $record ) {
			$attachment_id = isset( $record['attachment_id'] ) ? absint( $record['attachment_id'] ) : 0;
			$filename      = isset( $record['filename'] ) ? (string) $record['filename'] : '';

			if ( ! $attachment_id || '' === $filename ) {
				continue;
			}

			if ( ! isset( $originals[ $attachment_id ] ) ) {
				return false;
			}

			$known = array( $originals[ $attachment_id ]['filename'] );

			foreach ( isset( $originals[ $attachment_id ]['extra'] ) ? $originals[ $attachment_id ]['extra'] : array() as $extra ) {
				$known[] = $extra['filename'];
			}

			if ( ! in_array( $filename, $known, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Drop records again (their copy was rolled back because
	 * the invoice list could not be rewritten). Best effort.
	 *
	 * @param array $records See record_legacy_originals().
	 */
	public static function forget_legacy_originals( array $records ) {
		$lock = self::acquire_named_lock( self::LEGACY_ORIGINALS_LOCK, 25 );

		if ( false === $lock ) {
			return;
		}

		try {
			$originals = self::legacy_originals();
			$changed   = false;

			foreach ( $records as $record ) {
				$attachment_id = isset( $record['attachment_id'] ) ? absint( $record['attachment_id'] ) : 0;
				$filename      = isset( $record['filename'] ) ? (string) $record['filename'] : '';

				if ( ! isset( $originals[ $attachment_id ] ) ) {
					continue;
				}

				$extras = isset( $originals[ $attachment_id ]['extra'] ) ? $originals[ $attachment_id ]['extra'] : array();

				if ( $originals[ $attachment_id ]['filename'] === $filename ) {
					if ( $extras ) {
						$first = array_shift( $extras );
						$originals[ $attachment_id ]['filename'] = $first['filename'];
						$originals[ $attachment_id ]['user_id']  = $first['user_id'];
						$originals[ $attachment_id ]['extra']    = $extras;
					} else {
						unset( $originals[ $attachment_id ] );
					}
					$changed = true;
					continue;
				}

				foreach ( $extras as $k => $extra ) {
					if ( $extra['filename'] === $filename ) {
						unset( $extras[ $k ] );
						$originals[ $attachment_id ]['extra'] = array_values( $extras );
						$changed                               = true;
					}
				}
			}

			if ( $changed ) {
				update_option( self::LEGACY_ORIGINALS_OPTION, $originals, false );
			}
		} finally {
			self::release_invoice_list_lock( $lock );
		}
	}

	/**
	 * May the current user delete legacy originals? Deleting Media
	 * Library attachments is a site-wide, irreversible action, so on top of
	 * the PlanDose capability it needs core's manage_options.
	 *
	 * @return bool
	 */
	public static function can_delete_legacy_originals() {
		return current_user_can( Plandose_Admin::capability() ) && current_user_can( 'manage_options' );
	}

	/**
	 * «Διαγραφή πρωτοτύπων»: POST + nonce + manage_options, then one
	 * bounded batch of delete_legacy_originals_batch(). Never runs by itself.
	 */
	public static function handle_delete_legacy_originals() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Μη επιτρεπτή μέθοδος αιτήματος.', 'plandose' ), '', array( 'response' => 405 ) );
		}

		if ( ! self::can_delete_legacy_originals() ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'plandose_delete_legacy_originals' );

		$referer = wp_get_referer();
		$back    = $referer ? $referer : admin_url( 'admin.php?page=plandose-subscriptions' );
		$result  = self::delete_legacy_originals_batch( self::LEGACY_ORIGINALS_BATCH );

		if ( is_wp_error( $result ) ) {
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), $result->get_error_message(), 60 );
		} else {
			set_transient( 'plandose_legacy_originals_result_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Check, and delete where every check passes, up to $limit
	 * recorded legacy originals. Public for tests and WP-CLI; refuses unless
	 * can_delete_legacy_originals().
	 *
	 * Each original is deleted only when ALL of these hold:
	 *  - it is still an attachment post, and its file exists and is readable;
	 *  - a protected copy recorded for it exists and is readable in the
	 *    invoice folder, and its SHA-256 equals the original's;
	 *  - it is not the featured image (_thumbnail_id) of any post, not
	 *    named in any post's content, and not still listed (as a legacy
	 *    numeric entry) in any PlanDose invoice list.
	 * Otherwise it is skipped and the reason is kept on its entry. Every
	 * deletion and every skip is audited with IDs and reason codes only.
	 *
	 * Entries never tried come first, then skipped ones by oldest check, so
	 * repeated presses walk the whole list even when some keep failing.
	 *
	 * @param int $limit Maximum originals to process.
	 * @return array{deleted:int,skipped:int,gone:int,file_remains:int,remaining:int,storage_unavailable?:int}|WP_Error
	 *         WP_Error 'plandose_storage_unavailable' when the invoice folder
	 *         cannot be read (nothing is changed then).
	 */
	public static function delete_legacy_originals_batch( $limit = self::LEGACY_ORIGINALS_BATCH ) {
		if ( ! self::can_delete_legacy_originals() ) {
			return new WP_Error( 'plandose_forbidden', __( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ) );
		}

		$originals = self::legacy_originals();
		$summary   = array(
			'deleted'      => 0,
			'skipped'      => 0,
			'gone'         => 0,
			'file_remains' => 0,
			'remaining'    => count( $originals ),
		);

		if ( empty( $originals ) ) {
			return $summary;
		}

		// No copy can be verified without the folder; «not found» there must
		// never be read as «the copy is missing».
		$storage = Plandose_Invoice_Storage::readable_invoice_dir();

		if ( is_wp_error( $storage ) ) {
			return self::storage_unavailable_error( $storage );
		}

		$referenced = self::legacy_ids_in_invoice_lists();

		if ( null === $referenced ) {
			return new WP_Error( 'plandose_db_error', __( 'Δεν ήταν δυνατός ο έλεγχος των λιστών τιμολογίων λόγω σφάλματος βάσης δεδομένων. Κανένα αρχείο δεν διαγράφηκε.', 'plandose' ) );
		}

		uasort(
			$originals,
			static function ( $a, $b ) {
				$ka = empty( $a['skip'] ) ? -1 : (int) $a['checked_at'];
				$kb = empty( $b['skip'] ) ? -1 : (int) $b['checked_at'];
				return $ka <=> $kb;
			}
		);

		$batch   = array_slice( $originals, 0, max( 1, (int) $limit ), true );
		$remove  = array();
		$skipped = array();

		foreach ( $batch as $attachment_id => $entry ) {
			$post = get_post( $attachment_id );

			if ( ! $post ) {
				// Already removed some other way: nothing left to review.
				$remove[] = $attachment_id;
				++$summary['gone'];
				Plandose_Admin::audit( 'legacy_original_gone', $entry['user_id'], array( 'attachment_id' => $attachment_id ) );
				continue;
			}

			$reason = self::legacy_original_block_reason( $attachment_id, $entry, $post, $referenced );

			// The folder became unreachable mid-batch: stop, and leave this
			// and the remaining entries as they were.
			if ( 'storage_unavailable' === $reason ) {
				$summary['storage_unavailable'] = 1;
				break;
			}

			if ( '' !== $reason ) {
				$skipped[ $attachment_id ] = $reason;
				++$summary['skipped'];
				Plandose_Admin::audit(
					'legacy_original_skipped',
					$entry['user_id'],
					array(
						'attachment_id' => $attachment_id,
						'reason'        => $reason,
					)
				);
				continue;
			}

			$original = get_attached_file( $attachment_id );

			if ( ! wp_delete_attachment( $attachment_id, true ) ) {
				$skipped[ $attachment_id ] = 'delete_failed';
				++$summary['skipped'];
				Plandose_Admin::audit(
					'legacy_original_skipped',
					$entry['user_id'],
					array(
						'attachment_id' => $attachment_id,
						'reason'        => 'delete_failed',
					)
				);
				continue;
			}

			clearstatcache();

			// wp_delete_attachment() keeps the post deleted even when the
			// file could not be removed. The file was verified identical to
			// the protected copy above, so it is removed directly.
			$file_remains = $original && file_exists( $original )
				&& ! Plandose_Invoice_Storage::fs_delete( $original, 'Deleting verified legacy invoice original' );

			if ( $file_remains ) {
				++$summary['file_remains'];
			}

			$remove[] = $attachment_id;
			++$summary['deleted'];
			Plandose_Admin::audit(
				'legacy_original_deleted',
				$entry['user_id'],
				array(
					'attachment_id' => $attachment_id,
					'file_remains'  => $file_remains ? 1 : 0,
				)
			);
		}

		// Write back from a fresh read under the lock: the migration may
		// have added records while the files were being checked.
		$lock = self::acquire_named_lock( self::LEGACY_ORIGINALS_LOCK, 25 );

		if ( false === $lock ) {
			// The deletions stand; their entries are cleaned up as «gone» on
			// the next press.
			$summary['remaining'] = count( self::legacy_originals() );
		} else {
			try {
				$fresh = self::legacy_originals();
				$now   = time();

				foreach ( $remove as $attachment_id ) {
					unset( $fresh[ $attachment_id ] );
				}

				foreach ( $skipped as $attachment_id => $reason ) {
					if ( isset( $fresh[ $attachment_id ] ) ) {
						$fresh[ $attachment_id ]['skip']       = $reason;
						$fresh[ $attachment_id ]['checked_at'] = $now;
					}
				}

				if ( empty( $fresh ) ) {
					delete_option( self::LEGACY_ORIGINALS_OPTION );
				} else {
					update_option( self::LEGACY_ORIGINALS_OPTION, $fresh, false );
				}

				$summary['remaining'] = count( $fresh );
			} finally {
				self::release_invoice_list_lock( $lock );
			}
		}

		Plandose_Admin::audit(
			'legacy_originals_cleanup',
			0,
			array(
				'deleted'   => $summary['deleted'],
				'skipped'   => $summary['skipped'],
				'gone'      => $summary['gone'],
				'remaining' => $summary['remaining'],
			)
		);

		return $summary;
	}

	/**
	 * Why a recorded original may NOT be deleted, or '' when every
	 * check in delete_legacy_originals_batch() passes.
	 *
	 * @param int     $attachment_id Attachment ID.
	 * @param array   $entry         legacy_originals() entry.
	 * @param WP_Post $post          The attachment's post.
	 * @param array   $referenced    legacy_ids_in_invoice_lists() result.
	 * @return string Reason code.
	 */
	private static function legacy_original_block_reason( $attachment_id, array $entry, $post, array $referenced, $require_copy = true ) {
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return 'not_attachment';
		}

		if ( isset( $referenced[ $attachment_id ] ) ) {
			return 'in_invoice_list';
		}

		if ( ! $require_copy ) {
			return self::legacy_original_usage_reason( $attachment_id );
		}

		// No copy was ever made; only the explicit per-item delete
		// (handle_delete_legacy_original_uncopied()) may remove it.
		if ( ! empty( $entry['uncopied'] ) ) {
			return 'no_copy';
		}

		// A folder that cannot be read says nothing about the copy.
		if ( is_wp_error( Plandose_Invoice_Storage::readable_invoice_dir() ) ) {
			return 'storage_unavailable';
		}

		$original = get_attached_file( $attachment_id );

		if ( ! $original || ! is_file( $original ) || ! is_readable( $original ) ) {
			return 'original_missing';
		}

		$original_hash = hash_file( 'sha256', $original );

		if ( false === $original_hash ) {
			return 'original_missing';
		}

		$copies = array( $entry['filename'] );

		foreach ( isset( $entry['extra'] ) ? $entry['extra'] : array() as $extra ) {
			$copies[] = $extra['filename'];
		}

		$copy_found = false;
		$verified   = false;

		foreach ( $copies as $filename ) {
			$path = Plandose_Invoice_Storage::invoice_path( $filename );

			if ( is_wp_error( $path ) && self::is_storage_error( $path ) ) {
				return 'storage_unavailable';
			}

			if ( is_wp_error( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}

			// The copy must not BE the original (a PLANDOSE_INVOICE_DIR
			// pointed at the uploads month folder, a symlink, …).
			if ( realpath( $path ) === realpath( $original ) ) {
				continue;
			}

			$copy_found = true;
			$copy_hash  = hash_file( 'sha256', $path );

			if ( false !== $copy_hash && hash_equals( $copy_hash, $original_hash ) ) {
				$verified = true;
				break;
			}
		}

		if ( ! $copy_found ) {
			return 'copy_missing';
		}

		if ( ! $verified ) {
			return 'hash_mismatch';
		}

		return self::legacy_original_usage_reason( $attachment_id );
	}

	/**
	 * Whether an invoice_path() error is about the folder (unavailable,
	 * misconfigured, refused) rather than about one malformed file name.
	 *
	 * @param WP_Error $error invoice_path() error.
	 * @return bool
	 */
	private static function is_storage_error( WP_Error $error ) {
		return 'plandose_invoice_bad_filename' !== $error->get_error_code();
	}

	/**
	 * The admin error for a legacy-original action refused because the
	 * invoice folder cannot be read right now.
	 *
	 * @param WP_Error $cause Folder error (its message is appended).
	 * @return WP_Error
	 */
	private static function storage_unavailable_error( WP_Error $cause ) {
		return new WP_Error(
			'plandose_storage_unavailable',
			sprintf(
				/* translators: %s: why the invoice folder cannot be used */
				__( 'Ο φάκελος τιμολογίων δεν είναι προσβάσιμος αυτή τη στιγμή, οπότε δεν ήταν δυνατό να ελεγχθούν τα προστατευμένα αντίγραφα. Δεν έγινε καμία αλλαγή και δεν διαγράφηκε κανένα αρχείο. (%s)', 'plandose' ),
				$cause->get_error_message()
			)
		);
	}

	/**
	 * Numeric post meta keys that never reference an attachment, so an
	 * equal number there is not usage (prices, stock, editor IDs, …).
	 */
	const USAGE_IGNORED_META_KEYS = array( '_edit_last', '_edit_lock', '_price', '_regular_price', '_sale_price', '_stock', 'total_sales', '_wc_average_rating', '_wc_review_count', '_download_limit', '_download_expiry', '_weight', '_length', '_width', '_height' );

	/**
	 * Whether the attachment is still used by the site. Reason code, or ''.
	 *
	 * Checked, each as one bounded (LIMIT 1) prepared query:
	 * - featured image (_thumbnail_id);
	 * - any other post meta holding the ID — alone, in a comma list
	 *   (WooCommerce _product_image_gallery) or as a quoted serialized /
	 *   JSON value (ACF image/gallery fields) — and term meta holding it
	 *   (e.g. a product category thumbnail);
	 * - the site icon / logo;
	 * - post content: the file's path, a wp-image-<ID> class, a block's
	 *   "id":<ID> / "ids":[…], a [gallery ids="…"] / include="…" list, an
	 *   ?attachment_id=<ID> link;
	 * - a [gallery] without ids in the post the attachment is attached to
	 *   (such a gallery shows that post's attached images). Being attached
	 *   (post_parent) is not usage by itself.
	 *
	 * Deliberately broad: a false «in use» only keeps a file, a missed use
	 * deletes one.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function legacy_original_usage_reason( $attachment_id ) {
		global $wpdb;

		$attachment_id = absint( $attachment_id );
		$id            = (string) $attachment_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off reference check before an irreversible delete; must not be served from a cache.
		$thumbnail_of = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				'_thumbnail_id',
				$id
			)
		);

		if ( '' !== $wpdb->last_error ) {
			return 'check_failed';
		}

		if ( null !== $thumbnail_of ) {
			return 'thumbnail';
		}

		$ignored = self::USAGE_IGNORED_META_KEYS;
		$in_list = '(^|,)[[:space:]]*' . $id . '[[:space:]]*(,|$)';
		$quoted  = '%"' . $wpdb->esc_like( $id ) . '"%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Same as above; the IN () placeholders are built from a constant list.
		$in_meta = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id != %d AND meta_key NOT IN ( " . implode( ', ', array_fill( 0, count( $ignored ), '%s' ) ) . " ) AND ( meta_value = %s OR ( meta_value LIKE %s AND ( meta_value REGEXP %s OR meta_value LIKE %s ) ) ) LIMIT 1",
				array_merge( array( $attachment_id ), $ignored, array( $id, '%' . $wpdb->esc_like( $id ) . '%', $in_list, $quoted ) )
			)
		);
		// phpcs:enable

		if ( '' !== $wpdb->last_error ) {
			return 'check_failed';
		}

		if ( null === $in_meta ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same as above.
			$in_meta = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_id FROM {$wpdb->termmeta} WHERE meta_key != %s AND meta_key NOT LIKE %s AND meta_value = %s LIMIT 1",
					'order',
					$wpdb->esc_like( 'product_count_' ) . '%',
					$id
				)
			);

			if ( '' !== $wpdb->last_error ) {
				return 'check_failed';
			}
		}

		if ( null !== $in_meta
			|| $attachment_id === absint( get_option( 'site_icon' ) )
			|| $attachment_id === absint( get_option( 'site_logo' ) )
			|| $attachment_id === absint( get_theme_mod( 'custom_logo' ) ) ) {
			return 'in_meta';
		}

		$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( '' !== $relative ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same as above.
			$in_content = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE ID != %d AND post_type NOT IN ( 'attachment', 'revision' ) AND post_content LIKE %s LIMIT 1",
					$attachment_id,
					'%' . $wpdb->esc_like( $relative ) . '%'
				)
			);

			if ( '' !== $wpdb->last_error ) {
				return 'check_failed';
			}

			if ( null !== $in_content ) {
				return 'in_content';
			}
		}

		// The ID (not the path) in content: resized images, galleries,
		// blocks, attachment-page links. The LIKE narrows what REGEXP reads.
		$by_id = 'wp-image-' . $id . '([^0-9]|$)'
			. '|"id":' . $id . '([^0-9]|$)'
			. '|"ids":\\[([0-9]+,)*' . $id . '[],]'
			. '|(ids|include)=["\']?([0-9]+[[:space:]]*,[[:space:]]*)*' . $id . '([^0-9]|$)'
			. '|attachment_id=' . $id . '([^0-9]|$)';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same as above.
		$in_content = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID != %d AND post_type NOT IN ( 'attachment', 'revision' ) AND post_content LIKE %s AND post_content REGEXP %s LIMIT 1",
				$attachment_id,
				'%' . $wpdb->esc_like( $id ) . '%',
				$by_id
			)
		);

		if ( '' !== $wpdb->last_error ) {
			return 'check_failed';
		}

		if ( null !== $in_content ) {
			return 'in_content';
		}

		$parent = (int) wp_get_post_parent_id( $attachment_id );

		if ( $parent > 0 ) {
			$parent_post = get_post( $parent );

			if ( $parent_post && false !== strpos( (string) $parent_post->post_content, '[gallery' ) ) {
				return 'in_content';
			}
		}

		return '';
	}

	/**
	 * Every attachment ID still listed as a legacy (numeric) entry
	 * in any PlanDose invoice list, including rows of deleted accounts. Read
	 * in chunks so a large table is never loaded whole.
	 *
	 * @return array<int,true>|null Null on a database error.
	 */
	private static function legacy_ids_in_invoice_lists() {
		global $wpdb;

		$table  = Plandose_Subscriptions::table_name();
		$cursor = 0;
		$ids    = array();

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; batch scans and audit writes must hit live data, not a cache.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, invoices FROM {$table} WHERE user_id > %d AND invoices IS NOT NULL AND invoices != '' ORDER BY user_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is from $wpdb->prefix (site-controlled); prepare() cannot parameterize identifiers.
					$cursor,
					500
				)
			);

			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
				return null;
			}

			foreach ( $rows as $row ) {
				$cursor = max( $cursor, (int) $row->user_id );

				foreach ( Plandose_Subscriptions::decode_invoices( $row->invoices ) as $entry ) {
					if ( is_numeric( $entry ) ) {
						$ids[ (int) $entry ] = true;
					}
				}
			}
		} while ( count( $rows ) === 500 );

		return $ids;
	}

	/**
	 * Human-readable text for a skip reason code.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	public static function legacy_original_reason_label( $reason ) {
		$labels = array(
			'not_attachment'      => __( 'Το ID δεν είναι πλέον συνημμένο της Media Library.', 'plandose' ),
			'in_invoice_list'     => __( 'Εξακολουθεί να αναφέρεται σε λίστα τιμολογίων PlanDose.', 'plandose' ),
			'original_missing'    => __( 'Το αρχείο του πρωτοτύπου δεν βρέθηκε ή δεν διαβάζεται.', 'plandose' ),
			'copy_missing'        => __( 'Το προστατευμένο αντίγραφο δεν βρέθηκε ή δεν διαβάζεται.', 'plandose' ),
			'storage_unavailable' => __( 'Ο φάκελος τιμολογίων δεν ήταν προσβάσιμος κατά τον έλεγχο, οπότε το προστατευμένο αντίγραφο δεν ελέγχθηκε. Δεν έγινε καμία αλλαγή.', 'plandose' ),
			'hash_mismatch'       => __( 'Το προστατευμένο αντίγραφο δεν είναι πανομοιότυπο με το πρωτότυπο (SHA-256).', 'plandose' ),
			'thumbnail'           => __( 'Χρησιμοποιείται ως επιλεγμένη εικόνα (featured image) άρθρου ή σελίδας.', 'plandose' ),
			'in_content'          => __( 'Αναφέρεται στο περιεχόμενο άρθρου ή σελίδας.', 'plandose' ),
			'in_meta'             => __( 'Χρησιμοποιείται σε πεδίο άρθρου, προϊόντος ή κατηγορίας (π.χ. γκαλερί προϊόντος, πεδίο ACF) ή ως εικονίδιο/λογότυπο του site.', 'plandose' ),
			'check_failed'        => __( 'Ο έλεγχος αναφορών απέτυχε λόγω σφάλματος βάσης δεδομένων.', 'plandose' ),
			'no_copy'             => __( 'Η εγγραφή αφαιρέθηκε από τη λίστα τιμολογίων χωρίς να υπάρξει προστατευμένο αντίγραφο. Διαγράψτε το πρωτότυπο ρητά με το κουμπί της γραμμής.', 'plandose' ),
			'delete_failed'       => __( 'Η διαγραφή του συνημμένου απέτυχε.', 'plandose' ),
		);

		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : __( 'Άγνωστη αιτία.', 'plandose' );
	}

	/**
	 * The «Πρωτότυπα τιμολογίων στη Media Library» notice on the
	 * PlanDose screens — the recorded originals, their state, and the
	 * «Διαγραφή πρωτοτύπων» button — plus the result of the last press.
	 */
	public static function render_legacy_originals_notice() {
		if ( ! self::on_plandose_screen() ) {
			return;
		}

		$admin_id = get_current_user_id();
		$result   = get_transient( 'plandose_legacy_originals_result_' . $admin_id );

		if ( is_array( $result ) ) {
			delete_transient( 'plandose_legacy_originals_result_' . $admin_id );

			$text = sprintf(
				/* translators: 1: originals deleted, 2: originals skipped, 3: entries already gone, 4: originals still listed */
				__( 'PlanDose — Διαγραφή πρωτοτύπων: διαγράφηκαν %1$d, παραλείφθηκαν %2$d, είχαν ήδη αφαιρεθεί %3$d. Απομένουν %4$d στη λίστα.', 'plandose' ),
				isset( $result['deleted'] ) ? (int) $result['deleted'] : 0,
				isset( $result['skipped'] ) ? (int) $result['skipped'] : 0,
				isset( $result['gone'] ) ? (int) $result['gone'] : 0,
				isset( $result['remaining'] ) ? (int) $result['remaining'] : 0
			);

			if ( ! empty( $result['storage_unavailable'] ) ) {
				$text .= ' ' . __( 'Η διαδικασία σταμάτησε: ο φάκελος τιμολογίων έπαψε να είναι προσβάσιμος. Τα υπόλοιπα πρωτότυπα δεν ελέγχθηκαν και δεν άλλαξαν.', 'plandose' );
			}

			if ( ! empty( $result['file_remains'] ) ) {
				$text .= ' ' . sprintf(
					/* translators: %d: number of files */
					__( 'Σε %d περιπτώσεις το συνημμένο διαγράφηκε αλλά το αρχείο δεν ήταν δυνατό να σβηστεί από τον δίσκο — ελέγξτε τα δικαιώματα του φακέλου uploads (λεπτομέρειες στο error log).', 'plandose' ),
					(int) $result['file_remains']
				);
			}

			printf(
				'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
				empty( $result['skipped'] ) && empty( $result['file_remains'] ) && empty( $result['storage_unavailable'] ) ? 'notice-success' : 'notice-warning',
				esc_html( $text )
			);
		}

		$originals = self::legacy_originals();

		if ( empty( $originals ) ) {
			return;
		}

		$shown   = array_slice( $originals, 0, self::LEGACY_ORIGINALS_SHOWN, true );
		$skipped = count(
			array_filter(
				$originals,
				static function ( $entry ) {
					return ! empty( $entry['skip'] );
				}
			)
		);

		// A «copy_missing» recorded earlier is not offered for deletion while
		// the folder cannot be read: whether the copy exists is unknown.
		$storage_ok = ! is_wp_error( Plandose_Invoice_Storage::readable_invoice_dir() );
		?>
		<div class="notice notice-warning" id="plandose-legacy-originals">
			<p><strong><?php esc_html_e( 'PlanDose: πρωτότυπα παλιών τιμολογίων παραμένουν δημόσια στη Media Library', 'plandose' ); ?></strong></p>
			<p><?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of Media Library originals */
					__( 'Η μεταφορά των παλιών τιμολογίων ΑΝΤΕΓΡΑΨΕ %d αρχεία στην προστατευμένη αποθήκευση — δεν τα μετακίνησε. Τα πρωτότυπα βρίσκονται ακόμη στη Media Library (wp-content/uploads/ΕΕΕΕ/ΜΜ/) και έχουν δημόσια διεύθυνση, την οποία ΔΕΝ καλύπτει ο κανόνας του web server για τον φάκελο τιμολογίων. Το «Διαγραφή πρωτοτύπων» σβήνει κάθε πρωτότυπο μόνο αφού επιβεβαιώσει ότι το προστατευμένο αντίγραφο υπάρχει και είναι πανομοιότυπο (SHA-256), και ότι το συνημμένο δεν χρησιμοποιείται αλλού (επιλεγμένη εικόνα, περιεχόμενο, άλλη λίστα τιμολογίων). Ό,τι δεν περνά τους ελέγχους μένει στη λίστα με την αιτία.', 'plandose' ),
					count( $originals )
				)
			);
			?></p>
			<?php if ( $skipped > 0 ) : ?>
				<p class="description"><?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of originals skipped on an earlier attempt */
						__( '%d από αυτά παραλείφθηκαν σε προηγούμενη προσπάθεια (δείτε την αιτία παρακάτω) και θα ξαναελεγχθούν.', 'plandose' ),
						$skipped
					)
				);
				?></p>
			<?php endif; ?>
			<table class="widefat striped" style="max-width:60em;margin:0.5em 0">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'ID συνημμένου', 'plandose' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Φαρμακείο (ID χρήστη)', 'plandose' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Αντιγράφηκε', 'plandose' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Κατάσταση', 'plandose' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Ενέργειες', 'plandose' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $shown as $attachment_id => $entry ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $attachment_id ); ?></td>
							<td><?php echo esc_html( $entry['user_id'] ? (string) $entry['user_id'] : '—' ); ?></td>
							<td><?php echo esc_html( $entry['migrated_at'] ? wp_date( 'Y-m-d H:i', $entry['migrated_at'] ) : '—' ); ?></td>
							<td><?php echo esc_html( ! empty( $entry['skip'] ) ? self::legacy_original_reason_label( $entry['skip'] ) : ( ! empty( $entry['uncopied'] ) ? self::legacy_original_reason_label( 'no_copy' ) : __( 'Αναμένει έλεγχο', 'plandose' ) ) ); ?></td>
							<td>
								<?php if ( self::can_delete_legacy_originals() && ( ! empty( $entry['uncopied'] ) || ( $storage_ok && isset( $entry['skip'] ) && 'copy_missing' === $entry['skip'] ) ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pd-inline-form pd-confirm-submit" data-confirm="<?php
										/* translators: %d: attachment ID */
										echo esc_attr( sprintf( __( 'Οριστική διαγραφή του δημόσιου πρωτοτύπου #%d από τη Media Library; ΔΕΝ υπάρχει προστατευμένο αντίγραφο του αρχείου, οπότε μετά τη διαγραφή το τιμολόγιο δεν θα υπάρχει πουθενά στο site. Η ενέργεια δεν αναιρείται.', 'plandose' ), (int) $attachment_id ) );
									?>">
										<input type="hidden" name="action" value="plandose_delete_legacy_original_uncopied" />
										<input type="hidden" name="attachment_id" value="<?php echo esc_attr( (string) $attachment_id ); ?>" />
										<?php wp_nonce_field( 'plandose_delete_legacy_original_uncopied_' . (int) $attachment_id ); ?>
										<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Διαγραφή χωρίς αντίγραφο', 'plandose' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( count( $originals ) > count( $shown ) ) : ?>
				<p class="description"><?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of originals not listed */
						__( '… και %d ακόμη.', 'plandose' ),
						count( $originals ) - count( $shown )
					)
				);
				?></p>
			<?php endif; ?>
			<?php if ( self::can_delete_legacy_originals() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pd-inline-form pd-confirm-submit" style="margin:0.5em 0 0.75em" data-confirm="<?php echo esc_attr__( 'Οριστική διαγραφή των πρωτοτύπων από τη Media Library, όσων περνούν τους ελέγχους; Τα προστατευμένα αντίγραφα μένουν ως έχουν. Η ενέργεια δεν αναιρείται.', 'plandose' ); ?>">
					<input type="hidden" name="action" value="plandose_delete_legacy_originals" />
					<?php wp_nonce_field( 'plandose_delete_legacy_originals' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Διαγραφή πρωτοτύπων', 'plandose' ); ?></button>
					<span class="description"><?php
					echo esc_html(
						sprintf(
							/* translators: %d: batch size */
							__( 'Έως %d ανά πάτημα.', 'plandose' ),
							self::LEGACY_ORIGINALS_BATCH
						)
					);
					?></span>
				</form>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Η διαγραφή των πρωτοτύπων γίνεται μόνο από διαχειριστή του site (δικαίωμα manage_options).', 'plandose' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Keep a legacy (numeric) entry's public Media Library original
	 * on the «πρωτότυπα» list after the entry itself was removed from an
	 * invoice list. Nothing was copied, so it is recorded as 'uncopied' and
	 * can only be deleted explicitly (handle_delete_legacy_original_uncopied()).
	 * Best effort: a busy lock only costs the record.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $user_id       Pharmacy the entry belonged to.
	 * @return bool Whether it is on the list now.
	 */
	public static function record_uncopied_legacy_original( $attachment_id, $user_id ) {
		$attachment_id = absint( $attachment_id );
		$post          = $attachment_id ? get_post( $attachment_id ) : null;

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return false;
		}

		$lock = self::acquire_named_lock( self::LEGACY_ORIGINALS_LOCK, 25 );

		if ( false === $lock ) {
			return false;
		}

		try {
			$originals = self::legacy_originals();

			if ( ! isset( $originals[ $attachment_id ] ) ) {
				$originals[ $attachment_id ] = array(
					'user_id'     => absint( $user_id ),
					'filename'    => '',
					'migrated_at' => 0,
					'uncopied'    => 1,
					'removed_at'  => time(),
				);
				update_option( self::LEGACY_ORIGINALS_OPTION, $originals, false );
			}
		} finally {
			self::release_invoice_list_lock( $lock );
		}

		return true;
	}

	/**
	 * Every protected copy recorded for one original.
	 *
	 * @param array $entry legacy_originals() entry.
	 * @return string[]
	 */
	private static function legacy_copy_names( array $entry ) {
		$names = array();

		foreach ( array_merge( array( $entry ), isset( $entry['extra'] ) ? $entry['extra'] : array() ) as $item ) {
			if ( isset( $item['filename'] ) && '' !== (string) $item['filename'] ) {
				$names[] = (string) $item['filename'];
			}
		}

		return $names;
	}

	/**
	 * A protected copy was deleted (with its invoice). If it was the
	 * only copy of a recorded original, mark that original 'copy_missing' at
	 * once, so the explicit per-item delete is offered without waiting for
	 * the next «Διαγραφή πρωτοτύπων».
	 *
	 * @param string $filename Deleted copy's file name.
	 */
	public static function mark_legacy_copy_removed( $filename ) {
		$filename = (string) $filename;
		$hit      = false;

		foreach ( self::legacy_originals() as $entry ) {
			if ( in_array( $filename, self::legacy_copy_names( $entry ), true ) ) {
				$hit = true;
				break;
			}
		}

		if ( '' === $filename || ! $hit ) {
			return;
		}

		// Without a readable folder the other copies cannot be looked for;
		// the next «Διαγραφή πρωτοτύπων» checks again.
		if ( is_wp_error( Plandose_Invoice_Storage::readable_invoice_dir() ) ) {
			return;
		}

		$lock = self::acquire_named_lock( self::LEGACY_ORIGINALS_LOCK, 25 );

		if ( false === $lock ) {
			return;
		}

		try {
			$originals = self::legacy_originals();
			$changed   = false;

			foreach ( $originals as $attachment_id => $entry ) {
				$names = self::legacy_copy_names( $entry );

				if ( ! in_array( $filename, $names, true ) ) {
					continue;
				}

				$any_left = false;
				$unknown  = false;

				foreach ( array_diff( $names, array( $filename ) ) as $name ) {
					$path = Plandose_Invoice_Storage::invoice_path( $name );

					if ( is_wp_error( $path ) && self::is_storage_error( $path ) ) {
						$unknown = true;
						break;
					}

					if ( ! is_wp_error( $path ) && is_file( $path ) ) {
						$any_left = true;
						break;
					}
				}

				if ( ! $any_left && ! $unknown ) {
					$originals[ $attachment_id ]['skip']       = 'copy_missing';
					$originals[ $attachment_id ]['checked_at'] = time();
					$changed                                   = true;
				}
			}

			if ( $changed ) {
				update_option( self::LEGACY_ORIGINALS_OPTION, $originals, false );
			}
		} finally {
			self::release_invoice_list_lock( $lock );
		}
	}

	/**
	 * «Διαγραφή χωρίς αντίγραφο» — explicitly delete ONE public
	 * original that has no protected copy (its list entry was removed, or
	 * its copy was deleted with the invoice). POST, nonce bound to the
	 * attachment, manage_options, a confirm dialog in the page. Every other
	 * check of the batch delete still applies (still an attachment, not in
	 * any invoice list, not a featured image, not in any content); a copy
	 * that does exist sends the admin back to the normal, verified delete.
	 */
	public static function handle_delete_legacy_original_uncopied() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Μη επιτρεπτή μέθοδος αιτήματος.', 'plandose' ), '', array( 'response' => 405 ) );
		}

		if ( ! self::can_delete_legacy_originals() ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? self::parse_user_id( wp_unslash( $_POST['attachment_id'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- digits only (parse_user_id()).

		check_admin_referer( 'plandose_delete_legacy_original_uncopied_' . $attachment_id );

		$referer = wp_get_referer();
		$back    = $referer ? $referer : admin_url( 'admin.php?page=plandose-subscriptions' );
		$result  = self::delete_uncopied_legacy_original( $attachment_id );

		if ( is_wp_error( $result ) ) {
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), $result->get_error_message(), 60 );
		} else {
			set_transient(
				'plandose_legacy_originals_result_' . get_current_user_id(),
				array(
					'deleted'      => 1,
					'skipped'      => 0,
					'gone'         => 0,
					'file_remains' => $result ? 0 : 1,
					'remaining'    => count( self::legacy_originals() ),
				),
				10 * MINUTE_IN_SECONDS
			);
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * The work of handle_delete_legacy_original_uncopied(). Public for tests.
	 *
	 * @param int $attachment_id Recorded original.
	 * @return bool|WP_Error True when deleted; false when the attachment went
	 *                       but its file could not be removed from disk.
	 */
	public static function delete_uncopied_legacy_original( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! self::can_delete_legacy_originals() ) {
			return new WP_Error( 'plandose_forbidden', __( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ) );
		}

		$originals = self::legacy_originals();

		if ( ! $attachment_id || ! isset( $originals[ $attachment_id ] ) ) {
			return new WP_Error( 'plandose_not_recorded', __( 'Το πρωτότυπο δεν βρίσκεται στη λίστα. Ανανεώστε τη σελίδα.', 'plandose' ) );
		}

		$entry = $originals[ $attachment_id ];
		$names = self::legacy_copy_names( $entry );

		// Whether a recorded copy still exists cannot be known without the
		// folder: refuse rather than delete the only remaining file.
		if ( $names ) {
			$storage = Plandose_Invoice_Storage::readable_invoice_dir();

			if ( is_wp_error( $storage ) ) {
				return self::storage_unavailable_error( $storage );
			}
		}

		// A copy that exists means the normal, SHA-256-verified path applies.
		foreach ( $names as $name ) {
			$path = Plandose_Invoice_Storage::invoice_path( $name );

			if ( is_wp_error( $path ) && self::is_storage_error( $path ) ) {
				return self::storage_unavailable_error( $path );
			}

			if ( ! is_wp_error( $path ) && is_file( $path ) ) {
				return new WP_Error( 'plandose_copy_exists', __( 'Υπάρχει προστατευμένο αντίγραφο αυτού του πρωτοτύπου· χρησιμοποιήστε το «Διαγραφή πρωτοτύπων», που το επαληθεύει πριν τη διαγραφή.', 'plandose' ) );
			}
		}

		$post         = get_post( $attachment_id );
		$file_removed = true;

		if ( $post ) {
			$referenced = self::legacy_ids_in_invoice_lists();

			if ( null === $referenced ) {
				return new WP_Error( 'plandose_db_error', __( 'Δεν ήταν δυνατός ο έλεγχος των λιστών τιμολογίων λόγω σφάλματος βάσης δεδομένων. Κανένα αρχείο δεν διαγράφηκε.', 'plandose' ) );
			}

			$reason = self::legacy_original_block_reason( $attachment_id, $entry, $post, $referenced, false );

			if ( '' !== $reason ) {
				Plandose_Admin::audit(
					'legacy_original_skipped',
					$entry['user_id'],
					array(
						'attachment_id' => $attachment_id,
						'reason'        => $reason,
					)
				);

				return new WP_Error( 'plandose_blocked', self::legacy_original_reason_label( $reason ) );
			}

			$original = get_attached_file( $attachment_id );

			if ( ! wp_delete_attachment( $attachment_id, true ) ) {
				return new WP_Error( 'plandose_delete_failed', self::legacy_original_reason_label( 'delete_failed' ) );
			}

			clearstatcache();

			if ( $original && file_exists( $original ) ) {
				$file_removed = Plandose_Invoice_Storage::fs_delete( $original, 'Deleting uncopied legacy invoice original' );
			}
		}

		$lock = self::acquire_named_lock( self::LEGACY_ORIGINALS_LOCK, 25 );

		if ( false !== $lock ) {
			try {
				$fresh = self::legacy_originals();
				unset( $fresh[ $attachment_id ] );

				if ( empty( $fresh ) ) {
					delete_option( self::LEGACY_ORIGINALS_OPTION );
				} else {
					update_option( self::LEGACY_ORIGINALS_OPTION, $fresh, false );
				}
			} finally {
				self::release_invoice_list_lock( $lock );
			}
		}

		Plandose_Admin::audit(
			'legacy_original_deleted_uncopied',
			$entry['user_id'],
			array(
				'attachment_id' => $attachment_id,
				'file_remains'  => $file_removed ? 0 : 1,
			)
		);

		return $file_removed;
	}

	/**
	 * Whether an upload's raw name hides an executable or
	 * server-rendered type as its SECOND-TO-LAST extension. Only that one
	 * segment is checked, so names such as "invoice.pl.pdf" or
	 * "timologio.js.pdf" are accepted.
	 *
	 * @param string $raw_name Client-supplied file name.
	 * @return bool
	 */
	public static function has_dangerous_inner_extension( $raw_name ) {
		$parts = explode( '.', strtolower( trim( basename( str_replace( '\\', '/', (string) $raw_name ) ) ) ) );

		if ( count( $parts ) < 3 ) {
			return false;
		}

		$inner = rtrim( $parts[ count( $parts ) - 2 ], " _\t" );

		return 1 === preg_match( '/^(php\d*|phtml|pht|phar|phps|cgi|asp|aspx|ashx|asmx|jsp|jspx|shtml|shtm|stm|html|htm|xhtml|svg|svgz|htaccess|htpasswd)$/', $inner );
	}

	private static function validate_invoice_upload( $file ) {
		if ( empty( $file ) || ! is_array( $file ) ) {
			return new WP_Error( 'plandose_upload_missing', __( 'Δεν επιλέχθηκε αρχείο τιμολογίου.', 'plandose' ) );
		}

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $error ) {
			$messages = array(
				UPLOAD_ERR_INI_SIZE   => __( 'Το αρχείο ξεπερνά το όριο upload_max_filesize του server.', 'plandose' ),
				UPLOAD_ERR_FORM_SIZE  => __( 'Το αρχείο ξεπερνά το επιτρεπτό μέγεθος της φόρμας.', 'plandose' ),
				UPLOAD_ERR_PARTIAL    => __( 'Το αρχείο ανέβηκε μόνο εν μέρει. Δοκιμάστε ξανά.', 'plandose' ),
				UPLOAD_ERR_NO_FILE    => __( 'Δεν επιλέχθηκε αρχείο τιμολογίου.', 'plandose' ),
				UPLOAD_ERR_NO_TMP_DIR => __( 'Λείπει ο προσωρινός φάκελος uploads του server.', 'plandose' ),
				UPLOAD_ERR_CANT_WRITE => __( 'Ο server δεν μπόρεσε να γράψει το αρχείο στον δίσκο.', 'plandose' ),
				UPLOAD_ERR_EXTENSION  => __( 'Το upload διακόπηκε από επέκταση του PHP.', 'plandose' ),
			);

			return new WP_Error(
				'plandose_upload_error',
				isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'Σφάλμα κατά το ανέβασμα του αρχείου.', 'plandose' )
			);
		}

		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$name     = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( (string) $file['name'] ) ) : '';

		if ( '' === $tmp_name || '' === $name || ! is_uploaded_file( $tmp_name ) || ! is_file( $tmp_name ) || ! is_readable( $tmp_name ) ) {
			return new WP_Error( 'plandose_upload_invalid', __( 'Το αρχείο upload δεν είναι έγκυρο.', 'plandose' ) );
		}

		$actual_size = filesize( $tmp_name );

		if ( false === $actual_size || $actual_size <= 0 ) {
			return new WP_Error( 'plandose_upload_empty', __( 'Το αρχείο είναι κενό ή δεν ήταν δυνατό να διαβαστεί.', 'plandose' ) );
		}

		if ( $actual_size > Plandose_Settings::invoice_max_bytes() ) {
			return new WP_Error(
				'plandose_upload_too_large',
				sprintf(
					/* translators: %d: Maximum invoice size in MB. */
					__( 'Το αρχείο ξεπερνά το όριο των %dMB.', 'plandose' ),
					(int) Plandose_Settings::setting( 'invoice_max_mb', 5 )
				)
			);
		}

		$filetype = wp_check_filetype_and_ext( $tmp_name, $name );
		$mime     = ! empty( $filetype['type'] ) ? strtolower( (string) $filetype['type'] ) : '';
		$ext      = ! empty( $filetype['ext'] ) ? strtolower( (string) $filetype['ext'] ) : '';

		if ( '' === $mime || ! in_array( $mime, Plandose_Settings::allowed_invoice_mimes(), true ) ) {
			return new WP_Error( 'plandose_upload_bad_type', __( 'Μη επιτρεπτός τύπος αρχείου τιμολογίου.', 'plandose' ) );
		}

		$expected_exts = isset( Plandose_Invoice_Storage::ALLOWED_INVOICE_EXTENSIONS_BY_MIME[ $mime ] ) ? Plandose_Invoice_Storage::ALLOWED_INVOICE_EXTENSIONS_BY_MIME[ $mime ] : array();

		if ( '' === $ext || ! in_array( $ext, $expected_exts, true ) ) {
			return new WP_Error( 'plandose_upload_bad_type', __( 'Η επέκταση του αρχείου δεν αντιστοιχεί στον πραγματικό τύπο του.', 'plandose' ) );
		}

		$original_ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( '' === $original_ext || ! in_array( $original_ext, $expected_exts, true ) ) {
			return new WP_Error( 'plandose_upload_bad_extension', __( 'Η επέκταση του ονόματος αρχείου δεν είναι επιτρεπτή.', 'plandose' ) );
		}

		/*
		 * Refuse a double extension that hides something a web server may
		 * execute or render ("invoice.php.pdf", "a.phtml.jpg"). The stored
		 * copy always gets a fresh random name with only the final
		 * extension, so this is not what keeps the server safe — it refuses
		 * an upload that is almost certainly not a genuine invoice. Checked
		 * on the RAW name: sanitize_file_name() already rewrote
		 * "invoice.php.pdf" to "invoice.php_.pdf".
		 */
		$raw_name = isset( $file['name'] ) ? (string) wp_unslash( (string) $file['name'] ) : '';

		if ( self::has_dangerous_inner_extension( $raw_name ) ) {
			return new WP_Error( 'plandose_upload_bad_extension', __( 'Το όνομα αρχείου περιέχει μη επιτρεπτή διπλή επέκταση (π.χ. «.php.pdf»). Μετονομάστε το αρχείο και δοκιμάστε ξανά.', 'plandose' ) );
		}

		/*
		 * Final and most important gate: everything above can be satisfied
		 * by simply naming a file invoice.pdf, because
		 * wp_check_filetype_and_ext() does not inspect PDF contents. This
		 * reads the actual bytes, so a script or archive renamed to .pdf is
		 * rejected before it is ever written into the invoice directory.
		 */
		if ( ! Plandose_Invoice_Storage::content_matches_mime( $tmp_name, $mime ) ) {
			return new WP_Error( 'plandose_upload_content_mismatch', __( 'Το περιεχόμενο του αρχείου δεν αντιστοιχεί στον τύπο που δηλώνει η επέκτασή του.', 'plandose' ) );
		}

		return true;
	}
}