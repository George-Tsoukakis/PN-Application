<?php

namespace JobListings\Formatters;

use JobListings\Support\View;

defined( 'ABSPATH' ) || exit;

/**
 * Badge / notice HTML builders.
 */
final class Html {

	/**
	 * Status badge for a listing post.
	 *
	 * @param \WP_Post $jbli_post Listing post.
	 * @return string
	 */
	public static function jbli_status_badge( $jbli_post ) {

		if ( ! $jbli_post instanceof \WP_Post ) { return ''; }

		$jbli_expired_meta = defined( 'JBLI_META_EXPIRED' ) ? JBLI_META_EXPIRED : 'jbli_expired';

		if ( get_post_meta( $jbli_post->ID, $jbli_expired_meta, true ) )
		{
			return View::jbli_make()->jbli_render(
				'partials.status-badge',
				array( 'jbli_class' => 'jbli_badge_red', 'jbli_label' => __( 'Έληξε', 'job-listings' ), )
			);
		}

		$jbli_statuses = array(
			'publish'     => array( 'jbli_badge_green', __( 'Ενεργή', 'job-listings' ) ),
			'job-expired' => array( 'jbli_badge_red', __( 'Έληξε', 'job-listings' ) ),
			'draft'       => array( 'jbli_badge_gray', __( 'Ανενεργή', 'job-listings' ) ),
			'pending'     => array( 'jbli_badge_yellow', __( 'Σε αναμονή', 'job-listings' ) ),
			'private'     => array( 'jbli_badge_gray', __( 'Ιδιωτική', 'job-listings' ) ),
			'trash'       => array( 'jbli_badge_gray', __( 'Διαγραμμένη', 'job-listings' ) ),
		);

		$jbli_status = isset( $jbli_statuses[ $jbli_post->post_status ] )
			? $jbli_statuses[ $jbli_post->post_status ]
			: array( 'jbli_badge_gray', $jbli_post->post_status );

		return View::jbli_make()->jbli_render(
			'partials.status-badge',
			array( 'jbli_class' => $jbli_status[0], 'jbli_label' => $jbli_status[1], )
		);

	}

	/**
	 * Flash notice block.
	 *
	 * @param string $jbli_message Notice text (may contain allowed HTML).
	 * @param string $jbli_type    success|error|warning|info.
	 * @return string
	 */
	public static function jbli_notice( $jbli_message, $jbli_type = 'success' ) {

		$jbli_message = (string) $jbli_message;
		$jbli_type    = (string) $jbli_type;

		$jbli_allowed_types = array( 'success', 'error', 'warning', 'info' );

		if ( ! in_array( $jbli_type, $jbli_allowed_types, true ) ) { $jbli_type = 'info'; }

		$jbli_allowed_html = function_exists( 'jbli_notice_allowed_html' )
			? jbli_notice_allowed_html()
			: array();

		return View::jbli_make()->jbli_render(
			'partials.notice',
			array( 'jbli_type' => $jbli_type, 'jbli_message' => wp_kses( $jbli_message, $jbli_allowed_html ), )
		);

	}

	/**
	 * Days-left expiry badge.
	 *
	 * @param int $jbli_days Days remaining (negative = expired).
	 * @return string
	 */
	public static function jbli_expiry_badge( $jbli_days ) {

		$jbli_days = (int) $jbli_days;

		if ( $jbli_days < 0 )
		{
			return View::jbli_make()->jbli_render(
				'partials.expiry-badge',
				array( 'jbli_modifier' => 'over', 'jbli_label' => __( 'Έληξε', 'job-listings' ), )
			);
		}

		if ( 0 === $jbli_days )
		{
			return View::jbli_make()->jbli_render(
				'partials.expiry-badge',
				array( 'jbli_modifier' => 'today', 'jbli_label' => __( 'Λήγει σήμερα', 'job-listings' ), )
			);
		}

		return View::jbli_make()->jbli_render(
			'partials.expiry-badge',
			array(
				'jbli_modifier' => $jbli_days <= 5 ? 'soon' : '',
				'jbli_label'    => sprintf(
					/* translators: %d: number of days */
					__( 'Λήγει σε %d μέρες', 'job-listings' ),
					$jbli_days
				),
			)
		);

	}

	/**
	 * Login / register gate block.
	 *
	 * @param string $jbli_heading  Heading text.
	 * @param string $jbli_subtitle Supporting text.
	 * @param string   $jbli_icon_svg Optional SVG markup.
	 * @param string[] $jbli_benefits Optional bullet points (9.9.61).
	 * @return string
	 */
	public static function jbli_login_gate( $jbli_heading, $jbli_subtitle, $jbli_icon_svg = '', array $jbli_benefits = array() ) {

		$jbli_login_url    = esc_url( wp_login_url( get_permalink() ?: home_url( '/' ) ) );
		$jbli_register_url = esc_url( wp_registration_url() );

		if ( '' === $jbli_icon_svg )
		{
			$jbli_icon_svg = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
		}

		/* 9.9.61: the same media as next to the listing form (Ρυθμίσεις → «Φόρμα νέας αγγελίας»), or the built-in illustration. */
		$jbli_media_html = '';

		if ( function_exists( 'jbli_media_panel_html' ) )
		{
			$jbli_media_html = jbli_media_panel_html(
				(string) apply_filters( 'jbli_login_gate_media_url', (string) get_option( 'jbli_form_media_url', '' ) ),
				array(
					'kicker' => __( 'PharmacyNeeds', 'job-listings' ),
					'title'  => __( 'Αγγελίες εργασίας για φαρμακεία σε όλη την Ελλάδα', 'job-listings' ),
				),
				'jbli_login_gate_panel'
			);
		}

		$jbli_listings_url = function_exists( 'jbli_recent_get_listings_page_url' ) ? (string) jbli_recent_get_listings_page_url() : '';

		$jbli_login_icon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>';

		return View::jbli_make()->jbli_render(
			'partials.login-gate',
			array(
				'jbli_heading'      => (string) $jbli_heading,
				'jbli_subtitle'     => (string) $jbli_subtitle,
				'jbli_icon_svg'     => $jbli_icon_svg,
				'jbli_login_url'    => $jbli_login_url,
				'jbli_register_url' => $jbli_register_url,
				'jbli_login_icon'   => $jbli_login_icon,
				'jbli_benefits'     => array_map( 'strval', $jbli_benefits ),
				'jbli_listings_url' => $jbli_listings_url,
				'jbli_media_html'   => $jbli_media_html,
			)
		);

	}

	/**
	 * Empty listings state.
	 *
	 * @param string|null $jbli_message Override message.
	 * @return string
	 */
	public static function jbli_empty_listings( $jbli_message = null ) {

		if ( null === $jbli_message )
		{
			$jbli_message = __( 'Δεν βρέθηκαν αγγελίες με αυτά τα κριτήρια.', 'job-listings' );
		}

		return View::jbli_make()->jbli_render(
			'partials.empty-listings',
			array( 'jbli_message' => (string) $jbli_message, )
		);

	}

}
