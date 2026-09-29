<?php
/**
 * PlanDose → Διαγνωστικά: the checks behind the admin screen and
 * `wp plandose check` — cron, invoice storage privacy, database, caching,
 * environment. Each check returns PASS/WARN/FAIL with a one-line fix hint.
 *
 * Also registers the few callbacks that must exist on every request
 * (WP-Cron runs outside wp-admin): the cleanup-cron heartbeat and the
 * legacy invoice migration's cron event.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Diagnostics {

	const PASS = 'PASS';
	const WARN = 'WARN';
	const FAIL = 'FAIL';

	/** Non-autoloaded option: time of the last daily cleanup run. */
	const CRON_LAST_RUN_OPTION = 'plandose_cleanup_last_run';

	/**
	 * Non-autoloaded option: the daily jobs the last cron run skipped
	 * because their tables were not upgraded (see gate_daily_jobs()).
	 */
	const CRON_SKIPPED_OPTION = 'plandose_cleanup_skipped';

	/** A daily job not seen for this long is overdue. */
	const CRON_OVERDUE = 172800;

	/** Minimum versions (the plugin header's Requires PHP / Requires at least). */
	const MIN_PHP = '8.0';
	const MIN_WP  = '6.3';

	/** Admin page slug. */
	const PAGE = 'plandose-diagnostics';

	/**
	 * Page-cache plugins: basename => array( name, what to configure ).
	 */
	const CACHE_PLUGINS = array(
		'wp-rocket/wp-rocket.php'                    => array( 'WP Rocket', 'Απενεργοποιήστε το «Enable caching for logged-in WordPress users».' ),
		'w3-total-cache/w3-total-cache.php'          => array( 'W3 Total Cache', 'Page Cache: ενεργό το «Don\'t cache pages for logged in users».' ),
		'litespeed-cache/litespeed-cache.php'        => array( 'LiteSpeed Cache', 'Cache: «Cache Logged-in Users» = OFF, ή εξαίρεση των σελίδων του εργαλείου στο Excludes → Do Not Cache URIs.' ),
		'wp-super-cache/wp-cache.php'                => array( 'WP Super Cache', 'Κρατήστε ενεργό το «Disable caching for visitors who have a cookie set / logged in».' ),
		'wp-fastest-cache/wpFastestCache.php'        => array( 'WP Fastest Cache', 'Μην ενεργοποιείτε cache για συνδεδεμένους· εξαιρέστε τις σελίδες του εργαλείου στο Exclude.' ),
		'sg-cachepress/sg-cachepress.php'            => array( 'SiteGround Optimizer', 'Εξαιρέστε τις σελίδες του εργαλείου στο Dynamic Caching → Exclude URLs.' ),
		'cloudflare/cloudflare.php'                  => array( 'Cloudflare (APO)', 'Στο APO ενεργό το «Bypass cache on cookie» ώστε το wordpress_logged_in να παρακάμπτει την cache.' ),
		'nginx-helper/nginx-helper.php'              => array( 'Nginx Helper (FastCGI cache)', 'Στο vhost: παράκαμψη της cache όταν υπάρχει cookie wordpress_logged_in (set $skip_cache 1).' ),
		'breeze/breeze.php'                          => array( 'Breeze', 'Απενεργοποιημένο το cache για συνδεδεμένους χρήστες.' ),
		'hummingbird-performance/wp-hummingbird.php' => array( 'Hummingbird', 'Page Caching: «Cache for logged in users» = OFF.' ),
		'wp-optimize/wp-optimize.php'                => array( 'WP-Optimize', '«Serve cached pages to logged in users» = OFF.' ),
		'cache-enabler/cache-enabler.php'            => array( 'Cache Enabler', 'Από προεπιλογή δεν κάνει cache σε συνδεδεμένους· μην το αλλάξετε.' ),
		'comet-cache/comet-cache.php'                => array( 'Comet Cache', 'Logged-In Users: μην ενεργοποιείτε cache για συνδεδεμένους.' ),
	);

	/** @var bool */
	private static $initialized = false;

	/**
	 * Called on every request from plandose_init().
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		// Late priority: runs after the plugin's own cleanup jobs on the
		// same hook, so the recorded time means "the daily jobs ran".
		if ( defined( 'PLANDOSE_CLEANUP_CRON_HOOK' ) ) {
			add_action( PLANDOSE_CLEANUP_CRON_HOOK, array( __CLASS__, 'record_cron_run' ), 999 );
		}

		if ( class_exists( 'Plandose_Invoice_Migration' ) && method_exists( 'Plandose_Invoice_Migration', 'init_cron' ) ) {
			Plandose_Invoice_Migration::init_cron();
		}

		if ( is_admin() ) {
			add_action( 'admin_post_plandose_diagnostics_reprobe', array( __CLASS__, 'handle_reprobe' ) );
			// Months whose print history could not be archived.
			add_action( 'admin_post_plandose_unarchived', array( __CLASS__, 'handle_unarchived' ) );
			add_action( 'admin_notices', array( __CLASS__, 'render_unarchived_notice' ) );
		}
	}

	/** @var string Page hook of the stand-alone Διαγνωστικά menu, if added. */
	private static $standalone_hook = '';

	/**
	 * While the database upgrade is failing, the PlanDose menu
	 * (Plandose_Admin::admin_menu()) is not registered — its screens need
	 * the upgraded tables — but Διαγνωστικά is exactly what an administrator
	 * needs then, so it gets a top-level entry of its own.
	 */
	public static function init_standalone_menu() {
		add_action( 'admin_menu', array( __CLASS__, 'register_standalone_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'standalone_assets' ) );
	}

	/**
	 * Hooked to 'admin_menu' by init_standalone_menu().
	 */
	public static function register_standalone_menu() {
		if ( ! class_exists( 'Plandose_Admin' ) ) {
			return;
		}

		$hook = add_menu_page(
			__( 'PlanDose → Διαγνωστικά', 'plandose' ),
			__( 'PlanDose', 'plandose' ),
			Plandose_Admin::capability(),
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			'dashicons-clipboard',
			58
		);

		self::$standalone_hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * The admin CSS/JS on the stand-alone page (its screen id is not one of
	 * Plandose_Admin::admin_screen_ids(), so it is passed as the regular
	 * Διαγνωστικά screen).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function standalone_assets( $hook ) {
		if ( '' === self::$standalone_hook || (string) $hook !== self::$standalone_hook ) {
			return;
		}

		if ( class_exists( 'Plandose_Admin' ) && method_exists( 'Plandose_Admin', 'admin_assets' ) ) {
			Plandose_Admin::admin_assets( 'plandose_page_' . self::PAGE );
		}
	}

	/**
	 * Which daily jobs cannot run on the current schema.
	 *
	 * Only while the stored schema version differs from the code's (an
	 * upgrade failing, or in progress): each job's tables must exist with
	 * every column the schema defines (expected_columns()), else the job is
	 * returned for plandose_gate_daily_jobs() to unhook for this run.
	 *
	 * The heartbeat (record_cron_run()) is still recorded: it answers «does
	 * WP-Cron fire the daily event», which it does. Recording nothing would
	 * have Διαγνωστικά blame WP-Cron («overdue») for what is a database
	 * problem. Instead the skipped jobs are stored in CRON_SKIPPED_OPTION
	 * (a WARN in check_cron()) and written to the PHP error log.
	 *
	 * @param array $jobs plandose_daily_jobs().
	 * @return array The jobs to skip.
	 */
	public static function gate_daily_jobs( array $jobs ) {
		$stored = (string) get_option( 'plandose_version', '' );

		if ( defined( 'PLANDOSE_VERSION' ) && PLANDOSE_VERSION === $stored ) {
			delete_option( self::CRON_SKIPPED_OPTION );

			return array();
		}

		$expected  = self::expected_columns();
		$ready     = array();
		$skip      = array();
		$labels    = array();
		$not_ready = array();

		foreach ( $jobs as $job ) {
			foreach ( isset( $job['tables'] ) ? (array) $job['tables'] : array() as $table ) {
				if ( ! isset( $ready[ $table ] ) ) {
					$ready[ $table ] = self::table_ready( $table, isset( $expected[ $table ] ) ? (array) $expected[ $table ] : array() );

					if ( ! $ready[ $table ] ) {
						$not_ready[] = $table;
					}
				}

				if ( ! $ready[ $table ] ) {
					$skip[]   = $job;
					$labels[] = self::callback_label( $job['callback'] );
					break;
				}
			}
		}

		if ( ! $skip ) {
			delete_option( self::CRON_SKIPPED_OPTION );

			return array();
		}

		update_option(
			self::CRON_SKIPPED_OPTION,
			array(
				'at'     => time(),
				'jobs'   => $labels,
				'tables' => $not_ready,
			),
			false
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- failure logged for the site admin (debug.log), not shown to users.
		error_log( 'PlanDose: daily cleanup skipped ' . implode( ', ', $labels ) . ' — database upgrade not complete (plandose_version ' . ( '' === $stored ? '-' : $stored ) . '; tables missing or incomplete: ' . implode( ', ', $not_ready ) . ').' );

		return $skip;
	}

	/**
	 * Does the table exist with all these columns? Errors are suppressed
	 * (a missing table is the expected case here, not a fault to log).
	 *
	 * @param string   $table   Table name ($wpdb->prefix + fixed suffix).
	 * @param string[] $columns Column names.
	 * @return bool
	 */
	private static function table_ready( $table, array $columns ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema check; $table is $wpdb->prefix + a fixed suffix.
		$found = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
		$error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		if ( '' !== $error || ! is_array( $found ) || ! $found ) {
			return false;
		}

		$found = array_map( 'strtolower', $found );

		foreach ( $columns as $column ) {
			if ( ! in_array( strtolower( (string) $column ), $found, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * «Class::method» of a job callback, for the log and Διαγνωστικά.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private static function callback_label( $callback ) {
		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			return ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . (string) $callback[1];
		}

		return is_string( $callback ) ? $callback : 'callback';
	}

	/**
	 * Closed months whose print total could not be archived and
	 * whose counter was reset anyway (Plandose_Subscriptions::
	 * maybe_reset_month()). FAIL until the list is empty — the daily cron
	 * or «Αρχειοθέτηση ξανά» archives them once the table works, or an
	 * administrator notes the figures and clears the list.
	 *
	 * @return array
	 */
	public static function check_history() {
		$title   = __( 'Αρχειοθέτηση μηνιαίου ιστορικού', 'plandose' );
		$entries = class_exists( 'Plandose_Subscriptions' ) && method_exists( 'Plandose_Subscriptions', 'unarchived_months' )
			? Plandose_Subscriptions::unarchived_months()
			: array();

		if ( ! $entries ) {
			return self::result( self::PASS, $title, __( 'Όλοι οι κλεισμένοι μήνες είναι αρχειοθετημένοι.', 'plandose' ) );
		}

		$details = array();

		foreach ( array_slice( $entries, 0, 50 ) as $entry ) {
			$user      = get_userdata( $entry['user_id'] );
			$details[] = sprintf(
				/* translators: 1: user ID, 2: login (or «διαγραμμένος»), 3: month YYYY-MM, 4: prints, 5: date and time of the reset */
				__( '#%1$d %2$s — %3$s: %4$d εκτυπώσεις (μηδενίστηκε στις %5$s)', 'plandose' ),
				$entry['user_id'],
				$user ? $user->user_login : __( '(διαγραμμένος)', 'plandose' ),
				$entry['ym'],
				$entry['prints'],
				$entry['at'] ? self::format_time( $entry['at'] ) : '—'
			);
		}

		if ( count( $entries ) > 50 ) {
			/* translators: %d: entries not shown */
			$details[] = sprintf( __( '… και άλλοι %d', 'plandose' ), count( $entries ) - 50 );
		}

		return self::result(
			self::FAIL,
			$title,
			sprintf(
				/* translators: %d: number of months */
				_n( '%d κλεισμένος μήνας δεν αρχειοθετήθηκε: ο μετρητής μηδενίστηκε, αλλά το σύνολο του μήνα δεν γράφτηκε στο ιστορικό.', '%d κλεισμένοι μήνες δεν αρχειοθετήθηκαν: οι μετρητές μηδενίστηκαν, αλλά τα σύνολα δεν γράφτηκαν στο ιστορικό.', count( $entries ), 'plandose' ),
				count( $entries )
			),
			__( 'Ελέγξτε τον πίνακα ιστορικού (ενότητα «Βάση δεδομένων» παραπάνω). Όταν λειτουργεί, πατήστε «Αρχειοθέτηση ξανά» (το κάνει και η ημερήσια εργασία). Οι αριθμοί υπάρχουν και στο error log.', 'plandose' ),
			$details
		);
	}

	/**
	 * A notice on every admin screen for whoever manages PlanDose,
	 * while months wait to be archived (not on Διαγνωστικά itself, which
	 * shows the full check). Counts only — the list is in Διαγνωστικά.
	 */
	public static function render_unarchived_notice() {
		if ( ! class_exists( 'Plandose_Admin' ) || ! current_user_can( Plandose_Admin::capability() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen check only.
		if ( isset( $_GET['page'] ) && self::PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$entries = class_exists( 'Plandose_Subscriptions' ) && method_exists( 'Plandose_Subscriptions', 'unarchived_months' )
			? Plandose_Subscriptions::unarchived_months()
			: array();

		if ( ! $entries ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'PlanDose:', 'plandose' ),
			esc_html(
				sprintf(
					/* translators: %d: number of months */
					_n( '%d μήνας ιστορικού εκτυπώσεων δεν αρχειοθετήθηκε (σφάλμα βάσης).', '%d μήνες ιστορικού εκτυπώσεων δεν αρχειοθετήθηκαν (σφάλμα βάσης).', count( $entries ), 'plandose' ),
					count( $entries )
				)
			),
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Δείτε τα Διαγνωστικά', 'plandose' )
		);
	}

	/**
	 * «Αρχειοθέτηση ξανά» / «Καθαρισμός λίστας» for the months
	 * waiting to be archived. POST + nonce + manage_options, audited.
	 */
	public static function handle_unarchived() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Μη επιτρεπτή μέθοδος αιτήματος.', 'plandose' ), '', array( 'response' => 405 ) );
		}

		if ( ! current_user_can( Plandose_Admin::capability() ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'plandose_unarchived' );

		$do   = isset( $_POST['pd_do'] ) ? sanitize_key( wp_unslash( $_POST['pd_do'] ) ) : '';
		$back = admin_url( 'admin.php?page=' . self::PAGE );

		if ( 'retry' === $do ) {
			$outcome = Plandose_Subscriptions::retry_unarchived();
			Plandose_Admin::audit( 'unarchived_retry', 0, $outcome );
			wp_safe_redirect( add_query_arg( array( 'plandose_unarchived' => 'retry', 'archived' => (int) $outcome['archived'], 'left' => (int) $outcome['left'] ), $back ) );
			exit;
		}

		if ( 'dismiss' === $do ) {
			// The figures go into the audit log, so clearing the list never
			// loses them.
			$entries = Plandose_Subscriptions::unarchived_months();
			$summary = array();

			foreach ( $entries as $entry ) {
				$summary[] = $entry['user_id'] . ':' . $entry['ym'] . ':' . $entry['prints'];
			}

			Plandose_Admin::audit( 'unarchived_dismissed', 0, array( 'months' => implode( ',', $summary ) ) );
			Plandose_Subscriptions::forget_unarchived( 0 );
			wp_safe_redirect( add_query_arg( 'plandose_unarchived', 'dismissed', $back ) );
			exit;
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Daily cron heartbeat, plus the 24-hour re-check of the invoice
	 * folder's privacy (only when the folder exists: nothing is created).
	 */
	public static function record_cron_run() {
		update_option( self::CRON_LAST_RUN_OPTION, time(), false );

		if ( class_exists( 'Plandose_Invoice_Storage' ) ) {
			$dir = Plandose_Invoice_Storage::configured_invoice_dir();

			if ( ! is_wp_error( $dir ) && is_dir( $dir ) ) {
				Plandose_Invoice_Storage::privacy_status( true );
			}
		}
	}

	/**
	 * One result row.
	 *
	 * @param string   $status  PASS/WARN/FAIL.
	 * @param string   $title   What was checked.
	 * @param string   $message Outcome.
	 * @param string   $hint    One-line fix ('' when nothing to do).
	 * @param string[] $details Extra lines.
	 * @return array{status:string,title:string,message:string,hint:string,details:string[]}
	 */
	public static function result( $status, $title, $message, $hint = '', $details = array() ) {
		return array(
			'status'  => $status,
			'title'   => (string) $title,
			'message' => (string) $message,
			'hint'    => (string) $hint,
			'details' => array_values( array_map( 'strval', (array) $details ) ),
		);
	}

	/**
	 * Every check, grouped for display.
	 *
	 * @return array<string,array<int,array>> Section title => results.
	 */
	public static function run_all() {
		return array(
			__( 'Προγραμματισμένες εργασίες (cron)', 'plandose' ) => self::check_cron(),
			__( 'Αποθήκευση τιμολογίων', 'plandose' )            => self::check_storage(),
			__( 'Βάση δεδομένων', 'plandose' )                    => self::check_database(),
			__( 'Ιστορικό εκτυπώσεων', 'plandose' )               => array( self::check_history() ),
			__( 'Συμβατότητα με cache', 'plandose' )              => self::check_caching(),
			__( 'Περιβάλλον', 'plandose' )                         => array_merge( self::check_environment(), array( self::check_object_cache() ) ),
		);
	}

	/* --------------------------------------------------------------------
	 * (a) Cron
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<int,array>
	 */
	public static function check_cron() {
		$title   = __( 'Ημερήσιος καθαρισμός', 'plandose' );
		$hook    = defined( 'PLANDOSE_CLEANUP_CRON_HOOK' ) ? PLANDOSE_CLEANUP_CRON_HOOK : 'plandose_daily_cleanup';
		$next    = wp_next_scheduled( $hook );
		$last    = absint( get_option( self::CRON_LAST_RUN_OPTION, 0 ) );
		$now     = time();
		$no_cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$results = array();
		$details = array(
			/* translators: %s: date and time */
			sprintf( __( 'Επόμενη εκτέλεση: %s', 'plandose' ), $next ? self::format_time( $next ) : '—' ),
			/* translators: %s: date and time */
			sprintf( __( 'Τελευταία εκτέλεση: %s', 'plandose' ), $last ? self::format_time( $last ) : __( 'δεν έχει καταγραφεί ακόμη', 'plandose' ) ),
			$no_cron ? __( 'DISABLE_WP_CRON = true (το WP-Cron δεν τρέχει από επισκέψεις).', 'plandose' ) : __( 'DISABLE_WP_CRON δεν έχει οριστεί.', 'plandose' ),
		);

		if ( ! $next ) {
			$results[] = self::result( self::FAIL, $title, __( 'Η ημερήσια εργασία δεν είναι προγραμματισμένη.', 'plandose' ), __( 'Απενεργοποιήστε και ενεργοποιήστε ξανά το PlanDose· αν επιμένει, ελέγξτε το error log (WP_DEBUG).', 'plandose' ), $details );
			return $results;
		}

		$overdue_since = $last ? $now - $last : ( $next < $now ? $now - $next : 0 );
		$cron_hint     = $no_cron
			? __( 'Ζητήστε από τον πάροχο φιλοξενίας να ορίσει cron συστήματος που καλεί το wp-cron.php κάθε 5 λεπτά.', 'plandose' )
			: __( 'Το WP-Cron τρέχει μόνο όταν το site έχει επισκέψεις· ζητήστε από τον πάροχο φιλοξενίας cron συστήματος για το wp-cron.php κάθε 5 λεπτά.', 'plandose' );

		if ( $overdue_since > self::CRON_OVERDUE ) {
			$results[] = self::result( self::FAIL, $title, __( 'Η ημερήσια εργασία έχει να τρέξει πάνω από 48 ώρες.', 'plandose' ), $cron_hint, $details );
		} elseif ( $no_cron && ! $last ) {
			$results[] = self::result( self::WARN, $title, __( 'Το WP-Cron είναι απενεργοποιημένο και δεν έχει καταγραφεί ακόμη εκτέλεση.', 'plandose' ), $cron_hint, $details );
		} else {
			$results[] = self::result(
				self::PASS,
				$title,
				$last ? __( 'Η ημερήσια εργασία τρέχει κανονικά.', 'plandose' ) : __( 'Η ημερήσια εργασία είναι προγραμματισμένη (δεν έχει καταγραφεί ακόμη εκτέλεση).', 'plandose' ),
				'',
				$details
			);
		}

		// The last run skipped jobs because the schema was not upgraded
		// (gate_daily_jobs()): WP-Cron works, the database does not.
		$skipped = get_option( self::CRON_SKIPPED_OPTION );

		if ( is_array( $skipped ) && ! empty( $skipped['jobs'] ) ) {
			$skipped_details = array_map( 'strval', (array) $skipped['jobs'] );

			if ( ! empty( $skipped['tables'] ) ) {
				/* translators: %s: table names */
				$skipped_details[] = sprintf( __( 'Πίνακες που λείπουν ή είναι ελλιπείς: %s', 'plandose' ), implode( ', ', array_map( 'strval', (array) $skipped['tables'] ) ) );
			}

			$results[] = self::result(
				self::WARN,
				__( 'Εργασίες που παραλείφθηκαν', 'plandose' ),
				sprintf(
					/* translators: %s: date and time of the run */
					__( 'Η ημερήσια εκτέλεση της %s παρέλειψε εργασίες, επειδή η αναβάθμιση της βάσης δεν είχε ολοκληρωθεί.', 'plandose' ),
					! empty( $skipped['at'] ) ? self::format_time( (int) $skipped['at'] ) : '—'
				),
				__( 'Ολοκληρώστε την αναβάθμιση της βάσης (ενότητα «Βάση δεδομένων»)· οι εργασίες τρέχουν ξανά στην επόμενη ημερήσια εκτέλεση.', 'plandose' ),
				$skipped_details
			);
		}

		return $results;
	}

	/* --------------------------------------------------------------------
	 * (b) Invoice storage
	 * ------------------------------------------------------------------ */

	/**
	 * Location, permissions, verified privacy and protection files.
	 *
	 * @param array $args {
	 *     @type bool $live    Run a probe now instead of reading the cached verdict.
	 *     @type bool $persist Store the probe verdict (live only).
	 * }
	 * @return array<int,array>
	 */
	public static function check_storage( $args = array() ) {
		$args    = wp_parse_args( $args, array( 'live' => false, 'persist' => true ) );
		$results = array();
		$title   = __( 'Φάκελος τιμολογίων', 'plandose' );

		if ( ! class_exists( 'Plandose_Invoice_Storage' ) ) {
			return array( self::result( self::FAIL, $title, __( 'Η κλάση αποθήκευσης τιμολογίων δεν φορτώθηκε.', 'plandose' ), __( 'Ανεβάστε ξανά ολόκληρο το plugin.', 'plandose' ) ) );
		}

		$dir = Plandose_Invoice_Storage::configured_invoice_dir();

		if ( is_wp_error( $dir ) ) {
			// The message can hold server paths — in the detail lines,
			// which only site administrators see.
			return array( self::result( self::FAIL, $title, __( 'Ο φάκελος τιμολογίων που ορίστηκε δεν είναι έγκυρος.', 'plandose' ), __( 'Διορθώστε τη σταθερά PLANDOSE_INVOICE_DIR στο wp-config.php.', 'plandose' ), array( $dir->get_error_message() ) ) );
		}

		$custom  = Plandose_Invoice_Storage::using_custom_invoice_dir();
		$exists  = is_dir( $dir );
		$details = array(
			( $custom ? 'PLANDOSE_INVOICE_DIR = ' : __( 'Προεπιλογή (wp-content/uploads): ', 'plandose' ) ) . $dir,
			/* translators: %s: environment type */
			sprintf( __( 'Περιβάλλον: %s', 'plandose' ), function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production' ),
		);

		if ( ! $exists ) {
			$results[] = self::result( self::WARN, $title, __( 'Ο φάκελος δεν έχει δημιουργηθεί ακόμη (δεν έχει ανέβει τιμολόγιο).', 'plandose' ), __( 'Δημιουργείται με το πρώτο upload· ο έλεγχος ιδιωτικότητας γίνεται τότε αυτόματα.', 'plandose' ), $details );
		} elseif ( ! wp_is_writable( $dir ) ) {
			$results[] = self::result( self::FAIL, $title, __( 'Ο φάκελος δεν είναι εγγράψιμος από τον server.', 'plandose' ), __( 'Δώστε δικαίωμα εγγραφής στον χρήστη του PHP για τον φάκελο.', 'plandose' ), $details );
		} else {
			$results[] = self::result( self::PASS, $title, __( 'Ο φάκελος υπάρχει και είναι εγγράψιμος.', 'plandose' ), '', $details );
		}

		if ( $args['live'] ) {
			$state = $exists
				? Plandose_Invoice_Storage::probe_privacy( $dir, (bool) $args['persist'] )
				: Plandose_Invoice_Storage::probe_privacy( $dir, false );
		} else {
			$state = Plandose_Invoice_Storage::privacy_status( false );
		}

		$results[] = self::privacy_result( $state );

		if ( $exists ) {
			$results[] = self::protection_files_result( $dir );
		}

		return $results;
	}

	/**
	 * A privacy verdict as a result row.
	 *
	 * @param array $state privacy_status()/probe_privacy() result.
	 * @return array
	 */
	public static function privacy_result( array $state ) {
		$title      = __( 'Ιδιωτικότητα τιμολογίων (έλεγχος HTTP)', 'plandose' );
		$production = Plandose_Invoice_Storage::is_production();
		$reason     = Plandose_Invoice_Storage::privacy_reason_label( $state );
		$details    = array();

		if ( isset( $state['reason'] ) && 'dir_missing' === $state['reason'] ) {
			return self::result( self::WARN, $title, __( 'Ο φάκελος δεν υπάρχει ακόμη, οπότε δεν υπάρχει τίποτα εκτεθειμένο· ο έλεγχος γίνεται αυτόματα στο πρώτο upload.', 'plandose' ) );
		}

		if ( ! empty( $state['url'] ) ) {
			/* translators: %s: URL */
			$details[] = sprintf( __( 'Διεύθυνση φακέλου: %s/', 'plandose' ), $state['url'] );
		}

		if ( ! empty( $state['control_url'] ) ) {
			/* translators: 1: URL, 2: HTTP status */
			$details[] = sprintf( __( 'Αρχείο σύγκρισης σε δημόσιο φάκελο: %1$s → HTTP %2$d', 'plandose' ), $state['control_url'], (int) $state['control_code'] );
		}

		if ( ! empty( $state['checked_at'] ) ) {
			/* translators: %s: date and time */
			$details[] = sprintf( __( 'Τελευταίος έλεγχος: %s', 'plandose' ), self::format_time( (int) $state['checked_at'] ) );
		}

		$fix = Plandose_Invoice_Storage::refusal_fix( $state );

		// Run without DOCUMENT_ROOT (WP-CLI): not a verdict, not cached.
		if ( isset( $state['reason'] ) && 'docroot_unknown' === $state['reason'] ) {
			return self::result( self::WARN, $title, __( 'Ο φάκελος δεν έχει διεύθυνση στο site, αλλά από τη γραμμή εντολών δεν φαίνεται αν βρίσκεται μέσα στον δημόσιο χώρο του server.', 'plandose' ), __( 'Ξανατρέξτε με --docroot=<document root του site>, ή δείτε PlanDose → Διαγνωστικά στο wp-admin.', 'plandose' ), $details );
		}

		switch ( isset( $state['status'] ) ? $state['status'] : '' ) {
			case 'private':
				if ( 'outside_web_root' === $state['reason'] && empty( $state['docroot_known'] ) ) {
					return self::result( self::WARN, $title, __( 'Ο φάκελος δεν έχει διεύθυνση στο site, αλλά από τη γραμμή εντολών δεν φαίνεται αν βρίσκεται μέσα στον δημόσιο χώρο του server.', 'plandose' ), __( 'Ξανατρέξτε με --docroot=<document root του site>, ή δείτε PlanDose → Διαγνωστικά στο wp-admin.', 'plandose' ), $details );
				}

				if ( ! empty( $state['cli_kept'] ) ) {
					return self::result( self::WARN, $title, __( 'Από τη γραμμή εντολών ο έλεγχος δεν μπορεί να γίνει· ισχύει ο τελευταίος έλεγχος του web server, που έδειξε κλειστό φάκελο.', 'plandose' ), __( 'Πατήστε «Επανέλεγχος» στο PlanDose → Διαγνωστικά ή ξανατρέξτε με --docroot=<document root του site>. Μετά από 7 ημέρες χωρίς νέο έλεγχο η μεταφορά τιμολογίων σταματά.', 'plandose' ), array_merge( array( $reason ), $details ) );
				}

				if ( ! empty( $state['stale'] ) ) {
					return self::result( self::WARN, $title, __( 'Ο τελευταίος έλεγχος έδειξε κλειστό φάκελο, αλλά έγινε πριν από πάνω από 24 ώρες.', 'plandose' ), __( 'Πατήστε «Επανέλεγχος» (γίνεται και αυτόματα κάθε μέρα).', 'plandose' ), array_merge( array( $reason ), $details ) );
				}

				if ( 'http_refused' === $state['reason'] ) {
					$details[] = __( 'Το αίτημα έγινε από τον ίδιο τον server· αν υπάρχει CDN μπροστά, επιβεβαιώστε και από εξωτερικό δίκτυο.', 'plandose' );
				}

				return self::result( self::PASS, $title, __( 'Ο φάκελος τιμολογίων είναι κλειστός για το internet', 'plandose' ) . ' (' . $reason . ').', '', $details );

			case 'public':
				return self::result( self::FAIL, $title, __( 'Ο φάκελος τιμολογίων είναι ανοιχτός στο internet: ένα δοκιμαστικό αρχείο του κατέβηκε χωρίς σύνδεση.', 'plandose' ) . ' ' . __( 'Γι\' αυτό τα νέα τιμολόγια δεν αποθηκεύονται.', 'plandose' ), $fix, $details );

			case 'unverified':
				$problem = sprintf(
					/* translators: %s: reason */
					__( 'Δεν επιβεβαιώθηκε ότι ο φάκελος τιμολογίων είναι κλειστός για το internet (%s).', 'plandose' ),
					$reason
				);

				if ( ! $production ) {
					return self::result( self::WARN, $title, $problem . ' ' . __( 'Δοκιμαστικό site: μόνο προειδοποίηση.', 'plandose' ), $fix, $details );
				}

				if ( Plandose_Invoice_Storage::unverified_allowed() ) {
					return self::result( self::WARN, $title, $problem . ' ' . __( 'Τα τιμολόγια αποθηκεύονται, επειδή έχει οριστεί PLANDOSE_ALLOW_UNVERIFIED_INVOICE_DIR.', 'plandose' ), $fix, $details );
				}

				return self::result( self::FAIL, $title, $problem . ' ' . __( 'Γι\' αυτό τα νέα τιμολόγια δεν αποθηκεύονται.', 'plandose' ), $fix, $details );

			default:
				return self::result( self::WARN, $title, __( 'Δεν έχει γίνει ακόμη έλεγχος.', 'plandose' ), __( 'Πατήστε «Επανέλεγχος» (γίνεται και αυτόματα στο πρώτο upload).', 'plandose' ), $details );
		}
	}

	/**
	 * The .htaccess / web.config / index files in the folder.
	 *
	 * @param string $dir Existing invoice folder.
	 * @return array
	 */
	private static function protection_files_result( $dir ) {
		$title   = __( 'Αρχεία προστασίας φακέλου', 'plandose' );
		$problem = array();
		$foreign = false;

		foreach ( array_keys( Plandose_Invoice_Storage::protection_file_templates() ) as $name ) {
			$state = Plandose_Invoice_Storage::protection_file_state( trailingslashit( $dir ) . $name, $name );

			if ( 'current' === $state ) {
				continue;
			}

			$labels = array(
				'missing'  => __( 'λείπει', 'plandose' ),
				'outdated' => __( 'παλιά έκδοση', 'plandose' ),
				'foreign'  => __( 'άλλο περιεχόμενο', 'plandose' ),
			);

			$problem[] = $name . ': ' . ( isset( $labels[ $state ] ) ? $labels[ $state ] : $state );
			$foreign   = $foreign || 'foreign' === $state;
		}

		if ( empty( $problem ) ) {
			return self::result( self::PASS, $title, __( 'Όλα τα αρχεία προστασίας υπάρχουν στην τρέχουσα μορφή.', 'plandose' ) );
		}

		return self::result(
			self::WARN,
			$title,
			__( 'Κάποια αρχεία προστασίας λείπουν ή διαφέρουν. (Η πραγματική προστασία κρίνεται από τον έλεγχο HTTP παραπάνω.)', 'plandose' ),
			$foreign
				? __( 'Ένα αρχείο έχει περιεχόμενο που δεν έγραψε το PlanDose και δεν αλλάζει αυτόματα· ελέγξτε το χειροκίνητα.', 'plandose' )
				: __( 'Επαναγράφονται αυτόματα με το επόμενο upload ή «Επανέλεγχο».', 'plandose' ),
			$problem
		);
	}

	/* --------------------------------------------------------------------
	 * (c) Database
	 * ------------------------------------------------------------------ */

	/**
	 * The five plugin tables, their engine, and the stored version.
	 *
	 * @return array<int,array>
	 */
	public static function check_database() {
		global $wpdb;

		$results = array();
		$tables  = self::plugin_tables();

		if ( empty( $tables ) ) {
			return array( self::result( self::FAIL, __( 'Πίνακες', 'plandose' ), __( 'Οι κλάσεις του plugin δεν φορτώθηκαν.', 'plandose' ), __( 'Ανεβάστε ξανά ολόκληρο το plugin.', 'plandose' ) ) );
		}

		$names        = array_keys( $tables );
		$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Schema check; placeholders built above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME AS t, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$placeholders} is a generated list of %s.
				$names
			)
		);
		// phpcs:enable

		$missing = array();

		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			$missing   = array_keys( $tables );
			$results[] = self::result( self::WARN, __( 'Πίνακες', 'plandose' ), __( 'Ο έλεγχος των πινάκων απέτυχε.', 'plandose' ), __( 'Ζητήστε από τον πάροχο φιλοξενίας να ελέγξει τα δικαιώματα του χρήστη της βάσης δεδομένων.', 'plandose' ), array( (string) $wpdb->last_error ) );
		} else {
			$found = array();

			foreach ( $rows as $row ) {
				$found[ strtolower( (string) $row->t ) ] = (string) $row->e;
			}

			$missing = array();
			$bad     = array();
			$soft    = array();

			foreach ( $tables as $table => $needs_transactions ) {
				$key = strtolower( $table );

				if ( ! isset( $found[ $key ] ) ) {
					$missing[] = $table;
				} elseif ( 0 !== strcasecmp( $found[ $key ], 'InnoDB' ) ) {
					if ( $needs_transactions ) {
						$bad[] = $table . ': ' . $found[ $key ];
					} else {
						$soft[] = $table . ': ' . $found[ $key ];
					}
				}
			}

			if ( $missing ) {
				$results[] = self::result( self::FAIL, __( 'Πίνακες', 'plandose' ), __( 'Λείπουν πίνακες του PlanDose.', 'plandose' ), __( 'Απενεργοποιήστε και ενεργοποιήστε ξανά το plugin· αν επιμένει, ελέγξτε ότι ο χρήστης της βάσης έχει δικαίωμα CREATE.', 'plandose' ), $missing );
			} else {
				$results[] = self::result( self::PASS, __( 'Πίνακες', 'plandose' ), sprintf(
					/* translators: %d: number of tables */
					_n( 'Υπάρχει ο %d πίνακας του PlanDose.', 'Υπάρχουν και οι %d πίνακες του PlanDose.', count( $names ), 'plandose' ),
					count( $names )
				), '', $names );
			}

			if ( $bad ) {
				$results[] = self::result( self::FAIL, __( 'Συναλλαγές (InnoDB)', 'plandose' ), __( 'Πίνακες χρεώσεων χωρίς συναλλαγές: η «χρέωση και απόδειξη μαζί» δεν είναι εγγυημένη.', 'plandose' ), __( 'Ζητήστε από τον πάροχο φιλοξενίας να μετατρέψει αυτούς τους πίνακες σε InnoDB (ALTER TABLE <πίνακας> ENGINE=InnoDB).', 'plandose' ), $bad );
			} elseif ( $soft ) {
				$results[] = self::result( self::WARN, __( 'Συναλλαγές (InnoDB)', 'plandose' ), __( 'Κάποιοι βοηθητικοί πίνακες δεν είναι InnoDB.', 'plandose' ), __( 'Προαιρετικά, ζητήστε από τον πάροχο να τους μετατρέψει σε InnoDB.', 'plandose' ), $soft );
			} elseif ( ! $missing ) {
				$results[] = self::result( self::PASS, __( 'Συναλλαγές (InnoDB)', 'plandose' ), __( 'Όλοι οι πίνακες είναι InnoDB.', 'plandose' ) );
			}
		}

		if ( ! $missing && '' === (string) $wpdb->last_error ) {
			$results[] = self::columns_result();
		}

		$stored   = (string) get_option( 'plandose_version', '' );
		$expected = defined( 'PLANDOSE_VERSION' ) ? PLANDOSE_VERSION : '';

		$results[] = $stored === $expected
			? self::result( self::PASS, __( 'Έκδοση σχήματος', 'plandose' ), __( 'Η αποθηκευμένη έκδοση ταιριάζει με τον κώδικα.', 'plandose' ), '', array( 'plandose_version = ' . $stored ) )
			: self::result( self::WARN, __( 'Έκδοση σχήματος', 'plandose' ), __( 'Η αποθηκευμένη έκδοση διαφέρει από τον κώδικα (η αναβάθμιση της βάσης δεν ολοκληρώθηκε).', 'plandose' ), __( 'Φορτώστε μια σελίδα του wp-admin· αν παραμένει, απενεργοποιήστε/ενεργοποιήστε το plugin και δείτε το error log.', 'plandose' ), array( 'plandose_version = ' . ( '' === $stored ? '—' : $stored ), 'PLANDOSE_VERSION = ' . $expected ) );

		return $results;
	}

	/**
	 * Every column the schema defines exists (SHOW COLUMNS), e.g.
	 * print_requests.replay_count, which an upgrade adds to existing tables.
	 *
	 * @return array Result row.
	 */
	public static function columns_result() {
		global $wpdb;

		$title   = __( 'Στήλες πινάκων', 'plandose' );
		$missing = array();
		$failed  = array();

		foreach ( self::expected_columns() as $table => $columns ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema check; $table is $wpdb->prefix + a fixed suffix.
			$found = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );

			if ( '' !== (string) $wpdb->last_error || ! is_array( $found ) ) {
				$failed[] = $table;
				continue;
			}

			$found = array_map( 'strtolower', $found );

			foreach ( $columns as $column ) {
				if ( ! in_array( strtolower( $column ), $found, true ) ) {
					$missing[] = $table . '.' . $column;
				}
			}
		}

		if ( $missing ) {
			return self::result(
				self::FAIL,
				$title,
				__( 'Λείπουν στήλες από πίνακες του PlanDose: η αναβάθμιση της βάσης δεν ολοκληρώθηκε.', 'plandose' ),
				__( 'Απενεργοποιήστε και ενεργοποιήστε ξανά το PlanDose (η αναβάθμιση της βάσης θα ξανατρέξει). Αν συνεχίζει, ζητήστε από τον πάροχο να ελέγξει ότι ο χρήστης της βάσης έχει δικαίωμα ALTER.', 'plandose' ),
				$missing
			);
		}

		if ( $failed ) {
			return self::result( self::WARN, $title, __( 'Δεν ήταν δυνατός ο έλεγχος των στηλών.', 'plandose' ), __( 'Ζητήστε από τον πάροχο φιλοξενίας να ελέγξει τα δικαιώματα του χρήστη της βάσης δεδομένων.', 'plandose' ), $failed );
		}

		return self::result( self::PASS, $title, __( 'Όλες οι στήλες που ορίζει το σχήμα υπάρχουν.', 'plandose' ) );
	}

	/**
	 * Table => column names, read from the CREATE TABLE statements the
	 * plugin gives dbDelta(). The Print_Charges and Print_Log schema_sql()
	 * are parsed directly;
	 * the three tables whose SQL is inline in create_table() are listed
	 * here and must be kept in step with it.
	 *
	 * @return array<string,string[]>
	 */
	public static function expected_columns() {
		global $wpdb;

		$columns = array();
		$sql     = array();

		if ( class_exists( 'Plandose_Subscriptions' ) ) {
			$columns[ Plandose_Subscriptions::table_name() ]         = array( 'user_id', 'status', 'sub_end_date', 'print_count', 'count_reset_at', 'last_print_at', 'invoices' );
			$columns[ Plandose_Subscriptions::audit_table_name() ]   = array( 'id', 'created_at', 'event', 'admin_user_id', 'target_user_id', 'ip', 'meta' );
			$columns[ Plandose_Subscriptions::history_table_name() ] = array( 'user_id', 'ym', 'prints', 'archived_at' );
		}

		if ( class_exists( 'Plandose_Print_Charges' ) && method_exists( 'Plandose_Print_Charges', 'schema_sql' ) ) {
			$sql = array_merge( $sql, (array) Plandose_Print_Charges::schema_sql( $wpdb->get_charset_collate() ) );
		}

		if ( class_exists( 'Plandose_Print_Log' ) ) {
			$sql = array_merge( $sql, (array) Plandose_Print_Log::schema_sql( $wpdb->get_charset_collate() ) );
		}

		foreach ( $sql as $statement ) {
			$parsed = self::parse_create_table( (string) $statement );

			if ( $parsed ) {
				$columns[ $parsed['table'] ] = $parsed['columns'];
			}
		}

		/**
		 * Filters the expected columns per table (tests; add-ons).
		 *
		 * @param array<string,string[]> $columns Table => column names.
		 */
		return (array) apply_filters( 'plandose_diagnostics_expected_columns', $columns );
	}

	/**
	 * Table name and column names of one dbDelta()-style CREATE TABLE.
	 *
	 * @param string $sql Statement.
	 * @return array{table:string,columns:string[]}|null
	 */
	public static function parse_create_table( $sql ) {
		if ( ! preg_match( '/CREATE\s+TABLE\s+`?([A-Za-z0-9_$]+)`?\s*\((.*)\)[^)]*$/s', $sql, $m ) ) {
			return null;
		}

		$columns = array();

		foreach ( preg_split( '/\r?\n/', $m[2] ) as $line ) {
			$line = trim( $line );

			if ( ! preg_match( '/^`?([A-Za-z0-9_]+)`?\s+[A-Za-z]/', $line, $c ) ) {
				continue;
			}

			if ( in_array( strtoupper( $c[1] ), array( 'PRIMARY', 'KEY', 'UNIQUE', 'INDEX', 'FULLTEXT', 'SPATIAL', 'CONSTRAINT', 'FOREIGN', 'CHECK' ), true ) ) {
				continue;
			}

			$columns[] = $c[1];
		}

		return $columns ? array( 'table' => $m[1], 'columns' => $columns ) : null;
	}

	/**
	 * The plugin's tables => whether they must support transactions.
	 *
	 * @return array<string,bool>
	 */
	public static function plugin_tables() {
		$tables = array();

		if ( class_exists( 'Plandose_Subscriptions' ) ) {
			$tables[ Plandose_Subscriptions::table_name() ]         = true;
			$tables[ Plandose_Subscriptions::audit_table_name() ]   = false;
			$tables[ Plandose_Subscriptions::history_table_name() ] = false;
		}

		if ( class_exists( 'Plandose_Print_Charges' ) ) {
			$tables[ Plandose_Print_Charges::table_name() ]          = true;
			$tables[ Plandose_Print_Charges::requests_table_name() ] = true;
		}

		// Written after the charge commit, outside any transaction.
		if ( class_exists( 'Plandose_Print_Log' ) ) {
			$tables[ Plandose_Print_Log::table_name() ] = false;
		}

		return $tables;
	}

	/* --------------------------------------------------------------------
	 * (d) Caching
	 * ------------------------------------------------------------------ */

	/**
	 * Page-cache plugins/drop-ins and what each needs so a pharmacist's
	 * page is never cached. Settings are read where that is possible (WP
	 * Rocket); the real leak test needs `wp plandose check` (it signs in).
	 *
	 * @return array<int,array>
	 */
	public static function check_caching() {
		$results  = array();
		$title    = __( 'Page cache', 'plandose' );
		$detected = array();

		foreach ( self::CACHE_PLUGINS as $basename => $info ) {
			if ( self::plugin_is_active( $basename ) || ( 'wp-rocket/wp-rocket.php' === $basename && defined( 'WP_ROCKET_VERSION' ) ) ) {
				$detected[] = $info[0] . ' — ' . $info[1];
			}
		}

		$drop_in = defined( 'WP_CONTENT_DIR' ) && file_exists( trailingslashit( WP_CONTENT_DIR ) . 'advanced-cache.php' );
		$wp_cache = defined( 'WP_CACHE' ) && WP_CACHE;

		if ( $drop_in ) {
			$detected[] = __( 'Drop-in wp-content/advanced-cache.php', 'plandose' ) . ( $wp_cache ? ' (WP_CACHE = true)' : ' (WP_CACHE ανενεργό)' );
		} elseif ( $wp_cache ) {
			$detected[] = 'WP_CACHE = true';
		}

		$note = __( 'Το PlanDose στέλνει no-cache headers και ορίζει DONOTCACHEPAGE στις σελίδες του εργαλείου για συνδεδεμένους φαρμακοποιούς· τα περισσότερα plugins το σέβονται, όχι όμως κάθε cache του server ή του CDN.', 'plandose' );

		if ( empty( $detected ) ) {
			$results[] = self::result( self::PASS, $title, __( 'Δεν εντοπίστηκε plugin ή drop-in page cache.', 'plandose' ), '', array( $note, __( 'Cache του server (Nginx/LiteSpeed/CDN) δεν φαίνεται από εδώ: ο πραγματικός έλεγχος διαρροής γίνεται με «wp plandose check».', 'plandose' ) ) );
		} else {
			$results[] = self::result(
				self::WARN,
				$title,
				__( 'Υπάρχει page cache. Οι σελίδες με το εργαλείο δεν πρέπει ποτέ να αποθηκεύονται για συνδεδεμένους φαρμακοποιούς.', 'plandose' ),
				__( 'Ρυθμίστε κάθε cache όπως γράφει παρακάτω και επιβεβαιώστε με «wp plandose check» (συνδέεται ως φαρμακείο και ελέγχει πραγματική διαρροή).', 'plandose' ),
				array_merge( $detected, array( $note ) )
			);
		}

		if ( defined( 'WP_ROCKET_VERSION' ) || self::plugin_is_active( 'wp-rocket/wp-rocket.php' ) ) {
			$settings = get_option( 'wp_rocket_settings', array() );

			$results[] = is_array( $settings ) && ! empty( $settings['cache_logged_user'] )
				? self::result( self::FAIL, 'WP Rocket', __( 'Είναι ενεργή η αποθήκευση σελίδων για συνδεδεμένους χρήστες («Enable caching for logged-in WordPress users»).', 'plandose' ), __( 'Απενεργοποιήστε τη ρύθμιση, ή βεβαιωθείτε ότι το WP Rocket κρατά ξεχωριστή cache ανά χρήστη.', 'plandose' ) )
				: self::result( self::PASS, 'WP Rocket', __( 'Η αποθήκευση σελίδων για συνδεδεμένους χρήστες είναι απενεργοποιημένη.', 'plandose' ) );
		}

		if ( self::plugin_is_active( 'nginx-helper/nginx-helper.php' ) ) {
			$results[] = self::result(
				self::WARN,
				'Nginx FastCGI cache',
				__( 'Το Nginx Helper είναι ενεργό, άρα ο Nginx αποθηκεύει σελίδες. Η παράκαμψη για συνδεδεμένους χρήστες ορίζεται στο Nginx, όχι στο WordPress — δεν διαβάζεται από εδώ.', 'plandose' ),
				__( 'Στο vhost: if ( $http_cookie ~* "wordpress_logged_in" ) { set $skip_cache 1; }', 'plandose' )
			);
		}

		return $results;
	}

	/**
	 * is_plugin_active() lives in an admin file WP-CLI and cron do not
	 * always load, so the active list is read directly.
	 *
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	public static function plugin_is_active( $plugin ) {
		if ( in_array( $plugin, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}

		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );

			return isset( $network[ $plugin ] );
		}

		return false;
	}

	/* --------------------------------------------------------------------
	 * (e) Environment, (f) object cache
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<int,array>
	 */
	public static function check_environment() {
		global $wp_version;

		$results = array();
		$wp      = isset( $wp_version ) ? (string) $wp_version : (string) get_bloginfo( 'version' );

		$results[] = version_compare( PHP_VERSION, self::MIN_PHP, '>=' )
			? self::result( self::PASS, 'PHP', 'PHP ' . PHP_VERSION . ' (≥ ' . self::MIN_PHP . ')' )
			: self::result( self::FAIL, 'PHP', 'PHP ' . PHP_VERSION . ' < ' . self::MIN_PHP, __( 'Αναβαθμίστε την PHP από τον πίνακα του παρόχου.', 'plandose' ) );

		$results[] = version_compare( $wp, self::MIN_WP, '>=' )
			? self::result( self::PASS, 'WordPress', 'WordPress ' . $wp . ' (≥ ' . self::MIN_WP . ')' )
			: self::result( self::FAIL, 'WordPress', 'WordPress ' . $wp . ' < ' . self::MIN_WP, __( 'Αναβαθμίστε το WordPress.', 'plandose' ) );

		$results[] = extension_loaded( 'mbstring' )
			? self::result( self::PASS, 'mbstring', __( 'Η επέκταση mbstring είναι διαθέσιμη.', 'plandose' ) )
			: self::result( self::WARN, 'mbstring', __( 'Λείπει η επέκταση mbstring· ελληνικά κείμενα μπορεί να κόβονται λάθος.', 'plandose' ), __( 'Ζητήστε από τον πάροχο να ενεργοποιήσει το php-mbstring.', 'plandose' ) );

		$results[] = extension_loaded( 'fileinfo' )
			? self::result( self::PASS, 'fileinfo', __( 'Η επέκταση fileinfo είναι διαθέσιμη (έλεγχος περιεχομένου τιμολογίων).', 'plandose' ) )
			: self::result( self::WARN, 'fileinfo', __( 'Λείπει η επέκταση fileinfo· ο τύπος των τιμολογίων ελέγχεται μόνο από την υπογραφή του αρχείου.', 'plandose' ), __( 'Ζητήστε από τον πάροχο να ενεργοποιήσει το php-fileinfo.', 'plandose' ) );

		return $results;
	}

	/**
	 * @return array
	 */
	public static function check_object_cache() {
		$title = __( 'Object cache', 'plandose' );

		if ( wp_using_ext_object_cache() ) {
			return self::result( self::PASS, $title, __( 'Υπάρχει μόνιμη object cache (π.χ. Redis/Memcached). Τα κλειδώματα του PlanDose διαβάζουν απευθείας τη βάση, οπότε δεν επηρεάζονται.', 'plandose' ) );
		}

		return self::result( self::PASS, $title, __( 'Δεν υπάρχει μόνιμη object cache (προαιρετική· δεν χρειάζεται για το PlanDose).', 'plandose' ) );
	}

	/* --------------------------------------------------------------------
	 * Admin screen
	 * ------------------------------------------------------------------ */

	/**
	 * PlanDose → Διαγνωστικά. Read-only apart from the «Επανέλεγχος» POST,
	 * which only site administrators see.
	 */
	public static function render_page() {
		if ( ! current_user_can( Plandose_Admin::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		$sections = self::run_all();

		Plandose_Admin::page_header( __( 'PlanDose → Διαγνωστικά', 'plandose' ), __( 'Έλεγχοι λειτουργίας και ασφάλειας για αυτόν τον server.', 'plandose' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display flag set by our own redirect.
		if ( isset( $_GET['plandose_probe'] ) && class_exists( 'Plandose_Invoice_Storage' ) ) {
			$state = Plandose_Invoice_Storage::privacy_status( false );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				'private' === $state['status'] ? 'success' : 'warning',
				esc_html(
					sprintf(
						/* translators: %s: result of the check */
						__( 'Ο έλεγχος ιδιωτικότητας ολοκληρώθηκε: %s.', 'plandose' ),
						Plandose_Invoice_Storage::privacy_reason_label( $state )
					)
				)
			);
		}

		$counts = array(
			self::PASS => 0,
			self::WARN => 0,
			self::FAIL => 0,
		);

		foreach ( $sections as $results ) {
			foreach ( $results as $result ) {
				++$counts[ $result['status'] ];
			}
		}
		?>
		<div class="plandose-main-card plandose-main-card-full">
			<p><strong><?php echo esc_html( sprintf( 'PASS %d · WARN %d · FAIL %d', $counts[ self::PASS ], $counts[ self::WARN ], $counts[ self::FAIL ] ) ); ?></strong></p>
			<?php
			// When these results were taken, and when the invoice
			// folder was last probed over HTTP (that one is cached), so an
			// «OK» is never mistaken for a fresh check.
			$privacy_checked = 0;

			if ( class_exists( 'Plandose_Invoice_Storage' ) ) {
				$privacy_state   = Plandose_Invoice_Storage::privacy_status( false );
				$privacy_checked = isset( $privacy_state['checked_at'] ) ? (int) $privacy_state['checked_at'] : 0;
			}
			?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: date and time of this page's checks, 2: date and time of the last invoice-folder probe (or «ποτέ») */
						__( 'Έλεγχοι σελίδας: %1$s · Τελευταίος έλεγχος ιδιωτικότητας τιμολογίων: %2$s', 'plandose' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
						$privacy_checked > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $privacy_checked ) : __( 'ποτέ', 'plandose' )
					)
				);
				?>
			</p>
			<?php
			// Outcome of «Αρχειοθέτηση ξανά» / «Καθαρισμός λίστας».
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display flags set by our own redirect.
			$unarchived_flag = isset( $_GET['plandose_unarchived'] ) ? sanitize_key( wp_unslash( $_GET['plandose_unarchived'] ) ) : '';
			if ( 'retry' === $unarchived_flag ) {
				$archived_now = isset( $_GET['archived'] ) ? absint( $_GET['archived'] ) : 0;
				$left_now     = isset( $_GET['left'] ) ? absint( $_GET['left'] ) : 0;
				printf(
					'<div class="notice notice-%1$s"><p>%2$s</p></div>',
					$left_now ? 'warning' : 'success',
					esc_html(
						sprintf(
							/* translators: 1: months archived now, 2: months still waiting */
							__( 'Αρχειοθέτηση ξανά — γράφτηκαν στο ιστορικό: %1$d · σε αναμονή: %2$d.', 'plandose' ),
							$archived_now,
							$left_now
						)
					)
				);
			} elseif ( 'dismissed' === $unarchived_flag ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Η λίστα καθαρίστηκε· οι αριθμοί καταγράφηκαν στο audit log.', 'plandose' ) . '</p></div>';
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			?>
			<?php if ( current_user_can( 'manage_options' ) && class_exists( 'Plandose_Subscriptions' ) && Plandose_Subscriptions::unarchived_months() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pd-inline-form">
					<input type="hidden" name="action" value="plandose_unarchived" />
					<input type="hidden" name="pd_do" value="retry" />
					<?php wp_nonce_field( 'plandose_unarchived' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Αρχειοθέτηση ξανά', 'plandose' ); ?></button>
					<span class="description"><?php esc_html_e( 'Γράφει στο ιστορικό τους μήνες που δεν αρχειοθετήθηκαν (το κάνει και η ημερήσια εργασία).', 'plandose' ); ?></span>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pd-inline-form pd-confirm-submit" data-confirm="<?php echo esc_attr__( 'Η λίστα θα καθαριστεί χωρίς να γραφτούν οι μήνες στο ιστορικό (οι αριθμοί μένουν στο audit log). Συνέχεια;', 'plandose' ); ?>">
					<input type="hidden" name="action" value="plandose_unarchived" />
					<input type="hidden" name="pd_do" value="dismiss" />
					<?php wp_nonce_field( 'plandose_unarchived' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Καθαρισμός λίστας', 'plandose' ); ?></button>
				</form>
			<?php endif; ?>
			<?php if ( ! current_user_can( 'manage_options' ) ) : ?>
				<p class="description"><?php esc_html_e( 'Οι τεχνικές λεπτομέρειες (διαδρομές στον server, σφάλματα βάσης, εκδόσεις) εμφανίζονται μόνο σε διαχειριστές του site.', 'plandose' ); ?></p>
			<?php endif; ?>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pd-inline-form">
					<input type="hidden" name="action" value="plandose_diagnostics_reprobe" />
					<?php wp_nonce_field( 'plandose_diagnostics_reprobe' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Επανέλεγχος ιδιωτικότητας τιμολογίων', 'plandose' ); ?></button>
					<span class="description"><?php esc_html_e( 'Γράφει ένα προσωρινό δοκιμαστικό αρχείο στον φάκελο τιμολογίων, το ζητά από τον web server χωρίς σύνδεση και το σβήνει.', 'plandose' ); ?></span>
				</form>
			<?php endif; ?>
		</div>
		<?php foreach ( $sections as $section => $results ) : ?>
			<div class="plandose-main-card plandose-main-card-full">
				<h2><?php echo esc_html( $section ); ?></h2>
				<table class="widefat striped pd-diag-table">
					<thead>
						<tr>
							<th scope="col" style="width:6em"><?php esc_html_e( 'Κατάσταση', 'plandose' ); ?></th>
							<th scope="col" style="width:16em"><?php esc_html_e( 'Έλεγχος', 'plandose' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Αποτέλεσμα', 'plandose' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $results as $result ) : ?>
							<tr>
								<td><span class="pd-diag-status is-<?php echo esc_attr( strtolower( $result['status'] ) ); ?>"><?php echo esc_html( $result['status'] ); ?></span></td>
								<th scope="row"><?php echo esc_html( $result['title'] ); ?></th>
								<td>
									<?php echo esc_html( $result['message'] ); ?>
									<?php if ( '' !== $result['hint'] ) : ?>
										<p class="pd-diag-hint"><?php echo esc_html( __( 'Διόρθωση:', 'plandose' ) . ' ' . $result['hint'] ); ?></p>
									<?php endif; ?>
									<?php if ( $result['details'] && current_user_can( 'manage_options' ) ) : // Paths, DB errors, versions — site administrators only. ?>
										<ul class="pd-diag-details">
											<?php foreach ( $result['details'] as $detail ) : ?>
												<li><?php echo esc_html( $detail ); ?></li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>
		<?php
		Plandose_Admin::page_footer();
	}

	/**
	 * «Επανέλεγχος»: POST + nonce + manage_options. Creates the folder the
	 * same way an upload would (so there is something to test), probes it
	 * and stores the verdict.
	 */
	public static function handle_reprobe() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Μη επιτρεπτή μέθοδος αιτήματος.', 'plandose' ), '', array( 'response' => 405 ) );
		}

		if ( ! current_user_can( Plandose_Admin::capability() ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'plandose_diagnostics_reprobe' );

		$back = admin_url( 'admin.php?page=' . self::PAGE );
		$dir  = Plandose_Invoice_Storage::invoice_dir();

		if ( is_wp_error( $dir ) ) {
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), $dir->get_error_message(), 60 );
			wp_safe_redirect( $back );
			exit;
		}

		$state = Plandose_Invoice_Storage::privacy_status( true, true );

		Plandose_Admin::audit(
			'invoice_storage_probe',
			0,
			array(
				'status' => $state['status'],
				'reason' => $state['reason'],
				'http'   => (int) $state['http_code'],
			)
		);

		wp_safe_redirect( add_query_arg( 'plandose_probe', '1', $back ) );
		exit;
	}

	/**
	 * Site-local date and time.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private static function format_time( $timestamp ) {
		return wp_date( 'Y-m-d H:i', (int) $timestamp );
	}
}
