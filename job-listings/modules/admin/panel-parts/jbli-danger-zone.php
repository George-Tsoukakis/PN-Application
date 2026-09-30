<?php
/**
 * Admin panel partial: Danger Zone
 *
 * Storage backfill tool and destructive uninstall setting.
 * Expects: $jbli_backfill_nonce, $jbli_settings_nonce, $jbli_delete_on_uninstall.
 *
 * @package JobListings
 * @since   9.9.25
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="jbli_ap_tools">

	<h2 class="jbli_ap_tools_heading">
		<?php esc_html_e( 'Εργαλεία Storage', 'job-listings' ); ?>
	</h2>

	<div class="jbli_ap_tools_row">

		<?php  ?>
		<div class="jbli_ap_tools_col">
			<h3><?php esc_html_e( 'Συγχρονισμός Αγγελιών', 'job-listings' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Ανεβάζει όλες τις υπάρχουσες αγγελίες στο storage index (wpjbli_pharmacy_listings). Τρέξτε το μετά από bulk import ή εάν τα στατιστικά του dashboard φαίνονται εσφαλμένα.', 'job-listings' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=jbli_settings' ) ); ?>" class="jbli_ap_form">
				<input type="hidden" name="jbli_admin_action" value="backfill_storage">
				<input type="hidden" name="job_post_id"              value="0">
				<input type="hidden" name="_job_listing_admin_nonce" value="<?php echo esc_attr( $jbli_backfill_nonce ); ?>">
				<button type="submit" class="button button-secondary">
					<?php esc_html_e( '🔄 Συγχρονισμός όλων των αγγελιών', 'job-listings' ); ?>
				</button>
			</form>
		</div>

		<?php  ?>
		<div class="jbli_ap_tools_col jbli_ap_tools_col_danger">
			<h3><?php esc_html_e( '⚠️ Ζώνη Κινδύνου', 'job-listings' ); ?></h3>
			<?php

				if ( $jbli_delete_on_uninstall )
				{

					?>
					<div class="notice notice-error inline" style="margin:0 0 14px;padding:10px 14px;">
						<p style="margin:0;font-weight:600;">
							<?php esc_html_e( '🚨 ΠΡΟΣΟΧΗ: Η διαγραφή δεδομένων κατά την απεγκατάσταση είναι ΕΝΕΡΓΗ.', 'job-listings' ); ?>
						</p>
						<p style="margin:6px 0 0;">
							<?php esc_html_e( 'Αν απεγκαταστήσετε το plugin τώρα, θα διαγραφούν μόνιμα ΟΛΕΣ οι αγγελίες, οι πίνακες βάσης δεδομένων και οι ρυθμίσεις. Η ενέργεια δεν αναστρέφεται.', 'job-listings' ); ?>
						</p>
					</div>
					<?php
				}
			?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=jbli_settings' ) ); ?>" class="jbli_ap_form">
				<input type="hidden" name="jbli_admin_action" value="save_settings">
				<input type="hidden" name="jbli_settings_section" value="danger">
				<input type="hidden" name="job_post_id"              value="0">
				<input type="hidden" name="_job_listing_admin_nonce" value="<?php echo esc_attr( $jbli_settings_nonce ); ?>">

				<label class="jbli_ap_danger_check">
					<input
						type="checkbox"
						name="jbli_delete_on_uninstall"
						value="1"
						<?php checked( $jbli_delete_on_uninstall ); ?>
						onchange="document.getElementById('jbli_uninstall_warning').style.display = this.checked ? 'block' : 'none';"
					>
					<?php esc_html_e( 'Διαγραφή ΟΛΩΝ των δεδομένων κατά την απεγκατάσταση (αγγελίες, πίνακες, options)', 'job-listings' ); ?>
				</label>

				<div
					id="jbli_uninstall_warning"
					style="display:<?php echo $jbli_delete_on_uninstall ? 'block' : 'none'; ?>;background:#fff3cd;border:1px solid #f0ad4e;border-radius:4px;padding:10px 14px;margin-bottom:12px;"
				>
					<strong style="color:#856404;">
						<?php esc_html_e( '⚠️ Μη αναστρέψιμη ενέργεια:', 'job-listings' ); ?>
					</strong>
					<?php esc_html_e( 'Κάνοντας αποθήκευση με αυτή την επιλογή, η επόμενη απεγκατάσταση του plugin θα διαγράψει μόνιμα ΟΛΕΣ τις αγγελίες, τους πίνακες (wpjbli_*) και τις ρυθμίσεις. Δεν υπάρχει undo.', 'job-listings' ); ?>
				</div>

				<div class="jbli_ap_danger_actions">
					<button
						type="submit"
						class="button button-secondary"
						<?php

							if ( ! $jbli_delete_on_uninstall )
							{

								?>
								data-confirm="<?php esc_attr_e( 'Είστε σίγουροι; Αυτή η ρύθμιση θα επιτρέψει τη μόνιμη διαγραφή ΟΛΩΝ των δεδομένων κατά την απεγκατάσταση.', 'job-listings' ); ?>"
								<?php
							}
						?>
					>
						<?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'job-listings' ); ?>
					</button>
				</div>
			</form>
		</div>

	</div>

</div>
