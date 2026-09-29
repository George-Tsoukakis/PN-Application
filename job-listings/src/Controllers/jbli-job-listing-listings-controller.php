<?php

namespace JobListings\Controllers;

use JobListings\Models\Listing;
use JobListings\Formatters\Html;
use JobListings\Support\View;

defined( 'ABSPATH' ) || exit;

/**
 * Handles [listings] rendering and filter AJAX responses.
 */
final class ListingsController {

	/**
	 * Render the [listings] shortcode.
	 *
	 * @return string
	 */
	public function jbli_render_shortcode() {

		if ( ! function_exists( 'jbli_parse_filters' ) || ! function_exists( 'jbli_build_query' ) )
		{
			return '';
		}

		$jbli_filters = jbli_parse_filters( wp_unslash( $_GET ) );
		$jbli_query   = jbli_build_query( $jbli_filters );

		$jbli_html = View::jbli_make()->jbli_render(
			'modules/listings/jbli-listings-template.php',
			array( 'jbli_filters' => $jbli_filters, 'jbli_query' => $jbli_query, )
		);

		wp_reset_postdata();

		return $jbli_html;

	}

	/**
	 * Handle AJAX filter requests.
	 *
	 * @return void
	 */
	public function jbli_handle_ajax_filter() {

		check_ajax_referer( 'jbli_nonce', 'jbli_nonce' );

		if ( ! function_exists( 'jbli_parse_filters' ) || ! function_exists( 'jbli_build_query' ) || ! function_exists( 'jbli_render_cards_html' ) )
		{

			wp_send_json_error(
				array( 'jbli_message' => __( 'Δεν ήταν δυνατή η φόρτωση των αγγελιών.', 'job-listings' ), ),
				500
			);

		}

		$jbli_filters = jbli_parse_filters( (array) wp_unslash( $_POST ) );
		$jbli_query   = jbli_build_query( $jbli_filters );
		$jbli_paged   = max( 1, absint( $jbli_filters['paged'] ?? 1 ) );

		$jbli_html = jbli_render_cards_html( $jbli_query, $jbli_paged );

		wp_reset_postdata();

		wp_send_json_success(
			array(
				'html'      => $jbli_html,
				'count'     => (int) $jbli_query->found_posts,
				'max_pages' => (int) $jbli_query->max_num_pages,
				'paged'     => $jbli_paged,
			)
		);

	}

	/**
	 * Build card HTML for a WP_Query result set.
	 *
	 * @param \WP_Query $jbli_query Query result.
	 * @param int       $jbli_paged Current page.
	 * @return string
	 */
	public function jbli_render_cards_html( $jbli_query, $jbli_paged = 1 ) {

		if ( ! $jbli_query instanceof \WP_Query ) { return ''; }

		$jbli_paged = max( 1, absint( $jbli_paged ) );

		if ( ! $jbli_query->have_posts( ) ) { return Html::jbli_empty_listings(); }

		$jbli_post_ids  = array_map( 'intval', wp_list_pluck( $jbli_query->posts, 'ID' ) );
		$jbli_terms_map = function_exists( 'jbli_bulk_fetch_terms' )
			? jbli_bulk_fetch_terms( $jbli_post_ids )
			: array();

		ob_start();

		echo '<div class="jbli_cards">';

		while ( $jbli_query->have_posts( ) ) {

			$jbli_query->the_post();

			$jbli_id      = get_the_ID();
			$jbli_listing = Listing::jbli_from_id(
				$jbli_id,
				$jbli_terms_map[ $jbli_id ] ?? null
			);

			if ( ! $jbli_listing ) { continue; }

			if ( function_exists( 'jbli_render_listing_card' ) )
			{
				jbli_render_listing_card( $jbli_listing->jbli_to_card_array() );
			}

		}

		echo '</div>';

		printf(
			'<div class="jbli_ajax_pagination" data-max-pages="%s" data-current="%s"></div>',
			esc_attr( (string) $jbli_query->max_num_pages ),
			esc_attr( (string) $jbli_paged )
		);

		$jbli_html = (string) ob_get_clean();

		if ( wp_doing_ajax() && function_exists( 'jbli_apply_modal_registry' ) && function_exists( 'jbli_apply_modal_html' ) )
		{

			$jbli_modal_ids   = jbli_apply_modal_registry( 0, true, true );
			$jbli_modals_html = '';

			foreach ( $jbli_modal_ids as $jbli_modal_post_id ) {

				$jbli_modals_html .= jbli_apply_modal_html( (int) $jbli_modal_post_id );

			}

			if ( $jbli_modals_html )
			{
				$jbli_html .= '<div class="jbli_ajax_modals" hidden>' . $jbli_modals_html . '</div>';
			}

		}

		return $jbli_html;

	}

}
