<?php
/**
 * 1.30.2: invoice storage / migration / Media Library originals review fixes.
 *
 * - the privacy probe tests every invoice extension, «private» only when all
 *   are refused;
 * - stale probe canaries are cleaned up (probe start, uninstall cleanup);
 * - the migration result notice sums the batches of one pass;
 * - the pending-copies journal keeps copies whose delete failed;
 * - the «still in use» check before deleting an original covers post/term
 *   meta, gallery ids and wp-image classes;
 * - record_legacy_originals() no longer drops the alloptions cache.
 */

require __DIR__ . '/lib.php';

$opt    = Plandose_Invoice_Storage::PRIVACY_OPTION;
$backup = get_option( $opt, null );
pdt_defer(
	static function () use ( $opt, $backup ) {
		if ( null === $backup ) {
			delete_option( $opt );
		} else {
			update_option( $opt, $backup, false );
		}
	}
);

$dir = Plandose_Invoice_Storage::invoice_dir();
pdt_check( ! is_wp_error( $dir ), 'default invoice folder available' );

// ---- 1. one canary per extension ------------------------------------------

pdt_same( array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' ), Plandose_Invoice_Storage::invoice_extensions(), 'every allowed invoice extension is probed' );

/**
 * Invoice-folder canaries: $codes[ext] (default 403); a code of 200 serves
 * the file from disk. The control canary is always served.
 */
function pdt1302_http( array $codes, array &$seen ) {
	return static function ( $pre, $args, $url ) use ( $codes, &$seen ) {
		$uploads = wp_get_upload_dir();
		$path    = rawurldecode( str_replace( $uploads['baseurl'], $uploads['basedir'], strtok( $url, '?' ) ) );
		$ext     = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$code    = 200;

		if ( false !== strpos( $url, '/plandose-invoices/' ) ) {
			$seen[] = $ext;
			$code   = isset( $codes[ $ext ] ) ? $codes[ $ext ] : 403;
		}

		$body = 200 === $code && is_readable( $path ) ? (string) file_get_contents( $path ) : 'nope';

		return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	};
}

$run = static function ( array $codes ) use ( $dir ) {
	$seen = array();
	$f    = pdt1302_http( $codes, $seen );
	add_filter( 'pre_http_request', $f, 10, 3 );
	$state = Plandose_Invoice_Storage::probe_privacy( $dir, false );
	remove_filter( 'pre_http_request', $f, 10 );
	return array( $state, $seen );
};

list( $state, $seen ) = $run( array() );
pdt_same( 'private/http_refused', $state['status'] . '/' . $state['reason'], 'every extension refused → private' );
pdt_same( array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' ), $seen, '… after one request per extension' );

list( $state ) = $run( array( 'jpg' => 200 ) );
pdt_same( 'public/http_served', $state['status'] . '/' . $state['reason'], '.pdf refused but .jpg served → public' );
pdt_same( '.jpg', $state['detail'], '… naming the extension' );

list( $state ) = $run( array( 'webp' => 500 ) );
pdt_same( 'unverified/server_error', $state['status'] . '/' . $state['reason'], 'one extension answered 500 → unverified' );

list( $state ) = $run( array( 'png' => 200, 'pdf' => 404 ) );
pdt_same( 'public', $state['status'], 'png served, pdf 404 → public' );

$images = array(
	'jpg'  => IMAGETYPE_JPEG,
	'png'  => IMAGETYPE_PNG,
	'webp' => IMAGETYPE_WEBP,
);

foreach ( $images as $ext => $type ) {
	$tmp = wp_tempnam( 'pd1302.' . $ext );
	file_put_contents( $tmp, pdt_call( 'Plandose_Invoice_Storage', 'canary_body', $ext, 'TOKEN123' ) );
	$info = @getimagesize( $tmp );
	pdt_check( is_array( $info ) && $type === $info[2], ".$ext canary is a real image" );
	pdt_check( false !== strpos( (string) file_get_contents( $tmp ), 'TOKEN123' ), ".$ext canary carries the token" );
	unlink( $tmp );
}

pdt_same( array(), (array) glob( trailingslashit( $dir ) . Plandose_Invoice_Storage::CANARY_PREFIX . '*' ), 'no canary left in the invoice folder' );

// ---- 2. stale canaries ---------------------------------------------------

$old   = trailingslashit( $dir ) . Plandose_Invoice_Storage::CANARY_PREFIX . str_repeat( 'a', 24 ) . '.png';
$fresh = trailingslashit( $dir ) . Plandose_Invoice_Storage::CANARY_PREFIX . str_repeat( 'b', 24 ) . '.pdf';
$other = trailingslashit( $dir ) . Plandose_Invoice_Storage::CANARY_PREFIX . 'notes.txt';
file_put_contents( $old, 'x' );
file_put_contents( $fresh, 'x' );
file_put_contents( $other, 'x' );
touch( $old, time() - 2 * HOUR_IN_SECONDS );
touch( $other, time() - 2 * HOUR_IN_SECONDS );
pdt_defer(
	static function () use ( $old, $fresh, $other ) {
		foreach ( array( $old, $fresh, $other ) as $f ) {
			if ( file_exists( $f ) ) {
				unlink( $f );
			}
		}
	}
);

$run( array() );
pdt_check( ! file_exists( $old ), 'a canary older than an hour is deleted by the next probe' );
pdt_check( file_exists( $fresh ), 'a fresh canary (a probe running in parallel) is kept' );
pdt_check( file_exists( $other ), 'a file that only starts with the prefix is never touched' );
pdt_check( Plandose_Invoice_Storage::is_canary_name( 'plandose-probe-control-' . str_repeat( 'Z', 24 ) . '.pdf' ), 'the control canary name is recognised' );
pdt_check( ! Plandose_Invoice_Storage::is_canary_name( 'plandose-probe-' . str_repeat( 'Z', 24 ) . '.php' ), 'no other extension is' );

// Uninstall cleanup: a leftover canary no longer keeps the folder.
$custom = trailingslashit( get_temp_dir() ) . 'pd1302-' . wp_generate_password( 8, false, false );
mkdir( $custom );
file_put_contents( $custom . '/.htaccess', 'Require all denied' );
file_put_contents( $custom . '/' . Plandose_Invoice_Storage::INVOICE_DIR_MARKER_NAME, Plandose_Invoice_Storage::INVOICE_DIR_MARKER_CONTENT );
file_put_contents( $custom . '/' . Plandose_Invoice_Storage::CANARY_PREFIX . str_repeat( 'c', 24 ) . '.webp', 'x' );
file_put_contents( $custom . '/inv1.pdf', 'x' );
pdt_check( Plandose_Invoice_Storage::delete_custom_dir_contents( $custom, array( 'inv1.pdf' ) ), 'uninstall: invoice + canary + protection removed, folder removed' );

$custom2 = $custom . '-2';
mkdir( $custom2 );
file_put_contents( $custom2 . '/.htaccess', 'Require all denied' );
file_put_contents( $custom2 . '/foreign.pdf', 'x' );
pdt_check( ! Plandose_Invoice_Storage::delete_custom_dir_contents( $custom2, array() ), 'uninstall: a foreign file still keeps the folder' );
pdt_check( file_exists( $custom2 . '/.htaccess' ) && file_exists( $custom2 . '/foreign.pdf' ), '… with its deny rule' );
unlink( $custom2 . '/.htaccess' );
unlink( $custom2 . '/foreign.pdf' );
rmdir( $custom2 );

// ---- 3. result notice sums the batches of a pass ---------------------------

$t_backup = get_transient( Plandose_Invoice_Migration::RESULT_TRANSIENT );
$o_names  = array(
	Plandose_Invoice_Migration::LEGACY_MIGRATION_OPTION,
	Plandose_Invoice_Migration::LEGACY_MIGRATION_CURSOR_OPTION,
	Plandose_Invoice_Migration::LEGACY_MIGRATION_FAILED_OPTION,
	Plandose_Invoice_Migration::PENDING_COPIES_OPTION,
);
$o_backup = array();
foreach ( $o_names as $name ) {
	$o_backup[ $name ] = get_option( $name, null );
}
pdt_defer(
	static function () use ( $t_backup, $o_backup ) {
		delete_transient( Plandose_Invoice_Migration::RESULT_TRANSIENT );
		if ( false !== $t_backup ) {
			set_transient( Plandose_Invoice_Migration::RESULT_TRANSIENT, $t_backup, DAY_IN_SECONDS );
		}
		foreach ( $o_backup as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value, false );
			}
		}
	}
);

