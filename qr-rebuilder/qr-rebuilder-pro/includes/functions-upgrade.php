<?php
/**
 * Activation, upgrade maintenance and one-off legacy cleanup.
 *
 * Runs on activation and on admin_init for administrators (never in AJAX),
 * one bounded cleanup batch per request. The legacy data (subscription-era
 * user meta, scan history table) is inert, so there is deliberately no cron.
 *
 * @package QR_ReBuilder_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Προσθέτει κάθε option που λείπει. Idempotent και φθηνό· δεν γράφει ποτέ πάνω
 * σε αποθηκευμένη τιμή, ούτε σε κενή (το '' του email είναι ρητή επιλογή).
 */
function qrrp_ensure_default_options() {
	$defaults = array(
		'capability'          => qrrp_default_tool_capability(),
		'tool_page_id'        => 0,
		'email_capability'    => qrrp_default_email_capability(),
		'allow_guests'        => '0',
		'allow_guest_email'   => '0',
		'allow_guest_manual_entry' => '0',
		'guest_email_domains' => '',
		'email_from_name'     => get_bloginfo( 'name' ) . ' - QR ReBuilder Pro',
		'email_from_address'  => get_option( 'admin_email' ),
		'sn_fallback_length'  => qrrp_default_gs1_fallback_length( 'sn' ),
		'lot_fallback_length' => qrrp_default_gs1_fallback_length( 'lot' ),
		'print_barcode_mm'    => qrrp_default_print_barcode_mm(),
		'print_show_note'     => qrrp_default_print_toggle(),
		'print_show_meta'     => qrrp_default_print_toggle(),
		'print_orientation'   => qrrp_default_print_orientation(),
	);

	foreach ( $defaults as $key => $value ) {
		$option_name = 'qrrp_' . $key;

		if ( false === get_option( $option_name, false ) ) {
			add_option( $option_name, $value );
		}
	}

}

/** Σβήνει options χωρίς χρήση. Ένα query ανά κλήση, άρα μόνο σε activation/αναβάθμιση. */
function qrrp_remove_obsolete_options() {
	delete_option( 'qrrp_ai_table' );
	delete_option( 'qrrp_enable_history' );
	delete_option( 'qrrp_sn_trim_trailing' );
}

/** Αναφέρει αποτυχία σταδίου maintenance (action, και log με WP_DEBUG)· μόνο γνωστά ονόματα. */
function qrrp_report_maintenance_failure( $stage ) {
	$stage = sanitize_key( (string) $stage );

	$allowed_stages = array(
		'usermeta_delete',
		'legacy_cleanup_flag_write',
		'drop_history_table',
		'drop_history_table_abandoned',
		'history_drop_state_write',
		'history_drop_done_write',
	);

	if ( ! in_array( $stage, $allowed_stages, true ) ) {
		return;
	}

	do_action( 'qrrp_maintenance_failed', $stage );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only operational trace; carries an allow-listed stage name only, no data.
		error_log( sprintf( 'QRRP maintenance: stage=%s failed', $stage ) );
	}
}

/** Γράφει option και επιβεβαιώνει διαβάζοντας πίσω (το update_option() δεν αρκεί). */
function qrrp_persist_option( $option_name, $value ) {
	update_option( $option_name, $value );

	return (string) get_option( $option_name, '' ) === (string) $value;
}

