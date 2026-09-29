<?php
/**
 * Static extractor for the PlanDose front-end dictionaries (TEST-ONLY).
 *
 * Reads includes/class-plandose-frontend.php with the PHP tokenizer — no
 * WordPress needed — and prints JSON:
 *
 *   { "el": { "key": { "value": "...", "formatted": false } }, "en": { ... } }
 *
 * el: the keyed array of build_i18n() when that method exists, otherwise the
 *     'i18n' => array( ... ) passed to the front end in assets().
 * en: the keyed array of build_i18n_en().
 *
 * Values are evaluated the way the page would see them with default settings:
 * __()/_x()/esc_*__() give their source string, sanitize_*()/(string) are
 * transparent, Plandose_Settings::setting( 'x', DEFAULT ) gives DEFAULT, a
 * ternary gives its first evaluable branch, local variables are followed.
 * sprintf() is filled server-side, so it is marked "formatted": true and its
 * arguments are substituted when they are known ($max_days = --max-days,
 * integer literals, Class::CONST found in the plugin). Anything else is null.
 *
 * Usage: php extract-i18n.php <plugin-dir> [--max-days=90]
 */

if ( $argc < 2 || ! is_dir( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php extract-i18n.php <plugin-dir> [--max-days=90]\n" );
	exit( 1 );
}

$plugin_dir = rtrim( $argv[1], '/' );
$max_days   = 90;
foreach ( array_slice( $argv, 2 ) as $arg ) {
	if ( preg_match( '/^--max-days=(\d+)$/', $arg, $m ) ) {
		$max_days = (int) $m[1];
	}
}

$file = $plugin_dir . '/includes/class-plandose-frontend.php';
if ( ! is_readable( $file ) ) {
	fwrite( STDERR, "Not found: $file\n" );
	exit( 1 );
}

/* Class constants of the whole plugin, for sprintf( ..., Plandose_Ajax::X ). */
$constants = array();
foreach ( glob( $plugin_dir . '/includes/*.php' ) as $inc ) {
	$src = file_get_contents( $inc );
	if ( preg_match( '/\bclass\s+(\w+)/', $src, $cm ) ) {
		preg_match_all( '/\bconst\s+(\w+)\s*=\s*(-?\d+)\s*;/', $src, $all, PREG_SET_ORDER );
		foreach ( $all as $c ) {
			$constants[ $cm[1] . '::' . $c[1] ] = (int) $c[2];
		}
	}
}

/* Tokens without whitespace/comments; single chars become [ char, char ]. */
$tokens = array();
foreach ( token_get_all( file_get_contents( $file ) ) as $tok ) {
	if ( is_array( $tok ) ) {
		if ( in_array( $tok[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$tokens[] = array( $tok[0], $tok[1] );
	} else {
		$tokens[] = array( $tok, $tok );
	}
}

function tk_is( $t, $what ) {
	return $t[0] === $what;
}

function is_open( $t ) {
	return in_array( $t[0], array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true );
}

function is_close( $t ) {
	return in_array( $t[0], array( ')', ']', '}' ), true );
}

/* Index of the bracket matching the opener at $i. */
function match_close( $toks, $i ) {
	$depth = 0;
	$n     = count( $toks );
	for ( $j = $i; $j < $n; $j++ ) {
		if ( is_open( $toks[ $j ] ) ) {
			$depth++;
		} elseif ( is_close( $toks[ $j ] ) ) {
			$depth--;
			if ( 0 === $depth ) {
				return $j;
			}
		}
	}
	return -1;
}

/* Split a token list on a top-level separator char. */
function split_top( $toks, $sep ) {
	$parts = array( array() );
	$depth = 0;
	foreach ( $toks as $t ) {
		if ( is_open( $t ) ) {
			$depth++;
		} elseif ( is_close( $t ) ) {
			$depth--;
		}
		if ( 0 === $depth && $t[0] === $sep ) {
			$parts[] = array();
			continue;
		}
		$parts[ count( $parts ) - 1 ][] = $t;
	}
	if ( array() === end( $parts ) ) {
		array_pop( $parts );
	}
	return $parts;
}

function unquote( $lit ) {
	$q    = $lit[0];
	$body = substr( $lit, 1, -1 );
	if ( "'" === $q ) {
		return strtr( $body, array( "\\'" => "'", '\\\\' => '\\' ) );
	}
	return stripcslashes( $body );
}

/* Function bodies: name => token list inside { }. */
function function_bodies( $toks ) {
	$out = array();
	$n   = count( $toks );
	for ( $i = 0; $i < $n - 1; $i++ ) {
		if ( T_FUNCTION === $toks[ $i ][0] && T_STRING === $toks[ $i + 1 ][0] ) {
			for ( $j = $i + 2; $j < $n && '{' !== $toks[ $j ][0] && ';' !== $toks[ $j ][0]; $j++ ) {
			}
			if ( $j < $n && '{' === $toks[ $j ][0] ) {
				$end                        = match_close( $toks, $j );
				$out[ $toks[ $i + 1 ][1] ] = array_slice( $toks, $j + 1, $end - $j - 1 );
			}
		}
	}
	return $out;
}

/* `$var = expr;` statements at the top level of a function body. */
function assignments( $body ) {
	$vars = array();
	foreach ( split_top( $body, ';' ) as $stmt ) {
		if ( count( $stmt ) > 2 && T_VARIABLE === $stmt[0][0] && '=' === $stmt[1][0] ) {
			$vars[ $stmt[0][1] ] = array_slice( $stmt, 2 );
		}
	}
	return $vars;
}

/* Parse array( ... ) / [ ... ] starting at $i: [ key => value tokens ]. */
function keyed_array( $toks, $i ) {
	$open = T_ARRAY === $toks[ $i ][0] ? $i + 1 : $i;
	$end  = match_close( $toks, $open );
	$out  = array();
	foreach ( split_top( array_slice( $toks, $open + 1, $end - $open - 1 ), ',' ) as $entry ) {
		$arrow = -1;
		$depth = 0;
		foreach ( $entry as $k => $t ) {
			if ( is_open( $t ) ) {
				$depth++;
			} elseif ( is_close( $t ) ) {
				$depth--;
			} elseif ( 0 === $depth && T_DOUBLE_ARROW === $t[0] ) {
				$arrow = $k;
				break;
			}
		}
		if ( 1 === $arrow && T_CONSTANT_ENCAPSED_STRING === $entry[0][0] ) {
			$out[ unquote( $entry[0][1] ) ] = array_slice( $entry, 2 );
		}
	}
	return $out;
}

/* The biggest keyed array literal in a token list. */
function largest_keyed_array( $toks ) {
	$best = array();
	$n    = count( $toks );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( ( T_ARRAY === $toks[ $i ][0] && isset( $toks[ $i + 1 ] ) && '(' === $toks[ $i + 1 ][0] ) || '[' === $toks[ $i ][0] ) {
			if ( '[' === $toks[ $i ][0] && $i > 0 && in_array( $toks[ $i - 1 ][0], array( T_VARIABLE, ']', ')', T_STRING ), true ) ) {
				continue; /* $a[ ... ] index, not an array literal */
			}
			$arr = keyed_array( $toks, $i );
			if ( count( $arr ) > count( $best ) ) {
				$best = $arr;
			}
		}
	}
	return $best;
}

/** Evaluate an expression: array( value|null, formatted ). */
function ev( $toks, $ctx ) {
	global $constants;
	$n = count( $toks );
	if ( 0 === $n ) {
		return array( null, false );
	}

	/* Ternary: cond ? a : b (top level). */
	$q = -1;
	$depth = 0;
	foreach ( $toks as $k => $t ) {
		if ( is_open( $t ) ) {
			$depth++;
		} elseif ( is_close( $t ) ) {
			$depth--;
		} elseif ( 0 === $depth && '?' === $t[0] ) {
			$q = $k;
			break;
		}
	}
	if ( $q >= 0 ) {
		$rest  = array_slice( $toks, $q + 1 );
		$depth = 0;
		$tern  = 0;
		foreach ( $rest as $k => $t ) {
			if ( is_open( $t ) ) {
				$depth++;
			} elseif ( is_close( $t ) ) {
				$depth--;
			} elseif ( 0 === $depth && '?' === $t[0] ) {
				$tern++;
			} elseif ( 0 === $depth && ':' === $t[0] ) {
				if ( 0 === $tern ) {
					$a = ev( array_slice( $rest, 0, $k ), $ctx );
					return null !== $a[0] ? $a : ev( array_slice( $rest, $k + 1 ), $ctx );
				}
				$tern--;
			}
		}
		return array( null, false );
	}

	/* Concatenation. */
	$parts = split_top( $toks, '.' );
	if ( count( $parts ) > 1 ) {
		$s   = '';
		$fmt = false;
		foreach ( $parts as $p ) {
			$v = ev( $p, $ctx );
			if ( null === $v[0] ) {
				return array( null, false );
			}
			$s  .= $v[0];
			$fmt = $fmt || $v[1];
		}
		return array( $s, $fmt );
	}

	$t = $toks[0];
	if ( 1 === $n && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
		return array( unquote( $t[1] ), false );
	}
	if ( 1 === $n && T_LNUMBER === $t[0] ) {
		return array( (string) (int) $t[1], false );
	}
	if ( T_STRING_CAST === $t[0] || T_INT_CAST === $t[0] ) {
		return ev( array_slice( $toks, 1 ), $ctx );
	}
	if ( '(' === $t[0] && match_close( $toks, 0 ) === $n - 1 ) {
		return ev( array_slice( $toks, 1, -1 ), $ctx );
	}
	if ( 1 === $n && T_VARIABLE === $t[0] ) {
		if ( '$max_days' === $t[1] ) {
			return array( (string) $ctx['max_days'], false );
		}
		if ( isset( $ctx['vars'][ $t[1] ] ) && empty( $ctx['seen'][ $t[1] ] ) ) {
			$ctx['seen'][ $t[1] ] = true;
			return ev( $ctx['vars'][ $t[1] ], $ctx );
		}
		return array( null, false );
	}
	/* Class::CONST */
	if ( 3 === $n && T_STRING === $t[0] && T_DOUBLE_COLON === $toks[1][0] && T_STRING === $toks[2][0] ) {
		$key = $t[1] . '::' . $toks[2][1];
		return isset( $constants[ $key ] ) ? array( (string) $constants[ $key ], false ) : array( null, false );
	}

	/* Calls: name( args ) or Class::method( args ). */
	$name = '';
	$open = -1;
	if ( T_STRING === $t[0] && $n > 1 && '(' === $toks[1][0] ) {
		$name = strtolower( $t[1] );
		$open = 1;
	} elseif ( T_STRING === $t[0] && $n > 3 && T_DOUBLE_COLON === $toks[1][0] && T_STRING === $toks[2][0] && '(' === $toks[3][0] ) {
		$name = strtolower( $t[1] . '::' . $toks[2][1] );
		$open = 3;
	}
	if ( $open < 0 || match_close( $toks, $open ) !== $n - 1 ) {
		return array( null, false );
	}
	$args = split_top( array_slice( $toks, $open + 1, $n - $open - 2 ), ',' );

	$gettext = array( '__', 'esc_html__', 'esc_attr__', '_x', 'esc_html_x', 'esc_attr_x' );
	$wrap    = array( 'sanitize_text_field', 'sanitize_textarea_field', 'wp_kses_post', 'esc_html', 'esc_attr', 'trim', 'wp_strip_all_tags', 'absint', 'intval', 'wp_specialchars_decode', 'self::plain_text' );
	if ( in_array( $name, $gettext, true ) || in_array( $name, $wrap, true ) ) {
		return isset( $args[0] ) ? ev( $args[0], $ctx ) : array( null, false );
	}
	if ( '::setting' === substr( $name, -9 ) ) {
		return isset( $args[1] ) ? ev( $args[1], $ctx ) : array( '', false );
	}
	if ( 'sprintf' === $name && isset( $args[0] ) ) {
		$fmt = ev( $args[0], $ctx );
		if ( null === $fmt[0] ) {
			return array( null, true );
		}
		$vals = array();
		foreach ( array_slice( $args, 1 ) as $a ) {
			$v = ev( $a, $ctx );
			if ( null === $v[0] ) {
				return array( $fmt[0], true );
			}
			$vals[] = $v[0];
		}
		return array( vsprintf( $fmt[0], $vals ), true );
	}
	return array( null, false );
}

function evaluate_dict( $arr, $ctx ) {
	$out = array();
	foreach ( $arr as $key => $expr ) {
		list( $value, $formatted ) = ev( $expr, $ctx );
		$out[ $key ] = array(
			'value'     => $value,
			'formatted' => $formatted,
		);
	}
	return $out;
}

$bodies = function_bodies( $tokens );

/* Greek. */
$el_arr = array();
$el_ctx = array( 'max_days' => $max_days, 'vars' => array() );
if ( isset( $bodies['build_i18n'] ) ) {
	$el_arr         = largest_keyed_array( $bodies['build_i18n'] );
	$el_ctx['vars'] = assignments( $bodies['build_i18n'] );
} elseif ( isset( $bodies['assets'] ) ) {
	$body = $bodies['assets'];
	$cnt  = count( $body );
	for ( $i = 0; $i < $cnt - 2; $i++ ) {
		if ( T_CONSTANT_ENCAPSED_STRING === $body[ $i ][0] && "'i18n'" === $body[ $i ][1] && T_DOUBLE_ARROW === $body[ $i + 1 ][0] ) {
			$el_arr         = keyed_array( $body, $i + 2 );
			$el_ctx['vars'] = assignments( $body );
			break;
		}
	}
}

/* English. */
$en_arr = array();
$en_ctx = array( 'max_days' => $max_days, 'vars' => array() );
if ( isset( $bodies['build_i18n_en'] ) ) {
	$en_arr         = largest_keyed_array( $bodies['build_i18n_en'] );
	$en_ctx['vars'] = assignments( $bodies['build_i18n_en'] );
}

if ( ! $el_arr || ! $en_arr ) {
	fwrite( STDERR, 'Could not find the ' . ( $el_arr ? 'English' : 'Greek' ) . " dictionary in $file\n" );
	exit( 2 );
}

echo json_encode(
	array(
		'el' => evaluate_dict( $el_arr, $el_ctx ),
		'en' => evaluate_dict( $en_arr, $en_ctx ),
	),
	JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
) . "\n";
