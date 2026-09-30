<?php
/**
 * Admin panel partial: Stats bar
 *
 * Displays listing counts by status.
 * Expects: $jbli_stats (array from jbli_get_admin_stats()).
 *
 * @package JobListings
 * @since   9.9.25
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="jbli_ap_stats">

	<div class="jbli_ap_stat jbli_ap_stat_green">
		<span class="jbli_ap_stat_n"><?php echo esc_html( (string) ( $jbli_stats['active']  ?? 0 ) ); ?></span>
		<span class="jbli_ap_stat_l"><?php esc_html_e( 'Ενεργές', 'job-listings' ); ?></span>
	</div>

	<div class="jbli_ap_stat jbli_ap_stat_red">
		<span class="jbli_ap_stat_n"><?php echo esc_html( (string) ( $jbli_stats['expired'] ?? 0 ) ); ?></span>
		<span class="jbli_ap_stat_l"><?php esc_html_e( 'Ληγμένες', 'job-listings' ); ?></span>
	</div>

	<div class="jbli_ap_stat jbli_ap_stat_gray">
		<span class="jbli_ap_stat_n"><?php echo esc_html( (string) ( $jbli_stats['draft']   ?? 0 ) ); ?></span>
		<span class="jbli_ap_stat_l"><?php esc_html_e( 'Ανενεργές', 'job-listings' ); ?></span>
	</div>

	<div class="jbli_ap_stat jbli_ap_stat_yellow">
		<span class="jbli_ap_stat_n"><?php echo esc_html( (string) ( $jbli_stats['pending'] ?? 0 ) ); ?></span>
		<span class="jbli_ap_stat_l"><?php esc_html_e( 'Σε αναμονή', 'job-listings' ); ?></span>
	</div>

	<div class="jbli_ap_stat jbli_ap_stat_blue">
		<span class="jbli_ap_stat_n"><?php echo esc_html( (string) ( $jbli_stats['total']   ?? 0 ) ); ?></span>
		<span class="jbli_ap_stat_l"><?php esc_html_e( 'Σύνολο', 'job-listings' ); ?></span>
	</div>

</div>
