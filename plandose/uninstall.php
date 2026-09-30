<?php
/**
 * Runs only when the plugin is deleted from the Plugins screen, not on deactivation.
 *
 * Whether anything durable is actually removed depends on the
 * `keep_data_on_uninstall` setting (PlanDose -> Ρυθμίσεις -> Απόρρητο & Audit Log),
 * which DEFAULTS TO KEEPING EVERYTHING. On a live site the subscriptions table,
 * print counters, the monthly print history, audit log, invoice files and
 * manual access overrides are real operational data, and a single accidental
 * click on "Διαγραφή" in the Plugins screen must not be able to destroy them.
 *
 * When the setting is left on (the default, and the case for any install that
 * never saved settings), this routine only clears throwaway state: the cron
 * event and the short-lived print-lock / rate-limit / invoice-error transients.
 *
 * Only when an administrator has deliberately switched the setting OFF does this
 * drop the plandose_subscriptions, plandose_audit_log and
 * plandose_print_history tables, delete the plugin options and user meta, and
 * remove the protected invoice upload directory created by
 * includes/class-plandose-invoice-storage.php.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Decide whether a custom PLANDOSE_INVOICE_DIR is provably owned by PlanDose
 * and therefore eligible for conservative cleanup during uninstall.
 *
 * This mirrors Plandose_Invoice_Storage::validate_custom_invoice_dir() /
 * ::protected_directories(), re-implemented standalone because uninstall.php
 * runs without the plugin's classes loaded. It fails CLOSED: any doubt returns
 * false and the directory is left on disk untouched.
 *
 * Requires ALL of: the path is absolute and a real directory, is not a symlink,
 * resolves via realpath(), is not a critical system/WordPress directory, is deep
 * enough not to be a broad shared path, and contains the exact ownership marker
 * file that invoice_dir() writes. Passing this gate never authorizes recursive
 * deletion: custom storage is cleaned conservatively file by file.
 *
 * @param string $dir        Normalized, untrailingslashit'd candidate path.
 * @param array  $upload_dir Result of wp_upload_dir( null, false ).
 * @return bool
 */
function plandose_uninstall_custom_dir_deletable( $dir, $upload_dir ) {
	// Prefer the plugin's own (unit-tested) implementation as the single
	// source of truth. It only needs the invoice storage class, which has no
	// parent and no top-level side effects, so it is safe to load here.
	$storage_file = __DIR__ . '/includes/class-plandose-invoice-storage.php';

	if ( ! class_exists( 'Plandose_Invoice_Storage' ) && is_readable( $storage_file ) ) {
		require_once $storage_file;
	}

	if ( class_exists( 'Plandose_Invoice_Storage' ) && method_exists( 'Plandose_Invoice_Storage', 'custom_invoice_dir_is_deletable' ) ) {
		return (bool) Plandose_Invoice_Storage::custom_invoice_dir_is_deletable( $dir );
	}

	// Standalone fallback (mirrors Plandose_Invoice_Storage::custom_invoice_dir_is_deletable()).
	// NUL (realpath() throws on PHP 8) and '..' are refused before any
	// filesystem call, as validate_custom_invoice_dir() does.
	if ( '' === (string) $dir || false !== strpos( (string) $dir, "\0" ) || in_array( '..', explode( '/', str_replace( '\\', '/', (string) $dir ) ), true ) || ! path_is_absolute( $dir ) ) {
		return false;
	}

	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return false;
	}

	$real = realpath( $dir );

	if ( false === $real ) {
		return false;
	}

	$real = untrailingslashit( wp_normalize_path( $real ) );

	// Critical directories that must never be deleted.
	$protected_raw = array(
		ABSPATH,
		dirname( ABSPATH ),
		defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
		'/',
	);

	if ( is_array( $upload_dir ) && empty( $upload_dir['error'] ) && ! empty( $upload_dir['basedir'] ) ) {
		$protected_raw[] = (string) $upload_dir['basedir'];
	}

	$protected = array();

	foreach ( $protected_raw as $path ) {
		if ( '' === (string) $path ) {
			continue;
		}

		$protected[] = untrailingslashit( wp_normalize_path( (string) $path ) );

		$path_real = realpath( $path );

		if ( false !== $path_real ) {
			$protected[] = untrailingslashit( wp_normalize_path( $path_real ) );
		}
	}

	if ( in_array( $dir, $protected, true ) || in_array( $real, $protected, true ) ) {
		return false;
	}

	// Reject overly broad paths such as "/", "/home" or "/home/account".
	$segments = array_values( array_filter( explode( '/', trim( $real, '/' ) ) ) );

	if ( count( $segments ) < 3 ) {
		return false;
	}

	// Require the ownership marker with its exact sentinel content.
	$marker_path = trailingslashit( $dir ) . '.plandose-invoice-storage';

	if ( ! is_file( $marker_path ) ) {
		return false;
	}

	$marker = file_get_contents( $marker_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- small local marker file; WP_Filesystem may be unavailable here.

	return is_string( $marker ) && 'plandose-invoice-storage-v1' === trim( $marker );
}

