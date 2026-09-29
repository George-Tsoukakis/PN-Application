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

	return 'Εκδήλωση Ενδιαφέροντος για τη θέση «{position}»';

}

function jbli_apply_default_body(): string {

	return "Αγαπητέ/ή {pharmacy},\n\nΈνας υποψήφιος εξέφρασε ενδιαφέρον για τη θέση «{position}».\n\nΣτοιχεία υποψηφίου:\n• Όνομα: {name}\n• Κινητό: {phone}\n• Email: {email}\n\nΑγγελία: {listing_url}\n\nΤο μήνυμα αυτό εστάλη αυτόματα από το PharmacyNeeds.";

}

function jbli_apply_replace_placeholders( string $jbli_text, array $jbli_data ): string {

	foreach ( $jbli_data as $jbli_key => $jbli_value ) {

		$jbli_text = str_replace( '{' . $jbli_key . '}', (string) $jbli_value, $jbli_text );

	}

	return $jbli_text;

}

function jbli_apply_check_rate_limit( int $jbli_post_id ): bool {

	$jbli_ip    = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
	$jbli_key   = 'jbli_apply_' . $jbli_post_id . '_' . md5( $jbli_ip );
	$jbli_count = (int) get_transient( $jbli_key );

	if ( $jbli_count >= 3 ) { return false; }

	set_transient( $jbli_key, $jbli_count + 1, HOUR_IN_SECONDS );
	return true;

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

	if ( ! jbli_apply_check_rate_limit( $jbli_post_id ) )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Έχετε στείλει πολλά αιτήματα. Δοκιμάστε αργότερα.', 'job-listings' ) ) );
	}

	$jbli_name  = sanitize_text_field( wp_unslash( $_POST['applicant_name']  ?? '' ) );
	$jbli_phone = sanitize_text_field( wp_unslash( $_POST['applicant_phone'] ?? '' ) );
	$jbli_email = sanitize_email( wp_unslash( $_POST['applicant_email']      ?? '' ) );

	if ( ! $jbli_name || ! $jbli_phone || ! $jbli_email || ! is_email( $jbli_email ) )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Παρακαλώ συμπληρώστε όλα τα πεδία σωστά.', 'job-listings' ) ) );
	}

	$jbli_to = sanitize_email( (string) get_post_meta( $jbli_post_id, JBLI_META_EMAIL, true ) );

	if ( ! $jbli_to || ! is_email( $jbli_to ) )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Δεν βρέθηκε email επικοινωνίας για αυτή την αγγελία.', 'job-listings' ) ) );
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

	$jbli_raw_subject = (string) get_option( 'jbli_apply_email_subject', jbli_apply_default_subject() );
	$jbli_raw_body    = (string) get_option( 'jbli_apply_email_body',    jbli_apply_default_body() );

	$jbli_subject = jbli_apply_replace_placeholders( $jbli_raw_subject, $jbli_placeholders );
	$jbli_body    = jbli_apply_replace_placeholders( $jbli_raw_body,    $jbli_placeholders );

	$jbli_body_html = jbli_email_heading( esc_html( $jbli_subject ) )
		. '<div style="white-space:pre-line;margin:0 0 14px;color:#444;font-size:15px;line-height:1.6;">'
		. wp_kses_post( nl2br( $jbli_body ) )
		. '</div>'
		. '<hr style="border:none;border-top:1px solid #eee;margin:20px 0;">'
		. jbli_email_paragraph(
			'<small style="color:#999;">Αίτημα από IP: ' . esc_html( sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) ) . '</small>'
		);


	if ( function_exists( 'jbli_apply_save_submission' ) )
	{
		jbli_apply_save_submission( $jbli_post_id, $jbli_name, $jbli_phone, $jbli_email );
	}

	$jbli_sent = wp_mail(
		$jbli_to,
		'[PharmacyNeeds] ' . $jbli_subject,
		jbli_email_wrap( $jbli_subject, $jbli_body_html ),
		jbli_email_headers()
	);

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
					<?php esc_html_e( 'Τα στοιχεία σας αποστέλλονται μόνο στο φαρμακείο της αγγελίας.', 'job-listings' ); ?>
				</p>

			</div>

		</div>
	</div>
	<?php
	return (string) ob_get_clean();

}
