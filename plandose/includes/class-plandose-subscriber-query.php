<?php
/**
 * Read side of the subscriber list: the WHERE builder, paginated queries,
 * hydration, cache invalidation and the admin stats/debug helpers.
 *
 * Nothing here writes to the subscriptions table; writes stay in
 * Plandose_Subscriptions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Subscriber_Query {

	/**
	 * Maximum number of subscriber rows returned by one database page.
	 *
	 * The dashboard uses real SQL LIMIT/OFFSET pagination and never loads a
	 * fixed 50,000-user snapshot into memory. Normal admin pages use 20 rows;
	 * background/export callers may request larger batches up to this ceiling.
	 */
	const MAX_SUBSCRIBER_PAGE_SIZE = 1000;

	/**
	 * Backward-compatibility alias for older callers. New code must use
	 * MAX_SUBSCRIBER_PAGE_SIZE and iterate through pages instead of treating
	 * this as a total-row ceiling.
	 *
	 * @deprecated 1.8.7
	 */
	const MAX_SUBSCRIBER_ROWS = self::MAX_SUBSCRIBER_PAGE_SIZE;

	/**
	 * Legacy transient key used by releases that cached every matched user in
	 * one large array. Nothing writes this transient now, but invalidation
	 * still removes it during upgrades.
	 */
	const SUBSCRIBER_CACHE_KEY = 'plandose_subscriber_rows_cache';

	/**
	 * Guards against registering the cache hooks more than once. WordPress
	 * already de-duplicates identical static-method callbacks by unique id, so
	 * this is defensive rather than corrective — it also keeps the class safe
	 * if these callbacks are ever refactored to closures or instance methods,
	 * which would not be de-duplicated.
	 *
	 * @var bool
	 */
	private static $cache_hooks_initialized = false;

	/**
	 * Remove the legacy full-dataset transient and the cached dashboard
	 * KPIs.
	 *
	 * Already called from every write that can move a subscriber figure —
	 * subscription updates, access overrides, row deletions and settings
	 * saves — which is why stats() can cache at all. Prints and monthly
	 * resets do not call it; "prints" may lag by the TTL.
	 */
	public static function invalidate_subscriber_cache() {
		delete_transient( self::SUBSCRIBER_CACHE_KEY );
		delete_transient( self::STATS_CACHE_KEY );
		delete_transient( self::UNCOUNTED_CACHE_KEY );
		delete_transient( self::COUNT_CACHE_KEY );
	}

	/**
	 * Meta keys whose change can move a cached figure.
	 *
	 * Only what the cached counts depend on — membership (the
	 * account_type keys), the manual access override, and the roles
	 * (uncounted_users() leaves administrators out). Names, phones, city
	 * and ΑΦΜ are not listed: nothing cached shows them, and billing_*
	 * updates (e.g. every WooCommerce checkout) would keep dropping the
	 * KPI caches.
	 *
	 * @return string[] Lower-case keys.
	 */
	private static function cache_relevant_meta_keys() {
		global $wpdb;

		return array_map(
			'strtolower',
			array_merge(
				Plandose_Access::account_type_meta_keys(),
				array(
					Plandose_Access::MANUAL_ACCESS_META_KEY,
					$wpdb->get_blog_prefix() . 'capabilities',
				)
			)
		);
	}

	public static function maybe_invalidate_on_meta_change( $meta_id, $user_id, $meta_key ) {
		// MySQL matches meta keys case-insensitively and ignores trailing
		// spaces, so compare the way the database does.
		if ( is_string( $meta_key ) && in_array( strtolower( rtrim( $meta_key ) ), self::cache_relevant_meta_keys(), true ) ) {
			self::invalidate_subscriber_cache();
		}
	}

	public static function invalidate_subscriber_cache_hook() {
		self::invalidate_subscriber_cache();
	}

	public static function init_cache_hooks() {
		if ( self::$cache_hooks_initialized ) {
			return;
		}

		self::$cache_hooks_initialized = true;

		add_action( 'updated_user_meta', array( __CLASS__, 'maybe_invalidate_on_meta_change' ), 10, 3 );
		add_action( 'added_user_meta', array( __CLASS__, 'maybe_invalidate_on_meta_change' ), 10, 3 );
		add_action( 'deleted_user_meta', array( __CLASS__, 'maybe_invalidate_on_meta_change' ), 10, 3 );
		/*
		 * No profile_update: WooCommerce saves the customer through
		 * wp_update_user() on every checkout, which would drop all the caches
		 * each time. Everything the cached figures depend on is already seen
		 * above — the account_type keys, the manual override, and the roles
		 * (WP_User::set_role()/add_role()/remove_role() all rewrite the
		 * capabilities meta, and set_user_role is hooked below). The wp_users
		 * columns a profile save changes (name, email) are never cached.
		 *
		 * user_register stays: a new account is a new uncounted user even
		 * before any meta of ours is written, and registrations are rare.
		 */
		add_action( 'user_register', array( __CLASS__, 'invalidate_subscriber_cache_hook' ) );
		// uncounted_users() caches a head count that excludes
		// administrators, so a role change or a deleted account moves it.
		add_action( 'set_user_role', array( __CLASS__, 'invalidate_subscriber_cache_hook' ) );
		add_action( 'deleted_user', array( __CLASS__, 'invalidate_subscriber_cache_hook' ) );
	}

	/**
	 * Build SQL WHERE clauses for a paginated subscriber query.
	 *
	 * DEFINITION: a "subscriber" is a registered user whose account is a
	 * pharmacist/pharmacy account. That is the population this screen
	 * manages, and it is intentionally NOT the same as "everyone who can
	 * open the tool" (see access_evaluation()). An administrator passes
	 * can_use_tool() whenever allow_admin_preview is on, but is not a
	 * pharmacy and does not belong here; conversely a pharmacy whose role
	 * sits in hidden_roles still belongs in this list — it is an account
	 * the admin manages — even though it currently cannot use the tool.
	 * Each Subscriptions card renders that row's real access state and the
	 * reason for it, so the difference is visible rather than silent.
	 *
	 * Membership mirrors the PHP rules used by is_registered_pharmacist():
	 * an account_type key must identify a pharmacy (exact match on one of
	 * the spellings from Plandose_Access::pharmacy_account_type_sql_variants()).
	 *
	 * A manual "allow" does not add a user to this list. Only accounts
	 * whose business category is «Φαρμακείο» may be PlanDose subscribers
	 * at all, and the override does not grant access to anyone else (see
	 * Plandose_Access::access_evaluation()), so listing such an account
	 * here would count a non-pharmacy as a subscriber. A leftover "allow"
	 * on a non-pharmacy shows up under uncounted_users() instead, where
	 * the admin can clear it.
	 *
	 * @param array $args   Sanitized query arguments.
	 * @param array $params Prepared-statement values, appended by reference.
	 * @return string SQL expression without the leading WHERE keyword.
	 */
	private static function subscriber_where_sql( $args, &$params ) {
		global $wpdb;

		// The membership test and the filters are built by the two
		// helpers below; the list itself uses membership_candidates_sql()
		// instead of this EXISTS form (see query_subscriber_ids()).
		$membership = "EXISTS (
			SELECT 1 FROM {$wpdb->usermeta} ap
			WHERE ap.user_id = u.ID
			  AND " . self::membership_predicate_sql( $params ) . '
		)';

		return '(' . $membership . ') AND ' . self::filters_where_sql( $args, $params );
	}

	/**
	 * The per-meta-row membership test on alias `ap`.
	 *
	 * The accepted spellings come from Plandose_Access, which also defines
	 * what is_registered_pharmacist() accepts — one list, so the front-end
	 * answer and this list cannot disagree about who is a pharmacy. See
	 * pharmacy_account_type_sql_variants() for why the comparison is a list
	 * of spellings rather than a normalization applied in SQL.
	 *
	 * The same keys and forms is_registered_pharmacist() accepts: every
	 * account_type meta key, the plain value, a value with a trailing
	 * no-break space, and a select stored as a serialized array (matched on
	 * the quoted element, so still an exact value).
	 *
	 * @param array $params Prepared-statement values, appended by reference.
	 * @return string
	 */
	private static function membership_predicate_sql( &$params ) {
		global $wpdb;

		$account_type_variants     = Plandose_Access::pharmacy_account_type_sql_variants();
		$account_type_placeholders = implode( ', ', array_fill( 0, count( $account_type_variants ), '%s' ) );
		$account_type_keys         = Plandose_Access::account_type_meta_keys();
		$account_type_key_holders  = implode( ', ', array_fill( 0, count( $account_type_keys ), '%s' ) );
		$serialized_likes          = implode( ' OR ', array_fill( 0, count( $account_type_variants ), 'ap.meta_value LIKE %s' ) );

		foreach ( $account_type_keys as $key ) {
			$params[] = $key;
		}

		$params[] = "\u{00A0}";

		foreach ( $account_type_variants as $variant ) {
			$params[] = $variant;
		}

		foreach ( $account_type_variants as $variant ) {
			$params[] = '%"' . $wpdb->esc_like( $variant ) . '"%';
		}

		return "ap.meta_key IN ({$account_type_key_holders})
			  AND TRIM(ap.meta_value) <> ''
			  AND (
				TRIM(REPLACE(ap.meta_value, %s, ' ')) IN ({$account_type_placeholders})
				OR {$serialized_likes}
			  )";
	}

	/**
	 * The members as a derived table `pm` (one row per user_id).
	 *
	 * Testing EXISTS(...) for EVERY row of wp_users runs TRIM/REPLACE over
	 * their meta — twice per page view (page + count); on a shop with
	 * thousands of customer accounts that dominates the admin screen.
	 * Starting from usermeta restricted by `meta_key IN (...)` uses
	 * core's meta_key index and only ever evaluates the account_type rows.
	 * The per-row test is exactly membership_predicate_sql(), so the result
	 * set is the same.
	 *
	 * @param array $params Prepared-statement values, appended by reference.
	 * @return string
	 */
	private static function membership_candidates_sql( &$params ) {
		global $wpdb;

		return "( SELECT DISTINCT ap.user_id FROM {$wpdb->usermeta} ap WHERE " . self::membership_predicate_sql( $params ) . ' ) pm';
	}

	/**
	 * The filters of the list (status, expiry, invoices, search), on the
	 * aliases u (wp_users) and s (subscriptions, LEFT JOINed).
	 *
	 * A manual deny does not remove a user from the list:
	 * a pharmacy you blocked is still a pharmacy, and each card shows its
	 * real access state.
	 *
	 * @param array $args   Sanitized query arguments.
	 * @param array $params Prepared-statement values, appended by reference.
	 * @return string SQL expression ('1=1' when there is no filter).
	 */
	private static function filters_where_sql( $args, &$params ) {
		global $wpdb;

		$where = array();
		$today = current_time( 'Y-m-d' );

		if ( 'pro' === $args['status'] ) {
			$where[]  = "(s.status = 'pro' AND s.sub_end_date IS NOT NULL AND s.sub_end_date >= %s)";
			$params[] = $today;
		} elseif ( 'free' === $args['status'] ) {
			$where[]  = "(s.user_id IS NULL OR s.status <> 'pro' OR s.sub_end_date IS NULL OR s.sub_end_date < %s)";
			$params[] = $today;
		} elseif ( 'expiring' === $args['status'] ) {
			// Site-local date N days from now, N being the configurable
			// "expiring soon" window.
			$soon     = current_datetime()->modify( '+' . Plandose_Settings::expiring_days() . ' days' )->format( 'Y-m-d' );
			$where[]  = "(s.status = 'pro' AND s.sub_end_date >= %s AND s.sub_end_date <= %s)";
			$params[] = $today;
			$params[] = $soon;
		}

		if ( '' !== $args['expiry_from'] ) {
			$where[]  = 's.sub_end_date >= %s';
			$params[] = $args['expiry_from'];
		}

		if ( '' !== $args['expiry_to'] ) {
			$where[]  = 's.sub_end_date <= %s';
			$params[] = $args['expiry_to'];
		}

		if ( 'has' === $args['invoice_status'] ) {
			$where[] = "(s.invoices IS NOT NULL AND TRIM(s.invoices) NOT IN ('', '[]', 'null'))";
		} elseif ( 'missing' === $args['invoice_status'] ) {
			$where[] = "(s.user_id IS NULL OR s.invoices IS NULL OR TRIM(s.invoices) IN ('', '[]', 'null'))";
		}

		if ( '' !== $args['search'] ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			// The ΑΦΜ keys too, as the search box promises.
			$search_meta_keys        = array_merge( Plandose_Access::PHARMACY_NAME_META_KEYS, Plandose_Access::AFM_META_KEYS );
			$search_key_placeholders = implode( ', ', array_fill( 0, count( $search_meta_keys ), '%s' ) );

			$where[] = "(
				u.display_name LIKE %s
				OR u.user_email LIKE %s
				OR u.user_login LIKE %s
				OR EXISTS (
					SELECT 1 FROM {$wpdb->usermeta} sm
					WHERE sm.user_id = u.ID
					  AND sm.meta_key IN ({$search_key_placeholders})
					  AND sm.meta_value LIKE %s
				)
			)";
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			foreach ( $search_meta_keys as $key ) {
				$params[] = $key;
			}
			$params[] = $like;
		}

		return $where ? implode( ' AND ', $where ) : '1=1';
	}

	/**
	 * Transient holding list totals per filter combination (no
	 * search), so paging through a filter does not COUNT again on every
	 * page. Dropped with the other caches; STATS_CACHE_TTL as backstop.
	 */
	const COUNT_CACHE_KEY = 'plandose_subscriber_count_cache';

	/**
	 * Query only the IDs for one database page and, optionally, the total.
	 *
	 * FROM the member candidates (membership_candidates_sql()), one
	 * row per user by construction, so no DISTINCT on the outer query. The
	 * total is cached per filter set unless a search is active (a search
	 * matches names and emails, which do not invalidate the caches).
	 *
	 * @param array $args       Sanitized query arguments.
	 * @param bool  $with_total Whether to run the COUNT query.
	 * @return array{ids:int[],total:int}
	 */
	private static function query_subscriber_ids( $args, $with_total = true ) {
		global $wpdb;

		$table   = Plandose_Subscriptions::table_name();
		$params  = array();
		$members = self::membership_candidates_sql( $params );
		$where   = self::filters_where_sql( $args, $params );
		$offset  = ( $args['paged'] - 1 ) * $args['per_page'];

		$from = "{$members}
			JOIN {$wpdb->users} u ON u.ID = pm.user_id
			LEFT JOIN {$table} s ON s.user_id = u.ID";

		$sql = "SELECT u.ID
			FROM {$from}
			WHERE {$where}
			ORDER BY u.display_name ASC, u.ID ASC
			LIMIT %d OFFSET %d";

		$page_params   = $params;
		$page_params[] = $args['per_page'];
		$page_params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is passed through $wpdb->prepare() on this line; the only interpolated identifiers are $table ($wpdb->prefix), $wpdb->users and $wpdb->usermeta.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $page_params ) );
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );

		$total = 0;

		if ( $with_total ) {
			$cache_id = '' === $args['search'] ? self::count_cache_id( $args ) : '';
			$cached   = '' !== $cache_id ? get_transient( self::COUNT_CACHE_KEY ) : false;

			if ( is_array( $cached ) && isset( $cached[ $cache_id ] ) ) {
				$total = (int) $cached[ $cache_id ];
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared on this line; same identifiers as above.
				$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} WHERE {$where}", $params ) );
				$total = (int) $count;

				if ( '' !== $cache_id && null !== $count ) {
					$cached = is_array( $cached ) ? $cached : array();

					// A handful of filter sets is all an admin uses; keep
					// the transient small.
					if ( count( $cached ) >= 20 ) {
						$cached = array();
					}

					$cached[ $cache_id ] = $total;
					set_transient( self::COUNT_CACHE_KEY, $cached, self::STATS_CACHE_TTL );
				}
			}
		}

		return array(
			'ids'   => $ids,
			'total' => $total,
		);
	}

	/**
	 * Cache id of a filter set for COUNT_CACHE_KEY. Includes today
	 * and the "expiring" window, which the status filters depend on.
	 *
	 * @param array $args Sanitized query arguments.
	 * @return string
	 */
	private static function count_cache_id( $args ) {
		return md5(
			wp_json_encode(
				array(
					$args['status'],
					$args['invoice_status'],
					$args['expiry_from'],
					$args['expiry_to'],
					current_time( 'Y-m-d' ),
					Plandose_Settings::expiring_days(),
				)
			)
		);
	}

	/**
	 * Load subscription rows for a page in one query.
	 *
	 * @param int[] $user_ids User IDs.
	 * @return array<int,object>
	 */
	private static function subscription_rows_by_user_ids( $user_ids ) {
		global $wpdb;

		$user_ids = array_values( array_filter( array_map( 'absint', (array) $user_ids ) ) );

		if ( empty( $user_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );
		$table        = Plandose_Subscriptions::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$table} is from $wpdb->prefix; {$placeholders} is a generated list of %d placeholders (one per user ID) filled by prepare() from $user_ids, which are absint()'d above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id IN ({$placeholders})",
				$user_ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$mapped = array();

		foreach ( (array) $rows as $row ) {
			$mapped[ (int) $row->user_id ] = $row;
		}

		return $mapped;
	}

	/**
	 * Hydrate one page of user IDs into the structure expected by the admin UI.
	 *
	 * @param int[] $user_ids User IDs in display order.
	 * @return object[]
	 */
	private static function hydrate_subscriber_items( $user_ids ) {
		$user_ids = array_values( array_filter( array_map( 'absint', (array) $user_ids ) ) );

		if ( empty( $user_ids ) ) {
			return array();
		}

		$users = get_users(
			array(
				'include' => $user_ids,
				'orderby' => 'include',
				'fields'  => array( 'ID', 'user_login', 'display_name', 'user_email' ),
			)
		);

		update_meta_cache( 'user', $user_ids );

		$users_by_id = array();
		foreach ( $users as $user ) {
			$users_by_id[ (int) $user->ID ] = $user;
		}

		$subscription_rows = self::subscription_rows_by_user_ids( $user_ids );
		$today             = new DateTimeImmutable( 'today', wp_timezone() );
		$items             = array();

		foreach ( $user_ids as $user_id ) {
			if ( empty( $users_by_id[ $user_id ] ) ) {
				continue;
			}

			$user = $users_by_id[ $user_id ];
			$row  = isset( $subscription_rows[ $user_id ] )
				? $subscription_rows[ $user_id ]
				: Plandose_Subscriptions::default_row_stub( $user_id );

			$pharmacy = Plandose_Access::get_meta_with_fallback( $user_id, Plandose_Access::PHARMACY_NAME_META_KEYS );
			$pharmacy = $pharmacy ? $pharmacy : $user->display_name;

			$days_left = null;
			$end_date  = ! empty( $row->sub_end_date )
				? DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $row->sub_end_date, wp_timezone() )
				: false;

			if ( $end_date instanceof DateTimeImmutable ) {
				$days_left = (int) $today->diff( $end_date )->format( '%r%a' );
			}

			$items[] = (object) array(
				'user'      => (object) array(
					'ID'           => $user->ID,
					'display_name' => $user->display_name,
					'user_email'   => $user->user_email,
					'user_login'   => $user->user_login,
				),
				'row'       => $row,
				'is_pro'    => Plandose_Subscriptions::is_pro_active( $row ),
				'days_left' => $days_left,
				'pharmacy'  => $pharmacy,
				'city'      => Plandose_Access::get_meta_with_fallback(
					$user_id,
					array( 'billing_city', 'user_registration_billing_city', 'city' )
				),
				'mobile'    => Plandose_Access::get_meta_with_fallback(
					$user_id,
					array( 'mobile_phone', 'user_registration_mobile_phone', 'mobile', 'phone_1', 'user_registration_phone_1', 'billing_phone' )
				),
				'afm'       => Plandose_Access::get_meta_with_fallback( $user_id, Plandose_Access::AFM_META_KEYS ),
			);
		}

		return $items;
	}

	/**
	 * Query subscribers using real database pagination.
	 */
	public static function query_subscribers( $args = array() ) {
		$defaults = array(
			'status'         => 'all',
			'search'         => '',
			'expiry_from'    => '',
			'expiry_to'      => '',
			'invoice_status' => 'all',
			'paged'          => 1,
			'per_page'       => 20,
			'with_total'     => true,
		);

		$args = wp_parse_args( $args, $defaults );

		$args['status'] = in_array( $args['status'], array( 'all', 'free', 'pro', 'expiring' ), true )
			? $args['status']
			: 'all';
		$args['invoice_status'] = in_array( $args['invoice_status'], array( 'all', 'has', 'missing' ), true )
			? $args['invoice_status']
			: 'all';
		$args['search']      = trim( sanitize_text_field( (string) $args['search'] ) );
		$args['paged']       = max( 1, (int) $args['paged'] );
		$args['per_page']    = max( 1, min( self::MAX_SUBSCRIBER_PAGE_SIZE, (int) $args['per_page'] ) );
		$args['with_total']  = ! empty( $args['with_total'] );
		$args['expiry_from'] = Plandose_Settings::sanitize_ymd( $args['expiry_from'] );
		$args['expiry_to']   = Plandose_Settings::sanitize_ymd( $args['expiry_to'] );

		$result = self::query_subscriber_ids( $args, $args['with_total'] );

		return array(
			'items' => self::hydrate_subscriber_items( $result['ids'] ),
			'total' => $result['total'],
		);
	}

	/**
	 * Users that are NOT counted as subscribers, and why.
	 *
	 * Answers "WordPress shows 96 subscribers, PlanDose 72 — who are the
	 * other 24?" without anyone opening the database. Administrators are
	 * left out (they are never pharmacies). Each row carries every value
	 * stored under the account_type keys, so a wrong or missing
	 * «Κατηγορία» is visible at a glance; rows are grouped by that value.
	 *
	 * Computed in SQL, not by loading every non-pharmacy user and running
	 * user_can() per user (on a site with 5,000 customer accounts that
	 * costs ~27 MB of memory and most of a quarter-second per page view
	 * for a collapsed box). The total and the per-«Κατηγορία» counts are
	 * one GROUP BY, administrators are excluded in the same statement, and
	 * only the $limit rows actually shown are loaded.
	 *
	 * "Administrator" is decided in SQL from the stored roles: every role
	 * that has manage_options (normally just 'administrator') is matched
	 * in the capabilities meta. That is what user_can( …, 'manage_options' )
	 * answers for ordinary accounts; a capability granted to one user
	 * individually, or by a filter, is not seen here — acceptable
	 * for a diagnostic list whose only job is to explain a head count.
	 *
	 * @param int $limit Maximum users to return in detail.
	 * @return array{total:int, groups:array<string,int>, rows:array}
	 */
	public static function uncounted_users( $limit = 300 ) {
		$limit   = max( 1, min( 1000, (int) $limit ) );
		$summary = self::uncounted_summary();

		if ( $summary['total'] < 1 ) {
			return array(
				'total'  => 0,
				'groups' => array(),
				'rows'   => array(),
			);
		}

		return array(
			'total'  => $summary['total'],
			'groups' => $summary['groups'],
			'rows'   => self::uncounted_rows( self::uncounted_ids( $limit ) ),
		);
	}

	/**
	 * The cheap half of uncounted_users(): the total and the
	 * per-«Κατηγορία» counts, without any per-user detail. This is all the
	 * list screens render up front; the users themselves are loaded only
	 * when the admin opens the box (Plandose_Admin_Subscriptions::ajax_uncounted_users()).
	 *
	 * The aggregate has to look at every user, so it is cached: dropped by
	 * invalidate_subscriber_cache() on any change of an account_type key,
	 * the roles, a new user or a deletion, with a short TTL as backstop.
	 *
	 * @return array{total:int, groups:array<string,int>}
	 */
	public static function uncounted_summary() {
		$cached = self::uncounted_cache();

		if ( $cached ) {
			return array(
				'total'  => $cached['total'],
				'groups' => $cached['groups'],
			);
		}

		list( $base_where, $base_params ) = self::uncounted_base_sql();
		list( $total, $groups )           = self::uncounted_groups( $base_where, $base_params );

		set_transient(
			self::UNCOUNTED_CACHE_KEY,
			array(
				'total'  => $total,
				'groups' => $groups,
			),
			self::STATS_CACHE_TTL
		);

		return array(
			'total'  => $total,
			'groups' => $groups,
		);
	}

	/**
	 * IDs of the uncounted users, newest first, at most $limit.
	 *
	 * Stored in the same transient as the counts, so both are dropped
	 * together and an open box repeats the query only after a change that
	 * could alter the list. IDs only — the names and emails are re-read on
	 * every view by uncounted_rows().
	 *
	 * @param int $limit Maximum IDs.
	 * @return int[]
	 */
	public static function uncounted_ids( $limit = 300 ) {
		global $wpdb;

		$limit  = max( 1, min( 1000, (int) $limit ) );
		$cached = self::uncounted_cache();

		// A cached list answers any limit it covers: either it was fetched
		// with at least this limit, or it already holds every uncounted user.
		if ( $cached && isset( $cached['ids'], $cached['ids_limit'] ) && ( $cached['ids_limit'] >= $limit || count( $cached['ids'] ) >= $cached['total'] ) ) {
			return array_slice( $cached['ids'], 0, $limit );
		}

		list( $base_where, $base_params ) = self::uncounted_base_sql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prepared on this line; $base_where comes from subscriber_where_sql() plus a fixed admin clause, every value bound. Only core table names are interpolated.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT u.ID FROM {$wpdb->users} u WHERE {$base_where} ORDER BY u.ID DESC LIMIT %d", array_merge( $base_params, array( $limit ) ) ) );
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );

		// Attach to the counts only when they are still cached, so the two
		// always describe the same moment; a failed query is not stored.
		if ( $cached && '' === $wpdb->last_error ) {
			$cached['ids']       = $ids;
			$cached['ids_limit'] = $limit;
			set_transient( self::UNCOUNTED_CACHE_KEY, $cached, self::STATS_CACHE_TTL );
		}

		return $ids;
	}

	/**
	 * The detail rows of uncounted_users() for the given IDs.
	 *
	 * @param int[] $ids User IDs, in display order.
	 * @return array
	 */
	public static function uncounted_rows( $ids ) {
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );

		// Only the rows actually displayed are loaded: one users query and
		// one meta query for these accounts.
		if ( $ids ) {
			cache_users( $ids );
		}

		$rows = array();

		foreach ( $ids as $user_id ) {
			$user = get_userdata( $user_id );

			if ( ! $user ) {
				continue;
			}

			$values = array();

			foreach ( Plandose_Access::account_type_meta_keys() as $key ) {
				$raw = get_user_meta( $user_id, $key, true );

				if ( '' !== $raw && null !== $raw && array() !== $raw ) {
					$values[ $key ] = is_array( $raw ) ? implode( ', ', array_map( 'strval', $raw ) ) : (string) $raw;
				}
			}

			$rows[] = array(
				'id'     => $user_id,
				'name'   => $user->display_name,
				'email'  => $user->user_email,
				'roles'  => implode( ', ', (array) $user->roles ),
				'values' => $values,
				'manual' => Plandose_Access::get_manual_access( $user_id ),
			);
		}

		return $rows;
	}

	/**
	 * The valid UNCOUNTED_CACHE_KEY entry, or null.
	 *
	 * @return array|null
	 */
	private static function uncounted_cache() {
		$cached = get_transient( self::UNCOUNTED_CACHE_KEY );

		if ( ! is_array( $cached ) || ! isset( $cached['total'], $cached['groups'] ) || ! is_array( $cached['groups'] ) ) {
			return null;
		}

		$cached['total'] = (int) $cached['total'];

		if ( isset( $cached['ids'] ) || isset( $cached['ids_limit'] ) ) {
			if ( ! isset( $cached['ids'], $cached['ids_limit'] ) || ! is_array( $cached['ids'] ) ) {
				unset( $cached['ids'], $cached['ids_limit'] );
			} else {
				$cached['ids']       = array_values( array_filter( array_map( 'absint', $cached['ids'] ) ) );
				$cached['ids_limit'] = (int) $cached['ids_limit'];
			}
		}

		return $cached;
	}

	/**
	 * WHERE expression (alias u = wp_users) selecting the uncounted users:
	 * not a subscriber and not an administrator.
	 *
	 * "Administrator" is decided in SQL from the stored roles: every role
	 * that has manage_options is matched in the capabilities meta. See
	 * uncounted_users().
	 *
	 * @return array{0:string,1:array} SQL and its bound values.
	 */
	private static function uncounted_base_sql() {
		global $wpdb;

		$params = array();
		$where  = self::subscriber_where_sql(
			array(
				'status'         => 'all',
				'search'         => '',
				'expiry_from'    => '',
				'expiry_to'      => '',
				'invoice_status' => 'all',
			),
			$params
		);

		/*
		 * Roles that carry manage_options, as LIKE patterns on the
		 * serialized capabilities array (a:1:{s:13:"administrator";b:1;}).
		 * The quoted role name cannot match a longer role name by accident.
		 */
		$admin_likes = array();

		foreach ( wp_roles()->roles as $role_key => $role ) {
			if ( ! empty( $role['capabilities']['manage_options'] ) ) {
				$admin_likes[] = '%' . $wpdb->esc_like( '"' . $role_key . '"' ) . '%';
			}
		}

		$not_admin_sql    = '1=1';
		$not_admin_params = array();

		if ( $admin_likes ) {
			$not_admin_sql = "NOT EXISTS (
				SELECT 1 FROM {$wpdb->usermeta} cap
				WHERE cap.user_id = u.ID
				  AND cap.meta_key = %s
				  AND ( " . implode( ' OR ', array_fill( 0, count( $admin_likes ), 'cap.meta_value LIKE %s' ) ) . ' )
			)';
			$not_admin_params = array_merge( array( $wpdb->get_blog_prefix() . 'capabilities' ), $admin_likes );
		}

		return array(
			"NOT ( {$where} ) AND {$not_admin_sql}",
			array_merge( $params, $not_admin_params ),
		);
	}

	/**
	 * Transient holding uncounted_users()' total and per-«Κατηγορία»
	 * counts and, once the details have been opened, the IDs
	 * shown (uncounted_ids()). Never names or emails.
	 */
	const UNCOUNTED_CACHE_KEY = 'plandose_uncounted_users';

	/**
	 * The GROUP BY half of uncounted_users().
	 *
	 * @param string $base_where WHERE expression selecting uncounted users.
	 * @param array  $base_params Values for $base_where's placeholders.
	 * @return array{0:int,1:array<string,int>} Total and label => users.
	 */
	private static function uncounted_groups( $base_where, $base_params ) {
		global $wpdb;

		$type_keys        = Plandose_Access::account_type_meta_keys();
		$type_key_holders = implode( ', ', array_fill( 0, count( $type_keys ), '%s' ) );

		/*
		 * One label per user — every non-empty value stored under the
		 * account_type keys, de-duplicated — then one row per label. The
		 * label subquery's placeholders come first in the statement, so
		 * the key list goes at the front of the parameter list.
		 */
		$group_sql = "SELECT label, COUNT(*) AS users FROM (
				SELECT u.ID, COALESCE( GROUP_CONCAT( DISTINCT TRIM( m.meta_value ) ORDER BY TRIM( m.meta_value ) SEPARATOR ' / ' ), '' ) AS label
				FROM {$wpdb->users} u
				LEFT JOIN {$wpdb->usermeta} m
				  ON m.user_id = u.ID
				 AND m.meta_key IN ( {$type_key_holders} )
				 AND TRIM( m.meta_value ) <> ''
				WHERE {$base_where}
				GROUP BY u.ID
			) t
			GROUP BY label
			ORDER BY users DESC, label ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $group_sql is passed through prepare() here; $where comes from subscriber_where_sql() and every value is bound. Only core table names are interpolated.
		$group_rows = $wpdb->get_results( $wpdb->prepare( $group_sql, array_merge( $type_keys, $base_params ) ) );

		$groups = array();
		$total  = 0;

		foreach ( (array) $group_rows as $group_row ) {
			$label = (string) $group_row->label;

			// A select stored as a serialized array reads as its values,
			// the way uncounted_users()' detail rows render it.
			if ( is_serialized( $label ) ) {
				$decoded = maybe_unserialize( $label );
				$label   = is_array( $decoded ) ? implode( ', ', array_map( 'strval', array_filter( $decoded, 'is_scalar' ) ) ) : $label;
			}

			$groups[ $label ] = ( isset( $groups[ $label ] ) ? $groups[ $label ] : 0 ) + (int) $group_row->users;
			$total           += (int) $group_row->users;
		}

		arsort( $groups );

		return array( $total, $groups );
	}

	/**
	 * Diagnostic dump for account type meta keys.
	 */
	public static function debug_account_types( $limit = 50 ) {
		$limit = max( 1, min( 500, (int) $limit ) );

		$users = get_users(
			array(
				'number'  => $limit,
				// 'roles' is not a wp_users column; full WP_User
				// objects carry roles, and all_with_meta primes the meta
				// cache so the loop below adds no per-user queries.
				'fields'  => 'all_with_meta',
				'orderby' => 'ID',
				'order'   => 'DESC',
			)
		);

		$rows = array();

		foreach ( $users as $user ) {
			$values = array();

			foreach ( Plandose_Access::account_type_meta_keys() as $key ) {
				$raw = get_user_meta( $user->ID, $key, true );

				if ( '' !== $raw && null !== $raw ) {
					$values[ $key ] = is_array( $raw ) ? wp_json_encode( $raw ) : $raw;
				}
			}

			$rows[] = array(
				'id'      => $user->ID,
				'name'    => $user->display_name,
				'email'   => $user->user_email,
				'roles'   => implode( ', ', (array) $user->roles ),
				'values'  => $values,
				'matches' => Plandose_Access::is_registered_pharmacist( $user->ID ),
			);
		}

		return $rows;
	}

	/**
	 * Transient holding the last computed dashboard KPIs.
	 *
	 * Dropped by invalidate_subscriber_cache() on every write that could
	 * move membership (subscription changes, access overrides, settings
	 * saves, row deletions). A charged print drops it too
	 * (Plandose_Ajax::register_print()), so «Εκτυπώσεις Μήνα» is current;
	 * the expensive list caches are left alone on a print.
	 */
	const STATS_CACHE_KEY = 'plandose_subscriber_stats';
	const STATS_CACHE_TTL = 300;

	/**
	 * Admin dashboard KPIs, as one aggregate query.
	 *
	 * Not a loop over hydrated subscribers: hydrate_subscriber_items()
	 * skips any ID whose user row get_users() did not return, so a batch
	 * can come back short and stop such a count early, and the KPIs would
	 * read low with nothing to indicate it — besides costing a full
	 * hydration of every subscriber on each of the four KPI screens.
	 *
	 * Conditional aggregation over the same FROM the list itself uses gives
	 * every figure in one pass, and the "expiring" definition is literally
	 * the same SQL the corresponding filter uses.
	 *
	 * The single row per user that SUM() depends on is guaranteed by the
	 * shape of the query: the member candidates are DISTINCT user_ids, and
	 * u and s are joined on their primary keys.
	 *
	 * @return array{total:int,free:int,pro:int,expiring:int,prints:int,month:string}
	 */
	public static function stats() {
		global $wpdb;

		list( $month_start, $next_month ) = Plandose_Subscriptions::current_month_bounds();

		$cached = get_transient( self::STATS_CACHE_KEY );

		// A cached set from the previous month is discarded even
		// inside its TTL — nothing invalidates the cache at midnight on
		// the 1st, and "prints" is a this-month figure.
		if ( is_array( $cached ) && isset( $cached['total'], $cached['prints'], $cached['month'] ) && $month_start === $cached['month'] ) {
			return $cached;
		}

		$params  = array();
		$members = self::membership_candidates_sql( $params );
		$table   = Plandose_Subscriptions::table_name();

		$today = current_time( 'Y-m-d' );
		$soon  = current_datetime()->modify( '+' . Plandose_Settings::expiring_days() . ' days' )->format( 'Y-m-d' );

		// FROM the member candidates like the list (one row per
		// user), so plain COUNT/SUM.
		$sql = "SELECT
				COUNT(*) AS total,
				COALESCE( SUM( CASE WHEN s.status = 'pro' AND s.sub_end_date IS NOT NULL AND s.sub_end_date >= %s THEN 1 ELSE 0 END ), 0 ) AS pro,
				COALESCE( SUM( CASE WHEN s.status = 'pro' AND s.sub_end_date IS NOT NULL AND s.sub_end_date >= %s AND s.sub_end_date <= %s THEN 1 ELSE 0 END ), 0 ) AS expiring,
				COALESCE( SUM( CASE WHEN s.count_reset_at >= %s AND s.count_reset_at < %s THEN s.print_count ELSE 0 END ), 0 ) AS prints
			FROM {$members}
			JOIN {$wpdb->users} u ON u.ID = pm.user_id
			LEFT JOIN {$table} s ON s.user_id = u.ID";

		/*
		 * "prints" sums only counters that belong to the running
		 * month — the SQL twin of Plandose_Subscriptions::current_month_count().
		 * A plain SUM( print_count ) would add last month's totals of every
		 * pharmacy the monthly reset has not reached yet, so on the 1st
		 * the KPI would show roughly the whole previous month as "this month".
		 */

		// The aggregate placeholders come first in the statement, so their
		// values go at the front of the list prepare() fills from.
		$stats_params = array_merge( array( $today, $today, $soon, $month_start, $next_month ), $params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is passed through $wpdb->prepare() on this line; the only interpolated identifiers are $table ($wpdb->prefix), $wpdb->users and $wpdb->usermeta, and $members is built by membership_candidates_sql() with every value bound through $params.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $stats_params ) );

		$total = $row ? (int) $row->total : 0;
		$pro   = $row ? (int) $row->pro : 0;

		$stats = array(
			'total'    => $total,
			'free'     => max( 0, $total - $pro ),
			'pro'      => $pro,
			'expiring' => $row ? (int) $row->expiring : 0,
			'prints'   => $row ? (int) $row->prints : 0,
			'month'    => $month_start,
		);

		// A failed query is not cached — otherwise its zeros would be kept
		// for 5 minutes and the dashboard would show «0 συνδρομητές» with
		// no sign of an error.
		if ( $row && '' === $wpdb->last_error ) {
			set_transient( self::STATS_CACHE_KEY, $stats, self::STATS_CACHE_TTL );
		}

		return $stats;
	}
}