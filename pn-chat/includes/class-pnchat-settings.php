<?php
/**
 * Settings (one option, `pnchat_settings`).
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings access.
 */
final class PNChat_Settings {

	const OPTION = 'pnchat_settings';

	/**
	 * Defaults. Texts are Greek because the site is Greek; every one is editable.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'          => 1,
			'visibility'       => 'all',      // all | logged_in.
			'placement'        => 'floating', // floating | shortcode.
			'title'            => 'PharmacyNeeds Βοηθός',
			'subtitle'         => 'Απαντάμε σε ερωτήσεις για την PharmacyNeeds',
			'welcome'          => 'Γεια σας! Ρωτήστε με ό,τι θέλετε για την PharmacyNeeds.',
			'placeholder'      => 'Γράψτε την ερώτησή σας…',
			'fallback'         => 'Δεν έχω ακόμα απάντηση γι\' αυτό. Δείτε μήπως σας βοηθούν οι Συχνές ερωτήσεις, ή πατήστε «Θέλω απάντηση από άνθρωπο» για να σας απαντήσουμε εμείς.',
			'fallback_button'  => 1,
			'related_max'      => 3,
			'didyoumean'       => 1,
			'didyoumean_text'  => 'Δεν είμαι σίγουρος ότι κατάλαβα. Μήπως εννοείτε:',
			'learn_auto'       => 3,
			'partial'          => 'Για το «{question}» δεν έχουμε πληροφορίες. Αφήστε το e-mail σας και θα σας απαντήσουμε σύντομα.',
			'unhelpful'        => 'Λυπούμαστε που δεν βοήθησε. Αφήστε το e-mail σας και θα σας απαντήσουμε σύντομα.',
			'email_thanks'     => 'Ευχαριστούμε! Θα σας απαντήσουμε σύντομα στο {email}.',
			'suggestions'      => "# Ερωτήσεις για το QR ReBuilder\nΤι είναι το QR ReBuilder;\nΠώς χρησιμοποιώ το QR ReBuilder;\nΆνοιγμα του QR ReBuilder | /qr-rebuilder/\n# Ερωτήσεις για το PlanDose\nΤι είναι το PlanDose;\nΠοιοι μπορούν να χρησιμοποιήσουν το PlanDose;\nΤι διαφέρει το Free από το Pro;\nΆνοιγμα του PlanDose | /plandose/",
			'synonyms'         => "κοστίζει, τιμή, κόστος, χρέωση, πόσο κάνει, δωρεάν\nεκτυπώνω, τυπώνω, εκτύπωση, print\nφαρμακείο, φαρμακοποιός\nπρόβλημα, σφάλμα, λάθος, error\nλογαριασμός, εγγραφή, προφίλ\nλειτουργεί, δουλεύει",
			'topics'           => "QR ReBuilder, rebuilder, datamatrix, gs1\nPlanDose, πλάνο δοσολογίας, πλάνα δοσολογίας, pro\nΚοινότητα Viber, viber\nΕλλείψεις ΕΟΦ, ελλείψεις, έλλειψη, εοφ\nΥπολογισμός αποθέματος, απόθεμα",
			'ai_enabled'       => 0,
			'ai_model'         => 'claude-opus-5-5',
			'ai_chat'          => 0,
			'ai_chat_daily'    => 50,
			'ai_chat_model'    => 'claude-opus-5-5',
			'ai_chat_label'    => 'Αυτόματη απάντηση από τις σελίδες μας. Δεν την έχει ελέγξει ακόμα άνθρωπος.',
			'ai_chat_wait'     => 'Ψάχνω στις σελίδες μας…',
			'ai_learn_monthly' => 200,
			'ai_learn_weekly'  => 0,
			'ai_learn_media'   => 0,
			'smalltalk_praise'       => 'Χαίρομαι που σας αρέσει! Ρωτήστε με ό,τι άλλο θέλετε.',
			'smalltalk_praise_topic' => 'Χαίρομαι που σας αρέσει! Θέλετε να μάθετε κάτι ακόμα για: {topic}; Δείτε και τις Συχνές ερωτήσεις.',
			'smalltalk_ok'           => 'Τέλεια! Αν θέλετε κάτι άλλο, ρωτήστε με.',
			'smalltalk_bye'          => 'Ευχαριστούμε! Καλή συνέχεια.',
			'smalltalk_complaint'    => 'Λυπάμαι που δεν σας βοήθησα. Αφήστε το e-mail σας και θα σας απαντήσουμε εμείς σύντομα.',
			'site_search'      => 1,
			'site_types'       => 'post, page',
			'site_exclude'     => '',
			'site_max'         => 3,
			'site_intro'       => 'Δεν έχω έτοιμη απάντηση, αλλά βρήκα σχετικές πληροφορίες στο site:',
			'site_more'        => 'Αν δεν βρήκατε αυτό που ψάχνατε, αφήστε το e-mail σας και θα σας απαντήσουμε σύντομα.',
			'strictness'       => 'normal', // loose | normal | strict.
			'max_answers'      => 3,
			'feedback'         => 1,
			'privacy_note'     => '',
			'color'            => '#0f766e',
			'position'         => 'right', // right | left.
			'rate_per_10min'   => 30,
			'notify_email'     => '',
			'notify_on_email'  => 1,
			'reply_subject'    => 'Απάντηση στην ερώτησή σας στην PharmacyNeeds',
			'retention_days'   => 365,
			'keep_on_uninstall' => 1,
		);
	}

	/**
	 * Current settings merged on the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
	}

	/**
	 * Where notifications go: the setting, or the site's admin e-mail when it
	 * is empty (read each time, so a new admin e-mail is followed).
	 *
	 * @return string
	 */
	public static function notify_address() {
		$email = (string) self::value( 'notify_email' );
		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * An editable text with its placeholder filled in. No sprintf(): a «%»
	 * typed in the text (e.g. «10%») can never break the chat.
	 *
	 * @param string $text  Setting text.
	 * @param string $token Placeholder name: «{question}» or «{email}».
	 * @param string $value Value.
	 * @return string
	 */
	public static function fill( $text, $token, $value ) {
		// Texts saved before 1.8.0 use %s (and %% for a percent sign).
		return strtr(
			(string) $text,
			array(
				'{' . $token . '}' => (string) $value,
				'%s'               => (string) $value,
				'%%'               => '%',
			)
		);
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function value( $key ) {
		$all = self::get();
		return $all[ $key ] ?? null;
	}

	/**
	 * Matcher threshold for the strictness setting.
	 *
	 * @return float
	 */
	public static function threshold() {
		$map = array(
			'loose'  => 0.42,
			'normal' => 0.5,
			'strict' => 0.62,
		);
		$s   = (string) self::value( 'strictness' );
		return $map[ $s ] ?? 0.5;
	}

	/**
	 * Cleans submitted settings.
	 *
	 * @param array<string,mixed> $in Raw input (already unslashed).
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$out = array();

		foreach ( array( 'enabled', 'feedback', 'notify_on_email', 'keep_on_uninstall', 'site_search', 'ai_enabled', 'ai_chat', 'ai_learn_weekly', 'ai_learn_media', 'fallback_button', 'didyoumean' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		$out['visibility'] = in_array( $in['visibility'] ?? '', array( 'all', 'logged_in' ), true ) ? $in['visibility'] : $d['visibility'];
		$out['placement']  = in_array( $in['placement'] ?? '', array( 'floating', 'shortcode' ), true ) ? $in['placement'] : $d['placement'];
		$out['strictness'] = in_array( $in['strictness'] ?? '', array( 'loose', 'normal', 'strict' ), true ) ? $in['strictness'] : $d['strictness'];
		$out['position']   = in_array( $in['position'] ?? '', array( 'right', 'left' ), true ) ? $in['position'] : $d['position'];

		foreach ( array( 'title', 'subtitle', 'placeholder', 'privacy_note', 'reply_subject', 'site_types', 'ai_chat_label', 'ai_chat_wait', 'didyoumean_text' ) as $k ) {
			$out[ $k ] = sanitize_text_field( (string) ( $in[ $k ] ?? '' ) );
		}
		foreach ( array( 'welcome', 'fallback', 'partial', 'unhelpful', 'email_thanks', 'suggestions', 'synonyms', 'topics', 'site_exclude', 'site_intro', 'site_more', 'smalltalk_praise', 'smalltalk_praise_topic', 'smalltalk_ok', 'smalltalk_bye', 'smalltalk_complaint' ) as $k ) {
			$out[ $k ] = sanitize_textarea_field( (string) ( $in[ $k ] ?? '' ) );
		}
		foreach ( array( 'title', 'fallback', 'unhelpful', 'placeholder', 'didyoumean_text', 'smalltalk_praise', 'smalltalk_praise_topic', 'smalltalk_ok', 'smalltalk_bye', 'smalltalk_complaint' ) as $k ) {
			if ( '' === $out[ $k ] ) {
				$out[ $k ] = $d[ $k ];
			}
		}

		$color        = sanitize_hex_color( (string) ( $in['color'] ?? '' ) );
		$out['color'] = $color ? $color : $d['color'];

		$model           = trim( sanitize_text_field( (string) ( $in['ai_model'] ?? '' ) ) );
		$out['ai_model'] = preg_match( '/^claude-[a-z0-9.-]+$/', $model ) ? $model : $d['ai_model'];
		$cmodel               = trim( sanitize_text_field( (string) ( $in['ai_chat_model'] ?? '' ) ) );
		$out['ai_chat_model'] = preg_match( '/^claude-[a-z0-9.-]+$/', $cmodel ) ? $cmodel : $d['ai_chat_model'];
		$out['ai_chat_daily']  = max( 1, min( 1000, absint( $in['ai_chat_daily'] ?? $d['ai_chat_daily'] ) ) );
		$out['ai_learn_monthly'] = max( 1, min( 5000, absint( $in['ai_learn_monthly'] ?? $d['ai_learn_monthly'] ) ) );
		$out['related_max']      = min( 5, absint( $in['related_max'] ?? $d['related_max'] ) );
		$out['learn_auto']       = min( 50, absint( $in['learn_auto'] ?? $d['learn_auto'] ) );
		$out['site_max']       = max( 1, min( 5, absint( $in['site_max'] ?? $d['site_max'] ) ) );
		$out['max_answers']    = max( 1, min( 5, absint( $in['max_answers'] ?? $d['max_answers'] ) ) );
		$out['rate_per_10min'] = max( 1, min( 500, absint( $in['rate_per_10min'] ?? $d['rate_per_10min'] ) ) );
		$out['retention_days'] = min( 3650, absint( $in['retention_days'] ?? $d['retention_days'] ) );

		$email               = sanitize_email( (string) ( $in['notify_email'] ?? '' ) );
		$out['notify_email'] = is_email( $email ) ? $email : '';

		return $out;
	}

	/**
	 * Texts of 1.0.0 that 1.0.1 changed: saved settings that still hold the
	 * old default get the new one (texts the admin changed are kept).
	 *
	 * @return void
	 */
	public static function migrate() {
		$version = (int) get_option( 'pnchat_settings_version' );
		if ( $version >= 8 ) {
			return;
		}
		if ( $version >= 7 ) {
			self::migrate_to_8();
			return;
		}
		if ( $version < 5 ) {
			self::migrate_to_5( $version );
		}
		if ( $version < 6 ) {
			// 1.7.0: «…στην PharmacyNeeds» in the e-mail subject.
			self::replace_old_defaults( array( 'reply_subject' => array( 'Απάντηση στην ερώτησή σας στο PharmacyNeeds' ) ) );
		}
		// 1.8.0: {question} and {email} instead of %s.
		self::replace_old_defaults(
			array(
				'partial'      => array( 'Για το «%s» δεν έχουμε πληροφορίες. Αφήστε το e-mail σας και θα σας απαντήσουμε σύντομα.' ),
				'email_thanks' => array( 'Ευχαριστούμε! Θα σας απαντήσουμε σύντομα στο %s.' ),
			)
		);
		self::migrate_to_8();
	}

	/**
	 * 1.9.2: a friendlier «don't know» text (the e-mail form is behind a
	 * button now). Only when the old default was never changed.
	 *
	 * @return void
	 */
	private static function migrate_to_8() {
		self::replace_old_defaults( array( 'fallback' => array( 'Δεν έχουμε πληροφορίες για το συγκεκριμένο ερώτημα. Αφήστε το e-mail σας και θα σας απαντήσουμε σύντομα.' ) ) );
		update_option( 'pnchat_settings_version', 8, false );
	}

	/**
	 * Migrations up to 1.4.0.
	 *
	 * @param int $version Stored settings version.
	 * @return void
	 */
	private static function migrate_to_5( $version ) {
		if ( $version >= 2 ) {
			// 1.2.1: QR ReBuilder no longer answered as PlanDose's QR.
			// 1.3.0: grouped suggestions, shorter welcome, QR ReBuilder how-to.
			if ( get_option( 'pnchat_seeded' ) ) {
				PNChat_Seed::upgrade_qr();
			}
			self::replace_old_defaults(
				array(
					'welcome'     => array( 'Γεια σας! Ρωτήστε με ό,τι θέλετε για την PharmacyNeeds και το PlanDose.' ),
					'suggestions' => array( "Τι είναι το PlanDose;\nΠοιοι μπορούν να χρησιμοποιήσουν το PlanDose;\nΤι διαφέρει το Free από το Pro;" ),
					// 1.4.0: «δωρεάν» asks about cost.
					'synonyms'    => array( "κοστίζει, τιμή, κόστος, χρέωση, πόσο κάνει\nεκτυπώνω, τυπώνω, εκτύπωση, print\nφαρμακείο, φαρμακοποιός\nπρόβλημα, σφάλμα, λάθος, error\nλογαριασμός, εγγραφή, προφίλ", "κοστίζει, τιμή, κόστος, χρέωση, πόσο κάνει\nεκτυπώνω, τυπώνω, εκτύπωση, print\nφαρμακείο, φαρμακοποιός\nπρόβλημα, σφάλμα, λάθος, error\nλογαριασμός, εγγραφή, προφίλ\nλειτουργεί, δουλεύει" ),
				)
			);
			update_option( 'pnchat_settings_version', 5, false );
			return;
		}
		self::replace_old_defaults(
			array(
				'subtitle'     => array( 'Απαντάμε σε ερωτήσεις για το PharmacyNeeds' ),
				'welcome'      => array( 'Γεια σας! Ρωτήστε με ό,τι θέλετε για το PharmacyNeeds και το PlanDose.', 'Γεια σας! Ρωτήστε με ό,τι θέλετε για την PharmacyNeeds και το PlanDose.' ),
				'privacy_note' => array( 'Μη γράφετε στοιχεία ασθενών.' ),
				'suggestions'  => array( "Τι είναι το PlanDose;\nΠοιοι μπορούν να χρησιμοποιήσουν το PlanDose;\nΤι διαφέρει το Free από το Pro;" ),
				'synonyms'     => array( "κοστίζει, τιμή, κόστος, χρέωση, πόσο κάνει\nεκτυπώνω, τυπώνω, εκτύπωση, print\nφαρμακείο, φαρμακοποιός\nπρόβλημα, σφάλμα, λάθος, error\nλογαριασμός, εγγραφή, προφίλ", "κοστίζει, τιμή, κόστος, χρέωση, πόσο κάνει\nεκτυπώνω, τυπώνω, εκτύπωση, print\nφαρμακείο, φαρμακοποιός\nπρόβλημα, σφάλμα, λάθος, error\nλογαριασμός, εγγραφή, προφίλ\nλειτουργεί, δουλεύει" ),
			)
		);
		if ( get_option( 'pnchat_seeded' ) ) {
			PNChat_Seed::upgrade_qr();
		}
		update_option( 'pnchat_settings_version', 5, false );
	}

	/**
	 * Older default texts that a newer version replaced, by setting.
	 *
	 * @return array<string,string[]>
	 */
	public static function old_defaults() {
		return array(
			'partial'      => array( 'Για το «%s» δεν έχουμε πληροφορίες. Αφήστε το e-mail σας και θα σας απαντήσουμε σύντομα.' ),
			'email_thanks' => array( 'Ευχαριστούμε! Θα σας απαντήσουμε σύντομα στο %s.' ),
			'fallback'     => array( 'Δεν έχουμε πληροφορίες για το συγκεκριμένο ερώτημα. Αφήστε το e-mail σας και θα σας απαντήσουμε σύντομα.' ),
		);
	}

	/**
	 * Settings from a brain file made with an older version: texts that are
	 * an older default get the current one (1.9.2: «…Αφήστε το e-mail σας»
	 * no longer fits the form behind a button). Texts the site wrote stay.
	 *
	 * @param array<string,mixed> $in Settings.
	 * @return array<string,mixed>
	 */
	public static function upgrade_texts( array $in ) {
		$new  = self::defaults();
		$norm = function ( $v ) {
			return trim( str_replace( "\r\n", "\n", (string) $v ) );
		};
		foreach ( self::old_defaults() as $k => $values ) {
			if ( isset( $in[ $k ] ) && in_array( $norm( $in[ $k ] ), array_map( $norm, $values ), true ) ) {
				$in[ $k ] = $new[ $k ];
			}
		}
		return $in;
	}

	/**
	 * Saved settings that still hold an older default text get the current
	 * default; texts the administrator wrote are kept.
	 *
	 * @param array<string,string[]> $old Key => older defaults.
	 * @return void
	 */
	private static function replace_old_defaults( array $old ) {
		$saved = get_option( self::OPTION );
		if ( ! is_array( $saved ) ) {
			return;
		}
		$new     = self::defaults();
		$changed = false;
		// Textareas come back from the browser with \r\n line ends.
		$norm = function ( $v ) {
			return trim( str_replace( "\r\n", "\n", (string) $v ) );
		};
		foreach ( $old as $k => $values ) {
			if ( isset( $saved[ $k ] ) && in_array( $norm( $saved[ $k ] ), array_map( $norm, $values ), true ) ) {
				$saved[ $k ] = $new[ $k ];
				$changed     = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $saved, false );
		}
	}

	/**
	 * Suggested questions as groups for the widget. One per line; a line
	 * starting with # starts a group («# Ερωτήσεις για το QR ReBuilder»); a
	 * line «text | address» is a link button instead of a question.
	 *
	 * @param string $text Setting.
	 * @return array<int,array{title:string,items:array<int,array{text:string,url?:string}>}>
	 */
	public static function suggestion_groups( $text ) {
		$groups = array();
		$cur    = array(
			'title' => '',
			'items' => array(),
		);
		$count  = 0;
		foreach ( PNChat_Store::lines( (string) $text ) as $line ) {
			if ( '#' === $line[0] ) {
				if ( $cur['items'] ) {
					$groups[] = $cur;
				}
				$cur = array(
					'title' => trim( ltrim( $line, '#' ) ),
					'items' => array(),
				);
				continue;
			}
			if ( $count >= 40 ) {
				continue;
			}
			$item = array( 'text' => $line );
			$bar  = strrpos( $line, '|' );
			if ( false !== $bar ) {
				$label = trim( substr( $line, 0, $bar ) );
				$url   = trim( substr( $line, $bar + 1 ) );
				if ( '' !== $url && '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
					$url = home_url( $url );
				}
				$url = esc_url_raw( $url, array( 'http', 'https' ) );
				if ( '' !== $label && '' !== $url ) {
					$item = array(
						'text' => $label,
						'url'  => $url,
					);
				}
			}
			$cur['items'][] = $item;
			++$count;
		}
		if ( $cur['items'] ) {
			$groups[] = $cur;
		}
		return $groups;
	}

	/**
	 * Saves cleaned settings.
	 *
	 * @param array<string,mixed> $clean From sanitize().
	 * @return bool The option now holds them (false: the database refused).
	 */
	public static function save( array $clean ) {
		update_option( self::OPTION, $clean, false );
		// update_option() is also false when nothing changed; the cache
		// keeps the old value only when the write failed.
		return get_option( self::OPTION ) === $clean;
	}
}
