<?php
/**
 * Admin panel partial: Επιλογές Πεδίων Αγγελίας + Email Εκδήλωσης
 *
 * Πεδία:
 *   Κατηγορία      → WordPress taxonomy (link)
 *   Νομός           → WordPress taxonomy (link)
 *   Τηλέφωνο        → free-text (info only)
 *   Αμοιβή          → editable options list  (jbli_salary_options)
 *   Τύπος Απασχόλησης → editable options list (jbli_type_options)
 *
 * @package JobListings
 * @since   9.9.39
 */

defined( 'ABSPATH' ) || exit;

$jbli_form_url 		= esc_url( admin_url( 'admin.php?page=jbli_settings' ) );
$jbli_nonce    		= wp_create_nonce( 'jbli_fields_save' );

$jbli_saved        	= sanitize_key( wp_unslash( $_GET['jbli_saved'] ?? '' ) );
$jbli_saved_labels 	= array(
	'apply_email'   => __( 'Ρυθμίσεις email αποθηκεύτηκαν.', 'job-listings' ),
	'field_options' => __( 'Επιλογές πεδίων αποθηκεύτηκαν.', 'job-listings' ),
	'google_map'    => __( 'Οι ρυθμίσεις του Google Map αποθηκεύτηκαν.', 'job-listings' ),
);

$jbli_salary_raw 	= (string) get_option( 'jbli_salary_options', '' );
$jbli_salary_dec 	= $jbli_salary_raw ? json_decode( $jbli_salary_raw, true ) : null;
$jbli_salary     	= ( is_array( $jbli_salary_dec ) && ! empty( $jbli_salary_dec ) ) ? $jbli_salary_dec : ( function_exists( 'jbli_salary_options_default' ) ? jbli_salary_options_default() : array() );

$jbli_type_raw 		= (string) get_option( 'jbli_type_options', '' );
$jbli_type_dec 		= $jbli_type_raw ? json_decode( $jbli_type_raw, true ) : null;
$jbli_type     		= ( is_array( $jbli_type_dec ) && ! empty( $jbli_type_dec ) ) ? $jbli_type_dec : ( function_exists( 'jbli_type_options_default' ) ? jbli_type_options_default() : array() );

$jbli_subj     		= (string) get_option( 'jbli_apply_email_subject', '' );
$jbli_body     		= (string) get_option( 'jbli_apply_email_body',    '' );
$jbli_def_subj 		= function_exists( 'jbli_apply_default_subject' ) ? jbli_apply_default_subject() : '';
$jbli_def_body 		= function_exists( 'jbli_apply_default_body' )    ? jbli_apply_default_body()    : '';

/* 9.9.51: a saved pre-9.9.51 default is shown (and sent) as the new default. */
if ( function_exists( 'jbli_apply_legacy_defaults' ) )
{
	$jbli_legacy = jbli_apply_legacy_defaults();

	if ( trim( $jbli_subj ) === $jbli_legacy['subject'] ) { $jbli_subj = ''; }
	if ( str_replace( "\r\n", "\n", trim( $jbli_body ) ) === $jbli_legacy['body'] ) { $jbli_body = ''; }
}

$jbli_google_map_api_key = (string) get_option( 'jbli_google_map_api_key', '' );
$jbli_form_media_url     = (string) get_option( 'jbli_form_media_url', '' );
$jbli_single_media_url   = (string) get_option( 'jbli_single_media_url', '' );
$jbli_title_max_chars    = function_exists( 'jbli_title_max_chars' ) ? jbli_title_max_chars() : (int) get_option( 'jbli_title_max_chars', 15 );
?>
<?php /* The "saved" notice is printed once by jbli-settings-template.php. */ ?>

<?php  ?>

