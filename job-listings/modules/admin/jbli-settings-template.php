<?php
/**
 * Settings — Admin Template
 *
 * @package JobListings
 */

defined('ABSPATH') || exit;

$jbli_partials = __DIR__ . '/panel-parts/';

$jbli_backfill_nonce 		= wp_create_nonce('jbli_admin_backfill_storage_0');
$jbli_settings_nonce 		= wp_create_nonce('jbli_admin_save_settings_0');
$jbli_delete_on_uninstall 	= (bool) get_option('jbli_delete_on_uninstall', false);
$jbli_public_phone 			= (bool) get_option('jbli_public_phone', true);
$jbli_public_email 			= (bool) get_option('jbli_public_email', false);
$jbli_public_address 		= (bool) get_option('jbli_public_address', false);

$jbli_ads_url 				= admin_url('admin.php?page=jbli_admin_panel');
$jbli_settings_url 			= admin_url('admin.php?page=jbli_settings');
$jbli_cache_url 			= admin_url('admin.php?page=jbli_cache');
?>

<div class="wrap jbli_ap">
	<h1 class="jbli_ap_heading">
		⚙️ <?php esc_html_e('Ρυθμίσεις', 'job-listings'); ?>
	</h1>

	<?php
		$jbli_saved = sanitize_key(wp_unslash($_GET['jbli_saved'] ?? ''));
		$jbli_saved_labels = array(
			'apply_email' => __('Ρυθμίσεις email αποθηκεύτηκαν.', 'job-listings'),
			'field_options' => __('Επιλογές πεδίων αποθηκεύτηκαν.', 'job-listings'),
			'settings_saved' => __('Ρυθμίσεις απορρήτου αποθηκεύτηκαν.', 'job-listings'),
			'backfilled' => __('Οι αγγελίες συγχρονίστηκαν επιτυχώς.', 'job-listings'),
			'google_map' => __('Οι ρυθμίσεις του Google Map αποθηκεύτηκαν.', 'job-listings'),
			'form_media' => __('Η εικόνα / το video της φόρμας αποθηκεύτηκε.', 'job-listings'),
		);
	

		if ( isset( $jbli_saved_labels[ $jbli_saved ] ) )
		{

			?>
			<div class="jbli_cache_notice" style="margin-top:16px;">
				<span class="jbli_cache_notice_icon">✅</span>
				<div><strong><?php echo esc_html( $jbli_saved_labels[ $jbli_saved ] ); ?></strong></div>
			</div>
			<?php
		}
	?>

	<div class="jbli_settings_tabs">
		<a href="<?php echo esc_url( $jbli_ads_url ); ?>" class="jbli_settings_tab">
			📋 <?php esc_html_e( 'Αγγελίες', 'job-listings' ); ?>
		</a>
		<a href="<?php echo esc_url( $jbli_settings_url ); ?>" class="jbli_settings_tab jbli_settings_tab_active">
			⚙️ <?php esc_html_e( 'Ρυθμίσεις', 'job-listings' ); ?>
		</a>
		<a href="<?php echo esc_url( $jbli_cache_url ); ?>" class="jbli_settings_tab">
			⚡ <?php esc_html_e( 'Cache', 'job-listings' ); ?>
		</a>
	</div>

	<?php include $jbli_partials . 'jbli-settings-box.php'; ?>
	<?php include $jbli_partials . 'jbli-fields-settings.php'; ?>
	<?php include $jbli_partials . 'jbli-danger-zone.php'; ?>
	<?php include $jbli_partials . 'jbli-confirm-script.php'; ?>

</div>