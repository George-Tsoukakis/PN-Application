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
			'welcome'          => 'Γεια σας! Ρωτήστε με ό,τι θέλετε για την PharmacyNeeds και το PlanDose.',
			'placeholder'      => 'Γράψτε την ερώτησή σας…',
			'fallback'         => 'Δεν έχουμε πληροφορίες για το συγκεκριμένο ερώτημα. Αφήστε το e-mail σας και θα σας απαντήσουμε σύντομα.',
			'partial'          => 'Για το «%s» δεν έχουμε πληροφορίες. Αφήστε το e-mail σας και θα σας απαντήσουμε σύντομα.',
			'unhelpful'        => 'Λυπούμαστε που δεν βοήθησε. Αφήστε το e-mail σας και θα σας απαντήσουμε σύντομα.',
			'email_thanks'     => 'Ευχαριστούμε! Θα σας απαντήσουμε σύντομα στο %s.',
			'suggestions'      => "Τι είναι το PlanDose;\nΠοιοι μπορούν να χρησιμοποιήσουν το PlanDose;\nΤι διαφέρει το Free από το Pro;",
			'synonyms'         => "κοστίζει, τιμή, κόστος, χρέωση, πόσο κάνει\nεκτυπώνω, τυπώνω, εκτύπωση, print\nφαρμακείο, φαρμακοποιός\nπρόβλημα, σφάλμα, λάθος, error\nλογαριασμός, εγγραφή, προφίλ",
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
			'reply_subject'    => 'Απάντηση στην ερώτησή σας στο PharmacyNeeds',
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
		$all = array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
		if ( '' === $all['notify_email'] ) {
			$all['notify_email'] = (string) get_option( 'admin_email' );
		}
		return $all;
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

		foreach ( array( 'enabled', 'feedback', 'notify_on_email', 'keep_on_uninstall', 'site_search' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		$out['visibility'] = in_array( $in['visibility'] ?? '', array( 'all', 'logged_in' ), true ) ? $in['visibility'] : $d['visibility'];
		$out['placement']  = in_array( $in['placement'] ?? '', array( 'floating', 'shortcode' ), true ) ? $in['placement'] : $d['placement'];
		$out['strictness'] = in_array( $in['strictness'] ?? '', array( 'loose', 'normal', 'strict' ), true ) ? $in['strictness'] : $d['strictness'];
		$out['position']   = in_array( $in['position'] ?? '', array( 'right', 'left' ), true ) ? $in['position'] : $d['position'];

		foreach ( array( 'title', 'subtitle', 'placeholder', 'privacy_note', 'reply_subject', 'site_types' ) as $k ) {
			$out[ $k ] = sanitize_text_field( (string) ( $in[ $k ] ?? '' ) );
		}
		foreach ( array( 'welcome', 'fallback', 'partial', 'unhelpful', 'email_thanks', 'suggestions', 'synonyms', 'site_exclude', 'site_intro', 'site_more' ) as $k ) {
			$out[ $k ] = sanitize_textarea_field( (string) ( $in[ $k ] ?? '' ) );
		}
		foreach ( array( 'title', 'fallback', 'unhelpful', 'placeholder' ) as $k ) {
			if ( '' === $out[ $k ] ) {
				$out[ $k ] = $d[ $k ];
			}
		}

		$color        = sanitize_hex_color( (string) ( $in['color'] ?? '' ) );
		$out['color'] = $color ? $color : $d['color'];

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
		if ( (int) get_option( 'pnchat_settings_version' ) >= 2 ) {
			return;
		}
		$saved = get_option( self::OPTION );
		if ( is_array( $saved ) ) {
			$old = array(
				'subtitle'     => 'Απαντάμε σε ερωτήσεις για το PharmacyNeeds',
				'welcome'      => 'Γεια σας! Ρωτήστε με ό,τι θέλετε για το PharmacyNeeds και το PlanDose.',
				'privacy_note' => 'Μη γράφετε στοιχεία ασθενών.',
			);
			$new = self::defaults();
			foreach ( $old as $k => $v ) {
				if ( isset( $saved[ $k ] ) && $saved[ $k ] === $v ) {
					$saved[ $k ] = $new[ $k ];
				}
			}
			update_option( self::OPTION, $saved, false );
		}
		update_option( 'pnchat_settings_version', 2, false );
	}

	/**
	 * Saves cleaned settings.
	 *
	 * @param array<string,mixed> $clean From sanitize().
	 * @return void
	 */
	public static function save( array $clean ) {
		update_option( self::OPTION, $clean, false );
	}
}