delete_transient( Plandose_Invoice_Migration::RESULT_TRANSIENT );
pdt_call( 'Plandose_Invoice_Migration', 'add_result', array( 11, 12 ), 25, 1 );
pdt_call( 'Plandose_Invoice_Migration', 'add_result', array( 12, 13, 14 ), 20, 2 );
$sum = get_transient( Plandose_Invoice_Migration::RESULT_TRANSIENT );
pdt_same( array( 4, 45, 3 ), array( $sum['rows'], $sum['files'], $sum['failed'] ), 'two batches summed; the pharmacy split by the budget counted once' );

pdt_call( 'Plandose_Invoice_Migration', 'add_result', array(), 0, 0 );
$sum = get_transient( Plandose_Invoice_Migration::RESULT_TRANSIENT );
pdt_same( 45, $sum['files'], 'an empty batch keeps the totals' );

// A batch continuing a pass (cursor stored) keeps them; a new pass resets.
update_option( Plandose_Invoice_Migration::LEGACY_MIGRATION_CURSOR_OPTION, PHP_INT_MAX - 1, false );
pdt_call( 'Plandose_Invoice_Migration', 'run_legacy_invoice_migration', false, 5, 5 );
$sum = get_transient( Plandose_Invoice_Migration::RESULT_TRANSIENT );
pdt_same( 45, is_array( $sum ) ? $sum['files'] : null, 'a later batch of the same pass keeps the totals' );

