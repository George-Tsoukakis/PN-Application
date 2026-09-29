<?php
/**
 * Admin Panel: Action Buttons
 *
 * Generates the HTML for single-action forms used in the listing table.
 * Each button is a self-contained <form> with a nonce and hidden fields.
 * Loaded by jbli-admin-panel.php.
 *
 * @package JobListings
 * @since   9.9.24
 */

defined( 'ABSPATH' ) || exit;

/**
 * Allowed admin actions — single source of truth shared with jbli-actions.php.
 *
 * @return string[]
 */
function jbli_admin_allowed_actions(): array {

	return array( 'approve', 'jbli_deactivate', 'renew', 'delete', 'backfill_storage', 'save_settings' );

}

/**
 * Build a self-contained action button form.
 *
 * Each button submits a single POST action to the current admin page.
 * The nonce is scoped to action + post_id so forged cross-action requests
 * are rejected even when the nonce itself is valid.
 *
 * @param int    $jbli_post_id      Listing post ID (0 for post-agnostic actions).
 * @param string $jbli_action       One of jbli_admin_allowed_actions().
 * @param string $jbli_label        Visible button text.
 * @param string $jbli_css          Extra CSS class(es) for the <button>.
 * @param array  $jbli_extra_fields Additional hidden input fields (name => value).
 * @return string HTML string, or empty string for unknown actions.
 */
function jbli_admin_action_btn(
	int $jbli_post_id,
	string $jbli_action,
	string $jbli_label,
	string $jbli_css = '',
	array $jbli_extra_fields = array()
): string {

	if ( ! in_array( $jbli_action, jbli_admin_allowed_actions( ), true ) ) { return ''; }

	$jbli_nonce   = wp_create_nonce( 'jbli_admin_' . $jbli_action . '_' . $jbli_post_id );
	$jbli_confirm = '';

	if ( 'delete' === $jbli_action )
	{
		$jbli_confirm = ' data-confirm="' . esc_attr__( 'Να διαγραφεί η αγγελία; Θα μεταφερθεί στον κάδο.', 'job-listings' ) . '"';
	}

	$jbli_hidden = '';

	foreach ( $jbli_extra_fields as $jbli_name => $jbli_value ) {

		$jbli_hidden .= '<input type="hidden" name="' . esc_attr( $jbli_name ) . '" value="' . esc_attr( (string) $jbli_value ) . '">';

	}

	return
		'<form method="post" action="' . esc_url( admin_url( 'admin.php?page=jbli_admin_panel' ) ) . '" class="jbli_ap_form">'
		. '<input type="hidden" name="jbli_admin_action" value="' . esc_attr( $jbli_action ) . '">'
		. '<input type="hidden" name="job_post_id" value="' . esc_attr( (string) $jbli_post_id ) . '">'
		. '<input type="hidden" name="_job_listing_admin_nonce" value="' . esc_attr( $jbli_nonce ) . '">'
		. $jbli_hidden
		. '<button type="submit" class="button ' . esc_attr( $jbli_css ) . '"' . $jbli_confirm . '>'
		. esc_html( $jbli_label )
		. '</button>'
		. '</form>';
}
