<?php
/**
 * Module: Import listings from external URLs (admin only).
 *
 * Αγγελίες → «Εισαγωγή από URL»: paste one or more ad URLs from other job
 * boards (e.g. jobfind.gr). Each page is fetched, parsed (schema.org
 * JobPosting, OpenGraph fallback) and saved as a listing: published at once
 * or "pending" for review, per the «Εισαγωγή από URL» setting (9.9.54).
 * The same source URL is never imported twice.
 *
 * @package JobListings
 * @since   9.9.53
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/jbli-import-parser.php';

defined( 'JBLI_META_SOURCE_URL' )  || define( 'JBLI_META_SOURCE_URL',  'jbli_source_url' );
defined( 'JBLI_META_SOURCE_SITE' ) || define( 'JBLI_META_SOURCE_SITE', 'jbli_source_site' );

/* 9.9.57: URLs per import. With JS each URL is its own request, so no timeouts. */
defined( 'JBLI_IMPORT_MAX_URLS' )  || define( 'JBLI_IMPORT_MAX_URLS',  100 );

/* Without JS the whole list runs in one request — keep it short. */
defined( 'JBLI_IMPORT_MAX_URLS_NOJS' ) || define( 'JBLI_IMPORT_MAX_URLS_NOJS', 20 );

/**
 * Status for imported listings, from Ρυθμίσεις → «Εισαγωγή από URL».
 *
 * 'publish' (default): approved immediately. 'pending': waits for review.
 *
 * @since 9.9.54
 * @return string 'publish' | 'pending'
 */
function jbli_import_status() {

	return 'pending' === get_option( 'jbli_import_status', 'publish' ) ? 'pending' : 'publish';

}

/**
 * Canonical form of a source URL (no fragment, no trailing spaces).
 *
 * @param string $jbli_url URL.
 * @return string
 */
function jbli_import_canonical_url( $jbli_url ) {

	/* Invisible characters that come along when copying from browsers / chats. */
	$jbli_url = trim( str_replace( array( "\xE2\x80\x8B", "\xE2\x80\x8E", "\xE2\x80\x8F", "\xEF\xBB\xBF", "\xC2\xA0" ), '', (string) $jbli_url ) );
	$jbli_url = (string) preg_replace( '~#.*$~', '', $jbli_url );

	/*
	 * Greek (any non-ASCII) letters typed/pasted as-is are percent-encoded;
	 * already-encoded %ce%b2… sequences are kept untouched. (9.9.56: the list
	 * used to go through sanitize_textarea_field(), which deletes %XX
	 * sequences, so Greek slugs were cut to "-life--2-" and the site said 404.)
	 */
	$jbli_url = (string) preg_replace_callback(
		'/[^\x00-\x7F]+/',
		static function ( $jbli_m ) { return rawurlencode( $jbli_m[0] ); },
		$jbli_url
	);
	$jbli_url = str_replace( ' ', '%20', $jbli_url );

	/* %CE%B2 and %ce%b2 are the same URL — one form, so duplicates are caught. */
	$jbli_url = (string) preg_replace_callback( '/%[0-9A-Fa-f]{2}/', static function ( $jbli_m ) { return strtolower( $jbli_m[0] ); }, $jbli_url );

	return esc_url_raw( $jbli_url, array( 'http', 'https' ) );

}

/**
 * Listing already imported from this URL (any status), or 0.
 *
 * @param string $jbli_url Canonical URL.
 * @return int
 */
function jbli_import_existing( $jbli_url ) {

	$jbli_ids = get_posts( array(
		'post_type'      => JBLI_CPT,
		'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'job-expired', 'trash' ),
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_key'       => JBLI_META_SOURCE_URL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'     => $jbli_url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	) );

	return $jbli_ids ? (int) $jbli_ids[0] : 0;

}

/**
 * Import one URL.
 *
 * @param string $jbli_url         URL.
 * @param string $jbli_apply_email Email for applications (overrides one found in the ad).
 * @return array{status:string,post_id?:int,title?:string,message?:string,warnings?:string[]}
 *               status: created | duplicate | error
 */