<div class="jbli_ap_tools">

	<h2 class="jbli_ap_tools_heading">
		<?php esc_html_e( 'Επιλογές Πεδίων Αγγελίας', 'job-listings' ); ?>
	</h2>

	<p style="margin:0 0 20px;font-size:13px;color:#6b7280;line-height:1.6;">
		<?php esc_html_e( 'Διαχειριστείτε τις διαθέσιμες επιλογές για κάθε πεδίο της φόρμας καταχώρησης αγγελίας.', 'job-listings' ); ?>
	</p>

	<?php  ?>
	<table class="widefat fixed striped" style="margin-bottom:28px;border-radius:6px;overflow:hidden;">
		<thead>
			<tr>
				<th style="width:200px;"><?php esc_html_e( 'Πεδίο', 'job-listings' ); ?></th>
				<th><?php esc_html_e( 'Τύπος', 'job-listings' ); ?></th>
				<th><?php esc_html_e( 'Διαχείριση', 'job-listings' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><strong><?php esc_html_e( 'Κατηγορία', 'job-listings' ); ?></strong></td>
				<td><span style="color:#6b7280;font-size:12px;"><?php esc_html_e( 'WordPress Taxonomy', 'job-listings' ); ?></span></td>
				<td>
					<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=job_category&post_type=' . JBLI_CPT ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Διαχείριση Κατηγοριών →', 'job-listings' ); ?>
					</a>
				</td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Νομός', 'job-listings' ); ?></strong></td>
				<td><span style="color:#6b7280;font-size:12px;"><?php esc_html_e( 'WordPress Taxonomy', 'job-listings' ); ?></span></td>
				<td>
					<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=job_nomos&post_type=' . JBLI_CPT ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Διαχείριση Νομών →', 'job-listings' ); ?>
					</a>
				</td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Τηλέφωνο', 'job-listings' ); ?></strong></td>
				<td><span style="color:#6b7280;font-size:12px;"><?php esc_html_e( 'Ελεύθερο κείμενο', 'job-listings' ); ?></span></td>
				<td><span style="color:#9ca3af;font-size:12px;"><?php esc_html_e( 'Συμπληρώνεται από τον χρήστη', 'job-listings' ); ?></span></td>
			</tr>
		</tbody>
	</table>

	<?php  ?>
	<form method="post" action="<?php echo esc_url( $jbli_form_url ); ?>">
		<input type="hidden" name="jbli_fields_action" value="save_field_options">
		<?php wp_nonce_field( 'jbli_fields_save', 'jbli_fields_nonce' ); ?>

		<div class="jbli_ap_tools_row">

			<?php  ?>
			<div>
				<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
					<strong style="font-size:13px;color:#374151;"><?php esc_html_e( 'Αμοιβή', 'job-listings' ); ?></strong>
					<button type="button" onclick="jbli_jlAddRow('jbli_salary_rows','jbli_salary')" class="button button-small">
						+ <?php esc_html_e( 'Νέα επιλογή', 'job-listings' ); ?>
					</button>
				</div>
				<div id="jbli_salary_rows" class="jbli_opts_table">
					<div class="jbli_opts_header">
						<span><?php esc_html_e( 'Slug (μοναδικό)', 'job-listings' ); ?></span>
						<span><?php esc_html_e( 'Ετικέτα (εμφανίζεται)', 'job-listings' ); ?></span>
						<span></span>
					</div>
				<?php

					foreach ( $jbli_salary as $jbli_k => $jbli_v )
					{

						?>
						<div class="jbli_opts_row">
							<input type="text" name="jbli_salary_keys[]"   value="<?php echo esc_attr( (string) $jbli_k ); ?>" placeholder="π.χ. 700-900"       class="jbli_opts_input jbli_opts_input_slug">
							<input type="text" name="jbli_salary_labels[]" value="<?php echo esc_attr( (string) $jbli_v ); ?>" placeholder="π.χ. 700€ – 900€"   class="jbli_opts_input">
							<button type="button" class="jbli_opts_del" onclick="this.closest('.jbli_opts_row').remove()" title="<?php esc_attr_e( 'Διαγραφή', 'job-listings' ); ?>">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
							</button>
						</div>
						<?php
					}
				?>
				</div>
			</div>

			<?php  ?>
			<div>
				<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
					<strong style="font-size:13px;color:#374151;"><?php esc_html_e( 'Τύπος Απασχόλησης', 'job-listings' ); ?></strong>
					<button type="button" onclick="jbli_jlAddRow('jbli_type_rows','jbli_type')" class="button button-small">
						+ <?php esc_html_e( 'Νέα επιλογή', 'job-listings' ); ?>
					</button>
				</div>
				<div id="jbli_type_rows" class="jbli_opts_table">
					<div class="jbli_opts_header">
						<span><?php esc_html_e( 'Slug (μοναδικό)', 'job-listings' ); ?></span>
						<span><?php esc_html_e( 'Ετικέτα (εμφανίζεται)', 'job-listings' ); ?></span>
						<span></span>
					</div>
				<?php

					foreach ( $jbli_type as $jbli_k => $jbli_v )
					{

						?>
						<div class="jbli_opts_row">
							<input type="text" name="jbli_type_keys[]"   value="<?php echo esc_attr( (string) $jbli_k ); ?>" placeholder="π.χ. plires-apasxolisi" class="jbli_opts_input jbli_opts_input_slug">
							<input type="text" name="jbli_type_labels[]" value="<?php echo esc_attr( (string) $jbli_v ); ?>" placeholder="π.χ. Πλήρης Απασχόληση" class="jbli_opts_input">
							<button type="button" class="jbli_opts_del" onclick="this.closest('.jbli_opts_row').remove()" title="<?php esc_attr_e( 'Διαγραφή', 'job-listings' ); ?>">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
							</button>
						</div>
						<?php
					}
				?>
				</div>
			</div>

		</div>

		<p style="margin:6px 0 0;font-size:11px;color:#9ca3af;">
			<?php esc_html_e( 'Σημείωση: το Slug χρησιμοποιείται εσωτερικά και δεν αλλάζει μετά από αποθήκευση χωρίς να ελεγχθεί αν υπάρχουν αγγελίες με την παλιά τιμή.', 'job-listings' ); ?>
		</p>

		<p style="margin:16px 0 0;">
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Αποθήκευση επιλογών', 'job-listings' ); ?>
			</button>
		</p>

	</form>

