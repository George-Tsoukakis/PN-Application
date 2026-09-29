<?php
/**
 * Plugin Name: PlanDose test — production switch (TEST-ONLY)
 * Description: Makes PlanDose apply its production rule for invoice storage
 * on the local test site, only while the non-autoloaded option
 * pd_test_force_production is 1. With pd_test_block_loopback = 1 every
 * outgoing HTTP request of this site answers 403 (a firewall / blocked
 * loopback), so the privacy probe must come out «unverified». Inert unless
 * PLANDOSE_TESTS is true and the environment type is 'local'. Never install
 * on a real site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'PLANDOSE_TESTS' ) && PLANDOSE_TESTS && function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
	add_filter(
		'plandose_invoice_storage_is_production',
		static function ( $production ) {
			return '1' === (string) get_option( 'pd_test_force_production', '' ) ? true : $production;
		}
	);

	add_filter(
		'pre_http_request',
		static function ( $pre ) {
			if ( '1' !== (string) get_option( 'pd_test_block_loopback', '' ) ) {
				return $pre;
			}

			return array(
				'headers'  => array(),
				'body'     => 'Forbidden',
				'response' => array( 'code' => 403, 'message' => 'Forbidden' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}
	);
}
