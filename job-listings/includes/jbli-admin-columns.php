<?php
/**
 * Admin Columns
 *
 * @package JobListings
 * @since   9.6.1
 */

defined( 'ABSPATH' ) || exit;

function jbli_admin_columns( array $jbli_columns ): array {

	return array(
		'cb'             => $jbli_columns['cb'] ?? '<input type="checkbox" />',
		'jbli_title'     => __( 'Θέση Εργασίας', 'job-listings' ),
		'job_pharmacy'   => __( 'Φαρμακείο', 'job-listings' ),
		'job_nomos'      => __( 'Νομός', 'job-listings' ),
		'job_category'   => __( 'Κατηγορία', 'job-listings' ),
		'job_type'       => __( 'Τύπος', 'job-listings' ),
		'job_salary'     => __( 'Αμοιβή', 'job-listings' ),
		'job_expires'    => __( 'Λήξη', 'job-listings' ),
		'job_status_col' => __( 'Κατάσταση', 'job-listings' ),
		'date'           => __( 'Ημερομηνία', 'job-listings' ),
	);

}

add_filter( 'manage_' . JBLI_CPT . '_posts_columns', 'jbli_admin_columns' );

function jbli_admin_column_content( string $jbli_column, int $jbli_post_id ): void {

	switch ( $jbli_column ) {
		case 'job_pharmacy':
			echo esc_html( jbli_admin_meta_or_dash( $jbli_post_id, JBLI_META_PHARMACY_NAME ) );
			break;

		case 'job_nomos':
			echo esc_html( jbli_admin_terms_or_dash( $jbli_post_id, 'job_nomos' ) );
			break;

		case 'job_category':
			echo esc_html( jbli_admin_terms_or_dash( $jbli_post_id, 'job_category' ) );
			break;

		case 'job_type':
			$jbli_type = get_post_meta( $jbli_post_id, JBLI_META_TYPE, true );
			echo esc_html( is_string( $jbli_type ) && '' !== $jbli_type ? jbli_type_label( $jbli_type ) : '—' );
			break;

		case 'job_salary':
			$jbli_salary = get_post_meta( $jbli_post_id, JBLI_META_SALARY, true );
			echo esc_html( is_string( $jbli_salary ) && '' !== $jbli_salary ? jbli_salary_label( $jbli_salary ) : '—' );
			break;

		case 'job_expires':

			$jbli_expires = get_post_meta( $jbli_post_id, JBLI_META_EXPIRES, true );

			if ( $jbli_expires && false !== strtotime( (string ) $jbli_expires ) )
			{
				$jbli_ts = strtotime( (string) $jbli_expires );
				echo esc_html( function_exists( 'wp_date' ) ? wp_date( 'd/m/Y', $jbli_ts ) : date_i18n( 'd/m/Y', $jbli_ts ) );
			} 
			else 
			{
				echo '—';
			}

			break;

		case 'job_status_col':

			$post = get_post( $jbli_post_id );

			if ( $post instanceof WP_Post ) { echo wp_kses_post( jbli_status_badge( $post ) ); }

			break;
	}

}

add_action( 'manage_' . JBLI_CPT . '_posts_custom_column', 'jbli_admin_column_content', 10, 2 );

function jbli_admin_meta_or_dash( int $jbli_post_id, string $jbli_meta_key ): string {

	$jbli_value = get_post_meta( $jbli_post_id, $jbli_meta_key, true );

	return is_string( $jbli_value ) && '' !== trim( $jbli_value ) ? trim( $jbli_value ) : '—';

}

function jbli_admin_terms_or_dash( int $jbli_post_id, string $jbli_taxonomy ): string {

	$jbli_terms = wp_get_post_terms( $jbli_post_id, $jbli_taxonomy, array( 'fields' => 'names' ) );

	if ( is_wp_error( $jbli_terms ) || empty( $jbli_terms ) ) { return '—'; }

	return implode( ', ', $jbli_terms );

}

function jbli_admin_apply_filters( WP_Query $jbli_query ): void {

	if ( ! is_admin( ) || ! $jbli_query->is_main_query() || JBLI_CPT !== $jbli_query->get( 'post_type' ) ) { return; }

	$jbli_nomos  = absint( $_GET['job_filter_nomos'] ?? 0 );
	$jbli_cat    = absint( $_GET['job_filter_cat'] ?? 0 );
	$jbli_status = sanitize_key( wp_unslash( $_GET['job_filter_status'] ?? '' ) );

	jbli_admin_apply_tax_filter( $jbli_query, 'job_nomos', $jbli_nomos );
	jbli_admin_apply_tax_filter( $jbli_query, 'job_category', $jbli_cat );

	$jbli_allowed_statuses = jbli_admin_filter_statuses();

	if ( $jbli_status && in_array( $jbli_status, $jbli_allowed_statuses, true ) )
	{
		$jbli_query->set( 'post_status', $jbli_status );
	}
	elseif ( ! $jbli_query->get( 'post_status' ) ) 
	{
		$jbli_query->set( 'post_status', $jbli_allowed_statuses );
	}

	$jbli_query->set( 'update_post_meta_cache', false );
	$jbli_query->set( 'update_post_term_cache', false );

}