delete_option( Plandose_Invoice_Migration::LEGACY_MIGRATION_CURSOR_OPTION );
// A new pass: no cursor stored. Only the reset is checked here — a pass
// over this site's real rows is not run (a query error returns first).
$break = static function ( $query ) {
	if ( false !== strpos( $query, Plandose_Subscriptions::table_name() ) && 0 === strpos( ltrim( $query ), 'SELECT user_id, invoices' ) ) {
		return 'SELECT pdt_no_such_column FROM ' . Plandose_Subscriptions::table_name();
	}
	return $query;
};
add_filter( 'query', $break );
$suppress = $GLOBALS['wpdb']->suppress_errors( true );
pdt_call( 'Plandose_Invoice_Migration', 'run_legacy_invoice_migration', false, 5, 5 );
$GLOBALS['wpdb']->suppress_errors( $suppress );
remove_filter( 'query', $break );
pdt_same( false, get_transient( Plandose_Invoice_Migration::RESULT_TRANSIENT ), 'a new pass (no cursor) starts from zero' );

// ---- 4. the journal keeps copies whose delete failed -----------------------

$a = 'lm-' . wp_generate_password( 32, false, false ) . '.pdf';
$b = 'lm-' . wp_generate_password( 32, false, false ) . '.pdf';
$c = 'lm-' . wp_generate_password( 32, false, false ) . '.pdf';
update_option( Plandose_Invoice_Migration::PENDING_COPIES_OPTION, array( $a, $b, $c ), false );
pdt_call( 'Plandose_Invoice_Migration', 'unjournal', array( $a, $c ) );
pdt_same( array( $b ), get_option( Plandose_Invoice_Migration::PENDING_COPIES_OPTION ), 'unjournal() drops only the settled names' );
pdt_call( 'Plandose_Invoice_Migration', 'unjournal', array( $b ) );
pdt_same( false, get_option( Plandose_Invoice_Migration::PENDING_COPIES_OPTION, false ), '… and the option once empty' );

$stuck = trailingslashit( $dir ) . $a;
$gone  = trailingslashit( $dir ) . $b;
file_put_contents( $stuck, 'x' );
file_put_contents( $gone, 'x' );
$chattr = function_exists( 'exec' ) ? exec( 'chattr +i ' . escapeshellarg( $stuck ) . ' 2>/dev/null; echo $?' ) : '1';
pdt_defer(
	static function () use ( $stuck, $gone ) {
		exec( 'chattr -i ' . escapeshellarg( $stuck ) . ' 2>/dev/null' );
		foreach ( array( $stuck, $gone ) as $f ) {
			if ( file_exists( $f ) ) {
				unlink( $f );
			}
		}
	}
);

update_option( Plandose_Invoice_Migration::PENDING_COPIES_OPTION, array( $a, $b, $c ), false );
$deleted = Plandose_Invoice_Migration::cleanup_unrecorded_copies();
pdt_check( ! file_exists( $gone ), 'cleanup: an unrecorded copy is deleted' );

if ( '0' === $chattr ) {
	pdt_same( 1, $deleted, '… one deleted' );
	pdt_same( array( $a ), get_option( Plandose_Invoice_Migration::PENDING_COPIES_OPTION ), 'cleanup: a copy that could not be deleted stays journaled' );
	exec( 'chattr -i ' . escapeshellarg( $stuck ) . ' 2>/dev/null' );
	Plandose_Invoice_Migration::cleanup_unrecorded_copies();
	pdt_check( ! file_exists( $stuck ), '… and is deleted by the next run' );
} else {
	echo "skip   chattr unavailable: failed-delete case not exercised\n";
}

pdt_same( false, get_option( Plandose_Invoice_Migration::PENDING_COPIES_OPTION, false ), 'cleanup: journal empty once everything is settled' );

// ---- 5./7. «still in use» before deleting an original ----------------------

$att = wp_insert_attachment(
	array(
		'post_title'     => 'pd1302 invoice',
		'post_mime_type' => 'application/pdf',
		'post_status'    => 'inherit',
	),
	'2019/03/pd1302-' . wp_generate_password( 8, false, false ) . '.pdf'
);
$host = wp_insert_post( array( 'post_title' => 'pd1302 host', 'post_status' => 'draft', 'post_content' => 'nothing' ) );
$term = wp_insert_term( 'pd1302-' . wp_generate_password( 6, false, false ), 'category' );
pdt_defer(
	static function () use ( $att, $host, $term ) {
		wp_delete_post( $att, true );
		wp_delete_post( $host, true );
		if ( is_array( $term ) ) {
			wp_delete_term( $term['term_id'], 'category' );
		}
	}
);

