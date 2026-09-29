<?php
/**
 * Form partial: Description field
 *
 * Renders the listing description textarea.
 * Expects: $jbli_fv (callable).
 *
 * @package JobListings
 * @since   9.9.23
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="jbli_field jbli_field_description">

	<label for="job_description"><?php esc_html_e( 'Περιγραφή', 'job-listings' ); ?> <span class="jbli_req">*</span></label>

	<textarea
		id="job_description"
		name="job_description"
		rows="6"
		class="jbli_textarea"
		autocomplete="off"
		enterkeyhint="done"
		required
	><?php echo esc_textarea( $jbli_fv( 'description' ) ); ?></textarea>

</div>
