<?php

namespace JobListings\Support;

defined( 'ABSPATH' ) || exit;

/**
 * View / templating helper.
 */
final class View {

	/**
	 * Absolute path to the primary views directory (trailing slash).
	 *
	 * @var string
	 */
	private $jbli_base_path;

	/**
	 * @param string|null $jbli_base_path Override views directory. Defaults to JBLI_DIR/views/.
	 */
	public function __construct( $jbli_base_path = null ) {

		if ( null === $jbli_base_path ) { $jbli_base_path = defined( 'JBLI_DIR' ) ? JBLI_DIR . 'views/' : ''; }

		$this->jbli_base_path = trailingslashit( (string) $jbli_base_path );

	}

	/**
	 * Shared singleton for the plugin views root.
	 *
	 * @return self
	 */
	public static function jbli_make() {

		static $jbli_instance = null;

		if ( null === $jbli_instance ) { $jbli_instance = new self(); }

		return $jbli_instance;

	}

	/**
	 * Render a view to a string.
	 *
	 * Dot notation: "partials.job-listing-login-gate" or "partials/job-listing-login-gate"
	 * Also resolves plugin-relative paths (e.g. modules/listings/jbli-listings-template.php).
	 *
	 * @param string               $jbli_name View name or relative path.
	 * @param array<string, mixed> $jbli_data Variables extracted into the view scope.
	 * @return string
	 */
	public function jbli_render( $jbli_name, array $jbli_data = array() ) {

		$jbli_file = $this->jbli_resolve( (string) $jbli_name );

		if ( ! $jbli_file )
		{
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { error_log( '[Job Listings] View not found: ' . $jbli_name ); }

			return '';
		}

		return $this->jbli_include_file( $jbli_file, $jbli_data );

	}

	/**
	 * Echo a view immediately.
	 *
	 * @param string               $jbli_name View name.
	 * @param array<string, mixed> $jbli_data Variables for the view.
	 * @return void
	 */
	public function jbli_display( $jbli_name, array $jbli_data = array() ) {

		echo $this->jbli_render( $jbli_name, $jbli_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	}

	/**
	 * Resolve a view name to an absolute readable file path.
	 *
	 * Prefixed partials under views/partials/ may be referenced as
	 * "partials.login-gate" (auto-prefixes job-listing-) or the full file name.
	 *
	 * @param string $jbli_name View name or path.
	 * @return string|null
	 */
	public function jbli_resolve( $jbli_name ) {

		$jbli_name = str_replace( '\\', '/', (string) $jbli_name );
		$jbli_name = ltrim( $jbli_name, '/' );

		if ( '' === $jbli_name ) { return null; }

		if ( '.php' === substr( $jbli_name, -4 ) ) { $jbli_name = substr( $jbli_name, 0, -4 ); }

		if ( false === strpos( $jbli_name, '/' ) ) { $jbli_name = str_replace( '.', '/', $jbli_name ); }

		$jbli_candidates = array( $this->jbli_base_path . $jbli_name . '.php', );


		if ( 0 === strpos( $jbli_name, 'partials/' ) )
		{
			$jbli_base = substr( $jbli_name, strlen( 'partials/' ) );

			if ( 0 !== strpos( $jbli_base, 'jbli-job-listing-' ) )
			{
				$jbli_candidates[] = $this->jbli_base_path . 'partials/jbli-job-listing-' . $jbli_base . '.php';
			}
		}

		if ( defined( 'JBLI_DIR' ) ) { $jbli_candidates[] = JBLI_DIR . $jbli_name . '.php'; }

		foreach ( $jbli_candidates as $jbli_path ) {

			if ( is_readable( $jbli_path ) ) { return $jbli_path; }

		}

		return null;

	}

	/**
	 * Include a PHP file with an isolated extract() scope.
	 *
	 * @param string               $jbli_file Absolute path.
	 * @param array<string, mixed> $jbli_data Variables.
	 * @return string
	 */
	private function jbli_include_file( $jbli_file, array $jbli_data ) {

		unset( $jbli_data['jbli_file'], $jbli_data['jbli_data'], $jbli_data['this'] );

		ob_start();

		( static function ( $jbli_file, $jbli_data )
		{
			extract( $jbli_data, EXTR_SKIP );
			include $jbli_file;
		} )( $jbli_file, $jbli_data );

		return (string) ob_get_clean();

	}

}
