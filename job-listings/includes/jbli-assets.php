<?php
/**
 * Frontend Assets
 *
 * @package JobListings
 * @since   9.7.9
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'jbli_enqueue_assets' ) ) {

	function jbli_enqueue_assets() {

		if ( is_admin() ) {
			return;
		}

		$jbli_modules = jbli_required_asset_modules();

		/* Stylesheets required by this request. Empty on pages with no listings content. */
		$jbli_css_modules = jbli_required_css_modules( $jbli_modules );

		if ( ! empty( $jbli_css_modules ) ) {

			$jbli_tokens_file = JBLI_DIR . 'includes/jbli-tokens.css';

			if ( is_readable( $jbli_tokens_file ) ) {
				wp_enqueue_style(
					'jbli_tokens',
					JBLI_URL . 'includes/jbli-tokens.css',
					array(),
					jbli_asset_version( $jbli_tokens_file )
				);
			}

			foreach ( $jbli_css_modules as $jbli_mod ) {

				$jbli_css_file = JBLI_DIR . "modules/{$jbli_mod}/css/jbli-{$jbli_mod}.css";

				if ( is_readable( $jbli_css_file ) ) {
					wp_enqueue_style(
						"jbli_{$jbli_mod}_style",
						JBLI_URL . "modules/{$jbli_mod}/css/jbli-{$jbli_mod}.css",
						/* apply overrides the older modal rules in jbli-recent.css, so it loads after them. */
						'apply' === $jbli_mod && in_array( 'recent', $jbli_css_modules, true ) ? array( 'jbli_tokens', 'jbli_recent_style' ) : array( 'jbli_tokens' ),
						jbli_asset_version( $jbli_css_file )
					);
				}
			}
		}

		/* Enqueue JS scripts for required modules */
		if ( empty( $jbli_modules ) ) {
			return;
		}

		$jbli_api_key = get_option( 'jbli_google_map_api_key', '' );

		/* Google Maps is required only by the listing form. */
		if ( in_array( 'form', $jbli_modules, true ) && ! empty( $jbli_api_key ) ) {
			wp_enqueue_script(
				'google-maps-places',
				'https://maps.googleapis.com/maps/api/js?key=' . rawurlencode( (string) $jbli_api_key ) . '&libraries=places',
				array(),
				JBLI_VERSION,
				true
			);
		}

		foreach ( $jbli_modules as $jbli_mod ) {

			$jbli_js_file = JBLI_DIR . "modules/{$jbli_mod}/js/jbli-{$jbli_mod}.js";

			if ( ! is_readable( $jbli_js_file ) || filesize( $jbli_js_file ) <= 50 ) {
				continue;
			}

			$jbli_deps = array();

			if ( 'form' === $jbli_mod ) {
				$jbli_deps[] = 'jquery';

				if ( ! empty( $jbli_api_key ) ) {
					$jbli_deps[] = 'google-maps-places';
				}
			}

			wp_enqueue_script(
				"jbli_{$jbli_mod}_script",
				JBLI_URL . "modules/{$jbli_mod}/js/jbli-{$jbli_mod}.js",
				$jbli_deps,
				jbli_asset_version( $jbli_js_file ),
				true
			);

			/* The apply modal needs the AJAX URL too; on single/recent pages listings JS is absent. */
			if ( 'apply' === $jbli_mod && ! in_array( 'listings', $jbli_modules, true ) ) {
				wp_localize_script( 'jbli_apply_script', 'jbli_data', jbli_script_data() );
			}

			if ( 'listings' === $jbli_mod ) {
				wp_localize_script( 'jbli_listings_script', 'jbli_data', jbli_script_data() );
				wp_add_inline_script(
					'jbli_listings_script',
					'document.documentElement.classList.add("jbli_loaded");',
					'before'
				);
			}
		}
	}
}

add_action( 'wp_enqueue_scripts', 'jbli_enqueue_assets', 100 );

