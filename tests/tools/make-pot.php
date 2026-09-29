<?php
/**
 * Minimal POT generator for PlanDose (stand-in for `wp i18n make-pot` where
 * WP-CLI is unavailable). TEST/DEV tool, not shipped.
 *
 * Extracts, for the 'plandose' text domain: __, _e, esc_html__, esc_html_e,
 * esc_attr__, esc_attr_e, _x, _ex, esc_html_x, esc_attr_x, _n, _nx, _n_noop,
 * _nx_noop; file:line references; the plugin header strings; and
 * translators comments. A translators comment belongs to the ONE gettext
 * call right after it: it must end on the line directly above the call or
 * on the call's own line, and is used at most once.
 *
 * Arguments that are not a single string literal (variables, concatenation)
 * cannot be extracted: the call is skipped with a warning on stderr.
 *
 * Usage: php make-pot.php <plugin-dir> <out.pot>
 */

if ( 3 !== $argc || ! is_file( rtrim( $argv[1], '/' ) . '/plandose.php' ) ) {
	fwrite( STDERR, "Usage: php make-pot.php <plugin-dir> <out.pot>\n  <plugin-dir> must contain plandose.php\n" );
	exit( 1 );
}
if ( ! is_dir( dirname( $argv[2] ) ) || ! is_writable( dirname( $argv[2] ) ) ) {
	fwrite( STDERR, 'Output directory not writable: ' . dirname( $argv[2] ) . "\n" );
	exit( 1 );
}

$root = rtrim( $argv[1], '/' );
$out  = $argv[2];

$funcs = array(
	'__'         => array( 'text' => 0, 'domain' => 1 ),
	'_e'         => array( 'text' => 0, 'domain' => 1 ),
	'esc_html__' => array( 'text' => 0, 'domain' => 1 ),
	'esc_html_e' => array( 'text' => 0, 'domain' => 1 ),
	'esc_attr__' => array( 'text' => 0, 'domain' => 1 ),
	'esc_attr_e' => array( 'text' => 0, 'domain' => 1 ),
	'_x'         => array( 'text' => 0, 'context' => 1, 'domain' => 2 ),
	'_ex'        => array( 'text' => 0, 'context' => 1, 'domain' => 2 ),
	'esc_html_x' => array( 'text' => 0, 'context' => 1, 'domain' => 2 ),
	'esc_attr_x' => array( 'text' => 0, 'context' => 1, 'domain' => 2 ),
	'_n'         => array( 'text' => 0, 'plural' => 1, 'domain' => 3 ),
	'_nx'        => array( 'text' => 0, 'plural' => 1, 'context' => 3, 'domain' => 4 ),
	'_n_noop'    => array( 'text' => 0, 'plural' => 1, 'domain' => 2 ),
	'_nx_noop'   => array( 'text' => 0, 'plural' => 1, 'context' => 2, 'domain' => 3 ),
);

$warnings = 0;
function pd_warn( $msg ) {
	global $warnings;
	$warnings++;
	fwrite( STDERR, 'Warning: ' . $msg . "\n" );
}

$entries = array(); // key => [msgid, plural, ctxt, refs[], comments[]]

function pd_add( &$entries, $msgid, $plural, $ctxt, $ref, $comment ) {
	$key = $ctxt . "\x04" . $msgid;
	if ( ! isset( $entries[ $key ] ) ) {
		$entries[ $key ] = array( 'msgid' => $msgid, 'plural' => $plural, 'ctxt' => $ctxt, 'refs' => array(), 'comments' => array() );
	}
	$entries[ $key ]['refs'][] = $ref;
	if ( '' !== $comment && ! in_array( $comment, $entries[ $key ]['comments'], true ) ) {
		$entries[ $key ]['comments'][] = $comment;
	}
}

