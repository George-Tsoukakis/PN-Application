<?php
// 2.15.7: Site Health — χωρητικότητα συνδέσμων email (token_capacity_verdict, καθαρή συνάρτηση).
require __DIR__ . '/boot.php';
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
function add_filter( ...$a ) {}
function add_action( ...$a ) {}
function esc_html__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
require qrrp_test_pdir() . '/includes/class-qrrp-site-health.php';

$fail = 0;
function ok( $c, $m ) { global $fail; if ( ! $c ) { $fail++; } echo ( $c ? 'PASS' : 'FAIL' ), " $m\n"; }
$now = 2000000000;
$v = QRRP_Site_Health::token_capacity_verdict( array( 'count' => 10, 'max' => 2000, 'full_at' => 0 ), $now );
ok( 'good' === $v['status'] && false !== strpos( $v['description'], '10 από 2000' ), 'low usage → good, shows 10/2000' );
$v = QRRP_Site_Health::token_capacity_verdict( array( 'count' => 1600, 'max' => 2000, 'full_at' => 0 ), $now );
ok( 'recommended' === $v['status'], '80% → recommended' );
$v = QRRP_Site_Health::token_capacity_verdict( array( 'count' => 1599, 'max' => 2000, 'full_at' => 0 ), $now );
ok( 'good' === $v['status'], 'just under 80% → good' );
$v = QRRP_Site_Health::token_capacity_verdict( array( 'count' => 5, 'max' => 2000, 'full_at' => $now - 3 * DAY_IN_SECONDS ), $now );
ok( 'recommended' === $v['status'] && false !== strpos( $v['label'], 'χωρίς σύνδεσμο' ), 'refusal 3 days ago → recommended, says emails went without link' );
$v = QRRP_Site_Health::token_capacity_verdict( array( 'count' => 5, 'max' => 2000, 'full_at' => $now - 8 * DAY_IN_SECONDS ), $now );
ok( 'good' === $v['status'], 'refusal 8 days ago → good again' );
$v = QRRP_Site_Health::token_capacity_verdict( null, $now );
ok( 'recommended' === $v['status'], 'tokens class missing → recommended' );
exit( $fail ? 1 : 0 );