function jbli_import_url( $jbli_url, $jbli_apply_email = '' ) {

	$jbli_url = jbli_import_canonical_url( $jbli_url );

	if ( '' === $jbli_url || ! wp_http_validate_url( $jbli_url ) )
	{
		return array( 'status' => 'error', 'message' => __( 'Μη έγκυρο URL.', 'job-listings' ) );
	}

	$jbli_existing = jbli_import_existing( $jbli_url );

	if ( $jbli_existing )
	{
		return array( 'status' => 'duplicate', 'post_id' => $jbli_existing, 'title' => get_the_title( $jbli_existing ) );
	}

	$jbli_html = jbli_import_fetch( $jbli_url );

	if ( is_wp_error( $jbli_html ) ) { return array( 'status' => 'error', 'message' => $jbli_html->get_error_message() ); }

	$jbli_data = jbli_import_parse( $jbli_html, $jbli_url );

	if ( is_wp_error( $jbli_data ) ) { return array( 'status' => 'error', 'message' => $jbli_data->get_error_message() ); }

	$jbli_host     = (string) preg_replace( '~^www\.~', '', (string) wp_parse_url( $jbli_url, PHP_URL_HOST ) );
	$jbli_pharmacy = $jbli_data['pharmacy'];
	$jbli_email    = is_email( $jbli_apply_email ) ? sanitize_email( $jbli_apply_email ) : $jbli_data['email'];

	$jbli_nomos_term = $jbli_data['nomos_id'] ? get_term( $jbli_data['nomos_id'], 'job_nomos' ) : null;
	$jbli_title      = function_exists( 'jbli_build_listing_title' )
		? jbli_build_listing_title( '' !== $jbli_pharmacy ? $jbli_pharmacy : __( 'Φαρμακείο', 'job-listings' ), $jbli_data['position'], $jbli_nomos_term instanceof WP_Term ? $jbli_nomos_term->name : '' )
		: $jbli_data['position'];

	$jbli_post_id = wp_insert_post(
		array(
			'post_type'    => JBLI_CPT,
			'post_status'  => jbli_import_status(),
			'post_author'  => get_current_user_id(),
			'post_title'   => $jbli_title,
			'post_content' => $jbli_data['description'],
			'post_name'    => function_exists( 'jbli_build_post_slug' ) ? jbli_build_post_slug( $jbli_title, 0 ) : '',
		),
		true
	);

	if ( is_wp_error( $jbli_post_id ) ) { return array( 'status' => 'error', 'message' => $jbli_post_id->get_error_message() ); }

	if ( $jbli_data['category_id'] ) { wp_set_post_terms( $jbli_post_id, array( (int) $jbli_data['category_id'] ), 'job_category' ); }
	if ( $jbli_data['nomos_id'] )    { wp_set_post_terms( $jbli_post_id, array( (int) $jbli_data['nomos_id'] ), 'job_nomos' ); }

	$jbli_meta = array(
		JBLI_META_POSITION      => $jbli_data['position'],
		JBLI_META_PHARMACY_NAME => $jbli_pharmacy,
		JBLI_META_SALARY        => $jbli_data['salary'],
		JBLI_META_TYPE          => $jbli_data['type'],
		JBLI_META_CONTACT_PHONE => $jbli_data['phone'],
		JBLI_META_EMAIL         => $jbli_email,
		JBLI_META_ADDRESS       => $jbli_data['address'],
		JBLI_META_EXPIRES       => '' !== $jbli_data['expires'] ? $jbli_data['expires'] : ( function_exists( 'jbli_future_datetime' ) ? jbli_future_datetime( 30 ) : '' ),
		JBLI_META_FEATURED      => 0,
		JBLI_META_SOURCE_URL    => $jbli_url,
		JBLI_META_SOURCE_SITE   => $jbli_host,
	);

	foreach ( $jbli_meta as $jbli_key => $jbli_value ) { update_post_meta( $jbli_post_id, $jbli_key, $jbli_value ); }

	if ( function_exists( 'jbli_sync_listing_storage' ) ) { jbli_sync_listing_storage( (int) $jbli_post_id ); }

	$jbli_warnings = $jbli_data['warnings'];

	if ( '' === $jbli_pharmacy ) { $jbli_warnings[] = __( 'Δεν βρέθηκε όνομα φαρμακείου — συμπληρώστε το στην επεξεργασία (πεδίο jbli_pharmacy_name).', 'job-listings' ); }

	if ( '' === $jbli_email ) { $jbli_warnings[] = __( 'Χωρίς email αιτήσεων: δεν θα εμφανίζεται «Εκδήλωση Ενδιαφέροντος», μόνο σύνδεσμος στην αρχική αγγελία.', 'job-listings' ); }

	do_action( 'jbli_imported', (int) $jbli_post_id, $jbli_url );

	return array(
		'status'   => 'created',
		'published'=> 'publish' === get_post_status( (int) $jbli_post_id ),
		'post_id'  => (int) $jbli_post_id,
		'title'    => $jbli_data['position'],
		'warnings' => $jbli_warnings,
	);

}

