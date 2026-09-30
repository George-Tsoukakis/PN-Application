<?php
/**
 * Module: Express Interest (Εκδήλωση Ενδιαφέροντος)
 *
 * Button + modal για εκδήλωση ενδιαφέροντος σε αγγελία.
 *
 * Αρχιτεκτονική:
 *   - jbli_apply_render($jbli_id) → επιστρέφει ΜΟΝΟ το button HTML.
 *     Καταχωρεί το $jbli_post_id για footer output.
 *   - wp_footer (priority 20) → βγάζει όλα τα modals ΕΚΤΟΣ DOM καρτών.
 *     Έτσι το position:fixed λειτουργεί σωστά.
 *
 * @package JobListings
 * @since   9.9.39
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/jbli-apply-submissions.php';

function jbli_apply_default_subject(): string {

	return 'Νέο ενδιαφέρον για τη θέση «{position}» — {name}';

}

/**
 * Intro text of the email. The applicant and listing details are added
 * below it automatically (9.9.51), so they are no longer part of the text.
 */
function jbli_apply_default_body(): string {

	return "Γεια σας,\n\nΟ/Η {name} εκδήλωσε ενδιαφέρον για τη θέση «{position}» μέσω του PharmacyNeeds. Παρακάτω θα βρείτε τα στοιχεία επικοινωνίας του/της και τα στοιχεία της αγγελίας σας.";

}

/**
 * Defaults shipped before 9.9.51. A site that still has them saved gets the
 * new defaults (the old body repeated the details now shown in the boxes).
 *
 * @return array{subject:string,body:string}
 */
function jbli_apply_legacy_defaults(): array {

	return array(
		'subject' => 'Εκδήλωση Ενδιαφέροντος για τη θέση «{position}»',
		'body'    => "Αγαπητέ/ή {pharmacy},\n\nΈνας υποψήφιος εξέφρασε ενδιαφέρον για τη θέση «{position}».\n\nΣτοιχεία υποψηφίου:\n• Όνομα: {name}\n• Κινητό: {phone}\n• Email: {email}\n\nΑγγελία: {listing_url}\n\nΤο μήνυμα αυτό εστάλη αυτόματα από το PharmacyNeeds.",
	);

}

/**
 * Build the full HTML email the pharmacy receives.
 *
 * @since 9.9.51
 *
 * @param int    $jbli_post_id Listing ID.
 * @param array  $jbli_ph      Placeholder values (name, phone, email, position, pharmacy, listing_url).
 * @param string $jbli_subject Final subject.
 * @param string $jbli_intro   Final intro text (plain text).
 * @return string Full HTML document.
 */
