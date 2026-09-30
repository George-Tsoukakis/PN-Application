<?php
/**
 * Formatters
 *
 * Label, badge, notice, and display formatting helpers.
 * Extracted from jbli-helpers.php for maintainability.
 *
 * @package JobListings
 * @since   9.9.17
 */

defined( 'ABSPATH' ) || exit;

/**
 * Built-in (fallback) salary options.
 * These are used when no custom options have been saved in the admin.
 *
 * @return array<string,string>
 */
function jbli_salary_options_default(): array {

	return array(
		'negotiable' => __( 'Διαπραγματεύσιμος', 'job-listings' ),
		'700-900'    => '700€ – 900€',
		'900-1100'   => '900€ – 1.100€',
		'1100-1400'  => '1.100€ – 1.400€',
		'1400-1700'  => '1.400€ – 1.700€',
		'1700-2200'  => '1.700€ – 2.200€',
		'2200+'      => '2.200€+',
	);

}

/**
 * Salary options — merged from DB (admin-editable) + built-in fallback.
 *
 * Format stored in DB: JSON object  {"slug":"Label", ...}
 * Falls back to built-in defaults when the DB option is empty or invalid.
 *
 * @return array<string,string>
 */
function jbli_salary_options(): array {

	$jbli_raw = get_option( 'jbli_salary_options', '' );

	if ( $jbli_raw && is_string( $jbli_raw ) )
	{
		$jbli_decoded = json_decode( $jbli_raw, true );

		if ( is_array( $jbli_decoded ) && ! empty( $jbli_decoded ) )
		{
			$jbli_clean = array();

			foreach ( $jbli_decoded as $jbli_k => $jbli_v ) {

				$jbli_k = jbli_sanitize_option_key( $jbli_k );
				$jbli_v = sanitize_text_field( (string) $jbli_v );

				if ( $jbli_k !== '' && $jbli_v !== '' ) { $jbli_clean[ $jbli_k ] = $jbli_v; }

			}

			if ( ! empty( $jbli_clean ) ) { return $jbli_clean; }
		}
	}

	return jbli_salary_options_default();

}

/**
 * Built-in (fallback) job type options.
 *
 * @return array<string,string>
 */
function jbli_type_options_default(): array {

	return array(
		'plires-apasxolisi'    => __( 'Πλήρης Απασχόληση', 'job-listings' ),
		'meriki-apasxolisi'    => __( 'Μερική Απασχόληση', 'job-listings' ),
		'epoximaki-apasxolisi' => __( 'Εποχιακή Απασχόληση', 'job-listings' ),
	);

}

/**
 * Job type options — merged from DB (admin-editable) + built-in fallback.
 *
 * Format stored in DB: JSON object  {"slug":"Label", ...}
 * The slug is also used as the schema.org employmentType map key in
 * jbli-single-template.php, so keep existing slugs intact when editing.
 *
 * @return array<string,string>
 */
function jbli_type_options(): array {

	$jbli_raw = get_option( 'jbli_type_options', '' );

	if ( $jbli_raw && is_string( $jbli_raw ) )
	{
		$jbli_decoded = json_decode( $jbli_raw, true );

		if ( is_array( $jbli_decoded ) && ! empty( $jbli_decoded ) )
		{
			$jbli_clean = array();

			foreach ( $jbli_decoded as $jbli_k => $jbli_v ) {

				$jbli_k = jbli_sanitize_option_key( $jbli_k );
				$jbli_v = sanitize_text_field( (string) $jbli_v );

				if ( $jbli_k !== '' && $jbli_v !== '' ) { $jbli_clean[ $jbli_k ] = $jbli_v; }

			}

			if ( ! empty( $jbli_clean ) ) { return $jbli_clean; }
		}
	}

	return jbli_type_options_default();

}

function jbli_option_label( $jbli_value, $jbli_options ) {

	$jbli_value   = trim( (string) $jbli_value );
	$jbli_options = is_array( $jbli_options ) ? $jbli_options : array();

	if ( isset( $jbli_options[ $jbli_value ] ) ) { return (string) $jbli_options[ $jbli_value ]; }

	/* Options saved before 9.9.57 lost the "+" of "2200+"; still show a label. */
	$jbli_plain = str_replace( '+', '', $jbli_value );

	if ( $jbli_plain !== $jbli_value && isset( $jbli_options[ $jbli_plain ] ) ) { return (string) $jbli_options[ $jbli_plain ]; }

	return $jbli_value;

}

