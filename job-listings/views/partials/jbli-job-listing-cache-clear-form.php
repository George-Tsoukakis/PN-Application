<?php
/**
 * Partial: Admin cache clear inline form.
 *
 * Expected vars: $jbli_url, $jbli_nonce, $jbli_target, $jbli_label, $jbli_class
 *
 * @package JobListings
 * @since   9.9.39
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="<?php echo esc_url( $jbli_url ); ?>" class="jbli_cache_clear_form">
	<input type="hidden" name="jbli_cache_action" value="clear">
	<input type="hidden" name="jbli_cache_nonce" value="<?php echo esc_attr( $jbli_nonce ); ?>">
	<input type="hidden" name="jbli_cache_targets[]" value="<?php echo esc_attr( $jbli_target ); ?>">
	<button type="submit" class="button <?php echo esc_attr( $jbli_class ); ?>"><?php echo esc_html( $jbli_label ); ?></button>
</form>
