<?php
/**
 * Partial: Flash notice.
 *
 * Expected vars: $jbli_type, $jbli_message (already kses'd)
 *
 * @package JobListings
 * @since   9.9.39
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="jbli_notice jbli_notice_<?php echo esc_attr( $jbli_type ); ?>"><?php echo $jbli_message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
