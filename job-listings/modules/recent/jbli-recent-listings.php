<?php
/**
 * Module: Recent Listings
 *
 * Shortcode: [recent-listings]
 *
 * Lightweight grid of the most recent published job listings — designed
 * to be embedded on the homepage or any landing page. No filters, no
 * pagination, no AJAX. Just a clean card grid.
 *
 * Attributes:
 *   count          (int)    Number of listings to show per page. Default: 6. Max: 24.
 *   columns        (int)    Grid columns on desktop: 2 or 3. Default: 3.
 *   view_all_url   (string) URL for the "Δείτε όλες τις αγγελίες" button.
 *                           Default: auto-detected from the [listings] page.
 *   jbli_title          (string) Section heading. Default: '' (no heading rendered).
 *   featured_first (bool)   Show featured listings first. Default: true.
 *   pagination     (bool)   Show pagination when listings exceed count. Default: true.
 *
 * Usage:
 *   [recent-listings]
 *   [recent-listings count="4" columns="2" title="Νέες Αγγελίες"]
 *   [recent-listings count="6" view_all_url="https://example.com/aggelies/"]
 *
 * @package JobListings
 * @since   9.9.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render [recent-listings] shortcode.
 *
 * @param array $jbli_atts Shortcode attributes.
 * @return string HTML output.
 */
