<?php
/**
 * Print settings (read side) and print geometry. Read by the shortcode on the
 * front end. An invalid stored value falls back to the default, never to a
 * smaller code or hidden fields; the admin sanitizers (where a missing
 * checkbox means '0') are a separate, write-side concern.
 *
 * @package QR_ReBuilder_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function qrrp_default_print_barcode_mm() {
	return 24;
}

/** Εύρος της ρύθμισης πλάτους (mm)· το διαβάζουν και τα min/max της φόρμας. */
function qrrp_print_barcode_mm_range() {
	return array(
		'min' => 12,
		'max' => 100,
	);
}

/**
 * Πάτωμα σαρωσιμότητας (mm): κάτω από αυτό ο κωδικός δεν σμικρύνεται ποτέ,
 * ξεχειλίζει ορατά. Φυσικός περιορισμός, ανεξάρτητος από το min του εύρους.
 *
 * Στα 14 mm, σύμβολα 18x18–32x32 (+4 modules quiet zone ανά πλευρά) δίνουν
 * X-dimension 0,54–0,35 mm, εντός GS1. Μεγαλύτερα payloads (π.χ. 48x48) πέφτουν
 * κάτω από 0,254 mm και η AJAX προειδοποιεί (qrrp_gs1_minimum_print_width_mm()).
 */
function qrrp_print_barcode_floor_mm() {
	return 14;
}

/**
 * Modules στο πλάτος της εικόνας (στήλες + quiet zone ανά πλευρά): ο σωστός
 * διαιρέτης για το X-dimension, αφού το CSS δίνει τα mm στην εικόνα.
 *
 * @return int 0 για άκυρα δεδομένα.
 */
function qrrp_total_print_modules( $symbol_cols, $quiet_zone ) {
	$cols  = is_numeric( $symbol_cols ) ? (int) $symbol_cols : 0;
	$quiet = is_numeric( $quiet_zone ) ? (int) $quiet_zone : -1;

	if ( $cols < 1 || $quiet < 0 ) {
		return 0;
	}

	return $cols + ( 2 * $quiet );
}

/** Πλάτος ενός module στο χαρτί (mm), ή null (όχι 0) όταν δεν υπολογίζεται. */
function qrrp_x_dimension_mm( $printed_width_mm, $total_modules ) {
	$width   = is_numeric( $printed_width_mm ) ? (float) $printed_width_mm : 0.0;
	$modules = is_numeric( $total_modules ) ? (int) $total_modules : 0;

	if ( $width <= 0.0 || $modules < 1 ) {
		return null;
	}

	return $width / $modules;
}

/** Ελάχιστο X-dimension της GS1 για ρυθμιζόμενα προϊόντα υγείας (mm). */
function qrrp_gs1_minimum_x_dimension_mm() {
	return 0.254;
}

/**
 * Ελάχιστο πλάτος εκτύπωσης (mm) ώστε αυτή η γεωμετρία να μείνει εντός GS1,
 * ή null χωρίς γεωμετρία. Εξαρτάται από το περιεχόμενο.
 */
function qrrp_gs1_minimum_print_width_mm( $total_modules ) {
	$modules = is_numeric( $total_modules ) ? (int) $total_modules : 0;

	if ( $modules < 1 ) {
		return null;
	}

	/* ceil (ελάχιστο)· το round( …, 12 ) αφαιρεί δυαδικό θόρυβο (0.254 × 38 × 1000 = 9652.000000000002). */
	$required_mm = round( qrrp_gs1_minimum_x_dimension_mm() * $modules, 12 );

	return ceil( $required_mm * 1000 ) / 1000;
}

/**
 * Έγκυρο πλάτος: θετικός ακέραιος, μόνο ψηφία. Η μορφή ελέγχεται πριν από
 * cast ((int) 'abc' = 0 θα γινόταν σιωπηλά 12 mm). Εκτός εύρους = έγκυρο, clamp.
 */
function qrrp_print_barcode_mm_is_valid( $value ) {
	if ( ! is_scalar( $value ) ) {
		return false;
	}

	$raw = trim( (string) $value );

	if ( 1 !== preg_match( '/^[0-9]+$/', $raw ) ) {
		return false;
	}

	return 0 !== (int) $raw;
}

function qrrp_clamp_print_barcode_mm( $mm ) {
	$range = qrrp_print_barcode_mm_range();

	return min( $range['max'], max( $range['min'], (int) $mm ) );
}

function qrrp_print_barcode_mm() {
	$stored = get_option( 'qrrp_print_barcode_mm', qrrp_default_print_barcode_mm() );

	if ( ! qrrp_print_barcode_mm_is_valid( $stored ) ) {
		return qrrp_default_print_barcode_mm();
	}

	return qrrp_clamp_print_barcode_mm( (int) trim( (string) $stored ) );
}

function qrrp_default_print_orientation() {
	return 'landscape';
}

function qrrp_allowed_print_orientations() {
	return array( 'landscape', 'portrait', 'auto' );
}

/** Έγκυρος προσανατολισμός ('AUTO' = 'auto'); το κενό και τα μη-ASCII είναι άκυρα. */
function qrrp_print_orientation_is_valid( $value ) {
	if ( ! is_scalar( $value ) ) {
		return false;
	}

	$raw = trim( (string) $value );

	if ( '' === $raw ) {
		return false;
	}

	return in_array( sanitize_key( $raw ), qrrp_allowed_print_orientations(), true );
}

function qrrp_print_orientation() {
	$stored = get_option( 'qrrp_print_orientation', qrrp_default_print_orientation() );

	if ( ! qrrp_print_orientation_is_valid( $stored ) ) {
		return qrrp_default_print_orientation();
	}

	return sanitize_key( trim( (string) $stored ) );
}

function qrrp_default_print_toggle() {
	return '1';
}

/**
 * Διακόπτης εκτύπωσης ως string '1'/'0' (όχι bool: η JS συγκρίνει με '0' μετά
 * το wp_localize_script()). Δέχεται τιμή, ώστε τα ονόματα options να μένουν
 * κυριολεκτικά στους καλούντες.
 */
function qrrp_print_toggle_value( $stored ) {
	if ( ! is_scalar( $stored ) ) {
		return qrrp_default_print_toggle();
	}

	$raw = trim( (string) $stored );

	if ( '0' === $raw ) {
		return '0';
	}

	if ( '1' === $raw ) {
		return '1';
	}

	return qrrp_default_print_toggle();
}

/** Εμφάνιση του σχολίου κάτω από τη γραμμή GS1. */
function qrrp_print_show_note() {
	return qrrp_print_toggle_value( get_option( 'qrrp_print_show_note', qrrp_default_print_toggle() ) );
}

/** Εμφάνιση των πεδίων PC / SN / LOT / EXP δίπλα στον κωδικό. */
function qrrp_print_show_meta() {
	return qrrp_print_toggle_value( get_option( 'qrrp_print_show_meta', qrrp_default_print_toggle() ) );
}
