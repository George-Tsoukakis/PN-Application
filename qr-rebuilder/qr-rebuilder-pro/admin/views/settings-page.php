<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Σελίδα «QR ReBuilder Pro → Ρυθμίσεις». Τα sections και fields τα καταχωρεί η
 * QRRP_Admin (Settings API). Φορτώνεται μέσα από μέθοδο της QRRP_Admin, άρα
 * οι μεταβλητές εδώ είναι τοπικές.
 */

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$qrrp_shortcode_tag = class_exists( 'QRRP_Tool_Page' ) ? QRRP_Tool_Page::SHORTCODE_TAG : 'qr_rebuilder_pro';
?>
<div class="wrap qrrp-admin-wrap">

	<div class="qrrp-admin-header">
		<span class="qrrp-admin-header-icon dashicons dashicons-camera" aria-hidden="true"></span>
		<div>
			<h1 id="qrrp-settings-title"><?php esc_html_e( 'QR ReBuilder Pro', 'qr-rebuilder-pro' ); ?></h1>
			<p id="qrrp-settings-description"><?php esc_html_e( 'Ρυθμίσεις σάρωσης, ανάλυσης GS1 και αναδημιουργίας QR κωδικών.', 'qr-rebuilder-pro' ); ?></p>
		</div>
	</div>

	<?php /* 2.15.2: τα admin notices (και το «Αποθηκεύτηκε») μπαίνουν εδώ, όχι μέσα στην κεφαλίδα. */ ?>
	<hr class="wp-header-end">

	<?php settings_errors(); ?>

	<form
		method="post"
		action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>"
		class="qrrp-admin-form"
		aria-labelledby="qrrp-settings-title"
		aria-describedby="qrrp-settings-description"
	>
		<?php settings_fields( QRRP_Admin::OPTION_GROUP ); ?>

		<div class="qrrp-admin-card">
			<?php do_settings_sections( QRRP_Admin::PAGE_SLUG ); ?>
			<?php submit_button( __( 'Αποθήκευση ρυθμίσεων', 'qr-rebuilder-pro' ) ); ?>
		</div>
	</form>

	<section class="qrrp-admin-card qrrp-admin-usage" aria-labelledby="qrrp-usage-title">
		<h2 id="qrrp-usage-title"><?php esc_html_e( 'Χρήση', 'qr-rebuilder-pro' ); ?></h2>
		<p>
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %s: the plugin shortcode, wrapped in a <code> tag. */
					__( 'Τοποθετήστε το shortcode %s σε οποιαδήποτε σελίδα ή άρθρο για να εμφανιστεί το εργαλείο σάρωσης και αναδημιουργίας QR.', 'qr-rebuilder-pro' ),
					'<code>[' . esc_html( $qrrp_shortcode_tag ) . ']</code>'
				)
			);
			?>
		</p>
	</section>

	<?php
	if ( class_exists( 'QRRP_Vendor_Check' ) ) {
		QRRP_Vendor_Check::render_card();
	}
	?>

</div>