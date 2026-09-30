<?php
/**
 * Admin panel partial: Privacy Settings box
 *
 * @package JobListings
 * @since   9.9.25
 * @since   9.9.37 Clean card-list layout with working CSS-only toggles.
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="jbli_ap_tools">

	<h2 class="jbli_ap_tools_heading">
		<?php esc_html_e( 'Ρυθμίσεις Απορρήτου', 'job-listings' ); ?>
	</h2>

	<div class="jbli_ap_tools_row">
		<div class="jbli_ap_tools_col">

			<p style="margin:0 0 20px;font-size:13px;color:#6b7280;line-height:1.6;">
				<?php esc_html_e( 'Επιλέξτε ποια στοιχεία επικοινωνίας είναι ορατά στις δημόσιες αγγελίες. Τα κρυφά στοιχεία δεν εμφανίζονται σε επισκέπτες ούτε σε bots/scrapers.', 'job-listings' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=jbli_settings' ) ); ?>" class="jbli_ap_form">
				<input type="hidden" name="jbli_admin_action" value="save_settings">
				<input type="hidden" name="job_post_id"              value="0">
				<input type="hidden" name="_job_listing_admin_nonce" value="<?php echo esc_attr( $jbli_settings_nonce ); ?>">
				<input type="hidden" name="jbli_delete_on_uninstall"   value="<?php echo esc_attr( $jbli_delete_on_uninstall ? '1' : '0' ); ?>">

				<div class="jbli_prv_list">

					<?php
					$jbli_items = array(
						array(
							'jbli_id'    => 'jbli_pub_phone',
							'name'       => 'jbli_public_phone',
							'checked'    => $jbli_public_phone,
							'jbli_title' => __( 'Τηλέφωνο', 'job-listings' ),
							'desc'       => __( 'Εμφάνιση τηλεφώνου στις δημόσιες αγγελίες.', 'job-listings' ),
							'badge'      => __( 'Default: Ενεργό', 'job-listings' ),
							'badge_ok'   => true,
							'icon'       => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.9a16 16 0 0 0 6 6l1.06-.94a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.73 16.18z"/></svg>',
						),
						array(
							'jbli_id'    => 'jbli_pub_email',
							'name'       => 'jbli_public_email',
							'checked'    => $jbli_public_email,
							'jbli_title' => __( 'Email', 'job-listings' ),
							'desc'       => __( 'Εμφάνιση email στις αγγελίες. Προσοχή: μπορεί να αυξήσει spam/scraping.', 'job-listings' ),
							'badge'      => __( 'Default: Ανενεργό', 'job-listings' ),
							'badge_ok'   => false,
							'icon'       => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
						),
						array(
							'jbli_id'    => 'jbli_pub_address',
							'name'       => 'jbli_public_address',
							'checked'    => $jbli_public_address,
							'jbli_title' => __( 'Διεύθυνση', 'job-listings' ),
							'desc'       => __( 'Εμφάνιση διεύθυνσης μόνο εφόσον υπάρχει συναίνεση χρήστη.', 'job-listings' ),
							'badge'      => __( 'Default: Ανενεργό', 'job-listings' ),
							'badge_ok'   => false,
							'icon'       => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
						),
					);
					?>
					<?php

						foreach ( $jbli_items as $jbli_item )
						{
							$jbli_checked    = ! empty( $jbli_item['checked'] );
							$jbli_item_class = 'jbli_prv_item' . ( $jbli_checked ? ' jbli_prv_item_on' : '' );

							?>
							<div class="<?php echo esc_attr( $jbli_item_class ); ?>" data-toggle="<?php echo esc_attr( $jbli_item['jbli_id'] ); ?>">

								<span class="jbli_prv_icon"><?php echo $jbli_item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe SVG icon. ?></span>

								<div class="jbli_prv_text">
									<strong><?php echo esc_html( $jbli_item['jbli_title'] ); ?></strong>
									<span><?php echo esc_html( $jbli_item['desc'] ); ?></span>
									<em class="jbli_prv_badge <?php echo $jbli_item['badge_ok'] ? 'jbli_prv_badge_ok' : 'jbli_prv_badge_warn'; ?>">
										<?php echo esc_html( $jbli_item['badge'] ); ?>
									</em>
								</div>

								<label class="jbli_prv_toggle" for="<?php echo esc_attr( $jbli_item['jbli_id'] ); ?>" aria-label="<?php echo esc_attr( $jbli_item['jbli_title'] ); ?>">
									<input
										type="checkbox"
										id="<?php echo esc_attr( $jbli_item['jbli_id'] ); ?>"
										name="<?php echo esc_attr( $jbli_item['name'] ); ?>"
										value="1"
										class="jbli_prv_toggle_input"
										<?php checked( $jbli_checked ); ?>
									>
									<span class="jbli_prv_toggle_track">
										<span class="jbli_prv_toggle_thumb"></span>
									</span>
								</label>

							</div>
							<?php
						}
					?>

				</div>

				<div class="jbli_prv_footer">
					<button type="submit" class="jbli_prv_save button button-primary">
						<?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'job-listings' ); ?>
					</button>
				</div>

			</form>

		</div>
	</div>

</div>
