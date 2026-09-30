<?php
/**
 * Module: Pharmacist Dashboard
 *
 * Shortcode: [dashboard]
 *
 * @package JobListings
 * @since   9.6.6
 */

defined( 'ABSPATH' ) || exit;

function jbli_render_dashboard() {

	ob_start();

	if ( ! is_user_logged_in( ) )
	{
		$jbli_html = function_exists( 'jbli_login_gate_html' )
			? jbli_login_gate_html(
				__( 'Οι αγγελίες του φαρμακείου σας, σε ένα σημείο', 'job-listings' ),
				__( 'Συνδεθείτε με τον λογαριασμό του φαρμακείου σας για να δείτε και να διαχειριστείτε τις αγγελίες σας.', 'job-listings' ),
				'',
				array(
					__( 'Ενεργές, ληγμένες και ανενεργές αγγελίες με μια ματιά', 'job-listings' ),
					__( 'Επεξεργασία, παύση και ανανέωση', 'job-listings' ),
					__( 'Υπενθύμιση με email πριν τη λήξη', 'job-listings' ),
				)
			)
			: '';
		echo $jbli_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML component.

		return ob_get_clean();
	}

	if ( ! jbli_is_pharmacist( ) && ! jbli_is_admin() )
	{
		$jbli_html = jbli_notice(
			__( 'Το dashboard είναι διαθέσιμο μόνο σε εγγεγραμμένα φαρμακεία.', 'job-listings' ),
			'error'
		);
		echo $jbli_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML component.

		return ob_get_clean();
	}

	$jbli_notice  = jbli_print_transient_notice();
	$jbli_user_id = get_current_user_id();

	if ( function_exists( 'jbli_get_dashboard_listing_ids' ) )
	{
		$jbli_listing_ids = array_map(
			'absint',
			(array) jbli_get_dashboard_listing_ids( $jbli_user_id, 100 )
		);

		$jbli_listing_ids = array_filter( $jbli_listing_ids );

		if ( ! empty( $jbli_listing_ids ) )
		{
			$jbli_jobs = get_posts(
				array(
					'post_type'              => JBLI_CPT,
					'post_status'            => array( 'publish', 'draft', 'pending', 'job-expired' ),
					'post__in'               => $jbli_listing_ids,
					'orderby'                => 'post__in',
					'posts_per_page'         => count( $jbli_listing_ids ),
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
					'no_found_rows'          => true,
				)
			);

			$jbli_jobs = is_array( $jbli_jobs )
				? $jbli_jobs
				: array();
		} 
		else 
		{
			$jbli_jobs = array();
		}
	} 
	else 
	{
		$jbli_jobs = get_posts(
			array(
				'post_type'              => JBLI_CPT,
				'author'                 => $jbli_user_id,
				'post_status'            => array( 'publish', 'draft', 'pending', 'job-expired' ),
				'posts_per_page'         => 100,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		$jbli_jobs = is_array( $jbli_jobs )
			? $jbli_jobs
			: array();
	}

	$jbli_stats = array( 'active'  => 0, 'expired' => 0, 'draft'   => 0, );

	foreach ( $jbli_jobs as $jbli_job ) {

		if ( ! $jbli_job instanceof WP_Post ) { continue; }

		switch ( $jbli_job->post_status ) {
			case 'publish':
				$jbli_stats['active']++;
				break;

			case 'job-expired':
				$jbli_stats['expired']++;
				break;

			default:
				$jbli_stats['draft']++;
				break;
		}

	}

	$jbli_form_url = jbli_get_form_page_url();

	$jbli_dash_url = get_permalink();

	if ( ! is_string( $jbli_dash_url ) ) { $jbli_dash_url = ''; }

	if ( '' === $jbli_dash_url )
	{
		$jbli_dash_url = function_exists( 'jbli_get_dashboard_url' )
			? jbli_get_dashboard_url()
			: home_url( '/dashboard/' );
	}

	$jbli_template = __DIR__ . '/jbli-dashboard-template.php';

	if ( is_string( $jbli_template ) && is_readable( $jbli_template ) )
	{
		include $jbli_template;
	}

	return ob_get_clean();

}

add_shortcode( 'dashboard', 'jbli_render_dashboard' );

function jbli_dash_adminpost_handler() {

	if ( ! is_user_logged_in( ) )
	{
		$jbli_dashboard_url = function_exists( 'jbli_get_dashboard_url' )
			? jbli_get_dashboard_url()
			: home_url( '/dashboard/' );
		wp_safe_redirect( wp_login_url( $jbli_dashboard_url ) );
		exit;
	}

	$jbli_action   = sanitize_key( wp_unslash( $_POST['job_listing_dash_action'] ?? '' ) );
	$jbli_post_id  = absint( $_POST['job_post_id'] ?? 0 );
	$jbli_nonce    = sanitize_text_field( wp_unslash( $_POST['_job_listing_dash_nonce'] ?? '' ) );
	$jbli_raw_back = esc_url_raw( wp_unslash( $_POST['jbli_back_url'] ?? '' ) );

	$jbli_back_url = wp_validate_redirect(
		$jbli_raw_back,
		function_exists( 'jbli_get_dashboard_url' )
			? jbli_get_dashboard_url()
			: home_url( '/dashboard/' )
	);

	if ( ! $jbli_post_id || ! $jbli_action )
	{
		jbli_redirect_with_notice( $jbli_back_url, __( 'Μη έγκυρο αίτημα.', 'job-listings' ), 'error' );
	}

	if ( ! wp_verify_nonce( $jbli_nonce, 'jbli_dash_' . $jbli_action . '_' . $jbli_post_id ) )
	{
		jbli_redirect_with_notice( $jbli_back_url, __( 'Σφάλμα ασφαλείας. Παρακαλώ δοκιμάστε ξανά.', 'job-listings' ), 'error' );
	}

	if ( ! jbli_is_pharmacist( ) && ! jbli_is_admin() )
	{
		jbli_redirect_with_notice( $jbli_back_url, __( 'Δεν έχετε άδεια.', 'job-listings' ), 'error' );
	}

	$post = get_post( $jbli_post_id );

	if ( ! $post instanceof WP_Post || JBLI_CPT !== $post->post_type )
	{
		jbli_redirect_with_notice( $jbli_back_url, __( 'Η αγγελία δεν βρέθηκε.', 'job-listings' ), 'error' );
	}

	if ( ! jbli_can_edit_listing( $jbli_post_id ) )
	{
		jbli_redirect_with_notice( $jbli_back_url, __( 'Δεν έχετε άδεια για αυτή την ενέργεια.', 'job-listings' ), 'error' );
	}

	switch ( $jbli_action ) {

		case 'delete':
			$jbli_title = get_post_meta( (int) $jbli_post_id, JBLI_META_POSITION, true );

			if ( ! $jbli_title ) { $jbli_title = get_the_title( (int) $jbli_post_id ); }

			$jbli_deleted = wp_trash_post( (int) $jbli_post_id );

			clean_post_cache(
				(int) $jbli_post_id
			);

			if ( ! $jbli_deleted )
			{
				jbli_redirect_with_notice(
					$jbli_back_url,
					__( 'Δεν ήταν δυνατή η διαγραφή της αγγελίας.', 'job-listings' ),
					'error'
				);
			}

			do_action( 'jbli_deleted', (int) $jbli_post_id );

			jbli_redirect_with_notice(
				$jbli_back_url,
				sprintf(
					/* translators: %s: Job listing title */
					__( '🗑️ Η αγγελία «%s» μεταφέρθηκε στον κάδο.', 'job-listings' ),
					wp_strip_all_tags( (string) $jbli_title )
				),
				'success'
			);

			break;

		case 'renew':

			if ( ! function_exists( 'jbli_renew_job' ) )
			{
				jbli_redirect_with_notice(
					$jbli_back_url,
					__( 'Η λειτουργία ανανέωσης δεν είναι διαθέσιμη.', 'job-listings' ),
					'error'
				);
			}

			$jbli_guard = jbli_dashboard_publish_guard( (int) $jbli_post_id, 'renew' );

			if ( null !== $jbli_guard ) { jbli_redirect_with_notice( $jbli_back_url, $jbli_guard, 'warning' ); }

			$jbli_renewed = jbli_renew_job( (int) $jbli_post_id );

			if ( is_wp_error( $jbli_renewed ) )
			{
				jbli_redirect_with_notice(
					$jbli_back_url,
					__( 'Δεν ήταν δυνατή η ανανέωση της αγγελίας.', 'job-listings' ),
					'error'
				);
			}

			clean_post_cache( (int) $jbli_post_id );

			jbli_redirect_with_notice(
				$jbli_back_url,
				__( '🔄 Η αγγελία ανανεώθηκε — 30 ακόμα ημέρες.', 'job-listings' ),
				'success'
			);

			break;

		case 'jbli_deactivate':

			if ( ! function_exists( 'jbli_deactivate_job' ) )
			{
				jbli_redirect_with_notice(
					$jbli_back_url,
					__( 'Σφάλμα συστήματος.', 'job-listings' ),
					'error'
				);
			}

			$jbli_deactivated = jbli_deactivate_job( (int) $jbli_post_id );

			if ( is_wp_error( $jbli_deactivated ) )
			{
				jbli_redirect_with_notice(
					$jbli_back_url,
					__( 'Δεν ήταν δυνατή η απενεργοποίηση της αγγελίας.', 'job-listings' ),
					'error'
				);
			}

			clean_post_cache( (int) $jbli_post_id );

			jbli_redirect_with_notice(
				$jbli_back_url,
				__( '⏸️ Η αγγελία απενεργοποιήθηκε.', 'job-listings' ),
				'success'
			);

			break;

		case 'jbli_activate':

			if ( ! function_exists( 'jbli_activate_job' ) )
			{
				jbli_redirect_with_notice(
					$jbli_back_url,
					__( 'Σφάλμα συστήματος.', 'job-listings' ),
					'error'
				);
			}

			/* A listing awaiting approval is published by an admin, not by its owner. */
			if ( 'pending' === get_post_status( (int) $jbli_post_id ) && ! jbli_is_admin() )
			{
				jbli_redirect_with_notice(
					$jbli_back_url,
					__( 'Η αγγελία περιμένει έγκριση από τον διαχειριστή.', 'job-listings' ),
					'warning'
				);
			}

			$jbli_guard = jbli_dashboard_publish_guard( (int) $jbli_post_id, 'activate' );

			if ( null !== $jbli_guard ) { jbli_redirect_with_notice( $jbli_back_url, $jbli_guard, 'warning' ); }

			$jbli_activated = jbli_activate_job( (int) $jbli_post_id );

			if ( is_wp_error( $jbli_activated ) )
			{
				jbli_redirect_with_notice(
					$jbli_back_url,
					__( 'Δεν ήταν δυνατή η ενεργοποίηση της αγγελίας.', 'job-listings' ),
					'error'
				);
			}

			clean_post_cache( (int) $jbli_post_id );

			jbli_redirect_with_notice(
				$jbli_back_url,
				__( '✅ Η αγγελία ενεργοποιήθηκε.', 'job-listings' ),
				'success'
			);

			break;

		default:
			jbli_redirect_with_notice( $jbli_back_url, __( 'Άγνωστη ενέργεια.', 'job-listings' ), 'error' );
	}

}

add_action( 'admin_post_job_listing_dash_action', 'jbli_dash_adminpost_handler' );

add_action( 'admin_post_nopriv_job_listing_dash_action', 'jbli_dash_adminpost_handler' );

function jbli_dash_action_btn(
	$jbli_post_id,
	$jbli_action,
	$jbli_label,
	$jbli_css      = '',
	$jbli_back_url = ''
) {

	$jbli_post_id = absint(
		$jbli_post_id
	);
	$jbli_action   = sanitize_key( $jbli_action );
	$jbli_label    = (string) $jbli_label;
	$jbli_css      = (string) $jbli_css;
	$jbli_back_url = (string) $jbli_back_url;

	$jbli_allowed_actions = array( 'delete', 'renew', 'jbli_deactivate', 'jbli_activate' );

	if ( ! in_array( $jbli_action, $jbli_allowed_actions, true ) ) { return ''; }

	$jbli_nonce    = wp_create_nonce( 'jbli_dash_' . $jbli_action . '_' . $jbli_post_id );
	$jbli_back_url = wp_validate_redirect(
		$jbli_back_url,
		function_exists( 'jbli_get_dashboard_url' )
			? jbli_get_dashboard_url()
			: home_url( '/dashboard/' )
	);
	$jbli_confirm  = '';

	if ( 'delete' === $jbli_action )
	{
		$jbli_confirm = ' data-confirm="' .
			esc_attr__( 'Να διαγραφεί η αγγελία; Θα μεταφερθεί στον κάδο.', 'job-listings' )
			. '"';
	}

	return
		'<form method="post" action="' . esc_url( $jbli_back_url ) . '" class="jbli_inline_form jbli_dash_action_form">'
		. '<input type="hidden" name="action" value="job_listing_dash_action">'
		. '<input type="hidden" name="job_listing_dash_action" value="' . esc_attr( $jbli_action ) . '">'
		. '<input type="hidden" name="job_post_id" value="' . esc_attr( (string) $jbli_post_id ) . '">'
		. '<input type="hidden" name="_job_listing_dash_nonce" value="' . esc_attr( $jbli_nonce ) . '">'
		. '<input type="hidden" name="jbli_back_url" value="' . esc_attr( $jbli_back_url ) . '">'
		. '<button type="submit" class="jbli_btn ' . esc_attr( $jbli_css ) . '"' . $jbli_confirm . '>'
		. esc_html( $jbli_label )
		. '</button>'
		. '</form>';
}

/**
 * Server-side checks before an owner re-publishes a listing from the dashboard.
 *
 * - A listing an admin deactivated stays off until an admin publishes it.
 * - «Ανανέωση» only applies to expired listings (not pending / drafts).
 * - Publishing must not exceed the active-listings cap.
 *
 * @since 9.9.57
 *
 * @param int    $jbli_post_id Listing ID.
 * @param string $jbli_action  'renew' | 'activate'.
 * @return string|null Message to show, or null when allowed.
 */
function jbli_dashboard_publish_guard( $jbli_post_id, $jbli_action ) {

	if ( jbli_is_admin() ) { return null; }

	$jbli_status = (string) get_post_status( $jbli_post_id );

	/* Already public (e.g. flagged expired but not yet moved by cron): renewing adds nothing to the active count. */
	if ( 'publish' === $jbli_status )
	{
		return ( 'renew' === $jbli_action && ! get_post_meta( $jbli_post_id, JBLI_META_EXPIRED, true ) )
			? __( 'Η αγγελία είναι ήδη ενεργή.', 'job-listings' )
			: null;
	}

	if ( defined( 'JBLI_META_ADMIN_HIDDEN' ) && get_post_meta( $jbli_post_id, JBLI_META_ADMIN_HIDDEN, true ) )
	{
		return __( 'Η αγγελία απενεργοποιήθηκε από τον διαχειριστή. Επικοινωνήστε μαζί μας για να ενεργοποιηθεί ξανά.', 'job-listings' );
	}

	if ( 'pending' === $jbli_status ) { return __( 'Η αγγελία περιμένει έγκριση από τον διαχειριστή.', 'job-listings' ); }

	if ( 'renew' === $jbli_action && 'job-expired' !== $jbli_status && ! get_post_meta( $jbli_post_id, JBLI_META_EXPIRED, true ) )
	{
		return __( 'Μόνο αγγελίες που έχουν λήξει μπορούν να ανανεωθούν.', 'job-listings' );
	}

	if ( function_exists( 'jbli_owner_at_active_cap' ) && jbli_owner_at_active_cap( (int) get_post_field( 'post_author', $jbli_post_id ) ) )
	{
		return sprintf(
			/* translators: %d: maximum number of active listings */
			__( 'Έχετε φτάσει το μέγιστο όριο ενεργών αγγελιών (%d). Απενεργοποιήστε μια άλλη πρώτα.', 'job-listings' ),
			(int) apply_filters( 'jbli_max_active_per_user', 5 )
		);
	}

	return null;

}
