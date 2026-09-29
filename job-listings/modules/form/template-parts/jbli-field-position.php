<?php
/**
 * Form partial: Position field
 *
 * Renders the job position / title text input.
 * Expects: $jbli_fv (callable).
 *
 * @package JobListings
 * @since   9.9.23
 */

defined('ABSPATH') || exit;
?>

<div class="jbli_field">

	<label for="job_position"><?php esc_html_e('Τίτλος Αγγελίας', 'job-listings'); ?> <span
			class="jbli_req">*</span></label>

	<input type="text" id="job_position" name="job_position" value="<?php echo esc_attr($jbli_fv('jbli_position')); ?>" class="jbli_input" maxlength="120" required autocomplete="organization-title" enterkeyhint="next" aria-describedby="job_position_hint">

	<span class="jbli_field_hint" id="job_position_hint" aria-live="polite">
		<span class="jbli_char_counter" data-input="job_position"
			data-max="120"><?php echo esc_html((string) (function_exists('jbli_strlen') ? jbli_strlen($jbli_fv('jbli_position')) : mb_strlen($jbli_fv('jbli_position')))); ?></span>/120
	</span>

</div>