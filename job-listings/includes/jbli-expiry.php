<?php
/**
 * Expiry
 *
 * Entry point for the expiry system. Loads focused sub-files in
 * dependency order and wires together all expiry concerns.
 *
 * Sub-module layout:
 *   expiry-parts/jbli-lifecycle.php — future_datetime, publish_and_reset,
 *                                renew_job, activate_job, deactivate_job.
 *   expiry-parts/jbli-actions.php  — set_expiry_on_publish, run_expiry,
 *                                expire_job, run_expiry_reminders.
 *   expiry-parts/jbli-cron.php     — schedule_cron + add_action hooks.
 *   expiry-parts/jbli-emails.php   — send_expiry_email, send_reminder_email,
 *                                get_email_listing_data, email primitives.
 *
 * Load order matters:
 *   jbli-lifecycle.php first — defines future_datetime used by jbli-actions.php.
 *   jbli-actions.php second  — defines expire_job/reminders used by jbli-cron.php.
 *   jbli-cron.php third      — hooks are registered after all handlers exist.
 *   jbli-emails.php last     — called by actions, no outgoing dependencies.
 *
 * @package JobListings
 * @since   9.6.1
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/expiry-parts/jbli-lifecycle.php';
require_once __DIR__ . '/expiry-parts/jbli-actions.php';
require_once __DIR__ . '/expiry-parts/jbli-cron.php';
require_once __DIR__ . '/expiry-parts/jbli-emails.php';
