<?php
/**
 * Form partial: Category & Nomos fields
 *
 * Renders the job_category and job_nomos select fields side by side.
 *
 * Expects:
 * - $jbli_selected_category (int)
 * - $jbli_selected_nomos    (int)
 *
 * @package JobListings
 * @since   9.9.31
 */

defined( 'ABSPATH' ) || exit;

$jbli_categories 		= function_exists( 'jbli_get_terms' ) ? jbli_get_terms( 'job_category' ) : array();
$jbli_nomoi_terms 		= function_exists( 'jbli_get_terms' ) ? jbli_get_terms( 'job_nomos' ) : array();

$jbli_categories  		= is_array( $jbli_categories ) ? $jbli_categories : array();
$jbli_nomoi_terms 		= is_array( $jbli_nomoi_terms ) ? $jbli_nomoi_terms : array();

$jbli_selected_category = isset( $jbli_selected_category ) ? absint( $jbli_selected_category ) : 0;
$jbli_selected_nomos    = isset( $jbli_selected_nomos ) ? absint( $jbli_selected_nomos ) : 0;
?>

<div class="jbli_form_grid jbli_form_grid_2 jbli_form_grid_category_nomos">

	<?php  ?>
	<div class="jbli_field jbli_form_field jbli_form_field_category">

		<label class="jbli_field_label" for="job_category"><?php esc_html_e( 'Κατηγορία', 'job-listings' ); ?> <span class="jbli_req" aria-hidden="true">*</span></label>

		<div class="jbli_select_wrap">
			<select id="job_category" name="job_category" class="jbli_select" required aria-required="true">
				<option value=""><?php esc_html_e( 'Επιλέξτε κατηγορία', 'job-listings' ); ?></option>
				<?php
					foreach ( $jbli_categories as $jbli_category )
					{
						if ( ! $jbli_category instanceof WP_Term ) { continue; }
						?><option value="<?php echo esc_attr( (string) $jbli_category->term_id ); ?>" <?php selected( $jbli_selected_category, (int) $jbli_category->term_id ); ?>><?php echo esc_html( $jbli_category->name ); ?></option><?php
					}
				?>
			</select>
		</div>

	</div>

	<?php  ?>
	<div class="jbli_field jbli_form_field jbli_form_field_nomos">

		<label class="jbli_field_label" for="job_nomos"><?php esc_html_e( 'Νομός', 'job-listings' ); ?> <span class="jbli_req" aria-hidden="true">*</span></label>

		<div class="jbli_select_wrap">
			<select id="job_nomos" name="job_nomos" class="jbli_select" required aria-required="true">
				<option value=""><?php esc_html_e( 'Επιλέξτε νομό', 'job-listings' ); ?></option>
				<?php
					foreach ( $jbli_nomoi_terms as $jbli_nomos )
					{
						if ( ! $jbli_nomos instanceof WP_Term ) { continue; }
						?><option value="<?php echo esc_attr( (string) $jbli_nomos->term_id ); ?>" <?php selected( $jbli_selected_nomos, (int) $jbli_nomos->term_id ); ?>><?php echo esc_html( $jbli_nomos->name ); ?></option><?php
					}
				?>
			</select>
		</div>

	</div>

</div>
