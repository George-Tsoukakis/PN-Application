<?php
/* Dashboard activate / renew rules (9.9.57). */
jbli_test_load( 'modules/form/form-parts/jbli-rate-limit.php', 'jbli_owner_at_active_cap' );
jbli_test_load( 'modules/dashboard/jbli-dashboard.php', 'jbli_dashboard_publish_guard' );

$T = &$GLOBALS['T'];
$T['status'] = array( 1 => 'draft', 2 => 'job-expired', 3 => 'pending', 4 => 'publish', 5 => 'draft' );
$T['author'] = array_fill_keys( array( 1, 2, 3, 4, 5 ), 7 );
$T['meta'][5]['_jbli_admin_hidden'] = 1;
$T['admin'] = false;

$T['active'] = 2; ok( null === jbli_dashboard_publish_guard( 1, 'activate' ), 'activate draft under cap' );
$T['active'] = 5; ok( is_string( jbli_dashboard_publish_guard( 1, 'activate' ) ), 'activate draft at cap blocked' );
ok( is_string( jbli_dashboard_publish_guard( 2, 'renew' ) ), 'renew expired at cap blocked' );
$T['active'] = 1; ok( null === jbli_dashboard_publish_guard( 2, 'renew' ), 'renew expired under cap' );
ok( is_string( jbli_dashboard_publish_guard( 1, 'renew' ) ), 'renew of a draft blocked' );
ok( is_string( jbli_dashboard_publish_guard( 3, 'renew' ) ), 'renew of a pending listing blocked' );
ok( is_string( jbli_dashboard_publish_guard( 5, 'activate' ) ), 'admin-deactivated listing blocked for owner' );
ok( is_string( jbli_dashboard_publish_guard( 4, 'renew' ) ), 'renew of an active listing blocked' );
$T['meta'][4]['jbli_expired'] = 1; $T['active'] = 5;
ok( null === jbli_dashboard_publish_guard( 4, 'renew' ), 'published but flagged expired: renew allowed at cap' );
$T['admin'] = true; ok( null === jbli_dashboard_publish_guard( 5, 'activate' ), 'admin bypasses the rules' );
$T['admin'] = false;
