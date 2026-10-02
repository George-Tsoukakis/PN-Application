<?php
/**
 * Claude, both off by default:
 * - training assistant (admin): reads pages of THIS site and drafts
 *   knowledge entries that an administrator reviews before they are saved;
 * - AI answers in the chat («ai_chat»): when no trained answer fits but
 *   pages of the site are about the question, Claude answers from those
 *   pages; the answer is labelled and waits in Ερωτήματα for review.
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
	// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- see above; key check only.
	const MODELS_URL    = 'https://api.anthropic.com/v1/models?limit=1';
	const API_VERSION   = '2023-06-01';
	const DEFAULT_MODEL = 'claude-opus-5-5';
	const USAGE_OPTION  = 'pnchat_ai_usage'; // Before 1.8.0; now counters «usage:…».
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
	 * The key out of whatever was pasted: the bare key, or a line such as
	 * `x-api-key: sk-ant-…` or `'sk-ant-…'`. '' when there is none.
	 *
	 * @param string $raw Pasted text.
	 * @return string
	 */
	public static function extract_key( $raw ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '/sk-ant-[A-Za-z0-9_\-]{20,300}/', $raw, $m ) ) {
			return $m[0];
		}
		return preg_match( '/^[A-Za-z0-9_\-]{20,300}$/', $raw ) ? $raw : '';
	}

	/**
	 * Asks Anthropic whether a key works (listing models costs nothing).
	 *
	 * @param string $key API key.
	 * @return bool|null True: works; false: rejected (401/403); null: unknown (no connection, other error).
	 */
	public static function check_key( $key ) {
		$res = wp_remote_get(
			self::MODELS_URL,
			array(
				'timeout' => 15,
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => self::API_VERSION,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 === $code ) {
			return true;
		}
		return in_array( $code, array( 401, 403 ), true ) ? false : null;
	}

	/**
	 * Where the key in use comes from, for error messages.
	 *
	 * @return string
	 */
	public static function key_source() {
		return self::key_from_constant()
			? 'το κλειδί ' . self::key_hint() . ' του wp-config.php (PNCHAT_ANTHROPIC_API_KEY· υπερισχύει των Ρυθμίσεων)'
			: 'το κλειδί ' . self::key_hint() . ' των Ρυθμίσεων';
	}

	/**
	 * Calls the Messages API and returns the decoded JSON answer.
	 *
	 * @param string                    $system     System prompt.
	 * @param string|array<int,mixed>   $user       User message: text, or content blocks (a PDF document and text).
	 * @param array<string,mixed>       $schema     JSON schema of the answer.
	 * @param string                    $model      Model id ('' = the setting).
	 * @param int                       $timeout    Seconds.
	 * @param int                       $max_tokens Longest answer.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function call( $system, $user, array $schema, $model = '', $timeout = 180, $max_tokens = 16000 ) {
		$key = self::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'pnchat_ai_key', 'Δεν έχει οριστεί API key (Ρυθμίσεις → AI βοηθός εκπαίδευσης).' );
		}
		$model = '' !== $model ? $model : self::model();
		$body  = array(
			'model'         => $model,
			'max_tokens'    => (int) $max_tokens,
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
		$headers = array(
			'content-type'      => 'application/json',
			'x-api-key'         => $key,
			'anthropic-version' => self::API_VERSION,
			'anthropic-beta'    => 'server-side-fallback-2026-07-01',
		);
		// Claude Haiku 4.5 takes neither the effort setting nor server-side
		// fallbacks (no safety classifiers to fall back from).
		if ( 0 === strpos( $model, 'claude-haiku-' ) ) {
			unset( $body['output_config']['effort'], $body['fallbacks'], $headers['anthropic-beta'] );
		}
		$res  = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => (int) $timeout,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			// Only errors before the request left the site prove that Claude
			// did no work. A time-out or a lost answer may have been charged.
			$not_sent = self::never_sent( $res );
			return new WP_Error(
				'pnchat_ai_http',
				( $not_sent ? 'Δεν έγινε σύνδεση με το Claude API: ' : 'Δεν ήρθε απάντηση από το Claude API (η κλήση μπορεί να χρεώθηκε): ' ) . $res->get_error_message(),
				array( 'not_sent' => $not_sent )
			);
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
			if ( 401 === $code ) {
				return new WP_Error( 'pnchat_ai_status', 'Η Anthropic απέρριψε ' . self::key_source() . ': είναι λάθος ή ακυρώθηκε. Φτιάξτε νέο κλειδί στο console.anthropic.com → API keys και βάλτε το στο PN Chat → Ρυθμίσεις → API key. (' . $msg . ')' );
			}
			return new WP_Error( 'pnchat_ai_status', ( $map[ $code ] ?? 'Σφάλμα του Claude API.' ) . ' (' . $msg . ')' );
		}
		self::add_usage( is_array( $data['usage'] ?? null ) ? $data['usage'] : array(), $model );

		$stop = (string) ( $data['stop_reason'] ?? '' );
		if ( 'refusal' === $stop ) {
			return new WP_Error( 'pnchat_ai_refusal', 'Το Claude αρνήθηκε να απαντήσει σε αυτό το αίτημα.' );
		}
		if ( 'max_tokens' === $stop ) {
			return new WP_Error( 'pnchat_ai_long', 'Η απάντηση κόπηκε (πολύ μεγάλη). Δοκιμάστε με μικρότερη σελίδα ή μικρότερο PDF.' );
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
	 * The HTTP error happened before the request reached Anthropic: blocked
	 * by WordPress, or the name, the proxy, the connection or TLS failed.
	 *
	 * @param WP_Error $error Error of wp_remote_post().
	 * @return bool
	 */
	public static function never_sent( WP_Error $error ) {
		if ( 'http_request_not_executed' === $error->get_error_code() ) {
			return true;
		}
		// cURL 5/6: proxy or host name not resolved, 7: no connection,
		// 35/60/77: TLS handshake or certificate. Anything else (28 time-out,
		// 52 empty reply, 56 lost connection…) may come after the request.
		return 1 === preg_match( '/cURL error (5|6|7|35|60|77):/', $error->get_error_message() );
	}

	/**
	 * Adds a call's tokens to the running total shown in the settings. Each
	 * number is its own atomic counter, so parallel calls all count.
	 *
	 * @param array<string,mixed> $usage API usage object.
	 * @param string              $model Model id.
	 * @return void
	 */
	private static function add_usage( array $usage, $model = '' ) {
		$add = array( 'calls' => 1 );
		foreach ( array( 'input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens' ) as $k ) {
			$add[ $k ] = (int) ( $usage[ $k ] ?? 0 );
		}
		// Cost at list price, per call, so mixed models add up correctly.
		// Cache writes cost 1.25 times the input price.
		$p = self::prices( (string) $model );
		if ( $p ) {
			$usd              = $add['input_tokens'] * $p[0] / 1e6
				+ $add['cache_creation_input_tokens'] * $p[0] * 1.25 / 1e6
				+ $add['output_tokens'] * $p[1] / 1e6
				+ $add['cache_read_input_tokens'] * $p[2] / 1e6;
			$add['micro_usd'] = (int) round( $usd * 1e6 );
		} else {
			$add['unpriced'] = 1;
		}
		foreach ( $add as $k => $v ) {
			if ( $v > 0 ) {
				PNChat_Counter::add( 'usage:' . $k, $v );
			}
		}
	}

	/**
	 * Moves the totals kept in an option before 1.8.0 into the counters.
	 *
	 * @return void
	 */
	public static function migrate_usage() {
		$old = get_option( self::USAGE_OPTION );
		if ( ! is_array( $old ) ) {
			return;
		}
		foreach ( $old as $k => $v ) {
			if ( (int) $v > 0 && preg_match( '/^[a-z_]+$/', (string) $k ) ) {
				PNChat_Counter::add( 'usage:' . $k, (int) $v );
			}
		}
		delete_option( self::USAGE_OPTION );
	}

	/**
	 * List prices per million tokens: input, output, cache read (USD).
	 *
	 * @param string $model Model id.
	 * @return array{0:float,1:float,2:float}|null
	 */
	public static function prices( $model ) {
		$table = array(
			'claude-opus-5-5'   => array( 4.0, 20.0, 0.2 ),
			'claude-sonnet-5-5' => array( 2.0, 10.0, 0.2 ),
			'claude-haiku-4-5'  => array( 1.0, 5.0, 0.1 ),
		);
		return $table[ $model ] ?? null;
	}

	/**
	 * Running totals.
	 *
	 * @return array<string,int>
	 */
	public static function usage() {
		return PNChat_Counter::all( 'usage:' );
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
		// Without personal details, for the search and for Claude.
		$question = self::redact( $question );
		$found    = PNChat_Site_Search::search( $question, 4 );
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
				'medical_advice' => array( 'type' => 'boolean' ),
				'found'          => array( 'type' => 'boolean' ),
				'note'           => array( 'type' => 'string' ),
				'entry'          => self::entry_schema(),
			),
			'required'             => array( 'medical_advice', 'found', 'note', 'entry' ),
			'additionalProperties' => false,
		);
		$system = self::rules() . "\n- Αν οι σελίδες ΔΕΝ απαντούν στην ερώτηση, βάλε found=false, εξήγησε στο note τι λείπει, και άφησε τα πεδία του entry κενά.\n- medical_advice: true αν η ερώτηση ζητά ιατρική ή φαρμακευτική συμβουλή (ποιο φάρμακο, δόση, παρενέργειες, συμπτώματα, συνδυασμοί, εγκυμοσύνη κ.λπ.), false αν αφορά τη χρήση του site ή των εργαλείων του (π.χ. πώς βάζω ένα φάρμακο σε πλάνο ή τυπώνω ετικέτα). Αν είναι true, βάλε found=false, ό,τι κι αν γράφουν οι σελίδες.\n- source_url: μία από τις διευθύνσεις των σελίδων που σου δόθηκαν.\n- note: μία πρόταση για τον διαχειριστή.";
		$user   = "Σελίδες του site:\n" . self::pages_text( $posts, self::PAGE_CHARS ) . "\nΕρώτηση επισκέπτη:\n<question>" . $question . "</question>\n\nΓράψε μία γνώση που απαντά στην ερώτηση, μόνο από τις σελίδες. Η ερώτηση του επισκέπτη να είναι η πρώτη από τις phrasings.";
		$json   = self::call( $system, $user, $schema, $model, $timeout );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$entry = PNChat_Brain::clean_entry( $json['entry'] ?? array() );
		// The source must be one of the pages sent; otherwise the best match.
		$urls   = array_column( $sources, 'url' );
		$source = (string) ( $json['entry']['source_url'] ?? '' );
		$source = in_array( $source, $urls, true ) ? $source : (string) ( $urls[0] ?? '' );
		// Second check, by the model itself: a medical question gets no
		// answer, even if the word lists let it through.
		$medical = ! empty( $json['medical_advice'] );
		if ( $medical ) {
			$json['found'] = false;
		}
		return array(
			'found'   => ! empty( $json['found'] ) && null !== $entry,
			'entry'   => $entry ? self::with_source( $entry, $source ) : array(),
			'sources' => $sources,
			'note'    => $medical ? 'Το AI έκρινε ότι η ερώτηση ζητά ιατρική συμβουλή, οπότε δεν απάντησε.' : sanitize_text_field( (string) ( $json['note'] ?? '' ) ),
			'medical' => $medical,
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
	 * Today's counter of AI calls from the chat.
	 *
	 * @return string
	 */
	private static function day_key() {
		return 'ai_chat:' . gmdate( 'Ymd' );
	}

	/**
	 * Takes one of today's AI chat calls; false when the day's limit is
	 * used. Atomic, so parallel visitors cannot go over the limit. Every
	 * call that reaches Claude counts, also when it finds no answer.
	 *
	 * @return bool
	 */
	public static function chat_take() {
		return PNChat_Counter::take( self::day_key(), (int) PNChat_Settings::value( 'ai_chat_daily' ), 2 * DAY_IN_SECONDS, false );
	}

	/**
	 * Gives today's call back (the request never reached Claude).
	 *
	 * @return void
	 */
	public static function chat_give_back() {
		PNChat_Counter::give_back( self::day_key() );
	}

	/**
	 * Claude certainly did no work, so nothing was charged: no key, an
	 * error status from the API, or a request that never left the site.
	 * A time-out is uncertain and keeps counting.
	 *
	 * @param WP_Error $error Error of call().
	 * @return bool
	 */
	public static function not_charged( WP_Error $error ) {
		$code = $error->get_error_code();
		if ( 'pnchat_ai_http' === $code ) {
			$data = $error->get_error_data();
			return is_array( $data ) && ! empty( $data['not_sent'] );
		}
		return in_array( $code, array( 'pnchat_ai_key', 'pnchat_ai_status' ), true );
	}

	/**
	 * AI chat calls used today.
	 *
	 * @return int
	 */
	public static function chat_used_today() {
		return PNChat_Counter::get( self::day_key() );
	}

	/**
	 * Words and phrases that ask for medical advice: such a question never
	 * goes to the AI. Greek as typed (accents and Greeklish are folded); a
	 * word matches itself and the longer words it begins («παρενέργει» →
	 * παρενέργεια, παρενέργειες); a phrase matches word by word.
	 * Filter «pnchat_ai_medical_terms».
	 *
	 * @return string[]
	 */
	public static function medical_terms() {
		return (array) apply_filters(
			'pnchat_ai_medical_terms',
			array(
				// Asking how much to take.
				// Not «τι δόση»: folded it reads like «τη δόση» (the article).
				'πόση δόση', 'ποια δόση', 'πόση δοσολογία', 'ποια δοσολογία', 'πόσα mg', 'πόσο mg', 'πόσα ml', 'πόσο ml', 'πόσα χάπια', 'πόσες σταγόνες', 'πόσο σιρόπι',
				// Asking what to take, or what not to take together.
				'τι να πάρω για', 'να μην πάρω', 'πάρω μαζί', 'πάρει μαζί', 'κάνει να πάρω', 'ξέχασα μια δόση', 'ξέχασα τη δόση', 'διπλή δόση',
				// Effects, conditions, symptoms.
				'παρενέργει', 'αλληλεπίδρ', 'αντένδειξ', 'πυρετ', 'πόνο', 'πονάει', 'πονάω', 'σύμπτωμ', 'διάγνωσ', 'εγκυμοσύν', 'έγκυος', 'θηλασμ', 'αλλεργί', 'βήχα', 'ζάχαρο', 'λοίμωξ',
				'κρυολόγ', 'γρίπη', 'διάρροι', 'δυσκοιλιότ', 'ναυτί', 'στομάχ', 'αϋπνί', 'ημικρανί', 'αντιπηκτ', 'άρρωστ', 'αρρώστι',
			)
		);
	}

	/**
	 * Medicine words that are about the site's tools as often as about
	 * advice («ετικέτες φαρμάκων», «πλάνο δοσολογίας»): medical only when
	 * the question is not about a tool of the site (see tool_context()).
	 * Filter «pnchat_ai_medicine_words».
	 *
	 * @return string[]
	 */
	public static function medicine_words() {
		return (array) apply_filters(
			'pnchat_ai_medicine_words',
			array( 'δοσολογ', 'δόση', 'δόσεις', 'φάρμακο', 'φάρμακα', 'φαρμάκου', 'φαρμάκων', 'χάπι', 'χάπια', 'σιρόπι', 'σταγόνες', 'αντιβίωσ', 'παυσίπον', 'αμπούλ', 'ένεση', 'ενέσεις' )
		);
	}

	/**
	 * Phrases that turn a medicine word into a request for advice even in a
	 * tool question («Τι φάρμακο να πάρει η μητέρα μου; Έχω PlanDose»).
	 * Filter «pnchat_ai_advice_phrases».
	 *
	 * @return string[]
	 */
	public static function advice_phrases() {
		return (array) apply_filters(
			'pnchat_ai_advice_phrases',
			array( 'τι φάρμακο', 'ποιο φάρμακο', 'ποια φάρμακα', 'τι χάπι', 'ποιο χάπι', 'κάνει για', 'βοηθάει', 'βοηθά', 'ασφαλές', 'ασφαλής', 'να πάρει', 'να πιει', 'να πίνει', 'να πάρω', 'να πίνω', 'να δώσω', 'πόσο συχνά', 'πριν το φαγητό', 'μετά το φαγητό', 'κόψω το χάπι' )
		);
	}

	/**
	 * Words of using the site's tools. With a topic of the chat (PlanDose,
	 * QR ReBuilder…) they make a medicine word a tool question.
	 * Filter «pnchat_ai_tool_words».
	 *
	 * @return string[]
	 */
	public static function tool_words() {
		return (array) apply_filters(
			'pnchat_ai_tool_words',
			array( 'εκτυπ', 'τυπών', 'ετικέτ', 'πλάνο', 'πλάνα', 'ημερολόγ', 'εφαρμογ', 'εργαλεί', 'λογαριασμ', 'εγγραφ', 'συνδρομ', 'κουμπί', 'σελίδα', 'ρύθμισ', 'καταχωρ', 'προσθέτ', 'προσθήκ', 'σβήν', 'διαγραφ', 'αλλάζ', 'αλλαγ', 'qr', 'pdf', 'print', 'barcode', 'σάρωσ', 'σκαν', 'αλλάξ', 'υπενθύμ', 'κινητ', 'ειδοποί', 'αποθήκευ', 'στείλ', 'email', 'mail' )
		);
	}

	/**
	 * The question asks for medical advice (see medical_terms()), or names
	 * an amount with a unit («500mg», «5 ml»), or uses a medicine word
	 * without being about a tool of the site, or with an advice phrase
	 * («τι φάρμακο», «κάνει για», «ασφαλές») even in a tool question.
	 *
	 * Examples: «Πόσα χάπια ντεπόν την ημέρα;», «παρενέργειες ibuprofen»,
	 * «δοσολογία παρακεταμόλης για παιδιά» are medical; «Πώς εκτυπώνω
	 * ετικέτες φαρμάκων στο PlanDose;», «Πώς φτιάχνω πλάνο δοσολογίας;» are not.
	 *
	 * @param string $question Question.
	 * @return bool
	 */
	public static function is_medical( $question ) {
		if ( preg_match( '/\d\s*(?:mg|mcg|μg|ml|iu)(?![\p{L}])/iu', (string) $question ) ) {
			return true;
		}
		$words = explode( ' ', PNChat_Text::fold( $question ) );
		if ( self::has_any( $words, self::medical_terms() ) ) {
			return true;
		}
		if ( ! self::has_any( $words, self::medicine_words() ) ) {
			return false;
		}
		return ! self::tool_context( $question, $words ) || self::has_any( $words, self::advice_phrases() );
	}

	/**
	 * The question is about a tool of the site: it names a topic of the chat
	 * or uses a tool word.
	 *
	 * @param string   $question Question.
	 * @param string[] $words    Its folded words.
	 * @return bool
	 */
	private static function tool_context( $question, array $words ) {
		return '' !== PNChat_Topics::detect( $question ) || self::has_any( $words, self::tool_words() );
	}

	/**
	 * The question is about the site's tools (a topic of the chat or a tool
	 * word).
	 *
	 * @param string $question Question.
	 * @return bool
	 */
	public static function about_tools( $question ) {
		return self::tool_context( $question, explode( ' ', PNChat_Text::fold( $question ) ) );
	}

	/**
	 * The AI may answer this question in the public chat. A word list cannot
	 * know every medicine or illness (Xanax, κορτιζόνη, κολικοί…), so the AI
	 * answers only what is shown to be about the site's tools and has no
	 * medical sign; everything else gets the site's pages and the e-mail
	 * form, as without AI. Filter «pnchat_ai_tools_only» (false: any
	 * question without a medical sign).
	 *
	 * @param string $question Question.
	 * @return bool
	 */
	public static function may_answer( $question ) {
		if ( self::is_medical( $question ) ) {
			return false;
		}
		return ! apply_filters( 'pnchat_ai_tools_only', true ) || self::about_tools( $question );
	}

	/**
	 * One of the terms is in the folded words (a word matches itself and,
	 * from four letters, the longer words it begins; a phrase word by word).
	 *
	 * @param string[] $words Folded words of the question.
	 * @param string[] $terms Terms as typed.
	 * @return bool
	 */
	private static function has_any( array $words, array $terms ) {
		$n = count( $words );
		foreach ( $terms as $term ) {
			$parts = array_values(
				array_filter(
					explode( ' ', PNChat_Text::fold( (string) $term ) ),
					function ( $p ) {
						return '' !== $p;
					}
				)
			);
			$k     = count( $parts );
			for ( $i = 0; $k && $i + $k <= $n; $i++ ) {
				$all = true;
				foreach ( $parts as $j => $p ) {
					$w = $words[ $i + $j ];
					if ( $w !== $p && ( strlen( $p ) < 4 || 0 !== strpos( $w, $p ) ) ) {
						$all = false;
						break;
					}
				}
				if ( $all ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Personal details typed inside a question, replaced before the text
	 * leaves the site: e-mail addresses, Greek phone numbers and 11-digit
	 * numbers (ΑΜΚΑ). The question's own e-mail and name fields are never sent.
	 *
	 * @param string $text Question.
	 * @return string
	 */
	public static function redact( $text ) {
		$text = (string) preg_replace( '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)+/u', '[e-mail]', (string) $text );
		$text = (string) preg_replace( '/(?<![\d+])(?:(?:\+|00)30[\s.\-]?)?(?:69\d|2\d\d)(?:[\s.\-]?\d){7}(?!\d)/u', '[τηλέφωνο]', $text );
		$text = (string) preg_replace( '/(?<!\d)\d{11}(?!\d)/', '[αριθμός]', $text );
		return (string) apply_filters( 'pnchat_ai_redact', $text );
	}

	/**
	 * Drafts entries from a source: a page of the site, a web page or a PDF
	 * (sent to Claude as a document, so it also reads tables and pictures).
	 *
	 * @param array<string,mixed> $src Source from PNChat_Learn::load_source().
	 * @return array<int,array<string,mixed>>|WP_Error Clean entries.
	 */
	public static function drafts_from_source( array $src ) {
		$is_pdf = '' !== (string) ( $src['pdf'] ?? '' );
		$max    = $is_pdf ? 25 : 8;
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
		$system = self::rules()
			. "\n- Γράψε έως {$max} γνώσεις για τα πιο χρήσιμα θέματα της πηγής, μία για κάθε διαφορετική ερώτηση που θα έκανε ένα φαρμακείο ή ένας επισκέπτης. Αν η πηγή δεν έχει χρήσιμη πληροφορία, επέστρεψε κενή λίστα."
			. "\n- Κάθε ερώτηση (phrasing) να λέει και το θέμα της (π.χ. «Πώς κάνω υποβολή στο eΔΑΠΥ;», όχι σκέτο «Πώς κάνω υποβολή;»), γιατί ο βοηθός ξέρει και άλλα εργαλεία."
			. "\n- keywords: ΠΟΤΕ το όνομα του εργαλείου, του προϊόντος ή του θέματος γενικά (π.χ. «eΔΑΠΥ», «PlanDose»): θα τραβούσε όλες τις ερωτήσεις του θέματος σε αυτή τη γνώση. Μόνο όροι που ανήκουν σε αυτή τη γνώση και σε καμία άλλη."
			. "\n- Μη γράφεις γνώση για κάτι που υπάρχει ήδη στη λίστα με τις γνώσεις που υπάρχουν."
			. ( $is_pdf ? "\n- Η πηγή είναι PDF. source_url: άφησέ το κενό. Σε απάντηση που εξηγεί βήματα, γράψε τα βήματα με τη σειρά (λίστα <ol>)." : '' );
		$titles = array_slice( array_map( fn( $e ) => $e['title'], PNChat_Store::entries( 'answer' ) ), 0, 400 );
		$known  = "Γνώσεις που υπάρχουν ήδη (μην τις επαναλάβεις):\n" . ( $titles ? '- ' . implode( "\n- ", $titles ) : '(καμία)' );
		$about  = 'Πηγή: «' . (string) $src['title'] . '»' . ( '' !== (string) $src['url'] ? ' (' . (string) $src['url'] . ')' : '' );
		if ( $is_pdf ) {
			$user = array(
				array(
					'type'   => 'document',
					'source' => array(
						'type'       => 'base64',
						'media_type' => 'application/pdf',
						'data'       => (string) $src['pdf'],
					),
					'title'  => mb_substr( (string) $src['title'], 0, 200 ),
				),
				array(
					'type' => 'text',
					'text' => $about . "\n\n" . $known . "\n\nΓράψε τις γνώσεις από το PDF.",
				),
			);
		} else {
			$user = $about . "\n<page url=\"" . esc_url_raw( (string) $src['url'] ) . '" title="' . esc_attr( (string) $src['title'] ) . "\">\n"
				. mb_substr( (string) $src['text'], 0, self::SOURCE_CHARS ) . "\n</page>\n\n" . $known . "\n\nΓράψε τις γνώσεις.";
		}
		$json = self::call( $system, $user, $schema, '', 300, $is_pdf ? 24000 : 16000 );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$out = array();
		foreach ( array_slice( (array) ( $json['entries'] ?? array() ), 0, $max ) as $raw ) {
			$e = PNChat_Brain::clean_entry( $raw );
			if ( $e ) {
				$out[] = self::with_source( $e, (string) $src['url'], ! empty( $src['external'] ) );
			}
		}
		return $out;
	}

	/**
	 * Adds a link to the source page when the answer has none, and keeps
	 * links pointing to this site only.
	 *
	 * @param array<string,mixed> $e        Clean entry.
	 * @param string              $url      Source page.
	 * @param bool                $any_host The source is on another site (a URL or PDF the
	 *                                      administrator chose): links to it are kept too.
	 * @return array<string,mixed>
	 */
	public static function with_source( array $e, $url, $any_host = false ) {
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host = $url ? wp_parse_url( $url, PHP_URL_HOST ) : '';
		$hosts    = array_filter( array( $host, $any_host ? $url_host : '' ) );
		$answer   = (string) preg_replace_callback(
			'#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
			function ( $m ) use ( $hosts ) {
				$h = wp_parse_url( $m[1], PHP_URL_HOST );
				return ( $h && ! in_array( $h, $hosts, true ) ) ? $m[2] : $m[0];
			},
			(string) $e['answer']
		);
		if ( $url && in_array( $url_host, $hosts, true ) && false === stripos( $answer, '<a ' ) ) {
			$answer .= '<p><a href="' . esc_url( $url ) . '">Περισσότερα εδώ</a></p>';
		}
		$e['answer'] = wp_kses( $answer, PNChat_Brain::allowed_html() );
		return $e;
	}
}