add_action( 'pre_get_posts', 'jbli_admin_apply_filters' );

function jbli_admin_apply_tax_filter( WP_Query $jbli_query, string $jbli_taxonomy, int $jbli_term_id ): void {

	if ( ! $jbli_term_id ) { return; }

	$jbli_tax_query   = (array) $jbli_query->get( 'tax_query' );
	$jbli_tax_query[] = array( 'taxonomy' => $jbli_taxonomy, 'field'    => 'term_id', 'terms'    => $jbli_term_id, );

	$jbli_query->set( 'tax_query', $jbli_tax_query );

}

function jbli_admin_filter_statuses(): array {

	return array( 'publish', 'draft', 'pending', 'job-expired', );

}

function jbli_admin_filter_dropdowns(): void {

	global $jbli_typenow;

	if ( JBLI_CPT !== $jbli_typenow ) { return; }

	$jbli_selected_nomos  = absint( $_GET['job_filter_nomos'] ?? 0 );
	$jbli_selected_cat    = absint( $_GET['job_filter_cat'] ?? 0 );
	$jbli_selected_status = sanitize_key( wp_unslash( $_GET['job_filter_status'] ?? '' ) );

	jbli_admin_tax_dropdown(
		'job_filter_nomos',
		'job_nomos',
		__( 'Όλοι οι νομοί', 'job-listings' ),
		$jbli_selected_nomos
	);

	jbli_admin_tax_dropdown(
		'job_filter_cat',
		'job_category',
		__( 'Όλες οι κατηγορίες', 'job-listings' ),
		$jbli_selected_cat
	);

	jbli_admin_status_dropdown( $jbli_selected_status );

}

add_action( 'restrict_manage_posts', 'jbli_admin_filter_dropdowns' );

function jbli_admin_tax_dropdown( string $jbli_name, string $jbli_taxonomy, string $jbli_placeholder, int $jbli_selected ): void {

	$jbli_cache_key = 'admin_tax_' . md5( $jbli_taxonomy );

	$jbli_terms 	= wp_cache_get( $jbli_cache_key, 'job-listings' );

	if ( false === $jbli_terms )
	{
		$jbli_terms = get_terms(
			array(
				'taxonomy'   => $jbli_taxonomy,
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		wp_cache_set( $jbli_cache_key, $jbli_terms, 'job-listings', HOUR_IN_SECONDS );
	}

	echo '<select name="' . esc_attr( $jbli_name ) . '">';
	echo '<option value="">' . esc_html( $jbli_placeholder ) . '</option>';

	if ( ! is_wp_error( $jbli_terms ) && is_array( $jbli_terms ) )
	{
		foreach ( $jbli_terms as $jbli_term ) {

			if ( ! $jbli_term instanceof WP_Term ) { continue; }

			printf(
				'<option value="%d"%s>%s</option>',
				(int) $jbli_term->term_id,
				selected( $jbli_selected, (int) $jbli_term->term_id, false ),
				esc_html( $jbli_term->name )
			);

		}
	}

	echo '</select>';

}

function jbli_admin_status_dropdown( string $jbli_selected ): void {

	$jbli_statuses = array(
		''            => __( 'Όλες οι καταστάσεις', 'job-listings' ),
		'publish'     => __( 'Ενεργές', 'job-listings' ),
		'draft'       => __( 'Ανενεργές', 'job-listings' ),
		'job-expired' => __( 'Ληγμένες', 'job-listings' ),
		'pending'     => __( 'Σε αναμονή', 'job-listings' ),
	);

	echo '<select name="job_filter_status">';

	foreach ( $jbli_statuses as $jbli_value => $jbli_label ) {

		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $jbli_value ),
			selected( $jbli_selected, $jbli_value, false ),
			esc_html( $jbli_label )
		);

	}

	echo '</select>';

}

function jbli_admin_row_actions( array $jbli_actions, WP_Post $post ): array {

	if ( JBLI_CPT !== $post->post_type || ! current_user_can( 'manage_options' ) ) { return $jbli_actions; }

	if ( jbli_admin_is_expired( $post ) )
	{
		$jbli_actions['job_renew'] = jbli_admin_action_link(
			$post->ID,
			'renew',
			__( 'Ανανέωση', 'job-listings' )
		);
	}

	if ( 'publish' === $post->post_status )
	{
		$jbli_actions['job_deactivate'] = jbli_admin_action_link(
			$post->ID,
			'jbli_deactivate',
			__( 'Παύση', 'job-listings' )
		);
	}

	if ( in_array( $post->post_status, array( 'draft', 'job-expired' ), true ) )
	{
		$jbli_actions['job_activate'] = jbli_admin_action_link(
			$post->ID,
			'jbli_activate',
			__( 'Ενεργοποίηση', 'job-listings' )
		);
	}

	return $jbli_actions;

}

