<?php
/**
 * Expiry: Cron Scheduling
 *
 * Registers WP-Cron events for expiry checking and reminder emails.
 * Loaded by jbli-expiry.php.
 *
 * @package JobListings
 * @since   9.9.26
 */

defined( 'ABSPATH' ) || exit;

function jbli_schedule_cron() {

	if ( ! wp_next_scheduled( 'jbli_hourly_cron' ) ) { wp_schedule_event( time(), 'hourly', 'jbli_hourly_cron' ); }

	if ( ! wp_next_scheduled( 'jbli_daily_reminder_cron' ) )
	{
		wp_schedule_event( time(), 'daily', 'jbli_daily_reminder_cron' );
	}

}

add_action( 'init', 					'jbli_schedule_cron', 20 );
add_action( 'jbli_hourly_cron',        	'jbli_run_expiry' );
add_action( 'jbli_daily_reminder_cron', 'jbli_run_expiry_reminders' );
