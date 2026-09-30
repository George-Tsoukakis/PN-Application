<?php
/**
 * Public listings template.
 *
 * @package JobListings
 * @since   9.6.1
 */

defined( 'ABSPATH' ) || exit;

$jbli_clear_url 	= get_permalink();

if ( ! is_string( $jbli_clear_url ) || '' === $jbli_clear_url ) { $jbli_clear_url = home_url( '/' ); }

$jbli_filters 			= isset( $jbli_filters ) && is_array( $jbli_filters ) ? $jbli_filters : array();

$jbli_query 			= isset( $jbli_query ) && $jbli_query instanceof WP_Query ? $jbli_query : new WP_Query();

$jbli_job_nomoi 		= function_exists( 'jbli_get_terms' ) ? jbli_get_terms( 'job_nomos' ) : array();
$jbli_job_nomoi 		= is_array( $jbli_job_nomoi ) ? $jbli_job_nomoi : array();

$jbli_job_categories 	= function_exists( 'jbli_get_terms' ) ? jbli_get_terms( 'job_category' ) : array();
$jbli_job_categories 	= is_array( $jbli_job_categories ) ? $jbli_job_categories : array();

$jbli_type_options 		= function_exists( 'jbli_type_options' ) ? jbli_type_options() : array();
$jbli_type_options 		= is_array( $jbli_type_options ) ? $jbli_type_options : array();

$jbli_salary_options 	= function_exists( 'jbli_salary_options' ) ? jbli_salary_options() : array();
$jbli_salary_options 	= is_array( $jbli_salary_options ) ? $jbli_salary_options : array();
?>

