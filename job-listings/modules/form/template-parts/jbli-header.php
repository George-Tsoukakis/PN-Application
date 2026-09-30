<?php
/**
 * Form partial: Header
 *
 * Renders the form heading and subtitle.
 * Expects: $jbli_is_edit (bool), $jbli_is_expired (bool).
 *
 * @package JobListings
 * @since   9.9.23
 */

defined( 'ABSPATH' ) || exit;
?>
<?php 
	if ( $jbli_is_edit && $jbli_is_expired ) 
	{ 
		?>
			<div class="jbli_notice jbli_notice_warning" role="alert">
				<strong><?php esc_html_e( 'Η αγγελία αυτή έχει λήξει.', 'job-listings' ); ?></strong> <?php esc_html_e( 'Μπορείτε να αποθηκεύσετε αλλαγές αλλά παραμένει ανενεργή μέχρι ανανέωση.', 'job-listings' ); ?>
			</div>
		<?php
	} 
?>

<div class="jbli_form_header">

	<h2 class="jbli_form_header_title"><?php echo esc_html( $jbli_is_edit ? __( 'Επεξεργασία Αγγελίας', 'job-listings' ) : __( 'Νέα Αγγελία Εργασίας', 'job-listings' ) ); ?></h2>

	<p class="jbli_form_header_sub"><?php if ( $jbli_is_edit ) { esc_html_e( 'Αλλάξτε τα βασικά στοιχεία και αποθηκεύστε.', 'job-listings' ); } else { echo wp_kses_post( __( 'Η αγγελία θα παραμείνει ενεργή για <strong>30 ημέρες</strong>.', 'job-listings' ) ); } ?></p>

</div>