/**
 * Conservative deletion of a custom invoice directory's contents (standalone
 * mirror of Plandose_Invoice_Storage::delete_custom_dir_contents(), used only if
 * the class can't be loaded during uninstall). Deletes ONLY the plugin's own
 * known files — first the invoice files recorded in the database, then, only
 * if nothing else is left, the protection/marker files — and removes the
 * directory solely if it is then empty. Foreign files and unknown subdirectories are never touched.
 *
 * @param string   $dir                 Marker-verified custom directory.
 * @param string[] $known_invoice_files Sanitized invoice basenames from the DB.
 * @return bool True if the directory itself was removed.
 */
function plandose_uninstall_delete_known_files( $dir, $known_invoice_files ) {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return false;
	}

	$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
	$known = array();

	foreach ( (array) $known_invoice_files as $filename ) {
		$raw  = (string) $filename;
		$safe = sanitize_file_name( basename( $raw ) );

		if ( '' !== $safe && $safe === $raw ) {
			$known[ $safe ] = true;
		}
	}

	$protection = array();

	foreach ( plandose_uninstall_protection_files() as $name ) {
		$protection[ $name ] = true;
	}

	// Lower-cased once, not per invoice file.
	$protection_lower = array_map( 'strtolower', array_keys( $protection ) );

	foreach ( $known as $name => $unused ) {
		if ( in_array( strtolower( $name ), $protection_lower, true ) ) {
			unset( $known[ $name ] );
		}
	}

	$entries = scandir( $dir );

	if ( false === $entries ) {
		if ( $debug ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- failure logged for the site admin (debug.log), not shown to users.
			error_log( '[PlanDose uninstall] Failed to read custom invoice directory: ' . $dir );
		}

		return false;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$path = trailingslashit( $dir ) . $entry;

		// A privacy-probe canary left by a probe that was killed mid-way
		// (Plandose_Invoice_Storage::CANARY_PATTERN): PlanDose's own file,
		// never in use now that the plugin is inactive.
		$is_canary = (bool) preg_match( '/^plandose-probe-(?:control-)?[A-Za-z0-9]{24}\.(?:pdf|jpg|jpeg|png|webp)$/D', $entry );

		// Delete only known regular files. Never recurse, follow links, or
		// remove unknown entries from custom storage.
		if ( ( ! isset( $known[ $entry ] ) && ! $is_canary ) || ! is_file( $path ) || is_link( $path ) ) {
			continue;
		}

		if ( ! unlink( $path ) && $debug ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- WP_Filesystem may be unavailable during uninstall; failure is logged below.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- failure logged for the site admin (debug.log), not shown to users.
			error_log( '[PlanDose uninstall] Failed to delete known custom-storage file: ' . $path );
		}
	}

	// The protection files are removed only when nothing else is
	// left, so an invoice the database did not list never ends up without
	// its deny rule (same rule as Plandose_Invoice_Storage).
	$left = scandir( $dir );

	if ( false === $left ) {
		return false;
	}

	foreach ( $left as $entry ) {
		if ( '.' !== $entry && '..' !== $entry && ! isset( $protection[ $entry ] ) ) {
			return false;
		}
	}

	foreach ( array_keys( $protection ) as $name ) {
		$path = trailingslashit( $dir ) . $name;

		if ( is_file( $path ) && ! is_link( $path ) && ! unlink( $path ) && $debug ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- WP_Filesystem may be unavailable during uninstall; failure is logged below.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- failure logged for the site admin (debug.log), not shown to users.
			error_log( '[PlanDose uninstall] Failed to delete protection file: ' . $path );
		}
	}

	$remaining = scandir( $dir );

	if ( false === $remaining ) {
		if ( $debug ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- failure logged for the site admin (debug.log), not shown to users.
			error_log( '[PlanDose uninstall] Failed to re-read custom invoice directory: ' . $dir );
		}

		return false;
	}

	$remaining = array_diff( $remaining, array( '.', '..' ) );

	if ( ! empty( $remaining ) ) {
		return false;
	}

	if ( ! rmdir( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Uninstall routine removing a now-empty plugin directory; WP_Filesystem may be unavailable during uninstall.
		if ( $debug ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- failure logged for the site admin (debug.log), not shown to users.
			error_log( '[PlanDose uninstall] Failed to remove empty custom invoice directory: ' . $dir );
		}

		return false;
	}

	return true;
}

