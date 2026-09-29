<?php
/**
 * Form partial: side media panel (image or video next to the form).
 *
 * The media comes from Ρυθμίσεις → «Εικόνα / Video φόρμας» (option
 * 'jbli_form_media_url'). Supported: an image URL, an .mp4/.webm video,
 * or a YouTube/Vimeo link. With nothing set, a built-in illustrated panel
 * is shown. The benefits card is always overlaid at the bottom.
 *
 * @package JobListings
 * @since   9.9.45
 */

defined( 'ABSPATH' ) || exit;

$jbli_media_url = (string) apply_filters( 'jbli_form_media_url', (string) get_option( 'jbli_form_media_url', '' ) );

$jbli_benefits = array(
	__( 'Δωρεάν δημοσίευση', 'job-listings' ),
	__( 'Ενεργή για 30 ημέρες', 'job-listings' ),
	__( 'Οι υποψήφιοι σας στέλνουν απευθείας', 'job-listings' ),
);
?>

<aside class="jbli_form_aside" aria-hidden="true">
	<?php
		echo jbli_media_panel_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside.
			$jbli_media_url,
			array(
				'kicker' => __( 'PharmacyNeeds', 'job-listings' ),
				'title'  => __( 'Βρείτε τον κατάλληλο συνεργάτη για το φαρμακείο σας', 'job-listings' ),
				'items'  => $jbli_benefits,
			)
		);
	?>
</aside>
