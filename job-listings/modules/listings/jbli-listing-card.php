<?php
/**
 * Partial: Single listing card.
 *
 * @package JobListings
 * @since   9.6.1
 */

defined('ABSPATH') || exit;

$jbli_permalink = get_permalink($jbli_id);

if ( !is_string( $jbli_permalink ) ) 
{
	$jbli_permalink = '';
}

$jbli_title 	= (string) ( $jbli_position ?: get_the_title($jbli_id) );
$jbli_excerpt 	= wp_trim_words( wp_strip_all_tags( (string) get_the_content( null, false, $jbli_id ) ), 20, '…' );
$jbli_nomoi 	= array_filter( array_map( 'sanitize_text_field', (array) $jbli_nomoi ) );
$jbli_cats 		= array_filter( array_map( 'sanitize_text_field', (array) $jbli_cats ) );
$jbli_days_left = isset( $jbli_days_left ) ? $jbli_days_left : ( function_exists( 'jbli_days_left' ) ? jbli_days_left( $jbli_id ) : '' );
?>
<article class="jbli_card<?php echo esc_attr( $jbli_is_featured ? ' jbli_card_featured' : '' ); ?>" itemscope
	itemtype="https://schema.org/JobPosting">
	<?php

	if ( $jbli_is_featured ) 
	{

		?>
		<div class="jbli_featured_ribbon">
			<?php esc_html_e('Προτεινόμενη', 'job-listings'); ?>
		</div>
		<?php
	}
	?>

	<div class="jbli_card_accent"></div>

	<div class="jbli_card_inner">

		<div class="jbli_card_top">
			<span class="jbli_card_pharmacy" itemprop="hiringOrganization" itemscope
				itemtype="https://schema.org/Organization">
				<span class="jbli_card_pharmacy_label"><?php esc_html_e( 'Φαρμακείο:', 'job-listings' ); ?></span>
				<strong
					itemprop="name"><?php echo esc_html( $jbli_pharmacy ? jbli_pharmacy_display_name( $jbli_pharmacy ) : '—' ); ?></strong>
			</span>
			<?php

			if ( $jbli_type ) 
			{

				?>
				<span class="jbli_badge jbli_badge_blue">
					<?php echo esc_html( jbli_type_label( $jbli_type ) ); ?>
				</span>
				<?php
			}
			?>
		</div>

		<h2 class="jbli_card_title" itemprop="title">
			<a href="<?php echo esc_url( $jbli_permalink ); ?>" itemprop="url"
				aria-label="<?php 
					/* translators: %s: Job listing title */
					echo esc_attr( sprintf( __( 'Δείτε την αγγελία: %s', 'job-listings' ), $jbli_title ) ); 
				?>">
				<span class="jbli_card_title_prefix"><?php esc_html_e( 'Αναζητά:', 'job-listings' ); ?></span>
				<em><?php echo esc_html( $jbli_title ); ?></em>
			</a>
		</h2>

		<ul class="jbli_card_meta" aria-label="<?php esc_attr_e('Στοιχεία αγγελίας', 'job-listings'); ?>">
			<?php

				if ( !empty( $jbli_nomoi ) ) 
				{

					?>
						<li itemprop="jobLocation">
							<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
								aria-hidden="true">
								<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
								<circle cx="12" cy="10" r="3" />
							</svg>
							<?php echo esc_html( implode( ', ', $jbli_nomoi ) ); ?>
						</li>
					<?php
				}
			?>
			<?php

			if ( $jbli_salary ) {

				?>
				<li class="jbli_card_salary">
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
						aria-hidden="true">
						<circle cx="12" cy="12" r="10" />
						<path d="M12 6v12M9 9h4.5a1.5 1.5 0 0 1 0 3H10a1.5 1.5 0 0 0 0 3H15" />
					</svg>
					<?php echo esc_html( jbli_salary_label( $jbli_salary ) ); ?>
				</li>
				<?php
			}
			?>
			<?php

			if ( !empty( $jbli_cats ) ) {

				?>
				<li>
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
						aria-hidden="true">
						<path d="M4 7h16M4 12h16M4 17h10" />
					</svg>
					<?php echo esc_html( implode( ', ', $jbli_cats ) ); ?>
				</li>
				<?php
			}
			?>
		</ul>
		<?php

		if ( $jbli_excerpt ) {

			?>
			<p class="jbli_card_excerpt" itemprop="description">
				<?php echo esc_html( $jbli_excerpt ); ?>
			</p>
			<?php
		}
		?>

		<div class="jbli_card_footer">
			<div class="jbli_card_footer_buttons">

				<a href="<?php echo esc_url( $jbli_permalink ); ?>" class="jbli_btn jbli_btn_primary jbli_btn_sm">
					<?php esc_html_e( 'Δείτε την αγγελία', 'job-listings' ); ?>
				</a>

				<?php

				if ( function_exists( 'jbli_apply_render' ) ) 
				{
					$jbli_apply = jbli_apply_render($jbli_id);

					if ( '' !== $jbli_apply ) 
					{
						echo $jbli_apply; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML component.
					}

					unset( $jbli_apply );
				}
				?>
			</div>

			<?php

			if ( $jbli_views > 0 ) 
			{

				?>
				<span class="jbli_views" title="<?php esc_attr_e('Προβολές', 'job-listings'); ?>">
					<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
						aria-hidden="true" focusable="false">
						<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
						<circle cx="12" cy="12" r="3"></circle>
					</svg>
					<?php echo esc_html( number_format_i18n( $jbli_views ) ); ?>
				</span>
				<?php
			}
			?>
		</div>
		<div class="jbli_card_footer_meta">

			<?php

				if ( ! empty( $jbli_days_left ) ) 
				{

					?>
					<span class="jbli_card_footer_expiry">
						<?php echo wp_kses_post( $jbli_days_left ); ?>
					</span>
					<?php
					
				}
			?>
		</div>

	</div>

</article>