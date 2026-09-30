<?php
/**
 * Partial: Expiry days-left badge.
 *
 * Expected vars: $jbli_modifier (over|today|soon|''), $jbli_label
 *
 * @package JobListings
 * @since   9.9.39
 */

defined( 'ABSPATH' ) || exit;

$jbli_modifier = isset( $jbli_modifier ) ? (string) $jbli_modifier : '';
$jbli_class    = 'jbli_expiry' . ( '' !== $jbli_modifier ? ' jbli_expiry_' . sanitize_html_class( $jbli_modifier ) : '' );
?>
<span class="<?php echo esc_attr( $jbli_class ); ?>"><?php echo esc_html( $jbli_label ); ?></span>