function jbli_apply_email_html( int $jbli_post_id, array $jbli_ph, string $jbli_subject, string $jbli_intro ): string {

	$jbli_phone_href = 'tel:' . preg_replace( '/[^\d+]/', '', (string) $jbli_ph['phone'] );
	$jbli_mail_href  = 'mailto:' . rawurlencode( (string) $jbli_ph['email'] )
		. '?subject=' . rawurlencode( 'Σχετικά με τη θέση «' . $jbli_ph['position'] . '»' );

	$jbli_nomoi = wp_get_post_terms( $jbli_post_id, 'job_nomos', array( 'fields' => 'names' ) );
	$jbli_cats  = wp_get_post_terms( $jbli_post_id, 'job_category', array( 'fields' => 'names' ) );
	$jbli_type  = (string) get_post_meta( $jbli_post_id, JBLI_META_TYPE, true );
	$jbli_sal   = (string) get_post_meta( $jbli_post_id, JBLI_META_SALARY, true );
	$jbli_exp   = (string) get_post_meta( $jbli_post_id, JBLI_META_EXPIRES, true );

	$jbli_listing_rows = array(
		array( __( 'Θέση', 'job-listings' ), esc_html( $jbli_ph['position'] ) ),
		array( __( 'Φαρμακείο', 'job-listings' ), esc_html( $jbli_ph['pharmacy'] ) ),
	);

	if ( ! is_wp_error( $jbli_cats ) && $jbli_cats )   { $jbli_listing_rows[] = array( __( 'Κατηγορία', 'job-listings' ), esc_html( implode( ', ', $jbli_cats ) ) ); }
	if ( ! is_wp_error( $jbli_nomoi ) && $jbli_nomoi ) { $jbli_listing_rows[] = array( __( 'Νομός', 'job-listings' ), esc_html( implode( ', ', $jbli_nomoi ) ) ); }
	if ( $jbli_type ) { $jbli_listing_rows[] = array( __( 'Απασχόληση', 'job-listings' ), esc_html( jbli_type_label( $jbli_type ) ) ); }
	if ( $jbli_sal )  { $jbli_listing_rows[] = array( __( 'Αμοιβή', 'job-listings' ), esc_html( jbli_salary_label( $jbli_sal ) ) ); }
	if ( $jbli_exp && false !== jbli_local_datetime_to_ts( $jbli_exp ) ) { $jbli_listing_rows[] = array( __( 'Λήξη αγγελίας', 'job-listings' ), esc_html( wp_date( 'd/m/Y', jbli_local_datetime_to_ts( $jbli_exp ) ) ) ); }

	$jbli_body = '<h1 style="margin:0 0 6px;font-size:22px;line-height:1.3;color:#111827;">' . esc_html__( 'Νέο ενδιαφέρον για την αγγελία σας', 'job-listings' ) . '</h1>'
		. '<p style="margin:0 0 18px;font-size:14px;color:#6b7280;">'
		. esc_html( sprintf( 'Θέση: %s · %s', $jbli_ph['position'], wp_date( 'd/m/Y, H:i' ) ) )
		. '</p>'
		. '<div style="margin:0 0 6px;font-size:15px;line-height:1.65;color:#374151;">' . nl2br( esc_html( $jbli_intro ) ) . '</div>'

		. jbli_email_section_title( __( 'Στοιχεία υποψηφίου', 'job-listings' ) )
		. jbli_email_info_table( array(
			array( __( 'Ονοματεπώνυμο', 'job-listings' ), esc_html( $jbli_ph['name'] ) ),
			array( __( 'Κινητό', 'job-listings' ), '<a href="' . esc_url( $jbli_phone_href, array( 'tel' ) ) . '" style="color:#047857;text-decoration:none;">' . esc_html( $jbli_ph['phone'] ) . '</a>' ),
			array( __( 'Email', 'job-listings' ), '<a href="mailto:' . esc_attr( $jbli_ph['email'] ) . '" style="color:#047857;text-decoration:none;">' . esc_html( $jbli_ph['email'] ) . '</a>' ),
		) )
		. jbli_email_buttons( array(
			array( __( 'Καλέστε τον/την υποψήφιο/α', 'job-listings' ), $jbli_phone_href, true ),
			array( __( 'Απάντηση με email', 'job-listings' ), $jbli_mail_href ),
		) )

		. jbli_email_section_title( __( 'Η αγγελία σας', 'job-listings' ) )
		. jbli_email_info_table( $jbli_listing_rows )
		. jbli_email_buttons( array(
			array( __( 'Προβολή αγγελίας', 'job-listings' ), (string) $jbli_ph['listing_url'] ),
		) )

		. '<p style="margin:20px 0 0;padding:12px 14px;border-radius:10px;background:#f0f9ff;color:#1e3a8a;font-size:13px;line-height:1.5;">'
		. esc_html__( 'Συμβουλή: πατήστε «Απάντηση» σε αυτό το email για να γράψετε απευθείας στον/στην υποψήφιο/α.', 'job-listings' )
		. '</p>';

	return jbli_email_wrap(
		$jbli_subject,
		$jbli_body,
		sprintf( '%s — %s, %s', $jbli_ph['name'], $jbli_ph['phone'], $jbli_ph['email'] )
	);

}

function jbli_apply_replace_placeholders( string $jbli_text, array $jbli_data ): string {

	foreach ( $jbli_data as $jbli_key => $jbli_value ) {

		$jbli_text = str_replace( '{' . $jbli_key . '}', (string) $jbli_value, $jbli_text );

	}

	return $jbli_text;

}

