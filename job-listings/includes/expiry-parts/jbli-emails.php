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

	$jbli_title = __( 'Η αγγελία σας έληξε', 'job-listings' );

	$jbli_body = jbli_email_heading( $jbli_title )
		/* translators: %s: pharmacy name */
		. jbli_email_paragraph( sprintf( __( 'Αγαπητέ/ή %s,', 'job-listings' ), '<strong>' . esc_html( $jbli_data['pharmacy'] ) . '</strong>' ) )
		/* translators: %s: job position */
		. jbli_email_paragraph( sprintf( __( 'Η αγγελία σας για τη θέση %s έχει λήξει.', 'job-listings' ), '<strong>«' . esc_html( $jbli_data['jbli_position'] ) . '»</strong>' ) )
		. jbli_email_button( __( 'Ανανέωση Αγγελίας', 'job-listings' ), $jbli_data['renew_url'] );

	wp_mail(
		$jbli_data['jbli_email'],
		jbli_email_subject_prefix() . ' ' . $jbli_title,
		jbli_email_wrap( $jbli_title, $jbli_body ),
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
		$jbli_timestamp = jbli_local_datetime_to_ts( $jbli_expires );
		$jbli_date      = false !== $jbli_timestamp ? wp_date( 'd/m/Y', $jbli_timestamp ) : '—';
	}

	$jbli_subject = __( 'Υπενθύμιση λήξης αγγελίας', 'job-listings' );

	$jbli_body = jbli_email_heading( __( 'Η αγγελία σας λήγει σε 3 μέρες', 'job-listings' ) )
		/* translators: %s: pharmacy name */
		. jbli_email_paragraph( sprintf( __( 'Αγαπητέ/ή %s,', 'job-listings' ), '<strong>' . esc_html( $jbli_data['pharmacy'] ) . '</strong>' ) )
		/* translators: 1: job position, 2: expiry date */
		. jbli_email_paragraph( sprintf( __( 'Η αγγελία σας για τη θέση %1$s λήγει στις %2$s.', 'job-listings' ), '<strong>«' . esc_html( $jbli_data['jbli_position'] ) . '»</strong>', '<strong>' . esc_html( $jbli_date ) . '</strong>' ) )
		. jbli_email_paragraph( __( 'Μπορείτε να την ανανεώσετε από τον πίνακα ελέγχου σας.', 'job-listings' ) )
		. jbli_email_button( __( 'Ανανέωση Αγγελίας', 'job-listings' ), $jbli_data['renew_url'] );

	return wp_mail(
		$jbli_data['jbli_email'],
		jbli_email_subject_prefix() . ' ' . $jbli_subject,
		jbli_email_wrap( $jbli_subject, $jbli_body ),
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
 * @since 9.9.51 Redesigned: brand header with tagline, optional preheader,
 *               card body, footer with links. Table layout + inline styles
 *               so it renders in Gmail, Outlook and phone mail apps.
 *
 * @param string $jbli_title     Email <title> (will be escaped).
 * @param string $jbli_body_html Pre-built body HTML.
 * @param string $jbli_preheader Optional inbox preview text.
 * @return string Full HTML email document.
 */
function jbli_email_wrap( $jbli_title, $jbli_body_html, $jbli_preheader = '' ) {

	$jbli_site = esc_html( get_bloginfo( 'name' ) );
	$jbli_home = esc_url( home_url( '/' ) );
	$jbli_dash = esc_url( function_exists( 'jbli_get_dashboard_url' ) ? jbli_get_dashboard_url() : home_url( '/dashboard/' ) );
	$jbli_year = esc_html( wp_date( 'Y' ) );

	return '<!DOCTYPE html><html lang="el"><head>'
		. '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<meta name="color-scheme" content="light">'
		. '<title>' . esc_html( (string) $jbli_title ) . '</title>'
		. '</head>'
		. '<body style="margin:0;padding:0;background:#eef5f2;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;">'
		. ( '' !== (string) $jbli_preheader
			? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . esc_html( (string) $jbli_preheader ) . '</div>'
			: '' )
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef5f2;padding:28px 12px;">'
		. '<tr><td align="center">'
		. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(2,44,34,.08);">'
		. '<tr><td style="background:#065f46;background-image:linear-gradient(135deg,#064e3b 0%,#047857 60%,#10b981 100%);padding:26px 32px;">'
		. '<a href="' . $jbli_home . '" style="text-decoration:none;color:#ffffff;font-size:22px;font-weight:800;letter-spacing:-.01em;">' . $jbli_site . '</a>'
		. '<div style="margin-top:4px;color:#a7f3d0;font-size:13px;">' . esc_html__( 'Αγγελίες εργασίας για φαρμακεία', 'job-listings' ) . '</div>'
		. '</td></tr>'
		. '<tr><td style="padding:30px 32px 26px;">' . wp_kses_post( (string) $jbli_body_html ) . '</td></tr>'
		. '<tr><td style="background:#f7faf9;padding:18px 32px;border-top:1px solid #e5efeb;text-align:center;">'
		. '<p style="margin:0 0 6px;font-size:12px;color:#6b7280;">'
		. '<a href="' . $jbli_home . '" style="color:#047857;text-decoration:none;font-weight:600;">' . $jbli_site . '</a>'
		. ' &nbsp;·&nbsp; <a href="' . $jbli_dash . '" style="color:#047857;text-decoration:none;font-weight:600;">' . esc_html__( 'Οι αγγελίες μου', 'job-listings' ) . '</a>'
		. '</p>'
		. '<p style="margin:0;font-size:11px;color:#9ca3af;">© ' . $jbli_year . ' ' . $jbli_site . '. ' . esc_html__( 'Αυτό το email στάλθηκε αυτόματα.', 'job-listings' ) . '</p>'
		. '</td></tr>'
		. '</table>'
		. '</td></tr>'
		. '</table>'
		. '</body></html>';

}

/**
 * Email section title (small uppercase label above a box).
 *
 * @since 9.9.51
 * @param string $jbli_text Text (escaped).
 * @return string
 */
function jbli_email_section_title( $jbli_text ) {

	/* 9.9.63: normal case (was uppercase); the title is written as it should read, e.g. «Στοιχεία Υποψηφίου». */
	return '<p style="margin:22px 0 8px;font-size:14px;font-weight:800;letter-spacing:0;color:#047857;">'
		. esc_html( (string) $jbli_text )
		. '</p>';

}

/**
 * Email key/value box.
 *
 * @since 9.9.51
 * @param array<int,array{0:string,1:string}> $jbli_rows Label (escaped) + value HTML (kses'd).
 * @return string
 */
function jbli_email_info_table( array $jbli_rows ) {

	$jbli_html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5efeb;border-radius:12px;border-collapse:separate;background:#fbfdfc;">';
	$jbli_last = count( $jbli_rows ) - 1;

	foreach ( array_values( $jbli_rows ) as $jbli_i => $jbli_row ) {

		$jbli_border = $jbli_i < $jbli_last ? 'border-bottom:1px solid #eef3f1;' : '';

		$jbli_html .= '<tr>'
			. '<td style="padding:11px 16px;' . $jbli_border . 'width:38%;font-size:13px;color:#6b7280;vertical-align:top;">' . esc_html( (string) $jbli_row[0] ) . '</td>'
			. '<td style="padding:11px 16px;' . $jbli_border . 'font-size:14px;color:#111827;font-weight:600;vertical-align:top;word-break:break-word;">' . wp_kses_post( (string) $jbli_row[1] ) . '</td>'
			. '</tr>';

	}

	return $jbli_html . '</table>';

}

/**
 * Row of email buttons.
 *
 * @since 9.9.51
 * @param array<int,array{0:string,1:string,2?:bool}> $jbli_buttons Label, URL, primary?
 * @return string
 */
function jbli_email_buttons( array $jbli_buttons ) {

	$jbli_cells = '';

	foreach ( $jbli_buttons as $jbli_button ) {

		$jbli_primary = ! empty( $jbli_button[2] );

		$jbli_cells .= '<td style="padding:0 8px 8px 0;">'
			. '<a href="' . esc_url( (string) $jbli_button[1], array( 'http', 'https', 'mailto', 'tel' ) ) . '" style="display:inline-block;padding:12px 20px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;'
			. ( $jbli_primary ? 'background:#047857;color:#ffffff;' : 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;' )
			. '">' . esc_html( (string) $jbli_button[0] ) . '</a>'
			. '</td>';

	}

	return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:14px 0 4px;"><tr>' . $jbli_cells . '</tr></table>';

}

/**
 * Subject prefix for the plugin's emails ("[PharmacyNeeds]").
 *
 * @since 9.9.60
 * @return string
 */
function jbli_email_subject_prefix() {

	return (string) apply_filters( 'jbli_email_subject_prefix', '[PharmacyNeeds]' );

}
