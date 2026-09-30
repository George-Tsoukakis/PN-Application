<?php
/**
 * Form partial: Consent checkbox
 *
 * Shown only on new jbli_listings (not on edits).
 * Expects: $jbli_is_edit (bool).
 *
 * @package JobListings
 * @since   9.9.23
 */

defined( 'ABSPATH' ) || exit;

if ( $jbli_is_edit ) { return; }

?>

<div class="jbli_form_consent">

	<label class="jbli_checkbox">

		<input type="checkbox" name="job_public_contact_consent" value="1" required aria-required="true">

		<span><?php esc_html_e( 'Συμφωνώ με τη χρήση των στοιχείων επικοινωνίας για την προβολή της αγγελίας.', 'job-listings' ); ?></span>

	</label>

</div>
