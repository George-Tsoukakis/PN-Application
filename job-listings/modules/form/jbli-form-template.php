<?php
/**
 * Submission form template.
 *
 * Orchestrates the form layout by including focused partial templates.
 * Each partial is responsible for exactly one section.
 *
 * Partial layout:
 *   template-parts/jbli-header.php          — heading, subtitle, expired notice.
 *   template-parts/jbli-field-position.php  — job title input.
 *   template-parts/jbli-field-category.php  — category + nomos selects (grid).
 *   template-parts/jbli-field-contact.php   — phone + email fields.
 *   template-parts/jbli-field-salary-type.php — salary + employment-type selects.
 *   template-parts/jbli-field-location.php  — address input + hidden lat/lng.
 *   template-parts/jbli-field-description.php — description textarea.
 *   template-parts/jbli-field-consent.php   — consent checkbox (new only).
 *
 * Variables provided by jbli-form.php (the shortcode renderer):
 *   $jbli_edit_id    (int)      0 for new listings, post ID for edits.
 *   $jbli_is_expired (bool)     Whether the listing being edited has expired.
 *   $jbli_v          (array)    Pre-filled field values (edit mode).
 *   $jbli_back_url   (string)   URL to return to on cancel / after redirect.
 *   $jbli_phone      (string)   User's phone from profile meta.
 *   $jbli_mobile     (string)   User's mobile from profile meta.
 *   $jbli_user_email (string)   User's account email.
 *   $jbli_notice     (string)   Optional transient notice HTML to show at top.
 *
 * @package JobListings
 * @since   9.7.9
 */

defined( 'ABSPATH' ) || exit;

$jbli_is_edit = (bool) $jbli_edit_id;

/* 9.9.57: imported listings — phone, email and address are optional. */
$jbli_contact_optional = function_exists( 'jbli_listing_is_imported' ) && jbli_listing_is_imported( (int) $jbli_edit_id );
$jbli_req_attr         = $jbli_contact_optional ? '' : ' required aria-required="true"';
$jbli_req_mark         = $jbli_contact_optional ? '' : ' <span class="jbli_req" aria-hidden="true">*</span>';

$jbli_fv = static function ( string $jbli_key, string $jbli_default = '' ) use ( $jbli_v ): string {
	return isset( $jbli_v[ $jbli_key ] ) ? (string) $jbli_v[ $jbli_key ] : $jbli_default;
};

$jbli_selected_category = isset( $jbli_v['category'] ) ? (int) $jbli_v['category'] : 0;
$jbli_selected_nomos    = isset( $jbli_v['nomos'] )    ? (int) $jbli_v['nomos']    : 0;
$jbli_contact_email     = isset( $jbli_v['contact_email'] ) ? (string) $jbli_v['contact_email'] : '';

$jbli_partials = __DIR__ . '/template-parts/';
?>

<?php if ( ! empty( $jbli_notice ) ) { echo wp_kses_post( (string) $jbli_notice ); } ?>

<div class="jbli_form_wrap jbli_form_wrap_simple jbli_form_wrap_compact jbli_form_layout">

	<div class="jbli_form_main">

	<?php include $jbli_partials . 'jbli-header.php'; ?>

	<form
		method="post"
		action="<?php echo esc_url( $jbli_back_url ); ?>"
		class="jbli_form jbli_form_simple"
		id="jbli_submit_form"
		autocomplete="on"
		data-jbli-errors="<?php echo esc_attr( implode( ',', (array) ( $jbli_error_fields ?? array() ) ) ); ?>"
	>

		<?php  ?>
		<input type="hidden" name="action"      value="job_listing_submit">
		<input type="hidden" name="jbli_edit_id" value="<?php echo esc_attr( (string) $jbli_edit_id ); ?>">
		<input type="hidden" name="jbli_back_url" value="<?php echo esc_attr( $jbli_back_url ); ?>">
		<?php wp_nonce_field( 'jbli_submit', '_job_listing_nonce' ); ?>

		<div class="jbli_form_card">

			<?php include $jbli_partials . 'jbli-field-position.php'; ?>

			<?php include $jbli_partials . 'jbli-field-category.php'; ?>

			<?php include $jbli_partials . 'jbli-field-contact.php'; ?>

			<?php include $jbli_partials . 'jbli-field-salary-type.php'; ?>

			<?php include $jbli_partials . 'jbli-field-location.php'; ?>

			<?php include $jbli_partials . 'jbli-field-description.php'; ?>

			<?php include $jbli_partials . 'jbli-field-consent.php'; ?>

			<div class="jbli_form_footer">
				<button
					type="submit"
					class="jbli_btn jbli_btn_primary jbli_btn_lg"
					id="jbli_submit_btn"
					data-loading-text="<?php esc_attr_e( 'Αποθήκευση…', 'job-listings' ); ?>"
				>
					<?php echo esc_html( $jbli_is_edit ? ( $jbli_is_expired ? __( 'Αποθήκευση αλλαγών', 'job-listings' ) : __( 'Αποθήκευση αγγελίας', 'job-listings' ) ) : __( 'Δημοσίευση αγγελίας', 'job-listings' ) ); ?>
				</button>
			</div>

		</div>

	</form>

	</div>

	<?php include $jbli_partials . 'jbli-form-media.php'; ?>

</div>
