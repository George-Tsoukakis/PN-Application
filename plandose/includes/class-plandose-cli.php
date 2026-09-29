<?php
/**
 * WP-CLI diagnostics: `wp plandose check`.
 *
 * Answers the two questions
 * that cannot be answered from the code alone, because they depend on how THIS server is configured:
 *
 *   1. Are the uploaded invoices reachable from the internet?
 *   2. Can a page built for a logged-in pharmacy be served to somebody else
 *      out of a shared cache (Nginx FastCGI cache, WP Rocket, Bunny)?
 *
 * Both are checked by making real HTTP requests to the site and looking at
 * what comes back — the presence of a rule or a setting is never taken as
 * proof on its own. The cache check signs in as a real account and then asks
 * for the same page anonymously, which is the only way to see an actual leak.
 *
 * Three things that make that safe to run on a live site:
 *
 * - The session it signs in with is created explicitly, used for the two
 *   requests, and destroyed again in a `finally` — wp_generate_auth_cookie()
 *   with no token would otherwise leave a usable five-minute session in that
 *   pharmacy's user meta after every run.
 * - Both requests go to a URL carrying a unique query parameter, so that on a
 *   site that really is leaking, the copy a cache stores is one nobody else
 *   will ever request. Without it the diagnostic would warm the cache with a
 *   logged-in page — creating the very leak it is looking for. --exact-url
 *   tests the plain address instead, for caches that skip URLs with a query.
 * - The requests carry no Cache-Control or Pragma header, because several
 *   stacks treat those as "bypass the cache" and would hide the leak.
 *
 * Apart from the session it cleans up after itself, the only thing it writes
 * is one random canary file in an existing invoice folder, requested over
 * HTTP and deleted again in a `finally` (Plandose_Invoice_Storage::
 * probe_privacy(), the same probe that gates uploads in production). No
 * option, no database row, no folder is created.
 *
 * The environment checks (cron, database, caching plugins, versions) are
 * shared with PlanDose → Διαγνωστικά (Plandose_Diagnostics).
 *
 * Exit status: 0 when nothing failed, 1 when any check reports FAIL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class Plandose_CLI {

	const PASS = 'PASS';
	const WARN = 'WARN';
	const FAIL = 'FAIL';

	/**
	 * Headers that tell us whether a shared cache served the response, in the
	 * order we prefer to report them. The value is the layer's name.
	 */
	const CACHE_HEADERS = array(
		'x-fastcgi-cache'             => 'Nginx FastCGI cache',
		'x-proxy-cache'               => 'Nginx proxy cache',
		'x-nginx-cache'               => 'Nginx cache',
		'cdn-cache'                   => 'Bunny CDN',
		'x-cache'                     => 'CDN/proxy',
		'x-cache-status'              => 'Nginx cache status',
		'cf-cache-status'             => 'Cloudflare',
		'x-litespeed-cache'           => 'LiteSpeed cache',
		'x-rocket-nginx-serving-role' => 'Rocket-Nginx',
	);

	/**
	 * Said after every PASS that rests on an HTTP request made from the
	 * server itself: such a request often resolves to the origin and never
	 * reaches a CDN sitting in front of it.
	 */
	const LOOPBACK_NOTE = 'Προσοχή: το αίτημα έγινε από τον ίδιο τον server. Αν υπάρχει CDN (π.χ. Bunny) μπροστά, μπορεί να μην πέρασε από αυτό — επαναλάβετε τον έλεγχο και από εξωτερικό δίκτυο.';

	/**
	 * Cache statuses that mean "this response came out of a shared cache".
	 * 'cached' is what Rocket-Nginx writes in x-rocket-nginx-serving-role.
	 */
	const HIT_VALUES = array( 'hit', 'stale', 'updating', 'revalidated', 'cached' );

	/**
	 * Every value $upstream_cache_status can take, plus the ones CDNs use.
	 * A header whose NAME mentions cache and whose VALUE is one of these is a
	 * cache-status header, whatever it is called (e.g. an Nginx that writes
	 * its status into X-PN-FastCGI-Cache), so the check can tell whether the
	 * address was cached at all.
	 */
	const STATUS_VALUES = array( 'hit', 'miss', 'bypass', 'expired', 'stale', 'updating', 'revalidated', 'cached', 'dynamic', 'none' );

	/**
	 * Run the diagnostics.
	 *
	 * ## OPTIONS
	 *
	 * [--as=<user>]
	 * : Login or ID of the account used for the logged-in cache test. Defaults
	 *   to a pharmacy account with PlanDose access. An administrator, or an
	 *   account without PlanDose access, is refused unless --force is given.
	 *
	 * [--force]
	 * : Allow --as to name an administrator or an account without access.
	 *
	 * [--url-path=<path>]
	 * : Site-relative path to test for cache leaks. Defaults to the front page.
	 *
	 * [--exact-url]
	 * : Test the address itself instead of a unique one. This is the conclusive
	 *   test, because many caches skip URLs carrying a query string — but on a
	 *   leaking site it may store the test account's page in the shared cache
	 *   until its TTL expires, so clear the cache afterwards.
	 *
	 * [--cache-header=<name>]
	 * : Name of a custom cache-status header this server sends (e.g.
	 *   X-PN-FastCGI-Cache). Headers whose name mentions "cache" and whose value
	 *   reads like a status are recognised automatically; this is for the rest.
	 *
	 * [--docroot=<path>]
	 * : The web server's document root. WP-CLI has no DOCUMENT_ROOT, so without
	 *   it a folder that has no WordPress URL cannot be confirmed outside the
	 *   web root and is reported as WARN.
	 *
	 * [--canary]
	 * : Accepted for compatibility. The invoice check always writes one
	 *   temporary canary file into an existing invoice folder and deletes it.
	 *
	 * [--skip-invoices]
	 * : Skip the invoice checks.
	 *
	 * [--skip-cache]
	 * : Skip the cache checks.
	 *
	 * [--yes]
	 * : Do not ask before the cache check signs in as a real pharmacy
	 *   account (no --as) or tests the exact address (--exact-url).
	 *
	 * ## EXAMPLES
	 *
	 *     wp plandose check
	 *     wp plandose check --docroot=/var/www/html
	 *     wp plandose check --exact-url
	 *     wp plandose check --as=pharmacy_login --url-path=/
	 *
	 * @when after_wp_load
	 */
	public function check( $args, $assoc_args ) {
		$results = array();

		if ( ! class_exists( 'Plandose_Diagnostics' ) ) {
			WP_CLI::error( 'Η κλάση Plandose_Diagnostics δεν φορτώθηκε — ανεβάστε ξανά ολόκληρο το plugin.' );
		}

		if ( isset( $assoc_args['docroot'] ) && class_exists( 'Plandose_Invoice_Storage' ) ) {
			Plandose_Invoice_Storage::set_document_root_override( (string) $assoc_args['docroot'] );
		}

		// Not "read-only" in the strict sense — see below.
		WP_CLI::line( 'PlanDose ' . PLANDOSE_VERSION . ' — διαγνωστικός έλεγχος' );
		// What it does write, said exactly: it changes no settings, but
		// --exact-url can store a page in the cache.
		WP_CLI::line( 'Δεν αλλάζει ρυθμίσεις. Γράφει ένα προσωρινό δοκιμαστικό αρχείο στον φάκελο τιμολογίων (σβήνεται αμέσως), ανοίγει μια πεντάλεπτη συνεδρία δοκιμής σε λογαριασμό φαρμακείου (καταργείται στο τέλος, καταγράφεται στο audit log) και, με --exact-url, μπορεί να αφήσει σελίδα στην cache.' );
		WP_CLI::line( 'Site: ' . home_url( '/' ) );
		WP_CLI::line( '' );

		$sections = array(
			'Βάση δεδομένων'  => array_merge( Plandose_Diagnostics::check_database(), method_exists( 'Plandose_Diagnostics', 'check_history' ) ? array( Plandose_Diagnostics::check_history() ) : array() ),
			'Περιβάλλον'      => array_merge( Plandose_Diagnostics::check_cron(), Plandose_Diagnostics::check_environment(), array( Plandose_Diagnostics::check_object_cache() ) ),
		);

		if ( empty( $assoc_args['skip-invoices'] ) ) {
			$sections['Τιμολόγια'] = self::check_invoices();
		}

		foreach ( $sections as $title => $section_results ) {
			WP_CLI::line( '== ' . $title . ' ==' );

			foreach ( $section_results as $result ) {
				self::report( $result );
				$results[] = $result;
			}

			WP_CLI::line( '' );
		}

		if ( empty( $assoc_args['skip-cache'] ) ) {
			WP_CLI::line( '== Cache σελίδων συνδεδεμένων χρηστών ==' );
			$as    = isset( $assoc_args['as'] ) ? (string) $assoc_args['as'] : '';
			$path  = isset( $assoc_args['url-path'] ) ? (string) $assoc_args['url-path'] : '/';
			$exact = ! empty( $assoc_args['exact-url'] );
			$extra = isset( $assoc_args['cache-header'] ) ? (string) $assoc_args['cache-header'] : '';
			$force = ! empty( $assoc_args['force'] );

			// Ask before the two steps that touch real accounts or
			// the shared cache (--yes answers in advance). Without a
			// terminal to ask on (cron, CI, monitoring) the cache check is
			// skipped with a WARN instead: WP_CLI::confirm() would read an
			// empty answer and end the command with exit code 0, hiding any
			// FAIL already found.
			$questions = array();

			if ( '' === $as ) {
				$questions[] = 'Χωρίς --as, ο έλεγχος θα ανοίξει προσωρινή συνεδρία στο όνομα του πρώτου λογαριασμού φαρμακείου με πρόσβαση. Συνέχεια;';
			}

			if ( $exact ) {
				$questions[] = 'Με --exact-url, σε site που διαρρέει, η σελίδα του λογαριασμού δοκιμής μπορεί να μείνει στην κοινόχρηστη cache μέχρι να λήξει. Θα χρειαστεί καθαρισμός cache μετά. Συνέχεια;';
			}

			$cache_results = array();

			if ( $questions && empty( $assoc_args['yes'] ) && ! self::interactive() ) {
				$cache_results[] = self::result(
					self::WARN,
					'Έλεγχος με συνδεδεμένο λογαριασμό',
					'Δεν έγινε: χρειάζεται επιβεβαίωση και δεν υπάρχει τερματικό.',
					array( 'Τρέξτε: wp plandose check --yes (ή --as=<login φαρμακείου>)' )
				);
			} else {
				foreach ( $questions as $question ) {
					WP_CLI::confirm( $question, $assoc_args );
				}

				$cache_results = self::check_cache( $as, $path, $exact, $extra, $force );
			}

			foreach ( $cache_results as $result ) {
				self::report( $result );
				$results[] = $result;
			}
			WP_CLI::line( '' );
		}

		$counts = array(
			self::PASS => 0,
			self::WARN => 0,
			self::FAIL => 0,
		);

		foreach ( $results as $result ) {
			++$counts[ $result['status'] ];
		}

		WP_CLI::line( sprintf( 'Σύνολο: %d PASS, %d WARN, %d FAIL', $counts[ self::PASS ], $counts[ self::WARN ], $counts[ self::FAIL ] ) );

		if ( $counts[ self::FAIL ] > 0 ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Whether a person can answer a question on STDIN.
	 *
	 * @return bool
	 */
	private static function interactive() {
		if ( ! defined( 'STDIN' ) ) {
			return false;
		}

		if ( function_exists( 'stream_isatty' ) ) {
			return stream_isatty( STDIN );
		}

		return function_exists( 'posix_isatty' ) && posix_isatty( STDIN );
	}

	/**
	 * Print one result: status, what was checked, and what to do about it.
	 *
	 * @param array $result { status, title, message, hint?, details[] }
	 */
	private static function report( $result ) {
		$colours = array(
			self::PASS => '%G',
			self::WARN => '%Y',
			self::FAIL => '%R',
		);

		WP_CLI::line( WP_CLI::colorize( $colours[ $result['status'] ] . $result['status'] . '%n' ) . '  ' . $result['title'] );
		WP_CLI::line( '      ' . $result['message'] );

		if ( ! empty( $result['hint'] ) ) {
			WP_CLI::line( '      → ' . $result['hint'] );
		}

		foreach ( (array) $result['details'] as $detail ) {
			WP_CLI::line( '      · ' . $detail );
		}
	}

	private static function result( $status, $title, $message, $details = array() ) {
		return array(
			'status'  => $status,
			'title'   => $title,
			'message' => $message,
			'hint'    => '',
			'details' => (array) $details,
		);
	}

	/* --------------------------------------------------------------------
	 * Invoices
	 * ------------------------------------------------------------------ */

	/**
	 * Where the invoices are, and whether the web server hands them out.
	 *
	 * Always a live anonymous HTTP probe with a canary file
	 * (Plandose_Invoice_Storage::probe_privacy()), never a PASS on the path
	 * alone; a folder without a URL is PASS only when DOCUMENT_ROOT (or
	 * --docroot) is known and the folder is outside it. The verdict is not
	 * stored: the site's own cache is written only by wp-admin checks.
	 *
	 * @param bool $canary Ignored (kept for callers of the old signature).
	 * @return array<int,array> Results.
	 */
	public static function check_invoices( $canary = false ) {
		unset( $canary );

		return Plandose_Diagnostics::check_storage(
			array(
				'live'    => true,
				'persist' => false,
			)
		);
	}

	/**
	 * Public URL for a path inside the web tree, or '' when it has none.
	 *
	 * @param string $path Absolute path.
	 * @return string URL without a trailing slash.
	 */
	public static function path_to_url( $path ) {
		return Plandose_Invoice_Storage::path_to_url( $path );
	}

	/* --------------------------------------------------------------------
	 * Cache
	 * ------------------------------------------------------------------ */

	/**
	 * Can a page built for a logged-in pharmacy reach anybody else?
	 *
	 * One request as the account, one as a guest, a comparison of what came
	 * back, and — in the default (unique URL) mode — a second guest request
	 * that establishes whether this address is cached at all. Plus the
	 * settings of the caching layers in use.
	 *
	 * @param string $as_user   Login or ID to sign in as ('' = pick a pharmacy).
	 * @param string $path      Site-relative path to request.
	 * @param bool   $exact_url    Test the address itself instead of a unique one.
	 * @param string $cache_header Custom cache-status header name, if any.
	 * @param bool   $force        Allow --as to name an admin / account without access.
	 * @return array<int,array> Results.
	 */
	public static function check_cache( $as_user = '', $path = '/', $exact_url = false, $cache_header = '', $force = false ) {
		$results = self::check_cache_settings();

		if ( ! class_exists( 'Plandose_Access' ) ) {
			$results[] = self::result( self::FAIL, 'Έλεγχος με συνδεδεμένο λογαριασμό', 'Η κλάση πρόσβασης δεν φορτώθηκε.' );

			return $results;
		}

		$user = self::pick_cache_test_user( $as_user, $force );

		if ( ! $user ) {
			$results[] = self::result(
				self::WARN,
				'Έλεγχος με συνδεδεμένο λογαριασμό',
				'Δεν βρέθηκε λογαριασμός φαρμακείου για τη δοκιμή, οπότε ο πραγματικός έλεγχος διαρροής δεν έγινε.',
				array( 'Τρέξτε: wp plandose check --as=<login φαρμακείου>' )
			);

			return $results;
		}

		// Signing in as a customer's account is recorded.
		if ( class_exists( 'Plandose_Admin' ) && method_exists( 'Plandose_Admin', 'audit' ) ) {
			Plandose_Admin::audit(
				'cli_cache_check',
				(int) $user->ID,
				array(
					'exact_url' => $exact_url ? 1 : 0,
					'path'      => (string) $path,
				)
			);
		}

		$url = home_url( '/' === $path ? '/' : $path );

		/*
		 * By default both requests carry a unique parameter, so that a cache
		 * which stores the answer stores it under a key nobody else will ask
		 * for. On a leaking site the plain address would be warmed with the
		 * test account's page and served to real visitors until its TTL ran
		 * out — a diagnostic must not create the problem it looks for.
		 */
		$probe_url = $exact_url
			? $url
			: add_query_arg( 'plandose-check', wp_generate_password( 10, false, false ), $url );

		if ( ! self::is_same_host( $probe_url ) ) {
			$results[] = self::result(
				self::FAIL,
				'Έλεγχος με συνδεδεμένο λογαριασμό',
				'Η διεύθυνση προς έλεγχο δεν ανήκει σε αυτό το site, οπότε δεν στάλθηκε κανένα cookie σύνδεσης.',
				array( 'Διεύθυνση: ' . $probe_url, 'Site: ' . home_url( '/' ) )
			);

			return $results;
		}

		$session     = self::open_test_session( $user );
		$guest_again = null;

		try {
			if ( '' === $session['cookie'] ) {
				$results[] = self::result(
					self::WARN,
					'Έλεγχος με συνδεδεμένο λογαριασμό',
					'Δεν ήταν δυνατή η δημιουργία προσωρινής συνεδρίας για τον λογαριασμό δοκιμής.',
					array( 'Λογαριασμός: ' . $user->user_login )
				);

				return $results;
			}

			$logged_in = self::http_get( $probe_url, self::session_cookies( $session['cookie'] ) );
			$guest     = self::http_get( $probe_url );

			/*
			 * A third request, as a guest again. It answers the question
			 * every "no leak" verdict depends on: is this address cached at
			 * all? Request #2 already claimed the anonymous key, so a HIT
			 * here means the cache IS storing this address — and only then
			 * does "the visitor got a guest page" prove anything. Without
			 * it, a stack that skips this URL (many skip query strings, and
			 * some skip the front page) would look exactly like a correctly
			 * configured one.
			 */
			if ( ! is_wp_error( $guest ) ) {
				$guest_again = self::http_get( $probe_url );
			}
		} finally {
			// The five-minute session exists only for the requests above.
			self::close_test_session( $user, $session['token'] );
		}

		if ( is_wp_error( $logged_in ) || is_wp_error( $guest ) ) {
			$error = is_wp_error( $logged_in ) ? $logged_in : $guest;

			$results[] = self::result(
				self::WARN,
				'Έλεγχος με συνδεδεμένο λογαριασμό',
				'Το αίτημα προς το site απέτυχε, οπότε ο έλεγχος δεν ολοκληρώθηκε: ' . $error->get_error_message(),
				array(
					'Διεύθυνση: ' . $probe_url,
					'Αν ισχύει WP_HTTP_BLOCK_EXTERNAL, προσθέστε τον δικό σας host στο WP_ACCESSIBLE_HOSTS.',
				)
			);

			return $results;
		}

		$code = (int) wp_remote_retrieve_response_code( $logged_in );

		if ( 200 !== $code ) {
			$results[] = self::result(
				self::WARN,
				'Έλεγχος με συνδεδεμένο λογαριασμό',
				'Η σελίδα δεν απάντησε κανονικά (HTTP ' . $code . '), οπότε ο έλεγχος δεν αποδεικνύει τίποτα.',
				array( 'Διεύθυνση: ' . $probe_url, 'Δοκιμάστε άλλη σελίδα με --url-path=<path>.' )
			);

			return $results;
		}

		$guest_code = (int) wp_remote_retrieve_response_code( $guest );

		if ( 200 !== $guest_code ) {
			$results[] = self::result(
				self::WARN,
				'Έλεγχος με συνδεδεμένο λογαριασμό',
				'Η σελίδα του επισκέπτη δεν απάντησε κανονικά (HTTP ' . $guest_code . '), οπότε η σύγκριση δεν αποδεικνύει τίποτα.',
				array( 'Διεύθυνση: ' . $probe_url )
			);

			return $results;
		}

		$logged_body  = (string) wp_remote_retrieve_body( $logged_in );
		$guest_body   = (string) wp_remote_retrieve_body( $guest );
		$logged_nonce = self::extract_nonce( $logged_body );
		$guest_nonce  = self::extract_nonce( $guest_body );
		$common       = array(
			'Λογαριασμός δοκιμής: ' . $user->user_login,
			'Διεύθυνση: ' . $probe_url,
			$exact_url
				? 'Έλεγχος στην ίδια τη διεύθυνση (--exact-url).'
				: 'Ο έλεγχος χρησιμοποιεί μοναδική διεύθυνση, ώστε να μη γεμίσει η cache με τη σελίδα του λογαριασμού δοκιμής. Αν η cache σας αγνοεί διευθύνσεις με παραμέτρους, τρέξτε ξανά με --exact-url.',
		);

		if ( '' !== $guest_nonce ) {
			/*
			 * A visitor must never receive the tool's configuration at all:
			 * it is only printed for an account with access. Matching the
			 * test account's own nonce would miss a cache holding ANOTHER
			 * pharmacist's page, which is the same leak.
			 */
			$results[] = self::result(
				self::FAIL,
				'Διαρροή σελίδας συνδεδεμένου χρήστη',
				$guest_nonce === $logged_nonce
					? 'ΔΙΑΡΡΟΗ: η σελίδα που πήρε ο επισκέπτης περιέχει το προσωπικό αναγνωριστικό (nonce) του συνδεδεμένου λογαριασμού δοκιμής.'
					: 'ΔΙΑΡΡΟΗ: ο επισκέπτης πήρε σελίδα φτιαγμένη για συνδεδεμένο φαρμακείο (περιέχει αναγνωριστικό PlanDose άλλου λογαριασμού).',
				array_merge(
					$common,
					array(
						'Κάποιο επίπεδο cache αποθηκεύει σελίδες συνδεδεμένων χρηστών.',
						'Ελέγξτε με τη σειρά: WP Rocket (caching for logged-in users), Nginx FastCGI cache (παράκαμψη στο cookie wordpress_logged_in), Bunny (να μη γίνεται cache σε HTML με αυτό το cookie).',
					)
				)
			);
		} elseif ( '' === $logged_nonce ) {
			$results[] = self::result(
				self::WARN,
				'Έλεγχος με συνδεδεμένο λογαριασμό',
				'Η σελίδα δεν περιείχε στοιχεία PlanDose ούτε για τον συνδεδεμένο λογαριασμό, οπότε δεν υπάρχει τι να συγκριθεί.',
				array_merge(
					$common,
					array(
						'Πιθανές αιτίες: το εργαλείο δεν εμφανίζεται σε αυτή τη σελίδα (ρύθμιση «Εμφάνιση»), ο λογαριασμός δεν έχει πρόσβαση, ή η απάντηση ήρθε από cache.',
						'Δοκιμάστε άλλη σελίδα: wp plandose check --url-path=/',
					)
				)
			);
		} else {
			/*
			 * "No leak" is only evidence when either this address really is
			 * cached (the third request came back from cache), or there is
			 * no page cache in front of the site at all — in which case
			 * there is nothing that could leak.
			 */
			$cached_here = $guest_again && ! is_wp_error( $guest_again ) && self::response_came_from_cache( $guest_again, $cache_header );
			$layer       = self::detected_cache_layer( array( $logged_in, $guest, $guest_again ), $cache_header );

			$results[] = $cached_here
				? self::result(
					self::PASS,
					'Διαρροή σελίδας συνδεδεμένου χρήστη',
					'Ο επισκέπτης πήρε σελίδα επισκέπτη: κανένα στοιχείο συνδεδεμένου φαρμακείου δεν εμφανίστηκε.',
					array_merge( $common, array( self::LOOPBACK_NOTE ) )
				)
				: self::result(
					self::WARN,
					'Διαρροή σελίδας συνδεδεμένου χρήστη',
					'' !== $layer
						? 'Καμία διαρροή σε αυτόν τον έλεγχο, αλλά δεν αποδεικνύεται και το αντίθετο: υπάρχει cache (' . $layer . '), όμως η διεύθυνση που δοκιμάστηκε δεν φάνηκε να αποθηκεύεται σε αυτήν.'
						: 'Καμία διαρροή σε αυτόν τον έλεγχο. Δεν εντοπίστηκε επίπεδο cache, αυτό όμως δεν αποδεικνύει ότι δεν υπάρχει: ένας Nginx μπορεί να κάνει cache χωρίς να στέλνει σχετικές κεφαλίδες, και ένα αίτημα από τον ίδιο τον server μπορεί να μην περνά από το CDN.',
					array_merge(
						$common,
						'' !== $layer
							? array()
							: array( 'Επιβεβαιώστε ότι δεν υπάρχει κοινόχρηστη cache (ρύθμιση Nginx/aaPanel, ή προσθέστε add_header X-FastCGI-Cache $upstream_cache_status; για να φαίνεται στις απαντήσεις).' ),
						$exact_url
							? array( 'Δοκιμάστε άλλη σελίδα που σίγουρα αποθηκεύεται σε cache: wp plandose check --exact-url --url-path=/kapoia-selida/' )
							: array(
								'Πολλές cache αγνοούν διευθύνσεις με παράμετρο. Για οριστική απάντηση τρέξτε: wp plandose check --exact-url',
								'Το --exact-url ελέγχει την ίδια τη διεύθυνση. Αν το site όντως διαρρέει, η σελίδα του λογαριασμού δοκιμής μπορεί να μείνει για λίγο στην cache — καθαρίστε την cache μετά.',
							),
						array( self::LOOPBACK_NOTE )
					)
				);
		}

		$results[] = self::cache_status_result( $logged_in, $user, $probe_url, $cache_header );

		return $results;
	}

	/**
	 * The cache-status headers of one response: the well-known ones, plus any
	 * header whose name mentions "cache" and whose value reads like a status
	 * (HIT / MISS / BYPASS / …). Cache-Control is deliberately excluded — it
	 * is an instruction, not a report of what happened.
	 *
	 * @param array  $response HTTP response.
	 * @param string $extra    Additional header name to treat as a status.
	 * @return array<string,array{label:string,value:string}> Keyed by header name.
	 */
	public static function cache_status_headers( $response, $extra = '' ) {
		$found = array();

		if ( ! $response || is_wp_error( $response ) ) {
			return $found;
		}

		foreach ( self::CACHE_HEADERS as $header => $label ) {
			$value = (string) wp_remote_retrieve_header( $response, $header );

			if ( '' !== $value ) {
				$found[ $header ] = array(
					'label' => $label,
					'value' => $value,
				);
			}
		}

		$extra = strtolower( trim( (string) $extra ) );

		foreach ( self::header_list( $response ) as $header => $value ) {
			$header = strtolower( (string) $header );
			$value  = is_array( $value ) ? implode( ', ', $value ) : (string) $value;

			if ( isset( $found[ $header ] ) || 'cache-control' === $header || '' === trim( $value ) ) {
				continue;
			}

			$named  = $header === $extra || ( false !== strpos( $header, 'cache' ) && false === strpos( $header, 'cache-control' ) );
			$status = false;

			foreach ( self::STATUS_VALUES as $candidate ) {
				if ( preg_match( '/\b' . $candidate . '\b/i', $value ) ) {
					$status = true;
					break;
				}
			}

			if ( $named && ( $status || $header === $extra ) ) {
				$found[ $header ] = array(
					'label' => 'cache (' . $header . ')',
					'value' => $value,
				);
			}
		}

		return $found;
	}

	/**
	 * All response headers as a plain name => value array.
	 *
	 * WordPress returns a CaseInsensitiveDictionary here, whose plain (array)
	 * cast exposes its private storage rather than the headers.
	 *
	 * @param array $response HTTP response.
	 * @return array<string,mixed>
	 */
	private static function header_list( $response ) {
		$headers = wp_remote_retrieve_headers( $response );

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			return (array) $headers->getAll();
		}

		if ( is_object( $headers ) && $headers instanceof Traversable ) {
			return iterator_to_array( $headers );
		}

		return is_array( $headers ) ? $headers : array();
	}

	/**
	 * Which page-cache layer is in front of this site, judged by the headers
	 * of the responses just received and by the caching plugins in use.
	 * Empty when nothing suggests a page cache at all.
	 *
	 * @param array $responses Responses (WP_Error entries are skipped).
	 * @return string Name of a layer, or ''.
	 */
	public static function detected_cache_layer( $responses, $extra = '' ) {
		foreach ( (array) $responses as $response ) {
			foreach ( self::cache_status_headers( $response, $extra ) as $found ) {
				return $found['label'];
			}

			if ( $response && ! is_wp_error( $response ) && (int) wp_remote_retrieve_header( $response, 'age' ) > 0 ) {
				return 'κοινόχρηστη cache (Age)';
			}
		}

		if ( defined( 'WP_ROCKET_VERSION' ) || self::plugin_is_active( 'wp-rocket/wp-rocket.php' ) ) {
			return 'WP Rocket';
		}

		if ( self::plugin_is_active( 'nginx-helper/nginx-helper.php' ) ) {
			return 'Nginx FastCGI cache';
		}

		return '';
	}

	/**
	 * Whether any cache layer says it served this response from store.
	 *
	 * @param array $response HTTP response.
	 * @return bool
	 */
	public static function response_came_from_cache( $response, $extra = '' ) {
		foreach ( self::cache_status_headers( $response, $extra ) as $found ) {
			if ( self::header_says_hit( $found['value'] ) ) {
				return true;
			}
		}

		return (int) wp_remote_retrieve_header( $response, 'age' ) > 0;
	}

	/**
	 * Whether a URL points at this site's own host. The auth cookie is only
	 * ever attached to a request that passes this.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_same_host( $url ) {
		$target = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home   = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		return '' !== $target && $target === $home;
	}

	/**
	 * Create a real, short-lived session for the test account.
	 *
	 * The token is created explicitly rather than letting
	 * wp_generate_auth_cookie() do it, so close_test_session() can destroy
	 * exactly this one afterwards: otherwise every run of the diagnostic
	 * would leave a usable five-minute session behind in that pharmacy's
	 * user meta.
	 *
	 * @param WP_User $user Account.
	 * @return array{cookie:string,token:string}
	 */
	private static function open_test_session( $user ) {
		$expiry  = time() + 5 * MINUTE_IN_SECONDS;
		$manager = WP_Session_Tokens::get_instance( $user->ID );
		$token   = $manager->create( $expiry );

		return array(
			'cookie' => (string) wp_generate_auth_cookie( $user->ID, $expiry, 'logged_in', $token ),
			'token'  => (string) $token,
		);
	}

	/**
	 * Destroy the session opened for the test, leaving the account exactly
	 * as it was found.
	 *
	 * @param WP_User $user  Account.
	 * @param string  $token Session token.
	 */
	private static function close_test_session( $user, $token ) {
		if ( '' === $token ) {
			return;
		}

		WP_Session_Tokens::get_instance( $user->ID )->destroy( $token );
	}

	/**
	 * The auth cookie as WP_Http cookies, bound to this site's host.
	 *
	 * @param string $value Cookie value from open_test_session().
	 * @return array<int,WP_Http_Cookie>
	 */
	private static function session_cookies( $value ) {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return array(
			new WP_Http_Cookie(
				array(
					'name'   => LOGGED_IN_COOKIE,
					'value'  => $value,
					'domain' => $host,
					'path'   => '/',
				)
			),
		);
	}

	/**
	 * What the caching layers said about the logged-in response.
	 *
	 * @param array   $response Logged-in response.
	 * @param WP_User $user     Account used.
	 * @param string  $url      URL requested.
	 * @return array Result.
	 */
	private static function cache_status_result( $response, $user, $url, $extra = '' ) {
		$hits    = array();
		$details = array( 'Λογαριασμός δοκιμής: ' . $user->user_login, 'Διεύθυνση: ' . $url );

		foreach ( self::cache_status_headers( $response, $extra ) as $header => $found ) {
			$details[] = $found['label'] . ' (' . $header . '): ' . $found['value'];

			if ( self::header_says_hit( $found['value'] ) ) {
				$hits[] = $found['label'];
			}
		}

		$cache_control = strtolower( (string) wp_remote_retrieve_header( $response, 'cache-control' ) );

		if ( '' !== $cache_control ) {
			$details[] = 'Cache-Control: ' . $cache_control;
		}

		$age = (string) wp_remote_retrieve_header( $response, 'age' );

		if ( '' !== $age && (int) $age > 0 ) {
			$details[] = 'Age: ' . $age . ' (η απάντηση ήταν αποθηκευμένη)';
			$hits[]    = 'κοινόχρηστη cache (Age)';
		}

		if ( $hits ) {
			return self::result(
				self::FAIL,
				'Κεφαλίδες cache στη σελίδα του συνδεδεμένου',
				'Η σελίδα του συνδεδεμένου λογαριασμού σερβιρίστηκε από cache: ' . implode( ', ', array_unique( $hits ) ) . '.',
				$details
			);
		}

		if ( '' !== $cache_control && preg_match( '/max-age=([1-9][0-9]*)/', $cache_control ) && false === strpos( $cache_control, 'private' ) && false === strpos( $cache_control, 'no-store' ) ) {
			return self::result(
				self::WARN,
				'Κεφαλίδες cache στη σελίδα του συνδεδεμένου',
				'Η σελίδα του συνδεδεμένου λογαριασμού επιτρέπει αποθήκευση σε κοινόχρηστη cache (Cache-Control χωρίς private/no-store).',
				$details
			);
		}

		$details[] = self::LOOPBACK_NOTE;

		return self::result(
			self::PASS,
			'Κεφαλίδες cache στη σελίδα του συνδεδεμένου',
			'Καμία κεφαλίδα δεν δείχνει ότι η σελίδα του συνδεδεμένου λογαριασμού ήρθε ή αποθηκεύεται σε κοινόχρηστη cache.',
			$details
		);
	}

	/**
	 * Whether a cache-status header value means the response came from cache.
	 *
	 * @param string $value Header value, e.g. "HIT", "BYPASS", "MISS, HIT".
	 * @return bool
	 */
	public static function header_says_hit( $value ) {
		$value = strtolower( trim( (string) $value ) );

		if ( '' === $value ) {
			return false;
		}

		/*
		 * A chain of caches reports one value per layer ("MISS, HIT"), and
		 * which layer is which is not standardized. This is a security
		 * check, so it errs on the side of noise: a HIT anywhere in the
		 * chain counts as "a shared cache served this page", even next to a
		 * MISS. A false alarm costs a minute; a missed leak costs patient
		 * and pharmacy data. Only values with no HIT at all — BYPASS, MISS,
		 * EXPIRED, DYNAMIC — are read as "not from cache".
		 */
		foreach ( self::HIT_VALUES as $hit ) {
			if ( preg_match( '/\b' . preg_quote( $hit, '/' ) . '\b/', $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The caching plugins in use and their settings, where they can be read
	 * (shared with PlanDose → Διαγνωστικά).
	 *
	 * @return array<int,array> Results.
	 */
	public static function check_cache_settings() {
		return Plandose_Diagnostics::check_caching();
	}

	/* --------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The account for the logged-in test: the one asked for, or a pharmacy
	 * with PlanDose access.
	 *
	 * @param string $as_user Login or ID, '' to pick one.
	 * @param bool   $force   Accept an administrator / an account without access.
	 * @return WP_User|null
	 */
	public static function pick_cache_test_user( $as_user = '', $force = false ) {
		if ( '' !== $as_user ) {
			$user = is_numeric( $as_user ) ? get_user_by( 'id', (int) $as_user ) : get_user_by( 'login', $as_user );

			if ( ! $user ) {
				WP_CLI::warning( 'Δεν βρέθηκε ο λογαριασμός: ' . $as_user );
				return null;
			}

			// The test opens a real session for this account and
			// requests pages with it — never an administrator's by accident,
			// and only an account the tool is actually shown to.
			if ( ! $force && user_can( $user, 'manage_options' ) ) {
				WP_CLI::warning( 'Ο λογαριασμός ' . $user->user_login . ' είναι διαχειριστής· δεν χρησιμοποιείται για τη δοκιμή (--force για παράκαμψη).' );
				return null;
			}

			if ( ! $force && ! Plandose_Access::can_use_tool( $user->ID ) ) {
				WP_CLI::warning( 'Ο λογαριασμός ' . $user->user_login . ' δεν έχει πρόσβαση στο PlanDose· η δοκιμή δεν θα έδειχνε τίποτα (--force για παράκαμψη).' );
				return null;
			}

			return $user;
		}

		$candidates = get_users(
			array(
				'number'     => 25,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-off diagnostic, capped at 25 rows.
					'relation' => 'OR',
					array(
						'key'     => 'account_type',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => 'user_registration_account_type',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $candidates as $user ) {
			// The automatic choice follows the same rule as --as — never
			// an administrator (with «Προεπισκόπηση διαχειριστή» on, an admin
			// with an account_type meta passes can_use_tool() and would get a
			// real session opened for the cache test).
			if ( user_can( $user, 'manage_options' ) ) {
				continue;
			}

			if ( Plandose_Access::can_use_tool( $user->ID ) ) {
				return $user;
			}
		}

		return null;
	}

	/**
	 * The PlanDose AJAX nonce localized into a page, if any.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	public static function extract_nonce( $body ) {
		// Anchored on the variable PlanDose localizes, then the first nonce
		// after it. Not on a balanced object: a translated string may well
		// contain "};" and would cut the match short.
		$body = (string) $body;
		$at   = strpos( $body, 'PlandoseConfig' );

		if ( false === $at ) {
			return '';
		}

		if ( ! preg_match( '/"nonce"\s*:\s*"([a-f0-9]{6,20})"/i', substr( $body, $at, 20000 ), $m ) ) {
			return '';
		}

		return $m[1];
	}

	/**
	 * One GET request to our own site, without following anything clever.
	 *
	 * @param string $url     URL.
	 * @param array  $cookies Cookies.
	 * @return array|WP_Error
	 */
	private static function http_get( $url, $cookies = array() ) {
		/*
		 * Deliberately NO Cache-Control / Pragma request headers: several
		 * stacks (stock Nginx snippets with proxy_cache_bypass $http_pragma,
		 * Bunny, LiteSpeed) read them as "skip the cache", which would hand
		 * this check a freshly built page and hide the very leak it is
		 * looking for.
		 *
		 * redirection => 0 so the session cookie is never replayed to
		 * wherever a redirect points, and cookies are refused outright for
		 * any host but this site's own.
		 */
		if ( $cookies && ! self::is_same_host( $url ) ) {
			return new WP_Error( 'plandose_cli_foreign_host', 'Η διεύθυνση δεν ανήκει σε αυτό το site.' );
		}

		return wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => true,
				'cookies'     => $cookies,
				'user-agent'  => 'PlanDose-Check/' . PLANDOSE_VERSION,
			)
		);
	}

	/**
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	private static function plugin_is_active( $plugin ) {
		return Plandose_Diagnostics::plugin_is_active( $plugin );
	}
}

WP_CLI::add_command( 'plandose', 'Plandose_CLI' );