if ( ! function_exists( 'jbli_required_css_modules' ) ) {

	/**
	 * Decide which module stylesheets this request needs.
	 *
	 * The five module stylesheets are NOT independent: single, form and
	 * dashboard each emit classes defined in one of the others, so they are
	 * still loaded as a group. The recent module is the exception - as of
	 * 9.9.40 jbli-recent.css carries its own copies of .jbli_btn_outline and
	 * .jbli_empty, the only two rules its markup borrowed - so a page whose
	 * only listings content is [recent-listings] loads that one file alone.
	 *
	 * Pages with no listings content at all load nothing, not even the tokens.
	 *
	 * @param string[] $jbli_modules Modules required by the current request.
	 * @return string[] Module slugs whose CSS must be enqueued.
	 */
	function jbli_required_css_modules( array $jbli_modules ) {

		$jbli_with_css = array( 'single', 'listings', 'recent', 'form', 'dashboard' );

		/* Nothing from this plugin renders here. */
		if ( empty( $jbli_modules ) ) {
			$jbli_css = array();
		} else {

			$jbli_needs_css = array_values( array_intersect( $jbli_modules, $jbli_with_css ) );

			/*
			 * Self-contained case: recent listings only, as on the front page.
			 * The apply modal ships its styles inside jbli-recent.css.
			 */
			if ( array( 'recent' ) === $jbli_needs_css ) {
				$jbli_css = array( 'recent' );
			} else {
				/* Entangled case: keep the historical behaviour and load them all. */
				$jbli_css = $jbli_with_css;
			}
		}

		/**
		 * Filter the module stylesheets enqueued for this request.
		 *
		 * Return jbli_asset_css_module_slugs() to restore pre-9.9.40 behaviour
		 * if custom markup outside a shortcode relies on these classes.
		 *
		 * @param string[] $jbli_css     Module slugs to enqueue.
		 * @param string[] $jbli_modules Modules required by the current request.
		 */
		$jbli_css = apply_filters( 'jbli_required_css_modules', $jbli_css, $jbli_modules );

		if ( ! is_array( $jbli_css ) ) {
			return array();
		}

		$jbli_css = array_values( array_intersect( $jbli_css, $jbli_with_css ) );

		/* The apply modal has its own stylesheet (9.9.45), loaded after the module CSS. */
		if ( in_array( 'apply', $jbli_modules, true ) ) {
			$jbli_css[] = 'apply';
		}

		return $jbli_css;
	}
}

if ( ! function_exists( 'jbli_asset_css_module_slugs' ) ) {

	/**
	 * Every module slug that ships a stylesheet.
	 *
	 * @return string[]
	 */
	function jbli_asset_css_module_slugs() {
		return array( 'single', 'listings', 'recent', 'form', 'dashboard', 'apply' );
	}
}

if ( ! function_exists( 'jbli_required_asset_modules' ) ) {

	/**
	 * Return only the frontend modules required by the current request.
	 *
	 * @return string[]
	 */
	function jbli_required_asset_modules() {

		static $jbli_modules = null;

		if ( null !== $jbli_modules ) {
			return $jbli_modules;
		}

		$jbli_modules = array();

		/* Single job listing uses the single layout and application modal. */
		if ( is_singular( JBLI_CPT ) ) {
			return $jbli_modules = array( 'single', 'apply' );
		}

		/* Job archive and taxonomies use the listing cards and application modal. */
		if ( is_post_type_archive( JBLI_CPT ) || is_tax( 'job_category' ) || is_tax( 'job_nomos' ) ) {
			return $jbli_modules = array( 'listings', 'apply' );
		}

		$jbli_post = jbli_asset_post();

		if ( ! $jbli_post instanceof WP_Post ) {
			return $jbli_modules;
		}

		$jbli_content = jbli_asset_searchable_content( $jbli_post );

		if ( jbli_content_has_shortcode( $jbli_content, 'listings' ) ) {
			$jbli_modules[] = 'listings';
			$jbli_modules[] = 'apply';
		}

		if ( jbli_content_has_shortcode( $jbli_content, 'new-listing' ) ) {
			$jbli_modules[] = 'form';
		}

		if ( jbli_content_has_shortcode( $jbli_content, 'dashboard' ) ) {
			$jbli_modules[] = 'dashboard';
		}

		if ( jbli_content_has_shortcode( $jbli_content, 'recent-listings' ) ) {
			$jbli_modules[] = 'recent';
			$jbli_modules[] = 'apply';
		}

		return $jbli_modules = array_values( array_unique( $jbli_modules ) );
	}
}

