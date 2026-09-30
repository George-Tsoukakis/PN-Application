<?php
/**
 * PlanDose — admin notices stay below the page header. TEST-ONLY.
 *
 * core's common.js moves every notice after `.wp-header-end`, or, when a
 * screen has none, after its first `.wrap h1` — which on the PlanDose
 * screens sits inside the flex header next to the logo. Every PlanDose
 * screen must therefore print <hr class="wp-header-end"> right after the
 * header, and the CSV button belongs to the header itself instead of being
 * pulled up over the notices with a negative margin.
 */

require __DIR__ . '/lib.php';

$pdt_admin = pdt_user( array(), 'administrator' );
wp_set_current_user( $pdt_admin );
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=plandose';

$screens = array(
	'Dashboard'   => array( 'Plandose_Admin_Subscriptions', 'render_dashboard_page' ),
	'Συνδρομές'   => array( 'Plandose_Admin_Subscriptions', 'render_subscriptions_page' ),
	'Δωρεάν'      => array( 'Plandose_Admin_Subscriptions', 'render_free_page' ),
	'Pro'         => array( 'Plandose_Admin_Subscriptions', 'render_pro_page' ),
	'Εκτυπώσεις'  => array( 'Plandose_Admin_Prints', 'render_page' ),
	'Ημερολόγιο'  => array( 'Plandose_Admin_Subscriptions', 'render_audit_page' ),
	'Settings'    => array( 'Plandose_Admin_Settings', 'render_settings_page' ),
	'Διαγνωστικά' => array( 'Plandose_Diagnostics', 'render_page' ),
);

/**
 * DOMNode::contains() is PHP 8.3+; walk the ancestors so PHP 8.0 works too.
 */
function pdt1300_node_contains( DOMNode $outer, DOMNode $inner ): bool {
	for ( $n = $inner; null !== $n; $n = $n->parentNode ) {
		if ( $n === $outer ) {
			return true;
		}
	}
	return false;
}

foreach ( $screens as $name => $callback ) {
	if ( ! is_callable( $callback ) ) {
		pdt_check( false, "$name: renderer exists" );
		continue;
	}

	ob_start();
	call_user_func( $callback );
	$html = (string) ob_get_clean();

	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
	libxml_clear_errors();
	$xp = new DOMXPath( $dom );

	$wrap = $xp->query( '//div[contains(concat(" ", normalize-space(@class), " "), " wrap ")]' )->item( 0 );
	$h1   = $wrap ? $xp->query( './/h1', $wrap )->item( 0 ) : null;
	$ends = $xp->query( '//hr[contains(concat(" ", normalize-space(@class), " "), " wp-header-end ")]' );
	$end  = $ends->length ? $ends->item( 0 ) : null;
	$head = $xp->query( '//div[contains(concat(" ", normalize-space(@class), " "), " plandose-admin-head ")]' )->item( 0 );

	pdt_same( 1, $ends->length, "$name: one <hr class=\"wp-header-end\">" );
	pdt_check( $end && $wrap && $end->parentNode === $wrap, "$name: … directly inside .wrap" );
	pdt_check( $end && $head && $head->parentNode === $wrap && $end->previousSibling && ( $end->previousSibling === $head || ( XML_TEXT_NODE === $end->previousSibling->nodeType && $end->previousSibling->previousSibling === $head ) ), "$name: … right after the header" );
	pdt_check( $h1 && $head && pdt1300_node_contains( $head, $h1 ), "$name: the title is in the header" );
}

// The CSV export sits in the header's action area, not in a floated box.
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=plandose-subscriptions';
ob_start();
Plandose_Admin_Subscriptions::render_subscriptions_page();
$html = (string) ob_get_clean();
$head_start = strpos( $html, 'class="plandose-admin-head"' );
$head_end   = strpos( $html, 'wp-header-end' );
$csv        = strpos( $html, 'value="plandose_export_csv"' );
pdt_check( false !== $csv && $csv > $head_start && $csv < $head_end, 'CSV export form is inside the header' );
pdt_check( false !== strpos( substr( $html, $head_start, $head_end - $head_start ), 'plandose-admin-head-actions' ), '… in its actions area' );
pdt_check( false === strpos( $html, 'plandose-top-actions' ), 'the old floated action box is gone' );

$css = (string) file_get_contents( PLANDOSE_PATH . 'assets/css/plandose-admin.css' );
pdt_check( ! preg_match( '/margin-top\s*:\s*-\s*[1-9]/', $css ), 'no negative margin-top pulling content over the notices' );

wp_set_current_user( 0 );
pdt_done();
