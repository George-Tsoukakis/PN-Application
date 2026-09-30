<?php
/**
 * Taxonomies
 *
 * @package JobListings
 * @since   9.6.6
 */

defined( 'ABSPATH' ) || exit;

function jbli_register_taxonomies() {

	register_taxonomy(
		'job_category',
		array( JBLI_CPT ),
		array(

			'labels' 				=> jbli_category_taxonomy_labels(),
			'hierarchical' 			=> true,
			'public'             	=> true,
			'publicly_queryable' 	=> true,
			'show_ui'           	=> true,
			'show_admin_column' 	=> true,
			'show_in_nav_menus' 	=> false,
			'show_in_rest' 			=> false,
			'rest_base'    			=> 'job-categories',
			'query_var' 			=> true,
			'capabilities' 			=> jbli_taxonomy_capabilities(),
			'rewrite' 				=> array( 'slug' => 'eidikotita', 'with_front'   => false, 'hierarchical' => true, ),
			'sort'	 				=> true,
		)
	);

	register_taxonomy(
		'job_nomos',
		array( JBLI_CPT ),
		array(

			'labels' 				=> jbli_nomos_taxonomy_labels(),
			'hierarchical' 			=> true,
			'public'             	=> true,
			'publicly_queryable' 	=> true,
			'show_ui'           	=> true,
			'show_admin_column' 	=> true,
			'show_in_nav_menus' 	=> false,
			'show_in_rest' 			=> false,
			'rest_base'    			=> 'job-nomoi',
			'query_var' 			=> true,
			'capabilities' 			=> jbli_taxonomy_capabilities(),
			'rewrite' 				=> array( 'slug'         => 'nomos', 'with_front'   => false, 'hierarchical' => true, ),
			'sort'	 				=> true,
		)
	);

}

add_action( 'init', 'jbli_register_taxonomies' );
add_action( 'admin_init', 'jbli_seed_terms', 20 );

function jbli_seed_terms() {

	if ( ! get_option( 'jbli_seeded_v1' ) )
	{
		jbli_seed_nomoi();
		update_option( 'jbli_seeded_v1', 1, false );
	}

	if ( ! get_option( 'jbli_seeded_v3' ) )
	{
		jbli_replace_all_categories();
		update_option( 'jbli_seeded_v3', 1, false );
	}

}

function jbli_replace_all_categories() {

	$jbli_definitive = array(
		'Φαρμακοποιός',
		'Βοηθός Φαρμακείου',
		'Τεχνικός Φαρμάκων',
		'Αισθητικός / Beauty Advisor',
		'Αποθηκάριος',
		'Υπεύθυνος E-shop Φαρμακείου',
		'Delivery / Διανομέας',
		'Υπεύθυνος Παραφαρμακείου',
		'Υπεύθυνος Φαρμακείου',
	);

	foreach ( $jbli_definitive as $jbli_name ) {

		if ( term_exists( $jbli_name, 'job_category' ) ) { continue; }

		wp_insert_term( $jbli_name, 'job_category' );

	}

}

/**
 * Category taxonomy labels.
 *
 * @return array
 */
function jbli_category_taxonomy_labels() {

	return array(

		'name' => _x(
			'Κατηγορίες Εργασίας',
			'taxonomy general name',
			'job-listings'
		),

		'singular_name' => _x(
			'Κατηγορία',
			'taxonomy singular name',
			'job-listings'
		),

		'search_items' => __(
			'Αναζήτηση Κατηγοριών',
			'job-listings'
		),

		'all_items' => __(
			'Όλες οι Κατηγορίες',
			'job-listings'
		),

		'parent_item' => __(
			'Γονική Κατηγορία',
			'job-listings'
		),

		'parent_item_colon' => __(
			'Γονική Κατηγορία:',
			'job-listings'
		),

		'edit_item' => __(
			'Επεξεργασία Κατηγορίας',
			'job-listings'
		),

		'update_item' => __(
			'Ενημέρωση Κατηγορίας',
			'job-listings'
		),

		'add_new_item' => __(
			'Νέα Κατηγορία',
			'job-listings'
		),

		'new_item_name' => __(
			'Όνομα νέας κατηγορίας',
			'job-listings'
		),

		'menu_name' => __(
			'Κατηγορίες',
			'job-listings'
		),

		'not_found' => __(
			'Δεν βρέθηκαν κατηγορίες.',
			'job-listings'
		),

		'items_list_navigation' => __(
			'Πλοήγηση λίστας κατηγοριών',
			'job-listings'
		),

		'items_list' => __(
			'Λίστα κατηγοριών',
			'job-listings'
		),

		'back_to_items' => __(
			'Πίσω στις κατηγορίες',
			'job-listings'
		),
	);

}