/**
 * Admin submenu.
 *
 * @return void
 */
function jbli_import_register_page() {

	add_submenu_page(
		'jbli_admin_panel',
		__( 'Εισαγωγή από URL', 'job-listings' ),
		__( 'Εισαγωγή από URL', 'job-listings' ),
		'manage_options',
		'jbli_import',
		'jbli_import_render_page'
	);

}

add_action( 'admin_menu', 'jbli_import_register_page', 20 );

/**
 * Render the import page (and process a submitted batch).
 *
 * @return void
 */
function jbli_import_render_page() {

	if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Unauthorized', 'job-listings' ), 403 ); }

	$jbli_results = array();
	$jbli_urls    = '';
	$jbli_email   = '';

	if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['jbli_import_urls'] ) )
	{
		check_admin_referer( 'jbli_import', 'jbli_import_nonce' );

		/* Not sanitize_textarea_field(): it strips the %XX sequences of Greek URLs. Each line is validated by jbli_import_canonical_url(). */
		$jbli_raw   = wp_check_invalid_utf8( (string) wp_unslash( $_POST['jbli_import_urls'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$jbli_email = sanitize_email( wp_unslash( $_POST['jbli_import_email'] ?? '' ) );
		$jbli_list  = array_slice( array_values( array_unique( array_filter( array_map( 'jbli_import_canonical_url', preg_split( '~[\r\n\s]+~', $jbli_raw ) ) ) ) ), 0, JBLI_IMPORT_MAX_URLS_NOJS );
		$jbli_urls  = implode( "\n", $jbli_list );

		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		foreach ( $jbli_list as $jbli_url ) {

			$jbli_results[] = array( 'url' => $jbli_url ) + jbli_import_url( $jbli_url, $jbli_email );

		}

		if ( array_filter( $jbli_results, static function ( $jbli_r ) { return 'created' === $jbli_r['status']; } ) ) { $jbli_urls = ''; }
	}

	$jbli_panel_url = admin_url( 'admin.php?page=jbli_admin_panel&f_status=pending' );
	?>
	<div class="wrap">

		<h1><?php esc_html_e( 'Εισαγωγή αγγελιών από URL', 'job-listings' ); ?></h1>

		<p style="max-width:780px;color:#4b5563;">
			<?php
				/* translators: %d: maximum number of URLs per import */
				echo esc_html( sprintf( __( 'Επικολλήστε συνδέσμους αγγελιών από άλλους ιστότοπους (π.χ. jobfind.gr, kariera.gr), έναν ανά γραμμή (έως %d κάθε φορά). Οι σύνδεσμοι εισάγονται ένας-ένας, με πρόοδο στην οθόνη, οπότε μπορείτε να βάλετε πολλούς μαζί.', 'job-listings' ), JBLI_IMPORT_MAX_URLS ) );
			?>
			<?php esc_html_e( 'Κάθε αγγελία δημιουργείται με τίτλο, περιγραφή, φαρμακείο, νομό, κατηγορία, τύπο και αμοιβή όπως αναγνωρίστηκαν, μαζί με σύνδεσμο στην αρχική. Ο ίδιος σύνδεσμος δεν εισάγεται δεύτερη φορά.', 'job-listings' ); ?>
		</p>

		<p style="max-width:780px;">
			<strong><?php esc_html_e( 'Έγκριση:', 'job-listings' ); ?></strong>
			<?php echo esc_html( 'pending' === jbli_import_status() ? __( 'Σε αναμονή για έλεγχο — εγκρίνετε από τη Διαχείριση Αγγελιών.', 'job-listings' ) : __( 'Άμεση έγκριση — οι αγγελίες δημοσιεύονται αμέσως.', 'job-listings' ) ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=jbli_settings#jbli_import_settings' ) ); ?>"><?php esc_html_e( 'Αλλαγή στις Ρυθμίσεις', 'job-listings' ); ?></a>
		</p>

		<p style="max-width:780px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:8px 12px;">
			<?php esc_html_e( 'Εισάγετε αγγελίες για τις οποίες έχετε δικαίωμα αναδημοσίευσης (π.χ. με άδεια του φαρμακείου ή του ιστότοπου). Η αρχική πηγή εμφανίζεται πάντα στην αγγελία.', 'job-listings' ); ?>
		</p>

		<div id="jbli_import_results"<?php echo $jbli_results ? '' : ' hidden'; ?>>

			<h2><?php esc_html_e( 'Αποτελέσματα', 'job-listings' ); ?> <span id="jbli_import_progress" style="font-size:14px;font-weight:400;color:#4b5563;" aria-live="polite"></span></h2>

			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th style="width:34%;"><?php esc_html_e( 'URL', 'job-listings' ); ?></th>
						<th style="width:14%;"><?php esc_html_e( 'Αποτέλεσμα', 'job-listings' ); ?></th>
						<th><?php esc_html_e( 'Αγγελία / Σημειώσεις', 'job-listings' ); ?></th>
					</tr>
				</thead>
				<tbody id="jbli_import_rows">
				<?php foreach ( $jbli_results as $jbli_r ) { echo jbli_import_result_row_html( $jbli_r ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				</tbody>
			</table>

			<?php if ( 'pending' === jbli_import_status() ) { ?>
				<p><a class="button button-primary" href="<?php echo esc_url( $jbli_panel_url ); ?>"><?php esc_html_e( 'Έλεγχος & έγκριση αγγελιών σε αναμονή', 'job-listings' ); ?></a></p>
			<?php } ?>

		</div>

		<form method="post" id="jbli_import_form" style="max-width:780px;margin-top:18px;">
			<?php wp_nonce_field( 'jbli_import', 'jbli_import_nonce' ); ?>

			<p>
				<label for="jbli_import_urls"><strong><?php esc_html_e( 'Σύνδεσμοι αγγελιών (ένας ανά γραμμή)', 'job-listings' ); ?></strong></label>
				<textarea id="jbli_import_urls" name="jbli_import_urls" rows="7" class="large-text code" placeholder="https://www.jobfind.gr/JobAd/View/GR/…" required><?php echo esc_textarea( $jbli_urls ); ?></textarea>
			</p>

			<p>
				<label for="jbli_import_email"><strong><?php esc_html_e( 'Email για «Εκδήλωση Ενδιαφέροντος» (προαιρετικό)', 'job-listings' ); ?></strong></label><br>
				<input type="email" id="jbli_import_email" name="jbli_import_email" value="<?php echo esc_attr( $jbli_email ); ?>" class="regular-text" placeholder="pharmacy@example.gr">
				<br><span style="color:#6b7280;"><?php esc_html_e( 'Αν συμπληρωθεί, οι αιτήσεις για αυτές τις αγγελίες στέλνονται εδώ. Αλλιώς χρησιμοποιείται email που βρέθηκε στην αγγελία· αν δεν βρεθεί, εμφανίζεται μόνο σύνδεσμος στην αρχική αγγελία.', 'job-listings' ); ?></span>
			</p>

			<p><button type="submit" id="jbli_import_submit" class="button button-primary button-hero"><?php esc_html_e( 'Εισαγωγή', 'job-listings' ); ?></button></p>
		</form>

		<?php jbli_import_print_script(); ?>

	</div>
	<?php

}

/**
 * One results-table row for an import result.
 *
 * @since 9.9.57 (was inline in jbli_import_render_page())
 *
 * @param array $jbli_r Result of jbli_import_url() plus 'url'.
 * @return string Escaped HTML.
 */
function jbli_import_result_row_html( array $jbli_r ) {

	$jbli_status = (string) ( $jbli_r['status'] ?? 'error' );

	if ( 'created' === $jbli_status )       { $jbli_label = '<strong style="color:#047857;">' . esc_html( ! empty( $jbli_r['published'] ) ? __( 'Δημοσιεύτηκε', 'job-listings' ) : __( 'Σε αναμονή', 'job-listings' ) ) . '</strong>'; }
	elseif ( 'duplicate' === $jbli_status ) { $jbli_label = '<strong style="color:#92400e;">' . esc_html__( 'Υπάρχει ήδη', 'job-listings' ) . '</strong>'; }
	else                                    { $jbli_label = '<strong style="color:#b91c1c;">' . esc_html__( 'Σφάλμα', 'job-listings' ) . '</strong>'; }

	$jbli_notes = '';

	if ( ! empty( $jbli_r['post_id'] ) )
	{
		$jbli_id    = (int) $jbli_r['post_id'];
		$jbli_edit  = function_exists( 'jbli_get_form_page_url' ) ? add_query_arg( 'job_edit', $jbli_id, jbli_get_form_page_url() ) : (string) get_edit_post_link( $jbli_id );
		$jbli_notes .= '<a href="' . esc_url( $jbli_edit ) . '">' . esc_html( (string) ( $jbli_r['title'] ?? '' ) ) . '</a>';
		$jbli_notes .= '&nbsp;·&nbsp;<a href="' . esc_url( (string) get_preview_post_link( $jbli_id ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Προεπισκόπηση', 'job-listings' ) . '</a>';
	}

	if ( ! empty( $jbli_r['message'] ) ) { $jbli_notes .= '<div style="color:#b91c1c;">' . esc_html( (string) $jbli_r['message'] ) . '</div>'; }

	if ( ! empty( $jbli_r['warnings'] ) )
	{
		$jbli_notes .= '<ul style="margin:6px 0 0 18px;list-style:disc;color:#92400e;">';

		foreach ( (array) $jbli_r['warnings'] as $jbli_w ) { $jbli_notes .= '<li>' . esc_html( (string) $jbli_w ) . '</li>'; }

		$jbli_notes .= '</ul>';
	}

	$jbli_url = (string) ( $jbli_r['url'] ?? '' );

	return '<tr>'
		. '<td style="word-break:break-all;"><a href="' . esc_url( $jbli_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $jbli_url ) . '</a></td>'
		. '<td>' . $jbli_label . '</td>'
		. '<td>' . $jbli_notes . '</td>'
		. '</tr>';

}

/**
 * AJAX: import a single URL (the import page sends the list one URL at a time).
 *
 * @since 9.9.57
 * @return void
 */
function jbli_import_ajax_one() {

	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => __( 'Unauthorized', 'job-listings' ) ), 403 ); }

	check_ajax_referer( 'jbli_import', 'nonce' );

	/* Not sanitize_text_field(): it strips the %XX sequences of Greek URLs. jbli_import_canonical_url() validates it. */
	$jbli_raw   = wp_check_invalid_utf8( (string) wp_unslash( $_POST['url'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$jbli_email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	$jbli_url   = jbli_import_canonical_url( $jbli_raw );

	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 60 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	$jbli_result = array( 'url' => '' !== $jbli_url ? $jbli_url : $jbli_raw ) + jbli_import_url( $jbli_raw, $jbli_email );

	wp_send_json_success(
		array(
			'status' => (string) $jbli_result['status'],
			'row'    => jbli_import_result_row_html( $jbli_result ),
		)
	);

}

add_action( 'wp_ajax_jbli_import_one', 'jbli_import_ajax_one' );

/**
 * Inline script: send the URL list one URL at a time, with progress.
 *
 * Without JS the form still posts the whole list (first JBLI_IMPORT_MAX_URLS_NOJS).
 *
 * @since 9.9.57
 * @return void
 */
function jbli_import_print_script() {

	$jbli_cfg = array(
		'ajax'    => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'jbli_import' ),
		'max'     => (int) JBLI_IMPORT_MAX_URLS,
		'i18n'    => array(
			/* translators: 1: current URL number, 2: total URLs */
			'progress' => __( '%1$d από %2$d', 'job-listings' ),
			'done'     => __( 'Ολοκληρώθηκε: %1$d νέες, %2$d υπήρχαν ήδη, %3$d σφάλματα.', 'job-listings' ),
			'tooMany'  => __( 'Βάλατε %1$d συνδέσμους· εισάγονται οι πρώτοι %2$d, οι υπόλοιποι μένουν στο πεδίο για την επόμενη φορά.', 'job-listings' ),
			'failed'   => __( 'Ο διακομιστής δεν απάντησε. Δοκιμάστε ξανά αυτόν τον σύνδεσμο.', 'job-listings' ),
			'busy'     => __( 'Εισαγωγή…', 'job-listings' ),
			'leave'    => __( 'Η εισαγωγή δεν έχει τελειώσει.', 'job-listings' ),
		),
	);
	?>
	<script>
	( function () {
		var cfg   = <?php echo wp_json_encode( $jbli_cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
		var form  = document.getElementById( 'jbli_import_form' );
		var box   = document.getElementById( 'jbli_import_results' );
		var rows  = document.getElementById( 'jbli_import_rows' );
		var prog  = document.getElementById( 'jbli_import_progress' );
		var btn   = document.getElementById( 'jbli_import_submit' );
		if ( ! form || ! window.fetch || ! window.FormData ) { return; }

		function fmt( s, a ) { return s.replace( /%(\d)\$d/g, function ( m, n ) { return String( a[ n - 1 ] ); } ); }
		function errorRow( url, msg ) {
			var tr = document.createElement( 'tr' ), td1 = document.createElement( 'td' ), td2 = document.createElement( 'td' ), td3 = document.createElement( 'td' ), b = document.createElement( 'strong' );
			td1.style.wordBreak = 'break-all'; td1.textContent = url;
			b.style.color = '#b91c1c'; b.textContent = '✕'; td2.appendChild( b );
			td3.style.color = '#b91c1c'; td3.textContent = msg;
			tr.appendChild( td1 ); tr.appendChild( td2 ); tr.appendChild( td3 );
			return tr;
		}

		var running = false;
		window.addEventListener( 'beforeunload', function ( e ) { if ( running ) { e.preventDefault(); e.returnValue = cfg.i18n.leave; return cfg.i18n.leave; } } );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( running ) { return; }

			var seen = {}, urls = [];
			form.elements.jbli_import_urls.value.split( /\s+/ ).forEach( function ( u ) {
				u = u.trim(); if ( u && ! seen[ u ] ) { seen[ u ] = 1; urls.push( u ); }
			} );
			if ( ! urls.length ) { return; }

			var total = urls.length;
			rows.innerHTML = '';
			box.hidden = false;
			var note = '', rest = [];
			if ( total > cfg.max ) { note = fmt( cfg.i18n.tooMany, [ total, cfg.max ] ) + ' '; rest = urls.slice( cfg.max ); urls = urls.slice( 0, cfg.max ); }

			var email = form.elements.jbli_import_email.value, n = 0, c = { created: 0, duplicate: 0, error: 0 }, failed = [];
			running = true; btn.disabled = true;

			function next() {
				if ( n >= urls.length ) {
					running = false; btn.disabled = false; btn.textContent = btn.getAttribute( 'data-label' );
					prog.textContent = note + fmt( cfg.i18n.done, [ c.created, c.duplicate, c.error ] );
					/* Keep the links that failed or were over the limit, ready for the next run. */
					form.elements.jbli_import_urls.value = failed.concat( rest ).join( '\n' );
					return;
				}
				var url = urls[ n++ ];
				prog.textContent = note + fmt( cfg.i18n.progress, [ n, urls.length ] );
				var fd = new FormData();
				fd.append( 'action', 'jbli_import_one' ); fd.append( 'nonce', cfg.nonce ); fd.append( 'url', url ); fd.append( 'email', email );
				fetch( cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						if ( ! j || ! j.success ) { throw new Error( 'bad' ); }
						c[ j.data.status ] = ( c[ j.data.status ] || 0 ) + 1;
						if ( 'error' === j.data.status ) { failed.push( url ); }
						rows.insertAdjacentHTML( 'beforeend', j.data.row );
					} )
					.catch( function () { c.error++; failed.push( url ); rows.appendChild( errorRow( url, cfg.i18n.failed ) ); } )
					.then( next );
			}
			btn.setAttribute( 'data-label', btn.textContent );
			btn.textContent = cfg.i18n.busy;
			next();
		} );
	} () );
	</script>
	<?php

}
