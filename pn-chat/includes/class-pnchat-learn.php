<?php
/**
 * Learning with AI: Claude reads pages of the site, a web address or a PDF
 * and proposes entries. The proposals wait in «Προτάσεις»; nothing reaches
 * the chat before an administrator approves it. The chat itself keeps
 * answering from the approved entries, without AI.
 *
 * Reading runs in the background (WP-Cron, one source at a time), so a whole
 * site or a long PDF never hits a web server's time limit.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reading queue and proposals.
 */
final class PNChat_Learn {

	const QUEUE      = 'pnchat_learn_queue';
	const STATE      = 'pnchat_learn_state';
	const CRON       = 'pnchat_learn';
	const WEEKLY     = 'pnchat_learn_weekly';
	const META       = '_pnchat_learned'; // Hash of a page when it was last read.
	const PDF_BYTES  = 20971520; // 20 MB: the request (base64, a third larger) stays under the API's 32 MB.
	const HTML_BYTES = 3145728;
	const MAX_ERRORS = 10;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'run_cron' ) );
		add_action( self::WEEKLY, array( __CLASS__, 'weekly' ) );
	}

	/**
	 * Proposals table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pnchat_proposals';
	}

	/**
	 * SQL of the proposals table (for dbDelta).
	 *
	 * @param string $charset Charset and collation.
	 * @return string
	 */
	public static function table_sql( $charset ) {
		return 'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				status varchar(10) NOT NULL DEFAULT 'pending',
				title_key varchar(190) NOT NULL DEFAULT '',
				source_url varchar(255) NOT NULL DEFAULT '',
				source_title varchar(255) NOT NULL DEFAULT '',
				entry longtext NOT NULL,
				note text NOT NULL,
				similar_id bigint(20) unsigned NOT NULL DEFAULT 0,
				entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status_created (status,created_at),
				KEY title_key (title_key)
			) {$charset};";
	}

	/* ------------------------------------------------------------------ */
	/* limits and settings                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Reading may run: AI on, with a key.
	 *
	 * @return bool
	 */
	public static function available() {
		return PNChat_AI::enabled();
	}

	/**
	 * Counter of this month's readings.
	 *
	 * @return string
	 */
	private static function month_key() {
		return 'ai_learn:' . gmdate( 'Ym' );
	}

	/**
	 * Sources read this month.
	 *
	 * @return int
	 */
	public static function used_this_month() {
		return PNChat_Counter::get( self::month_key() );
	}

	/**
	 * Sources that may be read in a month (setting «ai_learn_monthly»).
	 *
	 * @return int
	 */
	public static function monthly_limit() {
		return max( 1, (int) PNChat_Settings::value( 'ai_learn_monthly' ) );
	}

	/**
	 * Weekly re-reading of changed pages follows its setting.
	 *
	 * @return void
	 */
	public static function sync_weekly() {
		$on   = ! empty( PNChat_Settings::value( 'ai_learn_weekly' ) ) && self::available();
		$next = wp_next_scheduled( self::WEEKLY );
		if ( $on && ! $next ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::WEEKLY );
		} elseif ( ! $on && $next ) {
			wp_clear_scheduled_hook( self::WEEKLY );
		}
	}

	/**
	 * Weekly: the pages that changed since they were last read.
	 *
	 * @return void
	 */
	public static function weekly() {
		if ( empty( PNChat_Settings::value( 'ai_learn_weekly' ) ) || ! self::available() ) {
			return;
		}
		self::enqueue_site( true, 'Εβδομαδιαίος έλεγχος του site' );
	}

	/* ------------------------------------------------------------------ */
	/* queue                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Waiting jobs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function queue() {
		$q = get_option( self::QUEUE, array() );
		return is_array( $q ) ? array_values( $q ) : array();
	}

	/**
	 * Progress of the current reading.
	 *
	 * @return array<string,mixed>
	 */
	public static function state() {
		$s = get_option( self::STATE, array() );
		return array_merge(
			array(
				'label'     => '',
				'total'     => 0,
				'done'      => 0,
				'proposals' => 0,
				'skipped'   => 0,
				'paused'    => '',
				'errors'    => array(),
				'last'      => '',
			),
			is_array( $s ) ? $s : array()
		);
	}

	/**
	 * Saves the progress.
	 *
	 * @param array<string,mixed> $s State.
	 * @return void
	 */
	private static function save_state( array $s ) {
		update_option( self::STATE, $s, false );
	}

	/**
	 * Identity of a job, so the same source is never queued twice.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return string
	 */
	private static function job_key( array $job ) {
		switch ( $job['type'] ?? '' ) {
			case 'post':
				return 'post:' . (int) $job['id'];
			case 'url':
				return 'url:' . (string) $job['url'];
			default:
				return 'file:' . (string) ( $job['file'] ?? '' );
		}
	}

	/**
	 * Adds jobs to the queue and starts the background reading.
	 *
	 * @param array<int,array<string,mixed>> $jobs  Jobs: {type:post,id} {type:url,url} {type:file,file,name}.
	 * @param string                         $label What is being read, for the progress line.
	 * @return int Jobs added (already queued ones are not added again).
	 */
	public static function enqueue( array $jobs, $label ) {
		$queue = self::queue();
		$state = self::state();
		if ( ! $queue ) {
			// A new reading: the progress starts again.
			$state = array_merge(
				$state,
				array(
					'label'     => '',
					'total'     => 0,
					'done'      => 0,
					'proposals' => 0,
					'skipped'   => 0,
					'paused'    => '',
					'errors'    => array(),
				)
			);
		}
		$have = array();
		foreach ( $queue as $j ) {
			$have[ self::job_key( $j ) ] = true;
		}
		$added = 0;
		foreach ( $jobs as $j ) {
			$k = self::job_key( $j );
			if ( isset( $have[ $k ] ) ) {
				continue;
			}
			$have[ $k ] = true;
			$queue[]    = $j;
			++$added;
		}
		if ( ! $added ) {
			return 0;
		}
		update_option( self::QUEUE, $queue, false );
		$state['total'] += $added;
		$state['label']  = '' === $state['label'] ? (string) $label : $state['label'] . ' · ' . $label;
		$state['paused'] = '';
		self::save_state( $state );
		self::schedule();
		return $added;
	}

	/**
	 * Every page of the site that the chat may show.
	 *
	 * @param bool   $changed_only Only pages that changed since they were last read.
	 * @param string $label        Progress label.
	 * @return array{added:int,unchanged:int}
	 */
	public static function enqueue_site( $changed_only, $label = 'Όλο το site' ) {
		$ids       = get_posts(
			array(
				'post_type'      => PNChat_Site_Search::post_types(),
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => 2000,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		$excluded  = PNChat_Site_Search::excluded_ids();
		$jobs      = array();
		$unchanged = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( in_array( $id, $excluded, true ) ) {
				continue;
			}
			if ( $changed_only && get_post_meta( $id, self::META, true ) === self::post_hash( get_post( $id ) ) ) {
				++$unchanged;
				continue;
			}
			$jobs[] = array(
				'type' => 'post',
				'id'   => $id,
			);
		}
		return array(
			'added'     => self::enqueue( $jobs, $label ),
			'unchanged' => $unchanged,
		);
	}

	/**
	 * Hash of what Claude reads from a page.
	 *
	 * @param WP_Post|null $post Page.
	 * @return string
	 */
	public static function post_hash( $post ) {
		return $post instanceof WP_Post ? md5( get_the_title( $post ) . "\n" . PNChat_Site_Search::plain_text( $post ) ) : '';
	}

	/**
	 * Runs the next job soon, in the background.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( self::queue() && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 5, self::CRON );
		}
	}

	/**
	 * Stops: the waiting jobs are dropped (with their uploaded files).
	 *
	 * @return int Jobs dropped.
	 */
	public static function stop() {
		$queue = self::queue();
		foreach ( $queue as $j ) {
			if ( 'file' === ( $j['type'] ?? '' ) ) {
				self::delete_file( (string) $j['file'] );
			}
		}
		delete_option( self::QUEUE );
		wp_clear_scheduled_hook( self::CRON );
		$state           = self::state();
		$state['total'] -= count( $queue );
		$state['paused'] = '';
		self::save_state( $state );
		return count( $queue );
	}

	/**
	 * WP-Cron: one job, then the next one is scheduled.
	 *
	 * @return void
	 */
	public static function run_cron() {
		$r = self::process_next();
		if ( 'limit' !== $r['status'] && 'off' !== $r['status'] ) {
			self::schedule();
		}
	}

	/**
	 * Reads the next source of the queue.
	 *
	 * @return array{status:string,added:int,error:string} status: 'done', 'error', 'empty', 'busy', 'limit' (month), 'off' (no AI).
	 */
	public static function process_next() {
		$out = array(
			'status' => 'empty',
			'added'  => 0,
			'error'  => '',
		);
		if ( ! self::queue() ) {
			return $out;
		}
		if ( ! self::available() ) {
			$state           = self::state();
			$state['paused'] = 'off';
			self::save_state( $state );
			$out['status'] = 'off';
			return $out;
		}
		// One job at a time: the queue option is read and written whole.
		if ( ! PNChat_Counter::take( 'learn_lock', 1, 15 * MINUTE_IN_SECONDS, false ) ) {
			$out['status'] = 'busy';
			return $out;
		}
		try {
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- a long PDF takes minutes.
			}
			$queue = self::queue();
			if ( ! $queue ) {
				return $out;
			}
			if ( ! PNChat_Counter::take( self::month_key(), self::monthly_limit(), 40 * DAY_IN_SECONDS, false ) ) {
				$state           = self::state();
				$state['paused'] = 'limit';
				self::save_state( $state );
				$out['status'] = 'limit';
				return $out;
			}
			$job = array_shift( $queue );
			update_option( self::QUEUE, $queue, false );

			$r     = self::run_job( $job );
			$state = self::state();
			++$state['done'];
			$state['paused'] = '';
			if ( is_wp_error( $r ) ) {
				if ( PNChat_AI::not_charged( $r ) || 'pnchat_learn_source' === $r->get_error_code() ) {
					// Claude was never asked: the month's limit gets it back.
					PNChat_Counter::give_back( self::month_key() );
				}
				$state['errors'][] = array(
					'source' => self::job_label( $job ),
					'error'  => $r->get_error_message(),
					'at'     => current_time( 'mysql', true ),
				);
				$state['errors'] = array_slice( $state['errors'], -self::MAX_ERRORS );
				$out['status']   = 'error';
				$out['error']    = $r->get_error_message();
			} else {
				$state['proposals'] += $r['added'];
				$state['skipped']   += $r['skipped'];
				$out['status']       = 'done';
				$out['added']        = $r['added'];
			}
			$state['last'] = self::job_label( $job );
			self::save_state( $state );
			return $out;
		} finally {
			PNChat_Counter::give_back( 'learn_lock' );
		}
	}

	/**
	 * A job in words.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return string
	 */
	public static function job_label( array $job ) {
		switch ( $job['type'] ?? '' ) {
			case 'post':
				return (string) get_the_title( (int) $job['id'] );
			case 'url':
				return (string) $job['url'];
			default:
				return (string) ( $job['name'] ?? 'PDF' );
		}
	}

	/**
	 * Reads one source and stores its proposals.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array{added:int,skipped:int}|WP_Error
	 */
	public static function run_job( array $job ) {
		try {
			$src = self::load_source( $job );
			if ( is_wp_error( $src ) ) {
				return $src;
			}
			$drafts = PNChat_AI::drafts_from_source( $src );
			if ( is_wp_error( $drafts ) ) {
				return $drafts;
			}
			$added   = 0;
			$skipped = 0;
			foreach ( $drafts as $e ) {
				if ( self::add_proposal( $e, $src ) ) {
					++$added;
				} else {
					++$skipped;
				}
			}
			if ( ! empty( $src['post_id'] ) ) {
				update_post_meta( (int) $src['post_id'], self::META, (string) $src['hash'] );
			}
			return array(
				'added'   => $added,
				'skipped' => $skipped,
			);
		} finally {
			if ( 'file' === ( $job['type'] ?? '' ) ) {
				self::delete_file( (string) $job['file'] );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* sources                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * What Claude reads for a job.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array{title:string,url:string,text:string,pdf:string,external:bool,post_id:int,hash:string}|WP_Error
	 */
	public static function load_source( array $job ) {
		$type = (string) ( $job['type'] ?? '' );
		if ( 'url' === $type ) {
			$url = (string) $job['url'];
			// A page of this site is read from the database (drafts too, never).
			if ( self::is_own( $url ) ) {
				$id = url_to_postid( $url );
				if ( $id ) {
					$type = 'post';
					$job  = array(
						'type' => 'post',
						'id'   => $id,
					);
				}
			}
			if ( 'url' === $type ) {
				return self::fetch_url( $url );
			}
		}
		if ( 'post' === $type ) {
			$post = get_post( (int) $job['id'] );
			if ( ! PNChat_Site_Search::searchable( $post ) ) {
				return new WP_Error( 'pnchat_learn_source', 'Η σελίδα δεν είναι δημόσια ή είναι εξαιρεμένη από την αναζήτηση του chat.' );
			}
			$text = PNChat_Site_Search::plain_text( $post );
			if ( mb_strlen( trim( $text ) ) < 40 ) {
				return new WP_Error( 'pnchat_learn_source', 'Η σελίδα δεν έχει κείμενο για να διαβαστεί.' );
			}
			return array(
				'title'    => (string) get_the_title( $post ),
				'url'      => (string) get_permalink( $post ),
				'text'     => $text,
				'pdf'      => '',
				'external' => false,
				'post_id'  => (int) $post->ID,
				'hash'     => self::post_hash( $post ),
			);
		}
		if ( 'file' === $type ) {
			$path = self::file_path( (string) ( $job['file'] ?? '' ) );
			$data = '' !== $path && is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file we stored.
			if ( false === $data || 0 !== strpos( $data, '%PDF' ) ) {
				return new WP_Error( 'pnchat_learn_source', 'Το αρχείο PDF δεν βρέθηκε ή δεν είναι PDF.' );
			}
			return array(
				'title'    => (string) ( $job['name'] ?? 'PDF' ),
				'url'      => '',
				'text'     => '',
				'pdf'      => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API takes PDFs as base64.
				'external' => true,
				'post_id'  => 0,
				'hash'     => '',
			);
		}
		return new WP_Error( 'pnchat_learn_source', 'Άγνωστη πηγή.' );
	}

	/**
	 * The address is on this site.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	public static function is_own( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host && strtolower( (string) $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Downloads a web page or a PDF.
	 *
	 * @param string $url Address (http/https).
	 * @return array{title:string,url:string,text:string,pdf:string,external:bool,post_id:int,hash:string}|WP_Error
	 */
	public static function fetch_url( $url ) {
		// wp_safe_remote_get() refuses local and private addresses.
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 60,
				'redirection'         => 3,
				'limit_response_size' => self::PDF_BYTES + 1,
				'user-agent'          => 'PN Chat/' . PNCHAT_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'pnchat_learn_source', 'Η διεύθυνση δεν άνοιξε: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return new WP_Error( 'pnchat_learn_source', 'Η διεύθυνση απάντησε με σφάλμα HTTP ' . $code . '.' );
		}
		$body = (string) wp_remote_retrieve_body( $res );
		$type = strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
		$name = rawurldecode( (string) basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( 0 === strpos( $body, '%PDF' ) || false !== strpos( $type, 'application/pdf' ) ) {
			if ( strlen( $body ) > self::PDF_BYTES ) {
				return new WP_Error( 'pnchat_learn_source', 'Το PDF είναι μεγαλύτερο από 20 MB.' );
			}
			if ( 0 !== strpos( $body, '%PDF' ) ) {
				return new WP_Error( 'pnchat_learn_source', 'Το αρχείο δεν είναι έγκυρο PDF.' );
			}
			return array(
				'title'    => '' !== $name ? $name : $url,
				'url'      => $url,
				'text'     => '',
				'pdf'      => base64_encode( $body ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API takes PDFs as base64.
				'external' => true,
				'post_id'  => 0,
				'hash'     => '',
			);
		}
		if ( '' !== $type && false === strpos( $type, 'html' ) && false === strpos( $type, 'text/' ) ) {
			return new WP_Error( 'pnchat_learn_source', 'Η διεύθυνση δεν είναι σελίδα ή PDF (' . sanitize_text_field( $type ) . ').' );
		}
		$page = self::html_text( substr( $body, 0, self::HTML_BYTES ) );
		if ( mb_strlen( $page['text'] ) < 40 ) {
			return new WP_Error( 'pnchat_learn_source', 'Η σελίδα δεν έχει κείμενο για να διαβαστεί (ίσως φτιάχνεται με JavaScript).' );
		}
		return array(
			'title'    => '' !== $page['title'] ? $page['title'] : $url,
			'url'      => $url,
			'text'     => $page['text'],
			'pdf'      => '',
			'external' => ! self::is_own( $url ),
			'post_id'  => 0,
			'hash'     => '',
		);
	}

	/**
	 * Title and readable text of an HTML page: menus, headers, footers,
	 * scripts and forms left out; the main content when the page marks it.
	 *
	 * @param string $html HTML.
	 * @return array{title:string,text:string}
	 */
	public static function html_text( $html ) {
		$title = '';
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			$title = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
		}
		$html = (string) preg_replace( '#<(script|style|noscript|svg|template|iframe|form|nav|header|footer|aside)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );
		foreach ( array( 'main', 'article' ) as $tag ) {
			if ( preg_match( '#<' . $tag . '\b[^>]*>(.*)</' . $tag . '>#is', $html, $m ) && mb_strlen( wp_strip_all_tags( $m[1] ) ) > 200 ) {
				$html = $m[1];
				break;
			}
		}
		$html = (string) preg_replace( '#</(p|li|h[1-6]|div|tr|td|th|section|article|blockquote|dd|dt)>|<br\s*/?>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		$text = (string) preg_replace( "/\n\s*\n+/", "\n", $text );
		return array(
			'title' => $title,
			'text'  => trim( mb_substr( $text, 0, PNChat_AI::SOURCE_CHARS ) ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* uploaded PDFs                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Folder of uploaded PDFs waiting to be read (closed to the web).
	 *
	 * @return string
	 */
	public static function dir() {
		$up = wp_upload_dir( null, false );
		return trailingslashit( (string) $up['basedir'] ) . 'pn-chat-learn';
	}

	/**
	 * Full path of a stored PDF ('' for an invalid name).
	 *
	 * @param string $file Stored name.
	 * @return string
	 */
	private static function file_path( $file ) {
		return preg_match( '/^[a-z0-9]{24}\.pdf$/', $file ) ? self::dir() . '/' . $file : '';
	}

	/**
	 * Keeps an uploaded PDF until it is read.
	 *
	 * @param string $tmp  Uploaded temporary file.
	 * @param string $name Original name.
	 * @return array{type:string,file:string,name:string}|WP_Error Job.
	 */
	public static function store_upload( $tmp, $name ) {
		$size = (int) @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file is size 0.
		if ( $size <= 0 ) {
			return new WP_Error( 'pnchat_learn_upload', 'Δεν ανέβηκε αρχείο.' );
		}
		if ( $size > self::PDF_BYTES ) {
			return new WP_Error( 'pnchat_learn_upload', 'Το PDF είναι μεγαλύτερο από 20 MB.' );
		}
		$head = (string) file_get_contents( $tmp, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the uploaded file.
		if ( '%PDF' !== $head ) {
			return new WP_Error( 'pnchat_learn_upload', 'Το αρχείο δεν είναι PDF.' );
		}
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'pnchat_learn_upload', 'Ο φάκελος uploads δεν είναι εγγράψιμος.' );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$file = strtolower( wp_generate_password( 24, false ) ) . '.pdf';
		$ok   = is_uploaded_file( $tmp ) ? move_uploaded_file( $tmp, $dir . '/' . $file ) : copy( $tmp, $dir . '/' . $file );
		if ( ! $ok ) {
			return new WP_Error( 'pnchat_learn_upload', 'Το PDF δεν αποθηκεύτηκε στον server.' );
		}
		return array(
			'type' => 'file',
			'file' => $file,
			'name' => sanitize_file_name( (string) $name ),
		);
	}

	/**
	 * Deletes a stored PDF.
	 *
	 * @param string $file Stored name.
	 * @return void
	 */
	private static function delete_file( $file ) {
		$path = self::file_path( $file );
		if ( '' !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/* ------------------------------------------------------------------ */
	/* proposals                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Key of a title, for «already proposed or rejected».
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private static function title_key( $title ) {
		return substr( PNChat_Text::fold( (string) $title ), 0, 190 );
	}

	/**
	 * Checks a drafted entry against the brain and stores it as a proposal.
	 * Skipped: one with the same title already waiting or rejected.
	 *
	 * @param array<string,mixed> $e   Clean entry.
	 * @param array<string,mixed> $src Source.
	 * @return int Proposal id; 0 when skipped or refused by the database.
	 */
	public static function add_proposal( array $e, array $src ) {
		global $wpdb;
		$key = self::title_key( (string) $e['title'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$seen = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE title_key = %s AND status IN ( 'pending', 'rejected' )", self::table(), $key ) );
		if ( (int) $seen > 0 ) {
			return 0;
		}
		$review     = self::review( $e );
		$e          = $review['entry'];
		$e['kind']  = 'answer';
		$e['active'] = 1;
		unset( $e['hits'], $e['created_at'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			self::table(),
			array(
				'status'       => 'pending',
				'title_key'    => $key,
				'source_url'   => substr( (string) $src['url'], 0, 255 ),
				'source_title' => mb_substr( (string) $src['title'], 0, 250 ),
				'entry'        => wp_json_encode( $e ),
				'note'         => implode( "\n", $review['notes'] ),
				'similar_id'   => $review['similar_id'],
				'created_at'   => current_time( 'mysql', true ),
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Keyword hygiene and similarity with the brain.
	 *
	 * Keywords that already appear in two or more entries, or that name a
	 * whole topic, would pull every question of that topic to this entry
	 * (the «eΔΑΠΥ» problem): they are taken off, with a note.
	 *
	 * @param array<string,mixed> $e Clean entry.
	 * @return array{entry:array<string,mixed>,notes:string[],similar_id:int}
	 */
	public static function review( array $e ) {
		$notes   = array();
		$entries = PNChat_Store::entries();
		$texts   = array();
		foreach ( $entries as $x ) {
			$texts[] = ' ' . PNChat_Text::fold( $x['title'] . ' ' . implode( ' ', (array) $x['phrasings'] ) . ' ' . implode( ' ', (array) $x['keywords'] ) ) . ' ';
		}
		$topic_terms = array();
		foreach ( PNChat_Topics::all() as $t ) {
			foreach ( $t['terms'] as $words ) {
				$topic_terms[ implode( ' ', $words ) ] = $t['name'];
			}
		}
		$keep = array();
		foreach ( (array) $e['keywords'] as $k ) {
			$f = PNChat_Text::fold( (string) $k );
			if ( '' === $f ) {
				continue;
			}
			if ( isset( $topic_terms[ $f ] ) ) {
				$notes[] = sprintf( 'Αφαιρέθηκε η λέξη-κλειδί «%1$s»: είναι το θέμα «%2$s» και θα τραβούσε όλες τις ερωτήσεις του εδώ.', $k, $topic_terms[ $f ] );
				continue;
			}
			$n = 0;
			foreach ( $texts as $t ) {
				if ( false !== strpos( $t, ' ' . $f . ' ' ) ) {
					++$n;
				}
			}
			if ( $n >= 2 ) {
				$notes[] = sprintf( 'Αφαιρέθηκε η λέξη-κλειδί «%1$s»: υπάρχει ήδη σε %2$d γνώσεις, άρα δεν ξεχωρίζει αυτή.', $k, $n );
				continue;
			}
			$keep[] = $k;
		}
		$e['keywords'] = $keep;

		// The existing entry most of its questions land on (scores added
		// up), so one stray question does not decide it. A question that
		// lands elsewhere is named on its own: approved as it is, two
		// entries would compete for it.
		$m      = PNChat_Brain::matcher();
		$limit  = PNChat_Settings::threshold();
		$tops   = array();
		$totals = array();
		foreach ( (array) $e['phrasings'] as $p ) {
			$r = $m->rank( (string) $p );
			if ( $r && $r[0]['score'] >= $limit ) {
				$tops[]                    = array( (string) $p, $r[0] );
				$totals[ $r[0]['id'] ] = ( $totals[ $r[0]['id'] ] ?? 0 ) + $r[0]['score'];
			}
		}
		$similar = 0;
		if ( $totals ) {
			arsort( $totals );
			$best_id = (int) array_key_first( $totals );
			$best    = null;
			foreach ( $tops as $t ) {
				if ( (int) $t[1]['id'] !== $best_id ) {
					$notes[] = sprintf( 'Η ερώτηση «%1$s» ταιριάζει ήδη με τη γνώση «%2$s». Αλλάξτε τη διατύπωση ή αφαιρέστε την.', $t[0], $t[1]['title'] );
					continue;
				}
				if ( ! $best || $t[1]['score'] > $best['score'] ) {
					$best = $t[1];
				}
			}
			$n = count( array_filter( $tops, fn( $t ) => (int) $t[1]['id'] === $best_id ) );
			if ( 'block' === $best['kind'] ) {
				$notes[] = sprintf( 'Προσοχή: ερωτήσεις της ταιριάζουν με την απαγόρευση «%s». Ο βοηθός θα αρνείται να απαντήσει σε αυτές.', $best['title'] );
			} else {
				$similar = $best_id;
				$notes[] = sprintf( 'Μοιάζει με την υπάρχουσα γνώση «%1$s» (%2$d από %3$d ερωτήσεις, ταίριασμα έως %4$d%%). Ίσως είναι καλύτερα να προσθέσετε τις ερωτήσεις εκεί.', $best['title'], $n, count( (array) $e['phrasings'] ), (int) round( 100 * min( 1, $best['score'] ) ) );
			}
		}
		return array(
			'entry'      => $e,
			'notes'      => $notes,
			'similar_id' => $similar,
		);
	}

	/**
	 * One proposal.
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>|null With «entry» decoded.
	 */
	public static function proposal( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * Row with its entry decoded.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private static function decode( array $row ) {
		$e            = json_decode( (string) $row['entry'], true );
		$row['entry'] = is_array( $e ) ? $e : array();
		return $row;
	}

	/**
	 * Proposals waiting, newest source first.
	 *
	 * @param int $page     Page (1-based).
	 * @param int $per_page Per page.
	 * @return array<int,array<string,mixed>>
	 */
	public static function pending( $page = 1, $per_page = 20 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'pending' ORDER BY id ASC LIMIT %d OFFSET %d", self::table(), $per_page, max( 0, ( $page - 1 ) * $per_page ) ), ARRAY_A );
		return array_map( array( __CLASS__, 'decode' ), (array) $rows );
	}

	/**
	 * Proposals waiting.
	 *
	 * @return int
	 */
	public static function count_pending() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'pending'", self::table() ) );
	}

	/**
	 * Marks a proposal.
	 *
	 * @param int    $id       Id.
	 * @param string $status   approved | merged | rejected.
	 * @param int    $entry_id Entry it became or joined.
	 * @return bool
	 */
	public static function set_status( $id, $status, $entry_id = 0 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update(
			self::table(),
			array(
				'status'   => $status,
				'entry_id' => (int) $entry_id,
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Approves a proposal (as it is, or as the administrator edited it).
	 *
	 * @param int                      $id    Proposal id.
	 * @param array<string,mixed>|null $entry Edited entry; null = as proposed.
	 * @return int Entry id; 0 when not saved.
	 */
	public static function approve( $id, $entry = null ) {
		$p = self::proposal( $id );
		if ( ! $p || 'pending' !== $p['status'] ) {
			return 0;
		}
		$e = PNChat_Brain::clean_entry( null === $entry ? $p['entry'] : $entry );
		if ( ! $e ) {
			return 0;
		}
		$e['kind']   = 'answer';
		$e['active'] = 1;
		$saved       = PNChat_Store::save_entry( $e );
		if ( $saved ) {
			self::set_status( $id, 'approved', $saved );
		}
		return (int) $saved;
	}

	/**
	 * Adds the proposal's questions to the similar entry instead.
	 *
	 * @param int      $id        Proposal id.
	 * @param string[] $phrasings Questions to add (the edited ones).
	 * @return int Entry id; 0 when not saved.
	 */
	public static function merge( $id, array $phrasings ) {
		$p = self::proposal( $id );
		if ( ! $p || 'pending' !== $p['status'] || ! $p['similar_id'] ) {
			return 0;
		}
		$target = PNChat_Store::entry( (int) $p['similar_id'] );
		if ( ! $target ) {
			return 0;
		}
		$have = array_map( array( 'PNChat_Text', 'fold' ), (array) $target['phrasings'] );
		foreach ( $phrasings as $q ) {
			$q = trim( (string) $q );
			if ( '' !== $q && ! in_array( PNChat_Text::fold( $q ), $have, true ) ) {
				$target['phrasings'][] = $q;
				$have[]                = PNChat_Text::fold( $q );
			}
		}
		$saved = PNChat_Store::save_entry( $target, (int) $target['id'] );
		if ( $saved ) {
			self::set_status( $id, 'merged', (int) $target['id'] );
		}
		return (int) $saved;
	}

	/**
	 * Deletes decided proposals older than 90 days (rejected titles are
	 * remembered that long, so a weekly reading does not propose them again).
	 *
	 * @return void
	 */
	public static function purge_old() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status <> 'pending' AND created_at < %s", self::table(), gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
	}
}