/**
 * Nomos taxonomy labels.
 *
 * @return array
 */
function jbli_nomos_taxonomy_labels() {

	return array(

		'name' => _x(
			'Νομοί',
			'taxonomy general name',
			'job-listings'
		),

		'singular_name' => _x(
			'Νομός',
			'taxonomy singular name',
			'job-listings'
		),

		'search_items' => __(
			'Αναζήτηση Νομών',
			'job-listings'
		),

		'all_items' => __(
			'Όλοι οι Νομοί',
			'job-listings'
		),

		'parent_item' => __(
			'Γονικός Νομός',
			'job-listings'
		),

		'parent_item_colon' => __(
			'Γονικός Νομός:',
			'job-listings'
		),

		'edit_item' => __(
			'Επεξεργασία Νομού',
			'job-listings'
		),

		'update_item' => __(
			'Ενημέρωση Νομού',
			'job-listings'
		),

		'add_new_item' => __(
			'Νέος Νομός',
			'job-listings'
		),

		'new_item_name' => __(
			'Όνομα νέου νομού',
			'job-listings'
		),

		'menu_name' => __(
			'Νομοί',
			'job-listings'
		),

		'not_found' => __(
			'Δεν βρέθηκαν νομοί.',
			'job-listings'
		),

		'items_list_navigation' => __(
			'Πλοήγηση λίστας νομών',
			'job-listings'
		),

		'items_list' => __(
			'Λίστα νομών',
			'job-listings'
		),

		'back_to_items' => __(
			'Πίσω στους νομούς',
			'job-listings'
		),
	);

}

/**
 * Shared taxonomy capabilities.
 *
 * @return array
 */
function jbli_taxonomy_capabilities() {

	return array(
		'manage_terms' => 'manage_options',
		'edit_terms'   => 'manage_options',
		'delete_terms' => 'manage_options',
		'assign_terms' => 'edit_job_listings',
	);

}

function jbli_seed_nomoi() {

	$jbli_nomoi = array(
		'Αττική',
		'Αιτωλία & Ακαρνανία',
		'Αργολίδα',
		'Αρκαδία',
		'Άρτα',
		'Αχαΐα',
		'Βοιωτία',
		'Γρεβενά',
		'Δράμα',
		'Δωδεκάνησος',
		'Έβρος',
		'Ευβοία',
		'Ευρυτανία',
		'Ζάκυνθος',
		'Ηλεία',
		'Ημαθία',
		'Ηράκλειο',
		'Θεσπρωτία',
		'Θεσσαλονίκη',
		'Ιωάννινα',
		'Καβάλα',
		'Καρδίτσα',
		'Καστοριά',
		'Κέρκυρα',
		'Κεφαλληνία',
		'Κιλκίς',
		'Κοζάνη',
		'Κορινθία',
		'Κυκλάδες',
		'Λακωνία',
		'Λάρισα',
		'Λασίθι',
		'Λέσβος',
		'Λευκάδα',
		'Μαγνησία',
		'Μεσσηνία',
		'Ξάνθη',
		'Πέλλα',
		'Πιερία',
		'Πρέβεζα',
		'Ρέθυμνο',
		'Ροδόπη',
		'Σάμος',
		'Σέρρες',
		'Τρίκαλα',
		'Φθιώτιδα',
		'Φλώρινα',
		'Φωκίδα',
		'Χαλκιδική',
		'Χανιά',
		'Χίος',
	);

	foreach ( $jbli_nomoi as $jbli_name ) {

		jbli_maybe_insert_term( $jbli_name, 'job_nomos' );

	}

}

/**
 * Insert term if missing.
 *
 * @param string $jbli_name     Term name.
 * @param string $jbli_taxonomy Taxonomy name.
 * @return void
 */
function jbli_maybe_insert_term( $jbli_name, $jbli_taxonomy ) {

	$jbli_name     = sanitize_text_field( $jbli_name );
	$jbli_taxonomy = sanitize_key( $jbli_taxonomy );

	if ( '' === $jbli_name || '' === $jbli_taxonomy ) { return; }

	$jbli_exists = term_exists( $jbli_name, $jbli_taxonomy );

	if ( ! empty( $jbli_exists ) ) { return; }

	$jbli_result = wp_insert_term( $jbli_name, $jbli_taxonomy );

	if ( is_wp_error( $jbli_result ) && defined( 'WP_DEBUG' ) && WP_DEBUG )
	{
		error_log(
			sprintf(
				'[Job Listings] Failed inserting term "%s" into taxonomy "%s"',
				$jbli_name,
				$jbli_taxonomy
			)
		);
	}

}