function jbli_apply_check_rate_limit( int $jbli_post_id ): bool {

	/* 9.9.57: proxy-aware IP (Cloudflare etc.), same helper as the view counter. */
	$jbli_ip = function_exists( 'jbli_get_client_ip' )
		? jbli_get_client_ip()
		: sanitize_text_field( wp_unslash( (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) ) );

	$jbli_hash   = md5( wp_salt( 'nonce' ) . $jbli_ip );
	$jbli_key    = 'jbli_apply_' . $jbli_post_id . '_' . $jbli_hash;
	$jbli_global = 'jbli_apply_ip_' . $jbli_hash;

	$jbli_count        = (int) get_transient( $jbli_key );
	$jbli_global_count = (int) get_transient( $jbli_global );

	/* 3 per listing and 10 in total per hour from the same visitor. */
	$jbli_per_listing = (int) apply_filters( 'jbli_apply_limit_per_listing', 3 );
	$jbli_per_ip      = (int) apply_filters( 'jbli_apply_limit_per_ip', 10 );

	if ( $jbli_count >= $jbli_per_listing || $jbli_global_count >= $jbli_per_ip ) { return false; }

	set_transient( $jbli_key,    $jbli_count + 1,        HOUR_IN_SECONDS );
	set_transient( $jbli_global, $jbli_global_count + 1, HOUR_IN_SECONDS );
	return true;

}

/**
 * Honeypot: a hidden field people never see; bots that fill every input do.
 *
 * @since 9.9.57
 * @return bool
 */
function jbli_apply_honeypot_filled(): bool {

	$jbli_honeypot = isset( $_POST['jbli_hp_website'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['jbli_hp_website'] ) ) ) : '';

	return '' !== $jbli_honeypot;

}

/**
 * Sent faster than a person can (browser autofill included).
 *
 * Only judged when the script sent the timing, so a page still running the
 * pre-9.9.57 script (stale cache) is never blocked.
 *
 * @since 9.9.57
 * @return bool
 */
function jbli_apply_sent_too_fast(): bool {

	return isset( $_POST['jbli_elapsed'] ) && absint( $_POST['jbli_elapsed'] ) < 1500;

}

/**
 * Applicant name: letters, spaces, dots, apostrophes and hyphens — no links.
 *
 * @since 9.9.57
 * @param string $jbli_name Sanitized name.
 * @return bool
 */
function jbli_apply_valid_name( string $jbli_name ): bool {

	if ( function_exists( 'jbli_strlen' ) ? jbli_strlen( $jbli_name ) > 80 : strlen( $jbli_name ) > 160 ) { return false; }

	/* Domain-like text ("www.", "site.com") is a link, not a name. */
	if ( preg_match( '/www\.|\.[a-z]{2,6}(\s|$)/i', $jbli_name ) ) { return false; }

	return (bool) preg_match( "/^[\\p{L}][\\p{L}\\p{M} .'\\-]*$/u", $jbli_name );

}

