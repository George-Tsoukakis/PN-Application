<?php
/*
 * Ψεύτικο $wpdb πάνω σε πραγματική SQL μηχανή (SQLite μέσω PDO, in-memory).
 *
 * Υποσύνολο του wpdb που χρησιμοποιεί το plugin: options/usermeta/prefix,
 * prepare() με σημασιολογία WP, get_var/get_results/get_col/query, esc_like.
 * Τα MySQL-isms μεταφράζονται σε SQLite (βλ. translate()). Επιπλέον: options
 * και transients του core πάνω στον ίδιο πίνακα, και ο cron καθαρισμός του
 * core (delete_expired_transients).
 *
 * Χρήση: require boot.php (προαιρετικά) και μετά αυτό· qrrp_sqlite_install().
 * Το get_option() του boot.php διαβάζει $GLOBALS['__options']: εκεί μπαίνει
 * γέφυρα ArrayAccess προς τον πίνακα, ώστε να μη χρειάζεται redeclare.
 */

if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'ARRAY_N' ) ) { define( 'ARRAY_N', 'ARRAY_N' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

final class QRRP_SQLite_WPDB {
	public $prefix   = 'wp_';
	public $options  = 'wp_options';
	public $usermeta = 'wp_usermeta';

	public $last_error  = '';
	public $last_query  = '';
	public $last_sql    = ''; // Η μεταφρασμένη (SQLite) μορφή.
	public $num_queries = 0;
	public $rows_affected = 0;

	/** Hook πριν από κάθε query: fn( string $mysql_sql, QRRP_SQLite_WPDB $db ). Για κούρσες. */
	public $before_query = null;

	/** @var PDO */
	public $pdo;

	private $last_result = array();

	public function __construct( $prefix = 'wp_' ) {
		$this->prefix   = $prefix;
		$this->options  = $prefix . 'options';
		$this->usermeta = $prefix . 'usermeta';

		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		// Ο wpdb επιστρέφει πάντα strings (το CAS συγκρίνει ===).
		$this->pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );

		$this->pdo->exec( "CREATE TABLE {$this->options} (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT NOT NULL UNIQUE, option_value TEXT NOT NULL DEFAULT '', autoload TEXT NOT NULL DEFAULT 'yes')" );
		$this->pdo->exec( "CREATE TABLE {$this->usermeta} (umeta_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL DEFAULT 0, meta_key TEXT, meta_value TEXT)" );
		$this->pdo->exec( "CREATE INDEX {$this->usermeta}_user ON {$this->usermeta} (user_id, meta_key)" );
	}

	/* --- prepare / esc_like --- */

	/** WP: addcslashes με '_%\'. */
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/** Literal SQLite: διπλασιασμός του '· το backslash μένει κυριολεκτικό (όπως μετά το unescape της MySQL). */
	public function quote( $v ) {
		return "'" . str_replace( "'", "''", (string) $v ) . "'";
	}

	/**
	 * %s (quoted), %d, %f/%F, %% και %N$s. Args ως λίστα ή ως ένας πίνακας.
	 * Λάθος πλήθος args → null (ο WP κάνει _doing_it_wrong και αποτυγχάνει).
	 */
	public function prepare( $query, ...$args ) {
		if ( null === $query ) {
			return null;
		}
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = array_values( $args[0] );
		}

		$i     = 0;
		$bad   = false;
		$self  = $this;
		$out   = preg_replace_callback(
			'/%(?:(\d+)\$)?([sdfF%])/',
			static function ( $m ) use ( &$i, &$bad, $args, $self ) {
				if ( '%' === $m[2] ) {
					return '%';
				}
				$idx = ( '' !== $m[1] ) ? (int) $m[1] - 1 : $i++;
				if ( ! array_key_exists( $idx, $args ) ) {
					$bad = true;
					return '';
				}
				$v = $args[ $idx ];
				switch ( $m[2] ) {
					case 'd':
						return (string) (int) $v;
					case 'f':
					case 'F':
						return sprintf( '%F', (float) $v );
					default:
						return $self->quote( is_scalar( $v ) || null === $v ? (string) $v : '' );
				}
			},
			(string) $query
		);

		if ( $bad || $i < count( $args ) && ! preg_match( '/%\d+\$/', (string) $query ) ) {
			$this->last_error = 'prepare: placeholder/argument count mismatch';
			return null;
		}

		return $out;
	}

	/* --- Μετάφραση MySQL → SQLite --- */

	/**
	 * @return array{0:string,1:?array} [sql, multi-delete spec ή null]
	 */
	public function translate( $sql ) {
		$lits = array();
		// Βγάζουμε τα literals ώστε οι μετατροπές να μην αγγίζουν δεδομένα.
		$sql = preg_replace_callback(
			"/'[^']*+(?:''[^']*+)*+'/",
			static function ( $m ) use ( &$lits ) {
				$lits[] = $m[0];
				return "\x01" . ( count( $lits ) - 1 ) . "\x01";
			},
			$sql
		);
		if ( null === $sql ) {
			throw new RuntimeException( 'translate: preg error ' . preg_last_error_msg() );
		}

		$sql = preg_replace( '/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql );

		if ( preg_match( '/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b(.*)$/is', $sql, $m ) ) {
			$set = preg_replace( '/\bVALUES\s*\(\s*(\w+)\s*\)/i', 'excluded.$1', $m[1] );
			$sql = substr( $sql, 0, - strlen( $m[0] ) ) . 'ON CONFLICT(option_name) DO UPDATE SET' . $set;
		}

		$sql = preg_replace( '/\bAS\s+(?:UNSIGNED|SIGNED)(?:\s+INTEGER)?\b/i', 'AS INTEGER', $sql );
		$sql = self::translate_concat( $sql );
		$sql = preg_replace( '/\bSUBSTRING\s*\(/i', 'substr(', $sql );

		// MySQL συγκρίνει string στήλη με αριθμό αριθμητικά· η SQLite (TEXT affinity) λεξικογραφικά.
		$sql = preg_replace( '/(?<![\w.])((?:\w+\.)?(?:option_value|meta_value))\s*(<=|>=|<|>)\s*(-?\d+)\b/i', 'CAST($1 AS INTEGER) $2 $3', $sql );

		// Default escape της MySQL στο LIKE είναι το '\'· στην SQLite κανένα.
		$sql = preg_replace( "/\\bLIKE\\s+(\x01\\d+\x01)(?!\\s*ESCAPE)/i", "LIKE $1 ESCAPE '\\\\'", $sql );

		// Affected rows της MySQL = γραμμές που άλλαξαν, όχι που ταίριαξαν.
		if ( preg_match( "/^\\s*UPDATE\\s+(\\S+)\\s+SET\\s+(\\w+)\\s*=\\s*(\x01\\d+\x01)\\s+WHERE\\s+(.+)$/is", $sql, $m ) ) {
			$sql = "UPDATE {$m[1]} SET {$m[2]} = {$m[3]} WHERE ( {$m[4]} ) AND {$m[2]} IS NOT {$m[3]}";
		}

		$multi = null;
		if ( preg_match( '/^\s*DELETE\s+(\w+)\s*,\s*(\w+)\s+FROM\s+(.+)$/is', $sql, $m ) ) {
			$tables = array();
			foreach ( array( $m[1], $m[2] ) as $alias ) {
				if ( ! preg_match( '/(\w+)\s+(?:AS\s+)?' . preg_quote( $alias, '/' ) . '\b/i', $m[3], $tm ) ) {
					throw new RuntimeException( 'multi-delete: unknown alias ' . $alias );
				}
				$tables[ $alias ] = $tm[1];
			}
			$multi = $tables;
			$sql   = "SELECT {$m[1]}.rowid AS r0, {$m[2]}.rowid AS r1 FROM {$m[3]}";
		}

		$sql = preg_replace_callback(
			"/\x01(\\d+)\x01/",
			static function ( $m ) use ( $lits ) {
				return $lits[ (int) $m[1] ];
			},
			$sql
		);

		return array( $sql, $multi );
	}

	/** CONCAT(a, b, …) → (a || b || …), με ταίριασμα παρενθέσεων (literals ήδη εκτός). */
	private static function translate_concat( $sql ) {
		while ( preg_match( '/\bCONCAT\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE ) ) {
			$start = $m[0][1];
			$open  = $start + strlen( $m[0][0] ) - 1;
			$depth = 0;
			$args  = array();
			$cur   = '';
			$end   = -1;
			for ( $p = $open; $p < strlen( $sql ); $p++ ) {
				$c = $sql[ $p ];
				if ( '(' === $c ) {
					if ( $depth++ > 0 ) { $cur .= $c; }
					continue;
				}
				if ( ')' === $c ) {
					if ( --$depth === 0 ) { $args[] = $cur; $end = $p; break; }
					$cur .= $c;
					continue;
				}
				if ( ',' === $c && 1 === $depth ) { $args[] = $cur; $cur = ''; continue; }
				$cur .= $c;
			}
			if ( $end < 0 ) {
				throw new RuntimeException( 'CONCAT: unbalanced parentheses' );
			}
			$args = array_map( static fn( $a ) => '(' . self::translate_concat( trim( $a ) ) . ')', $args );
			$sql  = substr( $sql, 0, $start ) . '(' . implode( ' || ', $args ) . ')' . substr( $sql, $end + 1 );
		}
		return $sql;
	}

	/* --- Εκτέλεση --- */

	/** @return int|false Γραμμές (SELECT) ή affected rows· false σε σφάλμα. */
	public function query( $query ) {
		if ( null === $query || '' === $query ) {
			return false;
		}
		$this->last_result   = array();
		$this->last_error    = '';
		$this->rows_affected = 0;
		$this->last_query    = $query;
		++$this->num_queries;

		if ( is_callable( $this->before_query ) ) {
			call_user_func( $this->before_query, $query, $this );
		}

		try {
			list( $sql, $multi ) = $this->translate( $query );
			$this->last_sql      = $sql;

			if ( null !== $multi ) {
				return $this->rows_affected = $this->multi_delete( $sql, $multi );
			}

			if ( preg_match( '/^\s*(SELECT|PRAGMA|WITH)\b/i', $sql ) ) {
				$st                = $this->pdo->query( $sql );
				$this->last_result = $st->fetchAll( PDO::FETCH_ASSOC );
				return count( $this->last_result );
			}

			return $this->rows_affected = (int) $this->pdo->exec( $sql );
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/** DELETE a, b FROM …: συλλογή rowids και διαγραφή, ατομικά (savepoint). */
	private function multi_delete( $select, $tables ) {
		$this->pdo->exec( 'SAVEPOINT qrrp_md' );
		try {
			$rows = $this->pdo->query( $select )->fetchAll( PDO::FETCH_NUM );
			$ids  = array();
			$i    = 0;
			foreach ( $tables as $alias => $table ) {
				foreach ( $rows as $r ) {
					$ids[ $table ][ (int) $r[ $i ] ] = true;
				}
				++$i;
			}
			$deleted = 0;
			foreach ( $ids as $table => $set ) {
				foreach ( array_chunk( array_keys( $set ), 500 ) as $chunk ) {
					$deleted += (int) $this->pdo->exec( "DELETE FROM {$table} WHERE rowid IN (" . implode( ',', $chunk ) . ')' );
				}
			}
			$this->pdo->exec( 'RELEASE qrrp_md' );
			return $deleted;
		} catch ( Throwable $e ) {
			$this->pdo->exec( 'ROLLBACK TO qrrp_md' );
			$this->pdo->exec( 'RELEASE qrrp_md' );
			throw $e;
		}
	}

	public function get_var( $query = null, $x = 0, $y = 0 ) {
		if ( null !== $query && false === $this->query( $query ) ) {
			return null;
		}
		if ( ! isset( $this->last_result[ $y ] ) ) {
			return null;
		}
		$vals = array_values( $this->last_result[ $y ] );
		return $vals[ $x ] ?? null;
	}

	/** Όπως ο WP: σε σφάλμα query επιστρέφεται array() (το flush το αδειάζει), null μόνο χωρίς query. */
	public function get_results( $query = null, $output = OBJECT ) {
		if ( null === $query ) {
			return null;
		}
		$this->query( $query );
		$out = array();
		foreach ( $this->last_result as $row ) {
			if ( ARRAY_A === $output ) {
				$out[] = $row;
			} elseif ( ARRAY_N === $output ) {
				$out[] = array_values( $row );
			} else {
				$out[] = (object) $row;
			}
		}
		return $out;
	}

	public function get_col( $query = null, $x = 0 ) {
		if ( null !== $query ) {
			$this->query( $query );
		}
		$out = array();
		foreach ( $this->last_result as $row ) {
			$vals  = array_values( $row );
			$out[] = $vals[ $x ] ?? null;
		}
		return $out;
	}

	/* --- Βοηθητικά για τα tests (άμεση SQLite, χωρίς μετάφραση/hook) --- */

	public function raw( $sql, array $bind = array() ) {
		$st = $this->pdo->prepare( $sql );
		$st->execute( $bind );
		return $st;
	}

	public function count_prefix( $prefix ) {
		return (int) $this->raw( "SELECT COUNT(*) FROM {$this->options} WHERE substr(option_name, 1, ?) = ?", array( strlen( $prefix ), $prefix ) )->fetchColumn();
	}

	/** @return array name => value */
	public function rows_prefix( $prefix ) {
		$st = $this->raw( "SELECT option_name, option_value FROM {$this->options} WHERE substr(option_name, 1, ?) = ?", array( strlen( $prefix ), $prefix ) );
		return $st->fetchAll( PDO::FETCH_KEY_PAIR );
	}

	public function raw_value( $name ) {
		$v = $this->raw( "SELECT option_value FROM {$this->options} WHERE option_name = ?", array( $name ) )->fetchColumn();
		return false === $v ? null : (string) $v;
	}

	public function raw_set( $name, $value, $autoload = 'no' ) {
		$this->raw( "INSERT INTO {$this->options} (option_name, option_value, autoload) VALUES (?, ?, ?) ON CONFLICT(option_name) DO UPDATE SET option_value = excluded.option_value", array( $name, (string) $value, $autoload ) );
	}

	public function raw_delete( $name ) {
		return $this->raw( "DELETE FROM {$this->options} WHERE option_name = ?", array( $name ) )->rowCount();
	}

	public function total_rows() {
		return (int) $this->pdo->query( "SELECT COUNT(*) FROM {$this->options}" )->fetchColumn();
	}
}

/** Γέφυρα για το get_option() του boot.php ($GLOBALS['__options'][$o] ?? $d). */
final class QRRP_SQLite_Options_Bridge implements ArrayAccess {
	public function offsetExists( $name ): bool {
		return null !== qrrp_sq_raw_option( (string) $name );
	}
	public function offsetGet( $name ): mixed {
		$raw = qrrp_sq_raw_option( (string) $name );
		return null === $raw ? null : maybe_unserialize( $raw );
	}
	public function offsetSet( $name, $value ): void {
		update_option( (string) $name, $value );
	}
	public function offsetUnset( $name ): void {
		delete_option( (string) $name );
	}
}

/** Εγκαθιστά νέα κενή βάση ως global $wpdb. */
function qrrp_sqlite_install( $prefix = 'wp_' ) {
	$GLOBALS['wpdb']      = new QRRP_SQLite_WPDB( $prefix );
	$GLOBALS['__options'] = new QRRP_SQLite_Options_Bridge();
	return $GLOBALS['wpdb'];
}

/* --- Stubs WP που χρειάζονται οι κλάσεις (μόνο αν λείπουν) --- */

if ( ! function_exists( 'is_serialized' ) ) {
	function is_serialized( $data ) {
		if ( ! is_string( $data ) ) { return false; }
		$data = trim( $data );
		if ( 'N;' === $data ) { return true; }
		if ( strlen( $data ) < 4 || ':' !== $data[1] ) { return false; }
		$last = substr( $data, -1 );
		if ( ';' !== $last && '}' !== $last ) { return false; }
		return in_array( $data[0], array( 's', 'a', 'O', 'b', 'i', 'd' ), true );
	}
}
if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $data ) {
		if ( is_array( $data ) || is_object( $data ) ) { return serialize( $data ); }
		if ( is_serialized( $data ) ) { return serialize( $data ); } // Όπως ο core: διπλό serialize.
		return $data;
	}
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $data ) {
		return is_serialized( $data ) ? @unserialize( trim( $data ) ) : $data;
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( ...$a ) { return true; } }
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) { function wp_using_ext_object_cache() { return false; } }
if ( ! function_exists( 'wp_installing' ) ) { function wp_installing() { return false; } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) $s ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return false; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 0; } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $s = '' ) { return 'salt'; } }
if ( ! function_exists( 'do_action' ) ) { function do_action( ...$a ) {} }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v, ...$a ) { return $v; } }
if ( ! function_exists( 'add_action' ) ) { function add_action( ...$a ) {} }

