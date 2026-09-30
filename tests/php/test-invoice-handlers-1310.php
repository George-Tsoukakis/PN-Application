<?php
/**
 * 1.31.0: behavioural coverage of the invoice admin handlers and of the
 * invoice-storage path / content checks. TEST-ONLY.
 *
 * - Plandose_Admin_Invoices::validate_invoice_upload(): PHP upload error
 *   codes, a file that did not come from this request's upload;
 * - handle_upload_invoice(): method / capability / nonce /
 *   can_manage_account() refusals and the pre-validation errors, in
 *   process; then REAL multipart uploads through admin-post.php on the test
 *   server (is_uploaded_file() cannot be faked in the CLI): a minimal PDF
 *   and PNG are stored under a random name in the invoice folder and listed
 *   in the subscription row (audited), while the size limit, a disallowed
 *   extension, a double extension, a PNG named .jpg, PHP text named .pdf
 *   and an empty file are refused with nothing written;
 * - handle_delete_invoice(): fingerprint mismatch refused, success removes
 *   the list entry BEFORE the file, a failed DB write keeps the file;
 * - handle_delete_deleted_account_invoices(): requires delete_users, only
 *   for accounts that are gone, row first then files;
 * - Plandose_Invoice_Storage: sniff_real_mime / content_matches_mime*,
 *   invoice_path(), resolve_path() / path_is_in_public_tree() /
 *   invoice_dir_is_public() (the custom-folder cases in a child process
 *   that defines PLANDOSE_INVOICE_DIR), is_canary_name().
 *
 * The HTTP part talks to PD_BASE (default: the site URL) and is skipped
 * with a SKIP line when no server answers there.
 */

require __DIR__ . '/lib.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

global $wpdb;

ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- expected log lines (rollbacks, fs errors).

$table       = Plandose_Subscriptions::table_name();
$audit_table = Plandose_Subscriptions::audit_table_name();
$dir         = Plandose_Invoice_Storage::invoice_dir();
pdt_check( ! is_wp_error( $dir ) && is_dir( $dir ), 'default invoice folder available' );
$dir = is_wp_error( $dir ) ? '' : untrailingslashit( $dir );

// ---- shared state backups -------------------------------------------------------

$o_names  = array(
	'plandose_settings',
	Plandose_Invoice_Storage::PRIVACY_OPTION,
	Plandose_Admin_Invoices::LEGACY_ORIGINALS_OPTION,
	Plandose_Admin::DELETED_ACCOUNTS_OPTION,
);
$o_backup = array();
foreach ( $o_names as $name ) {
	$o_backup[ $name ] = get_option( $name, null );
}
pdt_defer(
	static function () use ( $o_backup ) {
		foreach ( $o_backup as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value, false );
			}
		}
	}
);

// Every file this test puts into (or finds added to) the invoice folder.
$files_before = $dir ? (array) scandir( $dir ) : array();
pdt_defer(
	static function () use ( $dir, $files_before ) {
		if ( ! $dir ) {
			return;
		}
		clearstatcache();
		foreach ( array_diff( (array) scandir( $dir ), $files_before ) as $f ) {
			if ( 0 === strpos( $f, 'pd1310-' ) || 1 === preg_match( '/^[A-Za-z0-9]{32}(-\d+)?\.(pdf|png|jpg|jpeg|webp)$/', $f ) ) {
				@unlink( $dir . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test cleanup.
			}
		}
	}
);

$audited_targets = array();
pdt_defer(
	static function () use ( &$audited_targets, $audit_table ) {
		global $wpdb;
		foreach ( array_unique( $audited_targets ) as $uid ) {
			$wpdb->delete( $audit_table, array( 'target_user_id' => $uid ) );
		}
	}
);

// ---- fixtures -------------------------------------------------------------------

$tmp = trailingslashit( get_temp_dir() ) . 'pd1310-' . strtolower( wp_generate_password( 10, false, false ) );
wp_mkdir_p( $tmp );
/** Remove a scratch folder; a symlink is removed itself, never followed. */
function pdt1310_rm( $path ) {
	if ( is_link( $path ) || is_file( $path ) ) {
		unlink( $path );
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	foreach ( (array) scandir( $path ) as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) {
			pdt1310_rm( $path . '/' . $entry );
		}
	}
	rmdir( $path );
}
pdt_defer(
	static function () use ( $tmp ) {
		pdt1310_rm( $tmp );
	}
);

$pdf_bytes  = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
$png_bytes  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 1×1 test image.
$jpeg_bytes = pdt_call( 'Plandose_Invoice_Storage', 'canary_body', 'jpg', '' );
$webp_bytes = pdt_call( 'Plandose_Invoice_Storage', 'canary_body', 'webp', '' );
$php_bytes  = "<?php echo 'pwned'; ?>\n";

$fixture = static function ( $name, $bytes ) use ( $tmp ) {
	$path = $tmp . '/' . $name;
	file_put_contents( $path, $bytes );
	return $path;
};

$f_pdf     = $fixture( 'a.pdf', $pdf_bytes );
$f_png     = $fixture( 'a.png', $png_bytes );
$f_jpeg    = $fixture( 'a.jpg', $jpeg_bytes );
$f_webp    = $fixture( 'a.webp', $webp_bytes );
$f_php     = $fixture( 'php.pdf', $php_bytes );
$f_gif_php = $fixture( 'gifphp.png', "GIF89a\x01\x00\x01\x00<?php system(\$_GET['c']); ?>" );
$f_garbage = $fixture( 'garbage.pdf', "\x00\x01\x02\x03\xFF\xFE garbage \x7F\x80" . str_repeat( "\x9A", 40 ) );
$f_junkpdf = $fixture( 'junkpdf.pdf', "JUNK\n" . $pdf_bytes );
$f_empty   = $fixture( 'empty.pdf', '' );

/** Run an admin-post handler; its redirect / wp_die come back as a string. */
function pdt1310_run( $callback ) {
	$redirect = static function ( $location ) {
		throw new RuntimeException( 'redirect:' . $location );
	};
	$die      = static function () {
		return static function ( $message, $title = '', $args = array() ) {
			$code = is_array( $args ) ? ( isset( $args['response'] ) ? $args['response'] : '' ) : $args;
			throw new RuntimeException( 'die:' . $code . ':' . wp_strip_all_tags( is_scalar( $message ) ? (string) $message : '' ) );
		};
	};
	add_filter( 'wp_redirect', $redirect );
	add_filter( 'wp_die_handler', $die );
	try {
		call_user_func( $callback );
		$out = 'returned';
	} catch ( RuntimeException $e ) {
		$out = $e->getMessage();
	}
	remove_filter( 'wp_redirect', $redirect );
	remove_filter( 'wp_die_handler', $die );

	return $out;
}

/** A POST request as seen by an admin-post handler. */
function pdt1310_post( array $post, $method = 'POST' ) {
	$_SERVER['REQUEST_METHOD'] = $method;
	$_POST                     = $post;
	$_GET                      = array();
	$_REQUEST                  = $post;
	$_FILES                    = array();
	unset( $_SERVER['HTTP_REFERER'] );
}

/** A transient straight from the database (the other process wrote it). */
function pdt1310_transient( $key ) {
	global $wpdb;
	$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . $key ) );
	return null === $v ? null : maybe_unserialize( $v );
}

function pdt1310_clear_transients( $admin_id ) {
	delete_transient( 'plandose_invoice_error_' . $admin_id );
	delete_transient( 'plandose_invoice_warning_' . $admin_id );
	wp_cache_flush();
}

/** The last audit row of an event for a target, or null. */
function pdt1310_audit( $event, $target ) {
	global $wpdb;
	$t = Plandose_Subscriptions::audit_table_name();
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE event = %s AND target_user_id = %d ORDER BY id DESC LIMIT 1", $event, $target ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test only.
}

function pdt1310_audit_count( $event, $target ) {
	global $wpdb;
	$t = Plandose_Subscriptions::audit_table_name();
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE event = %s AND target_user_id = %d", $event, $target ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test only.
}

$row_list = static function ( $uid ) {
	$row = Plandose_Subscriptions::get_row( $uid, false );
	return $row ? Plandose_Subscriptions::decode_invoices( $row->invoices ) : null;
};

$dir_listing = static function () use ( $dir ) {
	clearstatcache();
	$all = (array) scandir( $dir );
	sort( $all );
	return $all;
};

// A PlanDose manager that is NOT a site administrator: a custom PlanDose
// capability plus edit_users (so edit_user passes for ordinary accounts).
$custom_cap = static function () {
	return 'pd1310_manage';
};