function jbli_apply_handle_ajax(): void {

	$jbli_post_id = absint( $_POST['post_id'] ?? 0 );
	$jbli_nonce   = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );

	if ( ! $jbli_post_id || ! wp_verify_nonce( $jbli_nonce, 'jbli_apply_' . $jbli_post_id ) )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Μη έγκυρο αίτημα. Ανανεώστε τη σελίδα.', 'job-listings' ) ) );
	}

	$post = get_post( $jbli_post_id );

	if ( ! $post instanceof WP_Post || JBLI_CPT !== $post->post_type || 'publish' !== $post->post_status )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Η αγγελία δεν βρέθηκε.', 'job-listings' ) ) );
	}

	/* Bots get the normal "sent" answer, so they learn nothing; nothing is stored or mailed. */
	if ( jbli_apply_honeypot_filled() )
	{
		wp_send_json_success( array( 'jbli_message' => __( 'Το ενδιαφέρον σας στάλθηκε επιτυχώς!', 'job-listings' ) ) );
	}

	/* A person who was just very quick sees this and simply presses «Αποστολή» again. */
	if ( jbli_apply_sent_too_fast() )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Ελέγξτε τα στοιχεία σας και πατήστε ξανά «Αποστολή».', 'job-listings' ) ) );
	}

	$jbli_name  = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( wp_unslash( $_POST['applicant_name']  ?? '' ) ) ) );
	$jbli_phone = sanitize_text_field( wp_unslash( $_POST['applicant_phone'] ?? '' ) );
	$jbli_email = sanitize_email( wp_unslash( $_POST['applicant_email']      ?? '' ) );

	if ( ! $jbli_name || ! $jbli_phone || ! $jbli_email || ! is_email( $jbli_email ) )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Παρακαλώ συμπληρώστε όλα τα πεδία σωστά.', 'job-listings' ) ) );
	}

	if ( ! jbli_apply_valid_name( $jbli_name ) )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Το ονοματεπώνυμο μπορεί να έχει μόνο γράμματα.', 'job-listings' ) ) );
	}

	$jbli_phone_digits = (string) preg_replace( '/\D/', '', $jbli_phone );

	if ( strlen( $jbli_phone_digits ) < 10 || strlen( $jbli_phone_digits ) > 15 )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Συμπληρώστε έγκυρο τηλέφωνο (τουλάχιστον 10 ψηφία).', 'job-listings' ) ) );
	}

	$jbli_to = sanitize_email( (string) get_post_meta( $jbli_post_id, JBLI_META_EMAIL, true ) );

	if ( ! $jbli_to || ! is_email( $jbli_to ) )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Δεν βρέθηκε email επικοινωνίας για αυτή την αγγελία.', 'job-listings' ) ) );
	}

	/* 9.9.57: counted only for a valid request, so typos don't use up the attempts. */
	if ( ! jbli_apply_check_rate_limit( $jbli_post_id ) )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Έχετε στείλει πολλά αιτήματα. Δοκιμάστε αργότερα.', 'job-listings' ) ) );
	}

	$jbli_position = sanitize_text_field( (string) get_post_meta( $jbli_post_id, JBLI_META_POSITION, true ) )
		?: get_the_title( $jbli_post_id );
	$jbli_pharmacy = sanitize_text_field( (string) get_post_meta( $jbli_post_id, JBLI_META_PHARMACY_NAME, true ) )
		?: __( 'Φαρμακείο', 'job-listings' );

	$jbli_placeholders = array(
		'name'          => $jbli_name,
		'phone'         => $jbli_phone,
		'email'         => $jbli_email,
		'position'      => $jbli_position,
		'pharmacy'      => $jbli_pharmacy,
		'listing_url'   => get_permalink( $jbli_post_id ),
	);

	$jbli_raw_subject = trim( (string) get_option( 'jbli_apply_email_subject', '' ) );
	$jbli_raw_body    = trim( (string) get_option( 'jbli_apply_email_body',    '' ) );
	$jbli_legacy      = jbli_apply_legacy_defaults();

	/* Empty or still the pre-9.9.51 default → the new default. */
	if ( '' === $jbli_raw_subject || $jbli_legacy['subject'] === $jbli_raw_subject ) { $jbli_raw_subject = jbli_apply_default_subject(); }
	if ( '' === $jbli_raw_body || str_replace( "\r\n", "\n", $jbli_raw_body ) === $jbli_legacy['body'] ) { $jbli_raw_body = jbli_apply_default_body(); }

	$jbli_subject = wp_strip_all_tags( jbli_apply_replace_placeholders( $jbli_raw_subject, $jbli_placeholders ) );
	$jbli_body    = wp_strip_all_tags( jbli_apply_replace_placeholders( $jbli_raw_body,    $jbli_placeholders ) );

	$jbli_email_html = jbli_apply_email_html( $jbli_post_id, $jbli_placeholders, $jbli_subject, $jbli_body );

	/* "Reply" in the pharmacy's mail app goes straight to the applicant. */
	$jbli_headers   = jbli_email_headers();
	$jbli_headers[] = 'Reply-To: ' . str_replace( array( "\r", "\n", '<', '>', '"' ), '', $jbli_name ) . ' <' . $jbli_email . '>';

	/*
	 * 9.9.57: the record starts as "pending" and is marked "sent" or
	 * "failed" after wp_mail(), so the admin list shows what reached the
	 * pharmacy and a failed attempt is not mistaken for a sent application.
	 */
	$jbli_submission_id = function_exists( 'jbli_apply_save_submission' )
		? jbli_apply_save_submission( $jbli_post_id, $jbli_name, $jbli_phone, $jbli_email )
		: 0;

	$jbli_sent = wp_mail(
		$jbli_to,
		'[PharmacyNeeds] ' . $jbli_subject,
		$jbli_email_html,
		$jbli_headers
	);

	if ( $jbli_submission_id && function_exists( 'jbli_apply_set_submission_status' ) )
	{
		jbli_apply_set_submission_status( $jbli_submission_id, $jbli_sent ? 'sent' : 'failed' );
	}

	if ( ! $jbli_sent )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Αποτυχία αποστολής. Δοκιμάστε ξανά.', 'job-listings' ) ) );
	}

	wp_send_json_success( array( 'jbli_message' => __( 'Το ενδιαφέρον σας στάλθηκε επιτυχώς!', 'job-listings' ) ) );

}

