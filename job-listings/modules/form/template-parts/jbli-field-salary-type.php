<?php
/**
 * Form partial: Salary & Type fields
 *
 * Renders the salary and employment-type select fields side by side.
 *
 * Expects:
 * - $jbli_fv (callable)
 *
 * @package JobListings
 * @since   9.9.31
 */

defined( 'ABSPATH' ) || exit;

$jbli_salary_options 	= function_exists( 'jbli_salary_options' ) ? jbli_salary_options() : array();
$jbli_type_options 		= function_exists( 'jbli_type_options' ) ? jbli_type_options() : array();

$jbli_salary_options 	= is_array( $jbli_salary_options ) ? $jbli_salary_options : array();
$jbli_type_options   	= is_array( $jbli_type_options ) ? $jbli_type_options : array();

$jbli_selected_salary 	= is_callable( $jbli_fv ) ? (string) $jbli_fv( 'jbli_salary' ) : '';
$jbli_selected_type   	= is_callable( $jbli_fv ) ? (string) $jbli_fv( 'jbli_type' ) : '';
?>

<div class="jbli_form_grid jbli_form_grid_2 jbli_form_grid_salary_type">

	<?php  ?>
	<div class="jbli_field jbli_form_field jbli_form_field_salary">

		<label class="jbli_field_label" for="job_salary">
			<?php esc_html_e( 'Αμοιβή', 'job-listings' ); ?>
			<span class="jbli_req" aria-hidden="true">*</span>
		</label>

		<div class="jbli_select_wrap">
			<select id="job_salary" name="job_salary" class="jbli_select" required aria-required="true">
				<option value=""><?php esc_html_e( 'Επιλέξτε αμοιβή', 'job-listings' ); ?></option>
				<?php

					foreach ( $jbli_salary_options as $jbli_val => $jbli_label )
					{

						?>
						<option value="<?php echo esc_attr( (string) $jbli_val ); ?>" <?php selected( $jbli_selected_salary, (string) $jbli_val ); ?>><?php echo esc_html( (string) $jbli_label ); ?></option>
						<?php
					}
				?>
			</select>
		</div>

	</div>

	<?php  ?>
	<div class="jbli_field jbli_form_field jbli_form_field_type">

		<label class="jbli_field_label" for="job_type">
			<?php esc_html_e( 'Τύπος Απασχόλησης', 'job-listings' ); ?>
			<span class="jbli_req" aria-hidden="true">*</span>
		</label>

		<div class="jbli_select_wrap">
			<select id="job_type" name="job_type" class="jbli_select" required aria-required="true">
				<option value=""><?php esc_html_e( 'Επιλέξτε τύπο', 'job-listings' ); ?></option>
				<?php

					foreach ( $jbli_type_options as $jbli_val => $jbli_label )
					{

						?>
						<option value="<?php echo esc_attr( (string) $jbli_val ); ?>" <?php selected( $jbli_selected_type, (string) $jbli_val ); ?>><?php echo esc_html( (string) $jbli_label ); ?></option>
						<?php
					}
				?>
			</select>
		</div>

	</div>

</div>
