<?php
/**
 * Partial: Empty listings state.
 *
 * Expected vars: $jbli_message
 * Optional: $jbli_clear_url, $jbli_cta_label
 *
 * @package JobListings
 * @since   9.9.39
 */

defined( 'ABSPATH' ) || exit;

$jbli_clear_url = isset( $jbli_clear_url ) ? (string) $jbli_clear_url : '';
$jbli_cta_label = isset( $jbli_cta_label ) ? (string) $jbli_cta_label : '';
?>
<div class="jbli_empty">
	<div class="jbli_empty_icon" aria-hidden="true">🔍</div>
	<p><?php echo esc_html( $jbli_message ); ?></p>
	<?php

	if ( '' !== $jbli_clear_url && '' !== $jbli_cta_label )
	{

		?>
		<a href="<?php echo esc_url( $jbli_clear_url ); ?>" class="jbli_btn jbli_btn_outline"><?php echo esc_html( $jbli_cta_label ); ?></a>
		<?php
	}
	?>
</div>
