<?php
/**
 * Listings map (9.9.62)
 *
 * A static map of Greece under the [listings] results: one green circle per
 * νομός with active listings, sized by how many, plus a list of the busiest
 * areas. Clicking a circle or a row selects that νομός in the existing
 * filter (AJAX with JS, a normal link without).
 *
 * The outline is img/jbli-greece.svg (a cached file); only the circles are in
 * the page. No map service, API key or cookies.
 *
 * @package JobListings
 * @since   9.9.62
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pin positions and map size (generated data).
 *
 * @return array{width:int,height:int,pins:array<string,float[]>}
 */
function jbli_listings_map_data() {

	static $jbli_data = null;

	if ( null === $jbli_data )
	{
		$jbli_file = dirname( __DIR__ ) . '/jbli-map-data.php';
		$jbli_data = is_readable( $jbli_file ) ? (array) require $jbli_file : array();
	}

	return $jbli_data;

}

/**
 * Comparable form of a νομός name (case and accents ignored).
 *
 * @param string $jbli_name Name.
 * @return string
 */
function jbli_listings_map_key( $jbli_name ) {

	return mb_strtolower( remove_accents( trim( (string) $jbli_name ) ), 'UTF-8' );

}

/**
 * Published listings per νομός term ID.
 *
 * Counted directly (not term->count, which the term cache can hold for an
 * hour) and cached until the next listing change bumps the recent-listings
 * cache version.
 *
 * @return array<int,int>
 */
function jbli_listings_map_counts() {

	global $wpdb;

	$jbli_version = (int) get_option( 'jbli_recent_cache_version', 1 );
	$jbli_key     = 'jbli_map_counts_' . $jbli_version;
	$jbli_counts  = get_transient( $jbli_key );

	if ( is_array( $jbli_counts ) ) { return $jbli_counts; }

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$jbli_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT tt.term_id, COUNT(DISTINCT p.ID) AS n
		   FROM {$wpdb->term_taxonomy} tt
		   JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
		   JOIN {$wpdb->posts} p ON p.ID = tr.object_id
		  WHERE tt.taxonomy = %s AND p.post_type = %s AND p.post_status = 'publish'
		  GROUP BY tt.term_id",
		'job_nomos',
		JBLI_CPT
	) );

	$jbli_counts = array();

	foreach ( (array) $jbli_rows as $jbli_row ) { $jbli_counts[ (int) $jbli_row->term_id ] = (int) $jbli_row->n; }

	set_transient( $jbli_key, $jbli_counts, HOUR_IN_SECONDS );

	return $jbli_counts;

}

/**
 * Areas to show: νομοί with active listings and a known map position, busiest first.
 *
 * @return array<int,array{id:int,name:string,count:int,x:float,y:float}>
 */
function jbli_listings_map_areas() {

	$jbli_data   = jbli_listings_map_data();
	$jbli_pins   = array();
	$jbli_counts = jbli_listings_map_counts();
	$jbli_areas  = array();

	foreach ( (array) ( $jbli_data['pins'] ?? array() ) as $jbli_name => $jbli_xy ) { $jbli_pins[ jbli_listings_map_key( $jbli_name ) ] = $jbli_xy; }

	$jbli_terms = function_exists( 'jbli_get_terms' ) ? jbli_get_terms( 'job_nomos' ) : array();

	foreach ( $jbli_terms as $jbli_term ) {

		$jbli_count = (int) ( $jbli_counts[ (int) $jbli_term->term_id ] ?? 0 );
		$jbli_xy    = $jbli_pins[ jbli_listings_map_key( $jbli_term->name ) ] ?? null;

		if ( $jbli_count < 1 || ! $jbli_xy ) { continue; }

		$jbli_areas[] = array(
			'id'    => (int) $jbli_term->term_id,
			'name'  => (string) $jbli_term->name,
			'count' => $jbli_count,
			'x'     => (float) $jbli_xy[0],
			'y'     => (float) $jbli_xy[1],
		);

	}

	usort( $jbli_areas, static function ( $a, $b ) { return $b['count'] <=> $a['count'] ?: strcmp( $a['name'], $b['name'] ); } );

	return $jbli_areas;

}

/**
 * Map section HTML (empty when there is nothing to show).
 *
 * @param int    $jbli_current_nomos Selected νομός term ID (0 = none).
 * @param string $jbli_base_url      Listings page URL for the no-JS links.
 * @return string
 */