function qrrp_maybe_drop_history_table() {
	global $wpdb;

	/* Όνομα μόνο από $wpdb->prefix και literal· το DROP TABLE δεν δέχεται placeholder. */
	$qrrp_history_table = esc_sql( $wpdb->prefix . 'qrrp_scan_history' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix only; DROP TABLE cannot be parameterised.
	$result = $wpdb->query( "DROP TABLE IF EXISTS `{$qrrp_history_table}`" );

	return false !== $result;
}

/* Πόσες φορές δοκιμάζεται το DROP πριν η προσπάθεια εγκαταλειφθεί (π.χ. χωρίς δικαίωμα DROP). */
const QRRP_HISTORY_DROP_MAX_ATTEMPTS = 3;

/**
 * Δεσμεύει ατομικά (compare-and-swap) την επόμενη προσπάθεια DROP, ώστε
 * ταυτόχρονα αιτήματα να μην ξεπερνούν το όριο. Query: 1 = δεσμεύτηκε,
 * 0 = άλλο αίτημα κέρδισε (contended), false = σφάλμα βάσης.
 *
 * @return array{attempt: int, claimed: bool, contended: bool}
 */
function qrrp_claim_history_drop_attempt( $current ) {
	global $wpdb;

	$next = max( 0, (int) $current ) + 1;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap; update_option() cannot express "only if unchanged", and caching is invalidated explicitly below.
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			(string) $next,
			'qrrp_history_drop_attempts',
			(string) $current
		)
	);

	if ( 1 === $updated ) {
		/* Απευθείας SQL: ακυρώνεται και το alloptions, γιατί το option είναι autoloaded. */
		wp_cache_delete( 'qrrp_history_drop_attempts', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		return array(
			'attempt'   => $next,
			'claimed'   => true,
			'contended' => false,
		);
	}

	return array(
		'attempt'   => 0,
		'claimed'   => false,
		'contended' => ( false !== $updated ),
	);
}

/**
 * Best-effort DROP του πίνακα ιστορικού με περιορισμένες προσπάθειες.
 *
 * Η προσπάθεια καταγράφεται πριν το DROP· αν η καταγραφή αποτύχει, δεν γίνεται
 * schema change. Μετά το όριο εγκαταλείπεται (drop_history_table_abandoned):
 * ο πίνακας είναι αδρανής, ένας ατέρμονος βρόχος όχι.
 *
 * @return array{ok: bool, settled: bool} settled = σταμάτα να ξαναδοκιμάζεις.
 */
function qrrp_settle_history_table_drop() {
	if ( '1' === get_option( 'qrrp_history_table_dropped', '0' ) ) {
		return array(
			'ok'      => true,
			'settled' => true,
		);
	}

	$stored_attempts = get_option( 'qrrp_history_drop_attempts', false );

	if ( false === $stored_attempts ) {
		qrrp_report_maintenance_failure( 'history_drop_state_write' );

		return array(
			'ok'      => false,
			'settled' => false,
		);
	}

	$attempts = max( 0, (int) $stored_attempts );

	if ( $attempts >= (int) QRRP_HISTORY_DROP_MAX_ATTEMPTS ) {
		return array(
			'ok'      => false,
			'settled' => true,
		);
	}

	$claim = qrrp_claim_history_drop_attempt( $stored_attempts );

	/* Χωρίς δέσμευση, κανένα DROP. Το contended δεν είναι σφάλμα. */
	if ( ! $claim['claimed'] ) {
		if ( ! $claim['contended'] ) {
			qrrp_report_maintenance_failure( 'history_drop_state_write' );
		}

		return array(
			'ok'      => false,
			'settled' => false,
		);
	}

	$next_attempt = $claim['attempt'];

	if ( qrrp_maybe_drop_history_table() ) {
		if ( ! qrrp_persist_option( 'qrrp_history_table_dropped', '1' ) ) {
			qrrp_report_maintenance_failure( 'history_drop_done_write' );

			return array(
				'ok'      => false,
				'settled' => false,
			);
		}

		delete_option( 'qrrp_history_drop_attempts' );

		return array(
			'ok'      => true,
			'settled' => true,
		);
	}

	$exhausted = ( $next_attempt >= (int) QRRP_HISTORY_DROP_MAX_ATTEMPTS );

	qrrp_report_maintenance_failure(
		$exhausted ? 'drop_history_table_abandoned' : 'drop_history_table'
	);

	return array(
		'ok'      => false,
		'settled' => $exhausted,
	);
}

/* Ανώτατο πλήθος διαγραφών usermeta ανά κλήση του cleanup (συνολικά, ένα DELETE). */
const QRRP_LEGACY_CLEANUP_BATCH = 250;

/**
 * Διαγράφει ένα batch από τα user meta του παλιού συστήματος συνδρομών (έως
 * 1.6.x), με ένα DELETE ώστε το όριο να ισχύει κατά γράμμα.
 *
 * @return array{ok: bool, done: bool} done = batch μικρότερο του ορίου.
 */
