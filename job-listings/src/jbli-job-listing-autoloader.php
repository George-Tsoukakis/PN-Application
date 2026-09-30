<?php

namespace JobListings;

defined( 'ABSPATH' ) || exit;

/**
 * Prefixed-file autoloader for the JobListings namespace.
 */
final class Autoloader {

	/**
	 * Namespace prefix (with trailing backslash).
	 *
	 * @var string
	 */
	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * Absolute path to the src/ directory (with trailing slash).
	 *
	 * @var string
	 */
	private static $jbli_base_dir = '';

	/**
	 * Register the autoloader once.
	 *
	 * @param string $jbli_base_dir Absolute path to the src directory.
	 * @return void
	 */
	public static function jbli_register( $jbli_base_dir ) {

		self::$jbli_base_dir = trailingslashit( (string) $jbli_base_dir );

		spl_autoload_register( array( __CLASS__, 'jbli_load' ) );

	}

	/**
	 * Attempt to load a class file.
	 *
	 * Example: JobListings\Models\Listing → src/Models/jbli-job-listing-listing.php
	 *
	 * @param string $jbli_class Fully-qualified class name.
	 * @return void
	 */
	public static function jbli_load( $jbli_class ) {

		$jbli_class = (string) $jbli_class;

		if ( 0 !== strpos( $jbli_class, self::PREFIX ) ) { return; }

		$jbli_relative = substr( $jbli_class, strlen( self::PREFIX ) );
		$jbli_parts    = explode( '\\', $jbli_relative );
		$jbli_short    = array_pop( $jbli_parts );
		$jbli_kebab    = strtolower(
			preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', (string) $jbli_short )
		);
		$jbli_file     = 'jbli-job-listing-' . $jbli_kebab . '.php';

		if ( ! empty( $jbli_parts ) )
		{
			$jbli_dir = implode( '/', $jbli_parts ) . '/';
		}

		else { $jbli_dir = ''; }

		$jbli_path = self::$jbli_base_dir . $jbli_dir . $jbli_file;

		if ( is_readable( $jbli_path ) ) { require_once $jbli_path; }

	}

}
