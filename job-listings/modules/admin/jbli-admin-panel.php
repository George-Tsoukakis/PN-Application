<?php
/**
 * Module: Admin Panel
 *
 * Entry point for the admin panel module. Loads focused sub-files and
 * wires together all admin panel concerns.
 *
 * Sub-module layout:
 *   admin-parts/jbli-registration.php — menu page registration + asset enqueue.
 *   admin-parts/jbli-actions.php      — POST action handler + per-action helpers.
 *   admin-parts/jbli-stats.php        — listing count statistics.
 *   admin-parts/jbli-buttons.php      — action button form builder.
 *   admin-parts/jbli-render.php       — WP_Query builder + template renderer.
 *
 * @package JobListings
 * @since   9.6.6
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/admin-parts/jbli-registration.php';
require_once __DIR__ . '/admin-parts/jbli-stats.php';
require_once __DIR__ . '/admin-parts/jbli-buttons.php';
require_once __DIR__ . '/admin-parts/jbli-actions.php';
require_once __DIR__ . '/admin-parts/jbli-render.php';
