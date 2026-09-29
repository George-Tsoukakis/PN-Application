<?php
/**
 * Plugin Name:       Job Listings – PharmacyNeeds
 * Plugin URI:        https://pharmacyneeds.gr
 * Description:       Πλατφόρμα αγγελιών εργασίας αποκλειστικά για φαρμακεία.
 * Version:           9.9.54
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            PharmacyNeeds
 * Author URI:        https://pharmacyneeds.gr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       job-listings
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

defined( 'JBLI_VERSION' )  || define( 'JBLI_VERSION',  '9.9.54' );
defined( 'JBLI_FILE' )     || define( 'JBLI_FILE',     __FILE__ );
defined( 'JBLI_DIR' )      || define( 'JBLI_DIR',      plugin_dir_path( JBLI_FILE ) );
defined( 'JBLI_URL' )      || define( 'JBLI_URL',      plugin_dir_url( JBLI_FILE ) );
defined( 'JBLI_BASENAME' ) || define( 'JBLI_BASENAME', plugin_basename( JBLI_FILE ) );
defined( 'JBLI_CPT' )      || define( 'JBLI_CPT',      'job_listing' );

require_once JBLI_DIR . 'src/jbli-job-listing-autoloader.php';

\JobListings\Autoloader::jbli_register( JBLI_DIR . 'src' );

$jbli_plugin = \JobListings\Plugin::jbli_instance();
$jbli_plugin->jbli_load_legacy_files();
$jbli_plugin->jbli_boot();

if ( ! function_exists( 'jbli_discard_unexpected_output' ) )
{
	/**
	 * @param string $jbli_context Context label.
	 * @param string $jbli_output  Captured output.
	 * @return void
	 */
	function jbli_discard_unexpected_output( $jbli_context, $jbli_output ) {

		\JobListings\Plugin::jbli_discard_unexpected_output( $jbli_context, $jbli_output );

	}
}
