<?php
/**
 * Plugin Name: PN Chat
 * Plugin URI: https://pharmacyneeds.gr
 * Description: Our own chat assistant for PharmacyNeeds. It answers only from the knowledge we train it with, with no AI and no third-party services, and logs what it cannot answer so we can reply by e-mail and teach it.
 * Version: 1.0.3
 * Author: PharmacyNeeds
 * Author URI: https://pharmacyneeds.gr
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: pn-chat
 * Requires at least: 6.3
 * Requires PHP: 8.0
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PNCHAT_VERSION', '1.0.3' );
define( 'PNCHAT_FILE', __FILE__ );
define( 'PNCHAT_PATH', plugin_dir_path( __FILE__ ) );
define( 'PNCHAT_URL', plugin_dir_url( __FILE__ ) );

require_once PNCHAT_PATH . 'includes/class-pnchat-text.php';
require_once PNCHAT_PATH . 'includes/class-pnchat-matcher.php';
require_once PNCHAT_PATH . 'includes/class-pnchat-settings.php';
require_once PNCHAT_PATH . 'includes/class-pnchat-store.php';
require_once PNCHAT_PATH . 'includes/class-pnchat-brain.php';
require_once PNCHAT_PATH . 'includes/class-pnchat-seed.php';
require_once PNCHAT_PATH . 'includes/class-pnchat-rest.php';
require_once PNCHAT_PATH . 'includes/class-pnchat-frontend.php';
require_once PNCHAT_PATH . 'includes/class-pnchat-admin.php';
require_once PNCHAT_PATH . 'includes/class-pnchat-privacy.php';

/**
 * Activation: tables, starter brain, daily clean-up.
 *
 * @return void
 */
function pnchat_activate() {
	PNChat_Store::install();
	PNChat_Seed::maybe_seed();
	if ( ! wp_next_scheduled( 'pnchat_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'pnchat_daily' );
	}
}
register_activation_hook( __FILE__, 'pnchat_activate' );

/**
 * Deactivation: only the scheduled event goes; no data is touched.
 *
 * @return void
 */
function pnchat_deactivate() {
	wp_clear_scheduled_hook( 'pnchat_daily' );
}
register_deactivation_hook( __FILE__, 'pnchat_deactivate' );

/**
 * Upgrades the tables after a plugin update (activation hooks do not run then).
 *
 * @return void
 */
function pnchat_maybe_upgrade() {
	if ( (int) get_option( 'pnchat_db_version' ) < PNChat_Store::DB_VERSION ) {
		PNChat_Store::install();
	}
	PNChat_Settings::migrate();
}
add_action( 'plugins_loaded', 'pnchat_maybe_upgrade' );

/**
 * Daily: deletes questions older than the retention period.
 *
 * @return void
 */
function pnchat_daily() {
	PNChat_Store::purge_old( (int) PNChat_Settings::value( 'retention_days' ) );
}
add_action( 'pnchat_daily', 'pnchat_daily' );

PNChat_Rest::init();
PNChat_Frontend::init();
PNChat_Privacy::init();
if ( is_admin() ) {
	PNChat_Admin::init();
}

/**
 * "Settings" link on the Plugins screen.
 *
 * @param string[] $links Links.
 * @return string[]
 */
function pnchat_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=pn-chat' ) ) . '">Εκπαίδευση</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pnchat_action_links' );