add_action( 'wp_ajax_jbli_apply',        'jbli_apply_handle_ajax' );
add_action( 'wp_ajax_nopriv_jbli_apply', 'jbli_apply_handle_ajax' );

function jbli_apply_modal_registry( int $jbli_post_id = 0, bool $jbli_get = false, bool $jbli_reset = false ): array {

	static $jbli_ids = array();

	if ( $jbli_post_id > 0 && ! in_array( $jbli_post_id, $jbli_ids, true ) ) { $jbli_ids[] = $jbli_post_id; }

	if ( $jbli_get )
	{
		$jbli_current = $jbli_ids;

		if ( $jbli_reset ) { $jbli_ids = array(); }
		return $jbli_current;
	}

	return array();

}

/** Print all registered modals at wp_footer — safe position:fixed context. */
function jbli_apply_print_modals(): void {

	$jbli_ids = jbli_apply_modal_registry( 0, true );

	foreach ( $jbli_ids as $jbli_post_id ) {

		$jbli_html = jbli_apply_modal_html( (int) $jbli_post_id );
		echo $jbli_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML modal component.

	}

}

add_action( 'wp_footer', 'jbli_apply_print_modals', 20 );

function jbli_apply_render( int $jbli_post_id ): string {

	$post = get_post( $jbli_post_id );

	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) { return ''; }

	$jbli_to = (string) get_post_meta( $jbli_post_id, JBLI_META_EMAIL, true );

	if ( ! $jbli_to || ! is_email( $jbli_to ) ) { return ''; }

	jbli_apply_modal_registry( $jbli_post_id );

	$jbli_modal_id = 'jbli_apply_modal_' . $jbli_post_id;

	return '<button'
		. ' type="button"'
		. ' class="jbli_btn jbli_btn_apply jbli_btn_sm"'
		. ' data-jbli_apply_open="' . esc_attr( $jbli_modal_id ) . '"'
		. ' aria-haspopup="dialog">'
		. esc_html__( 'Εκδήλωση Ενδιαφέροντος', 'job-listings' )
		. '</button>';

}

