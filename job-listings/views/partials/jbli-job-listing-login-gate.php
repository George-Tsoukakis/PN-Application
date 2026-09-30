<?php
/**
 * Partial: Login / register gate.
 *
 * Two columns: text, benefits and buttons on the left; the media panel
 * (image / video from Ρυθμίσεις, or the built-in illustration) on the right.
 * On phones the media is hidden and the text stays centred.
 *
 * Expected vars: $jbli_heading, $jbli_subtitle, $jbli_icon_svg, $jbli_login_url,
 * $jbli_register_url, $jbli_login_icon, $jbli_benefits (string[]),
 * $jbli_listings_url (string, may be ''), $jbli_media_html (trusted HTML).
 *
 * @package JobListings
 * @since   9.9.39
 * @since   9.9.61 Split layout with benefits and media panel.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="jbli_login_gate" aria-labelledby="jbli_login_gate_title">
	<div class="jbli_login_gate_inner">

		<div class="jbli_login_gate_text">

			<p class="jbli_login_gate_eyebrow">
				<span class="jbli_login_gate_icon" aria-hidden="true"><?php echo $jbli_icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
				<?php esc_html_e( 'Για φαρμακεία', 'job-listings' ); ?>
			</p>

			<h2 id="jbli_login_gate_title" class="jbli_login_gate_title"><?php echo esc_html( $jbli_heading ); ?></h2>

			<p class="jbli_login_gate_subtitle"><?php echo esc_html( $jbli_subtitle ); ?></p>

			<?php if ( ! empty( $jbli_benefits ) ) { ?>
				<ul class="jbli_login_gate_benefits">
					<?php foreach ( (array) $jbli_benefits as $jbli_benefit ) { ?>
						<li><?php echo esc_html( (string) $jbli_benefit ); ?></li>
					<?php } ?>
				</ul>
			<?php } ?>

			<div class="jbli_login_gate_actions">
				<a href="<?php echo esc_url( $jbli_login_url ); ?>" class="jbli_login_gate_btn jbli_login_gate_btn_primary"><?php echo $jbli_login_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?> <?php esc_html_e( 'Σύνδεση', 'job-listings' ); ?></a>
				<a href="<?php echo esc_url( $jbli_register_url ); ?>" class="jbli_login_gate_btn jbli_login_gate_btn_secondary"><?php esc_html_e( 'Δημιουργία λογαριασμού', 'job-listings' ); ?></a>
			</div>

			<?php if ( ! empty( $jbli_listings_url ) ) { ?>
				<p class="jbli_login_gate_note">
					<?php esc_html_e( 'Ψάχνετε εργασία;', 'job-listings' ); ?>
					<a href="<?php echo esc_url( $jbli_listings_url ); ?>"><?php esc_html_e( 'Δείτε τις αγγελίες', 'job-listings' ); ?> <span aria-hidden="true">→</span></a>
				</p>
			<?php } ?>

		</div>

		<?php if ( ! empty( $jbli_media_html ) ) { ?>
			<div class="jbli_login_gate_media" aria-hidden="true">
				<?php echo $jbli_media_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by jbli_media_panel_html() from escaped parts. ?>
			</div>
		<?php } ?>

	</div>
</section>