/* --- Options API (σημασιολογία core, χωρίς object cache/alloptions) --- */

/** Ωμή τιμή από τη βάση, ή null. */
function qrrp_sq_raw_option( $name ) {
	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
	return null === $raw ? null : (string) $raw;
}

function qrrp_sq_get_option( $name, $default = false ) {
	$raw = qrrp_sq_raw_option( (string) $name );
	return null === $raw ? $default : maybe_unserialize( $raw );
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return qrrp_sq_get_option( $name, $default );
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/** Core: false αν υπάρχει· αλλιώς INSERT … ON DUPLICATE KEY UPDATE (upsert, όχι create). */
	function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
		global $wpdb;
		$name = trim( (string) $name );
		if ( '' === $name || null !== qrrp_sq_raw_option( $name ) ) {
			return false;
		}
		$autoload = in_array( $autoload, array( 'no', 'off', false ), true ) ? 'off' : 'on';
		$r        = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_name = VALUES(option_name), option_value = VALUES(option_value), autoload = VALUES(autoload)", $name, maybe_serialize( $value ), $autoload ) );
		return (bool) $r;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		global $wpdb;
		$name    = trim( (string) $name );
		$old_raw = qrrp_sq_raw_option( $name );
		if ( null === $old_raw ) {
			return add_option( $name, $value, '', null === $autoload ? 'yes' : $autoload );
		}
		$old = maybe_unserialize( $old_raw );
		if ( $value === $old || maybe_serialize( $value ) === maybe_serialize( $old ) ) {
			return false;
		}
		$r = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", maybe_serialize( $value ), $name ) );
		return (bool) $r;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		global $wpdb;
		$name = trim( (string) $name );
		if ( null === qrrp_sq_raw_option( $name ) ) {
			return false;
		}
		$r = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		return (bool) $r;
	}
}

