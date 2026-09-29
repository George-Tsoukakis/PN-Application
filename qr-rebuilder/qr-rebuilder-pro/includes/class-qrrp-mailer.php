<?php
/**
 * Αποστολή του αναδημιουργημένου GS1 DataMatrix με email.
 *
 * Επανελέγχει το GS1 payload, παράγει την εικόνα server-side, και προαιρετικά
 * προσθέτει σύνδεσμο με opaque email_rebuild token (QRRP_Tokens) για prefill.
 *
 * @package QR_Rebuilder_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QRRP_Mailer {

	/* Το όριο του raw ζει στην qrrp_max_raw_bytes(). */
	private const MAX_FIELD_BYTES       = 128;
	private const MAX_CUSTOMER_CHARS    = 200;
	private const MAX_PRINT_DATE_CHARS  = 32;

	/**
	 * Ταβάνι για το υποβληθέν page_url (de facto όριο URL των browsers). Θέμα
	 * υγιεινής: ο έλεγχος διαδρομής κρίνει ήδη πού δείχνει ο σύνδεσμος.
	 */
	private const MAX_URL_BYTES = 2048;

	/**
	 * Temp αρχεία εικόνας: create_image_file() γράφει
	 * GS1-DataMatrix-<8 hex site>-<12 hex>.png (2.15.7). Το site tag κρατά κάθε
	 * site στα δικά του αρχεία όταν πολλά WordPress μοιράζονται τον ίδιο /tmp.
	 * Αρχεία της παλιάς μορφής (GS1-DataMatrix-<12 hex>[-N].png, ≤ 2.15.6) δεν
	 * ανήκουν αποδεδειγμένα σε αυτό το site: σβήνονται μόνο όταν είναι ήδη
	 * παλαιότερα του TEMP_FILE_MAX_AGE, ακόμη και στο uninstall.
	 */
	private const TEMP_FILE_GLOB           = 'GS1-DataMatrix-*.png';
	private const TEMP_FILE_PATTERN        = '/\AGS1-DataMatrix-([a-f0-9]{8})-[a-f0-9]{12}\.png\z/';
	private const TEMP_FILE_LEGACY_PATTERN = '/\AGS1-DataMatrix-[a-f0-9]{12}(?:-[0-9]+)?\.png\z/';
	private const TEMP_FILE_MAX_AGE = 3600;
	private const TEMP_SWEEP_LIMIT  = 50;

	/**
	 * WP-Cron hook που σβήνει τα temp PNG μιας επιτυχούς αποστολής μετά το
	 * TEMP_FILE_MAX_AGE (2.15.2). Χωρίς args: κανένα όνομα αρχείου στο cron option.
	 */
	public const SWEEP_CRON_HOOK = 'qrrp_sweep_mail_temp_files';

	/**
	 * Handler του SWEEP_CRON_HOOK. Αν μείνουν αρχεία (νεότερα της ώρας ή πάνω από
	 * το όριο διαγραφών), ξαναπρογραμματίζεται, ώστε σε ήσυχο site να μη μείνει
	 * κανένα συνημμένο χωρίς επόμενη αποστολή.
	 */
	public static function run_scheduled_sweep() {
		self::sweep_stale_temp_files();

		if ( self::temp_files_remain() ) {
			self::schedule_temp_sweep();
		}
	}

	/** Για το uninstall: σβήνει όλα τα temp PNG αυτού του site, ανεξαρτήτως ηλικίας (και παλιάς μορφής > 1 ώρα). */
	public static function purge_all_temp_files() {
		self::sweep_stale_temp_files( 0, PHP_INT_MAX );
	}

	/**
	 * Στέλνει το email με το DataMatrix ως συνημμένο.
	 *
	 * Για επισκέπτες (μη συνδεδεμένους) το ελεύθερο κείμενο customer_name δεν
	 * μπαίνει στο email, το print_date γίνεται δεκτό μόνο σε μορφή ημερομηνίας,
	 * και ο παραλήπτης πρέπει να ανήκει στα επιτρεπτά domains (2.15.3, κενή
	 * λίστα = κανένα· φίλτρο qrrp_guest_email_recipient_allowed). Έτσι η φόρμα
	 * δεν γίνεται relay για αυθαίρετο κείμενο ή παραλήπτη.
	 *
	 * $proven_extras: extras (πέραν PC/SN/LOT/EXP) που έχει ήδη εξουσιοδοτήσει
	 * η provenance ή ένα επαληθευμένο validated_output proof. Ο Mailer δεν τα
	 * εξάγει ποτέ μόνος του από το $raw_data· αυτό θα επαλήθευε την είσοδο
	 * απέναντι στον εαυτό της.
	 *
	 * @param string $to_email      Παραλήπτης.
	 * @param array  $fields        PC/SN/LOT/EXP.
	 * @param string $customer_name Όνομα πελάτη (μόνο για συνδεδεμένους).
	 * @param string $print_date    Ημερομηνία εκτύπωσης.
	 * @param string $raw_data      Η αυθεντική συμβολοσειρά GS1.
	 * @param string $tool_page_url Σελίδα εργαλείου για τον σύνδεσμο.
	 * @param array  $proven_extras Εξουσιοδοτημένα extras.
	 * @param array  $provenance    Metadata provenance του server (provenance,
	 *                              changed_fields, changed_fields_unknown), για
	 *                              τη σήμανση χειροκίνητης αλλαγής (2.15.2).
	 * @return true|WP_Error
	 */
	public static function send( $to_email, $fields, $customer_name = '', $print_date = '', $raw_data = '', $tool_page_url = '', $proven_extras = array(), $provenance = array() ) {
		$to_email = sanitize_email( (string) $to_email );

		if ( ! is_email( $to_email ) ) {
			return new WP_Error( 'qrrp_invalid_email', __( 'Μη έγκυρη διεύθυνση email.', 'qr-rebuilder-pro' ) );
		}

		$is_guest = ! is_user_logged_in();

		if ( $is_guest && ! qrrp_guest_email_recipient_allowed( $to_email ) ) {
			return new WP_Error( 'qrrp_recipient_not_allowed', __( 'Η αποστολή σε αυτή τη διεύθυνση δεν επιτρέπεται.', 'qr-rebuilder-pro' ) );
		}

		$fields        = self::sanitize_fields( $fields );
		$customer_name = self::truncate_text( sanitize_text_field( (string) $customer_name ), self::MAX_CUSTOMER_CHARS );
		$print_date    = self::truncate_text( sanitize_text_field( (string) $print_date ), self::MAX_PRINT_DATE_CHARS );

		if ( $is_guest ) {
			$customer_name = '';

			if ( 1 !== preg_match( '/\A[0-9][0-9 .\/-]*\z/', $print_date ) ) {
				$print_date = '';
			}
		}

		/*
		 * Υπερμεγέθες raw απορρίπτεται, δεν κόβεται: κομμένο raw είναι άλλος
		 * κωδικός.
		 */
		if ( ! is_string( $raw_data ) ) {
			$raw_data = '';
		}

		if ( strlen( $raw_data ) > qrrp_max_raw_bytes() ) {
			return new WP_Error(
				'qrrp_raw_too_long',
				__( 'Η συμβολοσειρά GS1 υπερβαίνει το μέγιστο επιτρεπτό μήκος.', 'qr-rebuilder-pro' )
			);
		}

		$proven_extras = is_array( $proven_extras ) ? $proven_extras : array();

		$integrity = self::validate_gs1_integrity( $fields, $raw_data, $proven_extras );
		if ( is_wp_error( $integrity ) ) {
			return $integrity;
		}

		self::sweep_stale_temp_files();

		/*
		 * Η εικόνα παράγεται εδώ από το raw που μόλις επαληθεύτηκε· ο browser
		 * δεν στέλνει ποτέ εικόνα.
		 */

		$decoded = self::generate_image_bytes( $raw_data );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$tmp_file = self::create_image_file( $decoded );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		/*
		 * Από εδώ κάθε έξοδος περνά από το finally, που σβήνει το temp PNG. Εξαίρεση
		 * (2.15.7: μόνο με το φίλτρο qrrp_mail_attachment_deferred): mailer με ουρά
		 * που θα διαβάσει το συνημμένο αργότερα — τότε το σβήνει ο sweep μετά το
		 * TEMP_FILE_MAX_AGE (αρχείο 0600, τυχαίο όνομα).
		 */
		$rebuild_token    = '';
		$handed_to_mailer = false;

		try {
			$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$from_name = sanitize_text_field( (string) get_option( 'qrrp_email_from_name', $site_name ) );
			$from_mail = sanitize_email( (string) get_option( 'qrrp_email_from_address', get_option( 'admin_email' ) ) );

			if ( '' === $from_name ) {
				$from_name = $site_name;
			}

			if ( ! is_email( $from_mail ) ) {
				$from_mail = sanitize_email( (string) get_option( 'admin_email' ) );
			}

			if ( ! is_email( $from_mail ) ) {
				return new WP_Error( 'qrrp_invalid_sender', __( 'Δεν υπάρχει έγκυρη διεύθυνση email αποστολέα.', 'qr-rebuilder-pro' ) );
			}

			$subject = sprintf(
				/* translators: %s: the site name, used as the email subject prefix. */
				__( '[%s] Νέο GS1 DataMatrix', 'qr-rebuilder-pro' ),
				$site_name
			);

			/*
			 * Όνομα συνημμένου χωρίς GS1 αναγνωριστικά. Το 'name' => 'path' το
			 * σέβεται η wp_mail() από το WordPress 6.2· σε παλαιότερες εκδόσεις
			 * φαίνεται το όνομα στον δίσκο (GS1-DataMatrix-<random>.png).
			 */
			$attachment_name = self::supports_named_attachments()
				? 'GS1-DataMatrix.png'
				: basename( $tmp_file );

			$attachments = self::supports_named_attachments()
				? array( $attachment_name => $tmp_file )
				: array( $tmp_file );

			$body = self::build_html(
				$site_name,
				$fields,
				$customer_name,
				$print_date,
				$raw_data,
				$attachment_name,
				self::resolve_tool_page_url( $tool_page_url ),
				$rebuild_token,
				$proven_extras,
				self::provenance_note( is_array( $provenance ) ? $provenance : array() )
			);

			$from_filter = static function () use ( $from_mail ) {
				return $from_mail;
			};

			$name_filter = static function () use ( $from_name ) {
				return $from_name;
			};

			add_filter( 'wp_mail_from', $from_filter );
			add_filter( 'wp_mail_from_name', $name_filter );

			$sent       = false;
			$mail_error = false;

			try {
				$sent = wp_mail(
					$to_email,
					$subject,
					$body,
					array( 'Content-Type: text/html; charset=UTF-8' ),
					$attachments
				);
			} catch ( Throwable $exception ) {
				$mail_error = true;
			} finally {
				remove_filter( 'wp_mail_from', $from_filter );
				remove_filter( 'wp_mail_from_name', $name_filter );
			}

			if ( $mail_error || ! $sent ) {
				/*
				 * Το token δημιουργείται πριν από το wp_mail() για να μπει στο σώμα·
				 * σε αποτυχία αποστολής αποσύρεται.
				 */
				if ( '' !== $rebuild_token ) {
					self::forget_rebuild_token( $rebuild_token );
				}

				/*
				 * Privacy-safe observability: μόνο το είδος αποτυχίας. Ποτέ recipient,
				 * rebuild token, GS1 identifiers, raw payload ή exception message.
				 */
				$mail_failure_event = $mail_error ? 'exception' : 'returned_false';
				do_action( 'qrrp_mail_failed', $mail_failure_event );

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only trace χωρίς recipient/token/GS1 δεδομένα.
					error_log( sprintf( 'QRRP mail: event=%s', $mail_failure_event ) );
				}

				return new WP_Error(
					'qrrp_mail_failed',
					__( 'Η αποστολή email απέτυχε. Ελέγξτε τις ρυθμίσεις SMTP του site.', 'qr-rebuilder-pro' )
				);
			}

			/*
			 * 2.15.7: με σύγχρονο wp_mail() (PHPMailer/SMTP) το συνημμένο έχει ήδη
			 * διαβαστεί, οπότε το PNG (με SN/LOT/EXP) σβήνεται αμέσως στο finally.
			 * Μόνο αν δηλωθεί mailer με ουρά (φίλτρο qrrp_mail_attachment_deferred)
			 * μένει στον δίσκο για τον sweep μετά το TEMP_FILE_MAX_AGE.
			 */
			if ( ! (bool) apply_filters( 'qrrp_mail_attachment_deferred', false ) ) {
				return true;
			}

			$handed_to_mailer = true;

			/*
			 * Το email έχει ήδη φύγει: σφάλμα τρίτου στο cron δεν πρέπει να γίνει
			 * 500 ούτε να αποσύρει το token του συνδέσμου που μόλις στάλθηκε.
			 */
			try {
				self::schedule_temp_sweep();
			} catch ( Throwable $schedule_failed ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only trace χωρίς δεδομένα αποστολής.
					error_log( 'QRRP mail: temp sweep scheduling failed' );
				}
			}

			return true;
		} catch ( Throwable $exception ) {
			/*
			 * Αποσύρεται και το token, και το Throwable ξαναπετιέται ώστε ένα bug
			 * άλλου plugin να μη γίνει σιωπηλά qrrp_mail_failed.
			 */
			if ( '' !== $rebuild_token ) {
				self::forget_rebuild_token( $rebuild_token );
			}

			throw $exception;
		} finally {
			if ( ! $handed_to_mailer ) {
				self::delete_temp_file( $tmp_file );
			}
		}
	}

	/**
	 * Προγραμματίζει μία εκτέλεση του sweep TEMP_FILE_MAX_AGE + 15' αργότερα.
	 *
	 * Το wp_schedule_single_event() απορρίπτει νέο event του ίδιου hook αν υπάρχει
	 * ήδη ένα σε απόσταση < 10'. Με περιθώριο 15' (> 10'), ένα απορριφθέν event
	 * σημαίνει ότι το υπάρχον τρέχει όταν το αρχείο αυτής της αποστολής είναι ήδη
	 * > 65' παλιό, άρα θα σβηστεί. Αποτυχία προγραμματισμού δεν ακυρώνει την
	 * αποστολή: ο sweep τρέχει και σε κάθε επόμενη αποστολή.
	 */
	private static function schedule_temp_sweep() {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::TEMP_FILE_MAX_AGE + 15 * MINUTE_IN_SECONDS, self::SWEEP_CRON_HOOK );
	}

	/** Υπάρχουν ακόμη temp PNG αυτού του plugin (όχι symlinks); */
	private static function temp_files_remain() {
		$tmp_dir = get_temp_dir();

		if ( ! is_string( $tmp_dir ) || '' === $tmp_dir || ! is_dir( $tmp_dir ) ) {
			return false;
		}

		$files = glob( trailingslashit( $tmp_dir ) . self::TEMP_FILE_GLOB, GLOB_NOSORT );

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( self::is_own_temp_file( basename( $file ) ) && ! is_link( $file ) && is_file( $file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * PNG bytes του DataMatrix από το αυθεντικό raw. PNG (lossless) ώστε κάθε
	 * module να μένει ακριβές· ο QRRP_DataMatrix το τοποθετεί σε αδιαφανές λευκό.
	 */
	private static function generate_image_bytes( $raw_data ) {
		if ( ! class_exists( 'QRRP_DataMatrix' ) || ! is_callable( array( 'QRRP_DataMatrix', 'png_bytes_from_raw' ) ) ) {
			return new WP_Error(
				'qrrp_datamatrix_unavailable',
				__( 'Η δημιουργία του GS1 DataMatrix δεν είναι διαθέσιμη.', 'qr-rebuilder-pro' )
			);
		}

		return QRRP_DataMatrix::png_bytes_from_raw( $raw_data );
	}

	private static function sanitize_fields( $fields ) {
		$clean  = array_fill_keys( array( 'PC', 'SN', 'LOT', 'EXP' ), '' );
		$fields = is_array( $fields ) ? $fields : array();

		foreach ( $clean as $key => $unused ) {
			if ( isset( $fields[ $key ] ) && is_scalar( $fields[ $key ] ) ) {
				/*
				 * Ήδη επαληθευμένες GS1 τιμές· όχι sanitize_text_field(), που
				 * μπορεί να αλλοιώσει νόμιμους χαρακτήρες. Μόνο printable ASCII.
				 */
				$value = substr( (string) $fields[ $key ], 0, self::MAX_FIELD_BYTES );
				$value = preg_replace( '/[^\x20-\x7E]/', '', $value );

				$clean[ $key ] = is_string( $value ) ? $value : '';
			}
		}

		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $clean['EXP'], $m ) ) {
			$clean['EXP'] = $m[3] . '-' . $m[2] . '-' . $m[1];
		}

		return $clean;
	}

	/**
	 * Defense in depth: ξαναχτίζει το canonical raw από τα πεδία (και τα
	 * εξουσιοδοτημένα extras) και απαιτεί ακριβή ισότητα με το $raw_data, αφού
	 * η send() είναι public.
	 */
	private static function validate_gs1_integrity( $display_fields, $raw_data, $proven_extras = array() ) {
		if ( ! class_exists( 'QRRP_GS1_Parser' ) || ! is_callable( array( 'QRRP_GS1_Parser', 'validate_and_build' ) ) ) {
			return new WP_Error(
				'qrrp_parser_unavailable',
				__( 'Η επαλήθευση των GS1 δεδομένων δεν είναι διαθέσιμη.', 'qr-rebuilder-pro' )
			);
		}

		$fields = $display_fields;
		if ( ! empty( $fields['EXP'] ) && preg_match( '/^(\d{2})-(\d{2})-(\d{4})$/', $fields['EXP'], $m ) ) {
			$fields['EXP'] = $m[3] . '-' . $m[2] . '-' . $m[1];
		}

		/*
		 * Τα extras μπαίνουν στο χτίσιμο του αναμενόμενου raw· η σύγκριση
		 * παραμένει ακριβής, άρα μη εξουσιοδοτημένα extras δίνουν mismatch.
		 */
		$proven_extras = is_array( $proven_extras ) ? $proven_extras : array();

		$rebuilt = QRRP_GS1_Parser::validate_and_build( $fields, $proven_extras );
		if ( ! is_array( $rebuilt ) || ! empty( $rebuilt['errors'] ) || empty( $rebuilt['raw'] ) || ! is_string( $rebuilt['raw'] ) ) {
			return new WP_Error(
				'qrrp_invalid_gs1_payload',
				__( 'Τα GS1 δεδομένα δεν πέρασαν τον τελικό έλεγχο πριν από την αποστολή email.', 'qr-rebuilder-pro' )
			);
		}

		if ( '' === $raw_data || ! hash_equals( $rebuilt['raw'], $raw_data ) ) {
			return new WP_Error(
				'qrrp_raw_mismatch',
				__( 'Τα GS1 δεδομένα δεν συμφωνούν με το RAW payload του DataMatrix.', 'qr-rebuilder-pro' )
			);
		}

		return true;
	}

	/** Κόβει display text σε χαρακτήρες Unicode (το sanitization γίνεται στον καλούντα). */
	private static function truncate_text( $value, $max_length ) {
		return QRRP_Text::truncate( $value, $max_length );
	}

	/**
	 * Γράφει την εικόνα σε temp αρχείο για τη wp_mail().
	 *
	 * Το όνομα είναι τυχαίο (GS1-DataMatrix-<12 hex>.png) και δεν περιέχει GS1
	 * αναγνωριστικά, που αλλιώς θα κατέληγαν σε temp φάκελο, MIME headers και
	 * logs mail servers.
	 */
	private static function create_image_file( $decoded ) {
		$tmp_dir = get_temp_dir();

		if ( ! is_dir( $tmp_dir ) || ! wp_is_writable( $tmp_dir ) ) {
			return new WP_Error( 'qrrp_temp_failed', __( 'Δεν ήταν δυνατή η πρόσβαση στον προσωρινό φάκελο αρχείων.', 'qr-rebuilder-pro' ) );
		}

		$length = strlen( $decoded );

		/*
		 * Χωρίς WP_Filesystem: χρειάζεται exclusive create (fopen 'xb', ασφαλές
		 * απέναντι σε symlink/race), chmod πριν γραφτούν bytes, και τοπικό
		 * αρχείο (οι μεταφορές FTP/SSH δεν φτάνουν στο get_temp_dir()). Το
		 * αρχείο σβήνεται στο ίδιο αίτημα αν η αποστολή αποτύχει· μετά από
		 * επιτυχία, από τον sweep (βλ. send()).
		 */
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Exclusive create + pre-write chmod on a local temp file; see the explanation above.
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$suffix = QRRP_Tokens::random_token( 6 );

			if ( '' === $suffix ) {
				return new WP_Error( 'qrrp_temp_failed', __( 'Δεν ήταν δυνατή η δημιουργία ασφαλούς προσωρινού ονόματος αρχείου.', 'qr-rebuilder-pro' ) );
			}

			/* Χωρίς wp_unique_filename(): τυχαίο όνομα + fopen 'xb' αρκούν, και φίλτρο τρίτου δεν αλλάζει το μοτίβο του sweep. */
			$file   = trailingslashit( $tmp_dir ) . 'GS1-DataMatrix-' . self::temp_site_tag() . '-' . $suffix . '.png';
			$handle = @fopen( $file, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === $handle ) {
				continue;
			}

			/*
			 * Περιορισμός δικαιωμάτων πριν γραφτούν τα bytes: το fopen('xb')
			 * δημιουργεί με το umask και ο temp φάκελος μπορεί να είναι κοινόχρηστος.
			 * Το αποτέλεσμα ελέγχεται και με fileperms(), αφού ορισμένα
			 * filesystems επιστρέφουν true χωρίς να εφαρμόσουν το chmod. Αν δεν
			 * πετύχει, δεν γράφονται bytes (fail closed).
			 */
			$perms_ok = @chmod( $file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( $perms_ok ) {
				clearstatcache( true, $file );
				$mode     = @fileperms( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$perms_ok = ( false !== $mode ) && 0 === ( $mode & 0077 );
			}

			if ( ! $perms_ok ) {
				fclose( $handle );
				wp_delete_file( $file );
				continue;
			}

			$offset = 0;
			while ( $offset < $length ) {
				$written = @fwrite( $handle, substr( $decoded, $offset ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				if ( false === $written || 0 === $written ) {
					break;
				}

				$offset += $written;
			}

			@fflush( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( $offset !== $length ) {
				self::delete_temp_file( $file );
				return new WP_Error( 'qrrp_write_failed', __( 'Αποτυχία προσωρινής αποθήκευσης της εικόνας GS1 DataMatrix.', 'qr-rebuilder-pro' ) );
			}

			return $file;
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return new WP_Error( 'qrrp_temp_failed', __( 'Δεν ήταν δυνατή η δημιουργία προσωρινού αρχείου εικόνας.', 'qr-rebuilder-pro' ) );
	}

	/** wp_mail() honours 'name' => 'path' attachments only from WordPress 6.2. */
	private static function supports_named_attachments() {
		return version_compare( (string) get_bloginfo( 'version' ), '6.2', '>=' );
	}

	/**
	 * Σβήνει temp PNG αυτού του plugin (ακριβές μοτίβο ονόματος, παλαιότερα της
	 * μίας ώρας): συνημμένα επιτυχών αποστολών (2.15.2) και υπόλοιπα αποστολής
	 * που διακόπηκε πριν το finally. Το πολύ $limit διαγραφές ανά κλήση·
	 * ποτέ symlinks.
	 */
	private static function sweep_stale_temp_files( $max_age = self::TEMP_FILE_MAX_AGE, $limit = self::TEMP_SWEEP_LIMIT ) {
		$tmp_dir = get_temp_dir();

		if ( ! is_string( $tmp_dir ) || '' === $tmp_dir || ! is_dir( $tmp_dir ) ) {
			return;
		}

		$files = glob( trailingslashit( $tmp_dir ) . self::TEMP_FILE_GLOB, GLOB_NOSORT );

		if ( ! is_array( $files ) ) {
			return;
		}

		$cutoff         = time() - max( 0, (int) $max_age );
		$legacy_cutoff  = min( $cutoff, time() - self::TEMP_FILE_MAX_AGE );
		/* Αρχεία με tag άλλου site (ή παλιού salt): μόνο όταν είναι σίγουρα εγκαταλελειμμένα. */
		$foreign_cutoff = time() - DAY_IN_SECONDS;
		$deleted        = 0;

		/*
		 * 2.15.2: εξετάζονται όλα τα αρχεία και το όριο μετρά διαγραφές. Αφού τα
		 * συνημμένα επιτυχών αποστολών μένουν μία ώρα, τα νέα αρχεία μπορεί να
		 * είναι πάνω από TEMP_SWEEP_LIMIT και να έκρυβαν τα παλιά (GLOB_NOSORT).
		 */
		foreach ( $files as $file ) {
			if ( $deleted >= $limit ) {
				break;
			}

			$name = basename( $file );

			if ( self::is_own_temp_file( $name ) ) {
				$file_cutoff = $cutoff;
			} elseif ( 1 === preg_match( self::TEMP_FILE_LEGACY_PATTERN, $name ) ) {
				$file_cutoff = $legacy_cutoff;
			} elseif ( 1 === preg_match( self::TEMP_FILE_PATTERN, $name ) ) {
				$file_cutoff = $foreign_cutoff;
			} else {
				continue;
			}

			if ( is_link( $file ) || ! is_file( $file ) ) {
				continue;
			}

			$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false !== $mtime && $mtime <= $file_cutoff ) {
				wp_delete_file( $file );
				++$deleted;
			}
		}
	}

	/**
	 * 2.15.7: σταθερό, μη αναστρέψιμο tag του site (HMAC με το salt της
	 * εγκατάστασης και το blog id), ώστε sweep/purge να αγγίζουν μόνο δικά του αρχεία.
	 */
	private static function temp_site_tag() {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;

		return substr( hash_hmac( 'sha256', 'qrrp-temp-file|' . $blog_id, wp_salt( 'auth' ) ), 0, 8 );
	}

	private static function is_own_temp_file( $name ) {
		return 1 === preg_match( self::TEMP_FILE_PATTERN, (string) $name, $m ) && hash_equals( self::temp_site_tag(), $m[1] );
	}

	private static function delete_temp_file( $file ) {
		if ( is_string( $file ) && '' !== $file && file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * URL της σελίδας εργαλείου για τον σύνδεσμο του email.
	 *
	 * Canonical πηγή είναι η ρυθμισμένη σελίδα (qrrp_tool_page_id → permalink),
	 * με εφεδρεία το αποθηκευμένο qrrp_tool_page_url. Το υποβληθέν URL (που
	 * μπορεί να κρατά π.χ. ?lang=) γίνεται δεκτό μόνο αν είναι same-site και
	 * είτε έχει την ίδια διαδρομή με την canonical είτε είναι δημοσιευμένη
	 * σελίδα με το εργαλείο. Χωρίς έγκυρη canonical, κανένας σύνδεσμος.
	 */
	private static function resolve_tool_page_url( $submitted_url ) {
		$submitted_url = is_scalar( $submitted_url ) ? trim( (string) $submitted_url ) : '';

		/* Υπερμεγέθες URL απορρίπτεται (όχι κόψιμο) και πέφτουμε στην canonical. */
		if ( strlen( $submitted_url ) > self::MAX_URL_BYTES ) {
			$submitted_url = '';
		}

		$submitted_url = '' !== $submitted_url ? esc_url_raw( $submitted_url ) : '';
		/*
		 * Το ID της σελίδας δεν παλιώνει όπως το αποθηκευμένο URL (αλλαγή
		 * permalink, διαγραφή). Το αποτέλεσμα περνά από τους ίδιους ελέγχους
		 * same-site με κάθε άλλη τιμή.
		 */
		$tool_page_id = qrrp_tool_page_id();
		$canonical    = '';

		if ( $tool_page_id > 0 ) {
			$permalink = get_permalink( $tool_page_id );

			if ( is_string( $permalink ) && '' !== $permalink ) {
				$canonical = esc_url_raw( $permalink );
			}
		}

		$stored = '' !== $canonical
			? $canonical
			: esc_url_raw( (string) get_option( 'qrrp_tool_page_url', '' ) );

		if ( '' === $stored || ! QRRP_Url::is_same_site( $stored ) ) {
			return '';
		}

		/*
		 * Δύο δρόμοι αποδοχής, πρώτα ο φθηνός:
		 *   1. ίδια διαδρομή με την canonical (πράξεις string, χωρίς query)·
		 *   2. αλλιώς, δημοσιευμένη σελίδα που φιλοξενεί το εργαλείο (για
		 *      πολλαπλές σελίδες εργαλείου· κοστίζει ένα url_to_postid()).
		 * Και οι δύο προϋποθέτουν is_same_site(). Σελίδες όπως /wp-admin/ ή
		 * /checkout/ δεν περιέχουν το εργαλείο, άρα απορρίπτονται.
		 */
		if ( '' !== $submitted_url
			&& QRRP_Url::is_same_site( $submitted_url )
			&& ( self::has_same_tool_path( $submitted_url, $stored )
				|| self::is_tool_page_url( $submitted_url ) ) ) {
			return self::strip_unsafe_query_args( $submitted_url );
		}

		return self::strip_unsafe_query_args( $stored );
	}

	/**
	 * Αν ένα URL αντιστοιχεί σε δημοσιευμένη ανάρτηση που φιλοξενεί το εργαλείο.
	 *
	 * Δεύτερος δρόμος αποδοχής της resolve_tool_page_url(), για site με
	 * περισσότερες από μία σελίδες εργαλείου. Η ανίχνευση γίνεται από την
	 * QRRP_Tool_Page::post_has_tool() (ίδιος κώδικας με τον Shortcode, μαζί με
	 * synced patterns και το φίλτρο qrrp_page_has_tool).
	 *
 Ήδη ελεγμένο ως same-site από τον καλούντα.
	 * @return bool
	 */
	private static function is_tool_page_url( $url ) {
		if ( ! function_exists( 'url_to_postid' ) || ! class_exists( 'QRRP_Tool_Page' ) ) {
			/* Fail closed· πέφτουμε στην canonical. */
			return false;
		}

		$post_id = (int) url_to_postid( $url );

		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );

		/*
		 * Ο έλεγχος δημοσίευσης είναι εδώ και όχι στην κοινή κλάση: ένας
		 * σύνδεσμος προς τρίτον δεν πρέπει να δείχνει σε προσχέδιο.
		 */
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}

		return QRRP_Tool_Page::post_has_tool( $post );
	}

	/**
	 * Συγκρίνει μόνο τη διαδρομή δύο same-site URLs· /tool και /tool/ θεωρούνται
	 * ίδια. Τα query args τα φιλτράρει χωριστά η strip_unsafe_query_args().
	 */
	private static function has_same_tool_path( $candidate, $canonical ) {
		$candidate_path = self::normalized_url_path( $candidate );
		$canonical_path = self::normalized_url_path( $canonical );

		return '' !== $candidate_path && $candidate_path === $canonical_path;
	}

	/** Σταθερή διαδρομή για σύγκριση, ή '' αν το URL δεν αναλύεται. */
	private static function normalized_url_path( $url ) {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';

		if ( '' === $path ) {
			$path = '/';
		}

		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );

			if ( '' === $path ) {
				$path = '/';
			}
		}

		return $path;
	}

	/**
	 * Allowlist για τα query args του συνδέσμου email.
	 *
	 * Το same-site δεν σημαίνει ότι κάθε παράμετρος επιτρέπεται να αναπαραχθεί
	 * σε email προς τρίτον (π.χ. ?access_token=...). Κρατιούνται μόνο όσα έχουν
	 * δηλωθεί ασφαλή· προεπιλογή 'lang'. Επέκταση:
	 *
	 *   add_filter( 'qrrp_rebuild_url_allowed_query_args', function ( $args ) {
	 *       $args[] = 'site';
	 *       return $args;
	 *   } );
	 *
	 * Το qrrp_token δεν περνά ποτέ από εδώ· το προσθέτει ο server.
	 */
	private static function strip_unsafe_query_args( $url ) {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$allowed = apply_filters( 'qrrp_rebuild_url_allowed_query_args', array( 'lang' ) );
		$allowed = is_array( $allowed ) ? $allowed : array();

		$safe_names = array();
		foreach ( $allowed as $name ) {
			if ( is_scalar( $name ) && '' !== (string) $name && 'qrrp_token' !== (string) $name ) {
				$safe_names[] = (string) $name;
			}
		}

		$kept = array();

		if ( ! empty( $parts['query'] ) && ! empty( $safe_names ) ) {
			$query = array();
			parse_str( (string) $parts['query'], $query );

			foreach ( $safe_names as $name ) {
				if ( isset( $query[ $name ] ) && is_scalar( $query[ $name ] ) ) {
					$kept[ $name ] = (string) $query[ $name ];
				}
			}
		}

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '/';

		/* Το fragment δεν φτάνει ποτέ στον server και δεν έχει νόημα σε email. */
		$clean = $scheme . '://' . $parts['host'] . $port . $path;

		if ( ! empty( $kept ) ) {
			$clean .= '?' . http_build_query( $kept );
		}

		return esc_url_raw( $clean );
	}

	/**
	 * TTL του συνδέσμου email: φίλτρο qrrp_rebuild_token_ttl (προεπιλογή 7
	 * ημέρες), περιορισμένο σε 1 ώρα – 30 ημέρες. Αφορά μόνο τα email tokens·
	 * τα challenges έχουν δικό τους TTL στον QRRP_Provenance.
	 */
	private static function email_rebuild_ttl() {
		$ttl = (int) apply_filters( 'qrrp_rebuild_token_ttl', 7 * DAY_IN_SECONDS );

		return max( HOUR_IN_SECONDS, min( 30 * DAY_IN_SECONDS, $ttl ) );
	}

	/**
	 * Εκδίδει email_rebuild token: τα πεδία του payload, η μετατροπή του EXP
	 * από DD-MM-YYYY σε ISO και το TTL είναι σημασιολογία του mailer.
	 *
	 * @return string Το handle, ή '' σε αποτυχία (τότε το email δεν έχει σύνδεσμο).
	 */
	private static function create_rebuild_token( $fields, $proven_extras = array() ) {
		$exp_iso       = '';
		$proven_extras = is_array( $proven_extras ) ? $proven_extras : array();

		if ( preg_match( '/^(\d{2})-(\d{2})-(\d{4})$/', (string) $fields['EXP'], $m ) ) {
			$exp_iso = $m[3] . '-' . $m[2] . '-' . $m[1];
		}

		return QRRP_Tokens::issue(
			'email_rebuild',
			array(
				'pc'     => (string) $fields['PC'],
				'sn'     => (string) $fields['SN'],
				'lot'    => (string) $fields['LOT'],
				'exp'    => $exp_iso,
				'extras' => $proven_extras,
			),
			self::email_rebuild_ttl()
		);
	}

	/**
	 * Prefill από σύνδεσμο email (QRRP_Shortcode). Επαληθεύει τον τύπο, ώστε
	 * handle άλλου τύπου (challenge, proof) να μη δίνει δεδομένα.
	 *
	 * @param string $token
	 * @return array|false
	 */
	public static function lookup_rebuild_token( $token ) {
		return QRRP_Tokens::verify_token_for_request( $token, 'email_rebuild' );
	}

	/** Αποσύρει ένα email token (χρησιμοποιείται σε αποτυχία αποστολής). */
	public static function forget_rebuild_token( $token ) {
		QRRP_Tokens::forget( $token );
	}

	/** Καλείται από το uninstall.php. */
	public static function purge_all_rebuild_tokens() {
		return QRRP_Tokens::purge_all();
	}

	private static function build_html( $site_name, $fields, $customer_name, $print_date, $raw_data, $attachment_name, $tool_url = '', &$created_token = null, $proven_extras = array(), $provenance_note = '' ) {
		$rows = '';

		/* 2.15.2: ορατή σήμανση όταν οι τιμές δεν προέρχονται αυτούσιες από σάρωση. */
		$provenance_html = '';

		if ( is_string( $provenance_note ) && '' !== $provenance_note ) {
			$provenance_html = '
			<div style="margin:0 0 16px;padding:12px 14px;border:1px solid #dba617;border-left-width:4px;border-radius:6px;background:#fcf9e8;color:#1d2327;font-size:13px;line-height:1.5">
				<strong>' . esc_html( $provenance_note ) . '</strong>
			</div>';
		}

		if ( '' !== $customer_name ) {
			$rows .= self::row( __( 'Πελάτης', 'qr-rebuilder-pro' ), $customer_name );
		}

		if ( '' !== $print_date ) {
			$rows .= self::row( __( 'Ημερομηνία', 'qr-rebuilder-pro' ), $print_date );
		}

		foreach ( array( 'PC', 'SN', 'LOT', 'EXP' ) as $key ) {
			if ( '' === $fields[ $key ] ) {
				continue;
			}

			$shown = $fields[ $key ];

			/* 2.15.3: λήξη χωρίς ημέρα (ΗΗ=00) → μήνας/έτος και εξήγηση. */
			if ( 'EXP' === $key && preg_match( '/^00-(\d{2})-(\d{4})$/', $shown, $m ) ) {
				$shown = $m[1] . '/' . $m[2] . ' ' . __( '(χωρίς ημέρα – έως το τέλος του μήνα)', 'qr-rebuilder-pro' );
			}

			$rows .= self::row( $key, $shown );
		}

		$raw_html = '';

		/*
		 * Το email δίνει αναπαράσταση του raw ασφαλή για email ([GS] αντί για
		 * ASCII 29) και σύνδεσμο prefill. Και τα δύο είναι για αναδημιουργία μέσα
		 * από το plugin· το λογισμικό συνταγών χρειάζεται πραγματική σάρωση.
		 */
		$tool_url = is_string( $tool_url ) ? $tool_url : '';

		/*
		 * Ο σύνδεσμος κουβαλά μόνο opaque token, ποτέ PC/SN/LOT/EXP (θα
		 * κατέληγαν σε history, logs, referrers). Αν το token δεν αποθηκευτεί,
		 * το email βγαίνει χωρίς σύνδεσμο.
		 */
		/*
		 * 2.15.3: όχι για επισκέπτες. Ένα token 7 ημερών ανά αποστολή επισκέπτη
		 * γέμιζε το ευρετήριο· ο παραλήπτης έχει ήδη την εικόνα και τα στοιχεία.
		 */
		$rebuild_token = ( '' !== $tool_url && is_user_logged_in() ) ? self::create_rebuild_token( $fields, $proven_extras ) : '';

		/* Επιστρέφεται στον καλούντα ώστε να αποσυρθεί αν αποτύχει η αποστολή. */
		$created_token = $rebuild_token;

		if ( '' !== $tool_url && '' !== $rebuild_token ) {
			$rebuild_url = add_query_arg( array( 'qrrp_token' => $rebuild_token ), $tool_url );

			$raw_html = '
				<div style="margin-top:22px;padding:16px 18px;border:1px solid #cfe0f0;border-radius:8px;background:#eef5fb">
					<div style="font-size:13px;font-weight:700;margin-bottom:6px;color:#135e96">'
						. esc_html__( 'Αναδημιουργία κωδικού', 'qr-rebuilder-pro' ) .
					'</div>
					<div style="margin-bottom:12px;color:#3c434a;font-size:12px;line-height:1.6">'
						. esc_html__( 'Σαρώστε απευθείας την επισυναπτόμενη εικόνα, ή ανοίξτε το εργαλείο με προσυμπληρωμένα τα στοιχεία, πατήστε «Δημιουργία» και σαρώστε τον κωδικό.', 'qr-rebuilder-pro' ) .
					'</div>
					<a href="' . esc_url( $rebuild_url ) . '" style="display:inline-block;padding:10px 18px;background:#2271b1;color:#fff;text-decoration:none;border-radius:6px;font-size:13px;font-weight:600">'
						. esc_html__( 'Άνοιγμα στο QR ReBuilder Pro', 'qr-rebuilder-pro' ) .
					'</a>
				</div>';
		} else {
			$raw_html = '
				<div style="margin-top:22px;padding:16px 18px;border:1px solid #cfe0f0;border-radius:8px;background:#eef5fb">
					<div style="font-size:13px;font-weight:700;margin-bottom:6px;color:#135e96">'
						. esc_html__( 'Αναδημιουργία κωδικού', 'qr-rebuilder-pro' ) .
					'</div>
					<div style="color:#3c434a;font-size:12px;line-height:1.6">'
						. esc_html__( 'Σαρώστε την επισυναπτόμενη εικόνα, ή εισάγετε τα παραπάνω στοιχεία (PC, SN, LOT, EXP) στο QR ReBuilder Pro για να αναδημιουργηθεί ο κωδικός.', 'qr-rebuilder-pro' ) .
					'</div>
				</div>';
		}

		/*
		 * Κείμενο για τη «Χειροκίνητη εισαγωγή»: οι διαχωριστές ως [GS], που το
		 * εργαλείο μετατρέπει ξανά σε Group Separators.
		 */
		if ( '' !== $raw_data ) {
			$manual_text = esc_html( str_replace( "\x1D", '[GS]', $raw_data ) );

			$raw_html .= '
				<div style="margin-top:16px">
					<div style="font-size:13px;font-weight:700;margin-bottom:7px">'
						. esc_html__( 'GS1 για Χειροκίνητη εισαγωγή', 'qr-rebuilder-pro' ) .
					'</div>
					<div style="margin-bottom:7px;color:#646970;font-size:12px;line-height:1.5">'
						. esc_html__( 'Αντιγράψτε το και επικολλήστε το στη «Χειροκίνητη εισαγωγή» του QR ReBuilder Pro για να αναδημιουργηθεί ο κωδικός. Το [GS] είναι ο διαχωριστής των πεδίων.', 'qr-rebuilder-pro' ) .
					'</div>
					<div style="padding:13px 14px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7;font:12px/1.6 Consolas,Monaco,monospace;word-break:break-all">'
						. $manual_text .
					'</div>
				</div>';
		}

		return '
<!doctype html>
<html lang="el">
<body style="margin:0;padding:24px;background:#f3f6f9;font-family:Arial,sans-serif;color:#1d2327">
	<div style="max-width:620px;margin:auto;background:#fff;border:1px solid #e2e4e7;border-radius:12px;overflow:hidden">
		<div style="padding:24px 28px;background:#2271b1;color:#fff">
			<div style="font-size:12px;opacity:.9">' . esc_html( $site_name ) . '</div>
			<div style="margin-top:4px;font-size:23px;font-weight:700">' . esc_html__( 'Νέο GS1 DataMatrix', 'qr-rebuilder-pro' ) . '</div>
		</div>

		<div style="padding:26px 28px">
			<p style="margin:0 0 20px;line-height:1.6;color:#3c434a">'
				. esc_html__( 'Το αναδημιουργημένο GS1 DataMatrix είναι έτοιμο. Η εικόνα βρίσκεται επισυναπτόμενη στο email.', 'qr-rebuilder-pro' ) .
			'</p>
' . $provenance_html . '
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;border:1px solid #e2e4e7">'
				. $rows .
			'</table>

			' . $raw_html . '

			<div style="margin-top:22px;padding:13px 15px;border:1px solid #cfe0f0;border-radius:8px;background:#eef5fb">
				<strong style="color:#135e96">' . esc_html__( 'Εικόνα:', 'qr-rebuilder-pro' ) . '</strong>
				<span style="word-break:break-all">' . esc_html( $attachment_name ) . '</span>
			</div>
		</div>

		<div style="padding:14px 28px;background:#f6f7f7;border-top:1px solid #e2e4e7;text-align:center;color:#646970;font-size:11px">'
			. esc_html( $site_name ) . ' · QR ReBuilder Pro
		</div>
	</div>
</body>
</html>';
	}

	/**
	 * Κείμενο σήμανσης για τιμές που δεν προέρχονται αυτούσιες από σάρωση
	 * (2.15.2), ή '' όταν δεν χρειάζεται. Μόνο από metadata του server.
	 *
	 * @param array $provenance provenance / changed_fields / changed_fields_unknown.
	 * @return string
	 */
	public static function provenance_note( array $provenance ) {
		$kind = isset( $provenance['provenance'] ) && is_string( $provenance['provenance'] ) ? $provenance['provenance'] : '';

		/* 2.15.3: ό,τι δημιουργεί επισκέπτης είναι δήλωση, όχι αποδεδειγμένη σάρωση. */
		if ( 'user_declared' === $kind ) {
			return __( 'Δηλωμένο από τον χρήστη: τα στοιχεία δόθηκαν από επισκέπτη και η προέλευσή τους δεν επαληθεύεται.', 'qr-rebuilder-pro' );
		}

		if ( 'scan_unverified' === $kind ) {
			return __( 'Μη επαληθευμένη ανάγνωση: οι τιμές επιβεβαιώθηκαν από τον χρήστη.', 'qr-rebuilder-pro' );
		}

		if ( 'manual_reconstruction' !== $kind ) {
			return '';
		}

		$changed = array();

		if ( empty( $provenance['changed_fields_unknown'] ) && isset( $provenance['changed_fields'] ) && is_array( $provenance['changed_fields'] ) ) {
			$changed = array_values( array_intersect( array( 'PC', 'SN', 'LOT', 'EXP' ), $provenance['changed_fields'] ) );
		}

		if ( array() === $changed ) {
			return __( 'Χειροκίνητη καταχώριση: οι τιμές δηλώθηκαν από τον χρήστη, όχι από σάρωση.', 'qr-rebuilder-pro' );
		}

		return sprintf(
			/* translators: %s: comma-separated field labels, e.g. "SN, LOT". */
			__( 'Χειροκίνητη αλλαγή: %s (δηλώθηκε από τον χρήστη, όχι από σάρωση).', 'qr-rebuilder-pro' ),
			implode( ', ', $changed )
		);
	}

	private static function row( $label, $value ) {
		return '<tr>
			<td style="width:32%;padding:10px 12px;border-bottom:1px solid #e2e4e7;background:#f8f9fa;font-size:12px;font-weight:700;color:#646970">'
				. esc_html( $label ) .
			'</td>
			<td style="padding:10px 12px;border-bottom:1px solid #e2e4e7;font-size:13px;font-weight:600;word-break:break-word">'
				. esc_html( $value ) .
			'</td>
		</tr>';
	}
}