</div>

<?php  ?>

<div class="jbli_ap_tools">

	<h2 class="jbli_ap_tools_heading">
		<?php esc_html_e( 'Google Map', 'job-listings' ); ?>
	</h2>

	<p style="margin:0 0 16px;font-size:13px;color:#6b7280;line-height:1.6;">
		<?php esc_html_e( 'Ρυθμίσεις για το Google Map.', 'job-listings' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( $jbli_form_url ); ?>">
		<input type="hidden" name="jbli_fields_action" value="save_google_map">
		<?php wp_nonce_field( 'jbli_fields_save', 'jbli_fields_nonce' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="jbli_google_map_api_key"><?php esc_html_e( 'API Key', 'job-listings' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="jbli_google_map_api_key"
						name="jbli_google_map_api_key"
						value="<?php echo esc_attr( $jbli_google_map_api_key ); ?>"
						class="large-text"
					>
				</td>
			</tr>
		</table>

		<p>
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Αποθήκευση Google Map', 'job-listings' ); ?>
			</button>
		</p>

	</form>

</div>

<?php /* 9.9.54: approval mode for listings imported from URLs. */ ?>

<?php $jbli_import_status = function_exists( 'jbli_import_status' ) ? jbli_import_status() : 'publish'; ?>

<div class="jbli_ap_tools" id="jbli_import_settings">

	<h2 class="jbli_ap_tools_heading">
		<?php esc_html_e( 'Εισαγωγή από URL', 'job-listings' ); ?>
	</h2>

	<p style="margin:0 0 16px;font-size:13px;color:#6b7280;line-height:1.6;">
		<?php esc_html_e( 'Όταν εισάγεται μια αγγελία από URL, να δημοσιεύεται αμέσως ή να περιμένει έλεγχο;', 'job-listings' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( $jbli_form_url ); ?>">
		<input type="hidden" name="jbli_fields_action" value="save_import_status">
		<?php wp_nonce_field( 'jbli_fields_save', 'jbli_fields_nonce' ); ?>

		<fieldset style="margin:0 0 12px;">
			<label style="display:block;margin:0 0 8px;">
				<input type="radio" name="jbli_import_status" value="publish" <?php checked( 'publish', $jbli_import_status ); ?>>
				<strong><?php esc_html_e( 'Άμεση έγκριση', 'job-listings' ); ?></strong>
				— <?php esc_html_e( 'η αγγελία δημοσιεύεται αμέσως (προεπιλογή).', 'job-listings' ); ?>
			</label>
			<label style="display:block;">
				<input type="radio" name="jbli_import_status" value="pending" <?php checked( 'pending', $jbli_import_status ); ?>>
				<strong><?php esc_html_e( 'Σε αναμονή για έλεγχο', 'job-listings' ); ?></strong>
				— <?php esc_html_e( 'η αγγελία μπαίνει «Σε αναμονή» και την εγκρίνετε από τη Διαχείριση Αγγελιών.', 'job-listings' ); ?>
			</label>
		</fieldset>

		<p>
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Αποθήκευση', 'job-listings' ); ?>
			</button>
		</p>
	</form>

</div>

<?php /* 9.9.48: title length limit. */ ?>

<div class="jbli_ap_tools">

	<h2 class="jbli_ap_tools_heading">
		<?php esc_html_e( 'Τίτλος αγγελίας', 'job-listings' ); ?>
	</h2>

	<p style="margin:0 0 16px;font-size:13px;color:#6b7280;line-height:1.6;">
		<?php esc_html_e( 'Μέγιστος αριθμός χαρακτήρων (μαζί με τα κενά) για τον τίτλο της αγγελίας. Π.χ. «Φαρμακοποιός» = 12, «Βοηθός Φαρμακείου» = 17. Οι υπάρχουσες αγγελίες δεν αλλάζουν.', 'job-listings' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( $jbli_form_url ); ?>">
		<input type="hidden" name="jbli_fields_action" value="save_title_limit">
		<?php wp_nonce_field( 'jbli_fields_save', 'jbli_fields_nonce' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="jbli_title_max_chars"><?php esc_html_e( 'Μέγιστοι χαρακτήρες', 'job-listings' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						id="jbli_title_max_chars"
						name="jbli_title_max_chars"
						value="<?php echo esc_attr( (string) $jbli_title_max_chars ); ?>"
						min="5"
						max="120"
						step="1"
						class="small-text"
					>
					<span style="margin-left:8px;color:#6b7280;"><?php esc_html_e( '(5 – 120)', 'job-listings' ); ?></span>
				</td>
			</tr>
		</table>

		<p>
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Αποθήκευση ορίου τίτλου', 'job-listings' ); ?>
			</button>
		</p>

	</form>

</div>

<?php /* 9.9.45: media shown next to the new-listing form. */ ?>

<div class="jbli_ap_tools">

	<h2 class="jbli_ap_tools_heading">
		<?php esc_html_e( 'Εικόνες / Video (φόρμα & σελίδα αγγελίας)', 'job-listings' ); ?>
	</h2>

	<p style="margin:0 0 16px;font-size:13px;color:#6b7280;line-height:1.6;">
		<?php esc_html_e( 'Εμφανίζονται δεξιά από τη φόρμα νέας αγγελίας και δεξιά στη σελίδα κάθε αγγελίας. Δεκτά: εικόνα (JPG, PNG, WebP), video MP4/WebM, ή σύνδεσμος YouTube / Vimeo. Αν μείνουν κενά, εμφανίζεται μια έτοιμη πράσινη εικονογράφηση.', 'job-listings' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( $jbli_form_url ); ?>">
		<input type="hidden" name="jbli_fields_action" value="save_form_media">
		<?php wp_nonce_field( 'jbli_fields_save', 'jbli_fields_nonce' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="jbli_form_media_url"><?php esc_html_e( 'Φόρμα νέας αγγελίας', 'job-listings' ); ?></label>
				</th>
				<td>
					<input
						type="url"
						id="jbli_form_media_url"
						name="jbli_form_media_url"
						value="<?php echo esc_attr( $jbli_form_media_url ); ?>"
						class="large-text"
						placeholder="https://"
					>
					<p style="margin-top:8px;">
						<button type="button" class="button jbli_media_pick" data-target="jbli_form_media_url"><?php esc_html_e( 'Επιλογή από τα Πολυμέσα', 'job-listings' ); ?></button>
						<button type="button" class="button-link jbli_media_clear" data-target="jbli_form_media_url" style="margin-left:8px;"><?php esc_html_e( 'Καθαρισμός', 'job-listings' ); ?></button>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="jbli_single_media_url"><?php esc_html_e( 'Σελίδα αγγελίας', 'job-listings' ); ?></label>
				</th>
				<td>
					<input
						type="url"
						id="jbli_single_media_url"
						name="jbli_single_media_url"
						value="<?php echo esc_attr( $jbli_single_media_url ); ?>"
						class="large-text"
						placeholder="<?php esc_attr_e( 'Κενό = ίδια με της φόρμας', 'job-listings' ); ?>"
					>
					<p style="margin-top:8px;">
						<button type="button" class="button jbli_media_pick" data-target="jbli_single_media_url"><?php esc_html_e( 'Επιλογή από τα Πολυμέσα', 'job-listings' ); ?></button>
						<button type="button" class="button-link jbli_media_clear" data-target="jbli_single_media_url" style="margin-left:8px;"><?php esc_html_e( 'Καθαρισμός', 'job-listings' ); ?></button>
					</p>
				</td>
			</tr>
		</table>

		<p>
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Αποθήκευση εικόνας / video', 'job-listings' ); ?>
			</button>
		</p>

	</form>

	<script>
		( function () {
			document.querySelectorAll( '.jbli_media_clear' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () { document.getElementById( btn.getAttribute( 'data-target' ) ).value = ''; } );
			} );

			var picks = document.querySelectorAll( '.jbli_media_pick' );

			if ( ! window.wp || ! wp.media ) { picks.forEach( function ( b ) { b.style.display = 'none'; } ); return; }

			picks.forEach( function ( btn ) {
				var frame;
				btn.addEventListener( 'click', function () {
					var input = document.getElementById( btn.getAttribute( 'data-target' ) );
					if ( ! frame ) {
						frame = wp.media( {
							title: <?php echo wp_json_encode( __( 'Εικόνα ή video', 'job-listings' ) ); ?>,
							library: { type: [ 'image', 'video' ] },
							multiple: false
						} );
						frame.on( 'select', function () {
							var file = frame.state().get( 'selection' ).first().toJSON();
							input.value = file.url || '';
						} );
					}
					frame.open();
				} );
			} );
		}() );
	</script>

</div>

<?php  ?>

<div class="jbli_ap_tools">

	<h2 class="jbli_ap_tools_heading">
		<?php esc_html_e( 'Email Εκδήλωσης Ενδιαφέροντος', 'job-listings' ); ?>
	</h2>

	<p style="margin:0 0 16px;font-size:13px;color:#6b7280;line-height:1.6;">
		<?php esc_html_e( 'Το email που λαμβάνει το φαρμακείο όταν κάποιος πατήσει «Εκδήλωση Ενδιαφέροντος». Εδώ ορίζετε το θέμα και το εισαγωγικό κείμενο· τα στοιχεία του υποψηφίου (με κουμπιά κλήσης/απάντησης) και της αγγελίας προστίθενται αυτόματα από κάτω. Το «Απάντηση» στο email πηγαίνει απευθείας στον υποψήφιο.', 'job-listings' ); ?>
		<?php esc_html_e( 'Διαθέσιμα placeholders:', 'job-listings' ); ?>
		<code>{name}</code> <code>{phone}</code> <code>{email}</code> <code>{position}</code> <code>{pharmacy}</code> <code>{listing_url}</code>
	</p>

	<form method="post" action="<?php echo esc_url( $jbli_form_url ); ?>">
		<input type="hidden" name="jbli_fields_action" value="save_apply_email">
		<?php wp_nonce_field( 'jbli_fields_save', 'jbli_fields_nonce' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="jbli_apply_subject"><?php esc_html_e( 'Θέμα email', 'job-listings' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="jbli_apply_subject"
						name="jbli_apply_email_subject"
						value="<?php echo esc_attr( $jbli_subj ?: $jbli_def_subj ); ?>"
						class="large-text"
					>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="jbli_apply_body"><?php esc_html_e( 'Κείμενο email', 'job-listings' ); ?></label>
				</th>
				<td>
					<textarea
						id="jbli_apply_body"
						name="jbli_apply_email_body"
						rows="8"
						class="large-text code"
					><?php echo esc_textarea( $jbli_body ?: $jbli_def_body ); ?></textarea>
				</td>
			</tr>
		</table>

		<p>
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Αποθήκευση email', 'job-listings' ); ?>
			</button>
		</p>

	</form>

</div>

<?php  ?>
<style>
.jbli_opts_table {
	border: 1px solid #e0e0e0;
	border-radius: 6px;
	overflow: hidden;
	background: #fff;
}

.jbli_opts_header {
	display: grid;
	grid-template-columns: 1fr 1.6fr 28px;
	gap: 0;
	background: #f9fafb;
	border-bottom: 1px solid #e0e0e0;
	padding: 6px 10px;
}

.jbli_opts_header span {
	font-size: 11px;
	font-weight: 600;
	color: #6b7280;
	text-transform: uppercase;
	letter-spacing: .04em;
}

.jbli_opts_row {
	display: grid;
	grid-template-columns: 1fr 1.6fr 28px;
	gap: 0;
	border-bottom: 1px solid #f3f4f6;
	align-items: center;
}

.jbli_opts_row:last-child { border-bottom: none; }
.jbli_opts_row:hover { background: #fafafa; }
.jbli_opts_input {
	padding: 7px 10px;
	border: none;
	border-right: 1px solid #f3f4f6;
	font-size: 13px;
	color: #111;
	background: transparent;
	width: 100%;
	box-sizing: border-box;
}

.jbli_opts_input_slug {
	font-family: monospace;
	font-size: 12px;
	color: #4b5563;
}

.jbli_opts_input:focus {
	outline: none;
	background: #f0fdf9;
}

.jbli_opts_del {
	display: flex;
	align-items: center;
	justify-content: center;
	border: none;
	background: none;
	color: #d1d5db;
	cursor: pointer;
	padding: 0;
	height: 100%;
	min-height: 34px;
	transition: color .15s;
}

.jbli_opts_del:hover { color: #ef4444; }
</style>

<script>
function jbli_jlAddRow(containerId, prefix) {

	var container = document.getElementById(containerId);

	if ( !container ) return;
	var row = document.createElement('div');

	row.className = 'jbli_opts_row';
	row.innerHTML =
		'<input type="text" name="' + prefix + '_keys[]"   placeholder="new-slug"   class="jbli_opts_input jbli_opts_input_slug">' +
		'<input type="text" name="' + prefix + '_labels[]" placeholder="Ετικέτα"    class="jbli_opts_input">' +
		'<button type="button" class="jbli_opts_del" onclick="this.closest(\'.jbli_opts_row\').remove()" title="Διαγραφή">' +
			'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
		'</button>';
	container.appendChild(row);
	row.querySelector('input').focus();

}

</script>
