<?php
/**
 * AI training assistant (admin only). Claude reads pages of THIS site and
 * drafts knowledge entries; an administrator reviews them before anything is
 * saved. The public chat never calls the API.
 *
 * Plain HTTP through the WordPress HTTP API (wp_remote_post), as WordPress
 * plugins should, to the Claude Messages API.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claude API client and drafting prompts.
 */
final class PNChat_AI {

	// Direct Messages API call, not the WordPress 7.0 AI Client: the plugin
	// supports WordPress 6.3+, and the drafts need a JSON schema answer and
	// server-side refusal fallbacks, which a generic provider does not expose.
	// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- see above.
	const ENDPOINT      = 'https://api.anthropic.com/v1/messages';
	const API_VERSION   = '2023-06-01';
	const DEFAULT_MODEL = 'claude-opus-5-5';
	const USAGE_OPTION  = 'pnchat_ai_usage';
	const PAGE_CHARS    = 15000; // Characters of each page sent with a question.
	const SOURCE_CHARS  = 60000; // Characters of a page turned into entries.

	/**
	 * API key: the PNCHAT_ANTHROPIC_API_KEY constant (wp-config.php) wins
	 * over the one saved in the settings.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'PNCHAT_ANTHROPIC_API_KEY' ) && is_string( PNCHAT_ANTHROPIC_API_KEY ) && '' !== PNCHAT_ANTHROPIC_API_KEY ) {
			return PNCHAT_ANTHROPIC_API_KEY;
		}
		$key = get_option( 'pnchat_ai_key', '' );
		return is_string( $key ) ? $key : '';
	}

	/**
	 * The key comes from wp-config.php.
	 *
	 * @return bool
	 */
	public static function key_from_constant() {
		return defined( 'PNCHAT_ANTHROPIC_API_KEY' ) && is_string( PNCHAT_ANTHROPIC_API_KEY ) && '' !== PNCHAT_ANTHROPIC_API_KEY;
	}

	/**
	 * AI drafting is switched on and has a key.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return ! empty( PNChat_Settings::value( 'ai_enabled' ) ) && '' !== self::api_key();
	}

	/**
	 * Model id.
	 *
	 * @return string
	 */
	public static function model() {
		$m = trim( (string) PNChat_Settings::value( 'ai_model' ) );
		return preg_match( '/^claude-[a-z0-9.-]+$/', $m ) ? $m : self::DEFAULT_MODEL;
	}

	/**
	 * Last characters of the key, for the settings screen.
	 *
	 * @return string
	 */
	public static function key_hint() {
		$k = self::api_key();
		return '' === $k ? '' : '…' . substr( $k, -4 );
	}

