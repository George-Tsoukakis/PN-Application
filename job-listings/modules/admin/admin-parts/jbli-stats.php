<?php
/**
 * Admin Panel: Statistics
 *
 * Returns post-count stats for the admin panel dashboard.
 * Loaded by jbli-admin-panel.php.
 *
 * @package JobListings
 * @since   9.9.24
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return listing counts grouped by status.
 *
 * Uses wp_count_posts() which is cached by WordPress and runs a single
 * grouped COUNT query. No additional caching needed.
 *
 * @return array{active: int, expired: int, draft: int, pending: int, total: int}
 */
function jbli_get_admin_stats(): array {

	$jbli_counts = wp_count_posts( JBLI_CPT );

	$jbli_active  = (int) ( $jbli_counts->publish      ?? 0 );
	$jbli_expired = (int) ( $jbli_counts->{'job-expired'} ?? 0 );
	$jbli_draft   = (int) ( $jbli_counts->draft         ?? 0 );
	$jbli_pending = (int) ( $jbli_counts->pending       ?? 0 );

	return array(
		'active'  => $jbli_active,
		'expired' => $jbli_expired,
		'draft'   => $jbli_draft,
		'pending' => $jbli_pending,
		'total'   => $jbli_active + $jbli_expired + $jbli_draft + $jbli_pending,
	);

}