// Plugin header strings first, as WP-CLI does.
$main    = file_get_contents( $root . '/plandose.php' );
$headers = array( 'Plugin Name', 'Plugin URI', 'Description', 'Author', 'Author URI' );
foreach ( $headers as $h ) {
	if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $h, '/' ) . ':(.*)$/mi', $main, $m ) ) {
		$val = trim( $m[1] );
		$key = "\x04" . $val;
		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array( 'msgid' => $val, 'plural' => null, 'ctxt' => '', 'refs' => array( 'plandose.php' ), 'comments' => array() );
		}
		$entries[ $key ]['comments'][] = $h . ' of the plugin';
	}
}

$files = array();
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( 'php' === $f->getExtension() && ! preg_match( '#/(node_modules|vendor|tests)/#', $f->getPathname() ) ) {
		$files[] = substr( $f->getPathname(), strlen( $root ) + 1 );
	}
}
sort( $files );

function pd_unquote( $tok ) {
	if ( '"' === $tok[0] ) {
		return stripcslashes( substr( $tok, 1, -1 ) );
	}
	return str_replace( array( "\\\\", "\\'" ), array( "\\", "'" ), substr( $tok, 1, -1 ) );
}

foreach ( $files as $rel ) {
	$tokens  = token_get_all( file_get_contents( $root . '/' . $rel ) );
	$n       = count( $tokens );
	$comment      = '';
	$comment_end  = -100; /* line on which the pending translators comment ends */
	$comment_same = false;
	for ( $i = 0; $i < $n; $i++ ) {
		$t = $tokens[ $i ];
		if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			if ( preg_match( '/translators:(.*?)(\*\/)?\s*$/s', $t[1], $m ) ) {
				$c           = preg_replace( '/^\s*(\/\*+|\/\/|\*)\s?/m', '', 'translators:' . $m[1] );
				$comment     = trim( preg_replace( '/\s+/', ' ', $c ) );
				$comment_end = $t[2] + substr_count( rtrim( $t[1], "\n" ), "\n" );
				/* After code on its line ("foo(); // translators: x"): for a
				   later call on that same line only. */
				$gap = '';
				for ( $b = $i - 1; $b >= 0 && is_array( $tokens[ $b ] ) && T_WHITESPACE === $tokens[ $b ][0]; $b-- ) {
					$gap = $tokens[ $b ][1] . $gap;
				}
				/* <?php and // comments carry their own newline. */
				$prev_text    = $b >= 0 ? ( is_array( $tokens[ $b ] ) ? $tokens[ $b ][1] : $tokens[ $b ] ) : "\n";
				$comment_same = false === strpos( $gap, "\n" ) && "\n" !== substr( $prev_text, -1 );
			}
			continue;
		}
		if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $funcs[ $t[1] ] ) ) {
			continue;
		}
		// Not a method/function definition.
		$prev = $i - 1;
		while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
			$prev--;
		}
		if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( '(' !== $tokens[ $j ] ) {
			continue;
		}
		// Collect top-level args (only plain string literals are usable).
		$args  = array( array() );
		$depth = 0;
		for ( $k = $j + 1; $k < $n; $k++ ) {
			$tk = $tokens[ $k ];
			if ( '(' === $tk || '[' === $tk || '{' === $tk ) {
				$depth++;
			} elseif ( ')' === $tk || ']' === $tk || '}' === $tk ) {
				if ( 0 === $depth ) {
					break;
				}
				$depth--;
			} elseif ( ',' === $tk && 0 === $depth ) {
				$args[] = array();
				continue;
			}
			if ( is_array( $tk ) && in_array( $tk[0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
				continue;
			}
			$args[ count( $args ) - 1 ][] = $tk;
		}
		$spec = $funcs[ $t[1] ];
		$lit  = function ( $idx ) use ( $args ) {
			if ( ! isset( $args[ $idx ] ) || 1 !== count( $args[ $idx ] ) ) {
				return null;
			}
			$a = $args[ $idx ][0];
			return ( is_array( $a ) && T_CONSTANT_ENCAPSED_STRING === $a[0] ) ? pd_unquote( $a[1] ) : null;
		};
		$line = $t[2];

		/* The pending comment is for this call only if it ends right above
		   it (or on its line); either way it is used up now. */
		$c       = ( $line >= $comment_end && $line - $comment_end <= ( $comment_same ? 0 : 1 ) ) ? $comment : '';
		$comment = '';

		$domain = $lit( $spec['domain'] );
		if ( null === $domain && isset( $args[ $spec['domain'] ] ) && $args[ $spec['domain'] ] ) {
			pd_warn( "$rel:$line: {$t[1]}() with a non-literal text domain — skipped" );
			continue;
		}
		if ( 'plandose' !== $domain ) {
			continue;
		}
		$text   = $lit( $spec['text'] );
		$plural = isset( $spec['plural'] ) ? $lit( $spec['plural'] ) : null;
		$ctxt   = isset( $spec['context'] ) ? $lit( $spec['context'] ) : '';
		if ( null === $text || ( isset( $spec['plural'] ) && null === $plural ) || null === $ctxt ) {
			pd_warn( "$rel:$line: {$t[1]}() with a non-literal string argument — not extracted" );
			continue;
		}
		pd_add( $entries, $text, $plural, $ctxt, $rel . ':' . $line, $c );
	}
}

