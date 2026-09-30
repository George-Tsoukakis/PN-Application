<?php
/**
 * PlanDose — one-off orphan invoice scanner.
 *
 * WHY THIS EXISTS
 *
 * On installs older than 1.14.1, deleting a pharmacy account removed the
 * subscription row that listed its invoice files, but never the files
 * themselves — leaving PDFs containing AFM and tax data on disk with nothing
 * left pointing at them. The record of which files belonged to whom is gone,
 * so this script lists every file in the invoice directory that no
 * surviving subscription row references.
 *
 * HOW TO RUN
 *
 *     wp eval-file wp-content/plugins/plandose/tools/find-orphan-invoices.php
 *
 * It reports and deletes nothing. Read the list, satisfy yourself that the
 * files really are orphans, then delete them yourself. Re-run it afterwards
 * to confirm.
 *
 * READ THIS BEFORE ACTING ON THE OUTPUT
 *
 * A file is listed as an orphan when no row in wp_plandose_subscriptions
 * names it. That is usually because the account was deleted — but a file
 * uploaded in the seconds between this script reading the database and
 * listing the directory would look identical. Run it when nobody is
 * uploading, and treat anything modified in the last few minutes as
 * suspect rather than as an orphan.
 *
 * Legacy Media Library entries (numeric attachment IDs) are not files in
 * this directory and are ignored on both sides of the comparison.
 *
 * Delete this file once you have used it.
 *
 * @package PlanDose
 */

