<?php
/**
 * Form partial: Location field
 *
 * Renders the pharmacy address input (with Google Maps autocomplete hook).
 * Expects: $jbli_fv (callable).
 *
 * @package JobListings
 * @since   9.9.23
 */

defined( 'ABSPATH' ) || exit;

$jbli_addr_optional = ! empty( $jbli_contact_optional );
?>

<div class="jbli_field jbli_field_address">

	<label for="job_address"><?php esc_html_e( 'Διεύθυνση Φαρμακείου', 'job-listings' ); ?><?php if ( ! $jbli_addr_optional ) { ?> <span class="jbli_required" aria-hidden="true">*</span><?php } ?></label>

	<div class="jbli_input_with_icon">
	<input type="text" id="job_address" name="job_address" value="<?php echo esc_attr( $jbli_fv( 'jbli_address' ) ); ?>" class="jbli_input" autocomplete="street-address" placeholder="<?php esc_attr_e( 'π.χ. Σταδίου 10, Αθήνα', 'job-listings' ); ?>"<?php if ( ! $jbli_addr_optional ) { ?> required aria-required="true"<?php } ?>>
	<button type="button" id="jbli_get_location_btn" class="jbli_current_location_btn" title="<?php esc_attr_e( 'Set current location', 'job-listings' ); ?>" aria-label="<?php esc_attr_e( 'Set current location', 'job-listings' ); ?>">
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<circle cx="12" cy="12" r="10"></circle>
				<circle cx="12" cy="12" r="3"></circle>
				<line x1="12" y1="1" x2="12" y2="3"></line>
				<line x1="12" y1="21" x2="12" y2="23"></line>
				<line x1="1" y1="12" x2="3" y2="12"></line>
				<line x1="21" y1="12" x2="23" y2="12"></line>
			</svg>
		</button>
	</div>

</div>

<?php  ?>
<input type="hidden" id="job_lat" name="job_lat" value="<?php echo esc_attr( $jbli_fv( 'lat' ) ); ?>">
<input type="hidden" id="job_lng" name="job_lng" value="<?php echo esc_attr( $jbli_fv( 'lng' ) ); ?>">
