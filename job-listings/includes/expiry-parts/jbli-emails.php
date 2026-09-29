<?php
/**
 * Expiry: Email Notifications
 *
 * Transactional emails sent when a listing expires or is about to expire.
 * All email content is here — change copy, layout, or delivery logic in
 * this single file without touching expiry logic elsewhere.
 *
 * Loaded by jbli-expiry.php.
 *
 * @package JobListings
 * @since   9.9.26
 */

defined( 'ABSPATH' ) || exit;

/**
 * Send the listing-expired notification to the pharmacy owner.
 *
 * @param int $jbli_post_id Listing post ID.
 */
function jbli_send_expiry_email( $jbli_post_id ) {

	$jbli_post_id = absint( $jbli_post_id );
	$jbli_data    = jbli_get_email_listing_data( $jbli_post_id );

	if ( empty( $jbli_data ) ) { return; }

	$jbli_body = jbli_email_heading( 'Η αγγελία σας έληξε' )
		. jbli_email_paragraph( 'Αγαπητέ/ή <strong>' . esc_html( $jbli_data['pharmacy'] ) . '</strong>,' )
		. jbli_email_paragraph( 'Η αγγελία σας για τη θέση <strong>«' . esc_html( $jbli_data['jbli_position'] ) . '»</strong> έχει λήξει.' )
		. jbli_email_button( 'Ανανέωση Αγγελίας', $jbli_data['renew_url'] );

	wp_mail(
		$jbli_data['jbli_email'],
		'[PharmacyNeeds] Η αγγελία σας έληξε',
		jbli_email_wrap( 'Η αγγελία σας έληξε', $jbli_body ),
		jbli_email_headers()
	);

}

/**
 * Send the 3-day expiry reminder to the pharmacy owner.
 *
 * @param int $jbli_post_id Listing post ID.
 * @return bool wp_mail() return value, or false if data is unavailable.
 */
function jbli_send_reminder_email( $jbli_post_id ) {

	$jbli_post_id = absint( $jbli_post_id );
	$jbli_data    = jbli_get_email_listing_data( $jbli_post_id );

	if ( empty( $jbli_data ) ) { return false; }

	$jbli_expires = get_post_meta( $jbli_post_id, JBLI_META_EXPIRES, true );
	$jbli_date    = '—';

	if ( $jbli_expires )
	{
		$jbli_timestamp = strtotime( (string) $jbli_expires );
		$jbli_date      = false !== $jbli_timestamp ? date_i18n( 'd/m/Y', $jbli_timestamp ) : '—';
	}

	$jbli_body = jbli_email_heading( 'Η αγγελία σας λήγει σε 3 μέρες' )
		. jbli_email_paragraph( 'Αγαπητέ/ή <strong>' . esc_html( $jbli_data['pharmacy'] ) . '</strong>,' )
		. jbli_email_paragraph( 'Η αγγελία σας για τη θέση <strong>«' . esc_html( $jbli_data['jbli_position'] ) . '»</strong> λήγει στις <strong>' . esc_html( $jbli_date ) . '</strong>.' )
		. jbli_email_paragraph( 'Μπορείτε να την ανανεώσετε από τον πίνακα ελέγχου σας.' )
		. jbli_email_button( 'Ανανέωση Αγγελίας', $jbli_data['renew_url'] );

	return wp_mail(
		$jbli_data['jbli_email'],
		'[PharmacyNeeds] Υπενθύμιση λήξης αγγελίας',
		jbli_email_wrap( 'Υπενθύμιση λήξης αγγελίας', $jbli_body ),
		jbli_email_headers()
	);

}

/**
 * Collect the common data needed for listing notification emails.
 *
 * Returns an empty array when data is missing or invalid, so callers
 * can bail safely with a simple `if ( empty( $jbli_data ) )` check.
 *
 * @param int $jbli_post_id Listing post ID.
 * @return array{email: string, pharmacy: string, position: string, renew_url: string}|array{}
 */
