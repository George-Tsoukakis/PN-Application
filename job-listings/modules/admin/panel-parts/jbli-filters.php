<?php
/**
 * Admin panel partial: Filters bar
 *
 * Search + taxonomy + status filter form.
 * Expects: $jbli_f_s, $jbli_f_nomos, $jbli_f_cat, $jbli_f_status (all sanitized), $jbli_all_nomoi, $jbli_all_cats (WP_Term[]).
 *
 * @package JobListings
 * @since   9.9.25
 */

defined( 'ABSPATH' ) || exit;
?>

<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="jbli_ap_filters">

	<input type="hidden" name="page"      value="jbli_admin_panel">

	<div class="jbli_ap_filters_row">

		<input
			type="search"
			name="f_s"
			value="<?php echo esc_attr( trim( (string) $jbli_f_s ) ); ?>"
			placeholder="<?php esc_attr_e( 'Αναζήτηση φαρμακείου, θέσης...', 'job-listings' ); ?>"
			class="regular-text jbli_ap_search"
			maxlength="80"
			inputmode="search"
			enterkeyhint="search"
		>

		<select name="f_nomos" class="jbli_ap_select">
			<option value=""><?php esc_html_e( 'Όλοι οι νομοί', 'job-listings' ); ?></option>
		<?php

			foreach ( (array) $jbli_all_nomoi as $jbli_nomos )
			{
				if ( $jbli_nomos instanceof WP_Term )
				{

					?>
					<option value="<?php echo esc_attr( (string) $jbli_nomos->term_id ); ?>" <?php selected( $jbli_f_nomos, (int) $jbli_nomos->term_id ); ?>>
						<?php echo esc_html( $jbli_nomos->name ); ?>
					</option>
					<?php
				}
			}
		?>
		</select>

		<select name="f_cat" class="jbli_ap_select">
			<option value=""><?php esc_html_e( 'Όλες οι κατηγορίες', 'job-listings' ); ?></option>
		<?php

			foreach ( (array) $jbli_all_cats as $jbli_cat )
			{
				if ( $jbli_cat instanceof WP_Term )
				{

					?>
					<option value="<?php echo esc_attr( (string) $jbli_cat->term_id ); ?>" <?php selected( $jbli_f_cat, (int) $jbli_cat->term_id ); ?>>
						<?php echo esc_html( $jbli_cat->name ); ?>
					</option>
					<?php
				}
			}
		?>
		</select>

		<select name="f_status" class="jbli_ap_select">
			<option value=""><?php esc_html_e( 'Όλες οι καταστάσεις', 'job-listings' ); ?></option>
			<option value="publish"     <?php selected( $jbli_f_status, 'publish' ); ?>><?php esc_html_e( 'Ενεργές', 'job-listings' ); ?></option>
			<option value="draft"       <?php selected( $jbli_f_status, 'draft' ); ?>><?php esc_html_e( 'Ανενεργές', 'job-listings' ); ?></option>
			<option value="job-expired" <?php selected( $jbli_f_status, 'job-expired' ); ?>><?php esc_html_e( 'Ληγμένες', 'job-listings' ); ?></option>
			<option value="pending"     <?php selected( $jbli_f_status, 'pending' ); ?>><?php esc_html_e( 'Σε αναμονή', 'job-listings' ); ?></option>
		</select>

		<div class="jbli_ap_filters_actions">
			<?php submit_button( __( 'Εφαρμογή', 'job-listings' ), 'primary', 'submit', false ); ?>
		<?php

			if ( $jbli_f_nomos || $jbli_f_cat || $jbli_f_status || $jbli_f_s )
			{

				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=jbli_admin_panel' ) ); ?>" class="button">
					<?php esc_html_e( 'Καθαρισμός', 'job-listings' ); ?>
				</a>
				<?php
			}
		?>
		</div>

	</div>

</form>
