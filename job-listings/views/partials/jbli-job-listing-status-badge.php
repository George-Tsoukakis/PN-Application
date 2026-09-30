<?php
/**
 * Partial: Status badge.
 *
 * Expected vars: $jbli_class, $jbli_label
 *
 * @package JobListings
 * @since   9.9.39
 */

defined( 'ABSPATH' ) || exit;
?>
<span class="jbli_badge <?php echo esc_attr( $jbli_class ); ?>"><?php echo esc_html( $jbli_label ); ?></span>
