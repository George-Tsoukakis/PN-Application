<?php
/**
 * 1.12.0: «Έλεγχος ευρετηρίου». Every published page and post of the
 * searched types with its state: read, missing, too little text, waiting
 * to be read again, excluded (password, settings); the buttons read the
 * missing ones now or all again in the background; PDFs of the Media
 * Library with their reading state.
 */
require __DIR__ . '/lib.php';
pnt_settings(
	array(
		'site_search'  => 1,
		'site_types'   => 'page',
		'site_exclude' => '',
		'ai_enabled'   => 0,
	)
);
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$made = array();
pnt_defer(
	function () use ( &$made ) {
		foreach ( $made as $id ) {
			wp_delete_post( $id, true );
		}
	}
);
$mk   = function ( $title, $content, $extra = array() ) use ( &$made ) {
	$id     = wp_insert_post(
		array_merge(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			),
			$extra
		)
	);
	$made[] = $id;
	PNChat_Site_Search::index_post( $id );
	return $id;
};
$long    = str_repeat( 'Οδηγίες για την εγγραφή στο εργαλείο και τη χρήση του. ', 10 );
$ok      = $mk( 'Ελεγχος OK PNT', '<p>' . $long . '</p>' );
$thin    = $mk( 'Ελεγχος Λίγο PNT', '<p>Δείτε τον πίνακα.</p>' );
$locked  = $mk( 'Ελεγχος Κωδικός PNT', '<p>' . $long . '</p>', array( 'post_password' => 'x' ) );
$missing = $mk( 'Ελεγχος Λείπει PNT', '<p>' . $long . '</p>' );
delete_post_meta( $missing, PNChat_Site_Search::META );
$old     = $mk( 'Ελεγχος Παλιά PNT', '<p>' . $long . '</p>' );
delete_post_meta( $old, PNChat_Site_Search::META_TEXT );
$excl    = $mk( 'Ελεγχος Εξαίρεση PNT', '<p>' . $long . '</p>' );
// Saved directly: the first pnt_settings() puts the settings back at the end.
PNChat_Settings::save( array_merge( PNChat_Settings::get(), array( 'site_exclude' => (string) $excl ) ) );
$draft   = $mk( 'Ελεγχος Πρόχειρο PNT', '<p>' . $long . '</p>', array( 'post_status' => 'draft' ) );

$state = array();
foreach ( PNChat_Site_Search::report() as $r ) {
	$state[ $r['id'] ] = $r;
}
pnt_same( 'ok', $state[ $ok ]['status'] ?? '', 'report: a read page is ok' );
pnt_check( ( $state[ $ok ]['chars'] ?? 0 ) > 300 && ( $state[ $ok ]['read_at'] ?? 0 ) > time() - 60, 'report: with its characters and when it was read' );
pnt_same( 'thin', $state[ $thin ]['status'] ?? '', 'report: a page with very little text is flagged' );
pnt_same( 'missing', $state[ $missing ]['status'] ?? '', 'report: a page not indexed is missing' );
pnt_same( 'old', $state[ $old ]['status'] ?? '', 'report: a page read before 1.11.0 waits to be read again' );
pnt_same( array( 'excluded', 'Έχει κωδικό' ), array( $state[ $locked ]['status'] ?? '', $state[ $locked ]['reason'] ?? '' ), 'report: a page with a password is excluded, with the reason' );
pnt_same( 'excluded', $state[ $excl ]['status'] ?? '', 'report: a page excluded in the settings' );
pnt_check( ! isset( $state[ $draft ] ), 'report: drafts are not listed' );

ob_start();
PNChat_Admin::page_index();
$out = ob_get_clean();
pnt_check( false !== strpos( $out, 'Έλεγχος ευρετηρίου' ) && false !== strpos( $out, 'λείπουν' ), 'screen: shows the summary' );
pnt_check( false !== strpos( $out, 'Ελεγχος Λείπει PNT' ) && false !== strpos( $out, 'Ελεγχος Λίγο PNT' ) && false !== strpos( $out, 'Έχει κωδικό' ), 'screen: lists the missing, thin and excluded pages' );
pnt_check( false !== strpos( $out, 'peek=' . $thin . '#pnchat-peek' ), 'screen: «Τι διαβάζει» opens the preview of the page' );
pnt_check( false !== strpos( $out, 'pnchat_index_now' ) && false !== strpos( $out, 'pnchat_index_refresh' ), 'screen: the two buttons' );
pnt_check( false !== strpos( $out, 'Το AI δεν είναι ενεργό' ), 'screen: PDFs say when the AI is off' );
pnt_check( false === strpos( $out, 'Ελεγχος Πρόχειρο PNT' ), 'screen: drafts are not shown' );

add_filter(
	'wp_redirect',
	function ( $to ) {
		throw new Exception( $to );
	}
);
$go = function ( $action, array $get = array() ) {
	$_GET                 = $get;
	$_REQUEST             = array_merge( $get, array( '_wpnonce' => wp_create_nonce( 'pnchat_' . $action ) ) );
	try {
		call_user_func( array( 'PNChat_Admin', 'handle_' . $action ) );
	} catch ( Exception $e ) {
		return $e->getMessage();
	}
	return '';
};
$to = $go( 'index_now' );
pnt_check( false !== strpos( $to, 'pnchat_msg=index_now' ), '«Διάβασε τώρα όσες λείπουν»: back with a message' );
pnt_check( '' !== (string) get_post_meta( $missing, PNChat_Site_Search::META, true ), '«Διάβασε τώρα όσες λείπουν»: the missing page is read' );
wp_clear_scheduled_hook( PNChat_Site_Search::REFRESH );
$to = $go( 'index_refresh' );
pnt_check( false !== strpos( $to, 'pnchat_msg=index_refresh' ) && wp_next_scheduled( PNChat_Site_Search::REFRESH ), '«Διάβασέ τα όλα ξανά»: scheduled in the background' );
wp_clear_scheduled_hook( PNChat_Site_Search::REFRESH );
delete_option( PNChat_Site_Search::REFRESH );
$to = $go( 'index_media', array( 'all' => '1' ) );
pnt_check( false !== strpos( $to, 'pnchat_msg=ai_off' ), 'PDFs: with the AI off nothing is queued' );
wp_set_current_user( 0 );
$_REQUEST = array( '_wpnonce' => 'x' );
add_filter(
	'wp_die_handler',
	function () {
		return function ( $message ) {
			throw new Exception( is_string( $message ) ? $message : 'die' );
		};
	}
);
try {
	PNChat_Admin::handle_index_now();
	pnt_check( false, 'guard: a visitor cannot run it' );
} catch ( Throwable $e ) {
	pnt_check( true, 'guard: a visitor cannot run it' );
}
$_GET     = array();
$_REQUEST = array();
pnt_done();
