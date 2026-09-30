<?php
/**
 * Admin panel template.
 *
 * Orchestrates the panel layout by including focused partial templates.
 * Each partial owns exactly one section.
 *
 * Partial layout:
 *   panel-parts/jbli-header.php          — page title + action-result notice.
 *   panel-parts/jbli-stats.php           — listing counts bar.
 *   panel-parts/jbli-filters.php         — search + taxonomy + status filters.
 *   panel-parts/jbli-listings-table.php  — results table + pagination (or empty state).
 *   panel-parts/jbli-settings-box.php    — privacy settings form.
 *   panel-parts/jbli-danger-zone.php     — storage backfill + delete-on-uninstall.
 *   panel-parts/jbli-confirm-script.php  — inline JS for data-confirm dialogs.
 *
 * Variables provided by admin-parts/jbli-render.php:
 *   $jbli_stats     (array)     Listing counts from jbli_get_admin_stats().
 *   $jbli_query     (WP_Query)  The filtered listings query.
 *   $jbli_paged     (int)       Current page number.
 *   $jbli_f_nomos   (int)       Active nomos filter term ID.
 *   $jbli_f_cat     (int)       Active category filter term ID.
 *   $jbli_f_status  (string)    Active status filter key.
 *   $jbli_f_s       (string)    Active search string.
 *   $jbli_all_nomoi (WP_Term[]) All nomos terms for the filter dropdown.
 *   $jbli_all_cats  (WP_Term[]) All category terms for the filter dropdown.
 *
 * @package JobListings
 * @since   9.6.6
 */

defined( 'ABSPATH' ) || exit;

$jbli_partials = __DIR__ . '/panel-parts/';

$jbli_ads_url      = admin_url( 'admin.php?page=jbli_admin_panel' );
$jbli_settings_url = admin_url( 'admin.php?page=jbli_settings' );
$jbli_cache_url    = admin_url( 'admin.php?page=jbli_cache' );
?>

<div class="wrap jbli_ap">

	<div class="jbli_settings_tabs">
		<a href="<?php echo esc_url( $jbli_ads_url ); ?>" class="jbli_settings_tab jbli_settings_tab_active">
			📋 <?php esc_html_e( 'Αγγελίες', 'job-listings' ); ?>
		</a>
		<a href="<?php echo esc_url( $jbli_settings_url ); ?>" class="jbli_settings_tab">
			⚙️ <?php esc_html_e( 'Ρυθμίσεις', 'job-listings' ); ?>
		</a>
		<a href="<?php echo esc_url( $jbli_cache_url ); ?>" class="jbli_settings_tab">
			⚡ <?php esc_html_e( 'Cache', 'job-listings' ); ?>
		</a>
	</div>

	<?php include $jbli_partials . 'jbli-header.php'; ?>
	<?php include $jbli_partials . 'jbli-stats.php'; ?>
	<?php include $jbli_partials . 'jbli-filters.php'; ?>
	<?php include $jbli_partials . 'jbli-listings-table.php'; ?>
	<?php include $jbli_partials . 'jbli-confirm-script.php'; ?>

</div>
