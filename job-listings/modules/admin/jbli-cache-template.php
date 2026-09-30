<?php
/**
 * Settings & Cache — Admin Template
 *
 * Variables available from jbli_render_cache_page():
 *   $jbli_bust             int      Current asset bust counter.
 *   $jbli_has_rocket       bool     Whether WP Rocket is active.
 *   $jbli_has_object_cache bool     Whether an external object cache is in use.
 *   $jbli_cleared          array    Targets cleared in the previous request.
 *
 * @package JobListings
 * @since   9.9.0
 */

defined( 'ABSPATH' ) || exit;

$jbli_all_url   = admin_url( 'admin.php?page=jbli_cache' );
$jbli_nonce_val = wp_create_nonce( 'jbli_cache_clear' );
$jbli_labels    = function_exists( 'jbli_cache_target_labels' ) ? (array) jbli_cache_target_labels() : array();
?>

<div class="wrap jbli_ap">

	<h1 class="jbli_ap_heading">⚡ <?php esc_html_e( 'Cache', 'job-listings' ); ?></h1>
	<?php

		if ( ! empty( $jbli_cleared ) )
		{

			?>

				<div class="jbli_cache_notice">
					<span class="jbli_cache_notice_icon">✅</span>
					<div>
						<strong><?php esc_html_e( 'Εκκαθάριση ολοκληρώθηκε!', 'job-listings' ); ?></strong>
						<ul>
							<?php

								foreach ( $jbli_cleared as $jbli_key ) {

									?>

									<li><?php echo esc_html( isset( $jbli_labels[ $jbli_key ] ) ? (string) $jbli_labels[ $jbli_key ] : (string) $jbli_key ); ?></li>
									
									<?php
								}
							?>

						</ul>
					</div>
				</div>
			<?php
		}
	?>

	<div class="jbli_settings_tabs">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=jbli_admin_panel' ) ); ?>" class="jbli_settings_tab">📋 <?php esc_html_e( 'Αγγελίες', 'job-listings' ); ?></a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=jbli_settings' ) ); ?>" class="jbli_settings_tab">⚙️ <?php esc_html_e( 'Ρυθμίσεις', 'job-listings' ); ?></a>
		<a href="<?php echo esc_url( $jbli_all_url ); ?>" class="jbli_settings_tab jbli_settings_tab_active">⚡ <?php esc_html_e( 'Cache', 'job-listings' ); ?></a>
	</div>

	<div class="jbli_cache_clearall">
		<div class="jbli_cache_clearall_text">
			<p class="jbli_cache_clearall_title">🧹 <?php esc_html_e( 'Καθαρισμός Όλων', 'job-listings' ); ?></p>
			<p class="jbli_cache_clearall_sub">
				<?php esc_html_e( 'Διαγράφει transients, ανανεώνει assets, κάνει flush rewrite rules και εκκαθαρίζει WP Rocket (αν ενεργό).', 'job-listings' ); ?>
			</p>
		</div>
		<form method="post" action="<?php echo esc_url( $jbli_all_url ); ?>">
			<input type="hidden" name="jbli_cache_action" value="clear">
			<input type="hidden" name="jbli_cache_nonce" value="<?php echo esc_attr( $jbli_nonce_val ); ?>">
			<input type="hidden" name="jbli_cache_targets[]" value="all">
			<button type="submit" class="button jbli_cache_btn_primary">🧹 <?php esc_html_e( 'Καθαρισμός Όλων', 'job-listings' ); ?></button>
		</form>
	</div>

	<div class="jbli_cache_grid">

		<div class="jbli_cache_card">
			<div class="jbli_cache_card_head">
				<span class="jbli_cache_card_icon">⚡</span>
				<h2 class="jbli_cache_card_title"><?php esc_html_e( 'Plugin Transients', 'job-listings' ); ?></h2>
				<span class="jbli_cache_card_badge jbli_cache_card_badge_ok"><?php esc_html_e( 'Ενεργό', 'job-listings' ); ?></span>
			</div>
			<p class="jbli_cache_card_desc"><?php esc_html_e( 'Διαγράφει όλα τα WordPress transients που ξεκινούν με jbli_ (taxonomy terms cache, φίλτρα κ.λπ.). Χρήσιμο μετά από προσθήκη νέου νομού ή κατηγορίας.', 'job-listings' ); ?></p>
			<div class="jbli_cache_card_footer">
				<span class="jbli_cache_card_meta"><?php esc_html_e( 'jbli_terms_* + όλα τα jbli_* transients', 'job-listings' ); ?></span>
				<?php 

					if ( function_exists( 'jbli_cache_clear_form' ) ) 
					{ 
						jbli_cache_clear_form( 'jbli_transients', __( 'Εκκαθάριση', 'job-listings' ) ); 
					} 

				?>
			</div>
		</div>

		<div class="jbli_cache_card<?php echo esc_attr( ! $jbli_has_object_cache ? ' jbli_cache_card_unavailable' : '' ); ?>">
			<div class="jbli_cache_card_head">
				<span class="jbli_cache_card_icon">🗄️</span>
				<h2 class="jbli_cache_card_title"><?php esc_html_e( 'Object Cache', 'job-listings' ); ?></h2>
				<span class="jbli_cache_card_badge <?php echo esc_attr( $jbli_has_object_cache ? 'jbli_cache_card_badge_ok' : 'jbli_cache_card_badge_off' ); ?>"><?php echo $jbli_has_object_cache ? esc_html__( 'Ενεργό', 'job-listings' ) : esc_html__( 'Βασικό', 'job-listings' ); ?></span>
			</div>
			<p class="jbli_cache_card_desc"><?php esc_html_e( 'Εκκαθαρίζει τον WordPress object cache (Redis / Memcached / APCu). Αν δεν χρησιμοποιείς external object cache, εκτελείται wp_cache_flush() στο in-memory cache της τρέχουσας request.', 'job-listings' ); ?></p>
			<div class="jbli_cache_card_footer">
				<span class="jbli_cache_card_meta">wp_cache_flush()</span>
				<?php 

					if ( function_exists( 'jbli_cache_clear_form' ) ) 
					{ 
						jbli_cache_clear_form( 'object_cache', __( 'Εκκαθάριση', 'job-listings' ) ); 
					} 

				?>
			</div>
		</div>

		<div class="jbli_cache_card">
			<div class="jbli_cache_card_head">
				<span class="jbli_cache_card_icon">🔗</span>
				<h2 class="jbli_cache_card_title"><?php esc_html_e( 'Rewrite Rules', 'job-listings' ); ?></h2>
				<span class="jbli_cache_card_badge jbli_cache_card_badge_ok"><?php esc_html_e( 'Ενεργό', 'job-listings' ); ?></span>
			</div>
			<p class="jbli_cache_card_desc"><?php esc_html_e( 'Κάνει flush τα WordPress rewrite rules. Απαραίτητο μετά από αλλαγή θέματος, ενεργοποίηση plugin ή αλλαγή permalink structure ώστε τα URLs του plugin να λειτουργούν σωστά.', 'job-listings' ); ?></p>
			<div class="jbli_cache_card_footer">
				<span class="jbli_cache_card_meta">flush_rewrite_rules()</span>
				<?php 

					if ( function_exists( 'jbli_cache_clear_form' ) ) 
					{ 
						jbli_cache_clear_form( 'rewrite_rules', __( 'Εκκαθάριση', 'job-listings' ) ); 
					} 

				?>
			</div>
		</div>

		<div class="jbli_cache_card<?php echo esc_attr( ! $jbli_has_rocket ? ' jbli_cache_card_unavailable' : '' ); ?>">
			<div class="jbli_cache_card_head">
				<span class="jbli_cache_card_icon">🚀</span>
				<h2 class="jbli_cache_card_title">WP Rocket</h2>
				<span class="jbli_cache_card_badge <?php echo esc_attr( $jbli_has_rocket ? 'jbli_cache_card_badge_ok' : 'jbli_cache_card_badge_off' ); ?>"><?php echo $jbli_has_rocket ? esc_html__( 'Ενεργό', 'job-listings' ) : esc_html__( 'Δεν εντοπίστηκε', 'job-listings' ); ?></span>
			</div>
			<p class="jbli_cache_card_desc"><?php esc_html_e( 'Εκκαθαρίζει τον full-page cache του WP Rocket για όλο το domain (rocket_clean_domain). Χρήσιμο μετά από αλλαγές σε design ή περιεχόμενο ώστε οι επισκέπτες να βλέπουν άμεσα τις αλλαγές.', 'job-listings' ); ?></p>
			<div class="jbli_cache_card_footer">
				<span class="jbli_cache_card_meta">rocket_clean_domain()</span>
				<?php 

					if ( $jbli_has_rocket && function_exists( 'jbli_cache_clear_form' ) ) 
					{ 
						jbli_cache_clear_form( 'rocket', __( 'Εκκαθάριση', 'job-listings' ) ); 
					} 
					else 
					{ 
						?>
						<button type="button" class="button" disabled title="<?php esc_attr_e( 'Το WP Rocket δεν είναι ενεργό', 'job-listings' ); ?>"><?php esc_html_e( 'Δεν διαθέσιμο', 'job-listings' ); ?></button>
						<?php
					}
				?>
			</div>
		</div>

		<div class="jbli_cache_card">
			<div class="jbli_cache_card_head">
				<span class="jbli_cache_card_icon">🎨</span>
				<h2 class="jbli_cache_card_title"><?php esc_html_e( 'CSS / JS Assets', 'job-listings' ); ?></h2>
				<span class="jbli_cache_card_badge jbli_cache_card_badge_ok"><?php esc_html_e( 'Ενεργό', 'job-listings' ); ?></span>
			</div>
			<p class="jbli_cache_card_desc"><?php esc_html_e( 'Αυξάνει τον μετρητή έκδοσης CSS/JS (asset bust). Οι browsers των επισκεπτών κατεβάζουν αμέσως τα νέα αρχεία αντί να χρησιμοποιούν το cached αντίγραφό τους. Χρήσιμο μετά από κάθε αλλαγή design.', 'job-listings' ); ?></p>
			<div class="jbli_cache_card_footer">
				<span class="jbli_cache_card_meta">
					<?php 
					/* translators: %d: current asset bust version number */
					printf( esc_html__( 'Τρέχουσα έκδοση: v%d', 'job-listings' ), (int) $jbli_bust ); 
					?>
				</span>
				<?php 

					if ( function_exists( 'jbli_cache_clear_form' ) ) 
					{ 
						jbli_cache_clear_form( 'asset_bust', __( 'Ανανέωση', 'job-listings' ) ); 
					} 

				?>
			</div>
		</div>

	</div>

	<div class="jbli_cache_info">
		<strong>💡 <?php esc_html_e( 'Πότε να κάνεις εκκαθάριση;', 'job-listings' ); ?></strong>
		<ul>
			<li><?php esc_html_e( 'Μετά από αλλαγή θέματος ή ενεργοποίηση νέου plugin → Rewrite Rules + Rocket', 'job-listings' ); ?></li>
			<li><?php esc_html_e( 'Μετά από αλλαγή CSS / design → Asset Bust + Rocket', 'job-listings' ); ?></li>
			<li><?php esc_html_e( 'Μετά από προσθήκη νέου Νομού ή Κατηγορίας → Plugin Transients', 'job-listings' ); ?></li>
			<li><?php esc_html_e( 'Αν κάτι δεν εμφανίζεται σωστά → Καθαρισμός Όλων', 'job-listings' ); ?></li>
		</ul>
	</div>

</div>
