<?php
defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="jbli_page_wrap jbli_page_wrap_single">

	<?php
	while ( have_posts() )
	{

		the_post();

		$jbli_id = get_the_ID();

		/* Unpublished listings are redirected earlier, in jbli_single_redirect_unpublished(). */

		$jbli_meta 			= get_post_meta($jbli_id);

		$jbli_position 		= isset($jbli_meta[JBLI_META_POSITION][0]) ? (string) $jbli_meta[JBLI_META_POSITION][0] : '';
		$jbli_pharmacy 		= isset($jbli_meta[JBLI_META_PHARMACY_NAME][0]) ? (string) $jbli_meta[JBLI_META_PHARMACY_NAME][0] : '';
		$jbli_salary   		= isset($jbli_meta[JBLI_META_SALARY][0]) ? (string) $jbli_meta[JBLI_META_SALARY][0] : '';
		$jbli_type     		= isset($jbli_meta[JBLI_META_TYPE][0]) ? (string) $jbli_meta[JBLI_META_TYPE][0] : '';
		$jbli_address  		= isset($jbli_meta[JBLI_META_ADDRESS][0]) ? sanitize_text_field((string) $jbli_meta[JBLI_META_ADDRESS][0]) : '';
		$jbli_phone    		= isset($jbli_meta[JBLI_META_CONTACT_PHONE][0]) ? (string) $jbli_meta[JBLI_META_CONTACT_PHONE][0] : '';
		$jbli_email    		= isset($jbli_meta[JBLI_META_EMAIL][0]) ? (string) $jbli_meta[JBLI_META_EMAIL][0] : '';

		$jbli_expires 		= function_exists('jbli_get_or_create_expiry_date') ? jbli_get_or_create_expiry_date($jbli_id) : (string) ($jbli_meta[JBLI_META_EXPIRES][0] ?? '');

		$jbli_cats  		= wp_get_post_terms($jbli_id, 'job_category', array('fields' => 'names'));
		$jbli_nomoi 		= wp_get_post_terms($jbli_id, 'job_nomos', array('fields' => 'names'));

		$jbli_cats  		= is_wp_error($jbli_cats) ? array() : array_filter(array_map('sanitize_text_field', $jbli_cats));
		$jbli_nomoi 		= is_wp_error($jbli_nomoi) ? array() : array_filter(array_map('sanitize_text_field', $jbli_nomoi));

		$jbli_views    		= function_exists('jbli_get_views') ? absint(jbli_get_views($jbli_id)) : 0;
		$jbli_featured 		= (function_exists('jbli_is_featured') && (bool) jbli_is_featured($jbli_id));

		$jbli_phone_clean 	= (string) preg_replace('/[\s\-\(\)]/', '', $jbli_phone);

		$jbli_content 		= (string) get_the_content();

		$jbli_show_phone   = (bool) get_option( 'jbli_public_phone', true );
		$jbli_show_email   = (bool) get_option( 'jbli_public_email', false );
		$jbli_show_address = (bool) get_option( 'jbli_public_address', false );

		?>

		<div class="jbli_single">
			<?php

			/* The submit handler redirects here with a flash notice ("published" / "updated"). */
			if ( function_exists( 'jbli_print_transient_notice' ) ) { echo wp_kses_post( jbli_print_transient_notice() ); }

			$jbli_jsonld_date_posted   = get_the_date( 'c' );
			$jbli_jsonld_valid_through = '';

			if ( $jbli_expires && false !== jbli_local_datetime_to_ts( $jbli_expires ) )
			{
				$jbli_jsonld_valid_through = wp_date( 'c', jbli_local_datetime_to_ts( $jbli_expires ) );
			}

			$jbli_employment_type_map = array(
				'plires-apasxolisi'    => 'FULL_TIME',
				'meriki-apasxolisi'    => 'PART_TIME',
				'epoximaki-apasxolisi' => 'TEMPORARY',
			);

			$jbli_employment_type = $jbli_employment_type_map[ $jbli_type ] ?? '';

			$jbli_salary_block = null;

			if ( $jbli_salary && 'negotiable' !== $jbli_salary )
			{
				$jbli_parts = explode( '-', $jbli_salary );

				if ( 2 === count( $jbli_parts ) )
				{
					$jbli_salary_block = array(
						'@type'    => 'MonetaryAmount',
						'currency' => 'EUR',
						'value'    => array(
							'@type'    => 'QuantitativeValue',
							'minValue' => (float) trim( $jbli_parts[0] ),
							'maxValue' => (float) rtrim( trim( $jbli_parts[1] ), '+' ),
							'unitText' => 'MONTH',
						),
					);
				} elseif ( substr( $jbli_salary, -1 ) === '+' ) {
					$jbli_salary_block = array(
						'@type'    => 'MonetaryAmount',
						'currency' => 'EUR',
						'value'    => array(
							'@type'    => 'QuantitativeValue',
							'minValue' => (float) rtrim( $jbli_salary, '+' ),
							'unitText' => 'MONTH',
						),
					);
				}
			}


			$jbli_postal_address = array( '@type'          => 'PostalAddress', 'addressCountry' => 'GR', );

			if ( ! empty( $jbli_nomoi ) ) { $jbli_postal_address['addressRegion'] = implode( ', ', $jbli_nomoi ); }

			if ( $jbli_address ) { $jbli_postal_address['streetAddress'] = $jbli_address; }

			$jbli_schema = array(
				'@context'           => 'https://schema.org',
				'@type'              => 'JobPosting',
				'title'              => $jbli_position ?: get_the_title( $jbli_id ),
				'description'        => wp_strip_all_tags( get_the_content() ),
				'datePosted'         => $jbli_jsonld_date_posted,
				'hiringOrganization' => array(
					'@type' => 'Organization',
					'name'  => $jbli_pharmacy ?: get_bloginfo( 'name' ),
				),
				'jobLocation' => array( '@type'   => 'Place', 'address' => $jbli_postal_address, ),
				'directApply' => true,
			);

			if ( $jbli_jsonld_valid_through ) { $jbli_schema['validThrough'] = $jbli_jsonld_valid_through; }

			if ( $jbli_employment_type ) { $jbli_schema['employmentType'] = $jbli_employment_type; }

			if ( $jbli_salary_block ) { $jbli_schema['baseSalary'] = $jbli_salary_block; }


			$jbli_schema = (array) apply_filters( 'jbli_jsonld_schema', $jbli_schema, $jbli_id );

			echo '<script type="application/ld+json">'
				. wp_json_encode( $jbli_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG )
				. '</script>' . "\n";

			?>

			<div class="jbli_single_layout">

			<div class="jbli_single_main">

			<header class="jbli_single_hero<?php echo esc_attr( $jbli_featured ? ' jbli_single_hero_featured' : '' ); ?>">
				<?php if ( $jbli_featured ) { ?><div class="jbli_single_featured_tag"><?php esc_html_e( 'Προτεινόμενη Αγγελία', 'job-listings' ); ?></div><?php } ?>

				<p class="jbli_single_org">
					<span class="jbli_single_org_label"><?php esc_html_e( 'Φαρμακείο:', 'job-listings' ); ?></span>
					<strong><?php echo esc_html( $jbli_pharmacy ? jbli_pharmacy_display_name( $jbli_pharmacy ) : '—' ); ?></strong>
				</p>

				<h1 class="jbli_single_title">
					<span class="jbli_single_title_prefix"><?php esc_html_e( 'Αναζητά:', 'job-listings' ); ?></span>
					<span class="jbli_single_title_text"><?php echo esc_html( $jbli_position ?: get_the_title( $jbli_id ) ); ?></span>
				</h1>

				<div class="jbli_single_hero_badges">
					<?php if ( $jbli_type ) { ?><span class="jbli_badge jbli_badge_white"><?php echo esc_html( jbli_type_label( $jbli_type ) ); ?></span><?php } ?>
					<?php if ( $jbli_salary ) { ?><span class="jbli_badge jbli_badge_white"><?php echo esc_html( jbli_salary_label( $jbli_salary ) ); ?></span><?php } ?>
					<?php foreach ( $jbli_nomoi as $jbli_n ) { ?><span class="jbli_badge jbli_badge_white"><?php echo esc_html( $jbli_n ); ?></span><?php } ?>

					<?php echo wp_kses_post( jbli_days_left( $jbli_id ) ); ?>
				</div>

				<div class="jbli_single_hero_meta">
					<span>
						<?php esc_html_e( 'Δημοσιεύτηκε:', 'job-listings' ); ?>
						<?php echo esc_html( get_the_date( 'd/m/Y' ) ); ?>
					</span>
					<?php

						if ( $jbli_views > 0 )
						{

							?>
							<span>
								<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
									<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
									<circle cx="12" cy="12" r="3"/>
								</svg>
								<span data-jbli_views_count="<?php echo esc_attr( (string) $jbli_id ); ?>"><?php echo esc_html( number_format_i18n( $jbli_views ) ); ?></span> <?php echo esc_html( _n( 'προβολή', 'προβολές', $jbli_views, 'job-listings' ) ); ?>
							</span>
							<?php
						}
					?>
				</div>
			</header>

			<div class="jbli_single_content_wrap">

				<div class="jbli_single_info_row">

					<div class="jbli_info_card jbli_info_card_pharmacy">
						<div class="jbli_info_card_header">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
								<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
								<polyline points="9 22 9 12 15 12 15 22"/>
							</svg>
							<?php esc_html_e( 'Στοιχεία Φαρμακείου', 'job-listings' ); ?>
						</div>

						<div class="jbli_info_card_body">
							<div class="jbli_info_card_name">
								<?php echo esc_html( $jbli_pharmacy ?: __( 'Φαρμακείο', 'job-listings' ) ); ?>
							</div>
							<?php

								if ( ! empty( $jbli_nomoi ) )
								{

									?>
									<div class="jbli_info_card_row">
										<span class="jbli_info_card_label"><?php esc_html_e( 'Νομός', 'job-listings' ); ?></span>
										<span class="jbli_info_card_value"><?php echo esc_html( implode( ', ', $jbli_nomoi ) ); ?></span>
									</div>
									<?php
								}
							?>
							<?php

								if ( $jbli_show_address && $jbli_address )
								{

									?>
									<div class="jbli_info_card_row">
										<span class="jbli_info_card_label"><?php esc_html_e( 'Διεύθυνση', 'job-listings' ); ?></span>
										<span class="jbli_info_card_value"><?php echo esc_html( $jbli_address ); ?></span>
									</div>
									<?php
								}
							?>
							<?php

								if ( $jbli_show_phone && $jbli_phone && $jbli_phone_clean )
								{

									?>
									<div class="jbli_info_card_row">
										<span class="jbli_info_card_label"><?php esc_html_e( 'Τηλέφωνο', 'job-listings' ); ?></span>
										<a href="tel:<?php echo esc_attr( $jbli_phone_clean ); ?>" class="jbli_info_card_phone">
											<?php echo esc_html( $jbli_phone ); ?>
										</a>
									</div>
									<?php
								}
							?>
							<?php

								if ( $jbli_show_email && $jbli_email && is_email( $jbli_email ) )
								{

									?>
									<div class="jbli_info_card_row">
										<span class="jbli_info_card_label"><?php esc_html_e( 'Email', 'job-listings' ); ?></span>
										<a href="mailto:<?php echo esc_attr( antispambot( $jbli_email ) ); ?>" class="jbli_info_card_email">
											<?php echo esc_html( antispambot( $jbli_email ) ); ?>
										</a>
									</div>
									<?php
								}
							?>
							<?php

								if ( $jbli_show_phone && $jbli_phone && $jbli_phone_clean )
								{

									?>
									<a href="tel:<?php echo esc_attr( $jbli_phone_clean ); ?>" class="jbli_info_card_cta">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false">
											<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.64 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 6.08 6.08l1.01-.99a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
										</svg>
										<?php esc_html_e( 'Καλέστε τώρα', 'job-listings' ); ?>
									</a>
									<?php
								}
							?>
						</div>
					</div>

					<div class="jbli_info_card jbli_info_card_job">
						<div class="jbli_info_card_header">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
								<rect x="2" y="7" width="20" height="14" rx="2"/>
								<path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
							</svg>
							<?php esc_html_e( 'Στοιχεία Αγγελίας', 'job-listings' ); ?>
						</div>

						<div class="jbli_info_card_body">
							<?php

								if ( ! empty( $jbli_cats ) )
								{

									?>
									<div class="jbli_info_card_row">
										<span class="jbli_info_card_label"><?php esc_html_e( 'Κατηγορία', 'job-listings' ); ?></span>
										<span class="jbli_info_card_value"><?php echo esc_html( implode( ', ', $jbli_cats ) ); ?></span>
									</div>
									<?php
								}
							?>
							<?php

								if ( $jbli_type )
								{

									?>
									<div class="jbli_info_card_row">
										<span class="jbli_info_card_label"><?php esc_html_e( 'Τύπος', 'job-listings' ); ?></span>
										<span class="jbli_info_card_value"><?php echo esc_html( jbli_type_label( $jbli_type ) ); ?></span>
									</div>
									<?php
								}
							?>
							<?php

								if ( ! empty( $jbli_nomoi ) )
								{

									?>
									<div class="jbli_info_card_row">
										<span class="jbli_info_card_label"><?php esc_html_e( 'Νομός', 'job-listings' ); ?></span>
										<span class="jbli_info_card_value"><?php echo esc_html( implode( ', ', $jbli_nomoi ) ); ?></span>
									</div>
									<?php
								}
							?>
							<?php

								if ( $jbli_salary )
								{

									?>
									<div class="jbli_info_card_row">
										<span class="jbli_info_card_label"><?php esc_html_e( 'Αμοιβή', 'job-listings' ); ?></span>
										<span class="jbli_info_card_value jbli_info_card_value_salary"><?php echo esc_html( jbli_salary_label( $jbli_salary ) ); ?></span>
									</div>
									<?php
								}
							?>
							<?php

								if ( $jbli_expires )
								{
									$jbli_expires_ts = $jbli_expires ? jbli_local_datetime_to_ts( $jbli_expires ) : false;

									if ( false !== $jbli_expires_ts )
									{

										?>
										<div class="jbli_info_card_row">
											<span class="jbli_info_card_label"><?php esc_html_e( 'Λήξη', 'job-listings' ); ?></span>
											<span class="jbli_info_card_value"><?php echo esc_html( wp_date( 'd/m/Y', $jbli_expires_ts ) ); ?></span>
										</div>
										<?php
									}
								}
							?>

						</div>
					</div>

				</div>
				<?php

					if ( $jbli_content )
					{

						?>
						<div class="jbli_desc_card">
							<div class="jbli_desc_card_header">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
									<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
									<polyline points="14 2 14 8 20 8"/>
									<line x1="16" y1="13" x2="8" y2="13"/>
									<line x1="16" y1="17" x2="8" y2="17"/>
									<polyline points="10 9 9 9 8 9"/>
								</svg>
								<?php esc_html_e( 'Περιγραφή Θέσης', 'job-listings' ); ?>
							</div>

							<div class="jbli_desc_card_body" id="jbli_desc_body_<?php echo esc_attr( $jbli_id ); ?>">
								<div class="jbli_desc_card_content" id="jbli_desc_content_<?php echo esc_attr( $jbli_id ); ?>">
									<?php echo jbli_single_description_html( $jbli_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses'd inside. ?>
								</div>
								<div class="jbli_desc_card_fade"></div>
							</div>
							<div class="jbli_desc_card_action" id="jbli_desc_action_<?php echo esc_attr( $jbli_id ); ?>" style="display:none;">
								<button type="button" class="jbli_desc_card_more" id="jbli_desc_more_<?php echo esc_attr( $jbli_id ); ?>">
									<?php esc_html_e( 'Δείτε περισσότερα', 'job-listings' ); ?>
								</button>
							</div>
							<script>
							document.addEventListener("DOMContentLoaded", function() {
								var bodyId = "jbli_desc_body_<?php echo esc_attr( $jbli_id ); ?>";
								var contentId = "jbli_desc_content_<?php echo esc_attr( $jbli_id ); ?>";
								var actionId = "jbli_desc_action_<?php echo esc_attr( $jbli_id ); ?>";
								var moreBtnId = "jbli_desc_more_<?php echo esc_attr( $jbli_id ); ?>";

								var bodyEl = document.getElementById(bodyId);
								var contentEl = document.getElementById(contentId);
								var actionEl = document.getElementById(actionId);
								var moreBtn = document.getElementById(moreBtnId);

								if ( bodyEl && contentEl && actionEl && moreBtn )
								{
									// If content is taller than 240px, truncate it
									if ( contentEl.scrollHeight > 240 )
									{
										bodyEl.classList.add('jbli_desc_card_body_truncate');
										actionEl.style.display = 'block';

										moreBtn.addEventListener('click', function() {
											bodyEl.classList.remove('jbli_desc_card_body_truncate');
											actionEl.style.display = 'none';
										});
									}
								}

							});
							</script>
						</div>
						<?php
					}
				?>
				<?php

					$jbli_apply_html = function_exists( 'jbli_apply_render' ) ? jbli_apply_render( $jbli_id ) : '';

					echo $jbli_apply_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML component.

					/* 9.9.53: listings imported from another site show their source. */
					$jbli_source_url  = (string) get_post_meta( $jbli_id, 'jbli_source_url', true );
					$jbli_source_site = (string) get_post_meta( $jbli_id, 'jbli_source_site', true );

					if ( '' !== $jbli_source_url )
					{
						?>
						<div class="jbli_single_source">
							<span>
								<?php esc_html_e( 'Πηγή αγγελίας:', 'job-listings' ); ?>
								<strong><?php echo esc_html( $jbli_source_site ?: (string) wp_parse_url( $jbli_source_url, PHP_URL_HOST ) ); ?></strong>
							</span>
							<a href="<?php echo esc_url( $jbli_source_url ); ?>" class="jbli_btn <?php echo esc_attr( '' === $jbli_apply_html ? 'jbli_btn_primary' : 'jbli_btn_outline' ); ?>" target="_blank" rel="noopener noreferrer nofollow">
								<?php esc_html_e( 'Δείτε την αρχική αγγελία ↗', 'job-listings' ); ?>
							</a>
						</div>
						<?php
					}
				?>

				<div class="jbli_single_back">
					<?php
					$jbli_listings_url = function_exists( 'jbli_recent_get_listings_page_url' )
						? jbli_recent_get_listings_page_url()
						: home_url( '/aggelies/' );
					?>
					<a href="<?php echo esc_url( $jbli_listings_url ); ?>" class="jbli_btn jbli_btn_outline">
						<?php esc_html_e( '← Πίσω στις αγγελίες', 'job-listings' ); ?>
					</a>
				</div>

			</div>

			</div><!-- .jbli_single_main -->

			<?php
				/* 9.9.51: picture/video on the right, with a short "interested?" card (no button since 9.9.52). */
				$jbli_side_media = (string) get_option( 'jbli_single_media_url', '' );

				if ( '' === trim( $jbli_side_media ) ) { $jbli_side_media = (string) get_option( 'jbli_form_media_url', '' ); }

				$jbli_side_media = (string) apply_filters( 'jbli_single_media_url', $jbli_side_media, $jbli_id );

				$jbli_side_items = array_values( array_filter( array(
					$jbli_type   ? jbli_type_label( $jbli_type ) : '',
					$jbli_salary ? jbli_salary_label( $jbli_salary ) : '',
					! empty( $jbli_nomoi ) ? implode( ', ', $jbli_nomoi ) : '',
				) ) );
			?>
			<aside class="jbli_single_aside">
				<?php
					echo jbli_media_panel_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside.
						$jbli_side_media,
						array(
							'kicker' => __( 'PharmacyNeeds', 'job-listings' ),
							'title'  => __( 'Ενδιαφέρεστε για αυτή τη θέση;', 'job-listings' ),
							'items'  => $jbli_side_items,
						),
						'jbli_single_media'
					);
				?>
			</aside>

			</div><!-- .jbli_single_layout -->

		</div>
		<?php
	}
	?>

</div>

<?php get_footer(); ?>
