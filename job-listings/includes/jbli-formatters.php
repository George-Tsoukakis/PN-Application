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

				$jbli_k = sanitize_key( (string) $jbli_k );
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

				$jbli_k = sanitize_key( (string) $jbli_k );
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

	return isset( $jbli_options[ $jbli_value ] )
		? (string) $jbli_options[ $jbli_value ]
		: $jbli_value;

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
