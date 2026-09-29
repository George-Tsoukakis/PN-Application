<?php
/**
 * PlanDose — the personal-data exporter pages through the WHOLE print log
 * and print-charge ledger (it used to put the first 5000 / 500 rows on
 * page 1 and stop), while the one-off groups appear exactly once.
 * TEST-ONLY.
 */

require __DIR__ . '/lib.php';

global $wpdb;

$uid   = pdt_user( array( 'account_type' => 'Φαρμακείο', 'pharmacy_name' => 'Φαρμακείο Σελιδοποίησης' ) );
$email = get_userdata( $uid )->user_email;
$audit = Plandose_Subscriptions::audit_table_name();

pdt_defer(
	static function () use ( $uid, $audit ) {
		global $wpdb;
		Plandose_Print_Log::delete_for_user( $uid );
		$wpdb->delete( $audit, array( 'target_user_id' => $uid ) ); // phpcs:ignore
	}
);

// A small page size, so a handful of rows spans several pages.
$page_size = 5;
add_filter(
	'plandose_privacy_export_rows_per_page',
	static function () use ( $page_size ) {
		return $page_size;
	}
);

// One-off groups: subscription row, closed month, two audit rows.
$wpdb->insert( Plandose_Subscriptions::table_name(), array( 'user_id' => $uid, 'status' => 'free', 'print_count' => 2 ) ); // phpcs:ignore
$wpdb->insert( Plandose_Subscriptions::history_table_name(), array( 'user_id' => $uid, 'ym' => '2025-01', 'prints' => 3 ) ); // phpcs:ignore
for ( $i = 0; $i < 2; $i++ ) {
	$wpdb->insert( $audit, array( 'created_at' => current_time( 'mysql' ), 'event' => 'pdt_paging', 'target_user_id' => $uid, 'meta' => '{}' ) ); // phpcs:ignore
}

// 23 print-log rows and 7 charge rows, each at a distinct second, so the
// exported date string identifies the row.
$base       = time() - 3 * HOUR_IN_SECONDS;
$log_names  = array();
$log_ids    = array();
for ( $i = 0; $i < 23; $i++ ) {
	$wpdb->insert( Plandose_Print_Log::table_name(), array( 'user_id' => $uid, 'printed_ts' => $base + $i, 'kind' => ( $i % 3 ) ? 'charge' : 'reprint', 'is_pro' => 1, 'month_count' => $i + 1 ) ); // phpcs:ignore
	$log_ids[]   = (int) $wpdb->insert_id;
	$log_names[] = wp_date( 'Y-m-d H:i:s', $base + $i );
}
$charge_base  = time() - 10 * MINUTE_IN_SECONDS;
$charge_names = array();
for ( $i = 0; $i < 7; $i++ ) {
	$wpdb->insert( Plandose_Print_Charges::table_name(), array( 'user_id' => $uid, 'token_hash' => md5( 'pdt-paging-' . $i ), 'charged_ts' => $charge_base + $i, 'reprints' => $i ) ); // phpcs:ignore
	$charge_names[] = wp_date( 'Y-m-d H:i:s', $charge_base + $i );
}

pdt_same( 23, count( Plandose_Print_Log::rows_for_user( $uid ) ), 'fixture: 23 print-log rows' );

// ---- rows_for_user(): id order, offset paging --------------------------------------------
$paged_ids = array();
for ( $offset = 0; $offset < 30; $offset += $page_size ) {
	foreach ( Plandose_Print_Log::rows_for_user( $uid, $page_size, $offset ) as $row ) {
		$paged_ids[] = (int) $row->id;
	}
}
pdt_same( $log_ids, $paged_ids, 'print log rows_for_user(): every id once, in id order, across offsets' );

// ---- the exporter, page by page, the way WordPress drives it -----------------------------
$pages      = 0;
$done       = false;
$item_keys  = array();
$group_hits = array();
$log_seen   = array();
$chg_seen   = array();

while ( ! $done && $pages < 50 ) {
	++$pages;
	$res  = Plandose_Privacy::export( $email, $pages );
	$done = (bool) $res['done'];

	foreach ( $res['data'] as $item ) {
		$item_keys[] = $item['group_id'] . '|' . $item['item_id'];

		if ( ! isset( $group_hits[ $item['group_id'] ] ) ) {
			$group_hits[ $item['group_id'] ] = 0;
		}
		++$group_hits[ $item['group_id'] ];

		if ( 'plandose-print-log' === $item['group_id'] ) {
			pdt_check( count( $item['data'] ) <= $page_size, "page $pages: print-log item within the page size" );
			foreach ( $item['data'] as $d ) {
				$log_seen[] = $d['name'];
			}
		}

		if ( 'plandose-print-charges' === $item['group_id'] ) {
			foreach ( $item['data'] as $d ) {
				$chg_seen[] = $d['name'];
			}
		}
	}
}

pdt_check( $done, 'the exporter reports done eventually' );
pdt_same( 5, $pages, '23 rows at 5 per page: done on page 5, not before' );
pdt_same( $log_names, $log_seen, 'every print-log row exported exactly once, oldest first' );
pdt_same( $charge_names, $chg_seen, 'every print-charge row exported exactly once (7 rows over 2 pages)' );
pdt_same( count( $item_keys ), count( array_unique( $item_keys ) ), 'no item_id repeats across pages (WordPress would merge them out of order)' );

foreach ( array( 'plandose-subscription', 'plandose-print-history' ) as $group ) {
	pdt_same( 1, isset( $group_hits[ $group ] ) ? $group_hits[ $group ] : 0, "$group exported exactly once" );
}
pdt_same( 2, isset( $group_hits['plandose-audit'] ) ? $group_hits['plandose-audit'] : 0, 'both audit rows exported exactly once' );

// A page past the end is empty and done (WordPress never asks, but it must not repeat anything).
$past = Plandose_Privacy::export( $email, $pages + 1 );
pdt_same( array(), $past['data'], 'a page past the end holds nothing' );
pdt_check( $past['done'], '… and is done' );

// Default page size: a single page holds everything here.
remove_all_filters( 'plandose_privacy_export_rows_per_page' );
$one = Plandose_Privacy::export( $email, 1 );
pdt_check( $one['done'], 'default page size: 23 rows fit on page 1, done' );

pdt_done();