function jbli_salary_label( $jbli_value ) {

	return jbli_option_label( $jbli_value, jbli_salary_options() );

}

function jbli_type_label( $jbli_value ) {

	return jbli_option_label( $jbli_value, jbli_type_options() );

}

function jbli_status_badge( $jbli_post ) {

	return \JobListings\Formatters\Html::jbli_status_badge( $jbli_post );

}

function jbli_notice( $jbli_message, $jbli_type = 'success' ) {

	return \JobListings\Formatters\Html::jbli_notice( $jbli_message, $jbli_type );

}

function jbli_notice_allowed_html() {

	return array(
		'a'      => array( 'href'   => array(), 'target' => array(), 'rel'    => array(), ),
		'strong' => array(),
		'em'     => array(),
		'br'     => array(),
	);

}

function jbli_allowed_html() {

	return array(
		'p'      => array(),
		'br'     => array(),
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
		'u'      => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'h3'     => array(),
		'h4'     => array(),
		'h5'     => array(),
		'a'      => array(
			'href'        => array(),
			'title'       => array(),
			'target'      => array(),
			'rel'         => array(),
		),
		'span'   => array( 'class' => array(), ),
	);

}

/**
 * Pharmacy name for display after a "Φαρμακείο:" label.
 *
 * Strips a leading "Φαρμακείο" from the stored name so the label is not
 * doubled ("Φαρμακείο: Φαρμακείο Παπαδόπουλος" → "Φαρμακείο: Παπαδόπουλος").
 *
 * @since 9.9.51
 * @param string $jbli_name Stored pharmacy name.
 * @return string
 */
function jbli_pharmacy_display_name( $jbli_name ) {

	$jbli_name     = trim( (string) $jbli_name );
	$jbli_stripped = trim( (string) preg_replace( '/^φαρμακε[ιί]ο\s*[:\-–—]?\s*/iu', '', $jbli_name ) );

	return '' !== $jbli_stripped ? $jbli_stripped : $jbli_name;

}

/**
 * Listing description for the single page.
 *
 * Not the_content: the text is written by pharmacies, and the_content would
 * run any [shortcode] typed into it (forms, dashboards, other plugins'
 * shortcodes) on the public page. Shortcodes are removed; the rest gets
 * the same formatting (paragraphs, typography) through the plugin's HTML
 * allow-list.
 *
 * @since 9.9.57
 *
 * @param string $jbli_content Raw post_content.
 * @return string Safe HTML.
 */
function jbli_single_description_html( $jbli_content ) {

	$jbli_content = strip_shortcodes( (string) $jbli_content );
	$jbli_content = wp_kses( $jbli_content, jbli_allowed_html() );

	return wpautop( wptexturize( $jbli_content ) );

}

/**
 * Google Maps link for a listing's address (no API key, just a link).
 *
 * Uses the saved coordinates when the address was picked from Google's
 * suggestions, so the pin is exact; otherwise searches for the address with
 * the νομός and "Ελλάδα", so "Βύρωνας" finds Βύρωνας Αττικής.
 *
 * @since 9.9.63
 *
 * @param string   $jbli_address Street / area as typed.
 * @param string[] $jbli_nomoi   νομός names.
 * @param string   $jbli_lat     Latitude ('' if none).
 * @param string   $jbli_lng     Longitude ('' if none).
 * @return string URL, or '' without an address.
 */
function jbli_google_maps_url( $jbli_address, array $jbli_nomoi = array(), $jbli_lat = '', $jbli_lng = '' ) {

	$jbli_address = trim( (string) $jbli_address );

	if ( '' === $jbli_address ) { return ''; }

	$jbli_lat = trim( (string) $jbli_lat );
	$jbli_lng = trim( (string) $jbli_lng );

	if ( is_numeric( $jbli_lat ) && is_numeric( $jbli_lng ) && ( 0.0 !== (float) $jbli_lat || 0.0 !== (float) $jbli_lng ) )
	{
		$jbli_query = (float) $jbli_lat . ',' . (float) $jbli_lng;
	}
	else
	{
		$jbli_parts = array_merge( array( $jbli_address ), array_values( array_filter( array_map( 'strval', $jbli_nomoi ) ) ) );
		$jbli_parts[] = 'Ελλάδα';
		$jbli_query   = implode( ', ', array_unique( $jbli_parts ) );
	}

	return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $jbli_query );

}