$admin    = pdt_user( array(), 'administrator' );
$admin2   = pdt_user( array(), 'administrator' );
$manager  = pdt_user( array(), 'editor' );
$ph       = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$not_ph   = pdt_user( array( 'account_type' => 'Ιατρείο' ) );
$audited_targets = array( $admin, $admin2, $manager, $ph, $not_ph );
( new WP_User( $manager ) )->add_cap( 'pd1310_manage' );
( new WP_User( $manager ) )->add_cap( 'edit_users' );

// =================================================================================
// 1. validate_invoice_upload(): error codes and the is_uploaded_file() guard
// =================================================================================

$validate = static function ( $file ) {
	return pdt_call( 'Plandose_Admin_Invoices', 'validate_invoice_upload', $file );
};
$code_of  = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
};

pdt_same( 'plandose_upload_missing', $code_of( $validate( array() ) ), '1: empty $_FILES entry → plandose_upload_missing' );
pdt_same( 'plandose_upload_missing', $code_of( $validate( 'a.pdf' ) ), '1: a non-array entry → plandose_upload_missing' );

$err_messages = array(
	UPLOAD_ERR_INI_SIZE   => 'upload_max_filesize',
	UPLOAD_ERR_FORM_SIZE  => 'μέγεθος της φόρμας',
	UPLOAD_ERR_PARTIAL    => 'εν μέρει',
	UPLOAD_ERR_NO_FILE    => 'Δεν επιλέχθηκε',
	UPLOAD_ERR_NO_TMP_DIR => 'προσωρινός φάκελος',
	UPLOAD_ERR_CANT_WRITE => 'δεν μπόρεσε να γράψει',
	UPLOAD_ERR_EXTENSION  => 'επέκταση του PHP',
	99                    => 'Σφάλμα κατά το ανέβασμα',
);
foreach ( $err_messages as $err => $needle ) {
	$r = $validate( array( 'name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => $f_pdf, 'error' => $err, 'size' => 10 ) );
	pdt_check( is_wp_error( $r ) && 'plandose_upload_error' === $r->get_error_code() && false !== strpos( $r->get_error_message(), $needle ), "1: PHP upload error $err → plandose_upload_error «{$needle}»" );
}
$r = $validate( array( 'name' => 'a.pdf', 'tmp_name' => $f_pdf ) );
pdt_check( is_wp_error( $r ) && 'plandose_upload_error' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'Δεν επιλέχθηκε' ), '1: no error key → treated as UPLOAD_ERR_NO_FILE' );
$r = $validate( array( 'name' => 'a.pdf', 'tmp_name' => $f_pdf, 'error' => '3' ) );
pdt_same( 'plandose_upload_error', $code_of( $r ), "1: a string error code ('3') is still an error" );

// UPLOAD_ERR_OK, but not a file PHP received in this request: never trusted,
// whatever the path — a local path cannot be smuggled in as tmp_name.
foreach ( array( $f_pdf, $f_png, ABSPATH . 'wp-config.php', '/etc/passwd' ) as $path ) {
	$r = $validate( array( 'name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 10 ) );
	pdt_same( 'plandose_upload_invalid', $code_of( $r ), '1: error OK but tmp_name ' . basename( $path ) . ' is not an uploaded file → plandose_upload_invalid' );
}
pdt_same( 'plandose_upload_invalid', $code_of( $validate( array( 'name' => 'a.pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_OK ) ) ), '1: empty tmp_name → plandose_upload_invalid' );
pdt_same( 'plandose_upload_invalid', $code_of( $validate( array( 'name' => '', 'tmp_name' => $f_pdf, 'error' => UPLOAD_ERR_OK ) ) ), '1: empty name → plandose_upload_invalid' );

// =================================================================================
// 2. handle_upload_invoice() in process: refusals before any file is touched
// =================================================================================

$upload = static function () {
	Plandose_Admin_Invoices::handle_upload_invoice();
};
$err_key = static function ( $uid ) {
	return 'plandose_invoice_error_' . $uid;
};
$listing0 = $dir_listing();

wp_set_current_user( $admin );
pdt1310_post( array( 'user_id' => (string) $ph, '_wpnonce' => wp_create_nonce( 'plandose_upload_invoice' ) ), 'GET' );
pdt_check( 0 === strpos( pdt1310_run( $upload ), 'die:405:' ), '2: GET → 405' );

wp_set_current_user( $ph );
pdt1310_post( array( 'user_id' => (string) $ph, '_wpnonce' => wp_create_nonce( 'plandose_upload_invoice' ) ) );
pdt_check( 0 === strpos( pdt1310_run( $upload ), 'die:403:Δεν έχετε δικαίωμα πρόσβασης' ), '2: a pharmacy (no PlanDose capability) → 403' );

wp_set_current_user( 0 );
pdt1310_post( array( 'user_id' => (string) $ph ) );
pdt_check( 0 === strpos( pdt1310_run( $upload ), 'die:403:' ), '2: logged out → 403' );

wp_set_current_user( $admin );
pdt1310_post( array( 'user_id' => (string) $ph, '_wpnonce' => 'deadbeef00' ) );
pdt_check( 0 === strpos( pdt1310_run( $upload ), 'die:403:' ), '2: bad nonce → 403 (check_admin_referer)' );
pdt1310_post( array( 'user_id' => (string) $ph ) );
pdt_check( 0 === strpos( pdt1310_run( $upload ), 'die:403:' ), '2: missing nonce → 403' );
pdt1310_post( array( 'user_id' => (string) $ph, '_wpnonce' => wp_create_nonce( 'plandose_delete_invoice_' . $ph ) ) );
pdt_check( 0 === strpos( pdt1310_run( $upload ), 'die:403:' ), '2: a nonce for another action → 403' );

// can_manage_account(): a PlanDose manager who is not an administrator.
add_filter( 'plandose_manage_capability', $custom_cap );
wp_set_current_user( $manager );
pdt_check( Plandose_Admin::can_manage_account( $ph ), '2: (control) the custom-capability manager may manage a pharmacy' );
foreach ( array( 'another administrator' => $admin2, 'their own account' => $manager ) as $label => $target ) {
	pdt1310_post( array( 'user_id' => (string) $target, '_wpnonce' => wp_create_nonce( 'plandose_upload_invoice' ) ) );
	pdt_check( 0 === strpos( pdt1310_run( $upload ), 'die:403:Δεν έχετε δικαίωμα να επεξεργαστείτε' ), "2: non-admin manager uploading for $label → 403 (can_manage_account)" );
}
remove_filter( 'plandose_manage_capability', $custom_cap );

// Past the gates: errors are reported through the per-admin transient and a
// redirect back, with nothing stored.
wp_set_current_user( $admin );
$cases = array(
	'unknown user'      => array( array( 'user_id' => '999999999' ), null, 'Ο χρήστης δεν βρέθηκε' ),
	'user_id not digits' => array( array( 'user_id' => $ph . 'x' ), null, 'Ο χρήστης δεν βρέθηκε' ),
	'no file'           => array( array( 'user_id' => (string) $ph ), null, 'Δεν επιλέχθηκε αρχείο' ),
	'not a pharmacy'    => array( array( 'user_id' => (string) $not_ph ), array( 'name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => $f_pdf, 'error' => 0, 'size' => 10 ), 'Φαρμακείο' ),
	'not uploaded file' => array( array( 'user_id' => (string) $ph ), array( 'name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => $f_pdf, 'error' => 0, 'size' => 10 ), 'δεν είναι έγκυρο' ),
	'PHP upload error'  => array( array( 'user_id' => (string) $ph ), array( 'name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0 ), 'upload_max_filesize' ),
);
foreach ( $cases as $label => $case ) {
	list( $post, $file, $needle ) = $case;
	pdt1310_clear_transients( $admin );
	pdt1310_post( $post + array( '_wpnonce' => wp_create_nonce( 'plandose_upload_invoice' ) ) );
	if ( $file ) {
		$_FILES = array( 'plandose_invoice_file' => $file );
	}
	$_SERVER['HTTP_REFERER'] = admin_url( 'admin.php?page=plandose-subscriptions&pd1310=' . rawurlencode( $label ) );
	$out = pdt1310_run( $upload );
	$msg = get_transient( $err_key( $admin ) );
	pdt_check( 'redirect:' . admin_url( 'admin.php?page=plandose-subscriptions&pd1310=' . rawurlencode( $label ) ) === $out, "2: $label → redirect back to the referer: $out" );
	pdt_check( is_string( $msg ) && false !== strpos( $msg, $needle ), "2: $label → error «{$needle}» for this admin: " . var_export( $msg, true ) );
}
pdt_same( null, $row_list( $not_ph ), '2: a non-pharmacy account gets no subscription row from an upload attempt' );
pdt_same( array(), (array) $row_list( $ph ), '2: … and the pharmacy nothing listed' );
pdt_same( $listing0, $dir_listing(), '2: … and nothing written into the invoice folder' );
pdt_same( 0, pdt1310_audit_count( 'invoice_upload', $ph ), '2: … and no invoice_upload audit entry' );
pdt1310_post( array() );

// =================================================================================
// 3. real uploads through admin-post.php (is_uploaded_file() is true there)
// =================================================================================

$base = getenv( 'PD_BASE' ) ? untrailingslashit( getenv( 'PD_BASE' ) ) : untrailingslashit( site_url() );
$ping = wp_remote_get( $base . '/wp-login.php', array( 'timeout' => 10, 'redirection' => 0 ) );

if ( is_wp_error( $ping ) || 200 !== (int) wp_remote_retrieve_response_code( $ping ) ) {
	echo "SKIP   3: no test server answering at $base (set PD_BASE) — real-upload checks not run\n";
} else {
	// A logged-in session for $admin, shared by this process (nonce) and
	// the server (cookies).
	$expiration = time() + HOUR_IN_SECONDS;
	$token      = WP_Session_Tokens::get_instance( $admin )->create( $expiration );
	$c_auth     = wp_generate_auth_cookie( $admin, $expiration, 'auth', $token );
	$c_login    = wp_generate_auth_cookie( $admin, $expiration, 'logged_in', $token );
	$prev_cookie = $_COOKIE;
	$_COOKIE[ LOGGED_IN_COOKIE ] = $c_login;
	wp_set_current_user( $admin );
	$nonce = wp_create_nonce( 'plandose_upload_invoice' );
	$_COOKIE = $prev_cookie;
	$cookie_header = AUTH_COOKIE . '=' . rawurlencode( $c_auth ) . '; ' . LOGGED_IN_COOKIE . '=' . rawurlencode( $c_login );
	$referer       = $base . '/wp-admin/admin.php?page=plandose-subscriptions&pd1310=1';

	// Fresh «private» verdict for the default folder: the server accepts
	// uploads without running its own probe (the built-in server really
	// serves the folder, see storage-admin-1270.php).
	update_option(
		Plandose_Invoice_Storage::PRIVACY_OPTION,
		array(
			'status'        => 'private',
			'reason'        => 'http_refused',
			'checked_at'    => time(),
			'dir'           => untrailingslashit( wp_normalize_path( $dir ) ),
			'home'          => home_url( '/' ),
			'url'           => '',
			'http_code'     => 404,
			'detail'        => '',
			'docroot_known' => true,
		),
		false
	);

	$http_upload = static function ( $name, $bytes, $user_id, $ctype = 'application/octet-stream', $nonce_value = null ) use ( $base, $cookie_header, $referer, $nonce, $admin ) {
		pdt1310_clear_transients( $admin );
		$boundary = '----pd1310' . wp_generate_password( 16, false, false );
		$fields   = array(
			'action'   => 'plandose_upload_invoice',
			'user_id'  => (string) $user_id,
			'_wpnonce' => null === $nonce_value ? $nonce : $nonce_value,
		);
		$body     = '';
		foreach ( $fields as $k => $v ) {
			$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
		}
		$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"plandose_invoice_file\"; filename=\"" . str_replace( '"', '', $name ) . "\"\r\nContent-Type: $ctype\r\n\r\n" . $bytes . "\r\n--$boundary--\r\n";
		$res = wp_remote_post(
			$base . '/wp-admin/admin-post.php',
			array(
				'timeout'     => 60,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
					'Cookie'       => $cookie_header,
					'Referer'      => $referer,
				),
				'body'        => $body,
			)
		);
		wp_cache_flush();
		return array(
			is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res ),
			is_wp_error( $res ) ? $res->get_error_message() : (string) wp_remote_retrieve_header( $res, 'location' ),
			pdt1310_transient( 'plandose_invoice_error_' . $admin ),
			pdt1310_transient( 'plandose_invoice_warning_' . $admin ),
		);
	};

	// ---- 3a. a minimal PDF is stored ------------------------------------------------

	list( $code, $loc, $err, $warn ) = $http_upload( 'Τιμολόγιο Μαρτίου.pdf', $pdf_bytes, $ph, 'application/pdf' );
	$list = (array) $row_list( $ph );
	pdt_same( 302, $code, '3a: PDF upload → 302' );
	pdt_same( $referer, $loc, '3a: … back to the referring screen' );
	pdt_same( null, $err, '3a: … no error' );
	pdt_same( null, $warn, '3a: … no storage warning (verified private)' );
	pdt_same( 1, count( $list ), '3a: … one entry in the subscription row: ' . wp_json_encode( $list ) );
	$stored = isset( $list[0] ) ? (string) $list[0] : '';
	pdt_check( 1 === preg_match( '/^[A-Za-z0-9]{32}\.pdf$/', $stored ), "3a: … under a random 32-character name with the .pdf extension: $stored" );
	pdt_check( false === stripos( $stored, 'Τιμολόγιο' ) && false === stripos( $stored, 'Μαρτίου' ), '3a: … that keeps nothing of the client file name' );
	pdt_check( '' !== $stored && is_file( $dir . '/' . $stored ) && $pdf_bytes === file_get_contents( $dir . '/' . $stored ), '3a: … the file is in the invoice folder with the uploaded bytes' );
	pdt_same( '0640', '' !== $stored && is_file( $dir . '/' . $stored ) ? substr( sprintf( '%o', fileperms( $dir . '/' . $stored ) ), -4 ) : '', '3a: … mode 0640' );
	$a = pdt1310_audit( 'invoice_upload', $ph );
	$meta = $a ? json_decode( (string) $a->meta, true ) : null;
	pdt_check( $a && (int) $a->admin_user_id === $admin && is_array( $meta ) && isset( $meta['file'] ) && $stored === $meta['file'], '3a: … audited (invoice_upload, by this admin, naming the stored file)' );
	pdt_check( $a && '' !== (string) $a->ip && false === strpos( (string) $a->ip, '127.0.0.1' ), '3a: … with an anonymised IP: ' . ( $a ? $a->ip : '' ) );

	// ---- 3b. a PNG is stored, and the same client name twice gives two names ---------

	list( $code, , $err ) = $http_upload( 'scan.png', $png_bytes, $ph, 'image/png' );
	list( $code2, , $err2 ) = $http_upload( 'scan.png', $png_bytes, $ph, 'image/png' );
	$list = (array) $row_list( $ph );
	pdt_check( 302 === $code && null === $err && 302 === $code2 && null === $err2, '3b: two PNG uploads accepted' );
	pdt_same( 3, count( $list ), '3b: … appended to the list in upload order: ' . wp_json_encode( $list ) );
	pdt_check( 3 === count( $list ) && $list[0] === $stored && 1 === preg_match( '/^[A-Za-z0-9]{32}\.png$/', (string) $list[1] ) && 1 === preg_match( '/^[A-Za-z0-9]{32}\.png$/', (string) $list[2] ) && $list[1] !== $list[2], '3b: … each under its own random .png name' );
	pdt_check( 3 === count( $list ) && is_file( $dir . '/' . $list[1] ) && is_file( $dir . '/' . $list[2] ), '3b: … both files on disk' );
	pdt_same( 3, pdt1310_audit_count( 'invoice_upload', $ph ), '3b: … one audit entry per stored invoice' );

	// ---- 3c. refusals: nothing stored, nothing listed, a clear message ---------------

	$settings = get_option( 'plandose_settings' );
	$settings = is_array( $settings ) ? $settings : array();
	update_option( 'plandose_settings', array_merge( $settings, array( 'invoice_max_mb' => 1 ) ) );
	$limit     = 1024 * 1024;
	$big_pdf   = '%PDF-1.4' . "\n%" . str_repeat( 'A', $limit + 1 - 10 - 6 ) . "\n%%EOF";
	$exact_pdf = '%PDF-1.4' . "\n%" . str_repeat( 'A', $limit - 10 - 6 ) . "\n%%EOF";

	// Which gate refuses "junk before %PDF-" depends on the libmagic data PHP's
	// fileinfo ships with: some builds are undecided (octet-stream), so WordPress
	// lets it through and PlanDose's own content gate refuses it; others read the
	// "MZ" prefix as application/x-dosexec and WordPress refuses it first. Ask
	// WordPress (same PHP build as the server) which case this runner is.
	$junk_bytes = "MZ\x90\x00" . $pdf_bytes;
	$junk_tmp   = wp_tempnam( 'pdt1310-junk.pdf' );
	file_put_contents( $junk_tmp, $junk_bytes );
	$junk_wp    = wp_check_filetype_and_ext( $junk_tmp, 'invoice.pdf' );
	@unlink( $junk_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$junk_need  = ( 'application/pdf' === ( $junk_wp['type'] ?? '' ) ) ? 'Το περιεχόμενο του αρχείου δεν αντιστοιχεί' : 'Μη επιτρεπτός τύπος';

	$refusals = array(
		'size limit + 1 byte'                 => array( 'big.pdf', $big_pdf, 'application/pdf', 'όριο των 1MB' ),
		'disallowed extension .txt'           => array( 'invoice.txt', "plain text\n", 'text/plain', 'Μη επιτρεπτός τύπος' ),
		'disallowed extension .php'           => array( 'invoice.php', $php_bytes, 'application/x-php', 'Μη επιτρεπτός τύπος' ),
		'disallowed extension .html'          => array( 'invoice.html', '<html><body>x</body></html>', 'text/html', 'Μη επιτρεπτός τύπος' ),
		'double extension x.php.pdf'          => array( 'x.php.pdf', $pdf_bytes, 'application/pdf', 'διπλή επέκταση' ),
		'double extension a.phtml.jpg'        => array( 'a.phtml.jpg', $jpeg_bytes, 'image/jpeg', 'διπλή επέκταση' ),
		'double extension report.html.pdf'    => array( 'report.html.pdf', $pdf_bytes, 'application/pdf', 'διπλή επέκταση' ),
		// Refused by WordPress's own type check already (fileinfo says text/x-php).
		'PHP text named .pdf'                 => array( 'invoice.pdf', $php_bytes, 'application/pdf', 'Μη επιτρεπτός τύπος' ),
		// Refused either by wp_check_filetype_and_ext() or, when fileinfo is
		// undecided, by PlanDose's own content gate (see $junk_need above).
		'junk before the %PDF- header'        => array( 'invoice.pdf', $junk_bytes, 'application/pdf', $junk_need ),
		'a PNG named .jpg'                    => array( 'photo.jpg', $png_bytes, 'image/jpeg', 'επέκταση του ονόματος' ),
		'a GIF (not an allowed type) as .png' => array( 'x.png', "GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00!\xf9\x04\x00\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;", 'image/png', '' ),
		'an empty file'                       => array( 'empty.pdf', '', 'application/pdf', 'κενό' ),
	);
	$before_list  = (array) $row_list( $ph );
	$before_files = $dir_listing();
	$before_audit = pdt1310_audit_count( 'invoice_upload', $ph );
	foreach ( $refusals as $label => $r ) {
		list( $name, $bytes, $ctype, $needle ) = $r;
		list( $code, $loc, $err ) = $http_upload( $name, $bytes, $ph, $ctype );
		pdt_check( 302 === $code && $referer === $loc, "3c: $label → 302 back to the screen" );
		pdt_check( is_string( $err ) && '' !== $err && ( '' === $needle || false !== strpos( $err, $needle ) ), "3c: $label → refused «{$needle}»: " . var_export( $err, true ) );
	}
	pdt_same( $before_list, (array) $row_list( $ph ), '3c: … none of them listed' );
	pdt_same( $before_files, $dir_listing(), '3c: … none of them written into the invoice folder' );
	pdt_same( $before_audit, pdt1310_audit_count( 'invoice_upload', $ph ), '3c: … and none audited as an upload' );

	// Exactly at the limit is fine.
	list( $code, , $err ) = $http_upload( 'exact.pdf', $exact_pdf, $ph, 'application/pdf' );
	$list = (array) $row_list( $ph );
	pdt_check( 302 === $code && null === $err && count( $list ) === count( $before_list ) + 1, '3c: exactly invoice_max_mb (1 MB) → accepted' );
	update_option( 'plandose_settings', $settings );

	// JPEG and WebP pass too (the other allowed types).
	list( , , $err_j ) = $http_upload( 'photo.jpeg', $jpeg_bytes, $ph, 'image/jpeg' );
	list( , , $err_w ) = $http_upload( 'photo.webp', $webp_bytes, $ph, 'image/webp' );
	$list = (array) $row_list( $ph );
	$last = array_slice( $list, -2 );
	pdt_check( null === $err_j && 2 === count( $last ) && 1 === preg_match( '/^[A-Za-z0-9]{32}\.jpe?g$/', (string) $last[0] ), '3c: a real JPEG → stored as .jpg/.jpeg: ' . wp_json_encode( $last ) );
	$webp_ok = in_array( 'image/webp', Plandose_Settings::allowed_invoice_mimes(), true );
	pdt_check( ! $webp_ok || ( null === $err_w && 1 === preg_match( '/^[A-Za-z0-9]{32}\.webp$/', (string) $last[1] ) ), '3c: a real WebP → stored as .webp (when WebP is allowed): ' . var_export( $err_w, true ) );

	// ---- 3d. HTTP refusals: another pharmacy, bad nonce ------------------------------

	$count_before = count( (array) $row_list( $ph ) );
	list( $code, , $err ) = $http_upload( 'a.pdf', $pdf_bytes, $ph, 'application/pdf', 'deadbeef00' );
	pdt_check( 403 === $code && null === $err, '3d: bad nonce over HTTP → 403, nothing stored' );
	list( $code, , $err ) = $http_upload( 'a.pdf', $pdf_bytes, $not_ph, 'application/pdf' );
	pdt_check( 302 === $code && is_string( $err ) && false !== strpos( $err, 'Φαρμακείο' ), '3d: a non-pharmacy account over HTTP → refused' );
	pdt_same( $count_before, count( (array) $row_list( $ph ) ), '3d: … pharmacy list unchanged' );
	pdt_same( null, $row_list( $not_ph ), '3d: … and no row created for the non-pharmacy' );

	// ---- 3e. storage refused: a PUBLIC verdict blocks the upload, nothing written ----

	$state_public = get_option( Plandose_Invoice_Storage::PRIVACY_OPTION );
	$state_public['status'] = 'public';
	$state_public['reason'] = 'http_served';
	update_option( Plandose_Invoice_Storage::PRIVACY_OPTION, $state_public, false );
	// The server re-probes a cached refusal live; the built-in server
	// really serves the folder, so the re-probe finds it public too.
	$before_files = $dir_listing();
	list( $code, , $err ) = $http_upload( 'a.pdf', $pdf_bytes, $ph, 'application/pdf' );
	pdt_check( 302 === $code && is_string( $err ) && false !== strpos( $err, 'ανοιχτός στο internet' ), '3e: public invoice folder → upload refused: ' . var_export( $err, true ) );
	pdt_same( $count_before, count( (array) $row_list( $ph ) ), '3e: … nothing listed' );
	pdt_same( $before_files, $dir_listing(), '3e: … nothing (not even a canary) left in the folder' );
	pdt_same( 1, pdt1310_audit_count( 'invoice_upload_refused_storage', $ph ), '3e: … refusal audited' );

	WP_Session_Tokens::get_instance( $admin )->destroy_all();
	wp_set_current_user( 0 );
}

// =================================================================================
// 4. handle_delete_invoice()
// =================================================================================

$fp = static function ( $entry ) {
	return pdt_call( 'Plandose_Admin_Invoices', 'entry_fingerprint', $entry );
};
$new_name = static function ( $ext = 'pdf' ) {
	return 'pd1310-' . strtolower( wp_generate_password( 20, false, false ) ) . '.' . $ext;
};
$delete = static function () {
	Plandose_Admin_Invoices::handle_delete_invoice();
};

$ph_d = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$audited_targets[] = $ph_d;
Plandose_Subscriptions::get_row( $ph_d );
$d_a = $new_name();
$d_b = $new_name( 'png' );
$d_c = $new_name();
file_put_contents( $dir . '/' . $d_a, $pdf_bytes );
file_put_contents( $dir . '/' . $d_b, $png_bytes );
file_put_contents( $dir . '/' . $d_c, $pdf_bytes );
Plandose_Subscriptions::update( $ph_d, array( 'invoices' => wp_json_encode( array( $d_a, $d_b, $d_c ) ) ) );

$del_post = static function ( $uid, $i, $f, $nonce_uid = null ) {
	pdt1310_post(
		array(
			'user_id'  => (string) $uid,
			'i'        => (string) $i,
			'f'        => $f,
			'_wpnonce' => wp_create_nonce( 'plandose_delete_invoice_' . ( null === $nonce_uid ? $uid : $nonce_uid ) ),
		)
	);
};

wp_set_current_user( $admin );
$del_post( $ph_d, 0, $fp( $d_a ) );
$_SERVER['REQUEST_METHOD'] = 'GET';
pdt_check( 0 === strpos( pdt1310_run( $delete ), 'die:405:' ), '4: GET → 405' );

wp_set_current_user( $ph_d );
$del_post( $ph_d, 0, $fp( $d_a ) );
pdt_check( 0 === strpos( pdt1310_run( $delete ), 'die:403:' ), '4: the pharmacy itself (no capability) → 403' );

wp_set_current_user( $admin );
$del_post( $ph_d, 0, $fp( $d_a ), $not_ph );
pdt_check( 0 === strpos( pdt1310_run( $delete ), 'die:403:' ), '4: nonce bound to another pharmacy → 403' );

add_filter( 'plandose_manage_capability', $custom_cap );
wp_set_current_user( $manager );
$del_post( $admin2, 0, 'x' );
pdt_check( 0 === strpos( pdt1310_run( $delete ), 'die:403:Δεν έχετε δικαίωμα να επεξεργαστείτε' ), '4: non-admin manager deleting an administrator\'s invoice → 403 (can_manage_account)' );
remove_filter( 'plandose_manage_capability', $custom_cap );

wp_set_current_user( $admin );
$bad = array(
	'fingerprint of another entry' => array( 0, $fp( $d_b ) ),
	'wrong fingerprint'            => array( 0, '0123456789abcdef' ),
	'empty fingerprint'            => array( 0, '' ),
	'index out of range'           => array( 7, $fp( $d_a ) ),
	'negative index'               => array( '-1', $fp( $d_a ) ),
	'index not digits'             => array( '0x', $fp( $d_a ) ),
);
foreach ( $bad as $label => $b ) {
	pdt1310_clear_transients( $admin );
	$del_post( $ph_d, $b[0], $b[1] );
	$out = pdt1310_run( $delete );
	pdt_check( 0 === strpos( $out, 'redirect:' ), "4: $label → redirect back" );
	pdt_check( false !== strpos( (string) get_transient( 'plandose_invoice_error_' . $admin ), 'δεν βρέθηκε' ), "4: $label → «not found» error" );
}
pdt_same( array( $d_a, $d_b, $d_c ), $row_list( $ph_d ), '4: … list untouched by every refused delete' );
clearstatcache();
pdt_check( is_file( $dir . '/' . $d_a ) && is_file( $dir . '/' . $d_b ) && is_file( $dir . '/' . $d_c ), '4: … and every file still there' );
pdt_same( 0, pdt1310_audit_count( 'invoice_delete', $ph_d ), '4: … and nothing audited' );

// A failed DB write: the file stays (the list still names it).
$fail_update = static function ( $q ) use ( $table ) {
	return ( 0 === strpos( ltrim( $q ), 'UPDATE `' . $table . '`' ) ) ? 'UPDATE `pd1310_no_such_table` SET x = 1' : $q;
};
pdt1310_clear_transients( $admin );
$del_post( $ph_d, 1, $fp( $d_b ) );
$wpdb->suppress_errors( true );
add_filter( 'query', $fail_update );
$out = pdt1310_run( $delete );
remove_filter( 'query', $fail_update );
$wpdb->suppress_errors( false );
clearstatcache();
pdt_check( 0 === strpos( $out, 'redirect:' ) && false !== strpos( (string) get_transient( 'plandose_invoice_error_' . $admin ), 'βάσης δεδομένων' ), '4: DB write fails → database error reported' );
pdt_check( is_file( $dir . '/' . $d_b ), '4: … and the file is NOT deleted' );
pdt_same( array( $d_a, $d_b, $d_c ), $row_list( $ph_d ), '4: … list unchanged' );

// Success: the list is written while the file still exists, then the file goes.
$file_at_update = null;
$watch          = static function ( $q ) use ( $table, $dir, $d_b, &$file_at_update ) {
	if ( null === $file_at_update && 0 === strpos( ltrim( $q ), 'UPDATE `' . $table . '`' ) ) {
		clearstatcache();
		$file_at_update = is_file( $dir . '/' . $d_b );
	}
	return $q;
};
pdt1310_clear_transients( $admin );
$del_post( $ph_d, 1, $fp( $d_b ) );
$_SERVER['HTTP_REFERER'] = admin_url( 'admin.php?page=plandose-subscriptions&pd1310=del' );
add_filter( 'query', $watch );
$out = pdt1310_run( $delete );
remove_filter( 'query', $watch );
clearstatcache();
pdt_same( 'redirect:' . admin_url( 'admin.php?page=plandose-subscriptions&pd1310=del' ), $out, '4: matching index + fingerprint → redirect back to the referer' );
pdt_same( false, get_transient( 'plandose_invoice_error_' . $admin ), '4: … no error' );
pdt_same( array( $d_a, $d_c ), $row_list( $ph_d ), '4: … the entry is removed from the list, the rest kept in order' );
pdt_same( true, $file_at_update, '4: … the list was written while the file still existed (DB first)' );
pdt_check( ! file_exists( $dir . '/' . $d_b ), '4: … then the file is deleted' );
pdt_check( is_file( $dir . '/' . $d_a ) && is_file( $dir . '/' . $d_c ), '4: … other files untouched' );
$a    = pdt1310_audit( 'invoice_delete', $ph_d );
$meta = $a ? json_decode( (string) $a->meta, true ) : null;
pdt_check( is_array( $meta ) && $d_b === $meta['entry'] && 1 === (int) $meta['index'] && 2 === (int) $meta['remain'] && (int) $a->admin_user_id === $admin, '4: … audited (entry, index, remaining count, admin)' );

// A stale page: index 1 now holds $d_c, the old fingerprint no longer matches.
pdt1310_clear_transients( $admin );
$del_post( $ph_d, 1, $fp( $d_b ) );
pdt1310_run( $delete );
pdt_same( array( $d_a, $d_c ), $row_list( $ph_d ), '4: the old (index, fingerprint) of a deleted entry does not remove what moved into the slot' );

// The same file listed twice: removing one entry keeps the file.
Plandose_Subscriptions::update( $ph_d, array( 'invoices' => wp_json_encode( array( $d_a, $d_c, $d_a ) ) ) );
$del_post( $ph_d, 2, $fp( $d_a ) );
pdt1310_run( $delete );
clearstatcache();
pdt_same( array( $d_a, $d_c ), $row_list( $ph_d ), '4: duplicate entry → only that entry removed' );
pdt_check( is_file( $dir . '/' . $d_a ), '4: … and the file kept while the list still names it' );

// A traversal entry hand-edited into a row is dropped from the list but
// never used as a path.
$outside = $tmp . '/victim.pdf';
file_put_contents( $outside, 'victim' );
$trav = '../../../../../../..' . $outside;
$wpdb->update( $table, array( 'invoices' => wp_json_encode( array( $d_a, $trav ) ) ), array( 'user_id' => $ph_d ) );
$listed = $row_list( $ph_d );
if ( 2 === count( $listed ) ) {
	$del_post( $ph_d, 1, $fp( $listed[1] ) );
	pdt1310_run( $delete );
	clearstatcache();
	pdt_check( is_file( $outside ), '4: deleting a traversal entry never deletes a file outside the folder' );
} else {
	pdt_check( ! in_array( $trav, $listed, true ), '4: a traversal entry is not even decoded into the list' );
	pdt_check( is_file( $outside ), '4: … and the outside file is untouched' );
}
pdt1310_post( array() );

// =================================================================================
// 5. handle_delete_deleted_account_invoices()
// =================================================================================

$del_gone = static function () {
	Plandose_Admin_Invoices::handle_delete_deleted_account_invoices();
};
$gone_post = static function ( $uid ) {
	pdt1310_post( array( 'user_id' => (string) $uid, '_wpnonce' => wp_create_nonce( 'plandose_delete_deleted_account_invoices_' . $uid ) ) );
};

$ph_g = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$audited_targets[] = $ph_g;
Plandose_Subscriptions::get_row( $ph_g );
$g_a = $new_name();
$g_b = $new_name( 'png' );
file_put_contents( $dir . '/' . $g_a, $pdf_bytes );
file_put_contents( $dir . '/' . $g_b, $png_bytes );
Plandose_Subscriptions::update( $ph_g, array( 'invoices' => wp_json_encode( array( $g_a, $g_b ) ) ) );
$back = admin_url( 'admin.php?page=plandose-subscriptions' );

// Account still exists → refused, whatever the caller.
wp_set_current_user( $admin );
$gone_post( $ph_g );
pdt_same( 'redirect:' . add_query_arg( 'plandose_result', 'invalid_user', $back ), pdt1310_run( $del_gone ), '5: an existing account → invalid_user, nothing deleted' );
pdt_same( array( $g_a, $g_b ), $row_list( $ph_g ), '5: … its list intact' );

wp_delete_user( $ph_g );
clean_user_cache( $ph_g );
pdt_same( array( $g_a, $g_b ), $row_list( $ph_g ), '5: (setup) the deleted account keeps its retired row with the invoices' );

$gone_post( $ph_g );
$_SERVER['REQUEST_METHOD'] = 'GET';
pdt_check( 0 === strpos( pdt1310_run( $del_gone ), 'die:405:' ), '5: GET → 405' );

// PlanDose capability but no delete_users → refused.
add_filter( 'plandose_manage_capability', $custom_cap );
wp_set_current_user( $manager );
pdt_check( current_user_can( 'pd1310_manage' ) && ! current_user_can( 'delete_users' ), '5: (setup) manager has the PlanDose capability, not delete_users' );
$gone_post( $ph_g );
pdt_check( 0 === strpos( pdt1310_run( $del_gone ), 'die:403:Δεν έχετε δικαίωμα πρόσβασης' ), '5: without delete_users → 403' );
remove_filter( 'plandose_manage_capability', $custom_cap );

// delete_users alone, without the PlanDose capability → refused too.
wp_set_current_user( $manager );
( new WP_User( $manager ) )->add_cap( 'delete_users' );
wp_set_current_user( $manager );
$gone_post( $ph_g );
pdt_check( 0 === strpos( pdt1310_run( $del_gone ), 'die:403:' ), '5: delete_users without the PlanDose capability → 403' );
( new WP_User( $manager ) )->remove_cap( 'delete_users' );

wp_set_current_user( $admin );
pdt1310_post( array( 'user_id' => (string) $ph_g, '_wpnonce' => wp_create_nonce( 'plandose_delete_deleted_account_invoices_' . $ph ) ) );
pdt_check( 0 === strpos( pdt1310_run( $del_gone ), 'die:403:' ), '5: nonce bound to another account → 403' );
pdt_same( array( $g_a, $g_b ), $row_list( $ph_g ), '5: … nothing deleted by any refusal' );
clearstatcache();
pdt_check( is_file( $dir . '/' . $g_a ) && is_file( $dir . '/' . $g_b ), '5: … files intact' );

// A failed row delete keeps the files.
$fail_delete = static function ( $q ) use ( $table ) {
	return ( 0 === strpos( ltrim( $q ), 'DELETE FROM `' . $table . '`' ) ) ? 'DELETE FROM `pd1310_no_such_table`' : $q;
};
pdt1310_clear_transients( $admin );
$gone_post( $ph_g );
$wpdb->suppress_errors( true );
add_filter( 'query', $fail_delete );
$out = pdt1310_run( $del_gone );
remove_filter( 'query', $fail_delete );
$wpdb->suppress_errors( false );
clearstatcache();
pdt_check( 0 === strpos( $out, 'redirect:' ) && false !== strpos( (string) get_transient( 'plandose_invoice_error_' . $admin ), 'βάσης δεδομένων' ), '5: row delete fails → database error reported' );
pdt_check( is_file( $dir . '/' . $g_a ) && is_file( $dir . '/' . $g_b ), '5: … and the files are kept' );
pdt_same( array( $g_a, $g_b ), $row_list( $ph_g ), '5: … with the row that lists them' );

// Success: row first, then the files.
$file_at_delete = null;
$watch_del      = static function ( $q ) use ( $table, $dir, $g_a, &$file_at_delete ) {
	if ( null === $file_at_delete && 0 === strpos( ltrim( $q ), 'DELETE FROM `' . $table . '`' ) ) {
		clearstatcache();
		$file_at_delete = is_file( $dir . '/' . $g_a );
	}
	return $q;
};
pdt1310_clear_transients( $admin );
$gone_post( $ph_g );
add_filter( 'query', $watch_del );
$out = pdt1310_run( $del_gone );
remove_filter( 'query', $watch_del );
clearstatcache();
pdt_same( 'redirect:' . add_query_arg( 'plandose_result', 'invoices_removed', $back ), $out, '5: administrator, account gone → invoices_removed' );
pdt_same( null, $row_list( $ph_g ), '5: … the retired row is deleted' );
pdt_same( true, $file_at_delete, '5: … while the files still existed (row first)' );
pdt_check( ! file_exists( $dir . '/' . $g_a ) && ! file_exists( $dir . '/' . $g_b ), '5: … then both files are deleted' );
$a = pdt1310_audit( 'deleted_account_invoices_removed', $ph_g );
pdt_check( $a && 2 === (int) json_decode( (string) $a->meta, true )['invoices'], '5: … audited with the number of invoices' );

$gone_post( $ph_g );
pdt_same( 'redirect:' . add_query_arg( 'plandose_result', 'invalid_user', $back ), pdt1310_run( $del_gone ), '5: a second submit (nothing left) → invalid_user' );
$gone_post( 0 );
pdt_same( 'redirect:' . add_query_arg( 'plandose_result', 'invalid_user', $back ), pdt1310_run( $del_gone ), '5: user_id 0 → invalid_user' );
pdt1310_post( array() );
wp_set_current_user( 0 );

// =================================================================================
// 6. Plandose_Invoice_Storage: content sniffing
// =================================================================================

$sniff = static function ( $path ) {
	return pdt_call( 'Plandose_Invoice_Storage', 'sniff_real_mime', $path );
};

if ( function_exists( 'finfo_open' ) ) {
	pdt_same( 'application/pdf', $sniff( $f_pdf ), '6: sniff_real_mime: %PDF- header → application/pdf' );
	pdt_same( 'image/png', $sniff( $f_png ), '6: sniff_real_mime: PNG magic → image/png' );
	pdt_same( 'image/jpeg', $sniff( $f_jpeg ), '6: sniff_real_mime: JPEG magic → image/jpeg' );
	pdt_same( 'image/webp', $sniff( $f_webp ), '6: sniff_real_mime: RIFF/WEBP → image/webp' );
	pdt_check( ! in_array( $sniff( $f_php ), Plandose_Invoice_Storage::ALLOWED_INVOICE_MIMES, true ), '6: sniff_real_mime: PHP source named .pdf → not an invoice type (' . $sniff( $f_php ) . ')' );
	pdt_check( ! in_array( $sniff( $f_gif_php ), Plandose_Invoice_Storage::ALLOWED_INVOICE_MIMES, true ), '6: sniff_real_mime: GIF/PHP polyglot → not an invoice type (' . $sniff( $f_gif_php ) . ')' );
	pdt_check( ! in_array( $sniff( $f_garbage ), Plandose_Invoice_Storage::ALLOWED_INVOICE_MIMES, true ), '6: sniff_real_mime: random bytes → not an invoice type (' . $sniff( $f_garbage ) . ')' );
	pdt_check( ! in_array( $sniff( $f_empty ), Plandose_Invoice_Storage::ALLOWED_INVOICE_MIMES, true ), '6: sniff_real_mime: empty file → not an invoice type' );
}
pdt_same( '', $sniff( $tmp . '/missing.pdf' ), "6: sniff_real_mime: missing file → '' (no warning)" );
pdt_same( '', $sniff( $tmp ), "6: sniff_real_mime: a directory → ''" );

pdt_check( Plandose_Invoice_Storage::content_matches_mime( $f_pdf, 'application/pdf' ), '6: content_matches_mime: real PDF as application/pdf' );
pdt_check( Plandose_Invoice_Storage::content_matches_mime( $f_png, 'image/png' ), '6: content_matches_mime: real PNG as image/png' );
pdt_check( Plandose_Invoice_Storage::content_matches_mime( $f_jpeg, 'image/jpeg' ), '6: content_matches_mime: real JPEG as image/jpeg' );
pdt_check( Plandose_Invoice_Storage::content_matches_mime( $f_webp, 'image/webp' ), '6: content_matches_mime: real WebP as image/webp' );
pdt_check( ! Plandose_Invoice_Storage::content_matches_mime( $f_php, 'application/pdf' ), '6: content_matches_mime: PHP text claimed as PDF → false' );
pdt_check( ! Plandose_Invoice_Storage::content_matches_mime( $f_png, 'image/jpeg' ), '6: content_matches_mime: PNG claimed as JPEG → false' );
pdt_check( ! Plandose_Invoice_Storage::content_matches_mime( $f_pdf, 'image/png' ), '6: content_matches_mime: PDF claimed as PNG → false' );
pdt_check( ! Plandose_Invoice_Storage::content_matches_mime( $f_gif_php, 'image/png' ), '6: content_matches_mime: GIF/PHP polyglot claimed as PNG → false' );
pdt_check( ! Plandose_Invoice_Storage::content_matches_mime( $f_garbage, 'application/pdf' ), '6: content_matches_mime: random bytes as PDF → false' );
pdt_check( ! Plandose_Invoice_Storage::content_matches_mime( $f_empty, 'application/pdf' ), '6: content_matches_mime: empty file → false' );
pdt_check( ! Plandose_Invoice_Storage::content_matches_mime( $tmp . '/missing.pdf', 'application/pdf' ), '6: content_matches_mime: missing file → false (fail closed)' );

// The decision for a given fileinfo answer (fileinfo missing / undecided).
$sniffed = static function ( $path, $mime, $s ) {
	return Plandose_Invoice_Storage::content_matches_mime_sniffed( $path, $mime, $s );
};
pdt_same( true, $sniffed( $f_pdf, 'application/pdf', '' ), "6: sniffed '' (no fileinfo) + %PDF- header → true" );
pdt_same( false, $sniffed( $f_junkpdf, 'application/pdf', '' ), "6: sniffed '' + junk before %PDF- → false (the header must be at byte 0)" );
pdt_same( false, $sniffed( $f_php, 'application/pdf', 'application/octet-stream' ), '6: octet-stream + PHP text as PDF → false' );
pdt_same( false, $sniffed( $f_empty, 'application/pdf', '' ), "6: sniffed '' + empty file → false" );
pdt_same( true, $sniffed( $f_webp, 'image/webp', 'application/octet-stream' ), '6: octet-stream + real WebP → getimagesize decides → true' );
pdt_same( true, $sniffed( $f_jpeg, 'image/jpeg', '' ), "6: sniffed '' + real JPEG → true" );
pdt_same( false, $sniffed( $f_png, 'image/jpeg', '' ), "6: sniffed '' + PNG claimed as JPEG → false" );
pdt_same( false, $sniffed( $f_gif_php, 'image/png', '' ), "6: sniffed '' + GIF polyglot claimed as PNG → false" );
pdt_same( false, $sniffed( $f_garbage, 'image/png', 'application/octet-stream' ), '6: octet-stream + random bytes as PNG → false' );
pdt_same( false, $sniffed( $tmp . '/missing.png', 'image/png', '' ), "6: sniffed '' + missing image → false" );
pdt_same( true, $sniffed( $f_php, 'application/pdf', ' APPLICATION/PDF ' ), '6: a definite fileinfo answer is compared case-insensitively and trimmed' );
pdt_same( false, $sniffed( $f_pdf, 'application/pdf', 'text/x-php' ), '6: a definite other fileinfo type wins over a valid header' );
pdt_same( false, $sniffed( $f_pdf, 'application/pdf', 'application/x-empty' ), '6: application/x-empty is a definite (wrong) answer' );

// =================================================================================
// 7. invoice_path(): only a clean bare name inside the folder
// =================================================================================

$readable = Plandose_Invoice_Storage::readable_invoice_dir();
$ip       = static function ( $entry ) {
	$r = Plandose_Invoice_Storage::invoice_path( $entry );
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
};
$good = array( 'Ab9xYz0123456789abcdef0123456789.pdf', 'scan.png', 'τιμολόγιο.pdf', 'a.b.c.pdf', 'x-1.jpeg', 12345 );
foreach ( $good as $name ) {
	pdt_same( trailingslashit( $readable ) . $name, $ip( $name ), "7: invoice_path( '$name' ) → inside the invoice folder" );
}
$bad = array(
	'../a.pdf', '../../wp-config.php', '..', '.', '', '/etc/passwd', ABSPATH . 'wp-config.php', 'sub/a.pdf',
	"a\0.pdf", "a.pdf\0.php", 'a\\b.pdf', '..\\..\\a.pdf', 'C:\\a.pdf', '.htaccess', '.HTACCESS', 'web.config',
	'index.php', 'Index.HTML', '.plandose-invoice-storage', 'a b.pdf', 'x.php.pdf', ' a.pdf', 'a.pdf ', "a\n.pdf",
	'%2e%2e%2fa.pdf', '.pdf',
);
foreach ( $bad as $name ) {
	pdt_same( 'plandose_invoice_bad_filename', $ip( $name ), '7: invoice_path( ' . wp_json_encode( $name ) . ' ) refused' );
}

// delete_invoice_files() applies the same rule: nothing outside the folder,
// never a protection file.
$victim = $tmp . '/victim2.pdf';
file_put_contents( $victim, 'victim' );
$keep_rel = $new_name();
file_put_contents( $dir . '/' . $keep_rel, 'x' );
Plandose_Invoice_Storage::delete_invoice_files( array( '../../../../../../../..' . $victim, $victim, '.htaccess', 'index.php', 42, '', null, 'sub/' . $keep_rel ), $dir );
clearstatcache();
pdt_check( is_file( $victim ), '7: delete_invoice_files ignores traversal and absolute entries' );
pdt_check( is_file( $dir . '/.htaccess' ) && is_file( $dir . '/index.php' ), '7: … and never deletes a protection file' );
pdt_check( is_file( $dir . '/' . $keep_rel ), "7: … nor a folder file named through 'sub/…'" );
Plandose_Invoice_Storage::delete_invoice_files( array( $keep_rel ), $dir );
clearstatcache();
pdt_check( ! file_exists( $dir . '/' . $keep_rel ), '7: … while a clean listed name is deleted' );

// =================================================================================
// 8. resolve_path() / path_is_in_public_tree() / invoice_dir_is_public()
// =================================================================================

$resolve = static function ( $p ) {
	return pdt_call( 'Plandose_Invoice_Storage', 'resolve_path', $p );
};
$uploads   = wp_get_upload_dir();
$up_base   = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
$real_tmp  = untrailingslashit( wp_normalize_path( realpath( $tmp ) ) );
$abs_real  = untrailingslashit( wp_normalize_path( realpath( ABSPATH ) ) );

pdt_same( $real_tmp, $resolve( $tmp ), '8: resolve_path: existing folder → its realpath' );
pdt_same( $real_tmp . '/new/deeper', $resolve( $tmp . '/new/deeper' ), '8: resolve_path: missing tail re-appended to the deepest existing ancestor' );
pdt_same( $real_tmp . '/x', $resolve( $tmp . '/./x/' ), "8: resolve_path: './' and a trailing slash are resolved away" );
pdt_same( $real_tmp, $resolve( $tmp . '/../' . basename( $tmp ) ), "8: resolve_path: an existing '..' is resolved" );
pdt_same( '', $resolve( '' ), "8: resolve_path( '' ) → ''" );
pdt_same( $real_tmp . '/x', $resolve( str_replace( '/', '\\', $tmp ) . '\\x' ), '8: resolve_path: backslashes are normalised to slashes' );

$can_link = function_exists( 'symlink' );
if ( $can_link ) {
	$link = $tmp . '/link-to-uploads';
	$ok   = @symlink( $uploads['basedir'], $link ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be refused by the platform.
	if ( $ok ) {
		pdt_same( $up_base . '/pd1310-new/inv', $resolve( $link . '/pd1310-new/inv' ), '8: resolve_path: a symlinked parent is followed for a folder not created yet' );
		pdt_check( Plandose_Invoice_Storage::path_is_in_public_tree( $link . '/pd1310-new/inv' ), '8: path_is_in_public_tree: outside path whose parent is a symlink into uploads → public' );
		pdt_check( Plandose_Invoice_Storage::path_is_in_public_tree( $link ), '8: path_is_in_public_tree: the symlink itself → public' );

		// And the other direction: a symlink INSIDE the invoice folder pointing out.
		$in_dir_link = $dir . '/pd1310-link-' . strtolower( wp_generate_password( 8, false, false ) );
		if ( @symlink( $tmp, $in_dir_link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be refused.
			pdt_check( Plandose_Invoice_Storage::path_is_in_public_tree( $in_dir_link ), '8: path_is_in_public_tree: a link placed under uploads counts as public as written' );
			pdt_same( 'plandose_invoice_bad_filename', $ip( basename( $in_dir_link ) . '/victim2.pdf' ), '8: invoice_path never walks through a link in the folder' );
			$victim3 = $tmp . '/victim3.pdf';
			file_put_contents( $victim3, 'v' );
			Plandose_Invoice_Storage::delete_invoice_files( array( basename( $in_dir_link ) . '/victim3.pdf' ), $dir );
			clearstatcache();
			pdt_check( is_file( $victim3 ), '8: … nor does delete_invoice_files' );
			unlink( $in_dir_link );
		}
	} else {
		echo "SKIP   8: symlink() refused on this platform\n";
	}
}

pdt_check( Plandose_Invoice_Storage::path_is_in_public_tree( ABSPATH ), '8: path_is_in_public_tree( ABSPATH ) → public' );
pdt_check( Plandose_Invoice_Storage::path_is_in_public_tree( $up_base . '/plandose-invoices' ), '8: … the default invoice folder → public' );
pdt_check( Plandose_Invoice_Storage::path_is_in_public_tree( $up_base . '/not/created/yet' ), '8: … a missing folder under uploads → public' );
pdt_check( Plandose_Invoice_Storage::path_is_in_public_tree( WP_CONTENT_DIR . '/private' ), '8: … under wp-content → public' );
pdt_check( Plandose_Invoice_Storage::path_is_in_public_tree( str_replace( '/', '\\', $up_base ) . '\\inv' ), '8: … written with backslashes → public' );
pdt_check( Plandose_Invoice_Storage::path_is_in_public_tree( $up_base . '/' ), '8: … with a trailing slash → public' );
pdt_check( Plandose_Invoice_Storage::path_is_in_public_tree( $abs_real . '/wp-content/../wp-admin' ), "8: … an existing '..' that stays inside → public" );
// Conservative: the path AS WRITTEN starts under the web root, so it counts
// as public even though '..' climbs out of it.
pdt_check( Plandose_Invoice_Storage::path_is_in_public_tree( $up_base . '/../../../pd1310-outside-' . wp_generate_password( 6, false, false ) ), "8: … a written path under the web root whose '..' climbs out → still public (fail safe)" );
pdt_same( dirname( $abs_real ) . '/pd1310-x', $resolve( $up_base . '/../../../pd1310-x' ), "8: resolve_path: '..' over existing ancestors is resolved" );
pdt_check( ! Plandose_Invoice_Storage::path_is_in_public_tree( '' ), "8: … '' → false" );
pdt_check( ! Plandose_Invoice_Storage::path_is_in_public_tree( $tmp . '/private/invoices' ), '8: … a folder in the system temp dir → not public' );
pdt_check( ! Plandose_Invoice_Storage::path_is_in_public_tree( untrailingslashit( ABSPATH ) . '-sibling/inv' ), '8: … a sibling whose name starts with the web root → not public (segment match, not prefix)' );
pdt_check( ! Plandose_Invoice_Storage::path_is_in_public_tree( dirname( untrailingslashit( ABSPATH ) ) . '/pd1310-private' ), '8: … next to the web root (dirname( ABSPATH ) is not a root) → not public' );

// DOCUMENT_ROOT counts as a public root too.
Plandose_Invoice_Storage::set_document_root_override( $tmp . '/docroot' );
pdt_check( Plandose_Invoice_Storage::path_is_in_public_tree( $tmp . '/docroot/inv' ), '8: … a folder under DOCUMENT_ROOT → public' );
Plandose_Invoice_Storage::set_document_root_override( null );
pdt_check( ! Plandose_Invoice_Storage::path_is_in_public_tree( $tmp . '/docroot/inv' ), '8: … and not once DOCUMENT_ROOT is elsewhere' );

pdt_same( false, Plandose_Invoice_Storage::using_custom_invoice_dir(), '8: (this site) no PLANDOSE_INVOICE_DIR' );
pdt_same( true, Plandose_Invoice_Storage::invoice_dir_is_public(), '8: invoice_dir_is_public(): default uploads subfolder → true' );

// The custom-folder branch needs PLANDOSE_INVOICE_DIR defined before
// WordPress loads: one child process per value.
$child_src = $tmp . '/child.php';
file_put_contents(
	$child_src,
	'<?php $_SERVER["HTTP_HOST"] = "127.0.0.1:8899"; define( "PLANDOSE_INVOICE_DIR", $argv[2] ); require $argv[1];'
	. ' echo "\nPD1310:" . wp_json_encode( array( "custom" => Plandose_Invoice_Storage::using_custom_invoice_dir(), "public" => Plandose_Invoice_Storage::invoice_dir_is_public() ) );'
);
$child = static function ( $value ) use ( $child_src ) {
	$wp_load = getenv( 'WP_LOAD' ) ? getenv( 'WP_LOAD' ) : '/home/claude/wpenv/wp-load.php';
	$out     = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $child_src ) . ' ' . escapeshellarg( $wp_load ) . ' ' . escapeshellarg( $value ) . ' 2>/dev/null' );
	$pos     = strrpos( $out, 'PD1310:' );
	return false === $pos ? array( 'raw' => substr( $out, -300 ) ) : json_decode( substr( $out, $pos + 7 ), true );
};
$custom_cases = array(
	'outside the web root'           => array( $tmp . '/private/plandose-invoices', false ),
	'under uploads'                  => array( $up_base . '/private-inv', true ),
	'under ABSPATH'                  => array( untrailingslashit( ABSPATH ) . '/private-inv', true ),
	'under wp-content, trailing /'   => array( WP_CONTENT_DIR . '/inv/', true ),
	'blank (not set)'                => array( '   ', true ),
);
if ( $can_link && is_link( $tmp . '/link-to-uploads' ) ) {
	$custom_cases['outside, via a symlink into uploads'] = array( $tmp . '/link-to-uploads/sub/inv', true );
}
foreach ( $custom_cases as $label => $c ) {
	$r = $child( $c[0] );
	pdt_check( is_array( $r ) && isset( $r['public'] ) && $c[1] === $r['public'], "8: invoice_dir_is_public() with PLANDOSE_INVOICE_DIR $label → " . var_export( $c[1], true ) . ': ' . wp_json_encode( $r ) );
}

// =================================================================================
// 9. is_canary_name()
// =================================================================================

$canary = array(
	'plandose-probe-' . str_repeat( 'a', 24 ) . '.pdf'                => true,
	'plandose-probe-' . str_repeat( 'Z', 12 ) . str_repeat( '9', 12 ) . '.webp' => true,
	'plandose-probe-control-' . str_repeat( 'b', 24 ) . '.pdf'        => true,
	'plandose-probe-' . str_repeat( 'c', 24 ) . '.jpeg'               => true,
	'plandose-probe-' . str_repeat( 'c', 24 ) . '.jpg'                => true,
	'plandose-probe-' . str_repeat( 'c', 24 ) . '.png'                => true,
	'plandose-probe-' . str_repeat( 'a', 23 ) . '.pdf'                => false,
	'plandose-probe-' . str_repeat( 'a', 25 ) . '.pdf'                => false,
	'plandose-probe-' . str_repeat( 'a', 24 ) . '.PDF'                => false,
	'plandose-probe-' . str_repeat( 'a', 24 ) . '.php'                => false,
	'plandose-probe-' . str_repeat( 'a', 24 ) . '.pdf.php'            => false,
	'plandose-probe-' . str_repeat( 'a', 23 ) . '-.pdf'               => false,
	'plandose-probe-other-' . str_repeat( 'a', 24 ) . '.pdf'          => false,
	'x-plandose-probe-' . str_repeat( 'a', 24 ) . '.pdf'              => false,
	'../plandose-probe-' . str_repeat( 'a', 24 ) . '.pdf'             => false,
	'plandose-probe-notes.txt'                                        => false,
	''                                                                => false,
);
foreach ( $canary as $name => $expected ) {
	pdt_same( $expected, Plandose_Invoice_Storage::is_canary_name( $name ), '9: is_canary_name( ' . wp_json_encode( (string) $name ) . ' )' );
}

pdt_done();
