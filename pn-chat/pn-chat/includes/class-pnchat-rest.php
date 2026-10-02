<?php
/**
 * Public REST endpoints of the chat: ask, leave an e-mail, give feedback.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API.
 */
final class PNChat_Rest {

	const NS           = 'pn-chat/v1';
	const MAX_QUESTION = 500;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			self::NS,
			'/ask',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ask' ),
				'permission_callback' => array( __CLASS__, 'can_chat' ),
				'args'                => array(
					'question' => array(
						'type'     => 'string',
						'required' => true,
					),
					'page'     => array(
						'type' => 'string',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/email',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'email' ),
				'permission_callback' => array( __CLASS__, 'can_chat' ),
				'args'                => array(
					'id'    => array(
						'type'     => 'integer',
						'required' => true,
					),
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
					'email' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'feedback' ),
				'permission_callback' => array( __CLASS__, 'can_chat' ),
				'args'                => array(
					'id'      => array(
						'type'     => 'integer',
						'required' => true,
					),
					'token'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'helpful' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The chat is on, and the visitor may use it.
	 *
	 * @return true|WP_Error
	 */
	public static function can_chat() {
		$s = PNChat_Settings::get();
		if ( empty( $s['enabled'] ) ) {
			return new WP_Error( 'pnchat_off', 'Το chat δεν είναι διαθέσιμο.', array( 'status' => 403 ) );
		}
		if ( 'logged_in' === $s['visibility'] && ! is_user_logged_in() ) {
			return new WP_Error( 'pnchat_login', 'Συνδεθείτε για να χρησιμοποιήσετε το chat.', array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Answers a question.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function ask( WP_REST_Request $req ) {
		$question = trim( sanitize_textarea_field( (string) $req->get_param( 'question' ) ) );
		if ( '' === $question ) {
			return new WP_Error( 'pnchat_empty', 'Γράψτε μια ερώτηση.', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $question ) > self::MAX_QUESTION ) {
			return new WP_Error( 'pnchat_long', 'Η ερώτηση είναι πολύ μεγάλη (έως ' . self::MAX_QUESTION . ' χαρακτήρες).', array( 'status' => 400 ) );
		}
		$s = PNChat_Settings::get();
		if ( ! self::rate_ok( 'ask', (int) $s['rate_per_10min'], 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'pnchat_rate', 'Πολλές ερωτήσεις σε λίγο χρόνο. Δοκιμάστε ξανά σε λίγα λεπτά.', array( 'status' => 429 ) );
		}

		$result = PNChat_Brain::matcher()->ask( $question );
		$site   = self::site_results( $result, $question );
		if ( $site && 'unanswered' === $result['status'] ) {
			$result['status'] = 'site';
		}

		$items   = array();
		$matched = array();
		foreach ( $result['items'] as $i ) {
			$matched[] = (int) $i['id'];
			$items[]   = array(
				'kind'  => $i['kind'],
				'title' => 'answer' === $i['kind'] ? $i['title'] : '',
				'html'  => PNChat_Brain::render_answer( $i['answer'] ),
			);
		}
		PNChat_Store::count_hits( $matched );
		foreach ( $site as $r ) {
			$items[] = array(
				'kind'  => 'site',
				'title' => $r['title'],
				'html'  => PNChat_Site_Search::render( $r ),
			);
		}

		$token = wp_generate_password( 32, false );
		$page  = esc_url_raw( (string) $req->get_param( 'page' ) );
		$id    = PNChat_Store::log_question(
			array(
				'question'   => $question,
				'status'     => $result['status'],
				'matched'    => $matched,
				'unmatched'  => $result['unmatched'],
				'user_id'    => get_current_user_id(),
				'token_hash' => hash( 'sha256', $token ),
				'page_url'   => wp_http_validate_url( $page ) ? $page : '',
			)
		);

		$message = '';
		$intro   = '';
		if ( 'unanswered' === $result['status'] ) {
			$message = (string) $s['fallback'];
		} elseif ( 'site' === $result['status'] ) {
			$intro   = (string) $s['site_intro'];
			$message = (string) $s['site_more'];
		} elseif ( 'partial' === $result['status'] ) {
			$message = $site ? (string) $s['site_more'] : sprintf( (string) $s['partial'], implode( '», «', $result['unmatched'] ) );
		}

		return rest_ensure_response(
			array(
				'id'         => $id,
				'token'      => $id ? $token : '',
				'status'     => $result['status'],
				'items'      => $items,
				'intro'      => $intro,
				'message'    => $message,
				'ask_email'  => $id && in_array( $result['status'], array( 'unanswered', 'partial', 'site' ), true ),
				'feedback'   => ! empty( $s['feedback'] ) && $id && in_array( $result['status'], array( 'answered', 'partial', 'site' ), true ),
				'user_email' => self::user_email(),
			)
		);
	}

	/**
	 * Stores the visitor's e-mail for a question.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function email( WP_REST_Request $req ) {
		// Honeypot: real visitors never see the "website" field.
		if ( '' !== trim( (string) $req->get_param( 'website' ) ) ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}
		$q = self::owned_question( $req );
		if ( is_wp_error( $q ) ) {
			return $q;
		}
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'pnchat_email', 'Το e-mail δεν είναι σωστό.', array( 'status' => 400 ) );
		}
		if ( ! self::rate_ok( 'email', 10, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'pnchat_rate', 'Πολλές αποστολές. Δοκιμάστε ξανά αργότερα.', array( 'status' => 429 ) );
		}
		$name   = mb_substr( sanitize_text_field( (string) $req->get_param( 'name' ) ), 0, 190 );
		$status = in_array( $q['status'], PNChat_Store::open_statuses(), true ) ? $q['status'] : 'unanswered';
		PNChat_Store::update_question(
			(int) $q['id'],
			array(
				'email'  => $email,
				'name'   => $name,
				'status' => $status,
			)
		);
		self::notify_admin( (int) $q['id'], (string) $q['question'], $email, $name );

		$s = PNChat_Settings::get();
		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => sprintf( (string) $s['email_thanks'], $email ),
			)
		);
	}

	/**
	 * "Did this help?" buttons.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function feedback( WP_REST_Request $req ) {
		$q = self::owned_question( $req );
		if ( is_wp_error( $q ) ) {
			return $q;
		}
		$helpful = rest_sanitize_boolean( $req->get_param( 'helpful' ) );
		if ( ! $helpful && in_array( $q['status'], array( 'answered', 'partial', 'site' ), true ) ) {
			PNChat_Store::update_question( (int) $q['id'], array( 'status' => 'unhelpful' ) );
		}
		$s = PNChat_Settings::get();
		return rest_ensure_response(
			array(
				'ok'        => true,
				'ask_email' => ! $helpful,
				'message'   => $helpful ? 'Ευχαριστούμε!' : (string) $s['unhelpful'],
			)
		);
	}

	/**
	 * Pages of the site for what the brain could not answer (the whole
	 * question, or its unanswered parts). Never for a refused question.
	 *
	 * @param array<string,mixed> $result   Matcher result.
	 * @param string              $question Question.
	 * @return array<int,array<string,mixed>>
	 */
	public static function site_results( array $result, $question ) {
		$s = PNChat_Settings::get();
		if ( empty( $s['site_search'] ) || ! in_array( $result['status'], array( 'unanswered', 'partial' ), true ) ) {
			return array();
		}
		$text = 'partial' === $result['status'] ? implode( ' ', $result['unmatched'] ) : $question;
		return PNChat_Site_Search::search( $text, (int) $s['site_max'] );
	}

	/**
	 * The question the request's token belongs to.
	 *
	 * @param WP_REST_Request $req Request with id and token.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function owned_question( WP_REST_Request $req ) {
		$q     = PNChat_Store::question( absint( $req->get_param( 'id' ) ) );
		$token = (string) $req->get_param( 'token' );
		if ( ! $q || '' === $token || '' === (string) $q['token_hash'] || ! hash_equals( (string) $q['token_hash'], hash( 'sha256', $token ) ) ) {
			return new WP_Error( 'pnchat_token', 'Η ερώτηση δεν βρέθηκε. Ρωτήστε ξανά.', array( 'status' => 404 ) );
		}
		return $q;
	}

	/**
	 * Logged-in user's e-mail, to prefill the form.
	 *
	 * @return string
	 */
	private static function user_email() {
		$u = wp_get_current_user();
		return ( $u && $u->exists() ) ? (string) $u->user_email : '';
	}

	/**
	 * Fixed-window rate limit per visitor (IP hash, or user id).
	 *
	 * @param string $bucket Name.
	 * @param int    $limit  Requests per window.
	 * @param int    $window Seconds.
	 * @return bool
	 */
	private static function rate_ok( $bucket, $limit, $window ) {
		if ( current_user_can( PNChat_Admin::capability() ) ) {
			return true;
		}
		$who = get_current_user_id() ? 'u' . get_current_user_id() : 'ip' . ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		$key = 'pnchat_rl_' . $bucket . '_' . substr( hash_hmac( 'sha256', $who, wp_salt( 'nonce' ) ), 0, 24 );
		$hit = get_transient( $key );
		$n   = is_array( $hit ) ? (int) $hit['n'] : 0;
		$exp = is_array( $hit ) ? (int) $hit['exp'] : time() + $window;
		if ( $n >= $limit && $exp > time() ) {
			return false;
		}
		if ( $exp <= time() ) {
			$n   = 0;
			$exp = time() + $window;
		}
		set_transient(
			$key,
			array(
				'n'   => $n + 1,
				'exp' => $exp,
			),
			max( 1, $exp - time() )
		);
		return true;
	}

	/**
	 * Tells the administrators that someone is waiting for an answer.
	 *
	 * @param int    $id       Question id.
	 * @param string $question Question.
	 * @param string $email    Visitor e-mail.
	 * @param string $name     Visitor name.
	 * @return void
	 */
	private static function notify_admin( $id, $question, $email, $name ) {
		$s = PNChat_Settings::get();
		if ( empty( $s['notify_on_email'] ) || ! is_email( (string) $s['notify_email'] ) ) {
			return;
		}
		$link = admin_url( 'admin.php?page=pn-chat-questions&filter=email#q-' . $id );
		$body = "Νέα ερώτηση στο PN Chat περιμένει απάντηση με e-mail.\n\n"
			. 'Ερώτηση: ' . $question . "\n"
			. 'Από: ' . ( '' !== $name ? $name . ' ' : '' ) . '<' . $email . ">\n\n"
			. 'Απαντήστε ή εκπαιδεύστε τον βοηθό εδώ: ' . $link . "\n";
		wp_mail( (string) $s['notify_email'], '[PN Chat] Νέα ερώτηση χωρίς απάντηση', $body, array( 'Reply-To: ' . $email ) );
	}
}
