<?php
/* Imported listings: phone / email / address optional on edit (9.9.57). */
require_once $GLOBALS['JBLI_PLUGIN'] . 'modules/form/form-parts/jbli-validation.php';

$base = array( 'job_position' => 'Φαρμακοποιός', 'job_description' => 'desc', 'job_salary' => '2200+', 'job_type' => 'plires-apasxolisi', 'job_category' => 1, 'job_nomos' => 1, 'job_contact_phone' => '', 'job_contact_email' => '', 'job_address' => '' );

$GLOBALS['T']['meta'][10]['jbli_source_url'] = 'https://example.gr/a';

$_POST = $base;
ok( is_array( jbli_validate_form_data( 10 ) ), 'imported: empty phone/email/address accepted' );

$_POST = array_merge( $base, array( 'job_contact_phone' => '123' ) );
$r = jbli_validate_form_data( 10 );
ok( is_string( $r ) && false !== strpos( $r, '10 ψηφία' ), 'imported: short phone rejected' );

$_POST = array_merge( $base, array( 'job_contact_email' => 'bad' ) );
$r = jbli_validate_form_data( 10 );
ok( is_string( $r ) && false !== strpos( $r, 'έγκυρο email' ), 'imported: invalid email rejected' );

$_POST = $base;
$r = jbli_validate_form_data( 11 );
ok( is_string( $r ) && false !== strpos( $r, 'τηλέφωνο' ) && false !== strpos( $r, 'email' ) && false !== strpos( $r, 'διεύθυνση' ), 'normal listing: phone/email/address required' );

$_POST = array_merge( $base, array( 'job_contact_phone' => '2101234567', 'job_contact_email' => 'a@b.gr', 'job_address' => 'Σταδίου 1' ) );
$r = jbli_validate_form_data( 11 );
ok( is_array( $r ) && '2200+' === $r['jbli_salary'], 'normal listing: complete data accepted, "2200+" kept' );
