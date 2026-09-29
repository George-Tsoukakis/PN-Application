<?php
/**
 * Uninstall routine for QR ReBuilder Pro.
 *
 * Per site (every site on multisite): options, tokens, rate-limit state, the
 * public counter snapshot, legacy uploads and the old history table. Once per
 * network: legacy subscription user meta. Direct SQL only where no WordPress
 * API exists (DROP TABLE, pattern deletes).
 *
 * @package QR_ReBuilder_Pro
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/** Φορτώνει αρχείο του plugin· αν λείπει, το καταγράφει με WP_DEBUG και συνεχίζει. */
function qrrp_uninstall_require( $relative ) {
	$file = __DIR__ . '/' . $relative;

	if ( is_readable( $file ) ) {
		require_once $file;

		return true;
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only trace for an incomplete plugin package.
		error_log( sprintf( 'QRRP uninstall: missing %s, related cleanup skipped', $relative ) );
	}

	return false;
}

/**
 * Διαγράφει τα transients με το πρόθεμα: delete_transient() για όσα βρίσκονται
 * στη βάση (καθαρίζει και object cache), μετά LIKE για ορφανές γραμμές.
 */
function qrrp_uninstall_delete_transients( $prefix ) {
	global $wpdb;

	$value_pattern   = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
	$timeout_pattern = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact names are needed so delete_transient() can also clear an external object cache.
	$rows = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$value_pattern,
			$timeout_pattern
		)
	);

	$keys = array();

	foreach ( (array) $rows as $option_name ) {
		if ( ! is_string( $option_name ) ) {
			continue;
		}

		if ( 0 === strpos( $option_name, '_transient_timeout_' ) ) {
			$key = substr( $option_name, strlen( '_transient_timeout_' ) );
		} elseif ( 0 === strpos( $option_name, '_transient_' ) ) {
			$key = substr( $option_name, strlen( '_transient_' ) );
		} else {
			continue;
		}

		if ( 0 === strpos( $key, $prefix ) ) {
			$keys[ $key ] = true;
		}
	}

	foreach ( array_keys( $keys ) as $key ) {
		delete_transient( $key );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Final pattern cleanup of orphaned transient rows.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$value_pattern,
			$timeout_pattern
		)
	);
}

