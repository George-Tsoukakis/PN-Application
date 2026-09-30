<?php
/* Apply anti-spam and name validation (9.9.57). */
foreach ( array( 'jbli_apply_honeypot_filled', 'jbli_apply_sent_too_fast', 'jbli_apply_valid_name' ) as $f ) { jbli_test_load( 'modules/apply/jbli-apply.php', $f ); }

$_POST = array();
ok( ! jbli_apply_honeypot_filled() && ! jbli_apply_sent_too_fast(), 'old script without the fields is not blocked' );
$_POST = array( 'jbli_hp_website' => 'http://spam' ); ok( jbli_apply_honeypot_filled(), 'honeypot detected' );
$_POST = array( 'jbli_elapsed' => '800' );  ok( jbli_apply_sent_too_fast(), 'too fast detected' );
$_POST = array( 'jbli_elapsed' => '4000' ); ok( ! jbli_apply_sent_too_fast(), 'normal speed allowed' );

$names = array( 'Μαρία Παπαδοπούλου' => true, 'Γιώργος Τσ.' => true, "Anne-Marie O'Neil" => true, 'Ζωή' => true, 'www.spam.gr' => false, 'Buy cheap.com now' => false, 'http://x' => false, 'Νίκος 123' => false );
foreach ( $names as $n => $e ) { ok( jbli_apply_valid_name( $n ) === $e, "name '$n'" ); }