/**
 * Names of the files PlanDose writes into an invoice directory to protect
 * or mark it. Prefers Plandose_Invoice_Storage's list; the literal copy is
 * used only when that class is not loadable.
 *
 * @return string[]
 */
function plandose_uninstall_protection_files() {
	if ( class_exists( 'Plandose_Invoice_Storage' ) && method_exists( 'Plandose_Invoice_Storage', 'uninstall_protection_files' ) ) {
		return (array) Plandose_Invoice_Storage::uninstall_protection_files();
	}

	return array( '.htaccess', 'web.config', 'index.php', 'index.html', '.plandose-invoice-storage' );
}

/**
 * Drop protection-file names from the invoice names read from the
 * database. A corrupted or hand-edited row listing e.g. "index.php" or
 * "web.config" as an invoice would otherwise have that file unlinked in
 * the first cleanup pass, leaving remaining invoices unprotected. Compared
 * case-insensitively (case-insensitive file systems).
 *
 * @param string[] $names      Invoice basenames.
 * @param string[] $protection Protection file names.
 * @return string[]
 */
function plandose_uninstall_filter_invoice_names( array $names, array $protection ) {
	$reserved = array_map( 'strtolower', array_map( 'strval', $protection ) );
	$out      = array();

	foreach ( $names as $name ) {
		if ( ! in_array( strtolower( (string) $name ), $reserved, true ) ) {
			$out[] = (string) $name;
		}
	}

	return array_values( array_unique( $out ) );
}

/**
 * Whether durable PlanDose data must survive this uninstall.
 *
 * Reads the raw option directly, because uninstall.php runs without the
 * plugin's classes loaded and must not depend on them.
 *
 * Fails SAFE in every ambiguous case: a missing option, a corrupted option,
 * or an option saved by an older version that predates this setting all mean
 * "keep". Data is only removed when the stored value is explicitly falsy,
 * which can only happen if an administrator unchecked the box and saved.
 *
 * @return bool
 */
function plandose_uninstall_should_keep_data() {
	$settings = get_option( 'plandose_settings' );

	if ( ! is_array( $settings ) || ! array_key_exists( 'keep_data_on_uninstall', $settings ) ) {
		return true;
	}

	return ! empty( $settings['keep_data_on_uninstall'] );
}

/**
 * Legacy Media Library originals still to delete — the entries
 * Plandose_Admin_Invoices::legacy_originals() keeps (the same rule, the
 * class is not loaded during uninstall), minus those whose file is already
 * gone ('original_missing'): nothing is left to protect for those.
 *
 * @param mixed $stored The plandose_legacy_originals option.
 * @return int
 */
