<?php
/**
 * Cache invalidation — listings show up / disappear immediately.
 *
 * Every change to a listing (create, edit, approve, pause, expire, trash,
 * restore, permanent delete, import) queues it; once, at the end of the
 * request (after meta and terms are saved), the queue is flushed:
 *   - the [recent-listings] HTML cache (version salt bump),
 *   - WP Rocket: each listing, every page that shows listings, the home /
 *     front page and the listings' nomos & category archives,
 *   - the same URLs in other common page caches when present (LiteSpeed,
 *     W3 Total Cache, WP Super Cache, SiteGround, Nginx Helper, WP Fastest Cache).
 *
 * @package JobListings
 * @since   9.9.55
 */

defined( 'ABSPATH' ) || exit;

/**
 * The per-request queue.
 *
 * @return array{ids:int[],urls:string[]} By reference.
 */
function &jbli_cache_queue() {

	static $jbli_queue = array( 'ids' => array(), 'urls' => array() );

	return $jbli_queue;

}

/**
 * URLs that show one listing: its page and its nomos / category archives.
 *
 * @param int $jbli_post_id Listing ID.
 * @return string[]
 */
function jbli_listing_urls( $jbli_post_id ) {

	$jbli_urls      = array();
	$jbli_permalink = get_permalink( $jbli_post_id );

	if ( is_string( $jbli_permalink ) && '' !== $jbli_permalink ) { $jbli_urls[] = $jbli_permalink; }

	foreach ( array( 'job_nomos', 'job_category' ) as $jbli_tax ) {

		$jbli_terms = wp_get_post_terms( $jbli_post_id, $jbli_tax );

		if ( is_wp_error( $jbli_terms ) ) { continue; }

		foreach ( $jbli_terms as $jbli_term ) {

			$jbli_link = get_term_link( $jbli_term );

			if ( ! is_wp_error( $jbli_link ) ) { $jbli_urls[] = $jbli_link; }

		}

	}

	return $jbli_urls;

}

/**
 * Queue a listing for cache flushing at the end of the request.
 *
 * @param int  $jbli_post_id   Listing ID.
 * @param bool $jbli_collect_now Read its URLs now (the post is about to be deleted).
 * @return void
 */
function jbli_queue_listing_flush( $jbli_post_id, $jbli_collect_now = false ) {

	$jbli_post_id = absint( $jbli_post_id );

	if ( ! $jbli_post_id || JBLI_CPT !== get_post_type( $jbli_post_id ) || wp_is_post_revision( $jbli_post_id ) || wp_is_post_autosave( $jbli_post_id ) ) { return; }

	$jbli_queue = &jbli_cache_queue();

	if ( $jbli_collect_now )
	{
		$jbli_queue['urls'] = array_merge( $jbli_queue['urls'], jbli_listing_urls( $jbli_post_id ) );

		if ( function_exists( 'rocket_clean_post' ) ) { rocket_clean_post( $jbli_post_id ); }
	} else {
		$jbli_queue['ids'][ $jbli_post_id ] = $jbli_post_id;
	}

	static $jbli_registered = false;

	if ( ! $jbli_registered )
	{
		$jbli_registered = true;
		add_action( 'shutdown', 'jbli_flush_listing_caches', 0 );
	}

}

/**
 * Flush everything queued in this request (runs on shutdown; also callable directly).
 *
 * @return void
 */
