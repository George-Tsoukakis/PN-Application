<?php
/**
 * Custom Post Type: job_listing
 *
 * @package JobListings
 * @since   9.7.9
 */

defined( 'ABSPATH' ) || exit;

function jbli_register_post_type() {

	register_post_type(
		JBLI_CPT,
		jbli_post_type_args()
	);

	jbli_register_post_statuses();

}

add_action( 'init', 'jbli_register_post_type', 5 );

/**
 * Post type labels.
 *
 * @return array
 */
function jbli_post_type_labels() {

	return array(
		'name'           		=> _x( 'Αγγελίες Φαρμακείων', 'post type general name', 'job-listings' ),
		'singular_name'  		=> _x( 'Αγγελία', 'post type singular name', 'job-listings' ),
		'menu_name'      		=> _x( 'Αγγελίες', 'admin menu', 'job-listings' ),
		'name_admin_bar' 		=> _x( 'Αγγελία', 'add new on admin bar', 'job-listings' ),

		'add_new'      			=> _x( 'Νέα Αγγελία', 'job listing', 'job-listings' ),
		'add_new_item' 			=> __( 'Προσθήκη Αγγελίας', 'job-listings' ),

		'new_item'  			=> __( 'Νέα Αγγελία', 'job-listings' ),
		'edit_item' 			=> __( 'Επεξεργασία Αγγελίας', 'job-listings' ),
		'view_item' 			=> __( 'Προβολή Αγγελίας', 'job-listings' ),

		'all_items'    			=> __( 'Όλες οι Αγγελίες', 'job-listings' ),
		'search_items' 			=> __( 'Αναζήτηση Αγγελιών', 'job-listings' ),

		'parent_item_colon'     => __( 'Γονική Αγγελία:', 'job-listings' ),

		'not_found'          	=> __( 'Δεν βρέθηκαν αγγελίες.', 'job-listings' ),
		'not_found_in_trash' 	=> __( 'Ο κάδος είναι άδειος.', 'job-listings' ),

		'archives'   			=> __( 'Αρχείο Αγγελιών', 'job-listings' ),
		'attributes' 			=> __( 'Χαρακτηριστικά Αγγελίας', 'job-listings' ),

		'insert_into_item'      => __( 'Εισαγωγή στην αγγελία', 'job-listings' ),
		'uploaded_to_this_item' => __( 'Μεταφορτώθηκε σε αυτή την αγγελία', 'job-listings' ),

		'filter_items_list'     => __( 'Φιλτράρισμα λίστας αγγελιών', 'job-listings' ),
		'items_list_navigation' => __( 'Πλοήγηση λίστας αγγελιών', 'job-listings' ),
		'items_list'            => __( 'Λίστα αγγελιών', 'job-listings' ),
	);

}

/**
 * Post type args.
 *
 * @return array
 */
function jbli_post_type_args() {

	return array(

		'labels' => jbli_post_type_labels(),

		'description' => __( 'Αγγελίες εργασίας για φαρμακεία.', 'job-listings' ),

		'public'              	=> true,
		'publicly_queryable'  	=> true,
		'exclude_from_search' 	=> true,
		'query_var'           	=> true,

		'show_ui'           	=> true,
		'show_in_menu'      	=> false,
		'show_in_admin_bar' 	=> false,
		'show_in_nav_menus' 	=> false,

		'show_in_rest'          => false,

		'supports' => array( 'jbli_title', 'editor', 'author', 'custom-fields', ),

		'rewrite' => array(
			'slug'       => 'aggelia',
			'with_front' => false,
			'feeds'      => false,
			'pages'      => true,
		),

		'has_archive' => false,

		'capability_type' => array( 'job_listing', 'job_listings', ),

		'map_meta_cap' => true,

		'hierarchical'  => false,
		'menu_position' => 25,
		'menu_icon'     => 'dashicons-businessperson',

		'can_export'       => false,
		'delete_with_user' => false,
	);

}

function jbli_register_post_statuses() {

	register_post_status(
		'job-expired',
		array(

			'jbli_label' => _x(
				'Έληξε',
				'post status',
				'job-listings'
			),

			'public'    				=> false,
			'internal'  				=> false,
			'protected' 				=> false,
			'private'   				=> false,
			'exclude_from_search' 		=> true,
			'show_in_rest'        		=> false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,

			/* translators: %s: Number of expired listings */
			'label_count' => _n_noop(
				'Ληγμένη <span class="count">(%s)</span>',
				'Ληγμένες <span class="count">(%s)</span>',
				'job-listings'
			),
		)
	);

}