/** Καθαρίζει το τρέχον site. */
function qrrp_uninstall_site() {
	global $wpdb;

	/* Ο παλιός πίνακας ιστορικού· όνομα μόνο από $wpdb->prefix + literal. */
	$table = esc_sql( $wpdb->prefix . 'qrrp_scan_history' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix only; DROP TABLE cannot be parameterised.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

	$options = array(
		'qrrp_capability',
		'qrrp_email_capability',
		'qrrp_allow_guests',
		'qrrp_allow_guest_email',
		'qrrp_allow_guest_manual_entry',
		'qrrp_guest_email_domains',
		'qrrp_vendor_check', /* 2.15.6 */
		'qrrp_enable_history',
		'qrrp_email_from_name',
		'qrrp_email_from_address',
		'qrrp_sn_fallback_length',
		'qrrp_lot_fallback_length',
		'qrrp_print_barcode_mm',
		'qrrp_print_show_note',
		'qrrp_print_show_meta',
		'qrrp_print_orientation',
		'qrrp_sn_trim_trailing',
		'qrrp_version',
		'qrrp_ai_table',
		'qrrp_legacy_cleanup_done',
		'qrrp_legacy_usermeta_migrated',
		'qrrp_usermeta_migration_version',
		'qrrp_tool_page_url',
		'qrrp_tool_page_id',
		'qrrp_rl_last_sweep',
		'qrrp_stats',
		'qrrp_history_table_dropped',
		'qrrp_history_drop_attempts',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	/* Tokens μέσω ευρετηρίου (φτάνει και σε object cache), μετά με μοτίβο. */
	if ( is_callable( array( 'QRRP_Mailer', 'purge_all_rebuild_tokens' ) ) ) {
		QRRP_Mailer::purge_all_rebuild_tokens();
	}

	delete_option( 'qrrp_token_index' );
	delete_option( 'qrrp_token_index_full_at' );

	qrrp_uninstall_delete_transients( 'qrrp_tok_' );
	qrrp_uninstall_delete_transients( 'qrrp_rl_' );
	qrrp_uninstall_delete_transients( 'qrrp_sh_' );

	/* 2.15.2: το single event που σβήνει τα temp PNG των αποστολών email. */
	wp_clear_scheduled_hook( 'qrrp_sweep_mail_temp_files' );

	qrrp_uninstall_delete_uploads();
}

/**
 * Σβήνει το snapshot του μετρητή (και ορφανά .tmp) ονομαστικά, και τον legacy
 * φάκελο τιμολογίων, τον μόνο που διαγράφεται αναδρομικά.
 */
function qrrp_uninstall_delete_uploads() {
	$upload_dir = wp_upload_dir();

	if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
		return;
	}

	$uploads_base = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
	$snapshot_dir = wp_normalize_path( $uploads_base . 'qrrp' );
	$invoice_dir  = wp_normalize_path( $uploads_base . 'qrrp-invoices' );

	if ( is_dir( $snapshot_dir ) && 'qrrp' === basename( $snapshot_dir ) ) {
		$files = glob( $snapshot_dir . '/usage.json.*.tmp' );
		$files = is_array( $files ) ? $files : array();

		if ( is_file( $snapshot_dir . '/usage.json' ) ) {
			$files[] = $snapshot_dir . '/usage.json';
		}

		foreach ( $files as $file ) {
			if ( 0 === strpos( wp_normalize_path( $file ), $uploads_base ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}

		/* Ο φάκελος φεύγει μόνο αν έμεινε άδειος. */
		$left = glob( $snapshot_dir . '/*' );

		if ( is_array( $left ) && array() === $left ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.dir_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- Empty directory owned by this plugin.
			@rmdir( $snapshot_dir );
		}
	}

	if (
		0 === strpos( trailingslashit( $invoice_dir ), $uploads_base ) &&
		'qrrp-invoices' === basename( $invoice_dir ) &&
		is_dir( $invoice_dir )
	) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( WP_Filesystem() ) {
			global $wp_filesystem;

			if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
				$wp_filesystem->delete( $invoice_dir, true );
			}
		}
	}
}

/**
 * Δεδομένα δικτύου, μία φορά. Τα γενικά user meta (afm, account_type, phone_1,
 * mobile_phone) μένουν σκόπιμα: μπορεί να ανήκουν σε άλλο πρόσθετο.
 */
function qrrp_uninstall_network() {
	global $wpdb;

	/* Site transient του updater που αφαιρέθηκε στην 2.12.1. */
	delete_site_transient( 'qrrp_update_manifest' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No core API deletes usermeta rows by key across all users.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ( %s, %s, %s, %s, %s )",
			'qrrp_pro_expires',
			'qrrp_pro_activated_on',
			'qrrp_manual_access',
			'qrrp_invoices',
			'qrrp_hide_pharmacist_warning' /* 2.15.4 */
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Pattern delete of legacy per-user usage counters.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'qrrp_usage_' ) . '%'
		)
	);
}

/* Το uninstall δεν περνά από το κεντρικό αρχείο: ο Mailer και οι εξαρτήσεις του, με σειρά. */
foreach (
	array(
		'includes/class-qrrp-text.php',
		'includes/class-qrrp-url.php',
		'includes/class-qrrp-tool-page.php',
		'includes/class-qrrp-tokens.php',
		'includes/class-qrrp-mailer.php',
	) as $qrrp_file
) {
	qrrp_uninstall_require( $qrrp_file );
}

/*
 * 2.15.7: τα temp PNG φέρουν tag ανά site, οπότε το purge τρέχει μέσα σε κάθε
 * site (πριν: μία φορά για όλο τον κοινό φάκελο, σβήνοντας και αρχεία άλλων
 * εγκαταστάσεων στον ίδιο /tmp).
 */
function qrrp_uninstall_purge_temp_files() {
	if ( is_callable( array( 'QRRP_Mailer', 'purge_all_temp_files' ) ) ) {
		QRRP_Mailer::purge_all_temp_files();
	}
}

if ( is_multisite() && function_exists( 'get_sites' ) ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $qrrp_site_id ) {
		switch_to_blog( (int) $qrrp_site_id );
		qrrp_uninstall_site();
		qrrp_uninstall_purge_temp_files();
		restore_current_blog();
	}
} else {
	qrrp_uninstall_site();
	qrrp_uninstall_purge_temp_files();
}

qrrp_uninstall_network();
