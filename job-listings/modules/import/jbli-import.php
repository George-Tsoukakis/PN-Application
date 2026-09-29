<?php
/**
 * Module: Import listings from external URLs (admin only).
 *
 * Αγγελίες → «Εισαγωγή από URL»: paste one or more ad URLs from other job
 * boards (e.g. jobfind.gr). Each page is fetched, parsed (schema.org
 * JobPosting, OpenGraph fallback) and saved as a listing in "pending"
 * status for review; the admin approves it from the listings panel.
 * The same source URL is never imported twice.
 *
 * @package JobListings
 * @since   9.9.53
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/jbli-import-parser.php';

defined( 'JBLI_META_SOURCE_URL' )  || define( 'JBLI_META_SOURCE_URL',  'jbli_source_url' );
defined( 'JBLI_META_SOURCE_SITE' ) || define( 'JBLI_META_SOURCE_SITE', 'jbli_source_site' );

/**
 * Canonical form of a source URL (no fragment, no trailing spaces).
 *
 * @param string $jbli_url URL.
 * @return string
 */
function jbli_import_canonical_url( $jbli_url ) {

	$jbli_url = trim( (string) $jbli_url );
	$jbli_url = (string) preg_replace( '~#.*$~', '', $jbli_url );

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
			'post_status'  => 'pending',
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

		$jbli_urls  = sanitize_textarea_field( wp_unslash( $_POST['jbli_import_urls'] ) );
		$jbli_email = sanitize_email( wp_unslash( $_POST['jbli_import_email'] ?? '' ) );
		$jbli_list  = array_slice( array_values( array_unique( array_filter( array_map( 'trim', preg_split( '~[\r\n]+~', $jbli_urls ) ) ) ) ), 0, 20 );

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
			<?php esc_html_e( 'Επικολλήστε συνδέσμους αγγελιών από άλλους ιστότοπους (π.χ. jobfind.gr, kariera.gr), έναν ανά γραμμή (έως 20). Κάθε αγγελία δημιουργείται «Σε αναμονή» με τίτλο, περιγραφή, φαρμακείο, νομό, κατηγορία, τύπο και αμοιβή όπως αναγνωρίστηκαν, μαζί με σύνδεσμο στην αρχική. Ελέγξτε την και εγκρίνετέ την από τη Διαχείριση Αγγελιών. Ο ίδιος σύνδεσμος δεν εισάγεται δεύτερη φορά.', 'job-listings' ); ?>
		</p>

		<p style="max-width:780px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:8px 12px;">
			<?php esc_html_e( 'Εισάγετε αγγελίες για τις οποίες έχετε δικαίωμα αναδημοσίευσης (π.χ. με άδεια του φαρμακείου ή του ιστότοπου). Η αρχική πηγή εμφανίζεται πάντα στην αγγελία.', 'job-listings' ); ?>
		</p>

		<?php if ( $jbli_results ) { ?>

			<h2><?php esc_html_e( 'Αποτελέσματα', 'job-listings' ); ?></h2>

			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th style="width:34%;"><?php esc_html_e( 'URL', 'job-listings' ); ?></th>
						<th style="width:14%;"><?php esc_html_e( 'Αποτέλεσμα', 'job-listings' ); ?></th>
						<th><?php esc_html_e( 'Αγγελία / Σημειώσεις', 'job-listings' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $jbli_results as $jbli_r ) { ?>
					<tr>
						<td style="word-break:break-all;"><a href="<?php echo esc_url( $jbli_r['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $jbli_r['url'] ); ?></a></td>
						<td>
							<?php
								if ( 'created' === $jbli_r['status'] )       { echo '<strong style="color:#047857;">' . esc_html__( 'Δημιουργήθηκε', 'job-listings' ) . '</strong>'; }
								elseif ( 'duplicate' === $jbli_r['status'] ) { echo '<strong style="color:#92400e;">' . esc_html__( 'Υπάρχει ήδη', 'job-listings' ) . '</strong>'; }
								else                                         { echo '<strong style="color:#b91c1c;">' . esc_html__( 'Σφάλμα', 'job-listings' ) . '</strong>'; }
							?>
						</td>
						<td>
							<?php if ( ! empty( $jbli_r['post_id'] ) ) { ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $jbli_r['post_id'] ) ); ?>"><?php echo esc_html( (string) ( $jbli_r['title'] ?? '' ) ); ?></a>
								&nbsp;·&nbsp;<a href="<?php echo esc_url( (string) get_preview_post_link( (int) $jbli_r['post_id'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Προεπισκόπηση', 'job-listings' ); ?></a>
							<?php } ?>
							<?php if ( ! empty( $jbli_r['message'] ) ) { ?><div style="color:#b91c1c;"><?php echo esc_html( $jbli_r['message'] ); ?></div><?php } ?>
							<?php if ( ! empty( $jbli_r['warnings'] ) ) { ?>
								<ul style="margin:6px 0 0 18px;list-style:disc;color:#92400e;">
									<?php foreach ( $jbli_r['warnings'] as $jbli_w ) { ?><li><?php echo esc_html( $jbli_w ); ?></li><?php } ?>
								</ul>
							<?php } ?>
						</td>
					</tr>
				<?php } ?>
				</tbody>
			</table>

			<p><a class="button button-primary" href="<?php echo esc_url( $jbli_panel_url ); ?>"><?php esc_html_e( 'Έλεγχος & έγκριση αγγελιών σε αναμονή', 'job-listings' ); ?></a></p>

		<?php } ?>

		<form method="post" style="max-width:780px;margin-top:18px;">
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

			<p><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Εισαγωγή', 'job-listings' ); ?></button></p>
		</form>

	</div>
	<?php

}
