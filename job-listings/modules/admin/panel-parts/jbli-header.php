<?php
/**
 * Admin panel partial: Header
 *
 * Page title and action-result notice.
 * Expects: nothing (reads $_GET['job_done'] internally).
 *
 * @package JobListings
 * @since   9.9.25
 */

defined( 'ABSPATH' ) || exit;

$jbli_done_msgs = array(
	'approved'         => __( 'Η αγγελία εγκρίθηκε και δημοσιεύτηκε.', 'job-listings' ),
	'approved_renewed' => __( 'Η αγγελία εγκρίθηκε και ανανεώθηκε για 30 ημέρες.', 'job-listings' ),
	'deactivated'      => __( 'Η αγγελία απενεργοποιήθηκε.', 'job-listings' ),
	'renewed'          => __( 'Η αγγελία ανανεώθηκε για 30 ημέρες.', 'job-listings' ),
	'deleted'          => __( 'Η αγγελία μεταφέρθηκε στον κάδο.', 'job-listings' ),
	'backfilled'       => __( 'Συγχρονισμός ολοκληρώθηκε επιτυχώς.', 'job-listings' ),
	'settings_saved'   => __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'job-listings' ),
);

$jbli_done = sanitize_key( wp_unslash( $_GET['job_done'] ?? '' ) );
?>

<h1 class="jbli_ap_heading">
	<?php esc_html_e( 'Job Listings — Διαχείριση αγγελιών', 'job-listings' ); ?>
</h1>
<?php
	if ( isset( $jbli_done_msgs[ $jbli_done ] ) )
	{

		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( $jbli_done_msgs[ $jbli_done ] ); ?></p>
		</div>
		<?php
	}
?>