$usage = static function () use ( $att ) {
	return pdt_call( 'Plandose_Admin_Invoices', 'legacy_original_usage_reason', $att );
};
$set_content = static function ( $content ) use ( $host ) {
	global $wpdb;
	$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $host ) );
	clean_post_cache( $host );
};

pdt_same( '', $usage(), 'unused attachment → not in use' );

update_post_meta( $host, '_price', (string) $att );
pdt_same( '', $usage(), 'a price equal to the ID is not usage' );
delete_post_meta( $host, '_price' );

update_post_meta( $host, '_product_image_gallery', '5,' . $att . ',7' );
pdt_same( 'in_meta', $usage(), 'WooCommerce gallery comma list → in use' );
update_post_meta( $host, '_product_image_gallery', '5,' . $att . '1,7' );
pdt_same( '', $usage(), '… but not a longer ID containing it' );
delete_post_meta( $host, '_product_image_gallery' );

update_post_meta( $host, 'hero_image', (string) $att );
pdt_same( 'in_meta', $usage(), 'ACF image field (exact ID) → in use' );
delete_post_meta( $host, 'hero_image' );

update_post_meta( $host, 'gallery', array( '3', (string) $att ) );
pdt_same( 'in_meta', $usage(), 'ACF gallery (serialized) → in use' );
delete_post_meta( $host, 'gallery' );

if ( is_array( $term ) ) {
	update_term_meta( $term['term_id'], 'thumbnail_id', (string) $att );
	pdt_same( 'in_meta', $usage(), 'category thumbnail → in use' );
	delete_term_meta( $term['term_id'], 'thumbnail_id' );
}

$set_content( '[gallery ids="3, ' . $att . ',9"]' );
pdt_same( 'in_content', $usage(), '[gallery ids] → in use' );
$set_content( '[gallery ids="3,' . $att . '5"]' );
pdt_same( '', $usage(), '… not a longer ID' );
$set_content( '<img class="wp-image-' . $att . '" src="x-300x200.jpg">' );
pdt_same( 'in_content', $usage(), 'wp-image-<ID> → in use' );
$set_content( '<!-- wp:file {"id":' . $att . ',"href":"x"} -->' );
pdt_same( 'in_content', $usage(), 'block "id":<ID> → in use' );
$set_content( '<!-- wp:gallery {"ids":[1,' . $att . ']} -->' );
pdt_same( 'in_content', $usage(), 'block "ids":[…] → in use' );
$set_content( 'nothing' );

wp_update_post( array( 'ID' => $att, 'post_parent' => $host ) );
pdt_same( '', $usage(), 'attached to a post (post_parent) alone is not usage' );
$set_content( '[gallery]' );
pdt_same( 'in_content', $usage(), '… but a [gallery] without ids in that post is' );
$set_content( 'nothing' );
pdt_check( '' !== Plandose_Admin_Invoices::legacy_original_reason_label( 'in_meta' ) && Plandose_Admin_Invoices::legacy_original_reason_label( 'in_meta' ) !== Plandose_Admin_Invoices::legacy_original_reason_label( 'zzz' ), 'in_meta has its own label' );

// ---- 6. alloptions stays cached ------------------------------------------

$l_backup = get_option( Plandose_Admin_Invoices::LEGACY_ORIGINALS_OPTION, null );
pdt_defer(
	static function () use ( $l_backup ) {
		if ( null === $l_backup ) {
			delete_option( Plandose_Admin_Invoices::LEGACY_ORIGINALS_OPTION );
		} else {
			update_option( Plandose_Admin_Invoices::LEGACY_ORIGINALS_OPTION, $l_backup, false );
		}
	}
);
wp_load_alloptions();
pdt_check( false !== wp_cache_get( 'alloptions', 'options' ), 'alloptions cached before' );
$rec = array(
	array(
		'attachment_id' => $att,
		'user_id'       => 1,
		'filename'      => 'lm-' . wp_generate_password( 32, false, false ) . '.pdf',
		'migrated_at'   => time(),
	),
);
pdt_same( 1, Plandose_Admin_Invoices::record_legacy_originals( $rec ), 'record_legacy_originals() stores the record' );
pdt_check( false !== wp_cache_get( 'alloptions', 'options' ), '… without dropping the alloptions cache' );
pdt_check( Plandose_Admin_Invoices::legacy_originals_contain( $rec ), '… and it reads back' );

pdt_done();