function plandose_uninstall_count_legacy_originals( $stored ) {
	if ( ! is_array( $stored ) ) {
		return 0;
	}

	$count = 0;

	foreach ( $stored as $attachment_id => $entry ) {
		if ( ! absint( $attachment_id ) || ! is_array( $entry ) ) {
			continue;
		}

		if ( empty( $entry['filename'] ) && empty( $entry['uncopied'] ) ) {
			continue;
		}

		if ( isset( $entry['skip'] ) && 'original_missing' === $entry['skip'] ) {
			continue;
		}

		++$count;
	}

	return $count;
}

/**
 * How many of the invoice files listed in the database are still in the
 * invoice folder after the cleanup: 0 when all are gone (or none was
 * listed), -1 when that cannot be established — the folder is unknown or
 * cannot be read. Anything but 0 means the tables must be kept.
 *
 * A folder that does not exist holds none of them. The names were already
 * reduced to plain, sanitized basenames by the caller.
 *
 * @param string   $dir   Invoice folder ('' when it could not be resolved).
 * @param string[] $names Invoice basenames read from the database.
 * @return int
 */
function plandose_uninstall_invoice_files_left( $dir, array $names ) {
	if ( empty( $names ) ) {
		return 0;
	}

	if ( '' === (string) $dir ) {
		return -1;
	}

	clearstatcache();

	if ( ! file_exists( $dir ) && ! is_link( $dir ) ) {
		return 0;
	}

	if ( ! is_dir( $dir ) || false === scandir( $dir ) ) {
		return -1;
	}

	$left = 0;

	foreach ( $names as $name ) {
		$path = trailingslashit( $dir ) . $name;

		if ( file_exists( $path ) || is_link( $path ) ) {
			++$left;
		}
	}

	return $left;
}

/**
 * Run the full uninstall routine.
 *
 * Wrapped in a function so its working variables are function-scoped
 * locals instead of plugin-polluting global-scope variables
 * (WordPress.NamingConventions.PrefixAllGlobals).
 */