function jbli_render_recent_listings( $jbli_atts ) {

	$jbli_atts = shortcode_atts(
		array(
			'count'          => 6,
			'columns'        => 3,
			'view_all_url'   => '',
			'title'          => '',
			'featured_first' => 'true',
			'pagination'     => 'true',
		),
		$jbli_atts,
		'recent-listings'
	);

	$jbli_count          = min( 24, max( 1, (int) $jbli_atts['count'] ) );
	$jbli_columns        = in_array( (int) $jbli_atts['columns'], array( 2, 3 ), true ) ? (int) $jbli_atts['columns'] : 3;
	$jbli_section_title  = sanitize_text_field( (string) $jbli_atts['title'] );
	$jbli_featured_first = ( 'false' !== strtolower( (string) $jbli_atts['featured_first'] ) );
	$jbli_show_paging    = ( 'false' !== strtolower( (string) $jbli_atts['pagination'] ) );


	/* Capped: each page number is its own cached copy (9.9.58). */
	$jbli_paged = min( 50, max( 1, (int) ( $_GET['jbli_page'] ?? get_query_var( 'paged', 1 ) ) ) );


	$jbli_view_all_url = (string) $jbli_atts['view_all_url'];

	if ( '' === $jbli_view_all_url ) { $jbli_view_all_url = jbli_recent_get_listings_page_url(); }

	$jbli_view_all_url = esc_url( $jbli_view_all_url );


	$jbli_cache_version = (int) get_option( 'jbli_recent_cache_version', 1 );
	$jbli_cache_key     = 'jbli_rc_' . $jbli_cache_version . '_' . md5(
		$jbli_count . '|' . $jbli_columns . '|' . $jbli_section_title . '|' .
		(int) $jbli_featured_first . '|' . $jbli_view_all_url . '|' . $jbli_paged . '|' . (int) $jbli_show_paging
	);


	/*
	 * 9.9.58: the stylesheets are enqueued for every render, cached or not.
	 * The whole jbli-recent.css used to be printed inline inside the cached
	 * HTML, so the cache held 38 KB of CSS or none at all, depending on which
	 * request filled it. Styles enqueued after <head> print in the footer.
	 */
	jbli_recent_enqueue_styles();

	$jbli_cached_data = get_transient( $jbli_cache_key );

	if ( false !== $jbli_cached_data )
	{
		if ( is_array( $jbli_cached_data ) && isset( $jbli_cached_data['html'], $jbli_cached_data['post_ids'] ) )
		{
			if ( function_exists( 'jbli_apply_modal_registry' ) )
			{
				foreach ( $jbli_cached_data['post_ids'] as $jbli_post_id ) {

					jbli_apply_modal_registry( (int) $jbli_post_id );

				}
			}

			return $jbli_cached_data['html'];
		} 
		elseif ( is_string( $jbli_cached_data ) ) 
		{
			return $jbli_cached_data;
		}
	}




	$jbli_query_args = array(
		'post_type'              => JBLI_CPT,
		'post_status'            => 'publish',
		'posts_per_page'         => $jbli_count,
		'paged'                  => $jbli_paged,
		'no_found_rows'          => ! $jbli_show_paging,
		'ignore_sticky_posts'    => true,
		'update_post_term_cache' => true,
		'update_post_meta_cache' => true,
	);

	if ( $jbli_featured_first )
	{


		$jbli_query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation'        => 'OR',
			'featured_clause' => array(
				'key'       => JBLI_META_FEATURED,
				'compare'   => 'EXISTS',
				'type'      => 'NUMERIC',
			),
			'missing_clause' => array( 'key'     => JBLI_META_FEATURED, 'compare' => 'NOT EXISTS', ),
		);
		$jbli_query_args['orderby'] = array( 'featured_clause' => 'DESC', 'date'            => 'DESC', );
	} 
	else 
	{
		$jbli_query_args['orderby'] = 'date';
		$jbli_query_args['order']   = 'DESC';
	}

	$jbli_query     = new WP_Query( $jbli_query_args );
	$jbli_listings  = $jbli_query->posts;
	$jbli_max_pages = $jbli_show_paging ? (int) $jbli_query->max_num_pages : 1;

	if ( empty( $jbli_listings ) )
	{
		ob_start();
		?>
		<div class="jbli_recent jbli_recent_empty" style="display:flex;justify-content:center;padding:32px 16px;">
			<?php

				if ( $jbli_section_title )
				{

					?>
						<h2 class="jbli_recent_heading"><?php echo esc_html( $jbli_section_title ); ?></h2>
					<?php
				}
			?>
			<div style="display:inline-flex;align-items:center;gap:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:999px;padding:14px 28px;">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<rect x="3" y="4" width="18" height="18" rx="3"/>
					<path d="M8 2v4M16 2v4M3 10h18"/>
					<path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>
				</svg>
				<span style="font-size:.95rem;font-weight:700;color:#065f46;letter-spacing:-.01em;">
					<?php esc_html_e( 'Δεν υπάρχουν διαθέσιμες αγγελίες αυτή τη στιγμή.', 'job-listings' ); ?>
				</span>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}


	$jbli_card_data = array();

	$jbli_all_pids  = array_map( function( $jbli_p ) { return (int) $jbli_p->ID; }, $jbli_listings );
	$jbli_terms_map = function_exists( 'jbli_bulk_fetch_terms' ) ? jbli_bulk_fetch_terms( $jbli_all_pids ) : array();

	foreach ( $jbli_listings as $post ) {

		$jbli_pid  			= (int) $post->ID;
		$jbli_meta 			= get_post_meta( $jbli_pid );

		$jbli_position    	= isset( $jbli_meta[ JBLI_META_POSITION ][0] ) ? (string) $jbli_meta[ JBLI_META_POSITION ][0] : '';
		$jbli_pharmacy    	= isset( $jbli_meta[ JBLI_META_PHARMACY_NAME ][0] ) ? (string) $jbli_meta[ JBLI_META_PHARMACY_NAME ][0] : '';
		$jbli_salary      	= isset( $jbli_meta[ JBLI_META_SALARY ][0] ) ? (string) $jbli_meta[ JBLI_META_SALARY ][0] : '';
		$jbli_type        	= isset( $jbli_meta[ JBLI_META_TYPE ][0] ) ? (string) $jbli_meta[ JBLI_META_TYPE ][0] : '';
		$jbli_is_featured 	= function_exists( 'jbli_is_featured' ) && jbli_is_featured( $jbli_pid );
		$jbli_views       	= function_exists( 'jbli_get_views' ) ? (int) jbli_get_views( $jbli_pid ) : 0;

		$jbli_cats  		= $jbli_terms_map[ $jbli_pid ]['cats']  ?? array();
		$jbli_nomoi 		= $jbli_terms_map[ $jbli_pid ]['jbli_nomoi'] ?? array();

		$jbli_card_data[] 	= compact( 'jbli_pid', 'jbli_position', 'jbli_pharmacy', 'jbli_salary', 'jbli_type', 'jbli_is_featured', 'jbli_views', 'jbli_nomoi', 'jbli_cats' );

	}

	wp_reset_postdata();

	$jbli_cols_class = 'jbli_recent_grid_cols_' . $jbli_columns;
	$jbli_card_path  = JBLI_DIR . 'modules/listings/jbli-listing-card.php';

	if ( ! is_readable( $jbli_card_path ) ) { return ''; }

	ob_start();
	
	?>

	<section class="jbli_recent" aria-label="<?php esc_attr_e( 'Πρόσφατες αγγελίες', 'job-listings' ); ?>">
		<?php

			if ( $jbli_section_title )
			{

				?>
					<h2 class="jbli_recent_heading"><?php echo esc_html( $jbli_section_title ); ?></h2>
				<?php
			}
		?>

		<div class="jbli_recent_grid <?php echo esc_attr( $jbli_cols_class ); ?>">
			<?php 
				foreach ( $jbli_card_data as $jbli_card ) {

					$jbli_id          = $jbli_card['jbli_pid'];
					$jbli_position    = $jbli_card['jbli_position'];
					$jbli_pharmacy    = $jbli_card['jbli_pharmacy'];
					$jbli_salary      = $jbli_card['jbli_salary'];
					$jbli_type        = $jbli_card['jbli_type'];
					$jbli_is_featured = $jbli_card['jbli_is_featured'];
					$jbli_views       = $jbli_card['jbli_views'];
					$jbli_nomoi       = $jbli_card['jbli_nomoi'];
					$jbli_cats        = $jbli_card['jbli_cats'];
					/* Set per card: the card partial keeps an already-set value, which leaked from card to card. */
					$jbli_days_left   = function_exists( 'jbli_days_left' ) ? jbli_days_left( $jbli_id ) : '';

					include $jbli_card_path;

				}
			?>
		</div>

		<?php 

			if ( $jbli_show_paging && $jbli_max_pages > 1 ) 
			{
				$jbli_current_url = get_permalink() ?: home_url( '/' );

				?>
				<nav class="jbli_recent_pagination" aria-label="<?php esc_attr_e( 'Σελιδοποίηση αγγελιών', 'job-listings' ); ?>">
					<?php

						if ( $jbli_paged > 1 )
						{
							$jbli_prev_url = add_query_arg( 'jbli_page', $jbli_paged - 1, $jbli_current_url );
							?>
								<a href="<?php echo esc_url( $jbli_prev_url ); ?>" class="jbli_recent_page_btn jbli_recent_page_btn_prev" aria-label="<?php esc_attr_e( 'Προηγούμενη σελίδα', 'job-listings' ); ?>">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
								</a>
							<?php
						}

						for ( $jbli_i = 1; $jbli_i <= $jbli_max_pages; $jbli_i++ ) {

							if ( $jbli_i === $jbli_paged )
							{ ?>
								<span class="jbli_recent_page_btn jbli_recent_page_btn_current" aria-current="page"><?php echo (int) $jbli_i; ?></span>
							<?php } else {
								$jbli_page_url = add_query_arg( 'jbli_page', $jbli_i, $jbli_current_url );
								?>
								<a href="<?php echo esc_url( $jbli_page_url ); ?>" class="jbli_recent_page_btn"><?php echo (int) $jbli_i; ?></a>
							<?php }

						}

						if ( $jbli_paged < $jbli_max_pages )
						{
							$jbli_next_url = add_query_arg( 'jbli_page', $jbli_paged + 1, $jbli_current_url );
							?>
								<a href="<?php echo esc_url( $jbli_next_url ); ?>" class="jbli_recent_page_btn jbli_recent_page_btn_next" aria-label="<?php esc_attr_e( 'Επόμενη σελίδα', 'job-listings' ); ?>">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
								</a>
							<?php
						}
					?>

				</nav>
				<?php
			}
			if ( $jbli_view_all_url && ( ! $jbli_show_paging || $jbli_paged >= $jbli_max_pages ) )
			{

				?>
					<div class="jbli_recent_footer">
						<a href="<?php echo esc_url( $jbli_view_all_url ); ?>" class="jbli_btn jbli_btn_outline">
							<?php esc_html_e( 'Δείτε όλες τις αγγελίες', 'job-listings' ); ?>
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false">
								<path d="M5 12h14M12 5l7 7-7 7"/>
							</svg>
						</a>
					</div>
				<?php
			}
		?>

	</section>
	<?php

	$jbli_html = ob_get_clean();

	$jbli_cache_data = array( 'html'     => $jbli_html, 'post_ids' => $jbli_all_pids, );
	set_transient( $jbli_cache_key, $jbli_cache_data, 10 * MINUTE_IN_SECONDS );

	return $jbli_html;

}

