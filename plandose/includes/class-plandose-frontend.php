<?php
/**
 * Renders the floating PlanDose icon and popup modal on the front end,
 * and enqueues its assets.
 *
 * Works with:
 * - assets/css/plandose-trigger.css
 * - assets/css/plandose.css
 * - assets/js/state.js
 * - assets/js/api.js
 * - assets/js/validation.js
 * - assets/js/preview.js
 * - assets/js/medicine-form.js
 * - assets/js/print-styles.js
 * - assets/js/print.js
 * - assets/js/pro-labels.js (Pro accounts only)
 * - assets/js/modal.js
 * - assets/js/app.js
 * - assets/js/plandose-loader.js (loads the files above on demand)
 * - includes/class-plandose-ajax.php
 * - includes/class-plandose-subscriptions.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Frontend {

	/**
	 * Register frontend hooks.
	 */
	/**
	 * Prevents the frontend hooks from being registered more than once.
	 * Defensive, matching the other PlanDose classes. The flag is set only
	 * after the dependency check, so a premature call (before
	 * Plandose_Subscriptions is loaded) can still register successfully later.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/** admin-ajax action of the dictionary script (serve_i18n()). */
	const I18N_ACTION = 'plandose_i18n';

	/** Option holding the dictionaries' content hashes (i18n_state()). */
	const I18N_OPTION = 'plandose_i18n_versions';

	/** How many fingerprints (locale/version/settings) I18N_OPTION keeps. */
	const I18N_KEEP = 6;

	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		if ( ! class_exists( 'Plandose_Subscriptions' ) ) {
			return;
		}

		self::$initialized = true;

		add_action( 'template_redirect', array( __CLASS__, 'maybe_send_nocache_headers' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );

		/*
		 * The deferral rewrites the stylesheet link to use an inline onload
		 * handler (see defer_main_stylesheet()). A site with a
		 * Content-Security-Policy that forbids inline event handlers will
		 * therefore never apply the stylesheet at all, and the modal opens
		 * unstyled — a broken tool traded for a few milliseconds of first
		 * paint. Registration is conditional so such a site can opt out
		 * with one line in a theme's functions.php:
		 *
		 *     add_filter( 'plandose_defer_stylesheet', '__return_false' );
		 *
		 * rather than having to edit this file and lose the change on the
		 * next plugin update.
		 *
		 * The filter is read inside defer_main_stylesheet(), when
		 * the tag is printed, not here — on
		 * 'plugins_loaded', before any theme's functions.php has loaded —
		 * or the documented one-line opt-out above would never take effect.
		 */
		add_filter( 'style_loader_tag', array( __CLASS__, 'defer_main_stylesheet' ), 10, 4 );
		add_action( 'wp_footer', array( __CLASS__, 'render_modal' ) );

		add_action( 'wp_ajax_' . self::I18N_ACTION, array( __CLASS__, 'serve_i18n' ) );
		add_action( 'wp_ajax_nopriv_' . self::I18N_ACTION, array( __CLASS__, 'serve_i18n' ) );
	}

	/**
	 * Whether every required frontend asset is present and readable. Cached
	 * per request. Used by should_render() so the modal is never printed when
	 * its CSS/JS could not be enqueued (e.g. an incomplete deployment), which
	 * would otherwise leave an unstyled, non-functional UI on the page.
	 *
	 * @return bool
	 */
	private static function assets_available( $for_guest = false ) {
		static $available = array();

		// Pro accounts need one more file (pro-labels.js), so they
		// are cached apart.
		$is_pro = ! $for_guest && self::current_user_is_pro();
		$key    = $for_guest ? 'guest' : ( $is_pro ? 'pro' : 'full' );

		if ( isset( $available[ $key ] ) ) {
			return $available[ $key ];
		}

		// Shell assets, needed by both bundles.
		$files = array(
			'assets/css/plandose-trigger.css',
			'assets/css/plandose-guest.css',
		);

		if ( $for_guest ) {
			$files[] = 'assets/js/plandose-guest.js';
		} else {
			$files = array_merge(
				$files,
				array(
					'assets/css/plandose.css',
					'assets/js/state.js',
					'assets/js/api.js',
					'assets/js/validation.js',
					'assets/js/preview.js',
					'assets/js/medicine-form.js',
					'assets/js/print-styles.js',
					// Enqueued for every pharmacy (print.js depends
					// on it); without it here an incomplete
					// deployment would show a tool whose print step breaks.
					'assets/js/calendar-qr.js',
					'assets/js/print.js',
					'assets/js/rx-text.js',
					'assets/js/rx-lines.js',
					'assets/js/rx-parse.js',
					'assets/js/rx-review.js',
					'assets/js/rx-import.js',
					'assets/js/modal.js',
					'assets/js/app.js',
					'assets/js/plandose-loader.js',
				)
			);

			// The Pro label module is sent to Pro accounts only (see
			// assets()), so only they need it: a missing pro-labels.js must
			// not hide the whole tool from Free pharmacies as well.
			if ( $is_pro ) {
				$files[] = 'assets/js/pro-labels.js';
			}
		}

		foreach ( $files as $relative_path ) {
			if ( ! is_readable( PLANDOSE_PATH . $relative_path ) ) {
				$available[ $key ] = false;

				return false;
			}
		}

		$available[ $key ] = true;

		return true;
	}

	/**
	 * Whether the current visitor may actually use the tool, as opposed to
	 * seeing the logged-out teaser. Computed once per request because
	 * should_render(), assets() and render_modal() all need the same answer
	 * and can_use_tool() hits user meta.
	 *
	 * @return bool
	 */
	private static function current_user_is_allowed() {
		static $cache = null;

		// Keyed by user id: a wp_set_current_user() later in the request
		// (a login form handled on the page, a test) must not reuse the
		// previous user's answer.
		$user_id = get_current_user_id();

		if ( null !== $cache && $cache['user_id'] === $user_id ) {
			return $cache['allowed'];
		}

		$cache = array(
			'user_id' => $user_id,
			'allowed' => $user_id > 0 && Plandose_Access::can_use_tool( $user_id ),
		);

		return $cache['allowed'];
	}

	/**
	 * Whether the current user has an active Pro subscription, read once
	 * per request and per user.
	 *
	 * should_render() runs on three hooks of every page, and assets() and
	 * render_modal() need the same answer; each call to
	 * Plandose_Subscriptions::user_is_pro() is a SELECT on the
	 * subscriptions table. The cache lives here and not in user_is_pro():
	 * the billing code relies on that one reading the row fresh.
	 *
	 * @return bool
	 */
	public static function current_user_is_pro() {
		static $cache = null;

		$user_id = get_current_user_id();

		if ( null !== $cache && $cache['user_id'] === $user_id ) {
			return $cache['pro'];
		}

		$cache = array(
			'user_id' => $user_id,
			'pro'     => $user_id > 0 && Plandose_Subscriptions::user_is_pro( $user_id ),
		);

		return $cache['pro'];
	}

	/**
	 * A page that carries the tool for a signed-in pharmacy (its
	 * AJAX nonce, Free/Pro state, print counter) must never be stored by a
	 * shared cache and served to someone else. Sending no-cache headers is
	 * a safety net on top of the server's own "skip the cache for
	 * logged-in cookies" rule (Nginx FastCGI cache, Bunny CDN), not a
	 * replacement for it: a cache configured to ignore Cache-Control
	 * still has to exclude logged-in visitors itself. DONOTCACHEPAGE is
	 * the convention page-cache plugins (WP Rocket, W3TC, …) honour.
	 *
	 * Guests are left alone: their teaser is the same for everyone and
	 * may be cached.
	 */
	public static function maybe_send_nocache_headers() {
		if ( is_admin() || headers_sent() || ! is_user_logged_in() ) {
			return;
		}

		if ( ! self::current_user_is_allowed() || ! self::should_render() ) {
			return;
		}

		nocache_headers();

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * Decide if the PlanDose button/modal should render.
	 *
	 * Rules:
	 * - If required assets are missing/unreadable, render nothing.
	 * - If plugin is disabled, render nothing.
	 * - Guests can see it only if show_to_guests is enabled.
	 * - Logged-in users see it only if they can use the tool.
	 */
	private static function should_render() {
		$settings = Plandose_Settings::settings();

		if ( empty( $settings['enabled'] ) ) {
			return false;
		}

		// Page targeting is checked before the per-user rules: on a page the
		// admin has excluded, nobody gets the button and no assets load.
		if ( ! Plandose_Settings::should_display_here() ) {
			return false;
		}

		$is_allowed = self::current_user_is_allowed();

		if ( ! $is_allowed ) {
			if ( is_user_logged_in() || empty( $settings['show_to_guests'] ) ) {
				return false;
			}
		}

		// Only the bundle this visitor will actually be served has to be
		// present: a missing tool module must not blank the guest teaser,
		// and vice versa.
		return self::assets_available( ! $is_allowed );
	}

	/**
	 * Return current frontend URL.
	 */
	private static function current_url() {
		// NOT sanitize_text_field(): it deletes every %XX octet, so a
		// Greek slug (/%ce%b1%cf%80…) would come back as a different, broken path
		// and the post-login redirect would land on a 404. The value is only
		// ever used as a same-site URL, and it is validated as one below
		// (control characters out, path-only, esc_url_raw on the full URL
		// built from home_url's host, then wp_validate_redirect).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as a same-site URL below.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

		$request_uri = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $request_uri );

		// Path only: must start with one '/', never '//' or '/\' (both are
		// read by browsers as a protocol-relative URL to another host).
		if ( '' === $request_uri || '/' !== $request_uri[0] || 0 === strpos( $request_uri, '//' ) || 0 === strpos( $request_uri, '/\\' ) ) {
			$request_uri = '/';
		}

		// Build the absolute URL from the stored home URL's scheme/host/port
		// plus the real request path. Using home_url( $request_uri ) directly
		// would prepend the home path a second time on subdirectory installs
		// (home https://example.com/wp + REQUEST_URI /wp/x -> .../wp/wp/x),
		// producing a broken post-login redirect_to. The host comes from the
		// stored home option, not the untrusted HTTP_HOST header.
		$home = wp_parse_url( home_url( '/' ) );

		if ( ! is_array( $home ) || empty( $home['scheme'] ) || empty( $home['host'] ) ) {
			return home_url( '/' );
		}

		$origin = $home['scheme'] . '://' . $home['host'];

		if ( ! empty( $home['port'] ) ) {
			$origin .= ':' . absint( $home['port'] );
		}

		$current_url = esc_url_raw( $origin . $request_uri );

		return wp_validate_redirect( $current_url, home_url( '/' ) );
	}

	/**
	 * Return login URL.
	 */
	private static function login_url() {
		$current_url = self::current_url();
		$custom      = Plandose_Settings::setting( 'login_page', '' );

		if ( ! empty( $custom ) ) {
			$custom = Plandose_Settings::safe_internal_url( $custom, '' );

			if ( '' !== $custom ) {
				// Encoded, like wp_login_url() does — a raw '&' or
				// '#' in the current URL would cut redirect_to short.
				return esc_url_raw(
					add_query_arg(
						'redirect_to',
						rawurlencode( $current_url ),
						$custom
					)
				);
			}
		}

		return esc_url_raw( wp_login_url( $current_url ) );
	}

	/**
	 * Get safe button position.
	 */
	private static function button_position() {
		$position = Plandose_Settings::setting( 'button_position', 'bottom_right' );
		$allowed  = array( 'bottom_right', 'bottom_left', 'top_right', 'top_left' );

		return in_array( $position, $allowed, true ) ? $position : 'bottom_right';
	}

	/**
	 * Get safe button color.
	 */
	private static function button_color() {
		$color = Plandose_Settings::setting( 'button_color', '#0f6e56' );
		$color = sanitize_hex_color( $color );

		return $color ? $color : '#0f6e56';
	}

	/**
	 * Load the large PlanDose stylesheet without blocking first paint.
	 *
	 * The small trigger stylesheet remains render-blocking so the floating
	 * button is styled immediately; this one only styles the modal, which
	 * nobody sees until they click.
	 *
	 * The rewrite preserves two attributes. `id` is what wp_dequeue_style()
	 * and any script looking the tag up by handle rely on. `media` is worse
	 * to lose: a stylesheet enqueued for `screen` would come back out
	 * applying everywhere, print included, which is the opposite of what the
	 * caller asked for. Both are carried
	 * through to the real stylesheet link and to the <noscript> fallback.
	 *
	 * KNOWN LIMITATION: the rel-swap runs from an inline onload handler, so
	 * a site with a Content-Security-Policy that forbids inline event
	 * handlers will never apply this stylesheet — the modal would open
	 * unstyled. The <noscript> copy does not help there, since scripting is
	 * enabled, just constrained. If that describes the site, switch the
	 * deferral off with the filter registered in init():
	 *
	 *     add_filter( 'plandose_defer_stylesheet', '__return_false' );
	 *
	 * and the stylesheet simply loads normally.
	 *
	 * @param string $html   The link tag HTML for the enqueued style.
	 * @param string $handle The style's registered handle.
	 * @param string $href   The stylesheet URL.
	 * @param string $media  The stylesheet media attribute.
	 * @return string
	 */
	public static function defer_main_stylesheet( $html, $handle, $href, $media ) {
		if ( 'plandose' !== $handle ) {
			return $html;
		}

		// Read here, late enough for a theme's functions.php (see init()).
		if ( ! apply_filters( 'plandose_defer_stylesheet', true ) ) {
			return $html;
		}

		$href  = esc_url( $href );
		$media = '' !== (string) $media ? (string) $media : 'all';
		$id    = $handle . '-css';

		/*
		 * The id goes on the preload link too, not only on the <noscript>
		 * copy. The browser treats the <noscript> fallback as inert
		 * text whenever scripting is on, i.e. in every case that matters,
		 * so without it the element that actually becomes the stylesheet
		 * would have no id at all, and anything looking it up by handle
		 * (wp_dequeue_style()'s generated id, a theme's own script, a
		 * page-builder preview) would silently find nothing.
		 *
		 * Only one of the two elements ever exists in the DOM at a time, so
		 * repeating the id across them cannot produce a duplicate.
		 */
		return sprintf(
			'<link rel="preload" id="%3$s" href="%1$s" as="style" media="%2$s" onload="this.onload=null;this.rel=\'stylesheet\'">' .
			'<noscript><link rel="stylesheet" id="%3$s" href="%1$s" media="%2$s"></noscript>',
			$href,
			esc_attr( $media ),
			esc_attr( $id )
		);
	}

	/**
	 * Enqueue frontend assets and localize JS config.
	 */
	public static function assets() {
		if ( ! self::should_render() ) {
			return;
		}

		// The floating button and the popup shell are the same for everyone.
		wp_enqueue_style(
			'plandose-trigger',
			PLANDOSE_URL . 'assets/css/plandose-trigger.css',
			array(),
			self::asset_version( 'assets/css/plandose-trigger.css' )
		);

		wp_enqueue_style(
			'plandose-guest',
			PLANDOSE_URL . 'assets/css/plandose-guest.css',
			array( 'plandose-trigger' ),
			self::asset_version( 'assets/css/plandose-guest.css' )
		);

		/*
		 * A visitor who is not a registered pharmacist can only ever see the
		 * login teaser, whose markup render_modal() already prints. Serving
		 * them one small script instead of the nine tool modules saves about
		 * 85 KB per page view, and skipping wp_localize_script() entirely
		 * means no nonce and no dictionaries are baked into HTML that a page
		 * cache may store and reuse.
		 */
		if ( ! self::current_user_is_allowed() ) {
			wp_enqueue_script(
				'plandose-guest',
				PLANDOSE_URL . 'assets/js/plandose-guest.js',
				array(),
				self::asset_version( 'assets/js/plandose-guest.js' ),
				true
			);

			wp_script_add_data( 'plandose-guest', 'strategy', 'defer' );

			return;
		}

		$script_files = array(
			'plandose-state'         => 'assets/js/state.js',
			'plandose-api'           => 'assets/js/api.js',
			'plandose-validation'    => 'assets/js/validation.js',
			'plandose-preview'       => 'assets/js/preview.js',
			'plandose-medicine-form' => 'assets/js/medicine-form.js',
			'plandose-print-styles'  => 'assets/js/print-styles.js',
			// «Υπενθυμίσεις στο κινητό» QR (bundles its own QR encoder).
			'plandose-calendar-qr'   => 'assets/js/calendar-qr.js',
			'plandose-print'         => 'assets/js/print.js',
			// «Επικόλληση συνταγής», for every pharmacy (not Pro-only).
			// Five files, in this order (see the top of rx-text.js).
			'plandose-rx-text'       => 'assets/js/rx-text.js',
			'plandose-rx-lines'      => 'assets/js/rx-lines.js',
			'plandose-rx-parse'      => 'assets/js/rx-parse.js',
			'plandose-rx-review'     => 'assets/js/rx-review.js',
			'plandose-rx-import'     => 'assets/js/rx-import.js',
			'plandose-modal'         => 'assets/js/modal.js',
			'plandose-app'           => 'assets/js/app.js',
		);

		/*
		 * The Pro label module is sent to Pro accounts ONLY, not shipped to
		 * every pharmacist and merely hidden, where flipping
		 * PlandoseConfig.isPro in the browser console would unlock it. A Free
		 * account never receives it. Not an absolute barrier (the file
		 * is public and runs in the browser), but not a one-line
		 * console change.
		 */
		$is_pro_account = self::current_user_is_pro();

		if ( $is_pro_account ) {
			$script_files['plandose-pro-labels'] = 'assets/js/pro-labels.js';
		}

		// The dictionaries are separate, long-cached scripts (see
		// serve_i18n()); the English one only for Pro.
		$i18n = self::i18n_scripts( $is_pro_account );

		// Readability of every asset is already guaranteed here by
		// should_render() -> assets_available() (called at the top of this
		// method), so per-file is_readable() guards here
		// would be redundant.

		/*
		 * The tool is loaded on demand. The page gets only
		 * plandose-loader.js (≈6 KB, mostly comments); the stylesheet and the modules below
		 * are fetched when the pointer reaches the button or on the first
		 * click — see assets/js/plandose-loader.js. A site that needs
		 * everything loaded with the page can switch it off:
		 *
		 *     add_filter( 'plandose_lazy_load', '__return_false' );
		 */
		$lazy = (bool) apply_filters( 'plandose_lazy_load', true );

		if ( $lazy ) {
			wp_enqueue_script(
				'plandose-loader',
				PLANDOSE_URL . 'assets/js/plandose-loader.js',
				array(),
				self::asset_version( 'assets/js/plandose-loader.js' ),
				true
			);

			wp_script_add_data( 'plandose-loader', 'strategy', 'defer' );
		} else {
			// plandose.css does not carry the :root variables or the popup
			// shell — those live in plandose-guest.css — so it must
			// load after it.
			wp_enqueue_style(
				'plandose',
				PLANDOSE_URL . 'assets/css/plandose.css',
				array( 'plandose-trigger', 'plandose-guest' ),
				self::asset_version( 'assets/css/plandose.css' )
			);

			// The dictionaries are enqueued without ?ver=: their URL is
			// already versioned by content. They are not fatal: if one
			// fails, state.js still runs and PD.txt() falls back to Greek.
			foreach ( $i18n['scripts'] as $i18n_handle => $i18n_src ) {
				wp_enqueue_script( $i18n_handle, $i18n_src, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The URL carries a content hash.
			}

			wp_enqueue_script(
				'plandose-state',
				PLANDOSE_URL . $script_files['plandose-state'],
				array_keys( $i18n['scripts'] ),
				self::asset_version( $script_files['plandose-state'] ),
				true
			);

			wp_enqueue_script(
				'plandose-api',
				PLANDOSE_URL . $script_files['plandose-api'],
				array( 'plandose-state' ),
				self::asset_version( $script_files['plandose-api'] ),
				true
			);

			wp_enqueue_script(
				'plandose-validation',
				PLANDOSE_URL . $script_files['plandose-validation'],
				array( 'plandose-state', 'plandose-api' ),
				self::asset_version( $script_files['plandose-validation'] ),
				true
			);

			wp_enqueue_script(
				'plandose-preview',
				PLANDOSE_URL . $script_files['plandose-preview'],
				array( 'plandose-state' ),
				self::asset_version( $script_files['plandose-preview'] ),
				true
			);

			wp_enqueue_script(
				'plandose-medicine-form',
				PLANDOSE_URL . $script_files['plandose-medicine-form'],
				array( 'plandose-state', 'plandose-validation', 'plandose-preview' ),
				self::asset_version( $script_files['plandose-medicine-form'] ),
				true
			);

			wp_enqueue_script(
				'plandose-print-styles',
				PLANDOSE_URL . $script_files['plandose-print-styles'],
				array( 'plandose-state' ),
				self::asset_version( $script_files['plandose-print-styles'] ),
				true
			);

			wp_enqueue_script(
				'plandose-calendar-qr',
				PLANDOSE_URL . $script_files['plandose-calendar-qr'],
				array( 'plandose-state', 'plandose-preview' ),
				self::asset_version( $script_files['plandose-calendar-qr'] ),
				true
			);

			wp_enqueue_script(
				'plandose-print',
				PLANDOSE_URL . $script_files['plandose-print'],
				array(
					'plandose-state',
					'plandose-api',
					'plandose-preview',
					'plandose-medicine-form',
					'plandose-print-styles',
					'plandose-calendar-qr',
				),
				self::asset_version( $script_files['plandose-print'] ),
				true
			);

			if ( $is_pro_account ) {
				wp_enqueue_script(
					'plandose-pro-labels',
					PLANDOSE_URL . $script_files['plandose-pro-labels'],
					array(
						'plandose-state',
						'plandose-preview',
						'plandose-medicine-form',
						'plandose-print',
					),
					self::asset_version( $script_files['plandose-pro-labels'] ),
					true
				);
			}

			wp_enqueue_script(
				'plandose-rx-text',
				PLANDOSE_URL . $script_files['plandose-rx-text'],
				array( 'plandose-state' ),
				self::asset_version( $script_files['plandose-rx-text'] ),
				true
			);

			wp_enqueue_script(
				'plandose-rx-lines',
				PLANDOSE_URL . $script_files['plandose-rx-lines'],
				array( 'plandose-rx-text' ),
				self::asset_version( $script_files['plandose-rx-lines'] ),
				true
			);

			wp_enqueue_script(
				'plandose-rx-parse',
				PLANDOSE_URL . $script_files['plandose-rx-parse'],
				array( 'plandose-state', 'plandose-validation', 'plandose-rx-lines' ),
				self::asset_version( $script_files['plandose-rx-parse'] ),
				true
			);

			wp_enqueue_script(
				'plandose-rx-review',
				PLANDOSE_URL . $script_files['plandose-rx-review'],
				array(
					'plandose-state',
					'plandose-validation',
					'plandose-preview',
					'plandose-medicine-form',
					'plandose-rx-parse',
				),
				self::asset_version( $script_files['plandose-rx-review'] ),
				true
			);

			wp_enqueue_script(
				'plandose-rx-import',
				PLANDOSE_URL . $script_files['plandose-rx-import'],
				array(
					'plandose-state',
					'plandose-validation',
					'plandose-preview',
					'plandose-medicine-form',
					'plandose-rx-review',
				),
				self::asset_version( $script_files['plandose-rx-import'] ),
				true
			);

			wp_enqueue_script(
				'plandose-modal',
				PLANDOSE_URL . $script_files['plandose-modal'],
				array(
					'plandose-state',
					'plandose-api',
					'plandose-preview',
					'plandose-medicine-form',
					'plandose-print',
				),
				self::asset_version( $script_files['plandose-modal'] ),
				true
			);

			wp_enqueue_script(
				'plandose-app',
				PLANDOSE_URL . $script_files['plandose-app'],
				array_merge(
					array(
						'plandose-state',
						'plandose-api',
						'plandose-validation',
						'plandose-preview',
						'plandose-medicine-form',
						'plandose-print-styles',
						'plandose-print',
						'plandose-rx-import',
						'plandose-modal',
					),
					// app.js runs the bootstrap, so it must come after the Pro
					// module whenever that module is sent.
					$is_pro_account ? array( 'plandose-pro-labels' ) : array()
				),
				self::asset_version( $script_files['plandose-app'] ),
				true
			);

			// The plugin requires WordPress 6.3+, where the 'defer' strategy is always honoured.
			foreach ( array_merge( array_keys( $i18n['scripts'] ), array_keys( $script_files ) ) as $handle ) {
				wp_script_add_data( $handle, 'strategy', 'defer' );
			}
		}

		$is_allowed     = self::current_user_is_allowed();
		$max_days       = Plandose_Settings::max_plan_days();
		$is_pro         = $is_allowed && $is_pro_account;

		$config = array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'plandose_nonce' ),
			'isAllowed'        => $is_allowed,
			'isPro'            => $is_pro,
			'maxDays'          => $max_days,
			// No 'freeMonthlyLimit' here: the guest teaser prints the limit server-side
			// (render_modal()), and the tool shows the server's own
			// remaining count from check_print.
			'buttonPosition'   => self::button_position(),
			'buttonColor'      => self::button_color(),
			'showPrintCounter' => (bool) Plandose_Settings::setting( 'show_print_counter', 1 ),
			'labelOrientation' => (string) Plandose_Settings::setting( 'label_orientation', 'landscape' ),
			// Shown in «Εκτύπωση ξανά — χωρίς νέα χρέωση, έως N φορές».
			'maxFreeReprints'  => Plandose_Ajax::MAX_FREE_REPRINTS,
			// The free-reprint window, for the billing status line.
			'reprintWindowMinutes' => self::reprint_window_minutes(),
			// The patient page of the «Υπενθυμίσεις στο κινητό» QR, '' = off.
			'calendarUrl'      => self::calendar_url(),
			/*
			 * Only the pharmacy's own printed texts (disclaimer, thanks
			 * lines) stay inline, so a sheet printed while the dictionary
			 * script failed to load still carries them. The dictionaries
			 * themselves are the i18n_scripts() URLs; state.js merges this
			 * over them.
			 */
			'i18n'             => $i18n['site'],
		);

		if ( ! $lazy ) {
			self::add_config_script( 'plandose-state', $config );

			return;
		}

		// Same data, printed before the loader instead: state.js reads
		// window.PlandoseConfig whenever it runs.
		self::add_config_script( 'plandose-loader', $config );

		// The modules in the order they depend on each other — the same
		// order the wp_enqueue_script() dependencies above produce. The
		// Pro label module goes to Pro accounts only, exactly as before.
		$ordered = array(
			'plandose-state',
			'plandose-api',
			'plandose-validation',
			'plandose-preview',
			'plandose-medicine-form',
			'plandose-print-styles',
			'plandose-calendar-qr',
			'plandose-print',
		);

		if ( $is_pro_account ) {
			$ordered[] = 'plandose-pro-labels';
		}

		$ordered[] = 'plandose-rx-text';
		$ordered[] = 'plandose-rx-lines';
		$ordered[] = 'plandose-rx-parse';
		$ordered[] = 'plandose-rx-review';
		$ordered[] = 'plandose-rx-import';
		$ordered[] = 'plandose-modal';
		$ordered[] = 'plandose-app';

		$script_urls = array();

		foreach ( $ordered as $handle ) {
			$script_urls[] = self::lazy_asset_url( $script_files[ $handle ], $handle, 'script' );
		}

		$i18n_urls = array();

		foreach ( $i18n['scripts'] as $handle => $src ) {
			$i18n_urls[] = esc_url_raw( (string) apply_filters( 'script_loader_src', $src, $handle ) );
		}

		wp_localize_script(
			'plandose-loader',
			'PlandoseLoader',
			array(
				'style'   => self::lazy_asset_url( 'assets/css/plandose.css', 'plandose', 'style' ),
				// Run before the modules; a failure here is not fatal.
				'i18n'    => $i18n_urls,
				'scripts' => $script_urls,
				'loading' => __( 'Φόρτωση του PlanDose…', 'plandose' ),
				'failed'  => __( 'Δεν ήταν δυνατή η φόρτωση του PlanDose. Ελέγξτε τη σύνδεσή σας και ανανεώστε τη σελίδα.', 'plandose' ),
			)
		);
	}

	/**
	 * The Greek front-end dictionary, under 'i18n' (the English one is
	 * build_i18n_en()).
	 *
	 * Built only for the dictionary script (serve_i18n()) and, once per
	 * version/locale/settings, to hash it (i18n_state()) — not on
	 * every page.
	 *
	 * @param int $max_days Maximum allowed plan duration in days.
	 * @return array{i18n: array<string,mixed>}
	 */
	private static function build_i18n( $max_days ) {
		$dictionaries = array(
			'i18n'             => array(
				'modalTitle'          => __( 'Δημιουργία Πλάνου Δοσολογίας', 'plandose' ),
				'modalSubtitle'       => __( 'Συμπληρώστε τα φάρμακα του ασθενή και εκτυπώστε ένα καθαρό πλάνο υπενθύμισης.', 'plandose' ),

				'stepPatient'         => __( 'Στοιχεία & Φάρμακα', 'plandose' ),
				'stepPreview'         => __( 'Προεπισκόπηση', 'plandose' ),
				'stepsAria'           => __( 'Βήματα PlanDose', 'plandose' ),
				'back'                => __( 'Πίσω', 'plandose' ),
				'preview'             => __( 'Προεπισκόπηση', 'plandose' ),

				'patientSection'      => __( 'Στοιχεία Ασθενή', 'plandose' ),
				'drugSection'         => __( 'Φάρμακα', 'plandose' ),
				'patient'             => __( 'Ασθενής (προαιρετικό)', 'plandose' ),
				// «Επικόλληση συνταγής» (assets/js/rx-import.js).
				'rxOpen'               => __( 'Επικόλληση συνταγής', 'plandose' ),
				/* translators: short badge next to a new feature. */
				'rxNew'                => __( 'ΝΕΟ', 'plandose' ),
				/* translators: keep «Ctrl+V» as written (⌘ on a Mac). */
				'rxDropPlaceholder'    => __( 'Πατήστε Ctrl+V για να επικολλήσετε τη συνταγή — διαβάζεται αμέσως.', 'plandose' ),
				'rxDropPlaceholderTouch' => __( 'Πατήστε παρατεταμένα εδώ και επιλέξτε «Επικόλληση» — η συνταγή διαβάζεται αμέσως.', 'plandose' ),
				'rxDropTitle'          => __( 'Επικολλήστε εδώ ολόκληρη την ηλεκτρονική συνταγή', 'plandose' ),
				/* translators: keep «Ctrl+A», «Ctrl+C», «Ctrl+V» as written: they are drawn as keys. */
				'rxDropHelp'           => __( 'Αντιγράψτε όλο το κείμενο της συνταγής (Ctrl+A → Ctrl+C), κάντε κλικ εδώ και πατήστε Ctrl+V. Φάρμακα, δοσολογία και ημέρες συμπληρώνονται αυτόματα.', 'plandose' ),
				'rxPlaceholder'        => __( 'Επικολλήστε εδώ όλο το κείμενο της ηλεκτρονικής συνταγής…', 'plandose' ),
				'rxPrivacy'            => __( 'Το κείμενο διαβάζεται μόνο σε αυτόν τον browser — δεν στέλνεται και δεν αποθηκεύεται.', 'plandose' ),
				'rxRead'               => __( 'Ανάγνωση', 'plandose' ),
				'rxCancel'             => __( 'Άκυρο', 'plandose' ),
				'rxNone'               => __( 'Δεν βρέθηκαν φάρμακα σε αυτό το κείμενο — αντιγράψτε ολόκληρη τη συνταγή και ξαναδοκιμάστε.', 'plandose' ),
				/* translators: %d: number of medicines found */
				'rxFound'              => __( 'Βρέθηκαν %d φάρμακα. Ελέγξτε τα πριν μπουν στο πλάνο.', 'plandose' ),
				/* translators: %s: patient name from the prescription */
				'rxPatient'            => __( 'Όνομα ασθενή: %s', 'plandose' ),
				/* translators: %s: the dosage line as written on the prescription */
				'rxSource'             => __( 'Συνταγή: %s', 'plandose' ),
				/* translators: %s: number of days */
				'rxDays'               => __( '%s ημέρες', 'plandose' ),
				'rxTimeLabel'          => __( 'Ώρα λήψης', 'plandose' ),
				/* translators: %d: number of medicines to add */
				'rxAdd'                => __( 'Προσθήκη στο πλάνο (%d)', 'plandose' ),
				'rxToForm'             => __( 'Συμπλήρωση στη φόρμα', 'plandose' ),
				'rxInvalid'            => __( 'Δεν μπορεί να μπει όπως είναι — συμπληρώστε το στη φόρμα.', 'plandose' ),
				/* translators: %d: number of medicines added */
				'rxAdded'              => __( 'Προστέθηκαν %d φάρμακα από τη συνταγή. Ελέγξτε το πλάνο πριν την εκτύπωση.', 'plandose' ),
				/* translators: %d: number of medicines left */
				'rxLeft'               => __( 'Μένουν %d για συμπλήρωση στη φόρμα.', 'plandose' ),
				'rxWarn_name'          => __( 'Ελέγξτε το όνομα του φαρμάκου.', 'plandose' ),
				'rxWarn_dose'          => __( 'Η δοσολογία δεν διαβάστηκε — συμπληρώστε τη.', 'plandose' ),
				'rxWarn_amount'        => __( 'Η ποσότητα δεν είναι έγκυρη — συμπληρώστε τη.', 'plandose' ),
				'rxWarn_unit'          => __( 'Επιλέξτε «Είδος».', 'plandose' ),
				'rxWarn_iuConfirm'     => __( 'Ινσουλίνη: η ποσότητα διαβάστηκε ως μονάδες (IU) — επιβεβαιώστε τη στη φόρμα.', 'plandose' ),
				'rxWarn_injectionQty'  => __( 'Η ποσότητα δεν ταιριάζει με ενέσεις — ελέγξτε μονάδες και «Είδος».', 'plandose' ),
				'rxWarn_freq'          => __( 'Η συχνότητα δεν διαβάστηκε — συμπληρώστε τη.', 'plandose' ),
				'rxWarn_freqWeekly'    => __( 'Εβδομαδιαία δοσολογία που δεν διαβάστηκε — συμπληρώστε τη.', 'plandose' ),
				'rxWarn_onceDays'      => __( 'Εφάπαξ, αλλά για περισσότερες ημέρες — ελέγξτε τη διάρκεια.', 'plandose' ),
				'rxWarn_phrase'        => __( 'Η δοσολογία έχει λέξεις ή αριθμούς που δεν αναγνωρίστηκαν — ελέγξτε ποσότητα και «Είδος».', 'plandose' ),
				'rxWarn_unitsOrInjection' => __( 'Ισχύς σε μονάδες/ml: ελέγξτε αν η ποσότητα είναι ενέσεις ή μονάδες (IU).', 'plandose' ),
				'rxWarn_weekDay'       => __( 'Η πρώτη δόση μπαίνει την ημέρα έναρξης του πλάνου — ελέγξτε ότι είναι η σωστή ημέρα.', 'plandose' ),
				'rxWarn_time'          => __( 'Η συνταγή δεν ορίζει ώρα — επιλέξτε την.', 'plandose' ),
				'rxWarn_methotrexateDaily' => __( 'ΠΡΟΣΟΧΗ: η μεθοτρεξάτη χορηγείται συνήθως μία φορά την εβδομάδα. Επιβεβαιώστε τη συχνότητα.', 'plandose' ),
				'rxWarn_extra'         => __( 'Η δοσολογία έχει κι άλλο κείμενο που δεν διαβάστηκε — ελέγξτε τη συνταγή.', 'plandose' ),
				'rxWarn_intervalCount' => __( 'Οι ημέρες δεν είναι ακέραιος αριθμός εβδομάδων/διαστημάτων: στο πλάνο μπαίνει μία δόση ακόμη (π.χ. 30 ημέρες εβδομαδιαία = 5 δόσεις). Ελέγξτε τη διάρκεια με όσα χορηγήθηκαν.', 'plandose' ),
				'rxWarn_monthly'      => __( '«1 φορά τον μήνα» μπήκε ως «κάθε 30 ημέρες» — ελέγξτε τις ημερομηνίες.', 'plandose' ),
				// Last check before printing.
				/* translators: %s: medicine name */
				'invalidItemForPrint' => __( 'Ελέγξτε το φάρμακο «%s»: η ποσότητα, το «Είδος», η συχνότητα ή η διάρκεια δεν είναι έγκυρα. Πατήστε «Επεξεργασία».', 'plandose' ),
				'methotrexatePrintConfirm' => __( 'ΠΡΟΣΟΧΗ: μεθοτρεξάτη συχνότερα από μία φορά την εβδομάδα. Αν η συχνότητα είναι σωστή, πατήστε ξανά «Εκτύπωση».', 'plandose' ),
				'rxWarn_duplicate'     => __( 'Υπάρχει ήδη πιο πάνω — δεν επιλέχθηκε.', 'plandose' ),
				'rxWarn_inPlan'        => __( 'Υπάρχει ήδη στο πλάνο — δεν επιλέχθηκε.', 'plandose' ),
				'rxLoaded'             => __( 'Φορτώθηκε στη φόρμα — ολοκληρώστε το εκεί.', 'plandose' ),
				/* translators: %s: patient names, comma-separated. */
				'rxManyPatients'       => __( 'Το κείμενο έχει συνταγές για διαφορετικούς ασθενείς (%s). Ελέγξτε ότι όλα τα φάρμακα είναι του ίδιου ασθενή.', 'plandose' ),
				/* translators: 1: patient already in the plan, 2: patient on the prescription. */
				'rxOtherPatient'       => __( 'Στο πλάνο είναι ήδη ο ασθενής «%1$s» — η συνταγή είναι για «%2$s».', 'plandose' ),
				'rxPickUnit'           => __( 'Επιλέξτε «Είδος».', 'plandose' ),
				'rxPickFreq'           => __( 'Επιλέξτε συχνότητα λήψης — η συνταγή δεν τη διάβασε με βεβαιότητα.', 'plandose' ),

				'patientHelp'         => __( 'Προαιρετικό πεδίο. Θα εμφανιστεί στην εκτύπωση.', 'plandose' ),
				'patientPlaceholder'  => __( 'π.χ. Ιωάννης Παπαδόπουλος', 'plandose' ),

				/* Plan start date. */
				'startDate'           => __( 'Ημερομηνία έναρξης', 'plandose' ),
				'startDateHelp'       => __( 'Από ποια ημέρα ξεκινά το πλάνο.', 'plandose' ),
				'startToday'          => __( 'Σήμερα', 'plandose' ),
				'startTomorrow'       => __( 'Αύριο', 'plandose' ),
				'invalidStartDate'    => __( 'Η ημερομηνία έναρξης δεν είναι έγκυρη.', 'plandose' ),
				'startDateInPast'     => __( 'Η ημερομηνία έναρξης έχει περάσει. Επιλέξτε σήμερα ή μεταγενέστερη ημέρα.', 'plandose' ),
				/* translators: %d: maximum number of days ahead the plan may start. */
				'startDateTooFar'     => __( 'Η ημερομηνία έναρξης δεν μπορεί να απέχει πάνω από %d ημέρες από σήμερα.', 'plandose' ),
				'planStartsOn'        => __( 'Έναρξη πλάνου:', 'plandose' ),

				/* First-dose daypart on the start day. */
				'firstSlotLabel'      => __( 'Πρώτη δόση την ημέρα έναρξης', 'plandose' ),
				'firstSlotHelp'       => __( 'Οι δόσεις που έχουν ήδη περάσει μεταφέρονται στο τέλος — το σύνολο δόσεων δεν αλλάζει.', 'plandose' ),
				/* translators: 1: daypart of the first dose, 2: its day, 3: daypart of the last dose, 4: its day. */
				'courseLine'          => __( 'Πρώτη δόση: %1$s %2$s • Τελευταία δόση: %3$s %4$s', 'plandose' ),

				'drug'                => __( 'Όνομα φαρμάκου', 'plandose' ),
				'drugPlaceholder'     => __( 'π.χ. Depon 500mg', 'plandose' ),
				'doseAmount'          => __( 'Ποσότητα', 'plandose' ),
				'doseUnit'            => __( 'Είδος', 'plandose' ),

				'unitTablet'          => __( 'Δισκίο(α)', 'plandose' ),
				'unitCapsule'         => __( 'Κάψουλα(ες)', 'plandose' ),
				'unitMl'              => __( 'ml', 'plandose' ),
				'unitMg'              => __( 'mg', 'plandose' ),
				'unitDrops'           => __( 'Σταγόνα(ες)', 'plandose' ),
				'unitAmpoule'         => __( 'Αμπούλα(ες)', 'plandose' ),
				'unitSachet'          => __( 'Φακελίσκος(οι)', 'plandose' ),
				'unitInhale'          => __( 'Εισπνοή(ες)', 'plandose' ),
				/* Creams, ointments, gels. */
				'unitApplication'     => __( 'Επάλειψη(εις)', 'plandose' ),
				/* Nasal / oral sprays. */
				'unitSpray'           => __( 'Ψεκασμός(οι)', 'plandose' ),
				'unitSuppository'     => __( 'Υπόθετο(α)', 'plandose' ),
				'unitPatch'           => __( 'Έμπλαστρο(α)', 'plandose' ),
				'unitInjection'       => __( 'Ένεση(εις)', 'plandose' ),
				'unitIu'              => __( 'Μονάδες (IU)', 'plandose' ),

				'frequency'           => __( 'Συχνότητα', 'plandose' ),
				'dailyTimeLabel'      => __( 'Ώρα λήψης', 'plandose' ),
				'times1'              => __( 'Μία φορά την ημέρα', 'plandose' ),
				'times2'              => __( '2 φορές την ημέρα', 'plandose' ),
				'times3'              => __( '3 φορές την ημέρα', 'plandose' ),
				'times4'              => __( '4 φορές την ημέρα', 'plandose' ),
				'missingDoseAmount'   => __( 'Συμπληρώστε την ποσότητα δόσης για το φάρμακο: ', 'plandose' ),
				'invalidDoseAmount'   => __( 'Η ποσότητα δόσης δεν είναι έγκυρη. Γράψτε αριθμό όπως 1, 1,5, 0,25, ½ ή 1/2.', 'plandose' ),
				/* Specific dose-quantity errors (see PD.checkDoseAmount()). */
				/* translators: %s: the quantity exactly as typed, e.g. "1.000". */
				'doseAmountThousands' => __( 'Η ποσότητα «%s» είναι ασαφής: μπορεί να διαβαστεί ως χιλιάδες. Γράψτε π.χ. 1 ή 1,5 — ή 1000 για χίλια.', 'plandose' ),
				/* translators: 1: dose as typed, e.g. 11/2; 2: what it reads as, e.g. 5,5; 3: the likely intended mixed number, e.g. 1 1/2 */
				'doseAmountMixed'     => __( 'Η ποσότητα «%1$s» διαβάζεται ως %2$s. Αν εννοείτε %3$s, γράψτε το με κενό («%3$s») ή ως δεκαδικό (π.χ. 1,5).', 'plandose' ),
				/* translators: 1: dose as typed, e.g. 10/2; 2: what it reads as, e.g. 5 */
				'doseAmountMixedPlain' => __( 'Η ποσότητα «%1$s» διαβάζεται ως %2$s. Ελέγξτε την τιμή: γράψτε μικτό αριθμό με κενό (π.χ. 1 1/2) ή δεκαδικό (π.χ. 1,5).', 'plandose' ),
				'doseAmountDecimals'  => __( 'Η ποσότητα δόσης μπορεί να έχει έως 2 δεκαδικά ψηφία (π.χ. 0,25).', 'plandose' ),
				'doseAmountZero'      => __( 'Η ποσότητα δόσης πρέπει να είναι μεγαλύτερη από το μηδέν.', 'plandose' ),
				/* translators: %s: largest quantity allowed per intake. */
				'doseAmountTooLarge'  => __( 'Η ποσότητα δόσης δεν μπορεί να ξεπερνά το %s ανά λήψη. Ελέγξτε την τιμή.', 'plandose' ),
				/* translators: 1: number of doses, 2: total quantity, 3: unit. */
				'doseTotalLine'       => __( 'Σύνολο: %1$s δόσεις • %2$s %3$s', 'plandose' ),
				/* translators: %1$s: number of doses. */
				'doseTotalDosesOnly'  => __( 'Σύνολο: %1$s δόσεις', 'plandose' ),
				'freqCustom'          => __( 'Εξειδικευμένη συχνότητα', 'plandose' ),
				'customModeLabel'     => __( 'Τρόπος εξειδικευμένης συχνότητας', 'plandose' ),
				'customModeDays'      => __( 'Ανά ημέρες', 'plandose' ),
				'customModeWeekday'   => __( 'Ανά ημέρα εβδομάδας', 'plandose' ),
				'weekdayLabel'        => __( 'Ποια ημέρα;', 'plandose' ),
				'customIntervalLabel' => __( 'Κάθε πόσες ημέρες;', 'plandose' ),
				'customIntervalPlaceholder' => __( 'π.χ. 7', 'plandose' ),
				'presetWeekly'        => __( 'Εβδομάδα (7)', 'plandose' ),
				'presetBiweekly'      => __( '2 εβδ. (14)', 'plandose' ),
				'preset15'            => __( '15 ημ.', 'plandose' ),
				'missingIntervalDays' => __( 'Συμπληρώστε κάθε πόσες ημέρες γίνεται η λήψη (1–90).', 'plandose' ),
				'noDosesInPeriod'     => __( 'Με αυτή τη διάρκεια δεν πέφτει καμία δόση — αυξήστε τη διάρκεια ή αλλάξτε την ημέρα. Φάρμακο: ', 'plandose' ),
				'labelCustomer' => __( 'Πελάτης', 'plandose' ),
				'labelDay'      => __( 'ημέρα', 'plandose' ),
				'labelDrug'     => __( 'Φάρμακο', 'plandose' ),
				'labelDosage'   => __( 'Δοσολογία', 'plandose' ),
				/* Same word as the form field «Σημειώσεις». */
				'labelNotesKey' => __( 'Σημειώσεις', 'plandose' ),
				'labelPhone'    => __( 'Τηλ.', 'plandose' ),
				'labelPharmacy' => __( 'ΦΑΡΜΑΚΕΙΟ', 'plandose' ),
				/* translators: %d: number of days of treatment, printed on the label. */
				'labelForDays'   => __( 'Για %d Μέρες', 'plandose' ),
				'labelForOneDay' => __( 'Για 1 Μέρα', 'plandose' ),
				'doseRowLabel'        => __( 'Δόση', 'plandose' ),
				// The A4 sheet laid out by day.
				'yourMedicines'       => __( 'Τα Φάρμακά σας:', 'plandose' ),
				/* translators: %d: number of days the medicine is taken */
				'forNDays'            => __( 'Για %d μέρες', 'plandose' ),
				'forOneDay'           => __( 'Για 1 μέρα', 'plandose' ),
				/* translators: %d: day number of the plan (1, 2, 3 …) */
				'dayNumber'           => __( 'Ημέρα %d', 'plandose' ),
				'anyTimeOfDay'        => __( 'Μέσα στην ημέρα', 'plandose' ),
				'every7Days'          => __( 'Μία φορά την εβδομάδα', 'plandose' ),
				'every14Days'         => __( 'Κάθε 2 εβδομάδες', 'plandose' ),
				/* translators: %d: number of days between doses. */
				'everyNDays'          => __( 'Κάθε %d ημέρες', 'plandose' ),
				/* translators: %s: weekday name, e.g. "Δευτέρα". */
				'everyWeekday'        => __( 'Κάθε %s', 'plandose' ),

				'notes'               => __( 'Σημειώσεις (προαιρετικό)', 'plandose' ),
				'notesPlaceholder'    => __( 'π.χ. Να λαμβάνεται μετά το φαγητό', 'plandose' ),
				'duration'            => __( 'Διάρκεια (ημέρες)', 'plandose' ),

				'add'                 => __( 'Προσθήκη Φαρμάκου', 'plandose' ),
				'saveDrug'            => __( 'Αποθήκευση Φαρμάκου', 'plandose' ),
				/* A medicine typed but not added blocks Preview/Print. */
				'unsavedDrug'         => __( 'Έχετε συμπληρώσει φάρμακο που δεν έχει προστεθεί στο πλάνο. Πατήστε «Προσθήκη Φαρμάκου» ή καθαρίστε τη φόρμα πριν συνεχίσετε.', 'plandose' ),
				'unsavedDrugEditing'  => __( 'Δεν έχετε αποθηκεύσει τις αλλαγές στο φάρμακο που επεξεργάζεστε. Πατήστε «Αποθήκευση Φαρμάκου» ή «Καθαρισμός» πριν συνεχίσετε.', 'plandose' ),
				'clearForm'           => __( 'Καθαρισμός', 'plandose' ),
				'clearFormAria'       => __( 'Καθαρισμός φόρμας φαρμάκου', 'plandose' ),
				'formCleared'         => __( 'Η φόρμα φαρμάκου καθαρίστηκε.', 'plandose' ),
				'editCancelled'       => __( 'Η επεξεργασία ακυρώθηκε — το φάρμακο στο πλάνο δεν άλλαξε.', 'plandose' ),
				'edit'                => __( 'Επεξεργασία', 'plandose' ),
				'deleteAria'          => __( 'Διαγραφή', 'plandose' ),
				'addedDrugs'          => __( 'Φάρμακα στο πλάνο', 'plandose' ),

				'emptyListTitle'      => __( 'Δεν έχουν προστεθεί φάρμακα ακόμα.', 'plandose' ),
				'emptyList'           => __( 'Προσθέστε τουλάχιστον ένα φάρμακο για να συνεχίσετε.', 'plandose' ),

				'scheduleReviewTitle' => __( 'Έλεγχος πλάνου', 'plandose' ),
				'scheduleReviewText'  => __( 'Ελέγξτε τα φάρμακα πριν την εκτύπωση.', 'plandose' ),
				'previewTitle'        => __( 'Προεπισκόπηση Εκτύπωσης', 'plandose' ),
				'previewText'         => __( 'Αυτό είναι το πλάνο που θα εκτυπωθεί.', 'plandose' ),

				'print'               => __( 'Εκτύπωση Πλάνου', 'plandose' ),
				'checkingPrint'       => __( 'Έλεγχος εκτύπωσης...', 'plandose' ),
				'printing'            => __( 'Εκτύπωση...', 'plandose' ),
				'pleaseWait'          => __( 'Παρακαλώ περιμένετε...', 'plandose' ),

				'drugAdded'           => __( 'Το φάρμακο προστέθηκε στο πλάνο.', 'plandose' ),
				'drugUpdated'         => __( 'Το φάρμακο ενημερώθηκε.', 'plandose' ),
				'drugUnchanged'       => __( 'Δεν έγινε καμία αλλαγή στο φάρμακο.', 'plandose' ),
				/* translators: %s: plan start date, e.g. 25/09 */
				'labelStartOn'        => __( 'Έναρξη: %s', 'plandose' ),
				/* translators: %s: part of the day of the first dose, e.g. Βράδυ */
				'labelFirstDose'      => __( '1η δόση: %s', 'plandose' ),
				'planReadyForNext'    => __( 'Το πλάνο εκτυπώθηκε. Η φόρμα καθαρίστηκε — έτοιμη για τον επόμενο ασθενή.', 'plandose' ),

				'missingDrugName'     => __( 'Συμπληρώστε το όνομα του φαρμάκου.', 'plandose' ),
				'missingDays'         => __( 'Λείπει η διάρκεια (ημέρες) για: ', 'plandose' ),
				'invalidCustomMode'   => __( 'Επιλέξτε έγκυρο τρόπο εξειδικευμένης συχνότητας.', 'plandose' ),
				/* translators: %d: max allowed days */
				'maxDaysExceeded'     => sprintf( __( 'Η διάρκεια δεν μπορεί να ξεπερνά τις %d ημέρες.', 'plandose' ), $max_days ),
				'addAtLeastOne'       => __( 'Προσθέστε τουλάχιστον ένα φάρμακο.', 'plandose' ),

				'genericError'        => __( 'Κάτι πήγε στραβά.', 'plandose' ),
				'confirmCloseText'    => __( 'Είστε σίγουροι ότι θέλετε να τερματίσετε αυτή την ενέργεια; Οι πληροφορίες του πλάνου θα χαθούν και η ενέργεια αυτή δεν μπορεί να αναιρεθεί.', 'plandose' ),
				'yes'                 => __( 'Ναι', 'plandose' ),
				'no'                  => __( 'Όχι', 'plandose' ),
				'connectionError'     => __( 'Πρόβλημα σύνδεσης. Δοκιμάστε ξανά.', 'plandose' ),
				/*
				 * Distinct from printNotRecorded on purpose: this one
				 * fires when the server answered, and answered that it
				 * had not recorded anything. Saying "πρόβλημα σύνδεσης"
				 * there would send the pharmacist looking at their
				 * internet connection for a problem that is not theirs.
				 */
				'printNotCounted'     => __( 'Η εκτύπωση δεν καταγράφηκε. Δοκιμάστε ξανά.', 'plandose' ),
				/* The plan stays on screen after the print dialog. */
				'reprint'             => __( 'Εκτύπωση ξανά', 'plandose' ),
				'newPatient'          => __( 'Νέος ασθενής', 'plandose' ),
				/* translators: %d: free reprints left for this plan. */
				'reprintedFree'       => __( 'Τυπώθηκε ξανά χωρίς νέα χρέωση. Δωρεάν επανεκτυπώσεις που απομένουν για αυτό το πλάνο: %d.', 'plandose' ),
				'printedPlanChanged'  => __( 'Το πλάνο εκτυπώθηκε. Αλλάξατε το πλάνο στο μεταξύ, οπότε η επόμενη εκτύπωση θα μετρήσει ως νέα.', 'plandose' ),
				/* translators: %d: minutes of inactivity. */
				'printedIdleCleared'  => __( 'Το πλάνο καθαρίστηκε αυτόματα μετά από %d λεπτά χωρίς δραστηριότητα.', 'plandose' ),
				'idleWarningOne'      => __( 'Το πλάνο θα καθαριστεί σε 1 λεπτό λόγω αδράνειας. Πατήστε «Κράτα το» ή συνεχίστε την εργασία σας για να μείνει στην οθόνη.', 'plandose' ),
				/* translators: %d: minutes left before the plan is cleared. */
				'idleWarningMany'     => __( 'Το πλάνο θα καθαριστεί σε %d λεπτά λόγω αδράνειας. Πατήστε «Κράτα το» ή συνεχίστε την εργασία σας για να μείνει στην οθόνη.', 'plandose' ),
				'idleWarningKeep'     => __( 'Κράτα το', 'plandose' ),
				'sessionExpired'      => __( 'Η σελίδα ήταν ανοιχτή πολλή ώρα και η σύνδεσή σας έληξε. Κάντε ανανέωση της σελίδας (F5) και δοκιμάστε ξανά.', 'plandose' ),

				'days'                => __( 'ημέρες', 'plandose' ),
				'time'                => __( 'Ώρα', 'plandose' ),
				'weekLabel'           => __( 'Εβδομάδα', 'plandose' ),

				'printTitle'          => __( 'Πλάνο Δοσολογίας', 'plandose' ),
				'pharmacySection'     => __( 'Στοιχεία Φαρμακείου', 'plandose' ),
				'printedOn'           => __( 'Εκτυπώθηκε στις', 'plandose' ),

				/* Pro-only medicine labels (printed separately, on the label printer). */
				'labelsTitle'         => __( 'Ετικέτες Φαρμάκων', 'plandose' ),
				'labelsPreviewIntro'  => __( 'Εκτυπώνονται χωριστά, στον ετικετογράφο, με το κουμπί «Εκτύπωση Ετικετών».', 'plandose' ),
				/* Label size picker (per computer) and fitting. */
				'labelSizeLabel'      => __( 'Μέγεθος ετικέτας (σε αυτόν τον υπολογιστή)', 'plandose' ),
				'labelSizeAuto'       => __( 'Όπως τώρα (από τον εκτυπωτή)', 'plandose' ),
				'labelSizeCustom'     => __( 'Άλλο μέγεθος…', 'plandose' ),
				'labelWidth'          => __( 'Πλάτος (mm)', 'plandose' ),
				'labelHeight'         => __( 'Ύψος (mm)', 'plandose' ),
				'labelApply'          => __( 'Εφαρμογή', 'plandose' ),
				'labelCustomInvalid'  => __( 'Μη έγκυρο μέγεθος.', 'plandose' ),
				/* translators: %1$s × %2$s: smallest label, %3$s × %4$s: largest label, in mm. */
				'labelCustomRange'    => __( 'Όπως γράφει το κουτί των ετικετών. Από %1$s × %2$s έως %3$s × %4$s mm.', 'plandose' ),
				/* translators: %1$s × %2$s: label width × height in mm. */
				'labelDriverHint'     => __( 'Στις ρυθμίσεις του εκτυπωτή επιλέξτε ετικέτα %1$s × %2$s mm. Στο παράθυρο εκτύπωσης: Περιθώρια «Κανένα», Κλίμακα 100%.', 'plandose' ),
				'labelTestPrint'      => __( 'Δοκιμαστική ετικέτα', 'plandose' ),
				'labelTestPatient'    => __( 'ΔΟΚΙΜΑΣΤΙΚΗ ΕΤΙΚΕΤΑ', 'plandose' ),
				'labelTestNotes'      => __( 'ΔΟΚΙΜΗ — μετά το φαγητό, με ένα ποτήρι νερό, όχι γάλα', 'plandose' ),
				'labelSizeHint'       => __( 'Επιλέξτε το μέγεθος των ετικετών σας: έτσι κάθε φάρμακο χωράει πάντα σε μία ετικέτα.', 'plandose' ),
				/* translators: %s: what was shortened (e.g. «Σημειώσεις, Πελάτης»). */
				'labelTrimmed'        => __( 'Συντομεύτηκαν για να χωρέσουν: %s. Στο πλάνο Α4 τυπώνονται ολόκληρα.', 'plandose' ),
				/* translators: %s: medicine names, comma separated */
				'labelNotesCutConfirm' => __( 'Οι σημειώσεις δεν χωράνε ολόκληρες στην ετικέτα και θα κοπούν ή θα παραλειφθούν: %s. Ελέγξτε την προεπισκόπηση. Πατήστε ξανά «Εκτύπωση Ετικετών» για να τυπωθούν έτσι, ή επιλέξτε μεγαλύτερη ετικέτα.', 'plandose' ),
				/* translators: %s: medicine name. */
				'labelTooLong'        => __( 'Το «%s» με τη δοσολογία του δεν χωράει σε μία ετικέτα αυτού του μεγέθους. Συντομεύστε το όνομα ή επιλέξτε μεγαλύτερη ετικέτα.', 'plandose' ),
				'printLabels'         => __( 'Εκτύπωση Ετικετών', 'plandose' ),
				'labelsPopupBlocked'  => __( 'Ο browser μπλόκαρε το παράθυρο εκτύπωσης ετικετών. Επιτρέψτε τα αναδυόμενα παράθυρα για αυτό το site και δοκιμάστε ξανά.', 'plandose' ),
				'planReadyForNextPro' => __( 'Το πλάνο εκτυπώθηκε. Μπορείτε ακόμη να τυπώσετε τις ετικέτες του με το «Εκτύπωση Ετικετών» — ή να ξεκινήσετε τον επόμενο ασθενή.', 'plandose' ),
				'durationLabel'       => __( 'Διάρκεια', 'plandose' ),
				/* translators: %s: pharmacy name */
				'printIntro'          => __( 'Το φαρμακείο %s ετοίμασε αυτό το πλάνο για να σας βοηθήσει στην καλύτερη οργάνωση και συνέπεια στη λήψη των φαρμάκων σας.', 'plandose' ),
				/* translators: %d: remaining prints this month */
				'printsRemaining'     => __( 'Απομένουν %d εκτυπώσεις αυτόν τον μήνα.', 'plandose' ),
				'printsUnlimited'     => __( 'Απεριόριστες εκτυπώσεις.', 'plandose' ),
				/*
				 * Browsers give no reliable signal for "the pharmacist
				 * actually pressed Print" vs. "they opened the dialog
				 * and pressed Cancel" — so a credit is consumed once
				 * the print window opens, regardless of what happens
				 * next. This line exists specifically to set that
				 * expectation up front instead of surprising someone
				 * with a lower counter after a cancelled print.
				 */
				'printCreditHint'     => sprintf(
					/* translators: 1: number of free reprints, 2: free-reprint window in minutes. */
					__( 'Η εκτύπωση μετράει μόλις ανοίξει το παράθυρο εκτύπωσης, ακόμη κι αν πατήσετε Ακύρωση. Χωρίς αλλαγές στο πλάνο, η επανεκτύπωση είναι δωρεάν έως %1$d φορές μέσα σε %2$d λεπτά.', 'plandose' ),
					Plandose_Ajax::MAX_FREE_REPRINTS,
					self::reprint_window_minutes()
				),

				'disclaimer'          => self::plain_text( sanitize_textarea_field( (string) Plandose_Settings::setting(
					'disclaimer',
					__( 'Το πλάνο αυτό είναι βοήθημα υπενθύμισης δοσολογίας και δεν αντικαθιστά την οδηγία ιατρού ή φαρμακοποιού. Σε περίπτωση αμφιβολίας συμβουλευτείτε τον φαρμακοποιό σας.', 'plandose' )
				) ) ),
				'thanksLine1'         => self::plain_text( sanitize_text_field( (string) Plandose_Settings::setting(
					'thanks_message_line1',
					__( 'Ευχαριστούμε που εμπιστευτήκατε το φαρμακείο μας.', 'plandose' )
				) ) ),
				'thanksLine2'         => self::plain_text( sanitize_text_field( (string) Plandose_Settings::setting(
					'thanks_message_line2',
					__( 'Για οποιαδήποτε διευκρίνηση είμαστε πάντα στη διάθεσή σας!', 'plandose' )
				) ) ),

				'morning'             => __( 'Πρωί', 'plandose' ),
				'noon'                => __( 'Μεσημέρι', 'plandose' ),
				'afternoon'           => __( 'Απόγευμα', 'plandose' ),
				'evening'             => __( 'Βράδυ', 'plandose' ),

				'rxWarn_iuSyringe' => __( 'Ισχύς σε μονάδες ανά ml σε μία προγεμισμένη σύριγγα: επιβεβαιώστε ότι η δόση είναι μία ένεση και ότι το φάρμακο δεν είναι ινσουλίνη.', 'plandose' ),
				'insulinUnitsRequired' => __( 'Ινσουλίνη: η δόση γράφεται σε μονάδες (IU). Επιλέξτε «Μονάδες (IU)» και συμπληρώστε τις μονάδες της συνταγής.', 'plandose' ),
				'rxWarn_insulinUnits' => __( 'Ινσουλίνη: η δοσολογία δίνεται σε ενέσεις, όχι σε μονάδες — συμπληρώστε στη φόρμα τη δόση σε μονάδες (IU).', 'plandose' ),
				'rxWarn_insulinMl'    => __( 'Ινσουλίνη: η δόση είναι γραμμένη σε ml ή mg, όχι σε μονάδες — συμπληρώστε στη φόρμα τη δόση σε μονάδες (IU).', 'plandose' ),
				// «Υπενθυμίσεις στο κινητό» QR on the sheet.
				'calQrTitle'          => __( 'Υπενθυμίσεις στο κινητό', 'plandose' ),
				'calQrText'           => __( 'Σκανάρετε με την κάμερα του κινητού: οι δόσεις μπαίνουν στο ημερολόγιό σας με ειδοποίηση.', 'plandose' ),
				'calQrPrivate'        => __( 'Δεν στέλνεται στο φαρμακείο. Το QR περιέχει τα φάρμακα του πλάνου.', 'plandose' ),
				'calQrTooBig'         => __( 'Το πλάνο είναι πολύ μεγάλο για το QR «Υπενθυμίσεις στο κινητό»: το φύλλο θα τυπωθεί χωρίς QR.', 'plandose' ),
				'calQrNoNotes'        => __( 'Οι σημειώσεις των φαρμάκων δεν χωρούν στο QR «Υπενθυμίσεις στο κινητό»: οι υπενθυμίσεις στο κινητό θα είναι χωρίς σημειώσεις. Στο τυπωμένο φύλλο υπάρχουν κανονικά.', 'plandose' ),
				'calQrNoPharmacy'     => __( 'Το όνομα του φαρμακείου (και τυχόν σημειώσεις των φαρμάκων) δεν χωρούν στο QR «Υπενθυμίσεις στο κινητό»: στο κινητό οι υπενθυμίσεις θα είναι χωρίς αυτά. Στο τυπωμένο φύλλο υπάρχουν κανονικά.', 'plandose' ),
				'rxWarn_everyHours'   => __( 'Η συνταγή λέει «κάθε … ώρες»: στο πλάνο οι δόσεις μπαίνουν Πρωί / Μεσημέρι / Βράδυ, όχι ανά ακριβές ωράριο. Αν πρέπει να απέχουν ακριβώς, γράψτε τις ώρες στις σημειώσεις.', 'plandose' ),
				'rxWarn_unitForm'     => __( 'Η δοσολογία δεν ταιριάζει με τη μορφή του φαρμάκου (π.χ. ένεση σε δισκία) — ελέγξτε ότι η γραμμή δοσολογίας ανήκει σε αυτό το φάρμακο.', 'plandose' ),
				'rxWarn_shortDuration' => __( 'Η διάρκεια φαίνεται πολύ μικρή για την ποσότητα που χορηγείται — ελέγξτε τη διάρκεια.', 'plandose' ),
				'rxWarn_strengthCheck' => __( 'Η περιεκτικότητα λείπει ή δεν διαβάστηκε ολόκληρη — συγκρίνετε το όνομα με τη συνταγή και επιβεβαιώστε.', 'plandose' ),
				'rxWarn_strengthZero' => __( 'Η περιεκτικότητα είναι γραμμένη χωρίς το αρχικό μηδέν (π.χ. «.25MG») και μπήκε με μηδέν μπροστά (0.25MG) — συγκρίνετε το όνομα με τη συνταγή στη φόρμα.', 'plandose' ),
				'rxWarn_strengthSplit' => __( 'Ο αριθμός της περιεκτικότητας είναι σπασμένος με κενό (π.χ. «0. 25MG», «1 . 5MG») και μπήκε ενωμένος (0.25MG, 1.5MG) — συγκρίνετε το όνομα με τη συνταγή στη φόρμα.', 'plandose' ),
				'rxWarn_strengthSep' => __( 'Ακριβώς πριν από την περιεκτικότητα υπάρχει τελεία, κόμμα ή άλλος αριθμός (π.χ. «1X. 5MG», «0 25MG») — ίσως λείπει μηδέν ή υποδιαστολή. Συγκρίνετε το όνομα με τη συνταγή στη φόρμα.', 'plandose' ),
				'rxWarn_weeklyOnly' => __( 'ΠΡΟΣΟΧΗ: αυτό το φάρμακο χορηγείται συνήθως μία φορά την εβδομάδα, εδώ είναι συχνότερα. Επαληθεύστε τη συχνότητα με τον γιατρό που το συνταγογράφησε.', 'plandose' ),
				/* translators: %s: medicine name. */
				'weeklyOnlyPrintConfirm' => __( 'ΠΡΟΣΟΧΗ: «%s» συχνότερα από μία φορά την εβδομάδα — συνήθως χορηγείται μία φορά την εβδομάδα. Αν η συχνότητα είναι επιβεβαιωμένη με τον γιατρό, πατήστε ξανά «Εκτύπωση».', 'plandose' ),
				'rxFoundOne' => __( 'Βρέθηκε 1 φάρμακο. Ελέγξτε το πριν μπει στο πλάνο.', 'plandose' ),
				'doseAmountDecimalsMg' => __( 'Η ποσότητα σε mg μπορεί να έχει έως 3 δεκαδικά ψηφία (π.χ. 0,125).', 'plandose' ),
				/* translators: %s: medicine name. */
				'drugDuplicateConfirm' => __( 'Το «%s» υπάρχει ήδη στο πλάνο. Αν πρέπει να μπει δεύτερη φορά (π.χ. άλλη δόση άλλες ώρες), πατήστε ξανά το ίδιο κουμπί — αλλιώς πατήστε «Επεξεργασία» στο φάρμακο της λίστας.', 'plandose' ),
				/* translators: %s: medicine names. */
				'methotrexateRepeatedPrintConfirm' => __( 'ΠΡΟΣΟΧΗ: μεθοτρεξάτη σε περισσότερες από μία εγγραφές του πλάνου («%s») — μαζί μπορεί να δίνουν δόση συχνότερα από μία φορά την εβδομάδα. Αν είναι σωστό, πατήστε ξανά «Εκτύπωση».', 'plandose' ),
				/* translators: %s: medicine names. */
				'weeklyRepeatedPrintConfirm' => __( 'ΠΡΟΣΟΧΗ: «%s» σε περισσότερες από μία εγγραφές του πλάνου — συνήθως χορηγείται μία φορά την εβδομάδα. Αν είναι σωστό, πατήστε ξανά «Εκτύπωση».', 'plandose' ),
				'weeklyRepeatedWarn' => __( 'ΠΡΟΣΟΧΗ: αυτό το φάρμακο χορηγείται συνήθως μία φορά την εβδομάδα και υπάρχει ήδη σε άλλη εγγραφή του πλάνου. Επιβεβαιώστε ότι μαζί δεν δίνουν δόση συχνότερα από το σωστό.', 'plandose' ),
				'rxWarn_dispensedQty' => __( 'Η ποσότητα που χορηγήθηκε δεν ταιριάζει με το πλάνο (πολύ περισσότερη ή πολύ λιγότερη) — ελέγξτε ποσότητα, συχνότητα και διάρκεια με τη συνταγή.', 'plandose' ),
				/* translators: 1: units dispensed (e.g. «60 δισκία»), 2: units the plan needs. */
				'rxDispensed' => __( 'Χορηγούνται %1$s · το πλάνο χρειάζεται %2$s', 'plandose' ),
				/* translators: %d: number of once-daily medicines without a time. */
				'rxBulkTime' => __( 'Ίδια ώρα για όλα τα 1×ημέρα (%d):', 'plandose' ),
				'rxBulkMark' => __( '(για όλα)', 'plandose' ),
				/* translators: 1: chosen time of day, 2: number of medicines. */
				'rxBulkDone' => __( '«%1$s»: ορίστηκε σε %2$d φάρμακα.', 'plandose' ),
				'rxWarn_brandWords' => __( 'Το όνομα έχει περισσότερες από μία λέξεις πριν από τη μορφή (τονίζονται) — επιβεβαιώστε ότι ανήκουν στο όνομα του φαρμάκου (π.χ. «PO», «HS» δεν ανήκουν).', 'plandose' ),
				/* translators: %s: the unread text, quoted. */
				'rxUnreadText' => __( 'Υπάρχει κείμενο στη συνταγή που δεν διαβάστηκε: «%s — ελέγξτε τη συνταγή.', 'plandose' ),
				'rxWarn_variableDose' => __( 'Η συνταγή έχει σειρά από ποσότητες (π.χ. διαφορετική δόση ανά ημέρα) — δεν είναι σταθερή δόση. Συμπληρώστε το πρόγραμμα στη φόρμα.', 'plandose' ),
				'printReplayLimitKeep' => __( 'Αυτή η προσπάθεια εκτύπωσης έχει ήδη επαναληφθεί όσες φορές επιτρέπεται. Τώρα δεν χρεώθηκε και δεν τυπώθηκε τίποτα. Αν πατήσετε ξανά «Εκτύπωση», θα γίνει δωρεάν επανεκτύπωση αν απομένουν (δείτε τη γραμμή χρέωσης) — αλλιώς θα μετρήσει ως νέα εκτύπωση.', 'plandose' ),
				'rxTooLarge' => __( 'Το κείμενο είναι πολύ μεγάλο για μία συνταγή — αντιγράψτε μόνο τη συνταγή και ξαναδοκιμάστε.', 'plandose' ),
				/* translators: %d: number of unread lines that look like dosage lines. */
				'rxUnreadDoseLines' => __( 'Βρέθηκαν %d γραμμές που μοιάζουν με δοσολογία αλλά δεν διαβάστηκαν — κάποιο φάρμακο μπορεί να λείπει. Ελέγξτε τη συνταγή.', 'plandose' ),
				'rxWarn_monthlyCount' => __( 'Μία φορά τον μήνα: το πλήθος των δόσεων εξαρτάται από την ημέρα που θα επιλέξετε — ελέγξτε τις ημερομηνίες με όσα χορηγήθηκαν.', 'plandose' ),
				'customModeMonthDay' => __( 'Ημέρα του μήνα', 'plandose' ),
				'monthDayLabel' => __( 'Ποια ημέρα του μήνα; (1–31)', 'plandose' ),
				'monthDayPlaceholder' => __( 'π.χ. 15', 'plandose' ),
				'monthDayHelp' => __( 'Η δόση πέφτει αυτή την ημέρα κάθε μήνα.', 'plandose' ),
				/* translators: %1$d: chosen day of the month (29-31). */
				'monthDayLastRule' => __( 'Όταν ένας μήνας δεν έχει %1$d ημέρες (π.χ. Φεβρουάριος, Απρίλιος), η δόση πέφτει την τελευταία ημέρα εκείνου του μήνα.', 'plandose' ),
				'preset30' => __( '30 ημ.', 'plandose' ),
				'rxFromPrescription' => __( 'Από τη συνταγή:', 'plandose' ),
				/* translators: %1$s: number of doses (always 1). */
				'doseTotalOneDoseOnly' => __( 'Σύνολο: %1$s δόση', 'plandose' ),
				/* translators: 1: number of doses (1), 2: total quantity, 3: unit. */
				'doseTotalLineOne' => __( 'Σύνολο: %1$s δόση • %2$s %3$s', 'plandose' ),
				'everyOneDay' => __( 'Κάθε ημέρα', 'plandose' ),
				'everyWeekdayUnset' => __( 'Μία φορά την εβδομάδα (επιλέξτε ημέρα)', 'plandose' ),
				'everyMonthDayUnset' => __( 'Μία φορά τον μήνα (επιλέξτε ημέρα)', 'plandose' ),
				/* translators: %d: day of the month (1-28). */
				'everyMonthDay' => __( 'Μία φορά τον μήνα, στις %d', 'plandose' ),
				/* translators: %d: day of the month (29-31). */
				'everyMonthDayLast' => __( 'Μία φορά τον μήνα, στις %d (ή την τελευταία ημέρα του μήνα)', 'plandose' ),
				'rxWeekdayLabel' => __( 'Ημέρα της εβδομάδας', 'plandose' ),
				'rxMonthDayLabel' => __( 'Ημέρα του μήνα (1–31)', 'plandose' ),
				'rxConfirm' => __( 'Επιβεβαιώνω', 'plandose' ),
				'rxFieldName' => __( 'Φάρμακο', 'plandose' ),
				'rxFieldQty' => __( 'Ποσότητα', 'plandose' ),
				'rxFieldFreq' => __( 'Συχνότητα', 'plandose' ),
				'rxFieldDays' => __( 'Διάρκεια', 'plandose' ),
				'rxOriginal' => __( 'Στη συνταγή', 'plandose' ),
				'rxAddBlocked' => __( 'Για να ενεργοποιηθεί η «Προσθήκη», επιλέξτε ή επιβεβαιώστε τα σημειωμένα πεδία των επιλεγμένων φαρμάκων.', 'plandose' ),
				'rxCountAck' => __( 'Έλεγξα τη συνταγή και ξέρω ποια φάρμακα λείπουν από τη λίστα.', 'plandose' ),
				'rxPatientConfirm' => __( 'Επιβεβαιώνω ότι τα φάρμακα που επιλέγω είναι του ασθενή αυτού του πλάνου.', 'plandose' ),
				/* translators: 1: medicines found on the prescription, 2: medicines read. */
				'rxCountMismatch' => __( 'Η συνταγή φαίνεται να έχει %1$d φάρμακα, διαβάστηκαν %2$d — ελέγξτε.', 'plandose' ),
				/* translators: %s: the quantity as typed. */
				'doseAmountMixedWhole' => __( 'Η ποσότητα «%s» δεν είναι έγκυρος μικτός αριθμός: ο αριθμητής του κλάσματος πρέπει να είναι μικρότερος από τον παρονομαστή (π.χ. 1 1/2). Ελέγξτε την τιμή.', 'plandose' ),
				'pickDailyTime' => __( 'Επιλέξτε ώρα λήψης (Πρωί, Μεσημέρι, Απόγευμα ή Βράδυ).', 'plandose' ),
				'pickWeekday' => __( 'Επιλέξτε ημέρα της εβδομάδας.', 'plandose' ),
				'pickMonthDay' => __( 'Επιλέξτε ημέρα του μήνα (1–31).', 'plandose' ),
				/* translators: 1: quantity per intake, 2: unit. */
				'doseUnusualConfirm' => __( 'Ασυνήθιστα μεγάλη ποσότητα: %1$s %2$s ανά λήψη. Αν είναι σωστή, πατήστε ξανά το ίδιο κουμπί για επιβεβαίωση.', 'plandose' ),
				'rxWarn_strength' => __( 'Η περιεκτικότητα δεν διαβάστηκε με βεβαιότητα και δεν μπήκε στο όνομα — ελέγξτε το όνομα.', 'plandose' ),
				'rxWarn_noDose' => __( 'Βρέθηκε φάρμακο χωρίς γραμμή «ΔΟΣΟΛΟΓΙΑ» — συμπληρώστε τη δοσολογία από τη συνταγή.', 'plandose' ),
				'rxWarn_tooLong' => __( 'Η γραμμή της δοσολογίας είναι υπερβολικά μεγάλη και δεν διαβάστηκε — συμπληρώστε τη.', 'plandose' ),
				'rxWarn_highQty' => __( 'Ασυνήθιστα μεγάλη ποσότητα ανά λήψη — επιβεβαιώστε την με τη συνταγή.', 'plandose' ),
				'rxWarn_asNeeded' => __( 'Η συνταγή λέει «όταν χρειάζεται» (SOS / σε περίπτωση / εάν) — δεν είναι σταθερό πρόγραμμα. Γράψτε την οδηγία στις σημειώσεις.', 'plandose' ),
				'rxWarn_sameDrugOtherDose' => __( 'Το ίδιο φάρμακο υπάρχει και με άλλη δοσολογία — επιλέξτε ποια ισχύει.', 'plandose' ),
				'rxWarn_pickWeekday' => __( 'Μία φορά την εβδομάδα: επιλέξτε ημέρα της εβδομάδας.', 'plandose' ),
				'rxWarn_pickMonthDay' => __( '«1 φορά τον μήνα»: επιλέξτε ημέρα του μήνα. Αν ένας μήνας δεν έχει αυτή την ημέρα, η δόση πέφτει την τελευταία ημέρα του μήνα.', 'plandose' ),
				'rxWarn_iuConfirmHere' => __( 'Ινσουλίνη: η ποσότητα διαβάστηκε ως μονάδες (IU) — επιβεβαιώστε την.', 'plandose' ),
				'rxWarn_intervalCountCheck' => __( 'Οι ημέρες δεν είναι ακέραιος αριθμός εβδομάδων/διαστημάτων: ελέγξτε το πλήθος των δόσεων (π.χ. 30 ημέρες εβδομαδιαία = 4 ή 5 δόσεις) με όσα χορηγήθηκαν.', 'plandose' ),
				'everyDayTimeUnset' => __( 'Μία φορά την ημέρα (επιλέξτε ώρα)', 'plandose' ),
				'notesLabelHint' => __( 'Στις ετικέτες μπορεί να χωρέσει μέρος των σημειώσεων· θα ζητηθεί επιβεβαίωση αν κοπούν. Στο πλάνο τυπώνονται ολόκληρες.', 'plandose' ),
				/* translators: %s: local time the print was charged (HH:MM). */
				'billingRecordedAt' => __( 'Η εκτύπωση χρεώθηκε στις %s.', 'plandose' ),
				/* translators: 1: free reprints left, 2: local time until which they are free (HH:MM). */
				'billingFreeLeft' => __( 'Δωρεάν επανεκτυπώσεις: %1$d, έως τις %2$s.', 'plandose' ),
				'billingNoFreeLeft' => __( 'Δεν απομένουν δωρεάν επανεκτυπώσεις — η επόμενη εκτύπωση χρεώνεται.', 'plandose' ),
				'billingNextCharged' => __( 'Η επόμενη εκτύπωση χρεώνεται ως νέα.', 'plandose' ),
				'billingCannotKnow' => __( 'Ο browser δεν μπορεί να γνωρίζει αν ο εκτυπωτής ολοκλήρωσε την εκτύπωση — ελέγξτε το χαρτί.', 'plandose' ),
				/* translators: 1: local time until which reprints are free (HH:MM), 2: number of free reprints. */
				'printedMaybeNoDialog' => __( 'Η χρέωση καταγράφηκε· αν δεν άνοιξε ο διάλογος εκτύπωσης, πατήστε «Εκτύπωση ξανά» — δωρεάν έως τις %1$s (έως %2$d φορές), όσο το πλάνο μένει στην οθόνη.', 'plandose' ),
				/* translators: %d: free-reprint window in minutes. */
				'printedReprintChargedWindow' => __( 'Η εκτύπωση χρεώθηκε ως νέα: οι δωρεάν επανεκτυπώσεις αυτού του πλάνου εξαντλήθηκαν ή πέρασαν %d λεπτά από την πρώτη εκτύπωση.', 'plandose' ),
				/* translators: 1: number of free reprints, 2: local time until which they are free (HH:MM), 3: minutes of inactivity before the plan is cleared. */
				'printedKeepPlanWindow' => __( 'Η εκτύπωση καταγράφηκε. Αν δεν τυπώθηκε σωστά (π.χ. πατήσατε Ακύρωση ή διαλέξατε λάθος εκτυπωτή), πατήστε «Εκτύπωση ξανά» — χωρίς νέα χρέωση, έως %1$d φορές, μέχρι τις %2$s. Χωρίς δραστηριότητα για %3$d λεπτά το πλάνο καθαρίζεται αυτόματα. Για τον επόμενο ασθενή πατήστε «Νέος ασθενής».', 'plandose' ),
				'printWindowClosedCancelled' => __( 'Η εκτύπωση ακυρώθηκε ή το παράθυρο μπλοκαρίστηκε — δεν καταγράφηκε νέα εκτύπωση. Πατήστε ξανά «Εκτύπωση»· αν το παράθυρο δεν ανοίγει, επιτρέψτε τα αναδυόμενα παράθυρα για αυτό το site.', 'plandose' ),
				/* translators: 1: local time until which the retry is free (HH:MM), 2: number of free retries, 3: minutes of inactivity before the plan is cleared. */
				'printRetryFreeUntil' => __( 'Η εκτύπωση δεν άνοιξε, αλλά η χρέωση καταγράφηκε. Πατήστε ξανά «Εκτύπωση» έως τις %1$s — δεν θα χρεωθεί δεύτερη φορά (έως %2$d επαναλήψεις), ακόμη κι αν διορθώσετε πρώτα το πλάνο ή αυτό καθαριστεί μετά από %3$d λεπτά αδράνειας.', 'plandose' ),
				'printPlanChangedCarried' => __( 'Το πλάνο άλλαξε ενώ καταγραφόταν η εκτύπωση, οπότε δεν τυπώθηκε. Η χρέωση καταγράφηκε: πατήστε ξανά «Εκτύπωση» για το τρέχον πλάνο — χωρίς νέα χρέωση.', 'plandose' ),
				'printPlanChangedNoSheet' => __( 'Το πλάνο άλλαξε ενώ καταγραφόταν η επανεκτύπωση, οπότε δεν τυπώθηκε. Η επόμενη εκτύπωση θα μετρήσει ως νέα.', 'plandose' ),
				'headerEmpty' => __( 'Δεν βρέθηκαν τα στοιχεία του φαρμακείου (όνομα) για το φύλλο. Συμπληρώστε τα στο προφίλ του λογαριασμού σας και δοκιμάστε ξανά.', 'plandose' ),
				/* translators: 1: local time until which the retry is free (HH:MM), 2: number of free retries. */
				'printNotRecordedUntil' => __( 'Πρόβλημα σύνδεσης. Δεν είναι βέβαιο αν η εκτύπωση χρεώθηκε. Πατήστε ξανά «Εκτύπωση» έως τις %1$s — αν είχε χρεωθεί, δεν θα χρεωθεί δεύτερη φορά (έως %2$d επαναλήψεις), ακόμη κι αν διορθώσετε πρώτα το πλάνο.', 'plandose' ),
				'closeBlockedPrinting' => __( 'Η εκτύπωση βρίσκεται σε εξέλιξη — το παράθυρο δεν μπορεί να κλείσει μέχρι να ολοκληρωθεί.', 'plandose' ),
				'labelPatientInitialsConfirm' => __( 'Το όνομα του πελάτη δεν χωράει και θα τυπωθούν μόνο τα αρχικά του. Πατήστε ξανά «Εκτύπωση Ετικετών» για να τυπωθεί έτσι, ή επιλέξτε μεγαλύτερη ετικέτα.', 'plandose' ),
				'dayCardNotesSeeList' => __( '(βλ. λίστα φαρμάκων)', 'plandose' ),
				'dayCardContinued' => __( '(συνέχεια)', 'plandose' ),
			),
		);

		return $dictionaries;
	}

	/**
	 * One dictionary, by language: 'el' or 'en'.
	 *
	 * @param string $lang 'el' or 'en'.
	 * @return array<string,mixed>
	 */
	private static function i18n_dictionary( $lang ) {
		$max_days = Plandose_Settings::max_plan_days();

		if ( 'en' === $lang ) {
			return self::build_i18n_en( $max_days );
		}

		$dictionaries = self::build_i18n( $max_days );

		return $dictionaries['i18n'];
	}

	/**
	 * A dictionary as the JSON the dictionary script assigns. Also the
	 * input of its content hash, so page and script always agree.
	 *
	 * @param array $dictionary Key → string map.
	 * @return string
	 */
	private static function i18n_json( $dictionary ) {
		// JSON_HEX_* only for parity with the inline config; the output is
		// a script file, not HTML.
		$json = wp_json_encode( $dictionary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );

		if ( false === $json ) {
			$json = wp_json_encode( $dictionary );
		}

		return false === $json ? '{}' : $json;
	}

	/**
	 * Content hash of one dictionary's JSON, used as its URL version.
	 *
	 * @param string $lang 'el' or 'en'.
	 * @param string $json Output of i18n_json().
	 * @return string 16 hex characters.
	 */
	private static function i18n_hash( $lang, $json ) {
		return substr( md5( $lang . "\n" . $json ), 0, 16 );
	}

	/**
	 * Everything the dictionary content depends on, cheaply: plugin
	 * version and this file's time (the dictionaries are code here), the
	 * locale and its translation files' times, and the settings that some
	 * strings embed (disclaimer, thanks lines, maximum days).
	 *
	 * A translation loaded from somewhere else (e.g. a translation plugin's
	 * own folder) can add its own part through 'plandose_i18n_fingerprint'.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	private static function i18n_fingerprint( $locale ) {
		$parts = array(
			PLANDOSE_VERSION,
			(string) filemtime( __FILE__ ),
			$locale,
			md5( maybe_serialize( Plandose_Settings::settings() ) ),
		);

		foreach ( array( WP_LANG_DIR . '/plugins/', PLANDOSE_PATH . 'languages/' ) as $dir ) {
			foreach ( array( '.mo', '.l10n.php' ) as $ext ) {
				$file    = $dir . 'plandose-' . $locale . $ext;
				$parts[] = is_file( $file ) ? (string) filemtime( $file ) : '-';
			}
		}

		$parts[] = (string) apply_filters( 'plandose_i18n_fingerprint', '', $locale );

		return md5( implode( '|', $parts ) );
	}

	/**
	 * Content hashes of the requested dictionaries and the pharmacy's own
	 * printed texts, for the current locale.
	 *
	 * Stored in one small autoloaded option keyed by i18n_fingerprint(), so
	 * a page does not build the dictionaries just to name their URL: they
	 * are built only when the fingerprint changes (update, new translation,
	 * settings saved). A few fingerprints are kept for sites that serve
	 * more than one locale.
	 *
	 * @param string[] $langs 'el' and/or 'en'.
	 * @return array{locale: string, hashes: array<string,string>, site: array<string,string>}
	 */
	private static function i18n_state( $langs ) {
		$locale      = determine_locale();
		$fingerprint = self::i18n_fingerprint( $locale );
		$stored      = get_option( self::I18N_OPTION, array() );
		$stored      = is_array( $stored ) ? $stored : array();
		$entry       = ( isset( $stored[ $fingerprint ] ) && is_array( $stored[ $fingerprint ] ) ) ? $stored[ $fingerprint ] : array();
		$changed     = false;

		foreach ( $langs as $lang ) {
			$has_hash = isset( $entry[ $lang ] ) && is_string( $entry[ $lang ] ) && '' !== $entry[ $lang ];
			$has_site = 'el' !== $lang || ( isset( $entry['site'] ) && is_array( $entry['site'] ) );

			if ( $has_hash && $has_site ) {
				continue;
			}

			$dictionary     = self::i18n_dictionary( $lang );
			$entry[ $lang ] = self::i18n_hash( $lang, self::i18n_json( $dictionary ) );
			$changed        = true;

			if ( 'el' === $lang ) {
				$entry['site'] = array();

				foreach ( array( 'disclaimer', 'thanksLine1', 'thanksLine2' ) as $key ) {
					if ( isset( $dictionary[ $key ] ) && is_string( $dictionary[ $key ] ) ) {
						$entry['site'][ $key ] = $dictionary[ $key ];
					}
				}
			}
		}

		if ( $changed ) {
			unset( $stored[ $fingerprint ] );
			$stored[ $fingerprint ] = $entry;
			$stored                 = array_slice( $stored, -self::I18N_KEEP, null, true );
			update_option( self::I18N_OPTION, $stored, true );
		}

		$hashes = array();

		foreach ( $langs as $lang ) {
			$hashes[ $lang ] = $entry[ $lang ];
		}

		return array(
			'locale' => $locale,
			'hashes' => $hashes,
			'site'   => isset( $entry['site'] ) && is_array( $entry['site'] ) ? $entry['site'] : array(),
		);
	}

	/**
	 * URLs of the dictionary scripts for this page, by script handle, and
	 * the pharmacy's printed texts to keep inline.
	 *
	 * @param bool $with_en Whether the English dictionary is needed (Pro).
	 * @return array{scripts: array<string,string>, site: array<string,string>}
	 */
	private static function i18n_scripts( $with_en ) {
		$state   = self::i18n_state( $with_en ? array( 'el', 'en' ) : array( 'el' ) );
		$scripts = array();

		foreach ( $state['hashes'] as $lang => $hash ) {
			$scripts[ 'en' === $lang ? 'plandose-i18n-en' : 'plandose-i18n' ] = add_query_arg(
				array(
					'action' => self::I18N_ACTION,
					'lang'   => $lang,
					'locale' => $state['locale'],
					'v'      => $hash,
				),
				admin_url( 'admin-ajax.php' )
			);
		}

		return array(
			'scripts' => $scripts,
			'site'    => $state['site'],
		);
	}

	/**
	 * Whether $locale looks like a WordPress locale (en_US, el, de_DE_formal).
	 *
	 * @param string $locale Candidate.
	 * @return bool
	 */
	private static function is_locale_name( $locale ) {
		return is_string( $locale ) && 1 === preg_match( '/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8}){0,2}$/', $locale );
	}

	/**
	 * The dictionary script: status, headers and body.
	 *
	 * Served with a year-long, immutable cache only when $version is the
	 * hash of what is served now; a stale or missing version still gets
	 * the current dictionary, but uncached, so an old URL can never pin
	 * other content in a browser or CDN.
	 *
	 * The English dictionary drives the Pro-only language toggle, so it is
	 * refused (403) unless $en_allowed, and cached privately (per browser,
	 * never by a shared cache) because the answer depends on the account.
	 *
	 * @param string $lang          'el' or 'en'.
	 * @param string $locale        Locale the page was rendered in.
	 * @param string $version       Hash from the page's URL.
	 * @param string $if_none_match If-None-Match request header.
	 * @param bool   $en_allowed    Whether the requester may have 'en' (Pro).
	 * @return array{status: int, headers: array<string,string>, body: string}
	 */
	public static function i18n_response( $lang, $locale, $version, $if_none_match = '', $en_allowed = false ) {
		$headers = array(
			'Content-Type'           => 'application/javascript; charset=UTF-8',
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control'          => 'no-cache, must-revalidate, max-age=0',
			'Expires'                => 'Wed, 11 Jan 1984 05:00:00 GMT',
		);

		if ( 'el' !== $lang && 'en' !== $lang ) {
			return array(
				'status'  => 400,
				'headers' => $headers,
				'body'    => "/* PlanDose: unknown dictionary */\n",
			);
		}

		if ( 'en' === $lang && ! $en_allowed ) {
			return array(
				'status'  => 403,
				'headers' => $headers,
				'body'    => "/* PlanDose: not available for this account */\n",
			);
		}

		$switched = false;

		if ( self::is_locale_name( $locale ) && determine_locale() !== $locale ) {
			// admin-ajax.php runs in the user's admin locale; the page may
			// have been rendered in another (the site's). A locale that is
			// not installed is refused by switch_to_locale(), and the hash
			// check below then keeps the answer uncached.
			$switched = switch_to_locale( $locale );
		}

		$json = self::i18n_json( self::i18n_dictionary( $lang ) );
		$hash = self::i18n_hash( $lang, $json );

		if ( $switched ) {
			restore_previous_locale();
		}

		$body = 'window.PlandoseI18n=window.PlandoseI18n||{};window.PlandoseI18n.' . $lang . '=' . $json . ";\n";

		if ( ! is_string( $version ) || '' === $version || ! hash_equals( $hash, $version ) ) {
			return array(
				'status'  => 200,
				'headers' => $headers,
				'body'    => $body,
			);
		}

		// The Greek dictionary holds no per-user data, only the UI strings
		// and the pharmacy's printed texts, which every sheet shows, so any
		// cache may keep it. The English one is served per account (Pro).
		$headers['Cache-Control'] = ( 'en' === $lang ? 'private' : 'public' ) . ', max-age=' . YEAR_IN_SECONDS . ', immutable';
		$headers['Expires']       = gmdate( 'D, d M Y H:i:s', time() + YEAR_IN_SECONDS ) . ' GMT';
		$headers['ETag']          = '"' . $hash . '"';

		if ( is_string( $if_none_match ) && false !== strpos( $if_none_match, '"' . $hash . '"' ) ) {
			return array(
				'status'  => 304,
				'headers' => $headers,
				'body'    => '',
			);
		}

		return array(
			'status'  => 200,
			'headers' => $headers,
			'body'    => $body,
		);
	}

	/**
	 * admin-ajax.php?action=plandose_i18n — the dictionary script.
	 *
	 * admin-ajax rather than a REST route: it is what the tool already
	 * talks to, it needs no REST-specific output override to send
	 * JavaScript, and it works on sites that restrict the REST API to
	 * logged-in users. Registered for guests too (wp_ajax_nopriv_): the
	 * Greek dictionary is the same for everyone, so a CDN in front of the
	 * site may cache it; the English one only goes to signed-in Pro
	 * accounts that may use the tool. No nonce on purpose — it is a
	 * read-only, cacheable GET with no user data, and a nonce in the URL
	 * would defeat the cache.
	 */
	public static function serve_i18n() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public, read-only, cacheable GET (see above).
		$lang    = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		$locale  = isset( $_GET['locale'] ) ? sanitize_text_field( wp_unslash( $_GET['locale'] ) ) : '';
		$version = isset( $_GET['v'] ) ? sanitize_key( wp_unslash( $_GET['v'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';

		$en_allowed = 'en' === $lang && self::current_user_is_allowed() && self::current_user_is_pro();
		$response   = self::i18n_response( $lang, $locale, $version, $inm, $en_allowed );

		if ( ! headers_sent() ) {
			// admin-ajax.php has already sent nocache_headers() and a
			// text/html type; replace them.
			header_remove( 'Pragma' );
			header_remove( 'Last-Modified' );
			status_header( $response['status'] );

			foreach ( $response['headers'] as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'HEAD' !== $method ) {
			echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JavaScript built from wp_json_encode() output.
		}

		exit;
	}

	/**
	 * The ?ver= of a plugin asset — the plugin version plus the
	 * file's modification time.
	 *
	 * With the version alone, a re-uploaded build of the SAME version would
	 * keep the same URL, so a CDN (Bunny) or the browser would go on serving
	 * stale JavaScript next to the new PHP (e.g. the dictionaries change but
	 * the «Είδος» list, built in state.js, does not). The file time changes with
	 * every upload, so each deployed file gets a URL of its own.
	 *
	 * @param string $relative Path inside the plugin, e.g. assets/js/state.js.
	 * @return string
	 */
	private static function asset_version( $relative ) {
		static $cache = array();

		if ( ! isset( $cache[ $relative ] ) ) {
			$mtime              = @filemtime( PLANDOSE_PATH . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file is handled by assets_available(); the version then falls back.
			$cache[ $relative ] = PLANDOSE_VERSION . ( $mtime ? '.' . $mtime : '' );
		}

		return $cache[ $relative ];
	}

	/**
	 * Versioned URL of a tool asset for plandose-loader.js.
	 *
	 * Built the way WordPress builds an enqueued asset's URL (?ver=) and
	 * passed through the same core filters, so CDN / asset-rewrite plugins
	 * that hook script_loader_src / style_loader_src treat the lazily
	 * loaded files exactly like enqueued ones.
	 *
	 * @param string $relative Path inside the plugin, e.g. assets/js/app.js.
	 * @param string $handle   Handle the file would have been enqueued as.
	 * @param string $type     'script' or 'style'.
	 * @return string
	 */
	private static function lazy_asset_url( $relative, $handle, $type ) {
		$src = add_query_arg( 'ver', self::asset_version( $relative ), PLANDOSE_URL . $relative );
		$src = 'style' === $type
			? apply_filters( 'style_loader_src', $src, $handle )
			: apply_filters( 'script_loader_src', $src, $handle );

		return esc_url_raw( (string) $src );
	}

	/**
	 * Free-reprint window in whole minutes (Plandose_Ajax::PRINT_RECEIPT_TTL).
	 *
	 * @return int
	 */
	private static function reprint_window_minutes() {
		return max( 1, (int) floor( Plandose_Ajax::PRINT_RECEIPT_TTL / 60 ) );
	}

	/**
	 * Print window.PlandoseConfig before $handle.
	 *
	 * The same object wp_localize_script() prints (top-level scalars
	 * turned into strings with HTML entities decoded, exactly as it does),
	 * but encoded with JSON_UNESCAPED_UNICODE: wp_localize_script() writes
	 * every Greek letter as a 6-byte escape, which makes the ~30 KB of Greek
	 * dictionaries ~80 KB of inline HTML on every page a pharmacist opens.
	 * JSON_HEX_TAG / JSON_HEX_AMP keep «</script>» and entities out of the
	 * inline script; U+2028/U+2029 are escaped by PHP regardless.
	 *
	 * @param string $handle Script handle.
	 * @param array  $config Configuration.
	 */
	private static function add_config_script( $handle, $config ) {
		foreach ( $config as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$config[ $key ] = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
			}
		}

		$json = wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );

		if ( false === $json ) {
			wp_localize_script( $handle, 'PlandoseConfig', $config );

			return;
		}

		wp_add_inline_script( $handle, 'var PlandoseConfig = ' . $json . ';', 'before' );
	}

	/**
	 * The patient page of the «Υπενθυμίσεις στο κινητό» QR, or ''
	 * when the setting is off.
	 *
	 * public/calendar.html is a static file: no WordPress, no database, and
	 * a CSP with connect-src 'none'. The plan travels after «#», which is
	 * never sent to the server. ?v= makes phones fetch the page of this
	 * version after an update.
	 *
	 * Filter 'plandose_calendar_url' to serve a copy of the page from
	 * elsewhere (it must be an http(s) URL without a fragment). Keep that
	 * copy free of analytics or other third-party scripts: they can read
	 * the whole address, the plan included.
	 *
	 * @return string
	 */
	private static function calendar_url() {
		if ( ! Plandose_Settings::setting( 'calendar_qr', 1 ) ) {
			return '';
		}

		$url = (string) apply_filters(
			'plandose_calendar_url',
			add_query_arg( 'v', PLANDOSE_VERSION, PLANDOSE_URL . 'public/calendar.html' )
		);
		$url = esc_url_raw( $url, array( 'http', 'https' ) );

		return ( '' === $url || false !== strpos( $url, '#' ) ) ? '' : $url;
	}

	/**
	 * Admin text saved through sanitize_*_field() stores a bare "<" as
	 * "&lt;". The JS and esc_html() escape it again on output, which would
	 * print "&lt;" literally, so hand them plain text.
	 *
	 * @param string $value Sanitized text.
	 * @return string
	 */
	private static function plain_text( $value ) {
		return wp_specialchars_decode( (string) $value, ENT_QUOTES );
	}

	/**
	 * English counterpart of the localized 'i18n' array.
	 *
	 * Plain string literals (not wrapped in __()) on purpose: the Greek
	 * strings are the translation source captured in the .pot, and these
	 * English values live entirely in code so no .po/.mo is needed. Keys
	 * must stay in sync with the 'i18n' array in build_i18n().
	 *
	 * disclaimer / thanksLine1 / thanksLine2 still read the admin's saved
	 * settings first (falling back to an English default) — so if the
	 * pharmacy has entered its own custom text, that text is shown as-is
	 * in both languages, exactly like the Greek side.
	 *
	 * @param int $max_days Maximum allowed plan duration in days.
	 * @return array<string,mixed>
	 */
	private static function build_i18n_en( $max_days ) {
		$max_days = absint( $max_days );

		/*
		 * These fields are admin-editable. Compare their saved values with the
		 * literal Greek defaults stored by default_settings(). Do not call __()
		 * for this comparison: translated strings depend on the active WordPress
		 * locale and could make untouched defaults look like custom text.
		 * Genuine custom copy is kept verbatim because it cannot be translated
		 * safely or predictably.
		 */
		$greek_disclaimer = sanitize_textarea_field(
			'Το πλάνο αυτό είναι βοήθημα υπενθύμισης δοσολογίας και δεν αντικαθιστά την οδηγία ιατρού ή φαρμακοποιού. Σε περίπτωση αμφιβολίας συμβουλευτείτε τον φαρμακοποιό σας.'
		);
		$greek_thanks1 = sanitize_text_field( 'Ευχαριστούμε που εμπιστευτήκατε το φαρμακείο μας.' );
		$greek_thanks2 = sanitize_text_field( 'Για οποιαδήποτε διευκρίνηση είμαστε πάντα στη διάθεσή σας!' );

		$saved_disclaimer = sanitize_textarea_field( (string) Plandose_Settings::setting( 'disclaimer', '' ) );
		$saved_thanks1    = sanitize_text_field( (string) Plandose_Settings::setting( 'thanks_message_line1', '' ) );
		$saved_thanks2    = sanitize_text_field( (string) Plandose_Settings::setting( 'thanks_message_line2', '' ) );

		$en_disclaimer = ( '' === $saved_disclaimer || $saved_disclaimer === $greek_disclaimer )
			? 'This plan is a dosage reminder aid and does not replace the guidance of a doctor or pharmacist. If in doubt, consult your pharmacist.'
			: $saved_disclaimer;
		$en_thanks1 = ( '' === $saved_thanks1 || $saved_thanks1 === $greek_thanks1 )
			? 'Thank you for trusting our pharmacy.'
			: $saved_thanks1;
		$en_thanks2 = ( '' === $saved_thanks2 || $saved_thanks2 === $greek_thanks2 )
			? 'We are always here for any clarification!'
			: $saved_thanks2;

		return array(
			'modalTitle'          => 'Create Dosage Plan',
			'modalSubtitle'       => 'Add the patient\'s medicines and print a clear reminder plan.',

			'stepPatient'         => 'Details & Medicines',
			'stepPreview'         => 'Preview',
			'stepsAria'           => 'PlanDose steps',
			'back'                => 'Back',
			'preview'             => 'Preview',

			'patientSection'      => 'Patient Details',
			'drugSection'         => 'Medicines',
			'patient'             => 'Patient (optional)',
			'rxOpen'               => 'Paste prescription',
			'rxNew'                => 'NEW',
			'rxDropPlaceholder'    => 'Press Ctrl+V to paste the prescription — it is read at once.',
			'rxDropPlaceholderTouch' => 'Press and hold here and choose “Paste” — the prescription is read at once.',
			'rxDropTitle'          => 'Paste the whole e-prescription here',
			'rxDropHelp'           => 'Copy all the text of the prescription (Ctrl+A → Ctrl+C), click here and press Ctrl+V. Medicines, dosage and days are filled in automatically.',
			'rxPlaceholder'        => 'Paste the whole text of the e-prescription here…',
			'rxPrivacy'            => 'The text is read only in this browser — it is not sent or stored.',
			'rxRead'               => 'Read',
			'rxCancel'             => 'Cancel',
			'rxNone'               => 'No medicines found in this text — copy the whole prescription and try again.',
			'rxFound'              => '%d medicines found. Check them before they go into the plan.',
			'rxPatient'            => 'Patient name: %s',
			'rxSource'             => 'Prescription: %s',
			'rxDays'               => '%s days',
			'rxTimeLabel'          => 'Time of day',
			'rxAdd'                => 'Add to plan (%d)',
			'rxToForm'             => 'Complete in the form',
			'rxInvalid'            => 'Cannot be added as it is — complete it in the form.',
			'rxAdded'              => '%d medicines added from the prescription. Check the plan before printing.',
			'rxLeft'               => '%d left to complete in the form.',
			'rxWarn_name'          => 'Check the medicine name.',
			'rxWarn_dose'          => 'The dosage could not be read — fill it in.',
			'rxWarn_amount'        => 'The quantity is not valid — fill it in.',
			'rxWarn_unit'          => 'Choose the dose form.',
			'rxWarn_iuConfirm'     => 'Insulin: the quantity was read as units (IU) — confirm it in the form.',
			'rxWarn_injectionQty'  => 'The quantity does not fit injections — check units and form.',
			'rxWarn_freq'          => 'The frequency could not be read — fill it in.',
			'rxWarn_freqWeekly'    => 'Weekly dosing that could not be read — fill it in.',
			'rxWarn_onceDays'      => 'Single dose, but for more days — check the duration.',
			'rxWarn_phrase'        => 'The dosage has words or numbers that were not recognised — check the quantity and “Type”.',
			'rxWarn_unitsOrInjection' => 'Strength in units/ml: check whether the quantity is injections or units (IU).',
			'rxWarn_weekDay'       => 'The first dose falls on the plan’s start date — check that it is the right day.',
			'rxWarn_time'          => 'The prescription gives no time of day — choose it.',
			'rxWarn_methotrexateDaily' => 'WARNING: methotrexate is usually taken once a week. Confirm the frequency.',
			'rxWarn_extra'         => 'The dosage has more text that was not read — check the prescription.',
			'rxWarn_intervalCount' => 'The days are not a whole number of intervals: the plan gets one more dose (e.g. 30 days weekly = 5 doses). Check the duration against what was dispensed.',
			'rxWarn_monthly'      => '«Once a month» was entered as «every 30 days» — check the dates.',
			'invalidItemForPrint' => 'Check the medicine «%s»: the amount, form, frequency or duration is not valid. Press «Edit».',
			'methotrexatePrintConfirm' => 'WARNING: methotrexate more often than once a week. If the frequency is right, press «Print» again.',
			'rxWarn_duplicate'     => 'Already listed above — not selected.',
			'rxWarn_inPlan'        => 'Already in the plan — not selected.',
			'rxLoaded'             => 'Loaded into the form — finish it there.',
			'rxManyPatients'       => 'The text has prescriptions for different patients (%s). Check that all medicines belong to the same patient.',
			'rxOtherPatient'       => 'The plan already has patient “%1$s” — the prescription is for “%2$s”.',
			'rxPickUnit'           => 'Choose the “Type”.',
			'rxPickFreq'           => 'Choose how often — the prescription did not give it with certainty.',

			'patientHelp'         => 'Optional field. It will appear on the printout.',
			'patientPlaceholder'  => 'e.g. John Smith',

			'startDate'           => 'Start date',
			'startDateHelp'       => 'The day the plan starts.',
			'startToday'          => 'Today',
			'startTomorrow'       => 'Tomorrow',
			'invalidStartDate'    => 'The start date is not valid.',
			'startDateInPast'     => 'The start date has passed. Choose today or a later day.',
			'startDateTooFar'     => 'The start date cannot be more than %d days from today.',
			'planStartsOn'        => 'Plan starts:',

			'firstSlotLabel'      => 'First dose on the start day',
			'firstSlotHelp'       => 'Doses that have already passed move to the end — the total number of doses does not change.',
			'courseLine'          => 'First dose: %1$s %2$s • Last dose: %3$s %4$s',

			'drug'                => 'Medicine name',
			'drugPlaceholder'     => 'e.g. Depon 500mg',
			'doseAmount'          => 'Amount',
			'doseUnit'            => 'Type',

			'unitTablet'          => 'Tablet(s)',
			'unitCapsule'         => 'Capsule(s)',
			'unitMl'              => 'ml',
			'unitMg'              => 'mg',
			'unitDrops'           => 'Drop(s)',
			'unitAmpoule'         => 'Ampoule(s)',
			'unitSachet'          => 'Sachet(s)',
			'unitInhale'          => 'Inhalation(s)',
			'unitApplication'     => 'Application(s)',
			'unitSpray'           => 'Spray(s)',
			'unitSuppository'     => 'Suppository(ies)',
			'unitPatch'           => 'Patch(es)',
			'unitInjection'       => 'Injection(s)',
			'unitIu'              => 'Units (IU)',

			'frequency'           => 'Frequency',
			'dailyTimeLabel'      => 'Time of day',
			'times1'              => 'Once a day',
			'times2'              => 'Twice a day',
			'times3'              => '3 times a day',
			'times4'              => '4 times a day',
			'missingDoseAmount'   => 'Enter the dose quantity for: ',
			'invalidDoseAmount'   => 'The dose quantity is not valid. Enter a number such as 1, 1.5, 0.25, ½ or 1/2.',
			'doseAmountThousands' => 'The quantity "%s" is ambiguous: it can be read as thousands. Enter e.g. 1 or 1.5 — or 1000 for one thousand.',
			'doseAmountMixed'     => 'The quantity "%1$s" reads as %2$s. If you mean %3$s, type it with a space ("%3$s") or as a decimal (e.g. 1.5).',
			'doseAmountMixedPlain' => 'The quantity "%1$s" reads as %2$s. Check the value: type a mixed number with a space (e.g. 1 1/2) or a decimal (e.g. 1.5).',
			'doseAmountDecimals'  => 'The dose quantity can have at most 2 decimal places (e.g. 0.25).',
			'doseAmountZero'      => 'The dose quantity must be greater than zero.',
			'doseAmountTooLarge'  => 'The dose quantity cannot exceed %s per intake. Check the value.',
			'doseTotalLine'       => 'Total: %1$s doses • %2$s %3$s',
			'doseTotalDosesOnly'  => 'Total: %1$s doses',
			'freqCustom'          => 'Custom frequency',
			'customModeLabel'     => 'Custom frequency mode',
			'customModeDays'      => 'By days',
			'customModeWeekday'   => 'By weekday',
			'weekdayLabel'        => 'Which day?',
			'customIntervalLabel' => 'Every how many days?',
			'customIntervalPlaceholder' => 'e.g. 7',
			'presetWeekly'        => 'Week (7)',
			'presetBiweekly'      => '2 wks (14)',
			'preset15'            => '15 days',
			'missingIntervalDays' => 'Enter how many days between doses (1–90).',
			'noDosesInPeriod'     => 'With this duration no dose falls in the plan — increase the duration or change the day. Medicine: ',
			'labelCustomer' => 'Customer',
			'labelDay'      => 'day',
			'labelDrug'     => 'Medicine',
			'labelDosage'   => 'Dosage',
			'labelNotesKey' => 'Notes',
			'labelPhone'    => 'Tel.',
			'labelPharmacy' => 'PHARMACY',
			'labelForDays'   => 'For %d days',
			'labelForOneDay' => 'For 1 day',
			'doseRowLabel'        => 'Dose',
			'yourMedicines'       => 'Your medicines:',
			'forNDays'            => 'For %d days',
			'forOneDay'           => 'For 1 day',
			'dayNumber'           => 'Day %d',
			'anyTimeOfDay'        => 'Any time of day',
			'every7Days'          => 'Once a week',
			'every14Days'         => 'Every 2 weeks',
			/* translators: %d: number of days between doses. */
			'everyNDays'          => 'Every %d days',
			/* translators: %d: number of hours between doses. */
			/* translators: %s: weekday name, e.g. "Monday". */
			'everyWeekday'        => 'Every %s',

			'notes'               => 'Notes (optional)',
			'notesPlaceholder'    => 'e.g. Take after food',
			'duration'            => 'Duration (days)',

			'add'                 => 'Add Medicine',
			'saveDrug'            => 'Save Medicine',
			'unsavedDrug'         => 'You have filled in a medicine that has not been added to the plan. Press "Add Medicine" or clear the form before continuing.',
			'unsavedDrugEditing'  => 'You have not saved the changes to the medicine you are editing. Press "Save Medicine" or "Clear" before continuing.',
			'clearForm'           => 'Clear',
			'clearFormAria'       => 'Clear the medicine form',
			'formCleared'         => 'The medicine form was cleared.',
			'editCancelled'       => 'Editing cancelled — the medicine in the plan was not changed.',
			'edit'                => 'Edit',
			'deleteAria'          => 'Delete',
			'addedDrugs'          => 'Medicines in the plan',

			'emptyListTitle'      => 'No medicines added yet.',
			'emptyList'           => 'Add at least one medicine to continue.',

			'scheduleReviewTitle' => 'Plan review',
			'scheduleReviewText'  => 'Check the medicines before printing.',
			'previewTitle'        => 'Print Preview',
			'previewText'         => 'This is the plan that will be printed.',

			'print'               => 'Print Plan',
			'checkingPrint'       => 'Checking print...',
			'printing'            => 'Printing...',
			'pleaseWait'          => 'Please wait...',

			'drugAdded'           => 'The medicine was added to the plan.',
			'drugUpdated'         => 'The medicine was updated.',
			'drugUnchanged'       => 'Nothing was changed in the medicine.',
			'labelStartOn'        => 'Start: %s',
			'labelFirstDose'      => '1st dose: %s',
			'planReadyForNext'    => 'The plan was printed. The form has been cleared — ready for the next patient.',

			'missingDrugName'     => 'Enter the medicine name.',
			'missingDays'         => 'Missing duration (days) for: ',
			'invalidCustomMode'   => 'Select a valid custom frequency mode.',
			/* translators: %d: max allowed days */
			'maxDaysExceeded'     => sprintf( 'Duration cannot exceed %d days.', $max_days ),
			'addAtLeastOne'       => 'Add at least one medicine.',

			'genericError'        => 'Something went wrong.',
			'confirmCloseText'    => 'Are you sure you want to stop this action? The plan details will be lost and this cannot be undone.',
			'yes'                 => 'Yes',
			'no'                  => 'No',
			'connectionError'     => 'Connection problem. Please try again.',
			'printNotCounted'     => 'The print was not recorded. Please try again.',
			'reprint'             => 'Print again',
			'newPatient'          => 'New patient',
			'reprintedFree'       => 'Printed again at no charge. Free reprints left for this plan: %d.',
			'printedPlanChanged'  => 'The plan was printed. You changed the plan meanwhile, so the next print counts as a new one.',
			'printedIdleCleared'  => 'The plan was cleared automatically after %d minutes of inactivity.',
			'idleWarningOne'      => 'The plan will be cleared in 1 minute due to inactivity. Press “Keep it” or carry on working to keep it on screen.',
			'idleWarningMany'     => 'The plan will be cleared in %d minutes due to inactivity. Press “Keep it” or carry on working to keep it on screen.',
			'idleWarningKeep'     => 'Keep it',
			'sessionExpired'      => 'The page was open too long and your session expired. Refresh the page (F5) and try again.',

			'days'                => 'days',
			'time'                => 'Time',
			'weekLabel'           => 'Week',

			'printTitle'          => 'Dosage Plan',
			'pharmacySection'     => 'Pharmacy Details',
			'printedOn'           => 'Printed on',

			/* Pro-only medicine labels (printed separately, on the label printer). */
			'labelsTitle'         => 'Medication Labels',
			'labelsPreviewIntro'  => 'Printed separately, on the label printer, with the "Print Labels" button.',
			'labelSizeLabel'      => 'Label size (on this computer)',
			'labelSizeAuto'       => 'As now (from the printer)',
			'labelSizeCustom'     => 'Other size…',
			'labelWidth'          => 'Width (mm)',
			'labelHeight'         => 'Height (mm)',
			'labelApply'          => 'Apply',
			'labelCustomInvalid'  => 'Invalid size.',
			'labelCustomRange'    => 'As printed on the label box. From %1$s × %2$s to %3$s × %4$s mm.',
			'labelDriverHint'     => 'In the printer settings choose a %1$s × %2$s mm label. In the print dialog: Margins "None", Scale 100%.',
			'labelTestPrint'      => 'Test label',
			'labelTestPatient'    => 'TEST LABEL',
			'labelTestNotes'      => 'TEST — after food, with a glass of water, not milk',
			'labelSizeHint'       => 'Choose the size of your labels: then every medicine always fits on one label.',
			'labelTrimmed'        => 'Shortened to fit: %s. The A4 plan prints them in full.',
			'labelNotesCutConfirm' => 'The notes do not fit on the label in full and will be shortened or left out: %s. Check the preview. Press "Print Labels" again to print them this way, or choose a larger label.',
			'labelTooLong'        => '"%s" and its dosage do not fit on one label of this size. Shorten the name or choose a larger label.',
			'printLabels'         => 'Print Labels',
			'labelsPopupBlocked'  => 'The browser blocked the label print window. Allow pop-ups for this site and try again.',
			'planReadyForNextPro' => 'The plan was printed. You can still print its labels with "Print Labels" — or start the next patient.',
			'durationLabel'       => 'Duration',
			/* translators: %s: pharmacy name */
			'printIntro'          => 'Pharmacy %s prepared this plan to help you organise and stay consistent with taking your medicines.',
			/* translators: %d: remaining prints this month */
			'printsRemaining'     => '%d prints remaining this month.',
			'printsUnlimited'     => 'Unlimited prints.',
			'printCreditHint'     => sprintf( 'A print counts as soon as the print window opens, even if you press Cancel. Without changes to the plan, printing it again is free up to %1$d times within %2$d minutes.', Plandose_Ajax::MAX_FREE_REPRINTS, self::reprint_window_minutes() ),

			'disclaimer'          => self::plain_text( $en_disclaimer ),
			'thanksLine1'         => self::plain_text( $en_thanks1 ),
			'thanksLine2'         => self::plain_text( $en_thanks2 ),

			// «Midday» / «Night», not «Noon» / «Evening»: «Noon»
			// reads as exactly 12:00 and «Evening» sits too close to
			// «Afternoon»; the four read as clearly separate parts of the
			// day, matching the Greek Πρωί / Μεσημέρι / Απόγευμα / Βράδυ.
			'morning'             => 'Morning',
			'noon'                => 'Midday',
			'afternoon'           => 'Afternoon',
			'evening'             => 'Night',

			'rxWarn_iuSyringe' => 'A strength in units per ml in a single prefilled syringe: confirm that the dose is one injection and that the medicine is not insulin.',
			'insulinUnitsRequired' => 'Insulin: the dose is given in units (IU). Choose “Units (IU)” and enter the units from the prescription.',
			'rxWarn_insulinUnits' => 'Insulin: the dose is given as injections, not units — enter the dose in units (IU) in the form.',
			'rxWarn_insulinMl'    => 'Insulin: the dose is written in ml or mg, not units — enter the dose in units (IU) in the form.',
			'calQrTitle'          => 'Reminders on your phone',
			'calQrText'           => 'Scan with your phone camera: the doses go into your calendar with an alert.',
			'calQrPrivate'        => 'Not sent to the pharmacy. The QR contains the plan’s medicines.',
			'calQrTooBig'         => 'The plan is too large for the “Reminders on your phone” QR: the sheet will print without a QR.',
			'calQrNoNotes'        => 'The medicine notes do not fit in the “Reminders on your phone” QR: the phone reminders will have no notes. The printed sheet has them as usual.',
			'calQrNoPharmacy'     => 'The pharmacy name (and any medicine notes) do not fit in the “Reminders on your phone” QR: the phone reminders will be without them. The printed sheet has them as usual.',
			'rxWarn_everyHours'   => 'The prescription says “every … hours”: the plan places the doses Morning / Midday / Night, not on an exact clock schedule. If they must be evenly spaced, write the times in the notes.',
			'rxWarn_unitForm'     => 'The dose does not match the form of the medicine (e.g. an injection for tablets) — check that this dose line belongs to this medicine.',
			'rxWarn_shortDuration' => 'The duration looks very short for the quantity dispensed — check the duration.',
			'rxWarn_strengthCheck' => 'The strength is missing or was only partly read — compare the name with the prescription and confirm.',
			'rxWarn_strengthZero' => 'The strength is written without its leading zero (e.g. “.25MG”) and was entered with the zero (0.25MG) — compare the name with the prescription in the form.',
			'rxWarn_strengthSplit' => 'The strength number is split by a space (e.g. “0. 25MG”, “1 . 5MG”) and was entered joined (0.25MG, 1.5MG) — compare the name with the prescription in the form.',
			'rxWarn_strengthSep' => 'There is a dot, comma or another number right before the strength (e.g. “1X. 5MG”, “0 25MG”) — a zero or decimal point may be missing. Compare the name with the prescription in the form.',
			'rxWarn_weeklyOnly' => 'WARNING: this medicine is normally given once a week, here it is more often. Verify the frequency with the prescriber.',
			/* translators: %s: medicine name. */
			'weeklyOnlyPrintConfirm' => 'WARNING: “%s” more often than once a week — it is normally given once a week. If the frequency has been verified with the prescriber, press “Print” again.',
			'rxFoundOne' => '1 medicine found. Check it before it goes into the plan.',
			'doseAmountDecimalsMg' => 'A quantity in mg can have at most 3 decimal places (e.g. 0.125).',
			/* translators: %s: medicine name. */
			'drugDuplicateConfirm' => '“%s” is already in the plan. If it must be added a second time (e.g. another dose at other times), press the same button again — otherwise press “Edit” on the medicine in the list.',
			/* translators: %s: medicine names. */
			'methotrexateRepeatedPrintConfirm' => 'WARNING: methotrexate in more than one plan entry (“%s”) — together they may give a dose more often than once a week. If this is correct, press “Print” again.',
			/* translators: %s: medicine names. */
			'weeklyRepeatedPrintConfirm' => 'WARNING: “%s” in more than one plan entry — it is normally given once a week. If this is correct, press “Print” again.',
			'weeklyRepeatedWarn' => 'WARNING: this medicine is normally given once a week and is already in another plan entry. Confirm that together they do not give a dose more often than intended.',
			'rxWarn_dispensedQty' => 'The quantity dispensed does not match the plan (far more or far less) — check the amount, frequency and duration against the prescription.',
			/* translators: 1: units dispensed (e.g. «60 δισκία»), 2: units the plan needs. */
			'rxDispensed' => 'Dispensed %1$s · the plan needs %2$s',
			/* translators: %d: number of once-daily medicines without a time. */
			'rxBulkTime' => 'Same time for all once-a-day medicines (%d):',
			'rxBulkMark' => '(for all)',
			/* translators: 1: chosen time of day, 2: number of medicines. */
			'rxBulkDone' => '“%1$s”: set for %2$d medicines.',
			'rxWarn_brandWords' => 'The name has more than one word before the dose form (highlighted) — confirm they belong to the medicine name (e.g. “PO”, “HS” do not).',
			/* translators: %s: the unread text, quoted. */
			'rxUnreadText' => 'The prescription has text that was not read: «%s — check the prescription.',
			'rxWarn_variableDose' => 'The prescription has a series of quantities (e.g. a different dose per day) — not a fixed dose. Enter the schedule in the form.',
			'printReplayLimitKeep' => 'This print attempt has already been repeated as many times as allowed. Nothing was charged or printed now. If you press “Print” again, it will be a free reprint if any are left (see the billing line) — otherwise it will count as a new print.',
			'rxTooLarge' => 'The text is too large for a prescription — copy only the prescription and try again.',
			/* translators: %d: number of unread lines that look like dosage lines. */
			'rxUnreadDoseLines' => '%d lines look like a dosage but were not read — a medicine may be missing. Check the prescription.',
			'rxWarn_monthlyCount' => 'Once a month: the number of doses depends on the day you choose — check the dates against what was dispensed.',
			'customModeMonthDay' => 'Day of the month',
			'monthDayLabel' => 'Which day of the month? (1–31)',
			'monthDayPlaceholder' => 'e.g. 15',
			'monthDayHelp' => 'The dose falls on this day every month.',
			/* translators: %1$d: chosen day of the month (29-31). */
			'monthDayLastRule' => 'When a month has fewer than %1$d days (e.g. February, April), the dose falls on the last day of that month.',
			'preset30' => '30 d.',
			'rxFromPrescription' => 'From the prescription:',
			/* translators: %1$s: number of doses (always 1). */
			'doseTotalOneDoseOnly' => 'Total: %1$s dose',
			/* translators: 1: number of doses (1), 2: total quantity, 3: unit. */
			'doseTotalLineOne' => 'Total: %1$s dose • %2$s %3$s',
			'everyOneDay' => 'Every day',
			'everyWeekdayUnset' => 'Once a week (choose the day)',
			'everyMonthDayUnset' => 'Once a month (choose the day)',
			/* translators: %d: day of the month (1-28). */
			'everyMonthDay' => 'Once a month, on day %d',
			/* translators: %d: day of the month (29-31). */
			'everyMonthDayLast' => 'Once a month, on day %d (or the last day of the month)',
			'rxWeekdayLabel' => 'Day of the week',
			'rxMonthDayLabel' => 'Day of the month (1–31)',
			'rxConfirm' => 'I confirm',
			'rxFieldName' => 'Medicine',
			'rxFieldQty' => 'Quantity',
			'rxFieldFreq' => 'Frequency',
			'rxFieldDays' => 'Duration',
			'rxOriginal' => 'On the prescription',
			'rxAddBlocked' => 'To enable “Add”, choose or confirm the marked fields of the selected medicines.',
			'rxCountAck' => 'I have checked the prescription and know which medicines are missing from the list.',
			'rxPatientConfirm' => 'I confirm that the medicines I select belong to the patient of this plan.',
			/* translators: 1: medicines found on the prescription, 2: medicines read. */
			'rxCountMismatch' => 'The prescription seems to list %1$d medicines, %2$d were read — check it.',
			/* translators: %s: the quantity as typed. */
			'doseAmountMixedWhole' => 'The quantity “%s” is not a valid mixed number: the numerator of the fraction must be smaller than the denominator (e.g. 1 1/2). Check the value.',
			'pickDailyTime' => 'Choose the time of day (Morning, Midday, Afternoon or Night).',
			'pickWeekday' => 'Choose the day of the week.',
			'pickMonthDay' => 'Choose the day of the month (1–31).',
			/* translators: 1: quantity per intake, 2: unit. */
			'doseUnusualConfirm' => 'Unusually large quantity: %1$s %2$s per intake. If it is correct, press the same button again to confirm.',
			'rxWarn_strength' => 'The strength could not be read with certainty and was left out of the name — check the name.',
			'rxWarn_noDose' => 'A medicine was found without a “ΔΟΣΟΛΟΓΙΑ” line — fill in the dosage from the prescription.',
			'rxWarn_tooLong' => 'The dosage line is too long and was not read — fill it in.',
			'rxWarn_highQty' => 'Unusually large quantity per intake — confirm it against the prescription.',
			'rxWarn_asNeeded' => 'The prescription says “when needed” (SOS / in case / if) — it is not a fixed schedule. Write the instruction in the notes.',
			'rxWarn_sameDrugOtherDose' => 'The same medicine is also listed with another dosage — choose which one applies.',
			'rxWarn_pickWeekday' => 'Once a week: choose the day of the week.',
			'rxWarn_pickMonthDay' => '“Once a month”: choose the day of the month. If a month does not have that day, the dose falls on the last day of the month.',
			'rxWarn_iuConfirmHere' => 'Insulin: the quantity was read as units (IU) — confirm it.',
			'rxWarn_intervalCountCheck' => 'The days are not a whole number of weeks/intervals: check the number of doses (e.g. 30 days weekly = 4 or 5 doses) against what was dispensed.',
			'everyDayTimeUnset' => 'Once a day (choose the time)',
			'notesLabelHint' => 'Only part of the notes may fit on the labels; you will be asked to confirm if they are cut. The plan always prints them in full.',
			/* translators: %s: local time the print was charged (HH:MM). */
			'billingRecordedAt' => 'The print was charged at %s.',
			/* translators: 1: free reprints left, 2: local time until which they are free (HH:MM). */
			'billingFreeLeft' => 'Free reprints: %1$d, until %2$s.',
			'billingNoFreeLeft' => 'No free reprints left — the next print is charged.',
			'billingNextCharged' => 'The next print is charged as a new one.',
			'billingCannotKnow' => 'The browser cannot know whether the printer finished printing — check the paper.',
			/* translators: 1: local time until which reprints are free (HH:MM), 2: number of free reprints. */
			'printedMaybeNoDialog' => 'The charge was recorded; if the print dialog did not open, press “Print again” — free until %1$s (up to %2$d times) while the plan stays on screen.',
			/* translators: %d: free-reprint window in minutes. */
			'printedReprintChargedWindow' => 'The print was charged as a new one: this plan\'s free reprints are used up or %d minutes have passed since the first print.',
			/* translators: 1: number of free reprints, 2: local time until which they are free (HH:MM), 3: minutes of inactivity before the plan is cleared. */
			'printedKeepPlanWindow' => 'The print was recorded. If it did not print correctly (e.g. you pressed Cancel or chose the wrong printer), press “Print again” — no new charge, up to %1$d times, until %2$s. After %3$d minutes without activity the plan is cleared automatically. For the next patient press “New patient”.',
			'printWindowClosedCancelled' => 'The print was cancelled or the window was blocked — no new print was recorded. Press “Print” again; if the window does not open, allow pop-ups for this site.',
			/* translators: 1: local time until which the retry is free (HH:MM), 2: number of free retries, 3: minutes of inactivity before the plan is cleared. */
			'printRetryFreeUntil' => 'The print did not open, but the charge was recorded. Press “Print” again until %1$s — you will not be charged twice (up to %2$d retries), even if you correct the plan first or it is cleared after %3$d minutes of inactivity.',
			'printPlanChangedCarried' => 'The plan changed while the print was being recorded, so it was not printed. The charge was recorded: press “Print” again for the current plan — no new charge.',
			'printPlanChangedNoSheet' => 'The plan changed while the reprint was being recorded, so it was not printed. The next print will count as a new one.',
			'headerEmpty' => 'The pharmacy details (name) for the sheet were not found. Fill them in on your account profile and try again.',
			/* translators: 1: local time until which the retry is free (HH:MM), 2: number of free retries. */
			'printNotRecordedUntil' => 'Connection problem. It is not certain whether the print was charged. Press “Print” again until %1$s — if it was charged, you will not be charged twice (up to %2$d retries), even if you correct the plan first.',
			'closeBlockedPrinting' => 'A print is in progress — the window cannot close until it finishes.',
			'labelPatientInitialsConfirm' => 'The customer\'s name does not fit and only the initials will be printed. Press “Print labels” again to print it this way, or choose a larger label.',
			'dayCardNotesSeeList' => '(see the medicine list)',
			'dayCardContinued' => '(continued)',
		);
	}

	/**
	 * Render floating button and modal shell.
	 */
	public static function render_modal() {
		if ( ! self::should_render() ) {
			return;
		}

		$position     = self::button_position();
		$color        = self::button_color();
		$text         = sanitize_text_field( (string) Plandose_Settings::setting( 'button_text', __( 'Πλάνο Δόσεων', 'plandose' ) ) );
		$text         = $text ? $text : __( 'Πλάνο Δόσεων', 'plandose' );
		$is_logged_in = is_user_logged_in();
		$is_allowed   = $is_logged_in && self::current_user_is_allowed();
		$is_guest_ui  = ! $is_allowed;
		$is_pro       = $is_allowed && self::current_user_is_pro();
		$free_limit   = Plandose_Settings::free_monthly_limit();
		$login_url    = self::login_url();

		$guest_bullets = array(
			__( 'Δημιουργεί πλάνο δοσολογίας για κάθε ασθενή σε λίγα δευτερόλεπτα.', 'plandose' ),
			__( 'Δίνει στον ασθενή ένα καθαρό checklist για το σπίτι.', 'plandose' ),
			__( 'Μειώνει τις επαναλαμβανόμενες ερωτήσεις για το πότε λαμβάνεται κάθε φάρμακο.', 'plandose' ),
			__( 'Προσφέρει πιο επαγγελματική εξυπηρέτηση μέσα από ένα απλό εκτυπώσιμο πλάνο.', 'plandose' ),
			__( 'Διαθέσιμο μόνο για εγγεγραμμένα φαρμακεία.', 'plandose' ),
		);
		?>
		<?php
		/*
		 * WCAG 2.5.3, Label in Name: the visible text is
		 * «PlanDose», so the accessible name starts with it — a voice
		 * user saying «click PlanDose» finds the button. The configured
		 * «Κείμενο κουμπιού» follows it (and stays the tooltip).
		 */
		$brand      = __( 'PlanDose', 'plandose' );
		$aria_label = ( false === stripos( (string) $text, $brand ) ) ? $brand . ' — ' . $text : (string) $text;
		?>
		<button
			id="plandose-trigger"
			class="plandose-pos-<?php echo esc_attr( $position ); ?>"
			type="button"
			aria-label="<?php echo esc_attr( $aria_label ); ?>"
			aria-haspopup="dialog"
			aria-controls="plandose-modal"
			aria-expanded="false"
			title="<?php echo esc_attr( $text ); ?>"
			style="background: <?php echo esc_attr( $color ); ?>;"
		>
			<span class="plandose-trigger-text"><?php esc_html_e( 'PlanDose', 'plandose' ); ?></span>
			<span class="plandose-icon" aria-hidden="true"></span>
		</button>

		<div id="plandose-overlay" hidden>
			<div
				id="plandose-modal"
				role="dialog"
				aria-modal="true"
				aria-labelledby="plandose-modal-title"
				aria-describedby="plandose-modal-description"
			>
				<button
					id="plandose-close"
					type="button"
					aria-label="<?php esc_attr_e( 'Κλείσιμο', 'plandose' ); ?>"
				>×</button>

				<?php if ( ! $is_guest_ui ) : ?>
					<?php /* Language toggle (EL/EN). Shown to every pharmacist, but switching is a Pro-only feature: for non-Pro it renders locked (padlock icon, dashed border, class is-locked) with the same "EN/EL" label, as a teaser, and a click only reveals the data-hint text. The actual switch is gated again in JS (PD.setLang bails when !IS_PRO). */ ?>
					<button
						id="plandose-lang-toggle"
						type="button"
						data-pro="<?php echo $is_pro ? '1' : '0'; ?>"
						data-hint="<?php esc_attr_e( 'Διαθέσιμο στο Pro', 'plandose' ); ?>"
						class="plandose-lang-toggle<?php echo $is_pro ? '' : ' is-locked'; ?>"
						aria-label="<?php echo esc_attr( $is_pro ? __( 'Αλλαγή γλώσσας', 'plandose' ) : __( 'Αλλαγή γλώσσας — διαθέσιμο στο Pro', 'plandose' ) ); ?>"
					>
						<span class="plandose-lang-label"> <?php echo esc_html( 'EN/EL' ); ?> </span>
						<?php if ( ! $is_pro ) : ?>
							<span class="plandose-lang-lock" aria-hidden="true"></span>
						<?php endif; ?>
					</button>
				<?php endif; ?>

				<div id="plandose-modal-head">
					<span class="plandose-modal-icon-wrap" aria-hidden="true">
						<span class="plandose-modal-icon"></span>
					</span>

					<div>
						<h2 id="plandose-modal-title">
							<?php if ( $is_guest_ui ) : ?>
								<?php esc_html_e( 'Τι είναι το', 'plandose' ); ?>
								<span class="plandose-title-green"><?php esc_html_e( 'PlanDose', 'plandose' ); ?></span>
							<?php else : ?>
								<?php esc_html_e( 'Δημιουργία Πλάνου Δοσολογίας', 'plandose' ); ?>
							<?php endif; ?>
						</h2>

						<p id="plandose-modal-description">
							<?php
							if ( $is_guest_ui ) {
								echo esc_html(
									__( 'Δημιουργήστε καθαρά πλάνα δοσολογίας και βοηθήστε τον ασθενή να ακολουθεί σωστά την αγωγή του.', 'plandose' )
								);
							} else {
								esc_html_e( 'Συμπληρώστε τα φάρμακα του ασθενή και εκτυπώστε ένα καθαρό πλάνο υπενθύμισης.', 'plandose' );
							}
							?>
						</p>
					</div>
				</div>

				<div id="plandose-app">
					<?php if ( $is_guest_ui ) : ?>
						<div class="plandose-guest">
							<div class="plandose-guest-freebox">
								<strong><?php esc_html_e( 'Δωρεάν περιβάλλον', 'plandose' ); ?></strong>
								<span>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: free monthly print limit */
											__( 'Στο δωρεάν περιβάλλον έχετε %d εκτυπώσεις τον μήνα.', 'plandose' ),
											$free_limit
										)
									);
									?>
								</span>
							</div>

							<ul class="plandose-guest-points">
								<?php foreach ( $guest_bullets as $bullet ) : ?>
									<li><?php echo esc_html( $bullet ); ?></li>
								<?php endforeach; ?>
							</ul>

							<?php
							/*
							 * The admin's own message to logged-out visitors.
							 * Empty setting prints nothing at all.
							 */
							$guest_message = self::plain_text( sanitize_textarea_field(
								(string) Plandose_Settings::setting( 'guest_message', '' )
							) );

							if ( '' !== trim( $guest_message ) ) :
								?>
								<p class="plandose-guest-message"><?php echo nl2br( esc_html( $guest_message ) ); ?></p>
								<?php
							endif;
							?>

							<a class="plandose-btn plandose-btn-primary plandose-guest-cta" href="<?php echo esc_url( $login_url ); ?>">
								<?php esc_html_e( 'Είσοδος', 'plandose' ); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}
}