	/**
	 * Calls the Messages API and returns the decoded JSON answer.
	 *
	 * @param string              $system System prompt.
	 * @param string              $user   User message.
	 * @param array<string,mixed> $schema  JSON schema of the answer.
	 * @param string              $model   Model id ('' = the setting).
	 * @param int                 $timeout Seconds.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function call( $system, $user, array $schema, $model = '', $timeout = 180 ) {
		$key = self::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'pnchat_ai_key', 'Δεν έχει οριστεί API key (Ρυθμίσεις → AI βοηθός εκπαίδευσης).' );
		}
		$body = array(
			'model'         => '' !== $model ? $model : self::model(),
			'max_tokens'    => 16000,
			'system'        => $system,
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			// Thinking is adaptive by default on current models; effort is set
			// explicitly (its default differs per model).
			'output_config' => array(
				'effort' => 'medium',
				'format' => array(
					'type'   => 'json_schema',
					'schema' => $schema,
				),
			),
			// A safety-classifier decline is retried server-side on the model
			// Anthropic recommends for that category, instead of failing.
			'fallbacks'     => 'default',
		);
		$res  = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => (int) $timeout,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $key,
					'anthropic-version' => self::API_VERSION,
					'anthropic-beta'    => 'server-side-fallback-2026-07-01',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'pnchat_ai_http', 'Δεν έγινε σύνδεση με το Claude API: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'HTTP ' . $code;
			$map = array(
				401 => 'Το API key δεν είναι σωστό ή ακυρώθηκε.',
				403 => 'Το API key δεν έχει δικαίωμα για αυτό το μοντέλο.',
				429 => 'Πολλές κλήσεις ή τελείωσε το υπόλοιπο του λογαριασμού. Δοκιμάστε σε λίγο.',
				529 => 'Το Claude API είναι προσωρινά υπερφορτωμένο. Δοκιμάστε σε λίγο.',
			);
			return new WP_Error( 'pnchat_ai_status', ( $map[ $code ] ?? 'Σφάλμα του Claude API.' ) . ' (' . $msg . ')' );
		}
		self::add_usage( is_array( $data['usage'] ?? null ) ? $data['usage'] : array() );

		$stop = (string) ( $data['stop_reason'] ?? '' );
		if ( 'refusal' === $stop ) {
			return new WP_Error( 'pnchat_ai_refusal', 'Το Claude αρνήθηκε να απαντήσει σε αυτό το αίτημα.' );
		}
		if ( 'max_tokens' === $stop ) {
			return new WP_Error( 'pnchat_ai_long', 'Η απάντηση κόπηκε (πολύ μεγάλη). Δοκιμάστε με μικρότερη σελίδα.' );
		}
		// Thinking blocks may come first: read the text block.
		$text = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) $block['text'];
			}
		}
		$json = json_decode( $text, true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'pnchat_ai_json', 'Το Claude δεν επέστρεψε έγκυρη απάντηση. Δοκιμάστε ξανά.' );
		}
		return $json;
	}

	/**
	 * Adds a call's tokens to the running total shown in the settings.
	 *
	 * @param array<string,mixed> $usage API usage object.
	 * @return void
	 */
	private static function add_usage( array $usage ) {
		$u = get_option( self::USAGE_OPTION, array() );
		$u = is_array( $u ) ? $u : array();
		foreach ( array( 'input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens' ) as $k ) {
			$u[ $k ] = (int) ( $u[ $k ] ?? 0 ) + (int) ( $usage[ $k ] ?? 0 );
		}
		$u['calls'] = (int) ( $u['calls'] ?? 0 ) + 1;
		update_option( self::USAGE_OPTION, $u, false );
	}

	/**
	 * Running totals.
	 *
	 * @return array<string,int>
	 */
	public static function usage() {
		$u = get_option( self::USAGE_OPTION, array() );
		return is_array( $u ) ? array_map( 'intval', $u ) : array();
	}