// Opened over the web (not through WP-CLI): stop silently. STDERR exists
// only on the command line; writing to it from a web request is a fatal
// error that can print the server path.
if ( ! defined( 'ABSPATH' ) ) {
	if ( 'cli' === PHP_SAPI && defined( 'STDERR' ) ) {
		fwrite( STDERR, "Run this through WP-CLI: wp eval-file <path to this file>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI script writing to STDERR.
	}
	exit( 1 );
}

// Even inside WordPress, run only from the command line (WP-CLI).
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

if ( ! class_exists( 'Plandose_Invoice_Storage' ) ) {
	echo "PlanDose does not appear to be active on this site — nothing to scan.\n";
	return;
}

global $wpdb;

// readable_invoice_dir(), not invoice_dir() — the scanner only reports;
// it must not create the folder or write protection files. Not
// configured_invoice_dir() either: a PLANDOSE_INVOICE_DIR without the
// PlanDose ownership marker was never PlanDose's folder (the plugin itself
// refuses to read invoices from it), and listing its files as «orphaned
// invoices» would invite someone to delete another application's data.
$plandose_dir = Plandose_Invoice_Storage::readable_invoice_dir();

if ( is_wp_error( $plandose_dir ) ) {
	if ( 'plandose_invoice_dir_missing' === $plandose_dir->get_error_code() ) {
		echo 'Invoice directory: ' . Plandose_Invoice_Storage::configured_invoice_dir() . "\n\nIt does not exist yet (no invoice has been uploaded). Nothing to scan.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text CLI output (wp eval-file), not HTML.
		return;
	}

	echo "Could not use the invoice directory: " . $plandose_dir->get_error_message() . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text CLI output (wp eval-file), not HTML.
	return;
}

echo "Invoice directory: {$plandose_dir}\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text CLI output (wp eval-file), not HTML.

/*
 * Every filename any surviving subscription row still points at. Read first,
 * so that a file uploaded during the scan is reported as an orphan rather
 * than quietly skipped — over-reporting is recoverable, under-reporting is
 * the whole problem this script exists to solve.
 */
$plandose_table = Plandose_Subscriptions::table_name();

/*
 * Abort on any database problem. A missing table, a wrong prefix or a
 * dropped connection would otherwise produce an EMPTY reference list — and
 * every invoice on disk would then be reported as an orphan, inviting someone
 * to delete a pharmacy's tax records. An empty table is a real answer; a
 * failed query is not, and the two must never look alike.
 */
$plandose_table_found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $plandose_table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off CLI check that must see the live schema.

if ( '' !== $wpdb->last_error || $plandose_table_found !== $plandose_table ) {
	$plandose_reason = '' !== $wpdb->last_error ? $wpdb->last_error : "table {$plandose_table} does not exist";

	if ( defined( 'STDERR' ) ) {
		fwrite( STDERR, "ABORTED: could not read the subscriptions table ({$plandose_reason}).\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI script writing to STDERR.
		fwrite( STDERR, "Without it every invoice would look orphaned. Nothing was listed or deleted.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI script writing to STDERR.
	}

	exit( 1 );
}

$plandose_blobs = $wpdb->get_col( "SELECT invoices FROM `{$plandose_table}` WHERE invoices IS NOT NULL AND invoices != ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-off CLI scan; {$plandose_table} is $wpdb->prefix + fixed suffix, no values.

if ( '' !== $wpdb->last_error || ! is_array( $plandose_blobs ) ) {
	if ( defined( 'STDERR' ) ) {
		fwrite( STDERR, 'ABORTED: the query for referenced invoices failed (' . ( '' !== $wpdb->last_error ? $wpdb->last_error : 'no result' ) . ").\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI script writing to STDERR.
		fwrite( STDERR, "Without it every invoice would look orphaned. Nothing was listed or deleted.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI script writing to STDERR.
	}

	exit( 1 );
}

$plandose_referenced = array();

foreach ( $plandose_blobs as $plandose_blob ) {
	foreach ( Plandose_Subscriptions::decode_invoices( $plandose_blob ) as $plandose_entry ) {
		if ( is_numeric( $plandose_entry ) ) {
			continue; // Legacy Media Library attachment, not a file here.
		}

		$plandose_referenced[ basename( (string) $plandose_entry ) ] = true;
	}
}

// Support files the plugin writes itself; never orphans.
$plandose_own = array(
	'.htaccess'   => true,
	'web.config'  => true,
	'index.php'   => true,
	'index.html'  => true,
	Plandose_Invoice_Storage::INVOICE_DIR_MARKER_NAME => true,
);

$plandose_entries = scandir( $plandose_dir );

if ( false === $plandose_entries ) {
	echo "Could not read the directory.\n";
	return;
}

$plandose_orphans = array();
$plandose_bytes   = 0;
$plandose_recent  = 0;
$plandose_now     = time();

foreach ( $plandose_entries as $plandose_entry ) {
	if ( '.' === $plandose_entry || '..' === $plandose_entry ) {
		continue;
	}

	$plandose_path = trailingslashit( $plandose_dir ) . $plandose_entry;

	if ( ! is_file( $plandose_path ) || is_link( $plandose_path ) ) {
		continue;
	}

	if ( isset( $plandose_own[ $plandose_entry ] ) || isset( $plandose_referenced[ $plandose_entry ] ) ) {
		continue;
	}

	// A privacy-probe canary (Plandose_Invoice_Storage::CANARY_PATTERN)
	// holds only a random token, never an invoice: it exists for the
	// seconds a probe runs, and one left by a killed probe is deleted by
	// the next probe after an hour. Not an orphan.
	if ( Plandose_Invoice_Storage::is_canary_name( $plandose_entry ) ) {
		continue;
	}

	$plandose_mtime = (int) filemtime( $plandose_path );
	$plandose_age   = $plandose_now - $plandose_mtime;
	$plandose_size  = (int) filesize( $plandose_path );

	$plandose_bytes += $plandose_size;

	if ( $plandose_age < 600 ) {
		++$plandose_recent;
	}

	$plandose_orphans[] = sprintf(
		'  %-40s  %8s KB   modified %s%s',
		$plandose_entry,
		number_format( $plandose_size / 1024, 1 ),
		gmdate( 'Y-m-d H:i', $plandose_mtime ),
		$plandose_age < 600 ? '   <-- MODIFIED IN THE LAST 10 MINUTES, DO NOT DELETE YET' : ''
	);
}

echo 'Files referenced by a surviving subscription row: ' . count( $plandose_referenced ) . "\n";
echo 'Unreferenced files found: ' . count( $plandose_orphans ) . "\n\n";

if ( empty( $plandose_orphans ) ) {
	echo "Nothing to clean up.\n";
	return;
}

echo implode( "\n", $plandose_orphans ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text CLI output (wp eval-file), not HTML.
echo 'Total: ' . number_format( $plandose_bytes / ( 1024 * 1024 ), 2 ) . " MB\n";

if ( $plandose_recent > 0 ) {
	echo "\nWARNING: {$plandose_recent} of these were modified in the last 10 minutes. Re-run\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text CLI output (wp eval-file), not HTML.
	echo "the scan later before deciding — an upload in progress looks the same as an orphan.\n";
}

echo "\nNothing has been deleted. Review the list, then remove the files yourself.\n";
