<?php
/**
 * 2.15.6: έλεγχος για νεότερη έκδοση των ενσωματωμένων βιβλιοθηκών DataMatrix.
 *
 * Μόνο ενημέρωση, ποτέ εγκατάσταση: οι βιβλιοθήκες μπαίνουν στο plugin με
 * ιδιωτικό πρόθεμα namespace και ελέγχονται πριν από κάθε αναβάθμιση (βλ.
 * vendor/QRRP-VENDOR-NOTES.md). Αυτόματη αντικατάσταση κώδικα σε site
 * παραγωγής θα παρέκαμπτε αυτούς τους ελέγχους.
 *
 * Η μόνη εξερχόμενη σύνδεση του plugin, και μόνο όταν ο διαχειριστής πατήσει
 * το κουμπί: HTTPS GET στο δημόσιο αποθετήριο της PHP (repo.packagist.org),
 * σε σταθερή διεύθυνση. Δεν στέλνεται τίποτα από το site (ούτε το URL του στο
 * User-Agent). Το αποτέλεσμα κρατιέται σε ένα non-autoload option.
 *
 * @package QR_ReBuilder_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QRRP_Vendor_Check {

	public const ACTION = 'qrrp_check_vendor_updates';

	public const OPTION = 'qrrp_vendor_check';

	/**
	 * Οι εκδόσεις που είναι μέσα στο vendor/. Ενημερώνεται μαζί με κάθε
	 * αναβάθμιση βιβλιοθήκης (το test t_vendor_check τη συγκρίνει με το
	 * QRRP-VENDOR-NOTES.md).
	 */
	public const INSTALLED = array(
		'tecnickcom/tc-lib-barcode' => '2.16.4',
		'tecnickcom/tc-lib-color'   => '3.0.7',
	);

	private const REPO_URL = 'https://repo.packagist.org/p2/%s.json';

	/* 2.15.7: 10 → 5 s· δύο πακέτα διαδοχικά, ώστε ένα κολλημένο δίκτυο να μη φτάνει το max_execution_time. */
	private const TIMEOUT = 5;

	/* Το JSON του Packagist για αυτά τα πακέτα είναι μερικές εκατοντάδες KB. */
	private const MAX_BYTES = 4194304;

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_check' ) );
	}

	/**
	 * admin-post: μόνο POST, μόνο διαχειριστής, με nonce. Κάνει τον έλεγχο,
	 * αποθηκεύει το αποτέλεσμα και επιστρέφει στη σελίδα ρυθμίσεων.
	 */
	public static function handle_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.', 'qr-rebuilder-pro' ), '', array( 'response' => 403 ) );
		}

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'Μη έγκυρο αίτημα.', 'qr-rebuilder-pro' ), '', array( 'response' => 405 ) );
		}

		check_admin_referer( self::ACTION );

		self::save( self::check_all() );

		wp_safe_redirect( admin_url( 'admin.php?page=' . QRRP_Admin::PAGE_SLUG ) . '#qrrp-vendor-title' );
		exit;
	}

	/** @return array{checked_at:int, packages:array<string,array>} */
	public static function check_all() {
		$packages = array();

		foreach ( self::INSTALLED as $package => $installed ) {
			$packages[ $package ] = self::check_package( $package, $installed );
		}

		return array(
			'checked_at' => time(),
			'packages'   => $packages,
		);
	}

	/**
	 * @param string $package   Όνομα πακέτου Packagist (ένα από τα INSTALLED).
	 * @param string $installed Εγκατεστημένη έκδοση.
	 * @return array{status:string, installed:string, latest:string, released:string, error:string}
	 */
	public static function check_package( $package, $installed ) {
		$result = array(
			'status'    => 'error',
			'installed' => $installed,
			'latest'    => '',
			'released'  => '',
			'error'     => '',
		);

		$response = wp_safe_remote_get(
			sprintf( self::REPO_URL, $package ),
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 2,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'QR-ReBuilder-Pro/' . QRRP_VERSION,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['error'] = 'network';

			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$result['error'] = 'http_' . $code;

			return $result;
		}

		$latest = self::latest_stable( json_decode( (string) wp_remote_retrieve_body( $response ), true ), $package );

		if ( null === $latest ) {
			$result['error'] = 'format';

			return $result;
		}

		$result['latest']   = $latest['version'];
		$result['released'] = $latest['time'];

		if ( version_compare( $latest['version'], $installed, '>' ) ) {
			$result['status'] = ( (int) $latest['version'] > (int) $installed ) ? 'major' : 'update';
		} else {
			$result['status'] = 'current';
		}

		return $result;
	}

	/**
	 * Η νεότερη σταθερή έκδοση (μόνο X.Y.Z, χωρίς dev/beta/RC) από το JSON
	 * του Packagist (μορφή p2). Δεν εμπιστεύεται τη σειρά: συγκρίνει όλες.
	 *
	 * @param mixed  $data    Αποκωδικοποιημένο JSON.
	 * @param string $package Όνομα πακέτου.
	 * @return array{version:string, time:string}|null
	 */
	public static function latest_stable( $data, $package ) {
		if ( ! is_array( $data ) || ! isset( $data['packages'][ $package ] ) || ! is_array( $data['packages'][ $package ] ) ) {
			return null;
		}

		$best = null;

		foreach ( $data['packages'][ $package ] as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['version'] ) || ! is_string( $entry['version'] ) ) {
				continue;
			}

			$version = ltrim( $entry['version'], 'vV' );

			if ( 1 !== preg_match( '/\A\d{1,4}\.\d{1,4}\.\d{1,6}\z/', $version ) ) {
				continue;
			}

			if ( null === $best || version_compare( $version, $best['version'], '>' ) ) {
				$time = ( isset( $entry['time'] ) && is_string( $entry['time'] ) && 1 === preg_match( '/\A\d{4}-\d{2}-\d{2}/', $entry['time'] ) )
					? substr( $entry['time'], 0, 10 )
					: '';

				$best = array(
					'version' => $version,
					'time'    => $time,
				);
			}
		}

		return $best;
	}

	private static function save( array $result ) {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $result, '', false );
		} else {
			update_option( self::OPTION, $result, false );
		}
	}

	/** Το τελευταίο αποθηκευμένο αποτέλεσμα, ή null. */
	public static function last_result() {
		$stored = get_option( self::OPTION, null );

		return ( is_array( $stored ) && isset( $stored['checked_at'], $stored['packages'] ) && is_array( $stored['packages'] ) ) ? $stored : null;
	}

	/** Κάρτα στη σελίδα ρυθμίσεων. */
	public static function render_card() {
		$last = self::last_result();
		?>
		<section class="qrrp-admin-card qrrp-admin-vendor" aria-labelledby="qrrp-vendor-title">
			<h2 id="qrrp-vendor-title"><?php esc_html_e( 'Βιβλιοθήκη DataMatrix', 'qr-rebuilder-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Ο κωδικός DataMatrix σχεδιάζεται από τις βιβλιοθήκες tc-lib-barcode και tc-lib-color (Tecnick.com), ενσωματωμένες στο plugin. Ο έλεγχος δείχνει μόνο αν υπάρχει νεότερη έκδοση· δεν εγκαθιστά τίποτα.', 'qr-rebuilder-pro' ); ?>
			</p>

			<table class="widefat striped qrrp-vendor-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Βιβλιοθήκη', 'qr-rebuilder-pro' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Εγκατεστημένη', 'qr-rebuilder-pro' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Νεότερη διαθέσιμη', 'qr-rebuilder-pro' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Κατάσταση', 'qr-rebuilder-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( self::INSTALLED as $package => $installed ) : ?>
						<?php $row = ( null !== $last && isset( $last['packages'][ $package ] ) && is_array( $last['packages'][ $package ] ) ) ? $last['packages'][ $package ] : null; ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( 'https://github.com/' . $package . '/releases' ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( substr( $package, strpos( $package, '/' ) + 1 ) ); ?></a>
							</td>
							<td><?php echo esc_html( $installed ); ?></td>
							<td>
								<?php
								if ( null !== $row && '' !== (string) ( $row['latest'] ?? '' ) ) {
									echo esc_html( (string) $row['latest'] );

									if ( '' !== (string) ( $row['released'] ?? '' ) ) {
										echo ' <span class="description">(' . esc_html( (string) $row['released'] ) . ')</span>';
									}
								} else {
									echo '&mdash;';
								}
								?>
							</td>
							<td><?php self::render_status( $package, $installed, $row ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="qrrp-vendor-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php submit_button( __( 'Έλεγχος για νέα έκδοση', 'qr-rebuilder-pro' ), 'secondary', 'submit', false ); ?>
				<span class="description">
					<?php
					if ( null !== $last ) {
						echo esc_html(
							sprintf(
								/* translators: %s: date and time of the last check. */
								__( 'Τελευταίος έλεγχος: %s', 'qr-rebuilder-pro' ),
								wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['checked_at'] )
							)
						);
					} else {
						esc_html_e( 'Δεν έχει γίνει ακόμη έλεγχος.', 'qr-rebuilder-pro' );
					}
					?>
				</span>
			</form>
			<p class="description">
				<?php esc_html_e( 'Ο έλεγχος συνδέεται στο δημόσιο αποθετήριο πακέτων της PHP (repo.packagist.org) μόνο όταν πατάτε το κουμπί. Αν υπάρχει νεότερη έκδοση, ενημερώστε τον developer: η αναβάθμιση γίνεται με τη διαδικασία του vendor/QRRP-VENDOR-NOTES.md και νέα έκδοση του plugin, ώστε να ελεγχθεί πριν φτάσει στο site.', 'qr-rebuilder-pro' ); ?>
			</p>
		</section>
		<?php
	}

	private static function render_status( $package, $installed, $row ) {
		if ( null === $row ) {
			echo '&mdash;';

			return;
		}

		$status = (string) ( $row['status'] ?? '' );
		$latest = (string) ( $row['latest'] ?? '' );

		if ( 'current' === $status ) {
			echo '<span class="qrrp-vendor-ok">' . esc_html__( 'Ενημερωμένη', 'qr-rebuilder-pro' ) . '</span>';

			return;
		}

		if ( 'update' === $status || 'major' === $status ) {
			echo '<strong class="qrrp-vendor-new">' . esc_html__( 'Υπάρχει νεότερη έκδοση', 'qr-rebuilder-pro' ) . '</strong>';

			if ( 'major' === $status ) {
				echo '<br /><span class="description">' . esc_html__( 'Νέα κύρια έκδοση: πιθανές ασύμβατες αλλαγές, χρειάζεται προσεκτικός έλεγχος.', 'qr-rebuilder-pro' ) . '</span>';
			}

			if ( 1 === preg_match( '/\A\d+\.\d+\.\d+\z/', $latest ) ) {
				echo '<br /><a href="' . esc_url( 'https://github.com/' . $package . '/compare/' . $installed . '...' . $latest ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Τι άλλαξε', 'qr-rebuilder-pro' ) . '</a>';
			}

			return;
		}

		$error = (string) ( $row['error'] ?? '' );

		if ( 'network' === $error ) {
			$message = __( 'Δεν ήταν δυνατή η σύνδεση με το αποθετήριο. Ο server ίσως δεν επιτρέπει εξερχόμενες συνδέσεις.', 'qr-rebuilder-pro' );
		} elseif ( 'format' === $error ) {
			$message = __( 'Το αποθετήριο απάντησε σε μορφή που δεν αναγνωρίστηκε.', 'qr-rebuilder-pro' );
		} else {
			$message = sprintf(
				/* translators: %s: HTTP status code, e.g. 503. */
				__( 'Το αποθετήριο απάντησε με σφάλμα (HTTP %s).', 'qr-rebuilder-pro' ),
				substr( $error, 5 )
			);
		}

		echo '<span class="qrrp-vendor-error">' . esc_html__( 'Ο έλεγχος απέτυχε', 'qr-rebuilder-pro' ) . '</span><br /><span class="description">' . esc_html( $message ) . '</span>';
	}
}

QRRP_Vendor_Check::init();