function jbli_apply_modal_html( int $jbli_post_id ): string {

	$jbli_nonce    = wp_create_nonce( 'jbli_apply_' . $jbli_post_id );
	$jbli_modal_id = 'jbli_apply_modal_' . $jbli_post_id;

	$jbli_job_position = sanitize_text_field( (string) get_post_meta( $jbli_post_id, JBLI_META_POSITION, true ) );
	$jbli_job_pharmacy = sanitize_text_field( (string) get_post_meta( $jbli_post_id, JBLI_META_PHARMACY_NAME, true ) );

	ob_start();
	?>
	<div
		id="<?php echo esc_attr( $jbli_modal_id ); ?>"
		class="jbli_apply_modal"
		role="dialog"
		aria-modal="true"
		aria-labelledby="<?php echo esc_attr( $jbli_modal_id ); ?>_title"
		hidden
	>
		<div class="jbli_apply_modal_backdrop" data-jbli_apply_close="<?php echo esc_attr( $jbli_modal_id ); ?>"></div>

		<div class="jbli_apply_modal_box">

			<button
				type="button"
				class="jbli_apply_modal_close"
				data-jbli_apply_close="<?php echo esc_attr( $jbli_modal_id ); ?>"
				aria-label="<?php esc_attr_e( 'Κλείσιμο', 'job-listings' ); ?>"
			>
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
					<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
				</svg>
			</button>

			<div class="jbli_apply_modal_head">

				<span class="jbli_apply_modal_icon" aria-hidden="true">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/></svg>
				</span>

				<h2 id="<?php echo esc_attr( $jbli_modal_id ); ?>_title" class="jbli_apply_modal_title">
					<?php esc_html_e( 'Εκδήλωση Ενδιαφέροντος', 'job-listings' ); ?>
				</h2>

				<?php if ( '' !== $jbli_job_position ) { ?>
					<p class="jbli_apply_modal_job">
						<strong><?php echo esc_html( $jbli_job_position ); ?></strong>
						<?php if ( '' !== $jbli_job_pharmacy ) { ?><span><?php echo esc_html( $jbli_job_pharmacy ); ?></span><?php } ?>
					</p>
				<?php } ?>

				<p class="jbli_apply_modal_sub">
					<?php esc_html_e( 'Συμπληρώστε τα στοιχεία σας και θα τα λάβει άμεσα το φαρμακείο.', 'job-listings' ); ?>
				</p>

			</div>

			<div class="jbli_apply_modal_notice" aria-live="polite" hidden></div>

			<div class="jbli_apply_modal_form_wrap">

				<div class="jbli_apply_field">
					<label for="<?php echo esc_attr( $jbli_modal_id ); ?>_name">
						<?php esc_html_e( 'Ονοματεπώνυμο', 'job-listings' ); ?> <span aria-hidden="true">*</span>
					</label>
					<div class="jbli_apply_input_wrap">
						<span class="jbli_apply_input_icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
						<input type="text" id="<?php echo esc_attr( $jbli_modal_id ); ?>_name" name="applicant_name" class="jbli_apply_input" autocomplete="name" placeholder="<?php esc_attr_e( 'Π.χ. Μαρία Παπαδοπούλου', 'job-listings' ); ?>" required>
					</div>
				</div>

				<div class="jbli_apply_field">
					<label for="<?php echo esc_attr( $jbli_modal_id ); ?>_phone">
						<?php esc_html_e( 'Κινητό τηλέφωνο', 'job-listings' ); ?> <span aria-hidden="true">*</span>
					</label>
					<div class="jbli_apply_input_wrap">
						<span class="jbli_apply_input_icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg></span>
						<input type="tel" id="<?php echo esc_attr( $jbli_modal_id ); ?>_phone" name="applicant_phone" class="jbli_apply_input" autocomplete="tel" inputmode="tel" placeholder="<?php esc_attr_e( 'Π.χ. 69xxxxxxxx', 'job-listings' ); ?>" required>
					</div>
				</div>

				<div class="jbli_apply_field">
					<label for="<?php echo esc_attr( $jbli_modal_id ); ?>_email">
						<?php esc_html_e( 'Email', 'job-listings' ); ?> <span aria-hidden="true">*</span>
					</label>
					<div class="jbli_apply_input_wrap">
						<span class="jbli_apply_input_icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/></svg></span>
						<input type="email" id="<?php echo esc_attr( $jbli_modal_id ); ?>_email" name="applicant_email" class="jbli_apply_input" autocomplete="email" inputmode="email" placeholder="<?php esc_attr_e( 'Π.χ. maria@email.gr', 'job-listings' ); ?>" required>
					</div>
				</div>

				<div class="jbli_apply_hp" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
					<label for="<?php echo esc_attr( $jbli_modal_id ); ?>_website"><?php esc_html_e( 'Μην συμπληρώσετε αυτό το πεδίο', 'job-listings' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $jbli_modal_id ); ?>_website" name="jbli_hp_website" value="" tabindex="-1" autocomplete="off">
				</div>

				<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $jbli_post_id ); ?>">
				<input type="hidden" name="nonce"   value="<?php echo esc_attr( $jbli_nonce ); ?>">
				<input type="hidden" name="action"  value="jbli_apply">

				<button type="button" class="jbli_btn jbli_btn_primary jbli_apply_modal_submit" data-jbli_apply_submit="<?php echo esc_attr( $jbli_modal_id ); ?>">
					<span class="jbli_apply_modal_submit_label"><?php esc_html_e( 'Αποστολή', 'job-listings' ); ?></span>
					<span class="jbli_apply_modal_submit_loading" hidden>
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true" class="jbli_spin">
							<path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
						</svg>
						<?php esc_html_e( 'Αποστολή...', 'job-listings' ); ?>
					</span>
				</button>

				<p class="jbli_apply_modal_privacy">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
					<span>
						<?php
						echo esc_html( sprintf(
							/* translators: %d: days applications are kept */
							__( 'Τα στοιχεία σας στέλνονται στο φαρμακείο της αγγελίας. Κρατάμε αντίγραφο της αίτησης για %d ημέρες και μετά διαγράφεται αυτόματα.', 'job-listings' ),
							function_exists( 'jbli_apply_retention_days' ) ? jbli_apply_retention_days() : 60
						) );
						$jbli_privacy_url = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
						if ( '' !== $jbli_privacy_url ) {
							echo ' <a href="' . esc_url( $jbli_privacy_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Πολιτική απορρήτου', 'job-listings' ) . '</a>';
						}
						?>
					</span>
				</p>

			</div>

		</div>
	</div>
	<?php
	return (string) ob_get_clean();

}