function plandose_run_uninstall() {
	global $wpdb;

	/*
	 * Table names are built from $wpdb->prefix, which WordPress itself
	 * derives from wp-config.php and controls -- never from request input --
	 * so string interpolation here follows the same pattern WordPress core
	 * and dbDelta() use throughout. The regex check below is purely an extra
	 * defense-in-depth guard: if a misconfigured db prefix ever contained
	 * characters outside what's safe to use unquoted in an identifier, this
	 * bails out instead of building a DROP TABLE statement from it. Backticks
	 * additionally quote the identifiers themselves.
	 */
	$prefix = $wpdb->prefix;

	// Default is to keep everything; see plandose_uninstall_should_keep_data().
	$plandose_keep_data = plandose_uninstall_should_keep_data();

	// Invoice filenames recorded in the database, collected BEFORE the table is
	// dropped. Used for the conservative custom-directory cleanup further down: for
	// a custom PLANDOSE_INVOICE_DIR we only ever delete files we know are ours, so
	// foreign files sharing that directory are never removed.
	$plandose_invoice_filenames = array();

	// The tables to drop, filled below only once the prefix passed the guard
	// and the invoice list was read. They are dropped AFTER the invoice
	// files are removed (see further down), never before.
	$plandose_tables = array();

	// An unusable prefix means the invoice list cannot be read, so which
	// files are PlanDose's cannot be known: keep-data mode for everything
	// (tables, options, usermeta, files), exactly as after a failed read
	// below — not a half-uninstall that deletes the options (the list of
	// public Media Library originals among them) but leaves the tables.
	if ( ! $plandose_keep_data && ! ( is_string( $prefix ) && preg_match( '/^[A-Za-z0-9_]+\z/', $prefix ) ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the uninstaller has no UI to report to.
		error_log( 'PlanDose uninstall: the database table prefix contains unexpected characters, so PlanDose data (tables, options, invoice files) was kept.' );
		$plandose_keep_data = true;
	}

	if ( ! $plandose_keep_data ) {
		$table = $prefix . 'plandose_subscriptions';

		// Read the invoice list only from a table that exists, and treat a
		// FAILED read as "keep everything": a lost connection or timeout here
		// gives an empty list, and dropping the tables anyway would leave the
		// invoice PDFs (with tax numbers) on disk with no record left to find
		// them by.
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off existence check during uninstall.
		$plandose_table_exists  = ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
		$plandose_invoice_blobs = array();

		if ( $plandose_table_exists && '' === $wpdb->last_error ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is built above from $wpdb->prefix (validated by the regex guard) + a fixed suffix; one-off read during uninstall, no cache applies.
			$plandose_invoice_blobs = $wpdb->get_col( "SELECT invoices FROM `{$table}` WHERE invoices IS NOT NULL AND invoices != ''" );
		}

		if ( '' !== $wpdb->last_error || ! is_array( $plandose_invoice_blobs ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the uninstaller has no UI to report to.
			error_log( 'PlanDose uninstall: the invoice list could not be read, so PlanDose data (tables, options, invoice files) was kept. Reinstall the plugin and delete it again to remove it.' );
			// Keep-data mode from here on: the tables, options, usermeta
			// and files stay, while what always runs (cron events, locks)
			// still does.
			$plandose_keep_data     = true;
			$plandose_invoice_blobs = array();
		}

		$plandose_legacy_ids_left = 0;

		if ( is_array( $plandose_invoice_blobs ) ) {
			foreach ( $plandose_invoice_blobs as $blob ) {
				$decoded = json_decode( (string) $blob, true );

				if ( ! is_array( $decoded ) ) {
					continue;
				}

				foreach ( $decoded as $entry ) {
					// Legacy attachment IDs (int or numeric string, as the
					// migration reads them) are not files in the invoice dir.
					if ( is_numeric( $entry ) ) {
						if ( (int) $entry > 0 ) {
							++$plandose_legacy_ids_left;
						}
						continue;
					}

					if ( ! is_string( $entry ) ) {
						continue;
					}

					$basename = basename( $entry );
					$safe     = sanitize_file_name( $basename );

					if ( '' !== $safe && $basename === $entry && $safe === $basename ) {
						$plandose_invoice_filenames[] = $safe;
					}
				}
			}
		}

		/*
		 * Invoices still in the Media Library — not yet migrated
		 * (numeric attachment IDs above), or migrated but whose public
		 * originals were never deleted (plandose_legacy_originals). Those
		 * files are outside the invoice folder, so this uninstaller cannot
		 * remove them; dropping the tables and the option would delete the
		 * only record of which attachments are invoices (with tax numbers).
		 * Keep everything instead, like after a failed read, until
		 * «Διαγραφή πρωτοτύπων» has been run.
		 */
		$plandose_legacy_originals_left = plandose_uninstall_count_legacy_originals( get_option( 'plandose_legacy_originals', array() ) );

		if ( ! $plandose_keep_data && ( $plandose_legacy_ids_left > 0 || $plandose_legacy_originals_left > 0 ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the uninstaller has no UI to report to.
			error_log( sprintf( 'PlanDose uninstall: invoices are still in the Media Library (%d not migrated, %d originals not deleted), so PlanDose data was kept. Finish the migration (PlanDose → Διαγνωστικά) and run «Διαγραφή πρωτοτύπων», then reinstall the plugin and delete it again.', $plandose_legacy_ids_left, $plandose_legacy_originals_left ) );
			$plandose_keep_data = true;
		}

		$storage_file = __DIR__ . '/includes/class-plandose-invoice-storage.php';

		if ( ! class_exists( 'Plandose_Invoice_Storage' ) && is_readable( $storage_file ) ) {
			require_once $storage_file;
		}

		$plandose_invoice_filenames = plandose_uninstall_filter_invoice_names( $plandose_invoice_filenames, plandose_uninstall_protection_files() );

		if ( ! $plandose_keep_data ) { // Not after a failed read (above).
			foreach ( array( 'plandose_subscriptions', 'plandose_audit_log', 'plandose_print_history', 'plandose_print_charges', 'plandose_print_requests', 'plandose_print_log' ) as $plandose_suffix ) {
				$plandose_tables[] = $prefix . $plandose_suffix;
			}
		}
	}

	// Remove the protected invoice directory (see Plandose_Invoice_Storage::invoice_dir()).
	// Honors the same PLANDOSE_INVOICE_DIR override (defined in wp-config.php) so a
	// site using private, outside-webroot storage still gets cleaned up on uninstall.
	//
	// SAFETY: cleanup is conservative for BOTH the default and any custom
	// directory. Only database-recorded invoice files and exact PlanDose support
	// files are removed, and the directory itself is removed only when it is left
	// empty. Foreign files and unknown subdirectories are never touched, and a
	// folder name alone is never treated as proof of ownership for a recursive
	// delete. A custom directory is additionally cleaned only when it is provably
	// owned by this plugin (marker gate below).
	$plandose_upload_dir = wp_upload_dir( null, false );
	$plandose_is_custom  = defined( 'PLANDOSE_INVOICE_DIR' ) && '' !== trim( (string) PLANDOSE_INVOICE_DIR );

	if ( $plandose_is_custom ) {
		$invoice_dir = untrailingslashit( wp_normalize_path( trim( (string) PLANDOSE_INVOICE_DIR ) ) );
		$may_delete  = plandose_uninstall_custom_dir_deletable( $invoice_dir, $plandose_upload_dir );
	} elseif ( is_array( $plandose_upload_dir ) && empty( $plandose_upload_dir['error'] ) && ! empty( $plandose_upload_dir['basedir'] ) ) {
		$invoice_dir = untrailingslashit( wp_normalize_path( trailingslashit( $plandose_upload_dir['basedir'] ) . 'plandose-invoices' ) );
		$may_delete  = true;
	} else {
		$invoice_dir = '';
		$may_delete  = false;
	}

	if ( ! $plandose_keep_data && $may_delete && '' !== $invoice_dir && is_dir( $invoice_dir ) && ! is_link( $invoice_dir ) ) {
		/*
		 * Conservative cleanup for BOTH the default and any custom directory:
		 * delete only the files we know are ours — the invoice files recorded in
		 * the database plus the exact PlanDose protection/marker files — and remove
		 * the directory solely when nothing else remains. Foreign files and unknown
		 * subdirectories are never touched: a folder name alone is never treated as
		 * proof of ownership for a recursive delete, not even for the default
		 * uploads subfolder. Prefers the plugin's own (unit-tested) implementation
		 * when the class can be loaded.
		 */
		$storage_file = __DIR__ . '/includes/class-plandose-invoice-storage.php';

		if ( ! class_exists( 'Plandose_Invoice_Storage' ) && is_readable( $storage_file ) ) {
			require_once $storage_file;
		}

		if ( class_exists( 'Plandose_Invoice_Storage' ) && method_exists( 'Plandose_Invoice_Storage', 'delete_custom_dir_contents' ) ) {
			Plandose_Invoice_Storage::delete_custom_dir_contents( $invoice_dir, $plandose_invoice_filenames );
		} else {
			plandose_uninstall_delete_known_files( $invoice_dir, $plandose_invoice_filenames );
		}
	}

	/*
	 * The tables are the only record of which files in the invoice folder
	 * are invoices (with tax numbers). They go only once every invoice file
	 * they list is really gone. When a file is still there — the cleanup
	 * was skipped (a PLANDOSE_INVOICE_DIR without a valid ownership marker,
	 * no usable uploads folder) or a delete failed (permissions) — dropping
	 * them would leave those PDFs on disk with nothing left to find them
	 * by. Keep-data mode instead, like after a failed read above: tables,
	 * options and usermeta stay, and a reinstall + delete can finish the job
	 * once the cause is fixed. Only the protection files may already be
	 * gone — and delete_custom_dir_contents() removes those solely from a
	 * folder with nothing else left in it.
	 */
	if ( ! $plandose_keep_data ) {
		$plandose_files_left = plandose_uninstall_invoice_files_left( $invoice_dir, $plandose_invoice_filenames );

		if ( 0 !== $plandose_files_left ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the uninstaller has no UI to report to.
			error_log(
				sprintf(
					'PlanDose uninstall: %s invoice file(s) listed in the database could not be removed from the invoice folder (%s), so PlanDose data (tables, options) was kept. Check the folder permissions and, for PLANDOSE_INVOICE_DIR, its .plandose-invoice-storage marker, then reinstall the plugin and delete it again.',
					$plandose_files_left < 0 ? 'the' : (string) $plandose_files_left,
					'' !== $invoice_dir ? $invoice_dir : 'folder unknown'
				)
			);
			$plandose_keep_data = true;
		}
	}

	if ( ! $plandose_keep_data ) {
		foreach ( $plandose_tables as $plandose_table_name ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$plandose_table_name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dropping this plugin's own tables on uninstall; each name is $wpdb->prefix (regex-validated above) + a fixed suffix, not user input.
		}
	}

	if ( ! $plandose_keep_data ) {
		foreach (
			array(
				'plandose_settings',
				'plandose_db_version',
				'plandose_free_limit_450_checked',
				'plandose_version',
				'plandose_legacy_invoice_migration_done',
				'plandose_legacy_invoice_migration_failed',
				'plandose_legacy_invoice_migration_rerun_1_21_0',
				'plandose_legacy_invoice_migration_cursor',
				'plandose_legacy_invoice_migration_lock',
				'plandose_legacy_invoice_migration_rerun_1_24_0',
				'plandose_legacy_originals',
				'plandose_legacy_originals_lock',
				'plandose_print_charges_since',
				'plandose_legacy_receipts_purged',
				'plandose_deleted_accounts',
				'plandose_invoice_privacy',
				'plandose_cleanup_last_run',
				'plandose_cleanup_skipped',
				'plandose_unarchived_months',
				'plandose_unarchived_months_lock',
				'plandose_legacy_invoice_migration_pending',
			) as $plandose_option_name
		) {
			delete_option( $plandose_option_name );
		}
	}

	// Single (not per-user) transients: the legacy-migration result banner and
	// the cached dashboard KPIs (Plandose_Subscriber_Query::STATS_CACHE_KEY).
	delete_transient( 'plandose_legacy_migration_result' );
	delete_transient( 'plandose_subscriber_stats' );
	delete_transient( 'plandose_uncounted_users' );
	delete_transient( 'plandose_engine_check' );
	delete_transient( 'plandose_engine_notice' );
	delete_transient( 'plandose_subscriber_rows_cache' );
	delete_transient( 'plandose_subscriber_count_cache' );
	delete_transient( 'plandose_upgrade_failed' );
	delete_option( 'plandose_upgrade_lock' );
	// Content hashes of the front-end dictionaries (a cache).
	delete_option( 'plandose_i18n_versions' );

	// Clear the daily cleanup cron event (see plandose.php / PLANDOSE_CLEANUP_CRON_HOOK).
	wp_clear_scheduled_hook( 'plandose_daily_cleanup' );

	// The background legacy-invoice migration (Plandose_Invoice_Migration::CRON_HOOK).
	wp_clear_scheduled_hook( 'plandose_legacy_invoice_migration' );

	// Keep the same identifier guard used above before interpolating WordPress
	// table names into the remaining direct cleanup queries.
	$plandose_safe_options_table  = is_string( $wpdb->options ) && preg_match( '/^[A-Za-z0-9_]+\z/', $wpdb->options );
	$plandose_safe_usermeta_table = is_string( $wpdb->usermeta ) && preg_match( '/^[A-Za-z0-9_]+\z/', $wpdb->usermeta );

	// Remove any PlanDose print-lock debounce options left over from any
	// version's implementation. These are normally short-lived (cleared
	// gradually by the daily cleanup cron for the legacy plain-option format,
	// or self-expiring for the current transient-backed format used by
	// Plandose_Print_Lock::acquire()), but a site could be
	// uninstalled before that ever happens, so sweep all three possible
	// storage forms here too. Also the per-user account-type throttle
	// transients (Plandose_Account_Type, plandose_acct_blocked_{id}).
	if ( $plandose_safe_options_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of this plugin's option rows during uninstall; no object cache applies to a bulk delete.
		$wpdb->query(
			"DELETE FROM `{$wpdb->options}`
			WHERE option_name LIKE '\\_transient\\_plandose\\_print\\_lock\\_%'
			   OR option_name LIKE '\\_transient\\_timeout\\_plandose\\_print\\_lock\\_%'
			   OR option_name LIKE 'plandose\\_print\\_lock\\_%'
			   OR option_name LIKE 'plandose\\_invoice\\_lock\\_%'
			   OR option_name LIKE '\\_transient\\_plandose\\_acct\\_blocked\\_%'
			   OR option_name LIKE '\\_transient\\_timeout\\_plandose\\_acct\\_blocked\\_%'
			   OR option_name LIKE '\\_transient\\_plandose\\_scope\\_refused\\_%'
			   OR option_name LIKE '\\_transient\\_timeout\\_plandose\\_scope\\_refused\\_%'"
		);

	}

	// Remove any AJAX rate-limit option rows left over from
	// Plandose_Ajax::is_rate_limited() — one row per user who ever used the
	// tool, in whichever storage form the non-atomic fallback used at the
	// time (legacy plain option, or the current transient-backed format).
	// Normally cleared per-user on account deletion (see
	// Plandose_Ajax::delete_rate_limit_for_user()), but a site could be
	// uninstalled without every user ever being deleted first.
	if ( $plandose_safe_options_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of this plugin's option rows during uninstall; no object cache applies to a bulk delete.
		$wpdb->query(
			"DELETE FROM `{$wpdb->options}`
			WHERE option_name LIKE '\\_transient\\_plandose\\_rl\\_%'
			   OR option_name LIKE '\\_transient\\_timeout\\_plandose\\_rl\\_%'
			   OR option_name LIKE 'plandose\\_rl\\_%'"
		);

	}

	// Remove any per-admin invoice-error transients (plandose_invoice_error_{id},
	// set for 60s on upload/validation failures) still present at uninstall,
	// including orphaned timeout rows. They are per-user, so they can't be
	// enumerated by exact name — sweep them with a pattern match.
	if ( $plandose_safe_options_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of this plugin's option rows during uninstall; no object cache applies to a bulk delete.
		$wpdb->query(
			"DELETE FROM `{$wpdb->options}`
			WHERE option_name LIKE '\\_transient\\_plandose\\_invoice\\_error\\_%'
			   OR option_name LIKE '\\_transient\\_timeout\\_plandose\\_invoice\\_error\\_%'
			   OR option_name LIKE '\\_transient\\_plandose\\_legacy\\_originals\\_result\\_%'
			   OR option_name LIKE '\\_transient\\_timeout\\_plandose\\_legacy\\_originals\\_result\\_%'
			   OR option_name LIKE '\\_transient\\_plandose\\_invoice\\_warning\\_%'
			   OR option_name LIKE '\\_transient\\_timeout\\_plandose\\_invoice\\_warning\\_%'"
		);
	}

	// Remove PlanDose user meta for every user site-wide: the manual access
	// override (Plandose_Access::MANUAL_ACCESS_META_KEY), the print receipts
	// (Plandose_Ajax::PRINT_RECEIPT_META_KEY) and the admin
	// notice-dismissal flags (the nginx notice, the display-scope notice and
	// the per-configured-size upload-size notice, which carries a numeric
	// suffix). WordPress core removes a single user's meta on wp_delete_user(),
	// but that doesn't cover users never deleted before the plugin is removed.
	if ( ! $plandose_keep_data && $plandose_safe_usermeta_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of this plugin's user meta during uninstall; no object cache applies to a bulk delete.
		$wpdb->query(
			"DELETE FROM `{$wpdb->usermeta}`
			WHERE meta_key = 'plandose_manual_access'
			   OR meta_key = 'plandose_print_receipt'
			   OR meta_key = 'plandose_dismissed_nginx_notice'
			   OR meta_key = 'plandose_dismissed_display_scope_notice'
			   OR meta_key LIKE 'plandose\\_dismissed\\_upload\\_size\\_notice\\_%'"
		);
	}
}

plandose_run_uninstall();