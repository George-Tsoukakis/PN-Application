<?php
/**
 * PlanDose admin: the Settings screen (PlanDose → Settings).
 *
 * Split out of class-plandose-admin.php so the settings form, its POST
 * handler, and its small template helpers live together, independent of
 * invoice handling and the subscriptions dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Admin_Settings {

	/**
	 * Prevents the settings-save hook from being registered more than once.
	 * WordPress already de-duplicates identical static-method callbacks by
	 * unique id, so this is defensive, matching the other PlanDose classes.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Settings only a site administrator (manage_options) may
	 * change. They decide how long the audit log keeps its records,
	 * whether it stores full IP addresses and whether uninstalling wipes
	 * every subscription and invoice — privacy and data-retention choices
	 * of the controller. A user who manages PlanDose through the delegated
	 * capability (Plandose_Admin::capability()) sees them read-only, and
	 * handle_save_settings() keeps the stored values for them.
	 */
	const PROTECTED_KEYS = array( 'audit_retention_days', 'audit_anonymize_ip', 'keep_data_on_uninstall' );

	/**
	 * Settings stored as lists. The audit entry of a save records how they
	 * changed (counts, and for short lists what was added/removed) instead
	 * of copying whole arrays into the log.
	 */
	const COUNT_ONLY_KEYS = array( 'display_pages' );

	/**
	 * Longest string value copied into the save_settings audit entry.
	 */
	const AUDIT_VALUE_MAX_LENGTH = 200;

	/**
	 * Whether the current user may change PROTECTED_KEYS.
	 *
	 * @return bool
	 */
	public static function can_change_protected_settings() {
		return current_user_can( 'manage_options' );
	}

	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		add_action( 'admin_post_plandose_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss_display_scope_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_display_scope_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_display_pages_notice' ) );
	}

	/**
	 * Whether we are on one of PlanDose's own admin screens.
	 *
	 * Thin wrapper over Plandose_Admin_Invoices::on_plandose_screen(),
	 * which lives in a different file. The two notices below called it
	 * bare, so an incomplete deployment missing that one file turned every
	 * wp-admin page load into a fatal error — while every other cross-class
	 * call in the plugin is guarded. Returns false when the helper is
	 * unavailable, which only costs a notice nobody sees.
	 *
	 * @return bool
	 */
	private static function on_plandose_screen() {
		return class_exists( 'Plandose_Admin_Invoices' )
			&& method_exists( 'Plandose_Admin_Invoices', 'on_plandose_screen' )
			&& Plandose_Admin_Invoices::on_plandose_screen();
	}

	/**
	 * Nudge the admin to narrow the display scope.
	 *
	 * `display_scope` defaults to 'everywhere' on purpose, so that upgrading
	 * never makes the button disappear from a page it used to be on. The cost
	 * of leaving it there is that every front-end request on the site — every
	 * post, every archive, every page that has nothing to do with the tool —
	 * enqueues the PlanDose stylesheet and a ≈6 KB loader (the JavaScript
	 * bundle loads only when the button is used — see plandose-loader.js).
	 * On a site where the tool lives on one or two pages that is pure waste,
	 * and it is a single dropdown to fix.
	 *
	 * Shown only on PlanDose's own admin screens, only while the scope is
	 * still 'everywhere', and dismissible for good.
	 */
	public static function render_display_scope_notice() {
		if ( ! self::on_plandose_screen() ) {
			return;
		}

		if ( 'everywhere' !== (string) Plandose_Settings::setting( 'display_scope', 'everywhere' ) ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), 'plandose_dismissed_display_scope_notice', true ) ) {
			return;
		}

		$settings_url = add_query_arg(
			array( 'page' => 'plandose-settings' ),
			admin_url( 'admin.php' )
		);

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'plandose_dismiss_display_scope_notice', '1' ),
			'plandose_dismiss_display_scope_notice'
		);

		printf(
			'<div class="notice notice-info"><p>%s</p><p><a class="button button-secondary" href="%s">%s</a> <a href="%s">%s</a></p></div>',
			esc_html__( 'Το κουμπί του PlanDose φορτώνει αυτή τη στιγμή σε ΟΛΕΣ τις σελίδες του site. Αυτό σημαίνει ότι τα αρχεία CSS και JavaScript του εργαλείου κατεβαίνουν και εκεί που δεν χρησιμοποιείται ποτέ. Αν το εργαλείο ζει σε μία ή δύο συγκεκριμένες σελίδες, περιορίστε το από τη ρύθμιση "Πού εμφανίζεται" και κερδίστε ταχύτητα σε όλο το υπόλοιπο site.', 'plandose' ),
			esc_url( $settings_url ),
			esc_html__( 'Άνοιγμα ρυθμίσεων', 'plandose' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Το θέλω σε όλες τις σελίδες, μη μου το ξαναδείξεις', 'plandose' )
		);
	}

	/**
	 * Warn when the scope is narrowed to specific pages but no page was
	 * picked.
	 *
	 * Plandose_Settings::should_display_here() shows the tool NOWHERE in
	 * that case, and a save of that combination is refused
	 * (Plandose_Settings::render_scope_refused_notice()). This notice covers
	 * a stored state from before that rule, which a save cannot reach.
	 *
	 * Unlike the sibling notice above this one is NOT dismissible and does
	 * not honour that notice's dismissal: 'everywhere' is a legitimate
	 * choice someone may want to keep, whereas this state is nobody's
	 * intention. It disappears the moment a page is selected, or the scope
	 * is set back to something else.
	 */
	public static function render_display_pages_notice() {
		if ( ! self::on_plandose_screen() ) {
			return;
		}

		if ( 'selected' !== (string) Plandose_Settings::setting( 'display_scope', 'everywhere' ) ) {
			return;
		}

		$pages = Plandose_Settings::setting( 'display_pages', array() );

		if ( is_array( $pages ) && ! empty( $pages ) ) {
			return;
		}

		$settings_url = add_query_arg(
			array( 'page' => 'plandose-settings' ),
			admin_url( 'admin.php' )
		);

		printf(
			'<div class="notice notice-warning"><p>%s</p><p><a class="button button-secondary" href="%s">%s</a></p></div>',
			esc_html__( 'Το κουμπί του PlanDose δεν εμφανίζεται σε ΚΑΜΙΑ σελίδα: η ρύθμιση λέει «Μόνο σε επιλεγμένες σελίδες», αλλά δεν έχει επιλεγεί καμία. Επιλέξτε τουλάχιστον μία σελίδα και αποθηκεύστε.', 'plandose' ),
			esc_url( $settings_url ),
			esc_html__( 'Επιλογή σελίδων', 'plandose' )
		);
	}

	/**
	 * Handle the dismissal link of the display-scope notice.
	 */
	public static function maybe_dismiss_display_scope_notice() {
		if ( ! isset( $_GET['plandose_dismiss_display_scope_notice'] ) ) {
			return;
		}

		if ( ! current_user_can( Plandose_Admin::capability() ) ) {
			return;
		}

		check_admin_referer( 'plandose_dismiss_display_scope_notice' );

		update_user_meta( get_current_user_id(), 'plandose_dismissed_display_scope_notice', 1 );

		wp_safe_redirect( remove_query_arg( array( 'plandose_dismiss_display_scope_notice', '_wpnonce' ) ) );
		exit;
	}

	public static function render_settings_page() {
		$s         = Plandose_Settings::settings();
		$roles     = wp_roles()->roles;
		$protected = ! self::can_change_protected_settings();
		Plandose_Admin::page_header( __( 'PlanDose → Settings', 'plandose' ), __( 'Διαχείριση ρυθμίσεων για το PlanDose.', 'plandose' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own admin-post redirect (nonce verified on the POST handler); triggers no state change.
		if ( isset( $_GET['updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['updated'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'plandose' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="plandose-settings-form">
			<input type="hidden" name="action" value="plandose_save_settings" />
			<?php wp_nonce_field( 'plandose_save_settings' ); ?>

			<div class="plandose-savebar">
				<span class="plandose-savebar-hint"><?php esc_html_e( 'Οι αλλαγές εφαρμόζονται αμέσως μετά την αποθήκευση.', 'plandose' ); ?></span>
				<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Αποθήκευση Ρυθμίσεων', 'plandose' ); ?></button>
			</div>

			<div class="plandose-settings-grid">
				<?php self::settings_card_start( '⚙️', __( 'Γενικές Ρυθμίσεις', 'plandose' ), '', __( 'Ενεργοποίηση του εργαλείου και βασική συμπεριφορά σύνδεσης.', 'plandose' ) ); ?>
					<?php self::toggle( 'enabled', __( 'Ενεργοποίηση PlanDose', 'plandose' ), $s['enabled'] ); ?>
					<?php self::text( 'login_page', __( 'Σελίδα σύνδεσης', 'plandose' ), $s['login_page'], __( 'Κενό = προεπιλεγμένο wp-login.php', 'plandose' ) ); ?>
					<?php self::textarea( 'guest_message', __( 'Μήνυμα μη συνδεδεμένου χρήστη', 'plandose' ), $s['guest_message'] ); ?>
					<p class="pd-field-help"><?php esc_html_e( 'Εμφανίζεται στο popup του επισκέπτη, πάνω από το κουμπί εισόδου. Αφήστε το κενό για να μην εμφανίζεται τίποτα.', 'plandose' ); ?></p>
				<?php self::settings_card_end(); ?>

				<?php self::settings_card_start( '👁️', __( 'Εμφάνιση Κουμπιού', 'plandose' ), '', __( 'Που, σε ποιους, και πώς εμφανίζεται το κουμπί στο site.', 'plandose' ) ); ?>
					<?php self::toggle( 'show_to_guests', __( 'Εμφάνιση κουμπιού σε επισκέπτες', 'plandose' ), $s['show_to_guests'] ); ?>
					<?php
					/*
					 * Shown always on and read-only. Only accounts whose
					 * business category is «Φαρμακείο» may use PlanDose.
					 * The field has no name, so it is never posted, and no
					 * setting is stored for it.
					 */
					printf(
						'<label class="pd-toggle"><span>%s</span><input type="checkbox" checked="checked" disabled="disabled"><em></em></label><p class="pd-field-help">%s</p>',
						esc_html__( 'Μόνο για εγγεγραμμένα φαρμακεία (account_type)', 'plandose' ),
						esc_html__( 'Το PlanDose είναι διαθέσιμο μόνο σε λογαριασμούς με Κατηγορία «Φαρμακείο».', 'plandose' )
					);
					?>
					<?php self::toggle( 'allow_admin_preview', __( 'Ορατό και στον διαχειριστή για δοκιμή', 'plandose' ), $s['allow_admin_preview'] ); ?>
					<?php self::display_scope_field( $s ); ?>
					<label><?php esc_html_e( 'Θέση κουμπιού', 'plandose' ); ?>
						<select name="button_position">
							<option value="bottom_right" <?php selected( $s['button_position'], 'bottom_right' ); ?>><?php esc_html_e( 'Κάτω δεξιά', 'plandose' ); ?></option>
							<option value="bottom_left" <?php selected( $s['button_position'], 'bottom_left' ); ?>><?php esc_html_e( 'Κάτω αριστερά', 'plandose' ); ?></option>
							<option value="top_right" <?php selected( $s['button_position'], 'top_right' ); ?>><?php esc_html_e( 'Πάνω δεξιά', 'plandose' ); ?></option>
							<option value="top_left" <?php selected( $s['button_position'], 'top_left' ); ?>><?php esc_html_e( 'Πάνω αριστερά', 'plandose' ); ?></option>
						</select>
					</label>
					<?php self::text( 'button_text', __( 'Κείμενο κουμπιού (επεξήγηση και όνομα για αναγνώστες οθόνης· στο κουμπί φαίνεται «PlanDose»)', 'plandose' ), $s['button_text'] ); ?>
					<label><?php esc_html_e( 'Χρώμα κουμπιού', 'plandose' ); ?><input type="color" name="button_color" value="<?php echo esc_attr( $s['button_color'] ); ?>"></label>
				<?php self::settings_card_end(); ?>

				<?php self::settings_card_start( '✨', __( 'Προεπισκόπηση', 'plandose' ), '', __( 'Πώς θα φαίνεται το κουμπί στο front-end.', 'plandose' ) ); ?>
					<?php // The preview shows what the site shows — «PlanDose» on the button, the configured text as its tooltip. ?>
					<div class="pd-preview-button" style="background:<?php echo esc_attr( $s['button_color'] ); ?>" title="<?php echo esc_attr( $s['button_text'] ); ?>">⚕ <?php esc_html_e( 'PlanDose', 'plandose' ); ?></div>
					<p class="description"><?php echo esc_html( sprintf( /* translators: %s: the configured button text */ __( 'Επεξήγηση (tooltip): %s', 'plandose' ), $s['button_text'] ) ); ?></p>
					<div class="pd-preview-modal"><strong><?php esc_html_e( 'Δημιουργία Πλάνου Δοσολογίας', 'plandose' ); ?></strong><p><?php esc_html_e( 'Συμπληρώστε τα φάρμακα του ασθενή και εκτυπώστε το πλάνο.', 'plandose' ); ?></p><span><?php esc_html_e( 'Συνέχεια', 'plandose' ); ?></span></div>
				<?php self::settings_card_end(); ?>

				<?php self::settings_card_start( '👑', __( 'Συνδρομές', 'plandose' ), '', __( 'Όρια ημερών για πλάνα, και η διάρκεια της συνδρομής Pro.', 'plandose' ) ); ?>
					<?php self::number( 'max_plan_days', __( 'Μέγιστες ημέρες πλάνου', 'plandose' ), $s['max_plan_days'] ); ?>
					<?php self::number( 'default_pro_days', __( 'Διάρκεια Pro (ημέρες)', 'plandose' ), $s['default_pro_days'], __( 'Εφαρμόζεται αυτόματα σε κάθε ενεργοποίηση/παράταση Pro — π.χ. 365 = 1 έτος.', 'plandose' ) ); ?>
					<?php self::number( 'max_add_days', __( 'Μέγιστες ημέρες ανά χειροκίνητη παράταση', 'plandose' ), $s['max_add_days'], __( 'Ανώτατο όριο ημερών που μπορεί να προσθέσει ο διαχειριστής σε μία ενέργεια παράτασης.', 'plandose' ) ); ?>
					<?php self::number( 'expiring_days', __( 'Πόσες ημέρες πριν τη λήξη θεωρείται «λήγει σύντομα»', 'plandose' ), $s['expiring_days'], __( 'Ορίζει την καρτέλα «Λήγουν Σύντομα», τον μετρητή στην κορυφή και την κόκκινη επισήμανση στις κάρτες. Προεπιλογή 7 ημέρες.', 'plandose' ) ); ?>
				<?php self::settings_card_end(); ?>

				<?php self::settings_card_start( '🖨️', __( 'Εκτυπώσεις', 'plandose' ), '', __( 'Μηνιαία όρια εκτυπώσεων για δωρεάν και Pro χρήστες.', 'plandose' ) ); ?>
					<?php self::number( 'free_monthly_limit', __( 'Μηνιαίο όριο δωρεάν εκτυπώσεων', 'plandose' ), $s['free_monthly_limit'] ); ?>
					<?php self::number( 'pro_print_limit', __( 'Όριο Pro εκτυπώσεων', 'plandose' ), $s['pro_print_limit'], __( '0 = απεριόριστο', 'plandose' ) ); ?>
					<?php self::toggle( 'show_print_counter', __( 'Εμφάνιση μετρητή εκτυπώσεων στον χρήστη', 'plandose' ), $s['show_print_counter'] ); ?>
					<?php self::toggle( 'calendar_qr', __( 'QR «Υπενθυμίσεις στο κινητό» στο τυπωμένο φύλλο', 'plandose' ), $s['calendar_qr'] ); ?>
					<small><?php esc_html_e( 'Ο ασθενής σκανάρει το QR και οι δόσεις μπαίνουν στο ημερολόγιο του κινητού του. Το πλάνο πηγαίνει μέσα στο QR, όχι στον server του site. Ο σύνδεσμος μένει στο ιστορικό του κινητού· αν ο ασθενής προσθέσει τις υπενθυμίσεις σε συγχρονιζόμενο ημερολόγιο (Google, iCloud), ισχύει η πολιτική του παρόχου.', 'plandose' ); ?></small>
					<label><?php esc_html_e( 'Προσανατολισμός ετικετών (Pro, ετικετογράφος)', 'plandose' ); ?>
						<select name="label_orientation">
							<?php foreach ( Plandose_Settings::label_orientations() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['label_orientation'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<p class="pd-field-help"><?php esc_html_e( 'Το μέγεθος της ετικέτας το ορίζει ο οδηγός του ετικετογράφου (ρολό/ετικέτες που έχει μέσα). Για φαρδιές-κοντές ετικέτες (π.χ. Zebra 100×47) η «Οριζόντια» τυπώνει κάθε φάρμακο σε μία ετικέτα. Αν οι ετικέτες βγαίνουν κομμένες ή σε δύο κομμάτια, αλλάξτε εδώ τον προσανατολισμό.', 'plandose' ); ?></p>
				<?php self::settings_card_end(); ?>

				<?php self::settings_card_start( '🧾', __( 'Τιμολόγια', 'plandose' ), '', __( 'Περιορισμοί για τα αρχεία τιμολογίων που ανεβάζουν οι φαρμακοποιοί.', 'plandose' ) ); ?>
					<?php self::number( 'invoice_max_mb', __( 'Μέγιστο μέγεθος upload (MB)', 'plandose' ), $s['invoice_max_mb'] ); ?>
					<p class="pd-muted description">
						<?php
						printf(
							/* translators: %s: the effective server-side upload limit in MB */
							esc_html__( 'Ο server σας δέχεται μέχρι %s MB ανά upload (upload_max_filesize/post_max_size). Ρύθμιση πάνω από αυτό δεν θα λειτουργεί πραγματικά.', 'plandose' ),
							esc_html( (string) round( wp_max_upload_size() / ( 1024 * 1024 ), 1 ) )
						);
						?>
					</p>
					<label><?php esc_html_e( 'Επιτρεπόμενοι τύποι αρχείων', 'plandose' ); ?></label>
					<?php self::checkbox_group( 'invoice_mimes', self::mime_choices(), $s['invoice_mimes'] ); ?>
				<?php self::settings_card_end(); ?>

				<?php self::settings_card_start( '🛡️', __( 'Απόρρητο & Audit Log', 'plandose' ), '', __( 'Διατήρηση των εγγραφών audit log και προστασία προσωπικών δεδομένων.', 'plandose' ) ); ?>
					<?php if ( $protected ) : ?>
						<p class="pd-field-help"><?php esc_html_e( 'Αυτές οι ρυθμίσεις απορρήτου και διατήρησης δεδομένων αλλάζουν μόνο από διαχειριστή του site.', 'plandose' ); ?></p>
					<?php endif; ?>
					<?php self::number( 'audit_retention_days', __( 'Διατήρηση audit log (ημέρες)', 'plandose' ), $s['audit_retention_days'], __( '0 = διατήρηση για πάντα. Παλαιότερες εγγραφές διαγράφονται αυτόματα από το ημερήσιο cron.', 'plandose' ), $protected ); ?>
					<?php self::toggle( 'audit_anonymize_ip', __( 'Ανωνυμοποίηση διεύθυνσης IP στο audit log', 'plandose' ), $s['audit_anonymize_ip'], $protected ); ?>
					<?php self::toggle( 'keep_data_on_uninstall', __( 'Διατήρηση δεδομένων σε περίπτωση απεγκατάστασης', 'plandose' ), $s['keep_data_on_uninstall'], $protected ); ?>
					<p class="pd-muted description">
						<?php esc_html_e( 'Όσο είναι ενεργό (προτεινόμενο), η διαγραφή του plugin από τη σελίδα Πρόσθετα ΔΕΝ σβήνει συνδρομές, μετρητές εκτυπώσεων, audit log ή αρχεία τιμολογίων. Απενεργοποιήστε το μόνο αν θέλετε πραγματικά να διαγραφούν όλα οριστικά.', 'plandose' ); ?>
					</p>
					<?php if ( empty( $s['keep_data_on_uninstall'] ) && class_exists( 'Plandose_Admin_Invoices' ) && Plandose_Admin_Invoices::legacy_invoices_block_uninstall() ) : ?>
						<p class="pd-scope-warning">
							<?php esc_html_e( '⚠ Υπάρχουν ακόμη παλιά τιμολόγια στη Βιβλιοθήκη Πολυμέσων (δεν έχουν μεταφερθεί ή δεν έχουν διαγραφεί τα πρωτότυπα). Μέχρι να ολοκληρωθεί η μεταφορά (Διαγνωστικά) και η «Διαγραφή πρωτοτύπων», η διαγραφή του plugin κρατά όλα τα δεδομένα.', 'plandose' ); ?>
						</p>
					<?php endif; ?>
				<?php self::settings_card_end(); ?>

				<?php self::settings_card_start( '🖨️', __( 'Κείμενα Εκτύπωσης', 'plandose' ), '', __( 'Το κείμενο που τυπώνεται στο κάτω μέρος κάθε πλάνου δοσολογίας.', 'plandose' ) ); ?>
					<?php self::textarea( 'disclaimer', __( 'Κείμενο disclaimer στην εκτύπωση', 'plandose' ), $s['disclaimer'] ); ?>
					<?php self::text( 'thanks_message_line1', __( 'Γραμμή ευχαριστίας 1', 'plandose' ), $s['thanks_message_line1'] ); ?>
					<?php self::text( 'thanks_message_line2', __( 'Γραμμή ευχαριστίας 2', 'plandose' ), $s['thanks_message_line2'] ); ?>
					<p class="pd-muted description">
						<?php esc_html_e( 'Οι δύο γραμμές τυπώνονται κάτω από το disclaimer. Αν τις αφήσετε όπως είναι, το Pro περιβάλλον στα Αγγλικά δείχνει τις αντίστοιχες αγγλικές προτάσεις· αν γράψετε δικό σας κείμενο, αυτό εμφανίζεται αυτούσιο και στις δύο γλώσσες.', 'plandose' ); ?>
					</p>
				<?php self::settings_card_end(); ?>

				<?php
				/*
				 * No «Επιτρεπόμενοι ρόλοι» column: under the pharmacy-only
				 * rule no role can let non-pharmacies in, so the control
				 * would do nothing. Hiding roles works — it blocks
				 * pharmacies that hold that role.
				 */
				self::settings_card_start( '👤', __( 'Ρόλοι Πρόσβασης', 'plandose' ), 'wide', __( 'Ποιοι ρόλοι χρηστών δεν βλέπουν καθόλου το εργαλείο, ακόμη κι αν ο λογαριασμός είναι φαρμακείο.', 'plandose' ) );
				?>
					<div class="pd-role-column">
						<label class="pd-group-label"><?php esc_html_e( 'Κρυφό για τους ρόλους', 'plandose' ); ?></label>
						<?php self::checkbox_group( 'hidden_roles', self::role_choices( $roles ), $s['hidden_roles'] ); ?>
					</div>
				<?php self::settings_card_end(); ?>
			</div>
		</form>
		<?php
		Plandose_Admin::page_footer();
	}

	public static function handle_save_settings() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Μη επιτρεπτή μέθοδος αιτήματος.', 'plandose' ), '', array( 'response' => 405 ) );
		}

		if ( ! current_user_can( Plandose_Admin::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'plandose_save_settings' );

		$defaults = Plandose_Settings::default_settings();
		$before   = Plandose_Settings::settings();
		$s        = array();
		$bools    = array( 'enabled', 'show_to_guests', 'allow_admin_preview', 'show_print_counter', 'calendar_qr', 'audit_anonymize_ip', 'keep_data_on_uninstall' );
		foreach ( $bools as $key ) {
			$s[ $key ] = empty( $_POST[ $key ] ) ? 0 : 1;
		}

		// wp_unslash() only. sanitize_text_field() strips %XX octets,
		// mangling valid encoded URLs; safe_internal_url() does the real
		// validation (control characters, host, scheme) and normalization.
		$s['login_page']         = isset( $_POST['login_page'] ) && is_string( $_POST['login_page'] ) ? Plandose_Settings::safe_internal_url( wp_unslash( $_POST['login_page'] ), '' ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated and normalized by safe_internal_url().
		$s['guest_message']      = isset( $_POST['guest_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['guest_message'] ) ) : $defaults['guest_message'];
		$s['button_position']    = isset( $_POST['button_position'] ) ? sanitize_key( wp_unslash( $_POST['button_position'] ) ) : 'bottom_right';
		$s['label_orientation']  = isset( $_POST['label_orientation'] ) ? sanitize_key( wp_unslash( $_POST['label_orientation'] ) ) : $defaults['label_orientation'];

		$posted_scope        = isset( $_POST['display_scope'] ) ? sanitize_key( wp_unslash( $_POST['display_scope'] ) ) : $defaults['display_scope'];
		$s['display_scope']  = array_key_exists( $posted_scope, Plandose_Settings::display_scopes() ) ? $posted_scope : $defaults['display_scope'];
		$posted_pages        = isset( $_POST['display_pages'] ) ? wp_unslash( (array) $_POST['display_pages'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below: scalars only, intval(), > 0.
		/*
		 * intval(), not absint() — the same choice, and for the same
		 * reason, as Plandose_Settings::sanitize_settings(). absint()
		 * takes the absolute value, so a stray -4 arriving in the POST
		 * body would become page 4: a real page, on the site, that nobody
		 * selected. Discard it instead. (The sanitizer's own pass cannot
		 * catch this, since by then the value would already have been made
		 * positive here.)
		 */
		$s['display_pages']  = array_values(
			array_unique(
				array_filter(
					// Nested arrays dropped first — intval( array )
					// is 1, which silently selected page 1.
					array_map( 'intval', array_filter( $posted_pages, 'is_scalar' ) ),
					static function ( $page_id ) {
						return $page_id > 0;
					}
				)
			)
		);

		$s['button_text']        = isset( $_POST['button_text'] ) ? sanitize_text_field( wp_unslash( $_POST['button_text'] ) ) : $defaults['button_text'];
		// is_string() — button_color[]=… would be a TypeError in sanitize_hex_color().
		$s['button_color']       = isset( $_POST['button_color'] ) && is_string( $_POST['button_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['button_color'] ) ) : $defaults['button_color'];
		$s['button_color']       = $s['button_color'] ? $s['button_color'] : $defaults['button_color'];
		$s['free_monthly_limit'] = isset( $_POST['free_monthly_limit'] ) ? max( 1, min( Plandose_Settings::LIMIT_FREE_MONTHLY_MAX, intval( wp_unslash( $_POST['free_monthly_limit'] ) ) ) ) : (int) Plandose_Settings::setting( 'free_monthly_limit', $defaults['free_monthly_limit'] );
		$s['max_plan_days']      = isset( $_POST['max_plan_days'] ) ? max( 1, min( Plandose_Settings::LIMIT_MAX_PLAN_DAYS, intval( wp_unslash( $_POST['max_plan_days'] ) ) ) ) : (int) Plandose_Settings::setting( 'max_plan_days', $defaults['max_plan_days'] );
		$s['default_pro_days']   = isset( $_POST['default_pro_days'] ) ? max( 1, min( Plandose_Settings::LIMIT_PRO_DAYS_MAX, intval( wp_unslash( $_POST['default_pro_days'] ) ) ) ) : (int) Plandose_Settings::setting( 'default_pro_days', $defaults['default_pro_days'] );
		$s['max_add_days']       = isset( $_POST['max_add_days'] ) ? max( 1, min( Plandose_Settings::LIMIT_ADD_DAYS_MAX, intval( wp_unslash( $_POST['max_add_days'] ) ) ) ) : (int) Plandose_Settings::setting( 'max_add_days', $defaults['max_add_days'] );
		$s['expiring_days']      = isset( $_POST['expiring_days'] ) ? max( 1, min( 365, intval( wp_unslash( $_POST['expiring_days'] ) ) ) ) : (int) Plandose_Settings::setting( 'expiring_days', $defaults['expiring_days'] );
		$s['pro_print_limit']    = isset( $_POST['pro_print_limit'] ) ? max( 0, min( Plandose_Settings::LIMIT_PRO_PRINT_MAX, intval( wp_unslash( $_POST['pro_print_limit'] ) ) ) ) : (int) Plandose_Settings::setting( 'pro_print_limit', $defaults['pro_print_limit'] );
		$s['invoice_max_mb']     = isset( $_POST['invoice_max_mb'] ) ? max( 1, min( Plandose_Settings::LIMIT_INVOICE_MB_MAX, intval( wp_unslash( $_POST['invoice_max_mb'] ) ) ) ) : (int) Plandose_Settings::setting( 'invoice_max_mb', $defaults['invoice_max_mb'] );
		$s['audit_retention_days'] = isset( $_POST['audit_retention_days'] ) ? max( 0, min( Plandose_Settings::LIMIT_AUDIT_RETENTION_MAX, intval( wp_unslash( $_POST['audit_retention_days'] ) ) ) ) : (int) Plandose_Settings::setting( 'audit_retention_days', $defaults['audit_retention_days'] );
		$s['disclaimer']         = isset( $_POST['disclaimer'] ) ? sanitize_textarea_field( wp_unslash( $_POST['disclaimer'] ) ) : $defaults['disclaimer'];
		$s['thanks_message_line1'] = isset( $_POST['thanks_message_line1'] ) ? sanitize_text_field( wp_unslash( $_POST['thanks_message_line1'] ) ) : $defaults['thanks_message_line1'];
		$s['thanks_message_line2'] = isset( $_POST['thanks_message_line2'] ) ? sanitize_text_field( wp_unslash( $_POST['thanks_message_line2'] ) ) : $defaults['thanks_message_line2'];

		$valid_mimes        = Plandose_Settings::valid_invoice_mimes();
		$posted_mimes       = isset( $_POST['invoice_mimes'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['invoice_mimes'] ) ) : array();
		$s['invoice_mimes'] = array_values( array_intersect( $valid_mimes, $posted_mimes ) );

		if ( empty( $s['invoice_mimes'] ) ) {
			$s['invoice_mimes'] = array( 'application/pdf' );
		}

		$wp_roles           = wp_roles()->roles;
		$valid_roles        = array_keys( $wp_roles );
		$s['hidden_roles']  = isset( $_POST['hidden_roles'] ) ? array_values( array_intersect( $valid_roles, array_map( 'sanitize_key', wp_unslash( (array) $_POST['hidden_roles'] ) ) ) ) : array();

		// Without manage_options the protected settings keep their
		// stored values, whatever the POST body says (the controls are
		// rendered disabled, so normally nothing is posted for them).
		$restricted = ! self::can_change_protected_settings();

		if ( $restricted ) {
			foreach ( self::PROTECTED_KEYS as $key ) {
				$s[ $key ] = array_key_exists( $key, $before ) ? $before[ $key ] : $defaults[ $key ];
			}
		}

		$s = Plandose_Settings::sanitize_settings( $s );

		$updated = update_option( 'plandose_settings', $s );
		$saved   = get_option( 'plandose_settings', false );

		/*
		 * Confirm the write landed — but confirm the right thing.
		 *
		 * Not $saved === $s: a strict comparison, key order and value
		 * types included, against the exact array built above, is
		 * stricter than "did it save". Any plugin filtering
		 * option_plandose_settings, or an object cache that round-trips an
		 * int as a string, would fail the comparison on a save that had in
		 * fact succeeded — and the response to failing it is wp_die() with
		 * a 500 and the words "σφάλμα βάσης δεδομένων", which is both alarming
		 * and untrue.
		 *
		 * What actually needs checking is that a settings array is now
		 * stored and that it carries the values this form just submitted.
		 * Filters and casting differences are somebody else's business;
		 * a lost write is ours. Comparing loosely, field by field, catches
		 * the failure that matters and nothing else.
		 */
		$save_ok = is_array( $saved );

		if ( $save_ok ) {
			foreach ( $s as $key => $value ) {
				if ( ! array_key_exists( $key, $saved ) ) {
					$save_ok = false;
					break;
				}

				// Loose on scalars (an int read back as '1' is fine), strict
				// on arrays after normalizing order (role and mime lists).
				if ( is_array( $value ) || is_array( $saved[ $key ] ) ) {
					$expected = (array) $value;
					$actual   = (array) $saved[ $key ];
					sort( $expected );
					sort( $actual );

					if ( $expected != $actual ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- Deliberate: see above.
						$save_ok = false;
						break;
					}

					continue;
				}

				if ( (string) $value !== (string) $saved[ $key ] ) {
					$save_ok = false;
					break;
				}
			}
		}

		if ( ! $save_ok ) {
			Plandose_Admin::audit(
				'save_settings_failed',
				0,
				array(
					'update_option_result' => (bool) $updated,
					'option_present'       => is_array( $saved ),
				)
			);

			wp_die(
				esc_html__( 'Οι ρυθμίσεις δεν αποθηκεύτηκαν λόγω σφάλματος βάσης δεδομένων. Δοκιμάστε ξανά.', 'plandose' ),
				esc_html__( 'Σφάλμα αποθήκευσης', 'plandose' ),
				array( 'response' => 500 )
			);
		}

		Plandose_Subscriber_Query::invalidate_subscriber_cache();
		$audit_meta = array( 'changed' => self::settings_changes( $before, $s ) );

		if ( $restricted ) {
			$audit_meta['protected_locked'] = true;
		}

		Plandose_Admin::audit( 'save_settings', 0, $audit_meta );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'plandose-settings',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * What a save changed, for the save_settings audit entry.
	 *
	 * Scalars: array( 'before' => …, 'after' => … ), long strings cut to
	 * AUDIT_VALUE_MAX_LENGTH. Lists: counts, plus the added/removed values
	 * except for COUNT_ONLY_KEYS (display_pages can hold hundreds of IDs).
	 * Compared loosely, like the save check above, so an int read back as
	 * a string is not reported as a change.
	 *
	 * @param array $before Settings before the save.
	 * @param array $after  Settings as saved.
	 * @return array<string,array>
	 */
	public static function settings_changes( $before, $after ) {
		$before  = is_array( $before ) ? $before : array();
		$after   = is_array( $after ) ? $after : array();
		$changes = array();

		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			$old = array_key_exists( $key, $before ) ? $before[ $key ] : null;
			$new = array_key_exists( $key, $after ) ? $after[ $key ] : null;

			if ( is_array( $old ) || is_array( $new ) ) {
				$old_list = array_map( 'strval', array_filter( (array) $old, 'is_scalar' ) );
				$new_list = array_map( 'strval', array_filter( (array) $new, 'is_scalar' ) );
				$added    = array_values( array_diff( $new_list, $old_list ) );
				$removed  = array_values( array_diff( $old_list, $new_list ) );

				if ( empty( $added ) && empty( $removed ) ) {
					continue;
				}

				$entry = array(
					'before_count' => count( $old_list ),
					'after_count'  => count( $new_list ),
				);

				if ( ! in_array( $key, self::COUNT_ONLY_KEYS, true ) ) {
					$entry['added']   = $added;
					$entry['removed'] = $removed;
				}

				$changes[ $key ] = $entry;
				continue;
			}

			if ( (string) $old === (string) $new ) {
				continue;
			}

			$changes[ $key ] = array(
				'before' => self::audit_scalar( $old ),
				'after'  => self::audit_scalar( $new ),
			);
		}

		return $changes;
	}

	/**
	 * A scalar setting value as recorded in the audit log.
	 *
	 * @param mixed $value Setting value.
	 * @return int|float|string|bool|null
	 */
	private static function audit_scalar( $value ) {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		$value = (string) $value;

		if ( strlen( $value ) > self::AUDIT_VALUE_MAX_LENGTH ) {
			$value = ( function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::AUDIT_VALUE_MAX_LENGTH ) : substr( $value, 0, self::AUDIT_VALUE_MAX_LENGTH ) ) . '…';
		}

		return $value;
	}

	private static function mime_choices() {
		$labels = array(
			'application/pdf' => 'PDF',
			'image/jpeg'       => 'JPEG',
			'image/png'        => 'PNG',
			'image/webp'       => 'WEBP',
		);
		$choices = array();
		foreach ( Plandose_Settings::valid_invoice_mimes() as $mime ) {
			$choices[ $mime ] = isset( $labels[ $mime ] ) ? $labels[ $mime ] : $mime;
		}
		return $choices;
	}

	private static function role_choices( $roles ) {
		$choices = array();
		foreach ( $roles as $role_key => $role ) {
			$choices[ $role_key ] = translate_user_role( $role['name'] );
		}
		return $choices;
	}

	private static function settings_card_start( $icon, $title, $class = '', $desc = '' ) {
		echo '<section class="plandose-settings-card ' . esc_attr( $class ) . '">';
		echo '<div class="pd-card-head"><h2><span>' . esc_html( $icon ) . '</span>' . esc_html( $title ) . '</h2>';
		if ( $desc ) {
			echo '<p class="pd-card-desc">' . esc_html( $desc ) . '</p>';
		}
		echo '</div><div class="pd-card-body">';
	}

	private static function settings_card_end() {
		echo '</div></section>';
	}

	/**
	 * Page targeting: where the PlanDose button is allowed to appear.
	 *
	 * Narrowing this is the single cheapest performance win available — on
	 * any page outside the scope the plugin prints nothing and enqueues no
	 * CSS or JS at all. The default stays "everywhere" so upgrading changes
	 * nothing until the admin decides otherwise.
	 *
	 * @param array $s Current settings.
	 */
	private static function display_scope_field( $s ) {
		$scope    = isset( $s['display_scope'] ) ? (string) $s['display_scope'] : 'everywhere';
		$selected = isset( $s['display_pages'] ) && is_array( $s['display_pages'] )
			? array_map( 'absint', $s['display_pages'] )
			: array();

		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'sort_column' => 'post_title',
				'number'      => 300,
			)
		);

		if ( ! is_array( $pages ) ) {
			$pages = array();
		}

		/*
		 * Every page that is already selected gets an <option>,
		 * whatever its status and wherever it sorts. The list above holds
		 * the first 300 published pages only; a selected page outside it
		 * (the 301st, or one since made private, draft or scheduled) would
		 * otherwise have no option, be missing from the next POST, and be
		 * dropped from display_pages on ANY save of this screen — with every
		 * selected page dropped, the scope silently falls back to «everywhere».
		 */
		$listed  = array_map( 'intval', wp_list_pluck( $pages, 'ID' ) );
		$missing = array_values( array_diff( $selected, $listed ) );

		if ( $missing ) {
			$extra = get_posts(
				array(
					'post_type'        => 'page',
					'post__in'         => $missing,
					'post_status'      => array( 'publish', 'private', 'draft', 'pending', 'future' ),
					'numberposts'      => count( $missing ),
					'orderby'          => 'post__in',
					'suppress_filters' => true,
				)
			);

			if ( is_array( $extra ) ) {
				$pages = array_merge( $extra, $pages );
			}
		}
		?>
		<label><?php esc_html_e( 'Σε ποιες σελίδες εμφανίζεται', 'plandose' ); ?>
			<select name="display_scope">
				<?php foreach ( Plandose_Settings::display_scopes() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $scope, $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<small>
				<?php esc_html_e( 'Εκτός των επιλεγμένων σελίδων δεν φορτώνεται κανένα αρχείο του PlanDose. Περιορίστε το για ταχύτερες σελίδες.', 'plandose' ); ?>
			</small>
		</label>

		<label><?php esc_html_e( 'Επιλεγμένες σελίδες', 'plandose' ); ?>
			<select name="display_pages[]" multiple size="8">
				<?php foreach ( $pages as $page ) : ?>
					<?php
					$page_label = '' !== trim( (string) $page->post_title )
						? $page->post_title
						/* translators: %d: page ID */
						: sprintf( __( '(χωρίς τίτλο) #%d', 'plandose' ), (int) $page->ID );

					if ( 'publish' !== $page->post_status ) {
						$status_object = get_post_status_object( $page->post_status );
						$page_label   .= ' — ' . ( $status_object ? $status_object->label : $page->post_status );
					}
					?>
					<option value="<?php echo (int) $page->ID; ?>" <?php echo in_array( (int) $page->ID, $selected, true ) ? 'selected' : ''; ?>>
						<?php echo esc_html( $page_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<small>
				<?php esc_html_e( 'Ισχύει μόνο όταν έχει επιλεγεί «Μόνο σε επιλεγμένες σελίδες». Επιλέξτε τουλάχιστον μία· χωρίς επιλογή η αποθήκευση απορρίπτεται.', 'plandose' ); ?>
			</small>
		</label>

		<?php
		/*
		 * The <small> above states the rule; this states that the rule is
		 * currently biting. Passive help text under a field nobody reads is
		 * not the same as telling someone their site is doing the opposite
		 * of what the dropdown next to it says.
		 */
		if ( 'selected' === $scope && empty( $selected ) ) :
			?>
			<p class="pd-scope-warning">
				<?php esc_html_e( '⚠ Δεν έχει επιλεγεί καμία σελίδα, οπότε αυτή τη στιγμή το PlanDose δεν εμφανίζεται πουθενά στο site.', 'plandose' ); ?>
			</p>
			<?php
		endif;
	}

	private static function toggle( $name, $label, $value, $disabled = false ) {
		printf( '<label class="pd-toggle"><span>%s</span><input type="checkbox" name="%s" value="1" %s%s><em></em></label>', esc_html( $label ), esc_attr( $name ), checked( $value, 1, false ), $disabled ? ' disabled="disabled"' : '' );
	}

	private static function text( $name, $label, $value, $help = '' ) {
		printf( '<label>%s<input type="text" name="%s" value="%s">%s</label>', esc_html( $label ), esc_attr( $name ), esc_attr( $value ), $help ? '<small>' . esc_html( $help ) . '</small>' : '' );
	}

	private static function number( $name, $label, $value, $help = '', $disabled = false ) {
		$limits = array(
			'free_monthly_limit' => array( 1, Plandose_Settings::LIMIT_FREE_MONTHLY_MAX ),
			'max_plan_days'      => array( 1, Plandose_Settings::LIMIT_MAX_PLAN_DAYS ),
			'default_pro_days'   => array( 1, Plandose_Settings::LIMIT_PRO_DAYS_MAX ),
			'max_add_days'       => array( 1, Plandose_Settings::LIMIT_ADD_DAYS_MAX ),
			'expiring_days'      => array( 1, 365 ),
			'pro_print_limit'    => array( 0, Plandose_Settings::LIMIT_PRO_PRINT_MAX ),
			'invoice_max_mb'     => array( 1, Plandose_Settings::LIMIT_INVOICE_MB_MAX ),
			'audit_retention_days' => array( 0, Plandose_Settings::LIMIT_AUDIT_RETENTION_MAX ),
		);

		$min = isset( $limits[ $name ] ) ? $limits[ $name ][0] : 0;
		$max = isset( $limits[ $name ] ) ? $limits[ $name ][1] : 2147483647;

		printf(
			'<label>%s<input type="number" name="%s" value="%s" min="%d" max="%d" step="1" inputmode="numeric"%s>%s</label>',
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $value ),
			(int) $min,
			(int) $max,
			$disabled ? ' disabled="disabled"' : '',
			$help ? '<small>' . esc_html( $help ) . '</small>' : ''
		);
	}

	private static function textarea( $name, $label, $value ) {
		printf( '<label>%s<textarea name="%s" rows="3">%s</textarea></label>', esc_html( $label ), esc_attr( $name ), esc_textarea( $value ) );
	}

	/**
	 * Renders a group of checkbox "pills" for a name[] field. Replaces the
	 * native <select multiple>, which is hard to use (needs Ctrl/Cmd-click,
	 * gives no visual feedback of what's selected) with a clear tap/click list.
	 */
	private static function checkbox_group( $name, $choices, $selected ) {
		$selected = (array) $selected;
		echo '<div class="pd-checkbox-group">';
		foreach ( $choices as $value => $label ) {
			printf(
				'<label class="pd-checkbox-pill"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s><span>%4$s</span></label>',
				esc_attr( $name ),
				esc_attr( $value ),
				checked( in_array( $value, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</div>';
	}
}