	/**
	 * Schema of one drafted entry.
	 *
	 * @return array<string,mixed>
	 */
	private static function entry_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'      => array( 'type' => 'string' ),
				'phrasings'  => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'keywords'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'answer'     => array( 'type' => 'string' ),
				'source_url' => array( 'type' => 'string' ),
			),
			'required'             => array( 'title', 'phrasings', 'keywords', 'answer', 'source_url' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Rules shared by both prompts.
	 *
	 * @return string
	 */
	private static function rules() {
		return "Γράφεις γνώσεις για το chat του site " . home_url() . ". Το chat απαντά σε φαρμακεία και επισκέπτες.\n"
			. "Κανόνες:\n"
			. "- Χρησιμοποίησε ΜΟΝΟ πληροφορίες που υπάρχουν στις σελίδες που σου δίνονται. Μην προσθέτεις τίποτα από δικές σου γνώσεις, ούτε τιμές, ημερομηνίες ή αριθμούς που δεν γράφουν οι σελίδες.\n"
			. "- Μην δίνεις ιατρικές συμβουλές, δοσολογίες ή οδηγίες λήψης φαρμάκων, ακόμη κι αν υπάρχουν στη σελίδα.\n"
			. "- Γράφεις στα ελληνικά, σύντομα και καθαρά (1–4 προτάσεις ή μια μικρή λίστα).\n"
			. "- answer: απλό HTML μόνο με <p>, <strong>, <ul>, <ol>, <li>, <a href>. Όπου βοηθά, βάλε link προς τη σελίδα-πηγή.\n"
			. "- phrasings: 4 έως 8 φυσικοί τρόποι που θα ρωτούσε κάποιος (ερωτήσεις, όχι τίτλοι), διαφορετικοί μεταξύ τους.\n"
			. "- keywords: 0 έως 3 λέξεις ή σύντομες φράσεις, μόνο αν είναι πολύ χαρακτηριστικές για αυτή τη γνώση· αλλιώς κενή λίστα.\n"
			. "- title: σύντομος τίτλος για τον διαχειριστή.\n"
			. "- source_url: η διεύθυνση της σελίδας από την οποία πήρες την απάντηση.";
	}

	/**
	 * Pages as text blocks for the prompt.
	 *
	 * @param WP_Post[] $posts Pages.
	 * @param int       $chars Characters per page.
	 * @return string
	 */
	private static function pages_text( array $posts, $chars ) {
		$out = '';
		foreach ( $posts as $i => $p ) {
			$text = PNChat_Site_Search::plain_text( $p );
			$out .= '<page index="' . ( $i + 1 ) . '" url="' . esc_url_raw( (string) get_permalink( $p ) ) . '" title="' . esc_attr( get_the_title( $p ) ) . "\">\n"
				. mb_substr( $text, 0, $chars ) . "\n</page>\n";
		}
		return $out;
	}

	/**
	 * Drafts one entry answering a visitor's question from the site's pages.
	 *
	 * @param string $question Question.
	 * @param string $model    Model id ('' = the setting).
	 * @param int    $timeout  Seconds.
	 * @return array{found:bool,entry:array<string,mixed>,sources:array<int,array{title:string,url:string}>,note:string}|WP_Error
	 */
	public static function draft_for_question( $question, $model = '', $timeout = 180 ) {
		$found = PNChat_Site_Search::search( $question, 4 );
		$posts = array();
		foreach ( $found as $r ) {
			$p = get_post( $r['id'] );
			if ( $p ) {
				$posts[] = $p;
			}
		}
		$sources = array_map(
			fn( $p ) => array(
				'title' => get_the_title( $p ),
				'url'   => (string) get_permalink( $p ),
			),
			$posts
		);
		if ( ! $posts ) {
			return array(
				'found'   => false,
				'entry'   => array(),
				'sources' => array(),
				'note'    => 'Δεν βρέθηκε καμία σχετική σελίδα στο site, οπότε δεν στάλθηκε τίποτα στο Claude.',
			);
		}
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'found' => array( 'type' => 'boolean' ),
				'note'  => array( 'type' => 'string' ),
				'entry' => self::entry_schema(),
			),
			'required'             => array( 'found', 'note', 'entry' ),
			'additionalProperties' => false,
		);
		$system = self::rules() . "\n- Αν οι σελίδες ΔΕΝ απαντούν στην ερώτηση, βάλε found=false, εξήγησε στο note τι λείπει, και άφησε τα πεδία του entry κενά.\n- note: μία πρόταση για τον διαχειριστή.";
		$user   = "Σελίδες του site:\n" . self::pages_text( $posts, self::PAGE_CHARS ) . "\nΕρώτηση επισκέπτη:\n<question>" . $question . "</question>\n\nΓράψε μία γνώση που απαντά στην ερώτηση, μόνο από τις σελίδες. Η ερώτηση του επισκέπτη να είναι η πρώτη από τις phrasings.";
		$json   = self::call( $system, $user, $schema, $model, $timeout );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$entry = PNChat_Brain::clean_entry( $json['entry'] ?? array() );
		return array(
			'found'   => ! empty( $json['found'] ) && null !== $entry,
			'entry'   => $entry ? self::with_source( $entry, (string) ( $json['entry']['source_url'] ?? '' ) ) : array(),
			'sources' => $sources,
			'note'    => sanitize_text_field( (string) ( $json['note'] ?? '' ) ),
		);
	}

	/**
	 * AI answers in the public chat are on and have a key.
	 *
	 * @return bool
	 */
	public static function chat_enabled() {
		return ! empty( PNChat_Settings::value( 'ai_chat' ) ) && '' !== self::api_key();
	}

	/**
	 * Takes one of today's AI chat answers; false when the day's limit is used.
	 *
	 * @return bool
	 */
	public static function chat_take() {
		$key  = 'pnchat_ai_chat_' . gmdate( 'Ymd' );
		$used = (int) get_transient( $key );
		if ( $used >= (int) PNChat_Settings::value( 'ai_chat_daily' ) ) {
			return false;
		}
		set_transient( $key, $used + 1, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * AI chat answers used today.
	 *
	 * @return int
	 */
	public static function chat_used_today() {
		return (int) get_transient( 'pnchat_ai_chat_' . gmdate( 'Ymd' ) );
	}

	/**
	 * Drafts entries covering one page of the site.
	 *
	 * @param int $post_id Page id.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function drafts_from_page( $post_id ) {
		$post = get_post( $post_id );
		if ( ! PNChat_Site_Search::searchable( $post ) ) {
			return new WP_Error( 'pnchat_ai_page', 'Η σελίδα δεν είναι δημόσια ή είναι εξαιρεμένη από την αναζήτηση.' );
		}
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'entries' => array(
					'type'  => 'array',
					'items' => self::entry_schema(),
				),
			),
			'required'             => array( 'entries' ),
			'additionalProperties' => false,
		);
		$system = self::rules() . "\n- Γράψε έως 8 γνώσεις για τα πιο χρήσιμα θέματα της σελίδας, μία για κάθε διαφορετική ερώτηση που θα έκανε ένα φαρμακείο. Αν η σελίδα δεν έχει χρήσιμη πληροφορία, επέστρεψε κενή λίστα.";
		$titles = array_map( fn( $e ) => $e['title'], PNChat_Store::entries( 'answer' ) );
		$user   = "Σελίδα του site:\n" . self::pages_text( array( $post ), self::SOURCE_CHARS )
			. "\nΓνώσεις που υπάρχουν ήδη (μην τις επαναλάβεις):\n" . ( $titles ? '- ' . implode( "\n- ", $titles ) : '(καμία)' )
			. "\n\nΓράψε τις γνώσεις.";
		$json   = self::call( $system, $user, $schema );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$out = array();
		foreach ( array_slice( (array) ( $json['entries'] ?? array() ), 0, 8 ) as $raw ) {
			$e = PNChat_Brain::clean_entry( $raw );
			if ( $e ) {
				$out[] = self::with_source( $e, (string) get_permalink( $post ) );
			}
		}
		return $out;
	}

	/**
	 * Adds a link to the source page when the answer has none, and keeps
	 * links pointing to this site only.
	 *
	 * @param array<string,mixed> $e   Clean entry.
	 * @param string              $url Source page.
	 * @return array<string,mixed>
	 */
	private static function with_source( array $e, $url ) {
		$host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$answer = (string) preg_replace_callback(
			'#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
			function ( $m ) use ( $host ) {
				$h = wp_parse_url( $m[1], PHP_URL_HOST );
				return ( $h && $h !== $host ) ? $m[2] : $m[0];
			},
			(string) $e['answer']
		);
		$url_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $url && $url_host === $host && false === stripos( $answer, '<a ' ) ) {
			$answer .= '<p><a href="' . esc_url( $url ) . '">Περισσότερα εδώ</a></p>';
		}
		$e['answer'] = wp_kses( $answer, PNChat_Brain::allowed_html() );
		return $e;
	}
}