function jbli_get_email_listing_data( $jbli_post_id ) {

	$jbli_post_id = absint( $jbli_post_id );
	$post         = get_post( $jbli_post_id );

	if ( ! $post instanceof WP_Post || JBLI_CPT !== $post->post_type ) { return array(); }

	$jbli_user = get_userdata( (int) $post->post_author );

	if ( ! $jbli_user instanceof WP_User || ! is_email( $jbli_user->user_email ) ) { return array(); }

	$jbli_position = get_post_meta( $jbli_post_id, JBLI_META_POSITION, true );

	return array(
		'jbli_email'    => sanitize_email( $jbli_user->user_email ),
		'pharmacy'      => jbli_get_pharmacy_name( (int) $post->post_author ),
		'jbli_position' => $jbli_position ? (string) $jbli_position : get_the_title( $jbli_post_id ),
		'renew_url'     => function_exists( 'jbli_get_dashboard_url' )
			? jbli_get_dashboard_url()
			: home_url( '/dashboard/' ),
	);

}

/**
 * Return email headers (Content-Type + From).
 *
 * @return string[]
 */
function jbli_email_headers() {

	$jbli_host = wp_parse_url( home_url(), PHP_URL_HOST );

	if ( ! is_string( $jbli_host ) || '' === $jbli_host ) { $jbli_host = 'localhost'; }

	return array(
		'Content-Type: text/html; charset=UTF-8',
		'From: ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' <noreply@' . sanitize_text_field( $jbli_host ) . '>',
	);

}

/**
 * Return an email <h2> heading block.
 *
 * @param string $jbli_text Heading text (will be escaped).
 * @return string HTML.
 */
function jbli_email_heading( $jbli_text ) {

	return '<h2 style="margin:0 0 16px;color:#1a1a1a;font-size:22px;line-height:1.3;">'
		. esc_html( (string) $jbli_text )
		. '</h2>';

}

/**
 * Return an email <p> paragraph block.
 *
 * @param string $jbli_html Paragraph HTML (passed through wp_kses_post).
 * @return string HTML.
 */
function jbli_email_paragraph( $jbli_html ) {

	return '<p style="margin:0 0 14px;color:#444;font-size:15px;line-height:1.6;">'
		. wp_kses_post( (string) $jbli_html )
		. '</p>';

}

/**
 * Return an email CTA button.
 *
 * @param string $jbli_label Button label (will be escaped).
 * @param string $jbli_url   Button URL (will be escaped).
 * @return string HTML.
 */
function jbli_email_button( $jbli_label, $jbli_url ) {

	return '<table cellpadding="0" cellspacing="0" style="margin:24px 0 28px;">'
		. '<tr><td style="background:#0d7a3e;border-radius:6px;padding:12px 28px;">'
		. '<a href="' . esc_url( (string) $jbli_url ) . '" style="color:#ffffff;font-weight:700;font-size:15px;text-decoration:none;">'
		. esc_html( (string) $jbli_label )
		. '</a></td></tr></table>';

}

/**
 * Wrap email body HTML in a full responsive HTML email shell.
 *
 * @param string $jbli_title     Email <title> (will be escaped).
 * @param string $jbli_body_html Pre-built body HTML.
 * @return string Full HTML email document.
 */
function jbli_email_wrap( $jbli_title, $jbli_body_html ) {

	$jbli_site = esc_html( get_bloginfo( 'name' ) );
	$jbli_home = esc_url( home_url() );

	return '<!DOCTYPE html><html lang="el"><head>'
		. '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<title>' . esc_html( (string) $jbli_title ) . '</title>'
		. '</head>'
		. '<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif;">'
		. '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 0;">'
		. '<tr><td align="center">'
		. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;max-width:600px;width:100%;">'
		. '<tr><td style="background:#0d7a3e;padding:28px 32px;">'
		. '<a href="' . $jbli_home . '" style="text-decoration:none;color:#ffffff;font-size:22px;font-weight:700;">' . $jbli_site . '</a>'
		. '</td></tr>'
		. '<tr><td style="padding:32px;">' . wp_kses_post( (string) $jbli_body_html ) . '</td></tr>'
		. '<tr><td style="background:#f9f9f9;padding:20px 32px;border-top:1px solid #e8e8e8;text-align:center;">'
		. '<p style="margin:0;font-size:12px;color:#999;">Αυτό το email στάλθηκε από <a href="' . $jbli_home . '" style="color:#0d7a3e;">' . $jbli_site . '</a>.</p>'
		. '</td></tr>'
		. '</table>'
		. '</td></tr>'
		. '</table>'
		. '</body></html>';

}