function jbli_listings_map_html( $jbli_current_nomos, $jbli_base_url ) {

	if ( ! (bool) apply_filters( 'jbli_listings_show_map', true ) ) { return ''; }

	$jbli_areas = jbli_listings_map_areas();

	if ( empty( $jbli_areas ) ) { return ''; }

	$jbli_data  = jbli_listings_map_data();
	$jbli_w     = (int) ( $jbli_data['width'] ?? 760 );
	$jbli_h     = (int) ( $jbli_data['height'] ?? 620 );
	$jbli_img   = JBLI_URL . 'modules/listings/img/jbli-greece.svg';
	$jbli_ver   = function_exists( 'jbli_asset_version' ) ? jbli_asset_version( JBLI_DIR . 'modules/listings/img/jbli-greece.svg' ) : JBLI_VERSION;
	$jbli_top   = array_slice( $jbli_areas, 0, 8 );
	$jbli_rest  = array_slice( $jbli_areas, 8 );
	$jbli_link  = static function ( $jbli_id ) use ( $jbli_base_url ) { return add_query_arg( 'job_nomos', (int) $jbli_id, $jbli_base_url ) . '#jbli_listings_root'; };
	$jbli_label = static function ( $jbli_area ) {
		/* translators: 1: νομός, 2: number of listings */
		return sprintf( _n( '%1$s · %2$d αγγελία', '%1$s · %2$d αγγελίες', $jbli_area['count'], 'job-listings' ), $jbli_area['name'], $jbli_area['count'] );
	};

	/* Small circles drawn first, so the big ones stay on top. */
	$jbli_by_size = $jbli_areas;
	usort( $jbli_by_size, static function ( $a, $b ) { return $a['count'] <=> $b['count']; } );

	ob_start();
	?>
	<section class="jbli_map" id="jbli_map" aria-labelledby="jbli_map_title">

		<div class="jbli_map_canvas">
			<svg class="jbli_map_svg" viewBox="0 0 <?php echo esc_attr( $jbli_w . ' ' . $jbli_h ); ?>" role="group" aria-label="<?php esc_attr_e( 'Χάρτης αγγελιών ανά νομό', 'job-listings' ); ?>">
				<image href="<?php echo esc_url( add_query_arg( 'ver', $jbli_ver, $jbli_img ) ); ?>" x="0" y="0" width="<?php echo esc_attr( (string) $jbli_w ); ?>" height="<?php echo esc_attr( (string) $jbli_h ); ?>" preserveAspectRatio="xMidYMid meet"/>
				<?php foreach ( $jbli_by_size as $jbli_area ) {
					$jbli_r = $jbli_area['count'] >= 10 ? 24 : ( $jbli_area['count'] >= 5 ? 20 : ( $jbli_area['count'] >= 2 ? 16 : 13 ) );
					$jbli_on = (int) $jbli_current_nomos === $jbli_area['id'];
					?>
					<a class="jbli_map_pin<?php echo $jbli_on ? ' is_active' : ''; ?>" href="<?php echo esc_url( $jbli_link( $jbli_area['id'] ) ); ?>" data-jbli_nomos="<?php echo esc_attr( (string) $jbli_area['id'] ); ?>" aria-label="<?php echo esc_attr( $jbli_label( $jbli_area ) ); ?>">
						<title><?php echo esc_html( $jbli_label( $jbli_area ) ); ?></title>
						<circle class="jbli_map_halo" cx="<?php echo esc_attr( (string) $jbli_area['x'] ); ?>" cy="<?php echo esc_attr( (string) $jbli_area['y'] ); ?>" r="<?php echo esc_attr( (string) ( $jbli_r + 6 ) ); ?>"/>
						<circle class="jbli_map_dot" cx="<?php echo esc_attr( (string) $jbli_area['x'] ); ?>" cy="<?php echo esc_attr( (string) $jbli_area['y'] ); ?>" r="<?php echo esc_attr( (string) $jbli_r ); ?>"/>
						<text class="jbli_map_num" x="<?php echo esc_attr( (string) $jbli_area['x'] ); ?>" y="<?php echo esc_attr( (string) ( $jbli_area['y'] + 5 ) ); ?>" text-anchor="middle" font-size="<?php echo esc_attr( $jbli_r > 18 ? '15' : '13' ); ?>"><?php echo esc_html( number_format_i18n( $jbli_area['count'] ) ); ?></text>
					</a>
				<?php } ?>
			</svg>
			<p class="jbli_map_legend"><?php esc_html_e( 'Ο αριθμός = ενεργές αγγελίες στον νομό', 'job-listings' ); ?></p>
		</div>

		<div class="jbli_map_side">
			<span class="jbli_map_eyebrow"><?php esc_html_e( 'Αγγελίες στον χάρτη', 'job-listings' ); ?></span>
			<h2 class="jbli_map_title" id="jbli_map_title"><?php esc_html_e( 'Πού ζητούνται συνεργάτες', 'job-listings' ); ?></h2>
			<p class="jbli_map_sub"><?php esc_html_e( 'Ο αριθμός δείχνει τις ενεργές αγγελίες κάθε νομού. Πατήστε σε μια περιοχή στον χάρτη ή στη λίστα για να δείτε μόνο τις αγγελίες της.', 'job-listings' ); ?></p>

			<ul class="jbli_map_list">
				<?php foreach ( $jbli_top as $jbli_area ) { ?>
					<li><a class="jbli_map_row<?php echo (int) $jbli_current_nomos === $jbli_area['id'] ? ' is_active' : ''; ?>" href="<?php echo esc_url( $jbli_link( $jbli_area['id'] ) ); ?>" data-jbli_nomos="<?php echo esc_attr( (string) $jbli_area['id'] ); ?>"><span><?php echo esc_html( $jbli_area['name'] ); ?></span><b><?php echo esc_html( number_format_i18n( $jbli_area['count'] ) ); ?></b></a></li>
				<?php } ?>
			</ul>

			<?php if ( $jbli_rest ) { ?>
				<details class="jbli_map_more">
					<summary>
						<?php
						/* translators: %d: number of areas */
						echo esc_html( sprintf( __( 'Όλες οι περιοχές (%d)', 'job-listings' ), count( $jbli_areas ) ) );
						?>
					</summary>
					<ul class="jbli_map_list">
						<?php foreach ( $jbli_rest as $jbli_area ) { ?>
							<li><a class="jbli_map_row<?php echo (int) $jbli_current_nomos === $jbli_area['id'] ? ' is_active' : ''; ?>" href="<?php echo esc_url( $jbli_link( $jbli_area['id'] ) ); ?>" data-jbli_nomos="<?php echo esc_attr( (string) $jbli_area['id'] ); ?>"><span><?php echo esc_html( $jbli_area['name'] ); ?></span><b><?php echo esc_html( number_format_i18n( $jbli_area['count'] ) ); ?></b></a></li>
						<?php } ?>
					</ul>
				</details>
			<?php } ?>
		</div>

	</section>
	<?php
	return (string) ob_get_clean();

}
