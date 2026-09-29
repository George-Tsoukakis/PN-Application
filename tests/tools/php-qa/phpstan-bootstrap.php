<?php
/**
 * PHPStan bootstrap: constants the plugin reads that PHPStan cannot infer.
 * Only parsed by PHPStan, never loaded by WordPress.
 *
 * @package PlanDose
 */

// plandose.php defines these from plugin_dir_path() / plugin_dir_url().
define( 'PLANDOSE_PATH', '/plugins/plandose/' );
define( 'PLANDOSE_URL', 'https://example.org/wp-content/plugins/plandose/' );
// Optional wp-config.php override (declared dynamic in phpstan.neon).
define( 'PLANDOSE_INVOICE_DIR', '' );
// Defined by wp_cookie_constants() at runtime.
define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_hash' );
