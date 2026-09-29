<?php
/**
 * Form partial: Contact fields
 *
 * Renders phone and email fields side by side.
 *
 * Expects:
 * - $jbli_fv            (callable)
 * - $jbli_phone         (string)
 * - $jbli_mobile        (string)
 * - $jbli_user_email    (string)  — fallback when no saved contact_email exists
 * - $jbli_contact_email (string)  — pre-filled value for edit mode
 *
 * @package JobListings
 * @since   9.9.31
 * @since   9.9.35 Email field is now editable; pre-filled from listing meta in edit mode.
 */

defined( 'ABSPATH' ) || exit;

$jbli_default_phone 		= ( isset( $jbli_mobile ) && '' !== trim( (string) $jbli_mobile ) ) ? (string) $jbli_mobile : ( ( isset( $jbli_phone ) && '' !== trim( (string) $jbli_phone ) ) ? (string) $jbli_phone : '' );
$jbli_contact_phone 		= is_callable( $jbli_fv ) ? $jbli_fv( 'jbli_contact_phone', $jbli_default_phone ) : $jbli_default_phone;
$jbli_user_email 			= ( isset( $jbli_user_email ) && is_email( $jbli_user_email ) ) ? (string) $jbli_user_email : '';
$jbli_default_email 		= ( isset( $jbli_contact_email ) && is_email( $jbli_contact_email ) ) ? (string) $jbli_contact_email : ( is_email( $jbli_user_email ) ? $jbli_user_email : '' );
$jbli_contact_email_value 	= is_callable( $jbli_fv ) ? $jbli_fv( 'contact_email', $jbli_default_email ) : $jbli_default_email;
?>

<div class="jbli_form_grid jbli_form_grid_2 jbli_form_grid_contact">

	<?php  ?>
	<div class="jbli_field jbli_form_field jbli_form_field_phone">

		<label class="jbli_field_label" for="job_contact_phone"><?php esc_html_e( 'Τηλέφωνο', 'job-listings' ); ?> <span class="jbli_req" aria-hidden="true">*</span></label>

		<input type="tel" id="job_contact_phone" name="job_contact_phone" value="<?php echo esc_attr( $jbli_contact_phone ); ?>" class="jbli_input" inputmode="tel" autocomplete="tel" required aria-required="true">

	</div>

	<?php  ?>
	<div class="jbli_field jbli_form_field jbli_form_field_email">

		<label class="jbli_field_label" for="job_contact_email"><?php esc_html_e( 'Email Επικοινωνίας', 'job-listings' ); ?> <span class="jbli_req" aria-hidden="true">*</span></label>

		<input type="email" id="job_contact_email" name="job_contact_email" value="<?php echo esc_attr( $jbli_contact_email_value ); ?>" class="jbli_input" inputmode="email" autocomplete="email" required aria-required="true">

	</div>

</div>