add_shortcode( 'recent-listings', 'jbli_render_recent_listings' );

/**
 * Enqueue the tokens + recent-listings stylesheets (no-op when already enqueued).
 *
 * Covers [recent-listings] placed where the head-time shortcode detection
 * cannot see it (widgets, theme templates, do_shortcode()).
 *
 * @since 9.9.58
 * @return void
 */
function jbli_recent_enqueue_styles() {

	$jbli_tokens_file = JBLI_DIR . 'includes/jbli-tokens.css';
	$jbli_recent_file = JBLI_DIR . 'modules/recent/css/jbli-recent.css';

	if ( ! wp_style_is( 'jbli_tokens', 'enqueued' ) && ! wp_style_is( 'jbli_tokens', 'done' ) && is_readable( $jbli_tokens_file ) )
	{
		wp_enqueue_style( 'jbli_tokens', JBLI_URL . 'includes/jbli-tokens.css', array(), jbli_asset_version( $jbli_tokens_file ) );
	}

	if ( ! wp_style_is( 'jbli_recent_style', 'enqueued' ) && ! wp_style_is( 'jbli_recent_style', 'done' ) && is_readable( $jbli_recent_file ) )
	{
		wp_enqueue_style( 'jbli_recent_style', JBLI_URL . 'modules/recent/css/jbli-recent.css', array( 'jbli_tokens' ), jbli_asset_version( $jbli_recent_file ) );
	}

}