function jbli_flush_listing_caches() {

	$jbli_queue = &jbli_cache_queue();
	$jbli_ids   = array_values( $jbli_queue['ids'] );
	$jbli_urls  = $jbli_queue['urls'];

	if ( ! $jbli_ids && ! $jbli_urls ) { return; }

	$jbli_queue = array( 'ids' => array(), 'urls' => array() );

	/* 1. [recent-listings] fragment cache. */
	if ( function_exists( 'jbli_recent_flush_listings_cache' ) ) { jbli_recent_flush_listings_cache(); }

	/* 2. Every URL that can show these listings. */
	foreach ( $jbli_ids as $jbli_id ) { $jbli_urls = array_merge( $jbli_urls, jbli_listing_urls( $jbli_id ) ); }

	$jbli_page_ids = function_exists( 'jbli_listing_page_ids' ) ? jbli_listing_page_ids() : array();
	$jbli_front_id = (int) get_option( 'page_on_front' );

	if ( $jbli_front_id > 0 ) { $jbli_page_ids[] = $jbli_front_id; }

	$jbli_page_ids = array_values( array_unique( array_map( 'intval', $jbli_page_ids ) ) );

	foreach ( $jbli_page_ids as $jbli_page_id ) {

		$jbli_link = get_permalink( $jbli_page_id );

		if ( is_string( $jbli_link ) && '' !== $jbli_link ) { $jbli_urls[] = $jbli_link; }

	}

	$jbli_urls[] = home_url( '/' );
	$jbli_urls   = array_values( array_unique( array_filter( $jbli_urls ) ) );

	/* 3. WP Rocket (its Cloudflare add-on purges the same URLs). */
	if ( function_exists( 'rocket_clean_post' ) )
	{
		foreach ( array_merge( $jbli_ids, $jbli_page_ids ) as $jbli_id ) { rocket_clean_post( $jbli_id ); }
	}

	if ( function_exists( 'rocket_clean_files' ) ) { rocket_clean_files( $jbli_urls ); }

	if ( function_exists( 'rocket_clean_home' ) ) { rocket_clean_home(); }

	/* 4. Other page caches, when installed. */
	foreach ( $jbli_urls as $jbli_url ) {

		do_action( 'litespeed_purge_url', $jbli_url );

		if ( function_exists( 'wpfc_clear_post_cache_by_url' ) ) { wpfc_clear_post_cache_by_url( $jbli_url ); }

		if ( function_exists( 'sg_cachepress_purge_cache' ) ) { sg_cachepress_purge_cache( $jbli_url ); }

	}

	foreach ( array_merge( $jbli_ids, $jbli_page_ids ) as $jbli_id ) {

		if ( function_exists( 'w3tc_flush_post' ) )      { w3tc_flush_post( $jbli_id ); }
		if ( function_exists( 'wp_cache_post_change' ) ) { wp_cache_post_change( $jbli_id ); }

		do_action( 'rt_nginx_helper_purge_post', $jbli_id );

	}

	/**
	 * Fires after the listing caches were flushed.
	 *
	 * @param int[]    $jbli_ids  Listing IDs.
	 * @param string[] $jbli_urls URLs purged.
	 */
	do_action( 'jbli_listing_caches_flushed', $jbli_ids, $jbli_urls );

}

/* Create / edit (front-end form, wp-admin, import). */
add_action( 'save_post_' . JBLI_CPT, static function ( $jbli_post_id ) { jbli_queue_listing_flush( (int) $jbli_post_id ); }, 99 );

/* Status changes: publish, pending, draft (pause), expired, trash. */
add_action(
	'transition_post_status',
	static function ( $jbli_new, $jbli_old, $post ) {

		if ( $post instanceof WP_Post && JBLI_CPT === $post->post_type && $jbli_new !== $jbli_old ) { jbli_queue_listing_flush( (int) $post->ID ); }

	},
	99,
	3
);

/*
 * Trash: WordPress renames the slug to "…__trashed", so the listing's real URL
 * must be read before that happens.
 */
add_action( 'wp_trash_post', static function ( $jbli_post_id ) { jbli_queue_listing_flush( (int) $jbli_post_id, true ); }, 5 );

/* Permanent delete: collect its URLs before the post and its terms are gone. */
add_action( 'before_delete_post', static function ( $jbli_post_id ) { jbli_queue_listing_flush( (int) $jbli_post_id, true ); }, 5 );

/* Trash / restore and the plugin's own lifecycle events. */
foreach ( array( 'trashed_post', 'untrashed_post', 'jbli_created', 'jbli_updated', 'jbli_expired', 'jbli_renewed', 'jbli_activated', 'jbli_deactivated', 'jbli_deleted', 'jbli_imported', 'jbli_featured_changed' ) as $jbli_hook ) {

	add_action( $jbli_hook, static function ( $jbli_post_id = 0 ) { jbli_queue_listing_flush( (int) $jbli_post_id ); }, 99 );

}

unset( $jbli_hook );

/* Meta-only changes that affect what cards show (featured, expiry). */
add_action(
	'updated_post_meta',
	static function ( $jbli_meta_id, $jbli_post_id, $jbli_meta_key ) {

		if ( in_array( $jbli_meta_key, array( JBLI_META_FEATURED, JBLI_META_EXPIRES, JBLI_META_EXPIRED ), true ) ) { jbli_queue_listing_flush( (int) $jbli_post_id ); }

	},
	10,
	3
);
