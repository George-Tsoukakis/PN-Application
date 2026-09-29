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

	<?php
		$jbli_pos_value = (string) $jbli_fv( 'jbli_position' );
		$jbli_max_title = function_exists( 'jbli_title_max_chars' ) ? jbli_title_max_chars() : 15;
		$jbli_pos_len   = function_exists( 'jbli_strlen' ) ? jbli_strlen( $jbli_pos_value ) : mb_strlen( $jbli_pos_value );
	?>

	<input type="text" id="job_position" name="job_position" value="<?php echo esc_attr( $jbli_pos_value ); ?>" class="jbli_input" maxlength="<?php echo esc_attr( (string) $jbli_max_title ); ?>" placeholder="<?php esc_attr_e( 'π.χ. Φαρμακοποιός', 'job-listings' ); ?>" required autocomplete="organization-title" enterkeyhint="next" aria-describedby="job_position_hint">

	<span class="jbli_field_hint" id="job_position_hint" aria-live="polite">
		<?php
			/* translators: %d: maximum number of characters */
			echo esc_html( sprintf( __( 'Σύντομος τίτλος, έως %d χαρακτήρες.', 'job-listings' ), $jbli_max_title ) );
		?>
		<span class="jbli_char_counter" data-input="job_position" data-max="<?php echo esc_attr( (string) $jbli_max_title ); ?>"><?php echo esc_html( (string) $jbli_pos_len ); ?></span>/<?php echo esc_html( (string) $jbli_max_title ); ?>
	</span>

</div>