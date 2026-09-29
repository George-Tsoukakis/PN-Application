<?php
/**
 * Owns the plandose_settings option: defaults, sanitization, typed accessors
 * and the display-scope rules that decide where the tool button appears.
 *
 * Schema, settings, access policy and reporting each live in their own file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Settings {

	/** Allowed values for 'label_orientation'. See label_orientations(). */
	const LABEL_ORIENTATIONS = array( 'landscape', 'portrait', 'auto' );

	/** Keys of display_scopes(), untranslated (see sanitize_settings()). */
	const DISPLAY_SCOPES = array( 'everywhere', 'front_page', 'selected' );

	/** Per-user transient prefix: a save with «selected pages» and no page was refused. */
	const SCOPE_REFUSED_TRANSIENT = 'plandose_scope_refused_';

	/**
	 * Factory defaults for the two limits an administrator can change from
	 * the settings screen.
	 *
	 * These literals are the ONLY copy of these numbers in the plugin. A
	 * site can override either one from wp-config.php by defining
	 * PLANDOSE_FREE_MONTHLY_LIMIT / PLANDOSE_MAX_PLAN_DAYS before WordPress
	 * loads plugins — see default_free_monthly_limit() and
	 * default_max_plan_days() below, which are the only things that should
	 * ever read those constants. Do not repeat the numbers anywhere else:
	 * a second copy that drifts out of step is worse than no override at
	 * all, because both look authoritative.
	 *
	 * They are DEFAULTS, not effective values. Once the plandose_settings
	 * option exists, the live figures come from free_monthly_limit() and
	 * max_plan_days().
	 */
	const DEFAULT_FREE_MONTHLY_LIMIT = 30;
	const DEFAULT_MAX_PLAN_DAYS      = 60;
	const DEFAULT_MAX_ADD_DAYS       = 1095;

	/**
	 * Canonical upper bounds for the numeric settings. These are the single
	 * source of truth: the settings form (min/max attributes), the POST save
	 * handler, and sanitize_settings() below must all clamp against these
	 * same constants. If each layer used its own literal, a value
	 * accepted by the save handler could be silently rewritten to a different
	 * ceiling by the sanitizer (e.g. default_pro_days 36500 → 1095).
	 */
	const LIMIT_FREE_MONTHLY_MAX = 1000000;
	const LIMIT_MAX_PLAN_DAYS    = 3650;
	const LIMIT_PRO_DAYS_MAX     = 36500;
	const LIMIT_ADD_DAYS_MAX     = 36500;
	const LIMIT_PRO_PRINT_MAX    = 1000000;
	const LIMIT_INVOICE_MB_MAX   = 50;
	const LIMIT_AUDIT_RETENTION_MAX = 3650;

	/**
	 * Values used as defaults before 1.6.3, kept here only so the migration
	 * below can recognize "this site never touched the setting" and safely
	 * bump it to the new default.
	 */
	const LEGACY_FREE_MONTHLY_LIMIT = 30;
	const LEGACY_DEFAULT_PRO_DAYS    = 90;

	/**
	 * One-time upgrade for sites that installed PlanDose before 1.6.3.
	 *
	 * A fresh install already gets the current defaults (free prints/month,
	 * 365-day Pro) via default_settings(). But existing installs already have
	 * a `plandose_settings` option saved in the database with the *old*
	 * defaults (30 / 90) baked in, and changing the class constants above
	 * does nothing to that already-saved row — WordPress options aren't
	 * re-derived from code defaults once they exist.
	 *
	 * So: if the currently saved value still exactly matches the old
	 * default, we treat that as "this pharmacy never customized it" and
	 * bump it to the new default. If an admin had deliberately set a
	 * different custom number, this leaves it untouched. Runs once per
	 * version bump (gated on plandose_db_version) so it doesn't re-run and
	 * doesn't fight a deliberate change back to 30/90 after upgrading.
	 */
	public static function maybe_migrate_legacy_defaults() {
		$installed_version = get_option( 'plandose_db_version', '' );
		$current_version    = defined( 'PLANDOSE_VERSION' ) ? PLANDOSE_VERSION : '1.6.3';

		if ( $installed_version && version_compare( $installed_version, '1.6.3', '>=' ) ) {
			return;
		}

		$settings = get_option( 'plandose_settings', false );

		if ( is_array( $settings ) ) {
			$changed = false;

			if ( isset( $settings['free_monthly_limit'] ) && self::LEGACY_FREE_MONTHLY_LIMIT === (int) $settings['free_monthly_limit'] ) {
				// The resolver, not the raw class constant: a site that has
				// set PLANDOSE_FREE_MONTHLY_LIMIT in wp-config.php should be
				// bumped to ITS chosen figure, not to the class default — otherwise the
				// upgrade would quietly overrule a deliberate configuration.
				$settings['free_monthly_limit'] = self::default_free_monthly_limit();
				$changed = true;
			}

			if ( isset( $settings['default_pro_days'] ) && self::LEGACY_DEFAULT_PRO_DAYS === (int) $settings['default_pro_days'] ) {
				$settings['default_pro_days'] = 365;
				$changed = true;
			}

			if ( $changed ) {
				update_option( 'plandose_settings', $settings );
			}
		}

		update_option( 'plandose_db_version', $current_version );
	}

	/**
	 * The Free default before it was lowered to 30 prints a month.
	 */
	const PREVIOUS_FREE_MONTHLY_LIMIT = 450;

	/**
	 * Option that records the one-time check below has run.
	 */
	const FREE_LIMIT_MIGRATION_OPTION = 'plandose_free_limit_450_checked';

	/**
	 * One-time move of the saved Free allowance from the old default (450)
	 * to the current one.
	 *
	 * The saved settings row keeps its number after the class default
	 * changes, so without this an existing site would stay at 450. Only the
	 * exact old default is changed: a figure an admin chose stays as it is.
	 * The flag makes it run once, so an admin who sets 450 again afterwards
	 * keeps it. The change is written to the audit log.
	 */
	public static function maybe_migrate_free_limit() {
		if ( get_option( self::FREE_LIMIT_MIGRATION_OPTION ) ) {
			return;
		}

		$settings = get_option( 'plandose_settings', false );
		$target   = self::default_free_monthly_limit();

		if (
			is_array( $settings )
			&& isset( $settings['free_monthly_limit'] )
			&& self::PREVIOUS_FREE_MONTHLY_LIMIT === (int) $settings['free_monthly_limit']
			&& self::PREVIOUS_FREE_MONTHLY_LIMIT !== $target
		) {
			$settings['free_monthly_limit'] = $target;

			if ( ! update_option( 'plandose_settings', $settings ) ) {
				// Not flagged: the next request tries again.
				return;
			}

			if ( class_exists( 'Plandose_Admin' ) && method_exists( 'Plandose_Admin', 'audit' ) ) {
				Plandose_Admin::audit(
					'free_limit_migrated',
					0,
					array(
						'from' => self::PREVIOUS_FREE_MONTHLY_LIMIT,
						'to'   => $target,
					),
					true
				);
			}
		}

		update_option( self::FREE_LIMIT_MIGRATION_OPTION, 1, true );
	}

	/**
	 * Default monthly print allowance for Free accounts.
	 *
	 * Returns PLANDOSE_FREE_MONTHLY_LIMIT when that constant is defined in
	 * wp-config.php and holds a usable number, otherwise the class default.
	 *
	 * The constant is validated rather than trusted: a typo (0, a negative
	 * number, a string, or something past LIMIT_FREE_MONTHLY_MAX) falls back
	 * to the default instead of being written into the settings row, where
	 * it would then survive removing the bad constant again. Note that 0
	 * is rejected here on purpose — it means "unlimited" for the Pro print
	 * limit, but a Free tier with no ceiling is a configuration mistake,
	 * not a plan.
	 *
	 * @return int
	 */
	public static function default_free_monthly_limit() {
		if ( defined( 'PLANDOSE_FREE_MONTHLY_LIMIT' ) && is_numeric( PLANDOSE_FREE_MONTHLY_LIMIT ) ) {
			$value = (int) PLANDOSE_FREE_MONTHLY_LIMIT;

			if ( $value >= 1 && $value <= self::LIMIT_FREE_MONTHLY_MAX ) {
				return $value;
			}
		}

		return self::DEFAULT_FREE_MONTHLY_LIMIT;
	}

	/**
	 * Default maximum plan duration in days. Same override and validation
	 * rules as default_free_monthly_limit() above.
	 *
	 * @return int
	 */
	public static function default_max_plan_days() {
		if ( defined( 'PLANDOSE_MAX_PLAN_DAYS' ) && is_numeric( PLANDOSE_MAX_PLAN_DAYS ) ) {
			$value = (int) PLANDOSE_MAX_PLAN_DAYS;

			if ( $value >= 1 && $value <= self::LIMIT_MAX_PLAN_DAYS ) {
				return $value;
			}
		}

		return self::DEFAULT_MAX_PLAN_DAYS;
	}

	/**
	 * Default plugin settings.
	 */
	public static function default_settings() {
		return array(
			'enabled'             => 1,
			'show_to_guests'      => 1,
			'login_page'          => '',
			'guest_message'       => __( 'Συνδεθείτε για να δημιουργήσετε το πλάνο δόσεων σας.', 'plandose' ),
			'allow_admin_preview' => 1,
			'display_scope'       => 'everywhere',
			'display_pages'       => array(),
			'button_position'     => 'bottom_right',
			'button_text'         => __( 'Πλάνο Δόσεων', 'plandose' ),
			'button_color'        => '#0f6e56',
			'free_monthly_limit'  => self::default_free_monthly_limit(),
			'max_plan_days'       => self::default_max_plan_days(),
			'default_pro_days'    => 365,
			'max_add_days'        => self::DEFAULT_MAX_ADD_DAYS,
			'expiring_days'       => 7,
			'pro_print_limit'     => 0,
			'show_print_counter'  => 1,
			// «Υπενθυμίσεις στο κινητό» QR on the printed sheet.
			'calendar_qr'         => 1,
			// Pro label printer: forced page orientation (see label_orientations()).
			'label_orientation'   => 'landscape',
			'audit_retention_days' => 180,
			'audit_anonymize_ip'  => 1,
			'keep_data_on_uninstall' => 1,
			'invoice_max_mb'      => 5,
			'invoice_mimes'       => self::valid_invoice_mimes(),
			'hidden_roles'        => array(),
			'disclaimer'          => __( 'Το πλάνο αυτό είναι βοήθημα υπενθύμισης δοσολογίας και δεν αντικαθιστά την οδηγία ιατρού ή φαρμακοποιού. Σε περίπτωση αμφιβολίας συμβουλευτείτε τον φαρμακοποιό σας.', 'plandose' ),
			/*
			 * The two closing lines of the printout, editable on the settings
			 * form. The defaults below are the same strings as
			 * Plandose_Frontend's fallbacks, so a site that never set them
			 * prints the same text.
			 */
			'thanks_message_line1' => __( 'Ευχαριστούμε που εμπιστευτήκατε το φαρμακείο μας.', 'plandose' ),
			'thanks_message_line2' => __( 'Για οποιαδήποτε διευκρίνηση είμαστε πάντα στη διάθεσή σας!', 'plandose' ),
		);
	}

	/**
	 * Page orientations the Pro label printout may force.
	 *
	 * 'landscape' is the default because it is the one proven on wide,
	 * short labels (e.g. Zebra 100x47 mm): a driver left on portrait gives
	 * the browser a narrow page box and the label breaks onto two. 'auto'
	 * forces nothing and leaves it to the printer driver.
	 *
	 * @return array value => label (translated; admin screen only)
	 */
	public static function label_orientations() {
		return array(
			'landscape' => __( 'Οριζόντια (προτείνεται)', 'plandose' ),
			'portrait'  => __( 'Κάθετη', 'plandose' ),
			'auto'      => __( 'Αυτόματα (όπως ο οδηγός του εκτυπωτή)', 'plandose' ),
		);
	}

	/**
	 * The invoice MIME types PlanDose can store. The list lives in
	 * Plandose_Invoice_Storage::ALLOWED_INVOICE_MIMES; that class is loaded
	 * after this one (plandose_required_files()), so it is read lazily here.
	 * The literal copy is only for a request where it is not loaded.
	 *
	 * @return string[]
	 */
	public static function valid_invoice_mimes() {
		if ( class_exists( 'Plandose_Invoice_Storage' ) && defined( 'Plandose_Invoice_Storage::ALLOWED_INVOICE_MIMES' ) ) {
			return array_values( (array) Plandose_Invoice_Storage::ALLOWED_INVOICE_MIMES );
		}

		return array( 'application/pdf', 'image/jpeg', 'image/png', 'image/webp' );
	}

	/**
	 * Sanitize plugin settings.
	 *
	 * @param mixed  $settings Raw settings.
	 * @param string $context  'save' (default: an admin is storing these) or
	 *                         'read' (settings() normalizing the stored row;
	 *                         must have no side effects).
	 */
	public static function sanitize_settings( $settings, $context = 'save' ) {
		$defaults = self::default_settings();

		if ( ! is_array( $settings ) ) {
			return $defaults;
		}

		$settings = wp_parse_args( $settings, $defaults );

		$settings['enabled']             = empty( $settings['enabled'] ) ? 0 : 1;
		$settings['show_to_guests']      = empty( $settings['show_to_guests'] ) ? 0 : 1;
		$settings['allow_admin_preview'] = empty( $settings['allow_admin_preview'] ) ? 0 : 1;
		$settings['show_print_counter']  = empty( $settings['show_print_counter'] ) ? 0 : 1;
		$settings['calendar_qr']         = empty( $settings['calendar_qr'] ) ? 0 : 1;
		$settings['keep_data_on_uninstall'] = empty( $settings['keep_data_on_uninstall'] ) ? 0 : 1;

		$settings['login_page']    = self::safe_internal_url( (string) $settings['login_page'], '' );
		$settings['guest_message'] = sanitize_textarea_field( (string) $settings['guest_message'] );
		$settings['button_text']   = sanitize_text_field( (string) $settings['button_text'] );
		$settings['button_color']  = sanitize_hex_color( (string) $settings['button_color'] );
		$settings['disclaimer']    = sanitize_textarea_field( (string) $settings['disclaimer'] );

		// Single-line each: they are printed as two <p> lines on the plan,
		// so a newline in them would not render as one anyway.
		$settings['thanks_message_line1'] = sanitize_text_field( (string) $settings['thanks_message_line1'] );
		$settings['thanks_message_line2'] = sanitize_text_field( (string) $settings['thanks_message_line2'] );

		if ( empty( $settings['button_color'] ) ) {
			$settings['button_color'] = $defaults['button_color'];
		}

		// Validated against the untranslated constant: label_orientations()
		// only exists to build the translated labels of the admin screen.
		if ( ! in_array( (string) $settings['label_orientation'], self::LABEL_ORIENTATIONS, true ) ) {
			$settings['label_orientation'] = $defaults['label_orientation'];
		}

		$allowed_positions = array( 'bottom_right', 'bottom_left', 'top_right', 'top_left' );
		if ( ! in_array( $settings['button_position'], $allowed_positions, true ) ) {
			$settings['button_position'] = $defaults['button_position'];
		}

		$settings['free_monthly_limit'] = max( 1, min( self::LIMIT_FREE_MONTHLY_MAX, (int) $settings['free_monthly_limit'] ) );
		$settings['max_plan_days']      = max( 1, min( self::LIMIT_MAX_PLAN_DAYS, (int) $settings['max_plan_days'] ) );
		$settings['default_pro_days']   = max( 1, min( self::LIMIT_PRO_DAYS_MAX, (int) $settings['default_pro_days'] ) );
		$settings['max_add_days']       = max( 1, min( self::LIMIT_ADD_DAYS_MAX, (int) $settings['max_add_days'] ) );
		$settings['expiring_days']      = max( 1, min( 365, (int) $settings['expiring_days'] ) );
		$settings['pro_print_limit']    = max( 0, min( self::LIMIT_PRO_PRINT_MAX, (int) $settings['pro_print_limit'] ) );
		$settings['invoice_max_mb']     = max( 1, min( self::LIMIT_INVOICE_MB_MAX, (int) $settings['invoice_max_mb'] ) );

		// Audit-log privacy controls. Retention of 0 = keep forever; cap at
		// ~10 years so an accidental huge value can't be stored. IP
		// anonymization is a simple on/off flag.
		$settings['audit_retention_days'] = max( 0, min( self::LIMIT_AUDIT_RETENTION_MAX, (int) $settings['audit_retention_days'] ) );
		$settings['audit_anonymize_ip']   = empty( $settings['audit_anonymize_ip'] ) ? 0 : 1;

		$valid_mimes = self::valid_invoice_mimes();

		if ( ! is_array( $settings['invoice_mimes'] ) ) {
			$settings['invoice_mimes'] = array();
		}

		$settings['invoice_mimes'] = array_values( array_intersect( $valid_mimes, $settings['invoice_mimes'] ) );

		if ( empty( $settings['invoice_mimes'] ) ) {
			$settings['invoice_mimes'] = array( 'application/pdf' );
		}

		$settings['hidden_roles'] = self::sanitize_roles_array( $settings['hidden_roles'] );

		// Two settings without effect (the tool is for
		// «Φαρμακείο» accounts only): dropped from the option. A stored row
		// that still carries them loses them on the next save.
		unset( $settings['require_pharmacy'], $settings['allowed_roles'] );

		if ( ! in_array( $settings['display_scope'], self::DISPLAY_SCOPES, true ) ) {
			$settings['display_scope'] = $defaults['display_scope'];
		}

		if ( ! is_array( $settings['display_pages'] ) ) {
			$settings['display_pages'] = array();
		}

		// Cast with (int), not absint(): absint() takes the absolute value, so
		// a stray -4 would silently become page 4 instead of being discarded.
		$settings['display_pages'] = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $settings['display_pages'] ),
					static function ( $page_id ) {
						return $page_id > 0;
					}
				)
			)
		);

		/*
		 * "Selected pages" with nothing selected is refused on save:
		 * the previously stored scope and pages are kept (or 'everywhere'
		 * when those are unusable too) and the admin is told why
		 * (render_scope_refused_notice()). It is not turned into
		 * 'everywhere' when READ: should_display_here() shows the tool
		 * nowhere for such a stored row, which is closer to what the admin
		 * asked for than every page on the site.
		 */
		if ( 'save' === $context && 'selected' === $settings['display_scope'] && empty( $settings['display_pages'] ) ) {
			$previous = get_option( 'plandose_settings', array() );
			$prev_scope = is_array( $previous ) && isset( $previous['display_scope'] ) ? (string) $previous['display_scope'] : '';
			$prev_pages = is_array( $previous ) && isset( $previous['display_pages'] ) && is_array( $previous['display_pages'] )
				? array_values( array_unique( array_filter( array_map( 'intval', $previous['display_pages'] ), static function ( $page_id ) {
					return $page_id > 0;
				} ) ) )
				: array();

			if ( in_array( $prev_scope, self::DISPLAY_SCOPES, true ) && ! ( 'selected' === $prev_scope && empty( $prev_pages ) ) ) {
				$settings['display_scope'] = $prev_scope;
				$settings['display_pages'] = $prev_pages;
			} else {
				$settings['display_scope'] = 'everywhere';
				$settings['display_pages'] = array();
			}

			$admin_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

			if ( $admin_id > 0 ) {
				set_transient( self::SCOPE_REFUSED_TRANSIENT . $admin_id, 1, 5 * MINUTE_IN_SECONDS );
			}
		}

		return $settings;
	}

	/**
	 * Restrict a URL setting to the current site (same host), or return
	 * $fallback. Empty/relative values (e.g. '/custom-login/') are always
	 * allowed and pass through esc_url_raw() unchanged.
	 *
	 * The custom login page setting is used verbatim as a redirect target
	 * for logged-out visitors (see Plandose_Frontend::login_url() and
	 * Plandose_Ajax::login_url()). Without this check, a malicious or
	 * compromised admin account could point every "login" link/redirect
	 * on the site at an external phishing page.
	 *
	 * The rule: refuse anything a browser
	 * might "repair" (whitespace, control characters, backslashes),
	 * normalize FIRST, validate the normalized string, and return exactly
	 * the string that was validated. Validating the RAW value and returning
	 * esc_url_raw() of it would return a different string from the one
	 * checked: esc_url_raw() silently deletes characters such as tabs and
	 * newlines, so "/\t/evil.com" would pass as a harmless path and come
	 * back as "//evil.com", a protocol-relative link to another site.
	 * "http:/evil.com" and "https:evil.com" have a scheme but no host, so a
	 * host check alone never runs, and browsers resolve both to evil.com.
	 *
	 * Accepted: a root-relative path ("/login/", "/wp-login.php?a=b"), or an
	 * absolute http/https URL on the home host and port. Everything else —
	 * other hosts, "//host", "/\host", scheme without host, user:pass@,
	 * javascript:/data:, bare "evil.com" — returns $fallback.
	 *
	 * @param mixed  $url      Candidate URL (normally the login_page setting).
	 * @param string $fallback Returned when $url is empty or not accepted.
	 * @return string
	 */
	public static function safe_internal_url( $url, $fallback = '' ) {
		if ( ! is_string( $url ) && ! is_numeric( $url ) ) {
			return $fallback;
		}

		// Outer whitespace only (a pasted value with a trailing space): what
		// is returned is this trimmed string, so trimming it cannot change
		// what gets validated below.
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return $fallback;
		}

		// Whitespace or control characters ANYWHERE inside: refuse rather
		// than strip. Browsers and esc_url_raw() both drop some of them
		// ("/\t/evil.com" becomes "//evil.com"), so any value containing
		// one does not mean what it looks like. The /u pattern also covers
		// Unicode spaces, bidi overrides and zero-width characters, and it
		// fails (returns false) on invalid UTF-8, which is refused too.
		if ( 1 === preg_match( '/[\x00-\x20\x7F]/', $url ) || 1 !== preg_match( '/^[^\p{Z}\p{C}]+$/u', $url ) ) {
			return $fallback;
		}

		// Browsers treat "\" as "/" in URLs ("/\evil.com" is "//evil.com").
		if ( false !== strpos( $url, '\\' ) ) {
			return $fallback;
		}

		// Normalize FIRST, then validate the result and return that same
		// string. esc_url_raw() returns '' for any other scheme
		// (javascript:, data:, ...) and turns a bare "evil.com" into
		// "http://evil.com", which the host check below then refuses.
		$normalized = esc_url_raw( $url, array( 'http', 'https' ) );

		if ( '' === $normalized || 1 === preg_match( '/[\x00-\x20\x7F\\\\]/', $normalized ) ) {
			return $fallback;
		}

		$parsed = wp_parse_url( $normalized );

		if ( ! is_array( $parsed ) ) {
			return $fallback;
		}

		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
			return $fallback;
		}

		if ( isset( $parsed['scheme'] ) || isset( $parsed['host'] ) ) {
			// Absolute URL: http/https, and a host that is exactly the
			// home host. A scheme without a host ("http:/evil.com",
			// "https:evil.com") fails here, as it must — browsers resolve
			// those to evil.com.
			if ( ! isset( $parsed['scheme'], $parsed['host'] ) || ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
				return $fallback;
			}

			// The string must literally begin "http://" or "https://", so
			// nothing wp_parse_url() tolerated can sit between the scheme
			// and the host.
			if ( 1 !== preg_match( '#^https?://[^/?\#]#i', $normalized ) ) {
				return $fallback;
			}

			$home_parts = wp_parse_url( home_url() );

			if ( empty( $home_parts['host'] ) || strtolower( $parsed['host'] ) !== strtolower( $home_parts['host'] ) ) {
				return $fallback;
			}

			$home_port = isset( $home_parts['port'] ) ? (int) $home_parts['port'] : null;
			$url_port  = isset( $parsed['port'] ) ? (int) $parsed['port'] : null;

			if ( $home_port !== $url_port ) {
				return $fallback;
			}
		} elseif ( '/' !== substr( $normalized, 0, 1 ) || '/' === substr( $normalized, 1, 1 ) ) {
			// Relative: exactly one leading "/" — not "//host" (protocol
			// relative) and not a relative path like "login/", which the
			// browser would resolve against whatever page it is on.
			return $fallback;
		}

		// Belt and braces: WordPress core's own redirect check must agree.
		// It never loosens the rules above (they already require the home
		// host); it only guards against a parsing quirk this code missed.
		if ( false === wp_validate_redirect( $normalized, false ) ) {
			return $fallback;
		}

		return $normalized;
	}

	/**
	 * Sanitize role arrays.
	 */
	private static function sanitize_roles_array( $roles ) {
		if ( ! is_array( $roles ) ) {
			return array();
		}

		$wp_roles = wp_roles();
		$valid    = is_object( $wp_roles ) ? array_keys( $wp_roles->roles ) : array();

		$roles = array_map( 'sanitize_key', $roles );
		$roles = array_values( array_unique( array_intersect( $valid, $roles ) ) );

		return $roles;
	}

	/**
	 * Get plugin settings.
	 *
	 * Called constantly — there are three dozen setting()/settings() call
	 * sites across the plugin, and setting() routes through here, so a
	 * single front-end request runs this many times over. Each unmemoized run
	 * would redo the whole of sanitize_settings(): wp_roles(), sanitize_hex_color(),
	 * several array_intersect/array_unique passes, sometimes wp_parse_url().
	 * All of it to produce the same answer as the call before it.
	 *
	 * The result is memoized against the raw option value it was
	 * derived from, rather than against a flag someone has to remember to
	 * clear. That distinction matters: an invalidation hook is one more
	 * thing to get wrong, and getting it wrong here would serve a stale
	 * access policy — settings() feeds access_evaluation(). Comparing the
	 * raw value instead means a change to the option is picked up on the
	 * very next call, by construction, whoever wrote it and however (the
	 * settings screen, another plugin's update_option(), a filter, WP-CLI).
	 *
	 * get_option() itself stays on the hot path deliberately. It is served
	 * from the options cache and costs almost nothing; skipping it is what
	 * would introduce staleness.
	 */
	public static function settings() {
		static $cached_raw       = null;
		static $cached_sanitized = null;

		$saved = get_option( 'plandose_settings', array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		if ( null !== $cached_sanitized && $saved === $cached_raw ) {
			return $cached_sanitized;
		}

		$cached_raw       = $saved;
		$cached_sanitized = self::sanitize_settings( wp_parse_args( $saved, self::default_settings() ), 'read' );

		return $cached_sanitized;
	}

	/**
	 * Return the value only if it is an exact calendar date in Y-m-d form,
	 * otherwise an empty string. Uses DateTime round-tripping so that
	 * impossible dates like 2024-02-31 are rejected rather than silently
	 * rolled over by strtotime().
	 *
	 * @param mixed $value Raw candidate value.
	 * @return string A valid 'Y-m-d' string, or '' if invalid.
	 */
	public static function sanitize_ymd( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		$date = DateTime::createFromFormat( 'Y-m-d', $value );

		if ( $date instanceof DateTime && $date->format( 'Y-m-d' ) === $value ) {
			return $value;
		}

		return '';
	}

	/**
	 * Get single setting.
	 */
	public static function setting( $key, $fallback = null ) {
		$settings = self::settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Free monthly print limit.
	 */
	public static function free_monthly_limit() {
		return max( 1, (int) self::setting( 'free_monthly_limit', self::DEFAULT_FREE_MONTHLY_LIMIT ) );
	}

	/**
	 * Pro print limit. 0 means unlimited.
	 */
	public static function pro_print_limit() {
		return max( 0, (int) self::setting( 'pro_print_limit', 0 ) );
	}

	/**
	 * Max plan days.
	 */
	public static function max_plan_days() {
		return max( 1, (int) self::setting( 'max_plan_days', self::DEFAULT_MAX_PLAN_DAYS ) );
	}

	/**
	 * Max days admin can add manually.
	 */
	public static function max_add_days() {
		return max( 1, (int) self::setting( 'max_add_days', self::DEFAULT_MAX_ADD_DAYS ) );
	}

	/**
	 * How many days ahead counts as "expiring soon".
	 *
	 * Used by the Λήγουν Σύντομα filter, the KPI counter and the highlight
	 * on each card, so all three always agree.
	 */
	public static function expiring_days() {
		return max( 1, min( 365, (int) self::setting( 'expiring_days', 7 ) ) );
	}

	/**
	 * Where the PlanDose button is allowed to appear.
	 *
	 * Keys are stored in the 'display_scope' setting; the labels are what
	 * the Settings screen shows.
	 *
	 * @return array<string,string>
	 */
	public static function display_scopes() {
		return array(
			'everywhere' => __( 'Σε όλες τις σελίδες', 'plandose' ),
			'front_page' => __( 'Μόνο στην αρχική σελίδα', 'plandose' ),
			'selected'   => __( 'Μόνο σε επιλεγμένες σελίδες', 'plandose' ),
		);
	}

	/**
	 * Whether the current request is a page the admin wants the tool on.
	 *
	 * Checked before any per-user rule, so on an excluded page nothing is
	 * printed and no PlanDose CSS/JS is enqueued at all. The default scope
	 * is 'everywhere' on purpose: an upgrade must not make the
	 * button disappear from a site that relies on it being global.
	 * Narrowing it is an explicit admin decision.
	 *
	 * @return bool
	 */
	public static function should_display_here() {
		$scope = (string) self::setting( 'display_scope', 'everywhere' );

		if ( ! in_array( $scope, self::DISPLAY_SCOPES, true ) ) {
			$scope = 'everywhere';
		}

		if ( 'everywhere' === $scope ) {
			return true;
		}

		if ( 'front_page' === $scope ) {
			/*
			 * is_front_page() alone is correct for both site layouts: it is
			 * true for the static page set as the front page, and also for
			 * the posts index when the site shows posts on the front page.
			 * is_home() must NOT be ORed in — on a site with a static front
			 * page and a separate posts page, is_home() is true on that
			 * posts page, so "only the front page" would have leaked the
			 * button onto the blog as well.
			 */
			return is_front_page();
		}

		// 'selected': the queried object must be one of the chosen pages.
		$pages = self::setting( 'display_pages', array() );

		// No page chosen means no page qualifies.
		if ( ! is_array( $pages ) || empty( $pages ) ) {
			return false;
		}

		$current_id = 0;

		if ( is_front_page() && 'page' === get_option( 'show_on_front' ) ) {
			$current_id = (int) get_option( 'page_on_front' );
		} elseif ( is_singular() ) {
			$current_id = (int) get_queried_object_id();
		} elseif ( is_home() && 'page' === get_option( 'show_on_front' ) ) {
			// The posts page chosen in Settings → Reading (it is
			// «is_home», never «is_singular»).
			$current_id = (int) get_option( 'page_for_posts' );
		} elseif ( function_exists( 'is_shop' ) && function_exists( 'wc_get_page_id' ) && is_shop() ) {
			// The WooCommerce shop page (a product archive).
			$current_id = (int) wc_get_page_id( 'shop' );
		}

		if ( $current_id < 1 ) {
			return false;
		}

		return in_array( $current_id, array_map( 'absint', $pages ), true );
	}

	/**
	 * Invoice max upload size in bytes.
	 */
	public static function invoice_max_bytes() {
		$mb = max( 1, (int) self::setting( 'invoice_max_mb', 5 ) );
		return $mb * 1024 * 1024;
	}

	/**
	 * Allowed invoice mime types.
	 */
	public static function allowed_invoice_mimes() {
		$valid = self::valid_invoice_mimes();
		$mimes = self::setting( 'invoice_mimes', $valid );

		if ( ! is_array( $mimes ) ) {
			$mimes = array();
		}

		$mimes = array_values( array_intersect( $valid, $mimes ) );

		return $mimes ? $mimes : array( 'application/pdf' );
	}

	/**
	 * Admin notice after a refused «selected pages» save (see
	 * sanitize_settings()). Shown once, to the admin who saved.
	 */
	public static function render_scope_refused_notice() {
		$admin_id = (int) get_current_user_id();

		if ( $admin_id < 1 || ! get_transient( self::SCOPE_REFUSED_TRANSIENT . $admin_id ) ) {
			return;
		}

		delete_transient( self::SCOPE_REFUSED_TRANSIENT . $admin_id );

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html__( 'PlanDose: η ρύθμιση «Μόνο σε επιλεγμένες σελίδες» δεν αποθηκεύτηκε, γιατί δεν επιλέχθηκε καμία σελίδα. Διατηρήθηκε η προηγούμενη ρύθμιση εμφάνισης. Επιλέξτε τουλάχιστον μία σελίδα και αποθηκεύστε ξανά.', 'plandose' )
		);
	}
}