if ( ! function_exists( 'jbli_asset_post' ) ) {

	/**
	 * Resolve the current frontend post, including a static front page.
	 *
	 * @return WP_Post|null
	 */
	function jbli_asset_post() {

		$jbli_queried = get_queried_object();
		$jbli_post    = $jbli_queried instanceof WP_Post ? $jbli_queried : get_post();

		if ( ! $jbli_post instanceof WP_Post && is_front_page() ) {
			$jbli_front_id = (int) get_option( 'page_on_front' );

			if ( $jbli_front_id > 0 ) {
				$jbli_post = get_post( $jbli_front_id );
			}
		}

		return $jbli_post instanceof WP_Post ? $jbli_post : null;
	}
}

if ( ! function_exists( 'jbli_asset_searchable_content' ) ) {

	/**
	 * Collect post content and string metadata where page builders may store shortcodes.
	 *
	 * @param WP_Post $jbli_post Current post.
	 * @return string[]
	 */
	function jbli_asset_searchable_content( WP_Post $jbli_post ) {

		$jbli_values = array( (string) $jbli_post->post_content );
		$jbli_meta   = get_post_meta( $jbli_post->ID );

		foreach ( $jbli_meta as $jbli_meta_values ) {
			foreach ( (array) $jbli_meta_values as $jbli_value ) {
				if ( is_string( $jbli_value ) && '' !== $jbli_value ) {
					$jbli_values[] = $jbli_value;
				}
			}
		}

		return $jbli_values;
	}
}

if ( ! function_exists( 'jbli_content_has_shortcode' ) ) {

	/**
	 * Check a content collection for one shortcode.
	 *
	 * @param string[] $jbli_values    Content values.
	 * @param string   $jbli_shortcode Shortcode tag.
	 * @return bool
	 */
	function jbli_content_has_shortcode( array $jbli_values, $jbli_shortcode ) {

		$jbli_shortcode = (string) $jbli_shortcode;

		foreach ( $jbli_values as $jbli_value ) {
			if ( has_shortcode( $jbli_value, $jbli_shortcode ) ) {
				return true;
			}

			/* Fallback for serialized/page-builder values. */
			if ( false !== strpos( $jbli_value, '[' . $jbli_shortcode ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'jbli_should_enqueue_assets' ) ) {

	/**
	 * Backward-compatible boolean used by any external integration.
	 *
	 * @return bool
	 */
	function jbli_should_enqueue_assets() {
		return ! empty( jbli_required_asset_modules() );
	}
}

if ( ! function_exists( 'jbli_asset_shortcodes' ) ) {

	function jbli_asset_shortcodes() {
		return array( 'listings', 'new-listing', 'dashboard', 'recent-listings' );
	}
}

if ( ! function_exists( 'jbli_asset_version' ) ) {

	function jbli_asset_version( $jbli_file ) {

		$jbli_file = (string) $jbli_file;

		static $jbli_versions = array();

		if ( isset( $jbli_versions[ $jbli_file ] ) ) {
			return $jbli_versions[ $jbli_file ];
		}

		if ( '' !== $jbli_file ) {
			clearstatcache( true, $jbli_file );
		}

		$jbli_versions[ $jbli_file ] = is_readable( $jbli_file )
			? JBLI_VERSION . '.' . (string) filemtime( $jbli_file ) . '.' . (string) get_option( 'jbli_asset_bust', 0 )
			: JBLI_VERSION . '.0.' . (string) get_option( 'jbli_asset_bust', 0 );

		return $jbli_versions[ $jbli_file ];
	}
}

if ( ! function_exists( 'jbli_script_data' ) ) {

	function jbli_script_data() {

		return array(
			'ajaxurl'     => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'jbli_nonce'  => wp_create_nonce( 'jbli_nonce' ),
			'isLoggedIn'  => is_user_logged_in(),
			'i18n'        => jbli_script_i18n(),
			'version'     => (string) JBLI_VERSION,
		);
	}
}

if ( ! function_exists( 'jbli_script_i18n' ) ) {

	function jbli_script_i18n() {

		return array(
			'confirm_delete' => __(
				'Να διαγραφεί οριστικά η αγγελία; Αυτή η ενέργεια δεν αναιρείται.',
				'job-listings'
			),
			'loading'        => __(
				'Φόρτωση...',
				'job-listings'
			),
			'generic_error'  => __(
				'Παρουσιάστηκε σφάλμα. Δοκιμάστε ξανά.',
				'job-listings'
			),
		);
	}
}