/**
 * Auto-detect the URL of the page that contains [listings] shortcode.
 *
 * Cached in a transient for 12 hours to avoid repeated DB queries on
 * high-traffic homepages.
 *
 * @since 9.9.1
 * @return string URL or empty string.
 */
function jbli_recent_get_listings_page_url() {

	$jbli_transient_key = 'jbli_listings_page_url';
	$jbli_cached        = get_transient( $jbli_transient_key );

	if ( false !== $jbli_cached ) { return (string) $jbli_cached; }

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$jbli_page_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type   = %s
			   AND post_content LIKE %s
			 ORDER BY ID ASC
			 LIMIT 1",
			'page',
			'%[listings]%'
		)
	);
	$jbli_page = $jbli_page_id ? array( (int) $jbli_page_id ) : array();

	$jbli_url = ! empty( $jbli_page ) ? (string) get_permalink( (int) $jbli_page[0] ) : '';

	set_transient( $jbli_transient_key, $jbli_url, 12 * HOUR_IN_SECONDS );

	return $jbli_url;

}

/**
 * Clear the listings-page URL transient when any page is saved, trashed or deleted.
 * Ensures the cached URL stays fresh after slug/content/existence changes.
 *
 * @since 9.9.1
 * @since 9.9.8 Also clears on before_delete_post and trashed_post.
 */
function jbli_recent_flush_page_url_cache( $jbli_post_id, $post = null ) {

	if ( null === $post ) { $post = get_post( $jbli_post_id ); }

	if ( $post instanceof WP_Post && 'page' === $post->post_type )
	{
		delete_transient( 'jbli_listings_page_url' );
		delete_transient( 'jbli_form_page_url' );
	}

}

add_action( 'save_post', 'jbli_recent_flush_page_url_cache', 10, 2 );
add_action( 'before_delete_post', 'jbli_recent_flush_page_url_cache' );
add_action( 'trashed_post', 'jbli_recent_flush_page_url_cache' );

/**
 * Flush all [recent-listings] HTML cache by bumping the version salt.
 *
 * Incrementing 'jbli_recent_cache_version' invalidates every cached
 * variant atomically — including entries in persistent object caches
 * (Redis, Memcached) that a SQL LIKE delete would miss.
 *
 * Old transient rows are left to expire naturally (10 min TTL) rather than
 * deleted, avoiding a potentially large LIKE query on high-traffic sites.
 *
 * @since 9.9.18
 */
function jbli_recent_flush_listings_cache(): void {

	/* Always moves forward, even for two changes within the same second (9.9.55). */
	update_option( 'jbli_recent_cache_version', max( time(), (int) get_option( 'jbli_recent_cache_version', 1 ) + 1 ), false );

}

add_action( 'jbli_created',     'jbli_recent_flush_listings_cache' );
add_action( 'jbli_updated',     'jbli_recent_flush_listings_cache' );
add_action( 'jbli_expired',     'jbli_recent_flush_listings_cache' );
add_action( 'jbli_renewed',     'jbli_recent_flush_listings_cache' );
add_action( 'jbli_activated',   'jbli_recent_flush_listings_cache' );
add_action( 'jbli_deactivated', 'jbli_recent_flush_listings_cache' );
add_action( 'before_delete_post',      'jbli_recent_flush_listings_cache' );
add_action( 'jbli_deleted',            'jbli_recent_flush_listings_cache' );
add_action( 'jbli_admin_deleted',      'jbli_recent_flush_listings_cache' );
add_action( 'jbli_featured_changed',   'jbli_recent_flush_listings_cache' );
add_action( 'jbli_imported',           'jbli_recent_flush_listings_cache' );

/**
 * Flush the [recent-listings] cache when a listing is trashed or restored.
 *
 * @param int $jbli_post_id Post ID.
 */
function jbli_recent_flush_on_trash( $jbli_post_id ): void {

	if ( JBLI_CPT === get_post_type( $jbli_post_id ) ) { jbli_recent_flush_listings_cache(); }

}

add_action( 'trashed_post',   'jbli_recent_flush_on_trash' );
add_action( 'untrashed_post', 'jbli_recent_flush_on_trash' );