/* --- Transients API (DB backend, σημασιολογία core) --- */

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		$expiration = (int) $expiration;
		$opt        = '_transient_' . $transient;
		$tout       = '_transient_timeout_' . $transient;

		if ( null === qrrp_sq_raw_option( $opt ) ) {
			$autoload = 'yes';
			if ( $expiration ) {
				$autoload = 'no';
				add_option( $tout, time() + $expiration, '', 'no' );
			}
			return add_option( $opt, $value, '', $autoload );
		}

		$update = true;
		$result = false;
		if ( $expiration ) {
			if ( null === qrrp_sq_raw_option( $tout ) ) {
				delete_option( $opt );
				add_option( $tout, time() + $expiration, '', 'no' );
				$result = add_option( $opt, $value, '', 'no' );
				$update = false;
			} else {
				update_option( $tout, time() + $expiration );
			}
		}
		if ( $update ) {
			$result = update_option( $opt, $value );
		}
		return $result;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/** Core: ληγμένο (timeout < time()) σβήνεται στην ανάγνωση. Autoload γραμμές δεν ελέγχονται. */
	function get_transient( $transient ) {
		$opt = '_transient_' . $transient;
		if ( ! wp_installing() ) {
			$raw_opt = qrrp_sq_raw_option( $opt );
			$is_auto = false;
			if ( null !== $raw_opt ) {
				global $wpdb;
				$al      = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $opt ) );
				$is_auto = in_array( $al, array( 'yes', 'on', 'auto-on', 'auto' ), true );
			}
			if ( ! $is_auto ) {
				$tout    = '_transient_timeout_' . $transient;
				$timeout = qrrp_sq_get_option( $tout );
				if ( false !== $timeout && $timeout < time() ) {
					delete_option( $opt );
					delete_option( $tout );
					return false;
				}
			}
		}
		return qrrp_sq_get_option( $opt );
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		$result = delete_option( '_transient_' . $transient );
		if ( $result ) {
			delete_option( '_transient_timeout_' . $transient );
		}
		return $result;
	}
}

/**
 * Ο daily cron του core (delete_expired_transients), με ρητό $now.
 * Ίδιο SQL με τον core: σβήνει ζεύγη value+timeout με timeout < now.
 * Ορφανά timeouts (χωρίς value) ο core ΔΕΝ τα σβήνει.
 *
 * @return int|false Γραμμές που σβήστηκαν.
 */
function qrrp_sq_delete_expired_transients( $now = null, $force_db = false ) {
	global $wpdb;
	if ( ! $force_db && wp_using_ext_object_cache() ) {
		return 0;
	}
	$now = null === $now ? time() : (int) $now;
	return $wpdb->query(
		$wpdb->prepare(
			"DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b
			WHERE a.option_name LIKE %s
			AND a.option_name NOT LIKE %s
			AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
			AND b.option_value < %d",
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			$now
		)
	);
}

if ( ! function_exists( 'delete_expired_transients' ) ) {
	function delete_expired_transients( $force_db = false ) {
		qrrp_sq_delete_expired_transients( null, $force_db );
	}
}