<div class="jbli_listings" id="jbli_listings_root">

	<form method="get" class="jbli_filters" id="jbli_filters_form" role="search" autocomplete="off">

		<div class="jbli_filters_head">
			<div>
				<h2 class="jbli_filters_title">
					<?php esc_html_e( 'Βρείτε αγγελίες εργασίας', 'job-listings' ); ?>
				</h2>
				<p class="jbli_filters_subtitle">
					<?php esc_html_e( 'Αναζητήστε ανά θέση, νομό, κατηγορία, τύπο απασχόλησης ή αμοιβή.', 'job-listings' ); ?>
				</p>
			</div>
		</div>
		<div class="jbli_filter_search_wrap">
			<div class="jbli_filter_search">

				<input
					type="search"
					name="job_s"
					id="jbli_search_input"
					value="<?php echo esc_attr( $jbli_filters['f_search'] ?? '' ); ?>"
					placeholder="<?php echo esc_attr__( 'Αναζήτηση αγγελίας, θέσης, φαρμακείου…', 'job-listings' ); ?>"
					class="jbli_input"
					maxlength="80"
					inputmode="search"
					enterkeyhint="search"
				>
			</div>
		</div>

		<div class="jbli_filter_row">
			<div class="jbli_filter_field">
				<div class="jbli_select_wrap"><select id="f_nomos" name="job_nomos" class="jbli_select" aria-label="<?php esc_attr_e( 'Νομός', 'job-listings' ); ?>">
					<option value=""><?php esc_html_e( 'Όλοι οι νομοί', 'job-listings' ); ?></option>
					<?php

					foreach ( $jbli_job_nomoi as $jbli_nomos )
					{
						if ( ! $jbli_nomos instanceof WP_Term ) { continue; }

						?>
						<option
							value="<?php echo esc_attr( (string) $jbli_nomos->term_id ); ?>"
							<?php selected( (int) ( $jbli_filters['f_nomos'] ?? 0 ), (int) $jbli_nomos->term_id ); ?>
						>
							<?php echo esc_html( $jbli_nomos->name ); ?>
						</option>
						<?php
					}

					?>
				</select></div>
			</div>

			<div class="jbli_filter_field">
				<div class="jbli_select_wrap"><select id="f_cat" name="job_cat" class="jbli_select" aria-label="<?php esc_attr_e( 'Κατηγορία', 'job-listings' ); ?>">
					<option value=""><?php esc_html_e( 'Όλες οι κατηγορίες', 'job-listings' ); ?></option>
					<?php

					foreach ( $jbli_job_categories as $jbli_category )
					{
						if ( ! $jbli_category instanceof WP_Term ) { continue; }

						?>
						<option
							value="<?php echo esc_attr( (string) $jbli_category->term_id ); ?>"
							<?php selected( (int) ( $jbli_filters['f_cat'] ?? 0 ), (int) $jbli_category->term_id ); ?>
						>
							<?php echo esc_html( $jbli_category->name ); ?>
						</option>
						<?php
					}

					?>
				</select></div>
			</div>

			<div class="jbli_filter_field">
				<div class="jbli_select_wrap"><select id="f_type" name="job_type" class="jbli_select" aria-label="<?php esc_attr_e( 'Τύπος απασχόλησης', 'job-listings' ); ?>">
					<option value=""><?php esc_html_e( 'Τύπος απασχόλησης', 'job-listings' ); ?></option>
					<?php

					foreach ( $jbli_type_options as $jbli_value => $jbli_label )
					{

						?>
						<option
							value="<?php echo esc_attr( $jbli_value ); ?>"
							<?php selected( (string) ( $jbli_filters['f_type'] ?? '' ), $jbli_value ); ?>
						>
							<?php echo esc_html( $jbli_label ); ?>
						</option>
						<?php
					}

					?>
				</select></div>
			</div>

			<div class="jbli_filter_field">
				<div class="jbli_select_wrap"><select id="f_salary" name="job_salary" class="jbli_select" aria-label="<?php esc_attr_e( 'Αμοιβή', 'job-listings' ); ?>">
					<option value=""><?php esc_html_e( 'Αμοιβή', 'job-listings' ); ?></option>
					<?php

					foreach ( $jbli_salary_options as $jbli_value => $jbli_label )
					{

						?>
						<option
							value="<?php echo esc_attr( $jbli_value ); ?>"
							<?php selected( (string) ( $jbli_filters['f_salary'] ?? '' ), $jbli_value ); ?>
						>
							<?php echo esc_html( $jbli_label ); ?>
						</option>
						<?php
					}

					?>
				</select></div>
			</div>


		</div>
		<div class="jbli_filter_field jbli_filter_field_actions">
			<button type="submit" class="jbli_btn jbli_btn_primary jbli_btn_search" id="jbli_filter_submit" >
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
				<?php esc_html_e( 'Αναζήτηση', 'job-listings' ); ?>
			</button>
			<a href="<?php echo esc_url( $jbli_clear_url ); ?>" class="jbli_btn jbli_btn_ghost" id="jbli_filters_clear">
				<?php esc_html_e( 'Καθαρισμός', 'job-listings' ); ?>
			</a>
		</div>

	</form>

	<div class="jbli_results_bar" id="jbli_results_bar" aria-live="polite">
		<span id="jbli_results_count">
			<?php
			$jbli_count = (int) $jbli_query->found_posts;

			if ( 0 === $jbli_count )
			{
				esc_html_e( 'Δεν βρέθηκαν αγγελίες', 'job-listings' );
			} 
			elseif ( 1 === $jbli_count ) 
			{
				esc_html_e( 'Βρέθηκε 1 αγγελία', 'job-listings' );
			} 
			else 
			{
				echo esc_html( sprintf(
					/* translators: %d: number of job listings found */
					__( 'Βρέθηκαν %d αγγελίες', 'job-listings' ),
					$jbli_count
				) );
			}

			?>
		</span>

		<span class="jbli_spinner" id="jbli_spinner" aria-hidden="true" aria-label="<?php esc_attr_e( 'Φόρτωση…', 'job-listings' ); ?>"></span>
	</div>

	<div id="jbli_cards_container" aria-live="polite">
	<?php

		if ( ! $jbli_query->have_posts() )
		{
			$jbli_html = jbli_view(
				'partials.empty-listings',
				array(
					'jbli_message'   => __( 'Δεν βρέθηκαν αγγελίες με αυτά τα κριτήρια.', 'job-listings' ),
					'jbli_clear_url' => $jbli_clear_url,
					'jbli_cta_label' => __( 'Δείτε όλες τις αγγελίες', 'job-listings' ),
				)
			);
			echo $jbli_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML component.
		}
		else
		{

			?>
			<div class="jbli_cards">
				<?php

					$jbli_post_ids  = array_map( 'intval', wp_list_pluck( $jbli_query->posts, 'ID' ) );
					$jbli_terms_map = function_exists( 'jbli_bulk_fetch_terms' ) ? jbli_bulk_fetch_terms( $jbli_post_ids ) : array();

					while ( $jbli_query->have_posts() ) {

						$jbli_query->the_post();

						$jbli_listing_id = get_the_ID();

						if ( function_exists( 'jbli_render_listing_card' ) && function_exists( 'jbli_get_card_data' ) )
						{
							jbli_render_listing_card(
								jbli_get_card_data(
									$jbli_listing_id,
									$jbli_terms_map[ $jbli_listing_id ] ?? array()
								)
							);
						}

					}

				?>
			</div>
			<?php
		}
	?>
	</div>

	<?php
	$jbli_pagination_args = array(
		'total'   => (int) $jbli_query->max_num_pages,
		'current' => max(
			1,
			absint( $jbli_filters['paged'] ?? 1 )
		),
		'prev_text' => __( '← Προηγ.', 'job-listings' ),
		'next_text' => __( 'Επόμ. →', 'job-listings' ),
		'add_args'  => array_filter(
			array(
				'job_nomos'  => ! empty( $jbli_filters['f_nomos'] ) ? (int) $jbli_filters['f_nomos'] : null,
				'job_cat'    => ! empty( $jbli_filters['f_cat'] ) ? (int) $jbli_filters['f_cat'] : null,
				'job_type'   => ! empty( $jbli_filters['f_type'] ) ? (string) $jbli_filters['f_type'] : null,
				'job_salary' => ! empty( $jbli_filters['f_salary'] ) ? (string) $jbli_filters['f_salary'] : null,
				'job_s'      => ! empty( $jbli_filters['f_search'] ) ? (string) $jbli_filters['f_search'] : null,
			)
		),
	);
	?>

	<noscript>
		<div class="jbli_pagination jbli_pagination_noscript" role="navigation" aria-label="<?php esc_attr_e( 'Σελιδοποίηση', 'job-listings' ); ?>">
			<?php
			$jbli_pagination = paginate_links( $jbli_pagination_args );

			if ( $jbli_pagination ) { echo wp_kses_post( $jbli_pagination ); }

			?>
		</div>
	</noscript>

	<div
		class="jbli_pagination"
		id="jbli_pagination"
		data-max-pages="<?php echo esc_attr( (string) $jbli_query->max_num_pages ); ?>"
		data-current="<?php echo esc_attr(
			(string) max(
				1,
				absint( $jbli_filters['paged'] ?? 1 )
			)
		); ?>"
		role="navigation"
		aria-label="<?php esc_attr_e( 'Σελιδοποίηση', 'job-listings' ); ?>"
		style="display:none"
	>
		<?php
		$jbli_pagination = paginate_links( $jbli_pagination_args );

		if ( $jbli_pagination ) { echo wp_kses_post( $jbli_pagination ); }

		?>
	</div>

</div>