add_filter( 'post_row_actions', 'jbli_admin_row_actions', 10, 2 );

function jbli_admin_is_expired( WP_Post $post ): bool {

	return 'job-expired' === $post->post_status || (bool) get_post_meta( $post->ID, JBLI_META_EXPIRED, true );

}

function jbli_admin_action_link( int $jbli_post_id, string $jbli_action, string $jbli_label ): string {

	$jbli_url = wp_nonce_url(
		add_query_arg(
			array( 'action'  => 'jbli_admin_action', 'job_act' => $jbli_action, 'post_id' => $jbli_post_id, ),
			admin_url( 'admin-post.php' )
		),
		'jbli_admin_' . $jbli_action . '_' . $jbli_post_id
	);

	return '<a href="' . esc_url( $jbli_url ) . '">' . esc_html( $jbli_label ) . '</a>';

}

function jbli_admin_handle_row_action(): void {

	if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Unauthorized', 'job-listings' ), 403 ); }

	$jbli_action  = sanitize_key( wp_unslash( $_GET['job_act'] ?? '' ) );
	$jbli_post_id = absint( $_GET['post_id'] ?? 0 );
	$jbli_nonce   = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );

	if ( ! jbli_admin_is_valid_action_request( $jbli_post_id, $jbli_action, $jbli_nonce ) )
	{
		wp_die( esc_html__( 'Invalid request', 'job-listings' ) );
	}

	$jbli_result = true;

	switch ( $jbli_action ) {
		case 'renew':
			$jbli_result = jbli_renew_job( $jbli_post_id );
			break;

		case 'jbli_deactivate':
			$jbli_result = jbli_admin_deactivate_listing( $jbli_post_id );
			break;

		case 'jbli_activate':
			$jbli_result = jbli_admin_activate_listing( $jbli_post_id );
			break;
	}

	if ( is_wp_error( $jbli_result ) )
	{
		wp_die(
			esc_html( $jbli_result->get_error_message() ),
			500
		);
	}

	jbli_admin_redirect_after_action( $jbli_action );

}

add_action( 'admin_post_jbli_admin_action', 'jbli_admin_handle_row_action' );

function jbli_admin_is_valid_action_request( int $jbli_post_id, string $jbli_action, string $jbli_nonce ): bool {

	if ( ! $jbli_post_id || ! in_array( $jbli_action, array( 'renew', 'jbli_deactivate', 'jbli_activate' ), true ) )
	{
		return false;
	}

	if ( ! wp_verify_nonce( $jbli_nonce, 'jbli_admin_' . $jbli_action . '_' . $jbli_post_id ) ) { return false; }

	$post = get_post( $jbli_post_id );

	return $post instanceof WP_Post && JBLI_CPT === $post->post_type;

}

function jbli_admin_deactivate_listing( int $jbli_post_id ) {

	if ( function_exists( 'jbli_deactivate_job' ) ) { return jbli_deactivate_job( $jbli_post_id ); }

	return new WP_Error( 'missing_helper', __( 'Deactivate function not available.', 'job-listings' ) );

}

function jbli_admin_activate_listing( int $jbli_post_id ) {

	if ( function_exists( 'jbli_activate_job' ) ) { return jbli_activate_job( $jbli_post_id ); }

	return new WP_Error( 'missing_helper', __( 'Activate function not available.', 'job-listings' ) );

}

function jbli_admin_redirect_after_action( string $jbli_action ): void {

	wp_safe_redirect(
		add_query_arg(
			array( 'post_type' => JBLI_CPT, 'job_done' => sanitize_key( $jbli_action ), ),
			admin_url( 'edit.php' )
		)
	);
	exit;

}

function jbli_admin_action_notice(): void {

	$jbli_screen = get_current_screen();

	if ( ! $jbli_screen || JBLI_CPT !== $jbli_screen->post_type ) { return; }

	$jbli_messages = array(
		'renew'           => __( 'Ανανεώθηκε.', 'job-listings' ),
		'jbli_deactivate' => __( 'Απενεργοποιήθηκε.', 'job-listings' ),
		'jbli_activate'   => __( 'Ενεργοποιήθηκε.', 'job-listings' ),
	);


	$jbli_done = sanitize_key( wp_unslash( $_GET['job_done'] ?? '' ) );

	if ( ! isset( $jbli_messages[ $jbli_done ] ) ) { return; }

	echo '<div class="notice notice-success is-dismissible"><p>'
		. esc_html( $jbli_messages[ $jbli_done ] )
		. '</p></div>';

}

add_action( 'admin_notices', 'jbli_admin_action_notice' );
