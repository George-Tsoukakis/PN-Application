<?php
/**
 * Dashboard template.
 *
 * @package JobListings
 * @since   9.6.6
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="jbli_dash_wrap">

	<div class="jbli_dash">

		<?php 
			if ( ! empty( $jbli_notice ) )
			{

				echo wp_kses_post(
					(string) $jbli_notice
				);
			}

			?>

			<div class="jbli_dash_header">

				<div>

					<h2 class="jbli_dash_title">
						<?php esc_html_e( 'Οι αγγελίες μου', 'job-listings' ); ?>
					</h2>

					<p class="jbli_dash_sub">
						<?php
						echo esc_html(
							(string) jbli_get_pharmacy_name(
								get_current_user_id()
							)
						);
						?>
					</p>

				</div>

				<a
					href="<?php echo esc_url( $jbli_form_url ); ?>"
					class="jbli_btn jbli_btn_primary"
				>
					<?php esc_html_e( 'Νέα αγγελία', 'job-listings' ); ?>
				</a>

			</div>

			<div class="jbli_stats">

				<div class="jbli_stat jbli_stat_green">

					<span class="jbli_stat_n">
						<?php echo esc_html( (string) ( $jbli_stats['active'] ?? 0 ) ); ?>
					</span>

					<span class="jbli_stat_l">
						<?php esc_html_e( 'Ενεργές', 'job-listings' ); ?>
					</span>

				</div>

				<div class="jbli_stat jbli_stat_red">

					<span class="jbli_stat_n">
						<?php echo esc_html( (string) ( $jbli_stats['expired'] ?? 0 ) ); ?>
					</span>

					<span class="jbli_stat_l">
						<?php esc_html_e( 'Ληγμένες', 'job-listings' ); ?>
					</span>

				</div>

				<div class="jbli_stat jbli_stat_gray">

					<span class="jbli_stat_n">
						<?php echo esc_html( (string) ( $jbli_stats['draft'] ?? 0 ) ); ?>
					</span>

					<span class="jbli_stat_l">
						<?php esc_html_e( 'Ανενεργές', 'job-listings' ); ?>
					</span>

				</div>

				<div class="jbli_stat jbli_stat_blue">

					<span class="jbli_stat_n">
						<?php echo esc_html( (string) ( is_countable( $jbli_jobs ) ? count( $jbli_jobs ) : 0 ) ); ?>
					</span>

					<span class="jbli_stat_l">
						<?php esc_html_e( 'Σύνολο', 'job-listings' ); ?>
					</span>

				</div>

			</div>
			<?php

			if ( empty( $jbli_jobs ) )
			{

				?>

					<div class="jbli_empty">

						<p>
							<?php esc_html_e( 'Δεν έχετε καταχωρήσει ακόμα αγγελίες.', 'job-listings' ); ?>
						</p>

						<a
							href="<?php echo esc_url( $jbli_form_url ); ?>"
							class="jbli_btn jbli_btn_primary"
						>
							<?php esc_html_e( 'Καταχωρήστε την πρώτη σας αγγελία', 'job-listings' ); ?>
						</a>

					</div>
				<?php
			}
			else
			{
				?>

				<div class="jbli_dash_list">

					<?php
						$jbli_meta_cache = array();


						$jbli__dash_post_ids  = array_map( fn( $jbli_j ) => (int) $jbli_j->ID, array_filter( $jbli_jobs, fn( $jbli_j ) => $jbli_j instanceof WP_Post ) );
						$jbli__dash_terms_map = function_exists( 'jbli_bulk_fetch_terms' )
							? jbli_bulk_fetch_terms( $jbli__dash_post_ids )
							: array();

						foreach ( $jbli_jobs as $jbli_job ) {

							if ( ! $jbli_job instanceof WP_Post ) { continue; }

							if ( ! isset( $jbli_meta_cache[ $jbli_job->ID ] ) )
							{
								$jbli_meta_cache[ $jbli_job->ID ] = get_post_meta( $jbli_job->ID );
							}

							$jbli_meta = is_array( $jbli_meta_cache[ $jbli_job->ID ] )
								? $jbli_meta_cache[ $jbli_job->ID ]
								: array();

							$jbli_position = isset( $jbli_meta[ JBLI_META_POSITION ][0] )
								? (string) $jbli_meta[ JBLI_META_POSITION ][0]
								: '';

							$jbli_salary   = isset( $jbli_meta[ JBLI_META_SALARY ][0] )
								? (string) $jbli_meta[ JBLI_META_SALARY ][0]
								: '';

							$jbli_type     = isset( $jbli_meta[ JBLI_META_TYPE ][0] )
								? (string) $jbli_meta[ JBLI_META_TYPE ][0]
								: '';


							$jbli_cats  = $jbli__dash_terms_map[ $jbli_job->ID ]['cats']  ?? array();
							$jbli_nomoi = $jbli__dash_terms_map[ $jbli_job->ID ]['jbli_nomoi'] ?? array();

							$jbli_is_expired = (
								'job-expired' === $jbli_job->post_status
								|| ! empty( $jbli_meta[ JBLI_META_EXPIRED ][0] )
							);

							$jbli_is_draft = (
								'draft' === $jbli_job->post_status
							);

							$jbli_edit_url = add_query_arg(
								array( 'job_edit' => absint( $jbli_job->ID ), ),
								$jbli_form_url
							);
							?>

							<div class="jbli_dash_item<?php echo esc_attr( $jbli_is_expired ? ' jbli_dash_item_expired' : '' ); ?>">

								<div class="jbli_dash_item_main">

									<div class="jbli_dash_item_badges">

										<?php

											echo wp_kses_post( jbli_status_badge(
												$jbli_job
											) );

											if ( $jbli_type )
											{

												?>

												<span class="jbli_badge jbli_badge_blue">
													<?php
													echo esc_html(
														jbli_type_label(
															(string) $jbli_type
														)
													);
													?>
												</span>
												
												<?php
											}
										?>

									</div>

									<h3 class="jbli_dash_item_title">

										<?php
										echo esc_html(
											(string) (
												$jbli_position
												?: $jbli_job->post_title
											)
										);
										?>

									</h3>

									<div class="jbli_dash_item_meta">
										<?php

											if ( ! empty( $jbli_cats ) )
											{

												?>

													<span>
														<?php echo esc_html( implode( ', ', $jbli_cats ) ); ?>
													</span>
												<?php
											}

											if ( ! empty( $jbli_nomoi ) )
											{

												?>

													<span>
														<?php echo esc_html( implode( ', ', $jbli_nomoi ) ); ?>
													</span>

												<?php
											}

											if ( $jbli_salary )
											{

												?>

													<span>
														<?php
														echo esc_html(
															jbli_salary_label(
																(string) $jbli_salary
															)
														);
														?>
													</span>

												<?php
											}
										?>

										<span>
											<?php esc_html_e( 'Λήξη:', 'job-listings' ); ?>

											<?php
											echo esc_html(
												jbli_expiry_date(
													$jbli_job->ID
												)
											);
											?>
										</span>

									</div>

								</div>

								<div class="jbli_dash_item_actions">

									<?php

										$jbli_view_url = get_permalink( (int) $jbli_job->ID );

										if ( ! is_string( $jbli_view_url ) ) { $jbli_view_url = ''; }

									?>

									<a
										href="<?php echo esc_url( $jbli_view_url ); ?>"
										target="_blank"
										rel="noopener noreferrer"
										class="jbli_btn jbli_btn_sm jbli_btn_outline"
									>
										<?php esc_html_e( 'Προβολή', 'job-listings' ); ?>
									</a>

									<a
										href="<?php echo esc_url( $jbli_edit_url ); ?>"
										class="jbli_btn jbli_btn_sm jbli_btn_outline"
									>
										<?php esc_html_e( 'Επεξεργασία', 'job-listings' ); ?>
									</a>
									<?php

										if ( $jbli_is_expired )
										{
											$jbli_btn = jbli_dash_action_btn(
												$jbli_job->ID,
												'renew',
												__( 'Ανανέωση', 'job-listings' ),
												'jbli_btn_sm jbli_btn_green',
												$jbli_dash_url
											);
											echo $jbli_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML button form.
										}
										elseif ( 'pending' === $jbli_job->post_status )
										{
											?>
											<span class="jbli_btn jbli_btn_sm jbli_btn_outline" aria-disabled="true"><?php esc_html_e( 'Σε αναμονή έγκρισης', 'job-listings' ); ?></span>
											<?php
										}
										elseif ( $jbli_is_draft )
										{
											$jbli_btn = jbli_dash_action_btn(
												$jbli_job->ID,
												'jbli_activate',
												__( 'Ενεργοποίηση', 'job-listings' ),
												'jbli_btn_sm jbli_btn_green',
												$jbli_dash_url
											);
											echo $jbli_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML button form.
										}
										else
										{
											$jbli_btn = jbli_dash_action_btn(
												$jbli_job->ID,
												'jbli_deactivate',
												__( 'Παύση', 'job-listings' ),
												'jbli_btn_sm jbli_btn_outline',
												$jbli_dash_url
											);
											echo $jbli_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML button form.
										}

										$jbli_btn = jbli_dash_action_btn(
											$jbli_job->ID,
											'delete',
											__( 'Διαγραφή', 'job-listings' ),
											'jbli_btn_sm jbli_btn_danger',
											$jbli_dash_url
										);
										echo $jbli_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML button form.

									?>

								</div>

							</div>
							<?php
						}
					?>

				</div>

				<?php
			}
		?>

	</div>

</div>
<?php

	if ( ! wp_script_is( 'jbli_listings_script', 'enqueued' ) )
	{

		?>
		<script>
			document.addEventListener('DOMContentLoaded', function () {

				const buttons = document.querySelectorAll(
					'.jbli_dash_action_form button[data-confirm]'
				);

				buttons.forEach(function (btn) {

					const form = btn.closest('form');

					if ( ! form ) { return; }

					form.addEventListener('submit', function (e) {

						if ( btn.dataset.submitted )
						{
							e.preventDefault();
							return false;
						}

						const message =
							btn.getAttribute('data-confirm')
							|| 'Να διαγραφεί η αγγελία;';

						if ( ! window.confirm(message ) )
						{
							e.preventDefault();
							return false;
						}

						btn.dataset.submitted = '1';
						btn.disabled = true;
						btn.classList.add('is-loading');
					});
				});
			});
		</script>
		<?php
	}
?>
