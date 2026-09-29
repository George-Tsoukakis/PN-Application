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
$jbli_media_url = esc_url_raw( trim( $jbli_media_url ) );
$jbli_media     = function_exists( 'jbli_form_media_type' ) ? jbli_form_media_type( $jbli_media_url ) : array( 'type' => '' );

$jbli_benefits = array(
	__( 'Δωρεάν δημοσίευση', 'job-listings' ),
	__( 'Ενεργή για 30 ημέρες', 'job-listings' ),
	__( 'Οι υποψήφιοι σας στέλνουν απευθείας', 'job-listings' ),
);
?>

<aside class="jbli_form_aside" aria-hidden="true">

	<div class="jbli_form_media jbli_form_media_<?php echo esc_attr( '' !== $jbli_media['type'] ? $jbli_media['type'] : 'default' ); ?>">

		<?php if ( 'image' === $jbli_media['type'] ) { ?>

			<img class="jbli_form_media_el" src="<?php echo esc_url( $jbli_media_url ); ?>" alt="" loading="lazy" decoding="async">

		<?php } elseif ( 'video' === $jbli_media['type'] ) { ?>

			<video class="jbli_form_media_el" src="<?php echo esc_url( $jbli_media_url ); ?>" autoplay muted loop playsinline preload="metadata"></video>

		<?php } elseif ( 'embed' === $jbli_media['type'] ) { ?>

			<iframe class="jbli_form_media_el" src="<?php echo esc_url( $jbli_media['embed'] ); ?>" title="" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture" tabindex="-1"></iframe>

		<?php } else { ?>

			<div class="jbli_form_media_art">
				<svg viewBox="0 0 320 260" width="100%" height="100%" fill="none" preserveAspectRatio="xMidYMid meet">
					<circle cx="250" cy="52" r="70" fill="rgba(255,255,255,.08)"/>
					<circle cx="54" cy="214" r="90" fill="rgba(255,255,255,.06)"/>
					<rect x="92" y="62" width="136" height="150" rx="22" fill="rgba(255,255,255,.14)" stroke="rgba(255,255,255,.4)" stroke-width="2"/>
					<rect x="130" y="44" width="60" height="30" rx="10" fill="rgba(255,255,255,.22)" stroke="rgba(255,255,255,.45)" stroke-width="2"/>
					<path d="M160 104v48M136 128h48" stroke="#fff" stroke-width="12" stroke-linecap="round"/>
					<rect x="116" y="170" width="88" height="8" rx="4" fill="rgba(255,255,255,.45)"/>
					<rect x="130" y="186" width="60" height="8" rx="4" fill="rgba(255,255,255,.3)"/>
					<circle cx="236" cy="176" r="26" fill="#34d399" stroke="#fff" stroke-width="3"/>
					<path d="m224 176 8 8 16-16" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</div>

		<?php } ?>

		<div class="jbli_form_media_shade"></div>

		<div class="jbli_form_media_card">
			<p class="jbli_form_media_kicker"><?php esc_html_e( 'PharmacyNeeds', 'job-listings' ); ?></p>
			<p class="jbli_form_media_title"><?php esc_html_e( 'Βρείτε τον κατάλληλο συνεργάτη για το φαρμακείο σας', 'job-listings' ); ?></p>
			<ul class="jbli_form_media_list">
				<?php foreach ( $jbli_benefits as $jbli_benefit ) { ?>
					<li><?php echo esc_html( $jbli_benefit ); ?></li>
				<?php } ?>
			</ul>
		</div>

	</div>

</aside>