function qrrp_cleanup_legacy_subscription_data() {
	global $wpdb;

	$batch = (int) QRRP_LEGACY_CLEANUP_BATCH;

	$usage_prefix = $wpdb->esc_like( 'qrrp_usage_' ) . '%';

	/*
	 * 2.16.1: οι χρήστες του batch, για να καθαριστεί και η cache τους· με
	 * persistent object cache τα σβησμένα meta σερβίρονταν ακόμη.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same rows as the DELETE below.
	$user_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$wpdb->usermeta}
			 WHERE ( meta_key IN ( %s, %s, %s, %s ) OR meta_key LIKE %s )
			 LIMIT %d",
			'qrrp_pro_expires',
			'qrrp_pro_activated_on',
			'qrrp_manual_access',
			'qrrp_invoices',
			$usage_prefix,
			$batch
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off legacy cleanup; no core API deletes usermeta by key across all users.
	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta}
			 WHERE ( meta_key IN ( %s, %s, %s, %s ) OR meta_key LIKE %s )
			 ORDER BY umeta_id
			 LIMIT %d",
			'qrrp_pro_expires',
			'qrrp_pro_activated_on',
			'qrrp_manual_access',
			'qrrp_invoices',
			$usage_prefix,
			$batch
		)
	);

	if ( is_array( $user_ids ) && function_exists( 'wp_cache_delete' ) ) {
		foreach ( $user_ids as $uid ) {
			wp_cache_delete( (int) $uid, 'user_meta' );
		}
	}

	if ( false === $deleted ) {
		return array(
			'ok'   => false,
			'done' => false,
		);
	}

	return array(
		'ok'   => true,
		'done' => ( (int) $deleted < $batch ),
	);
}

/**
 * Εφάπαξ καθαρισμός: legacy usermeta σε batches, μετά ανεξάρτητα το DROP.
 *
 * done=1 χωρίς κανένα history option προέρχεται από παλαιότερη έκδοση, όπου
 * σήμαινε ότι είχε πετύχει και το DROP· γι' αυτό ο μετρητής αρχικοποιείται
 * πριν γραφτεί η σημαία.
 *
 * @return bool True όταν τα usermeta έχουν ολοκληρωθεί.
 */
function qrrp_maybe_cleanup_legacy_data() {
	$cleanup_done = '1' === get_option( 'qrrp_legacy_cleanup_done', '0' );

	if ( $cleanup_done ) {
		$history_dropped  = get_option( 'qrrp_history_table_dropped', false );
		$history_attempts = get_option( 'qrrp_history_drop_attempts', false );

		if ( false === $history_dropped && false === $history_attempts ) {
			return true;
		}

		qrrp_settle_history_table_drop();

		return true;
	}

	$subscription = qrrp_cleanup_legacy_subscription_data();

	if ( ! $subscription['ok'] ) {
		qrrp_report_maintenance_failure( 'usermeta_delete' );

		return false;
	}

	if ( ! $subscription['done'] ) {
		return false;
	}

	$history_dropped  = get_option( 'qrrp_history_table_dropped', false );
	$history_attempts = get_option( 'qrrp_history_drop_attempts', false );

	if ( '1' !== (string) $history_dropped && false === $history_attempts ) {
		if ( ! qrrp_persist_option( 'qrrp_history_drop_attempts', '0' ) ) {
			qrrp_report_maintenance_failure( 'history_drop_state_write' );

			return false;
		}
	}

	if ( ! qrrp_persist_option( 'qrrp_legacy_cleanup_done', '1' ) ) {
		qrrp_report_maintenance_failure( 'legacy_cleanup_flag_write' );

		return false;
	}

	qrrp_settle_history_table_drop();

	return true;
}

function qrrp_activate_plugin() {
	$installed_version = (string) get_option( 'qrrp_version', '' );

	qrrp_ensure_default_options();

	qrrp_maybe_migrate_access_choices( $installed_version );

	qrrp_maybe_migrate_guest_manual_entry( $installed_version );

	qrrp_maybe_migrate_verified_email( $installed_version );

	qrrp_remove_obsolete_options();

	qrrp_maybe_cleanup_legacy_data();
	update_option( 'qrrp_version', QRRP_VERSION );
}

/**
 * 2.16.1: οι μεταπτώσεις πρόσβασης (μόνο προς το αυστηρότερο) τρέχουν σε κάθε
 * αίτημα μέχρι να ολοκληρωθεί η αναβάθμιση, όχι μόνο όταν ένας διαχειριστής
 * ανοίξει το wp-admin. Με αυτόματη ενημέρωση ή ανέβασμα μέσω FTP/CLI δεν
 * τρέχει activation hook, και ως τότε το email έμενε ανοιχτό στους
 * αυτο-δηλωμένους φαρμακοποιούς (2.16.0). Κόστος μετά την αναβάθμιση: ένα
 * autoloaded get_option και ένα version_compare.
 */
function qrrp_run_access_migrations() {
	$installed_version = (string) get_option( 'qrrp_version', '' );

	if ( '' !== $installed_version && version_compare( $installed_version, QRRP_VERSION, '>=' ) ) {
		return;
	}

	qrrp_maybe_migrate_access_choices( $installed_version );

	qrrp_maybe_migrate_guest_manual_entry( $installed_version );

	qrrp_maybe_migrate_verified_email( $installed_version );
}

/** Η εργασία αναβάθμισης· οι έλεγχοι περιβάλλοντος ζουν στην qrrp_run_upgrade_maintenance(). */
function qrrp_maybe_upgrade() {
	$installed_version = (string) get_option( 'qrrp_version', '' );

	/* Πριν τον έλεγχο έκδοσης, ώστε χαμένες γραμμές να αποκαθίστανται πάντα. */
	qrrp_ensure_default_options();

	qrrp_maybe_cleanup_legacy_data();

	if ( '' !== $installed_version && version_compare( $installed_version, QRRP_VERSION, '>=' ) ) {
		return;
	}

	qrrp_remove_obsolete_options();

	qrrp_maybe_migrate_access_choices( $installed_version );

	qrrp_maybe_migrate_guest_manual_entry( $installed_version );

	qrrp_maybe_migrate_verified_email( $installed_version );

	update_option( 'qrrp_version', QRRP_VERSION );
}

/**
 * 2.16.0: το email από αυτο-δηλωμένους φαρμακοποιούς (ή από κάθε συνδεδεμένο)
 * ήταν ανοιχτό relay: όποιος γραφόταν ως «Φαρμακείο» έστελνε σε οποιαδήποτε
 * διεύθυνση από το domain του site. Γίνεται «Μόνο εγκεκριμένοι φαρμακοποιοί»:
 *   qrrp_pharmacist                                   → qrrp_verified_pharmacist
 *   '' (ίδιο με το εργαλείο), εργαλείο read ή
 *   qrrp_pharmacist, χωρίς email επισκεπτών           → qrrp_verified_pharmacist
 * Μόνο προς το αυστηρότερο. Το «Ελεύθερο για όλους» (email επισκεπτών ανοιχτό)
 * είναι ρητή επιλογή και μένει. Μία φορά, από έκδοση < 2.16.0 (ή άγνωστη).
 *
 * @param string $installed_version Έκδοση πριν από αυτό το πέρασμα ('' αν λείπει).
 * @return bool Αν άλλαξε η τιμή.
 */
function qrrp_maybe_migrate_verified_email( $installed_version ) {
	$installed_version = is_scalar( $installed_version ) ? trim( (string) $installed_version ) : '';

	if ( '' !== $installed_version && version_compare( $installed_version, '2.16.0', '>=' ) ) {
		return false;
	}

	$email = get_option( 'qrrp_email_capability', null );

	if ( ! is_string( $email ) ) {
		return false;
	}

	$email       = trim( $email );
	$guest_email = '1' === get_option( 'qrrp_allow_guest_email', '0' );
	$tool        = qrrp_tool_capability();

	$migrate = 'qrrp_pharmacist' === $email
		|| ( '' === $email && ! $guest_email && in_array( $tool, array( 'read', 'qrrp_pharmacist' ), true ) );

	if ( ! $migrate ) {
		return false;
	}

	return (bool) update_option( 'qrrp_email_capability', 'qrrp_verified_pharmacist' );
}

/**
 * 2.15.5: η χειροκίνητη δημιουργία από επισκέπτες έγινε opt-in (προεπιλογή
 * '0'). Μέχρι την 2.15.4 κάθε εγκατάσταση έπαιρνε αυτόματα '1', οπότε η
 * αποθηκευμένη τιμή δεν ήταν επιλογή του διαχειριστή. Αλλάζει σε '0' μόνο
 * όταν το εργαλείο δεν είναι πραγματικά ανοιχτό σε επισκέπτες (ίδιος έλεγχος
 * με την qrrp_manual_entry_allowed(): qrrp_allow_guests = '1' ΚΑΙ δικαίωμα
 * 'read'): εκεί η ρύθμιση δεν είχε καμία επίδραση, άρα κανείς δεν χάνει
 * λειτουργία. Όπου οι επισκέπτες είναι ήδη ανοιχτοί, η τιμή μένει ως έχει.
 * Μία φορά, από έκδοση < 2.15.5 (ή άγνωστη).
 *
 * @param string $installed_version Έκδοση πριν από αυτό το πέρασμα ('' αν λείπει).
 * @return bool Αν άλλαξε η τιμή.
 */
function qrrp_maybe_migrate_guest_manual_entry( $installed_version ) {
	$installed_version = is_scalar( $installed_version ) ? trim( (string) $installed_version ) : '';

	if ( '' !== $installed_version && version_compare( $installed_version, '2.15.5', '>=' ) ) {
		return false;
	}

	if ( '1' === get_option( 'qrrp_allow_guests', '0' ) && 'read' === qrrp_tool_capability() ) {
		return false;
	}

	if ( '1' !== get_option( 'qrrp_allow_guest_manual_entry', '0' ) ) {
		return false;
	}

	return update_option( 'qrrp_allow_guest_manual_entry', '0' );
}

/**
 * Μετατροπή επιλογών πρόσβασης μόνο από έκδοση < 2.14.1 (ή άγνωστη): μετά,
 * οι τιμές είναι επιλογές του διαχειριστή και δεν ξαναγράφονται.
 *
 * @param string $installed_version Έκδοση πριν από αυτό το πέρασμα ('' αν λείπει).
 * @return bool Αν εκτελέστηκε.
 */
function qrrp_maybe_migrate_access_choices( $installed_version ) {
	$installed_version = is_scalar( $installed_version ) ? trim( (string) $installed_version ) : '';

	if ( '' !== $installed_version && version_compare( $installed_version, '2.14.1', '>=' ) ) {
		return false;
	}

	qrrp_migrate_access_choices();

	return true;
}

/**
 * Παλιές τιμές ρόλων → επιλογές 2.14.1 (μόνο προς το αυστηρότερο, idempotent):
 * πρόσβαση edit_posts, και email edit_posts ή '' χωρίς email επισκεπτών,
 * γίνονται qrrp_pharmacist.
 */
function qrrp_migrate_access_choices() {
	$tool = get_option( 'qrrp_capability', null );

	if ( is_string( $tool ) && 'edit_posts' === trim( $tool ) ) {
		update_option( 'qrrp_capability', 'qrrp_pharmacist' );
	}

	$email       = get_option( 'qrrp_email_capability', null );
	$guest_email = '1' === get_option( 'qrrp_allow_guest_email', '0' );

	if ( is_string( $email ) && ! $guest_email && in_array( trim( $email ), array( 'edit_posts', '' ), true ) ) {
		update_option( 'qrrp_email_capability', 'qrrp_pharmacist' );
	}
}

/**
 * admin_init: μόνο για διαχειριστή και όχι σε AJAX (το admin-ajax.php τρέχει
 * κι αυτό το admin_init, άρα αλλιώς θα έτρεχε σε κάθε σάρωση).
 */
function qrrp_run_upgrade_maintenance() {
	if ( wp_doing_ajax() ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	qrrp_maybe_upgrade();
}
