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

	<input type="text" id="job_position" name="job_position" value="<?php echo esc_attr($jbli_fv('jbli_position')); ?>" class="jbli_input" maxlength="120" placeholder="<?php esc_attr_e( 'π.χ. Ζητείται Βοηθός Φαρμακείου', 'job-listings' ); ?>" required autocomplete="organization-title" enterkeyhint="next" aria-describedby="job_position_hint">

	<?php
		$jbli_pos_value = $jbli_fv( 'jbli_position' );
		$jbli_pos_words = function_exists( 'jbli_title_words' ) ? count( jbli_title_words( $jbli_pos_value ) ) : 0;
		$jbli_max_words = function_exists( 'jbli_title_max_words' ) ? jbli_title_max_words() : 15;
		$jbli_max_chars = function_exists( 'jbli_title_max_word_chars' ) ? jbli_title_max_word_chars() : 30;
	?>
	<span class="jbli_field_hint" id="job_position_hint" aria-live="polite">
		<?php
			/* translators: %d: maximum number of words */
			echo esc_html( sprintf( __( 'Σύντομος τίτλος, π.χ. «Ζητείται Βοηθός Φαρμακείου» (έως %d λέξεις).', 'job-listings' ), $jbli_max_words ) );
		?>
		<span class="jbli_word_counter" data-input="job_position" data-max-words="<?php echo esc_attr( (string) $jbli_max_words ); ?>" data-max-word-chars="<?php echo esc_attr( (string) $jbli_max_chars ); ?>"><?php echo esc_html( (string) $jbli_pos_words ); ?></span>/<?php echo esc_html( (string) $jbli_max_words ); ?>
	</span>

</div>