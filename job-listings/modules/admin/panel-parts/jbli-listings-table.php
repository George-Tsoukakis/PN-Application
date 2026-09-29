<?php
/**
 * Admin panel partial: Listings table
 *
 * Renders the results count, the listings table (or empty state),
 * and pagination.
 * Expects: $jbli_query (WP_Query), $jbli_paged (int), $jbli_f_nomos, $jbli_f_cat, $jbli_f_status, $jbli_f_s.
 *
 * @package JobListings
 * @since   9.9.25
 */

defined( 'ABSPATH' ) || exit;
?>

<p class="jbli_ap_count">
	<?php esc_html_e( 'Εμφανίζονται', 'job-listings' ); ?>
	<strong><?php echo esc_html( (string) $jbli_query->post_count ); ?></strong>
	<?php esc_html_e( 'από', 'job-listings' ); ?>
	<strong><?php echo esc_html( (string) $jbli_query->found_posts ); ?></strong>
	<?php esc_html_e( 'αγγελίες', 'job-listings' ); ?>
</p>
<?php

if ( ! $jbli_query->have_posts() )
{

	?>
	<div class="jbli_ap_empty">
		<p><?php esc_html_e( 'Δεν βρέθηκαν αγγελίες με αυτά τα κριτήρια.', 'job-listings' ); ?></p>
	</div>
	<?php
}
else
{
	?>

	<table class="wp-list-table widefat fixed striped jbli_ap_table">
		<thead>
			<tr>
				<th style="width:22%"><?php esc_html_e( 'Αγγελία', 'job-listings' ); ?></th>
				<th style="width:15%"><?php esc_html_e( 'Φαρμακείο', 'job-listings' ); ?></th>
				<th style="width:10%"><?php esc_html_e( 'Νομός', 'job-listings' ); ?></th>
				<th style="width:10%"><?php esc_html_e( 'Κατηγορία', 'job-listings' ); ?></th>
				<th style="width:9%"><?php esc_html_e( 'Τύπος', 'job-listings' ); ?></th>
				<th style="width:8%"><?php esc_html_e( 'Κατάσταση', 'job-listings' ); ?></th>
				<th style="width:8%"><?php esc_html_e( 'Λήξη', 'job-listings' ); ?></th>
				<th style="width:18%"><?php esc_html_e( 'Ενέργειες', 'job-listings' ); ?></th>

			</tr>
		</thead>

		<tbody>
			<?php
			$jbli_filter_fields = array_filter( array(
				'f_nomos'  => $jbli_f_nomos  ?: null,
				'f_cat'    => $jbli_f_cat    ?: null,
				'f_status' => $jbli_f_status ?: null,
				'f_s'      => $jbli_f_s      ?: null,
			) );

			$jbli_meta_cache = array();


			$jbli__ap_post_ids  = array_map( 'intval', wp_list_pluck( $jbli_query->posts, 'ID' ) );
			$jbli__ap_terms_map = function_exists( 'jbli_bulk_fetch_terms' ) ? jbli_bulk_fetch_terms( $jbli__ap_post_ids ) : array();

			while ( $jbli_query->have_posts( ) ) {

				$jbli_query->the_post();

				$jbli_id = get_the_ID();
				$post    = get_post( $jbli_id );

				if ( ! isset( $jbli_meta_cache[ $jbli_id ] ) )
				{
					$jbli_meta_cache[ $jbli_id ] = get_post_meta( $jbli_id );
				}

				$jbli_meta 		= $jbli_meta_cache[ $jbli_id ];

				$jbli_position 		= $jbli_meta[ JBLI_META_POSITION ][0]      ?? '';
				$jbli_pharmacy 		= $jbli_meta[ JBLI_META_PHARMACY_NAME ][0] ?? '';
				$jbli_type     		= $jbli_meta[ JBLI_META_TYPE ][0]          ?? '';
				$jbli_salary   		= $jbli_meta[ JBLI_META_SALARY ][0]        ?? '';
				$jbli_nomoi 		= $jbli__ap_terms_map[ $jbli_id ]['jbli_nomoi'] ?? array();
				$jbli_cats  		= $jbli__ap_terms_map[ $jbli_id ]['cats']  ?? array();
				$jbli_author 		= $post instanceof WP_Post ? get_userdata( (int) $post->post_author ) : false;
				$jbli_is_expired 	= $post instanceof WP_Post && ( 'job-expired' === $post->post_status || ! empty( $jbli_meta[ JBLI_META_EXPIRED ][0] ) );
				$jbli_is_active  	= $post instanceof WP_Post && 'publish' === $post->post_status && ! $jbli_is_expired;
				$jbli_is_draft   	= $post instanceof WP_Post && 'draft'   === $post->post_status;
				$jbli_is_pending 	= $post instanceof WP_Post && 'pending' === $post->post_status;
			?>
			<tr class="jbli_ap_row<?php echo esc_attr( $jbli_is_expired ? ' jbli_ap_row_expired' : '' ); ?>">

				<td>
					<strong>
						<a href="<?php echo esc_url( get_permalink( $jbli_id ) ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $jbli_position ?: get_the_title() ); ?>
						</a>
					</strong>
					<?php

						if ( $jbli_salary )
						{

							?>
							<br><small class="jbli_ap_salary"><?php echo esc_html( jbli_salary_label( (string) $jbli_salary ) ); ?></small>
							<?php
						}
					?>
					<?php

						if ( $jbli_author instanceof WP_User )
						{

							?>
							<br><small class="jbli_ap_author"><?php echo esc_html( $jbli_author->user_email ); ?></small>
							<?php
						}
					?>
				</td>

				<td><?php echo esc_html( $jbli_pharmacy ?: '—' ); ?></td>
				<td><?php echo esc_html( ! empty( $jbli_nomoi ) && ! is_wp_error( $jbli_nomoi ) ? implode( ', ', $jbli_nomoi ) : '—' ); ?></td>
				<td><?php echo esc_html( ! empty( $jbli_cats )  && ! is_wp_error( $jbli_cats )  ? implode( ', ', $jbli_cats )  : '—' ); ?></td>
				<td><?php echo $jbli_type ? esc_html( jbli_type_label( (string) $jbli_type ) ) : '—'; ?></td>

				<td>
					<?php

						if ( $post instanceof WP_Post )
						{

							?>
							<?php echo wp_kses_post( jbli_status_badge( $post ) ); ?>
							<?php
						}
					?>
				</td>

				<td>
					<span class="jbli_ap_expiry_date"><?php echo esc_html( jbli_expiry_date( $jbli_id ) ); ?></span>
					<?php echo wp_kses_post( jbli_days_left( $jbli_id ) ); ?>
				</td>

				<td class="jbli_ap_actions">
					<?php

						if ( $jbli_is_pending )
						{

							?>
							<?php $jbli_btn = jbli_admin_action_btn( $jbli_id, 'approve', __( 'Έγκριση', 'job-listings' ), 'button-primary button-small', $jbli_filter_fields ); echo $jbli_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML button form. ?>
							<?php
						}
					?>
					<?php

						if ( $jbli_is_draft )
						{

							?>
							<?php $jbli_btn = jbli_admin_action_btn( $jbli_id, 'approve', __( 'Ενεργοποίηση', 'job-listings' ), 'button-small', $jbli_filter_fields ); echo $jbli_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML button form. ?>
							<?php
						}
					?>
					<?php

						if ( $jbli_is_expired )
						{

							?>
							<?php $jbli_btn = jbli_admin_action_btn( $jbli_id, 'renew', __( 'Ανανέωση', 'job-listings' ), 'button-primary button-small', $jbli_filter_fields ); echo $jbli_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML button form. ?>
							<?php
						}
					?>
					<?php

						if ( $jbli_is_active )
						{

							?>
							<?php $jbli_btn = jbli_admin_action_btn( $jbli_id, 'jbli_deactivate', __( 'Παύση', 'job-listings' ), 'button-small', $jbli_filter_fields ); echo $jbli_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML button form. ?>
							<?php
						}
					?>
					<?php

				$jbli_edit_url = function_exists( 'jbli_get_form_page_url' ) ? add_query_arg( 'job_edit', $jbli_id, jbli_get_form_page_url() ) : add_query_arg( 'job_edit', $jbli_id, home_url( '/nea-aggelia/' ) );
				?>
				<a href="<?php echo esc_url( $jbli_edit_url ); ?>"
				   class="button button-small jbli_ap_btn_edit"
				   title="<?php esc_attr_e( 'Επεξεργασία αγγελίας', 'job-listings' ); ?>">
					&#9998; <?php esc_html_e( 'Επεξεργασία', 'job-listings' ); ?>
				</a>
				<?php $jbli_btn = jbli_admin_action_btn( $jbli_id, 'delete', __( 'Διαγραφή', 'job-listings' ), 'button-small jbli_btn_delete', $jbli_filter_fields ); echo $jbli_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML button form. ?>

				<?php

					$jbli_sub_nonce    = wp_create_nonce( 'jbli_submissions_' . $jbli_id );
					$jbli_export_nonce = wp_create_nonce( 'jbli_export_csv_' . $jbli_id );
				?>
				<button
					type="button"
					class="button button-small"
					data-jbli_open_submissions="<?php echo esc_attr( (string) $jbli_id ); ?>"
					data-jbli_export_nonce="<?php echo esc_attr( $jbli_export_nonce ); ?>"
					title="<?php esc_attr_e( 'Υποβολές Ενδιαφέροντος', 'job-listings' ); ?>"
				>
					<?php esc_html_e( 'Υποβολές', 'job-listings' ); ?>
				</button>
				<?php

				?>
				<span
					hidden
					data-jbli_sub_nonce<?php echo esc_attr( (string) $jbli_id ); ?>="<?php echo esc_attr( $jbli_sub_nonce ); ?>"
				></span>

				</td>

			</tr>
		<?php
	}
?>
		</tbody>
	</table>
	<?php

		if ( $jbli_query->max_num_pages > 1 )
		{

			?>
			<div class="jbli_ap_pagination tablenav bottom">
				<div class="tablenav-pages">
					<?php
					
						$jbli_base_url = add_query_arg(
							array_filter( array(
								'post_type' => JBLI_CPT,
								'page'      => 'jbli_admin_panel',
								'f_nomos'   => $jbli_f_nomos  ?: null,
								'f_cat'     => $jbli_f_cat    ?: null,
								'f_status'  => $jbli_f_status ?: null,
								'f_s'       => $jbli_f_s      ?: null,
							) ),
							admin_url( 'edit.php' )
						);

						$jbli_pagination = paginate_links( array(
							'base'      => esc_url_raw( add_query_arg( 'paged', '%#%', $jbli_base_url ) ),
							'format'    => '',
							'current'   => $jbli_paged,
							'total'     => $jbli_query->max_num_pages,
							'prev_text' => __( '‹ Προηγ.', 'job-listings' ),
							'next_text' => __( 'Επόμ. ›',  'job-listings' ),
							'jbli_type' => 'plain',
						) );

						if ( $jbli_pagination ) { echo wp_kses_post( $jbli_pagination ); }

					?>
				</div>
			</div>
			<?php
		}
	?>
	<?php
}
?>
