<?php
/**
 * «Κατηγορία Επιχείρησης» (account type) — protection and admin editing.
 *
 * PlanDose is available ONLY to accounts whose business
 * category is «Φαρμακείο» (see Plandose_Access::access_evaluation()). That
 * makes the category meta the key that opens the tool, so this class makes
 * sure it can only be set in the two places it should be:
 *
 *   1. at registration (the registration form writes it once), and
 *   2. by an administrator, through the field this class adds to the
 *      WordPress user profile screen (audited).
 *
 * A logged-in, non-admin user can NOT change their own category afterwards
 * — e.g. through a front-end "edit profile" form of the registration plugin
 * — so a «Εταιρία» account cannot turn itself into «Φαρμακείο».
 *
 * @since 1.21.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Account_Type {

	/**
	 * The canonical category values offered to administrators. The first is
	 * the one PlanDose admits (Plandose_Access::pharmacy_account_type_values()
	 * normalizes case/accents, so older spellings still match).
	 */
	const PHARMACY = 'Φαρμακείο';
	const COMPANY  = 'Εταιρία';

	/**
	 * Nonce action for the profile field.
	 */
	const NONCE_ACTION = 'plandose_account_type_save';
	const NONCE_FIELD  = 'plandose_account_type_nonce';

	/**
	 * User IDs being created in this request. Their category may be written
	 * freely, whoever the current user is (registration plugins write their
	 * fields during or right after wp_insert_user(), sometimes after logging
	 * the new user in).
	 *
	 * @var array<int,bool>
	 */
	private static $registering = array();

	/**
	 * User IDs whose account is being deleted in this request (see
	 * mark_deleting()). Deleting THEIR category rows is let through,
	 * whoever the current user is: wp_delete_user() removes every meta row
	 * of the account, and refusing the category rows would only leave
	 * orphaned rows behind (plus a misleading «blocked» audit entry).
	 *
	 * @var array<int,bool>
	 */
	private static $deleting = array();

	/**
	 * Set while this class itself writes the meta (admin profile save), so
	 * its own writes pass the guard.
	 *
	 * @var bool
	 */
	private static $internal_write = false;

	/**
	 * Category chosen on the profile screen, applied at the very end of the
	 * save (see apply_pending()). user_id => value.
	 *
	 * @var array<int,string>
	 */
	private static $pending = array();

	/**
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		// Mark new users as "being registered" as early as possible:
		// insert_user_meta runs BEFORE wp_insert_user() writes meta_input,
		// and user_register runs after it.
		add_filter( 'insert_user_meta', array( __CLASS__, 'mark_registering' ), 1, 3 );
		add_action( 'user_register', array( __CLASS__, 'mark_registering_id' ), 0 );

		// Account deletion (wp_delete_user(), and wpmu_delete_user() on
		// multisite): both fire their action before removing the meta rows
		// and 'deleted_user' after. Marked as early as possible, so a
		// delete_user callback of another plugin that clears the category
		// is covered too.
		add_action( 'delete_user', array( __CLASS__, 'mark_deleting' ), PHP_INT_MIN );
		add_action( 'wpmu_delete_user', array( __CLASS__, 'mark_deleting' ), PHP_INT_MIN );
		add_action( 'deleted_user', array( __CLASS__, 'unmark_deleting' ), PHP_INT_MAX );

		add_filter( 'add_user_metadata', array( __CLASS__, 'guard_write' ), 10, 4 );
		add_filter( 'update_user_metadata', array( __CLASS__, 'guard_write' ), 10, 4 );
		add_filter( 'delete_user_metadata', array( __CLASS__, 'guard_delete' ), 10, 5 );
		// The by-meta-ID paths (update_metadata_by_mid(),
		// delete_metadata_by_mid(), used e.g. by some importers and by
		// custom-field UIs) bypass the three filters above.
		add_filter( 'update_user_metadata_by_mid', array( __CLASS__, 'guard_update_by_mid' ), 10, 4 );
		add_filter( 'delete_user_metadata_by_mid', array( __CLASS__, 'guard_delete_by_mid' ), 10, 2 );

		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_field' ) );
		add_action( 'profile_update', array( __CLASS__, 'apply_pending' ), PHP_INT_MAX );
	}

	/**
	 * insert_user_meta filter: remember users being created.
	 *
	 * @param array   $meta   Default meta.
	 * @param WP_User $user   User object.
	 * @param bool    $update Whether this is an update.
	 * @return array Unchanged meta.
	 */
	public static function mark_registering( $meta, $user, $update ) {
		if ( ! $update && $user instanceof WP_User && $user->ID ) {
			self::$registering[ (int) $user->ID ] = true;
		}

		return $meta;
	}

	/**
	 * user_register action: remember users being created.
	 *
	 * @param int $user_id New user ID.
	 */
	public static function mark_registering_id( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id < 1 ) {
			return;
		}

		// Some social-login / sync plugins re-fire user_register for an
		// EXISTING account. Only a genuinely new account (created within the
		// last 10 minutes) gets the registration exemption this way.
		$user = get_userdata( $user_id );
		$born = $user ? strtotime( $user->user_registered . ' UTC' ) : false;

		if ( $born && ( time() - $born ) <= 10 * MINUTE_IN_SECONDS ) {
			self::$registering[ $user_id ] = true;
		}
	}

	/**
	 * delete_user / wpmu_delete_user action: the account is being deleted in
	 * this request, so its category rows may go with it.
	 *
	 * Whoever may call wp_delete_user() for an account (an administrator,
	 * a role with delete_users, or a registration plugin's own «delete my
	 * account») decides that; the category guard only stops a LIVING
	 * account from changing its category. Only deletions are let through
	 * this way, never writes.
	 *
	 * @param int $user_id User being deleted.
	 * @return void
	 */
	public static function mark_deleting( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id > 0 ) {
			self::$deleting[ $user_id ] = true;
		}
	}

	/**
	 * deleted_user action: the deletion is over.
	 *
	 * @param int $user_id Deleted user.
	 * @return void
	 */
	public static function unmark_deleting( $user_id ) {
		unset( self::$deleting[ (int) $user_id ] );
	}

	/**
	 * Whether the current user may administer this account's category.
	 *
	 * Same rule as the rest of PlanDose (Plandose_Admin::can_manage_account()):
	 * a non-administrator with PlanDose rights may change other accounts,
	 * never their own and never an administrator's. A role that merely has
	 * edit_users (e.g. a shop manager) is not enough.
	 *
	 * @param int $user_id Target user.
	 * @return bool
	 */
	private static function current_user_can_administer( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id < 1 || ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return class_exists( 'Plandose_Admin' ) && Plandose_Admin::can_manage_account( $user_id );
	}

	/**
	 * Whether the current request may change this user's category.
	 *
	 * Allowed:
	 *  - during that user's registration (see $registering);
	 *  - by an administrator of this account (current_user_can_administer());
	 *  - with no logged-in user, only in a trusted server-side context:
	 *    WP-CLI, WP-Cron, or wherever the filter
	 *    `plandose_account_type_trusted_context` returns true (for
	 *    importers/sync tools that run anonymously). An anonymous web
	 *    request is not trusted by default — a front-end form
	 *    plugin writing meta for a not-logged-in visitor must not be able
	 *    to set the category.
	 *
	 * Refused: any other request — which includes the account owner
	 * editing their own profile.
	 *
	 * @param int $user_id Target user.
	 * @return bool
	 */
	private static function may_change( $user_id ) {
		if ( self::$internal_write || isset( self::$registering[ (int) $user_id ] ) ) {
			return true;
		}

		$current = get_current_user_id();

		if ( 0 === $current ) {
			return self::is_trusted_context( $user_id );
		}

		return self::current_user_can_administer( $user_id );
	}

	/**
	 * Server-side contexts where an anonymous write is trusted.
	 *
	 * @param int $user_id Target user.
	 * @return bool
	 */
	private static function is_trusted_context( $user_id ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}

		/**
		 * Whether an anonymous (no logged-in user) request may change a
		 * user's PlanDose business category. For importers/sync tools that
		 * run outside WP-CLI and cron.
		 *
		 * @param bool $trusted Default false.
		 * @param int  $user_id Target user.
		 */
		return true === apply_filters( 'plandose_account_type_trusted_context', false, (int) $user_id );
	}

	/**
	 * Whether a meta key holds the business category.
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	private static function is_category_key( $meta_key ) {
		if ( ! is_string( $meta_key ) || '' === $meta_key ) {
			return false;
		}

		// MySQL compares meta keys case-insensitively, ignores trailing
		// spaces and (under the usual *_unicode_ci / *_ai_ci collations)
		// accents, so 'Account_Type ' and 'account_typé' address the same
		// row as 'account_type'. Match the key the way the database will.
		$canonical = array_map( array( __CLASS__, 'key_form' ), Plandose_Access::account_type_meta_keys() );

		if ( in_array( self::key_form( $meta_key ), $canonical, true ) ) {
			return true;
		}

		// Printable ASCII has no other equivalences in MySQL's collations.
		// Anything else (full-width letters, ignorable control characters,
		// ligatures, …) is left to the database's own collation to decide.
		if ( 1 !== preg_match( '/[^\x21-\x7E]/', rtrim( $meta_key ) ) ) {
			return false;
		}

		return self::db_key_matches( $meta_key );
	}

	/**
	 * A meta key folded the way MySQL's case- and accent-insensitive,
	 * trailing-space-padding collations compare it.
	 *
	 * @param string $meta_key Meta key.
	 * @return string
	 */
	private static function key_form( $meta_key ) {
		$key = rtrim( (string) $meta_key );

		if ( function_exists( 'remove_accents' ) ) {
			$key = remove_accents( $key );
		}

		return strtolower( $key );
	}

	/**
	 * Whether the usermeta.meta_key column's collation treats $meta_key as
	 * equal to one of the category keys. Asked once per key and request.
	 * A failed query counts as a match (fail closed: the key is guarded).
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	private static function db_key_matches( $meta_key ) {
		static $cache = array();

		if ( isset( $cache[ $meta_key ] ) ) {
			return $cache[ $meta_key ];
		}

		global $wpdb;

		$keys      = Plandose_Access::account_type_meta_keys();
		$collation = self::meta_key_collation();

		if ( '' !== $collation ) {
			$charset = (string) strstr( $collation, '_', true );
			$operand = "CONVERT(%s USING {$charset}) COLLATE {$collation}";
		} else {
			$operand = '%s';
		}

		$sql = 'SELECT ' . $operand . ' IN (' . implode( ', ', array_fill( 0, count( $keys ), $operand ) ) . ')';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql holds only placeholders and a validated collation name; a comparison, no table read.
		$result = $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( $meta_key ), $keys ) ) );

		$cache[ $meta_key ] = ( null === $result ) ? true : ( '1' === (string) $result );

		return $cache[ $meta_key ];
	}

	/**
	 * Collation of usermeta.meta_key ('' when it cannot be read).
	 *
	 * @return string
	 */
	private static function meta_key_collation() {
		static $collation = null;

		if ( null !== $collation ) {
			return $collation;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema lookup, once per request and only for unusual meta keys.
		$column    = $wpdb->get_row( "SHOW FULL COLUMNS FROM {$wpdb->usermeta} LIKE 'meta_key'", ARRAY_A );
		$found     = ( is_array( $column ) && isset( $column['Collation'] ) ) ? (string) $column['Collation'] : '';
		$collation = ( 1 === preg_match( '/^[A-Za-z0-9]+_[A-Za-z0-9_]+\z/', $found ) ) ? $found : '';

		return $collation;
	}

	/**
	 * The values of every row a query on ($user_id, $meta_key) hits, read
	 * straight from the database so the column collation decides which
	 * rows match — exactly the rows the pending write/delete will touch.
	 * (The meta cache is keyed case-sensitively: under 'ACCOUNT_TYPE' it
	 * finds nothing although the database changes the 'account_type' row.)
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key as the caller spelled it.
	 * @return array<int,mixed>|null Unserialized values, or null when the read failed.
	 */
	private static function db_values( $user_id, $meta_key ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must see the rows the database will change, not the (case-sensitive) meta cache.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id", (int) $user_id, (string) $meta_key ) );

		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}

		return array_values( array_map( 'maybe_unserialize', (array) $rows ) );
	}

	/**
	 * Whether writing $value under ($user_id, $meta_key) leaves the category
	 * as it is: every row the key hits already holds that category, or no
	 * row is hit and the value is empty. Fails closed on a read error.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key as the caller spelled it.
	 * @param mixed  $value    New value.
	 * @return bool
	 */
	private static function write_keeps_category( $user_id, $meta_key, $value ) {
		$current = self::db_values( $user_id, $meta_key );

		if ( null === $current ) {
			return false;
		}

		if ( ! $current ) {
			return self::same_category( '', $value );
		}

		foreach ( $current as $stored ) {
			if ( ! self::same_category( $stored, $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether every row ($user_id, $meta_key) hits is empty, so deleting
	 * them clears no category. Fails closed on a read error.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key as the caller spelled it.
	 * @return bool
	 */
	private static function rows_are_empty( $user_id, $meta_key ) {
		$current = self::db_values( $user_id, $meta_key );

		if ( null === $current ) {
			return false;
		}

		foreach ( $current as $stored ) {
			if ( '' !== self::scalar( $stored ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * add_user_metadata / update_user_metadata short-circuit.
	 *
	 * Returning null lets WordPress write; returning false blocks the write
	 * (and reports failure to the caller). Only an actual CHANGE is blocked:
	 * profile forms that re-save the unchanged value keep working.
	 *
	 * @param null|bool $check    Short-circuit value from earlier filters.
	 * @param int       $user_id  User ID.
	 * @param string    $meta_key Meta key.
	 * @param mixed     $value    New value.
	 * @return null|bool
	 */
	public static function guard_write( $check, $user_id, $meta_key, $value = null ) {
		if ( null !== $check || ! self::is_category_key( $meta_key ) ) {
			return $check;
		}

		if ( self::may_change( $user_id ) ) {
			return $check;
		}

		if ( self::write_keeps_category( (int) $user_id, $meta_key, $value ) ) {
			return $check;
		}

		self::log_blocked( (int) $user_id, $meta_key );

		return false;
	}

	/**
	 * delete_user_metadata short-circuit: a user may not clear their own
	 * category either.
	 *
	 * @param null|bool $check    Short-circuit value.
	 * @param int       $user_id  User ID.
	 * @param string    $meta_key Meta key.
	 * @return null|bool
	 */
	public static function guard_delete( $check, $user_id, $meta_key, $meta_value = '', $delete_all = false ) {
		if ( null !== $check || ! self::is_category_key( $meta_key ) ) {
			return $check;
		}

		/*
		 * delete_metadata( 'user', 0, 'account_type', '', true )
		 * removes the category of EVERY user (user_id is 0, so the check
		 * below would read an empty value and let it through). Allowed only for
		 * PlanDose itself, a trusted server context or an administrator
		 * (e.g. the registration plugin's own uninstall).
		 */
		if ( $delete_all ) {
			if ( self::$internal_write || self::is_trusted_context( 0 ) || current_user_can( 'manage_options' ) ) {
				return $check;
			}

			self::log_blocked( 0, $meta_key );

			return false;
		}

		if ( isset( self::$deleting[ (int) $user_id ] ) || self::may_change( $user_id ) ) {
			return $check;
		}

		if ( self::rows_are_empty( (int) $user_id, $meta_key ) ) {
			return $check;
		}

		self::log_blocked( (int) $user_id, $meta_key );

		return false;
	}

	/**
	 * update_user_metadata_by_mid short-circuit. The row is looked
	 * up by its meta ID to learn the user and the current key; a change of
	 * the key (rename) is covered in both directions.
	 *
	 * @param null|bool   $check      Short-circuit value.
	 * @param int         $meta_id    Meta ID.
	 * @param mixed       $meta_value New value.
	 * @param string|bool $meta_key   New key, or false to keep it.
	 * @return null|bool
	 */
	public static function guard_update_by_mid( $check, $meta_id, $meta_value = null, $meta_key = false ) {
		if ( null !== $check ) {
			return $check;
		}

		$meta = get_metadata_by_mid( 'user', (int) $meta_id );

		if ( ! is_object( $meta ) || empty( $meta->user_id ) ) {
			return $check;
		}

		$user_id     = (int) $meta->user_id;
		$old_key     = (string) $meta->meta_key;
		$new_key     = ( is_string( $meta_key ) && '' !== $meta_key ) ? $meta_key : $old_key;
		$touches_old = self::is_category_key( $old_key );
		$touches_new = self::is_category_key( $new_key );

		if ( ( ! $touches_old && ! $touches_new ) || self::may_change( $user_id ) ) {
			return $check;
		}

		$same_key = self::key_form( $old_key ) === self::key_form( $new_key );

		if ( $touches_old && $same_key && self::same_category( $meta->meta_value, $meta_value ) ) {
			return $check;
		}

		// A non-category row renamed to a category key: allowed only when
		// it writes the value already in force.
		if ( ! $touches_old && self::write_keeps_category( $user_id, $new_key, $meta_value ) ) {
			return $check;
		}

		self::log_blocked( $user_id, $touches_old ? $old_key : $new_key );

		return false;
	}

	/**
	 * delete_user_metadata_by_mid short-circuit.
	 *
	 * @param null|bool $check   Short-circuit value.
	 * @param int       $meta_id Meta ID.
	 * @return null|bool
	 */
	public static function guard_delete_by_mid( $check, $meta_id ) {
		if ( null !== $check ) {
			return $check;
		}

		$meta = get_metadata_by_mid( 'user', (int) $meta_id );

		if ( ! is_object( $meta ) || empty( $meta->user_id ) || ! self::is_category_key( (string) $meta->meta_key ) ) {
			return $check;
		}

		$user_id = (int) $meta->user_id;

		if ( isset( self::$deleting[ $user_id ] ) || self::may_change( $user_id ) || '' === self::scalar( $meta->meta_value ) ) {
			return $check;
		}

		self::log_blocked( $user_id, (string) $meta->meta_key );

		return false;
	}

	/**
	 * Compare two category values the way PlanDose does (case/accents).
	 *
	 * @param mixed $a Value.
	 * @param mixed $b Value.
	 * @return bool
	 */
	private static function same_category( $a, $b ) {
		return Plandose_Access::normalize_text( self::scalar( $a ) ) === Plandose_Access::normalize_text( self::scalar( $b ) );
	}

	/**
	 * Flatten a meta value (selects may store arrays) to a string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function scalar( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), static function ( $v ) { return '' !== $v; } ) );
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Audit a refused change (once per user and key per request).
	 *
	 * @param int    $user_id  Target user.
	 * @param string $meta_key Meta key.
	 */
	private static function log_blocked( $user_id, $meta_key ) {
		static $logged = array();

		$key = $user_id . '|' . $meta_key;

		if ( isset( $logged[ $key ] ) ) {
			return;
		}

		$logged[ $key ] = true;

		// At most one entry per user per hour: a user resubmitting a form
		// must not be able to flood the audit log.
		$throttle = 'plandose_acct_blocked_' . $user_id;

		if ( get_transient( $throttle ) ) {
			return;
		}

		set_transient( $throttle, 1, HOUR_IN_SECONDS );

		if ( class_exists( 'Plandose_Admin' ) ) {
			Plandose_Admin::audit( 'account_type_change_blocked', $user_id, array( 'key' => $meta_key ) );
		}
	}

	/**
	 * Every non-empty stored category value, by meta key.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,string>
	 */
	private static function stored_values( $user_id ) {
		$values = array();

		foreach ( Plandose_Access::account_type_meta_keys() as $key ) {
			$value = self::scalar( get_user_meta( (int) $user_id, $key, true ) );

			if ( '' !== $value ) {
				$values[ $key ] = $value;
			}
		}

		return $values;
	}

	/**
	 * The category as PlanDose sees it (first non-empty key).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function current_value( $user_id ) {
		foreach ( Plandose_Access::account_type_meta_keys() as $key ) {
			$value = self::scalar( get_user_meta( (int) $user_id, $key, true ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Profile screen field — administrators only.
	 *
	 * @param WP_User $user User being edited.
	 */
	public static function render_profile_field( $user ) {
		if ( ! $user instanceof WP_User || ! self::current_user_can_administer( $user->ID ) ) {
			return;
		}

		$current   = self::current_value( $user->ID );
		$options   = array( self::PHARMACY, self::COMPANY );
		$is_custom = '' !== $current && ! in_array( $current, $options, true );
		$pharmacy  = Plandose_Access::is_registered_pharmacist( $user->ID );
		$stored    = self::stored_values( $user->ID );
		$conflict  = count( array_unique( array_map( array( 'Plandose_Access', 'normalize_text' ), $stored ) ) ) > 1;
		?>
		<h2><?php esc_html_e( 'PlanDose', 'plandose' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="plandose-account-type"><?php esc_html_e( 'Κατηγορία Επιχείρησης', 'plandose' ); ?></label></th>
				<td>
					<?php wp_nonce_field( self::NONCE_ACTION . '_' . $user->ID, self::NONCE_FIELD ); ?>
					<select name="plandose_account_type" id="plandose-account-type">
						<option value="" <?php selected( '', $current ); ?>><?php esc_html_e( '— Χωρίς κατηγορία —', 'plandose' ); ?></option>
						<?php foreach ( $options as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $option, $is_custom ? '' : $current ); ?>><?php echo esc_html( $option ); ?></option>
						<?php endforeach; ?>
						<?php if ( $is_custom ) : ?>
							<option value="<?php echo esc_attr( '__keep__' ); ?>" selected="selected">
								<?php
								/* translators: %s: category value stored by the registration form. */
								echo esc_html( sprintf( __( 'Άλλη τιμή: %s (διατήρηση)', 'plandose' ), $current ) );
								?>
							</option>
						<?php endif; ?>
					</select>
					<p class="description">
						<?php
						echo $pharmacy
							? esc_html__( 'Ο λογαριασμός αναγνωρίζεται ως φαρμακείο και μπορεί να χρησιμοποιήσει το PlanDose.', 'plandose' )
							: esc_html__( 'Ο λογαριασμός ΔΕΝ αναγνωρίζεται ως φαρμακείο: δεν έχει πρόσβαση στο PlanDose και δεν μπορεί να γίνει Pro.', 'plandose' );
						?>
						<br />
						<?php esc_html_e( 'Ο χρήστης δεν μπορεί να αλλάξει μόνος του την κατηγορία μετά την εγγραφή. Οι αλλαγές από αυτή τη σελίδα καταγράφονται στο Ημερολόγιο του PlanDose.', 'plandose' ); ?>
					</p>
					<?php if ( $conflict ) : ?>
						<p class="description" style="color:#b32d2e">
							<?php
							/* translators: %s: list of stored values, e.g. "account_type: Εταιρία, user_registration_account_type: Φαρμακείο". */
							echo esc_html( sprintf( __( 'Προσοχή: η κατηγορία είναι αποθηκευμένη με διαφορετικές τιμές (%s). Με την αποθήκευση θα γραφτεί η επιλεγμένη τιμή παντού.', 'plandose' ), implode( ', ', array_map( static function ( $k, $v ) { return $k . ': ' . $v; }, array_keys( $stored ), $stored ) ) ) );
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Profile save, step 1: validate and remember the chosen category.
	 *
	 * Not written here: other plugins (e.g. User Registration) save their
	 * own profile fields later in the same request and could write the old
	 * value back. apply_pending() writes it at the very end.
	 *
	 * @param int $user_id User being saved.
	 */
	public static function save_profile_field( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id < 1 || ! isset( $_POST['plandose_account_type'], $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		if ( ! self::current_user_can_administer( $user_id ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION . '_' . $user_id ) ) {
			return;
		}

		$raw = wp_unslash( $_POST['plandose_account_type'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- whitelisted below.

		if ( ! is_string( $raw ) ) {
			return;
		}

		// $raw is already unslashed above; a second wp_unslash() would
		// strip a backslash the admin actually sent ("\\Φαρμακείο" would
		// pass the whitelist as «Φαρμακείο»).
		$new = sanitize_text_field( $raw );

		if ( '__keep__' === $new || ! in_array( $new, array( '', self::PHARMACY, self::COMPANY ), true ) ) {
			return;
		}

		self::$pending[ $user_id ] = $new;
	}

	/**
	 * Profile save, step 2 (profile_update, last priority): write the chosen
	 * category to EVERY category key the user has (plus 'account_type').
	 *
	 * Plandose_Access::is_registered_pharmacist() accepts a match on any
	 * key, so changing only one of them would leave a stale «Φαρμακείο» in
	 * another and silently keep access open. Skipped only when every key
	 * already holds the chosen value.
	 *
	 * @param int $user_id User just saved.
	 */
	public static function apply_pending( $user_id ) {
		$user_id = (int) $user_id;

		if ( ! isset( self::$pending[ $user_id ] ) ) {
			return;
		}

		$new = self::$pending[ $user_id ];
		unset( self::$pending[ $user_id ] );

		$stored       = self::stored_values( $user_id );
		$old          = self::current_value( $user_id );
		$was_pharmacy = Plandose_Access::is_registered_pharmacist( $user_id );

		$keys = array( 'account_type' );

		foreach ( Plandose_Access::account_type_meta_keys() as $key ) {
			if ( metadata_exists( 'user', $user_id, $key ) ) {
				$keys[] = $key;
			}
		}

		$keys = array_unique( $keys );

		$all_equal = true;

		foreach ( $keys as $key ) {
			if ( ( isset( $stored[ $key ] ) ? $stored[ $key ] : '' ) !== $new ) {
				$all_equal = false;
				break;
			}
		}

		if ( $all_equal ) {
			return;
		}

		self::$internal_write = true;

		try {
			foreach ( $keys as $key ) {
				if ( '' === $new ) {
					delete_user_meta( $user_id, $key );
				} else {
					update_user_meta( $user_id, $key, $new );
				}
			}
		} finally {
			self::$internal_write = false;
		}

		if ( class_exists( 'Plandose_Subscriber_Query' ) && method_exists( 'Plandose_Subscriber_Query', 'invalidate_subscriber_cache' ) ) {
			Plandose_Subscriber_Query::invalidate_subscriber_cache();
		}

		if ( class_exists( 'Plandose_Admin' ) ) {
			Plandose_Admin::audit(
				'account_type_change',
				$user_id,
				array(
					'from'         => $old,
					'to'           => $new,
					'was_pharmacy' => $was_pharmacy ? 1 : 0,
					'is_pharmacy'  => Plandose_Access::is_registered_pharmacist( $user_id ) ? 1 : 0,
				)
			);
		}
	}
}

Plandose_Account_Type::init();
