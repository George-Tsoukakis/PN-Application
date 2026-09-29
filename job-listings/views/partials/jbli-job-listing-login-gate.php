<?php
/**
 * Partial: Login / register gate.
 *
 * Expected vars: $jbli_heading, $jbli_subtitle, $jbli_icon_svg, $jbli_login_url, $jbli_register_url, $jbli_login_icon
 *
 * @package JobListings
 * @since   9.9.39
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="jbli_login_gate">
	<div class="jbli_login_gate_inner">
		<div class="jbli_login_gate_icon" aria-hidden="true"><?php echo $jbli_icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<h2 class="jbli_login_gate_title"><?php echo esc_html( $jbli_heading ); ?></h2>
		<p class="jbli_login_gate_subtitle"><?php echo esc_html( $jbli_subtitle ); ?></p>
		<div class="jbli_login_gate_actions">
			<a href="<?php echo esc_url( $jbli_login_url ); ?>" class="jbli_login_gate_btn jbli_login_gate_btn_primary"><?php echo $jbli_login_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Σύνδεση', 'job-listings' ); ?></a>
			<a href="<?php echo esc_url( $jbli_register_url ); ?>" class="jbli_login_gate_btn jbli_login_gate_btn_secondary"><?php esc_html_e( 'Εγγραφή', 'job-listings' ); ?></a>
		</div>
	</div>
</div>