function pd_po( $s ) {
	$s = addcslashes( $s, "\\\"\t" );
	$s = str_replace( "\n", '\n', $s );
	return '"' . $s . '"';
}

$version = preg_match( '/^\s*\*\s*Version:\s*(\S+)/mi', $main, $m ) ? $m[1] : '';
$pot     = "# Copyright (C) " . gmdate( 'Y' ) . " PharmacyNeeds\n"
	. "# This file is distributed under the GPL-2.0+ license.\n"
	. "# The source strings below are Greek: PlanDose was written for Greek\n"
	. "# pharmacies and its source language is Greek rather than the English\n"
	. "# that WordPress convention assumes. Greek sites therefore need no\n"
	. "# translation file at all — the source is already correct for them.\n"
	. "# This template exists so any OTHER locale can be added without\n"
	. "# touching the code.\n"
	. "msgid \"\"\nmsgstr \"\"\n"
	. "\"Project-Id-Version: PlanDose $version\\n\"\n"
	. "\"Report-Msgid-Bugs-To: https://pharmacyneeds.gr\\n\"\n"
	. "\"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n\"\n"
	. "\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n"
	. "\"MIME-Version: 1.0\\n\"\n"
	. "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
	. "\"Content-Transfer-Encoding: 8bit\\n\"\n"
	. '"POT-Creation-Date: ' . gmdate( 'Y-m-d\TH:i:s+00:00' ) . "\\n\"\n"
	. "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n"
	. "\"X-Generator: PlanDose make-pot.php\\n\"\n"
	. "\"X-Domain: plandose\\n\"\n";

foreach ( $entries as $e ) {
	$pot .= "\n";
	foreach ( $e['comments'] as $c ) {
		$pot .= '#. ' . $c . "\n";
	}
	foreach ( $e['refs'] as $r ) {
		$pot .= '#: ' . $r . "\n";
	}
	if ( preg_match( '/%(\d+\$)?[sd]/', $e['msgid'] ) ) {
		$pot .= "#, php-format\n";
	}
	if ( '' !== $e['ctxt'] ) {
		$pot .= 'msgctxt ' . pd_po( $e['ctxt'] ) . "\n";
	}
	$pot .= 'msgid ' . pd_po( $e['msgid'] ) . "\n";
	if ( null !== $e['plural'] ) {
		$pot .= 'msgid_plural ' . pd_po( $e['plural'] ) . "\n";
		$pot .= "msgstr[0] \"\"\nmsgstr[1] \"\"\n";
	} else {
		$pot .= "msgstr \"\"\n";
	}
}

if ( false === file_put_contents( $out, $pot ) ) {
	fwrite( STDERR, "Could not write $out\n" );
	exit( 1 );
}
echo count( $entries ) . ' entries' . ( $warnings ? ", $warnings warnings" : '' ) . "\n";
