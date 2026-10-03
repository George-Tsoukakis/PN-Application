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
					'context'  => array(
						'type' => 'string',
					),
					// 1.9.2: the entry that answered last, the entries already
					// shown in this conversation, the conversation's random id
					// and whether the question was a button («chip»).
					'prev'     => array(
						'type' => 'integer',
					),
					'seen'     => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'conv'     => array(
						'type' => 'string',
					),
					'via'      => array(
						'type' => 'string',
					),
					// 1.10.0: a «Μήπως εννοείτε» button: the entry and the
					// question it was offered for (with that question's token).
					'pick'     => array(
						'type' => 'integer',
					),
					'from'     => array(
						'type' => 'integer',
					),
					'from_token' => array(
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

		// Conversation topic: a question that names no topic («Είναι δωρεάν;»)
		// is read in the topic of the previous answer («…το QR ReBuilder»).
		$context = PNChat_Topics::valid( sanitize_text_field( (string) $req->get_param( 'context' ) ) );

		// Small talk («ωραίο tool», «οκ», «καληνύχτα»): answered naturally,
		// unless a trained entry answers it (e.g. «Ευχαριστώ»). A complaint
		// («δεν με βοήθησες») always gets the e-mail form, even when it is
		// close to an entry (here «…ο βοηθός»).
		// A tapped «Μήπως εννοείτε» button: that entry is the answer, and the
		// visitor's first wording is a lesson for it.
		$picked = self::picked( $req );

		$talk = $picked ? '' : PNChat_Smalltalk::detect( $question );
		if ( '' !== $talk ) {
			$plain = 'complaint' === $talk ? array( 'status' => '' ) : PNChat_Brain::matcher()->ask( $question );
			if ( 'answered' !== $plain['status'] ) {
				return self::smalltalk_response( $talk, $question, $context, $req );
			}
		}
		// «Ωραία, τι κάνει;» is searched as «τι κάνει;» (the remark is not
		// part of the question); the log keeps what the visitor wrote.
		$search  = PNChat_Smalltalk::strip_lead( $question );
		$own     = PNChat_Topics::detect( $search, true );
		$follow  = '' !== $context && '' === $own;
		// The entry that answered last, for «Και πώς την ακυρώνω;». Not when
		// the question names another topic.
		$prev    = absint( $req->get_param( 'prev' ) );
		if ( $prev && '' !== $own && PNChat_Topics::of_entry( $prev ) !== $own ) {
			$prev = 0;
		}
		$result  = $picked ? $picked : self::answer_in_context( $search, $follow ? $context : '', $prev );
		$site    = self::site_results( $result, $follow ? rtrim( $search, " \t?;;.!" ) . ' ' . $context : $search );
		// «Είναι το Aerolin στη λίστα;»: the row of the site's table that
		// names it, even next to a trained answer that does not.
		$rows = self::table_rows( $result, $follow ? rtrim( $search, " \t?;;.!" ) . ' ' . $context : $search );
		if ( $rows ) {
			$shown = array_column( $rows, 'id' );
			$site  = array_values(
				array_filter(
					$site,
					function ( $r ) use ( $shown ) {
						return ! in_array( (int) $r['id'], $shown, true );
					}
				)
			);
		}

		// No trained answer, but pages of the site are about it: the AI may
		// answer from those pages, and its answer waits in Ερωτήματα as a
		// proposed entry for an administrator to approve.
		$ai    = null;
		$asked = $follow ? rtrim( $search, " \t?;;.!" ) . ' (' . $context . ')' : $search;
		if ( 'unanswered' === $result['status'] && $site && PNChat_AI::chat_enabled() && ! self::near_block( $asked ) && self::rate_ok( 'ai', 10, HOUR_IN_SECONDS ) && PNChat_AI::chat_take() ) {
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- the API call may take up to a minute.
			}
			$draft = PNChat_AI::draft_for_question( $asked, (string) $s['ai_chat_model'], 90 );
			if ( is_wp_error( $draft ) && PNChat_AI::not_charged( $draft ) ) {
				// The API was never reached (no connection, key or HTTP
				// error): the day's limit gets the use back.
				PNChat_AI::chat_give_back();
			}
			if ( ! is_wp_error( $draft ) && ! empty( $draft['found'] ) && ! empty( $draft['entry'] ) ) {
				$ai               = $draft;
				$result['status'] = 'ai';
				$site             = array();
			}
		}
		if ( ( $site || $rows ) && 'unanswered' === $result['status'] ) {
			$result['status'] = 'site';
		}

		$items   = array();
		$matched = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'kind'  => 'site',
				'title' => '',
				'html'  => $r['html'],
			);
		}
		foreach ( $result['items'] as $i ) {
			$matched[] = (int) $i['id'];
			$items[]   = array(
				'kind'  => $i['kind'],
				'title' => 'answer' === $i['kind'] ? $i['title'] : '',
				'html'  => PNChat_Brain::render_answer( $i['answer'] ),
			);
		}
		PNChat_Store::count_hits( $matched );
		if ( $ai ) {
			$items[] = array(
				'kind'  => 'ai',
				'title' => '',
				'label' => (string) $s['ai_chat_label'],
				'html'  => PNChat_Brain::render_answer( (string) $ai['entry']['answer'] ),
			);
		}
		foreach ( $site as $r ) {
			$items[] = array(
				'kind'  => 'site',
				'title' => $r['title'],
				'html'  => PNChat_Site_Search::render( $r ),
			);
		}

		// Learning from the conversation: the visitor typed (not a button)
		// a question the chat answered; an unanswered one just before in
		// the same conversation probably meant this entry.
		$conv  = self::conv_hash( (string) $req->get_param( 'conv' ) );
		$first = self::first_answer( $result );
		if ( 'answered' === $result['status'] && $first && ! $picked && '' === (string) $req->get_param( 'via' ) ) {
			$hinted = PNChat_Store::hint_previous( $conv, $first );
			if ( $hinted ) {
				PNChat_Lessons::record( self::wording( PNChat_Store::question( $hinted ) ), $first, $conv, 'rephrase' );
			}
		}

		// Not sure: the closest entries as «Μήπως εννοείτε…;» buttons.
		$dym = array();
		if ( 'unanswered' === $result['status'] && ! empty( $s['didyoumean'] ) ) {
			$dym = PNChat_Lessons::suggestions( $search, $follow ? $context : '' );
		}

		$token = wp_generate_password( 32, false );
		$id    = PNChat_Store::log_question(
			array(
				'question'   => $question,
				'conv'       => $conv,
				'offered'    => array_column( $dym, 'id' ),
				'status'     => $result['status'],
				'matched'    => $matched,
				// Follow-up questions are logged with their topic, so the
				// training form starts from «Είναι δωρεάν; (QR ReBuilder)».
				'unmatched'  => $follow ? array_map(
					function ( $u ) use ( $context ) {
						return $u . ' (' . $context . ')';
					},
					$result['unmatched']
				) : $result['unmatched'],
				'user_id'    => get_current_user_id(),
				'token_hash' => hash( 'sha256', $token ),
				'page_url'   => self::page_url( (string) $req->get_param( 'page' ) ),
				'draft'      => $ai,
			)
		);

		$message = '';
		$intro   = '';
		// Nothing to show: a friendly «don't know yet», the suggested
		// questions again and the e-mail form behind a button, so a remark
		// out of the chat's subjects is not met with a form.
		$button = 'unanswered' === $result['status'] && ! empty( $s['fallback_button'] );
		if ( 'unanswered' === $result['status'] ) {
			$message = $dym ? (string) $s['didyoumean_text'] : (string) $s['fallback'];
		} elseif ( 'ai' === $result['status'] ) {
			$message = (string) $s['site_more'];
		} elseif ( 'site' === $result['status'] ) {
			$intro   = (string) $s['site_intro'];
			$message = (string) $s['site_more'];
		} elseif ( 'partial' === $result['status'] ) {
			$message = $site ? (string) $s['site_more'] : PNChat_Settings::fill( (string) $s['partial'], 'question', implode( '», «', $result['unmatched'] ) );
		}

		return rest_ensure_response(
			array(
				'id'         => $id,
				'token'      => $id ? $token : '',
				'status'     => $result['status'],
				'items'      => $items,
				'intro'      => $intro,
				'topic'      => self::topic_of( $result, $own, $follow ? $context : '' ),
				'entry'      => $first,
				'related'    => 'answered' === $result['status'] ? self::related( $first, (array) $req->get_param( 'seen' ), (int) $s['related_max'] ) : array(),
				'message'    => $message,
				'ask_email'  => $id && ! $button && in_array( $result['status'], array( 'unanswered', 'partial', 'site', 'ai' ), true ),
				'email_button'     => $id && $button,
				'show_suggestions' => $button && ! $dym,
				'didyoumean'       => $dym,
				'feedback'   => ! empty( $s['feedback'] ) && $id && in_array( $result['status'], array( 'answered', 'partial', 'site', 'ai' ), true ),
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
		if ( 'blocked' === $q['status'] ) {
			return new WP_Error( 'pnchat_email_blocked', 'Σε αυτή την ερώτηση δεν μπορούμε να απαντήσουμε με e-mail.', array( 'status' => 400 ) );
		}
		$s      = PNChat_Settings::get();
		$name   = mb_substr( sanitize_text_field( (string) $req->get_param( 'name' ) ), 0, 190 );
		$thanks = PNChat_Settings::fill( (string) $s['email_thanks'], 'email', $email );
		$status = self::status_after_email( (string) $q['status'] );
		// The same address again (a double tap, a page reload): nothing new to
		// store and no second notification.
		if ( strtolower( $email ) === strtolower( (string) $q['email'] ) && $status === $q['status'] ) {
			if ( '' !== $name && $name !== $q['name'] ) {
				PNChat_Store::update_question( (int) $q['id'], array( 'name' => $name ) );
			}
			return rest_ensure_response(
				array(
					'ok'      => true,
					'message' => $thanks,
				)
			);
		}
		if ( ! self::rate_ok( 'email', 10, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'pnchat_rate', 'Πολλές αποστολές. Δοκιμάστε ξανά αργότερα.', array( 'status' => 429 ) );
		}
		$saved = PNChat_Store::update_question(
			(int) $q['id'],
			array(
				'email'  => $email,
				'name'   => $name,
				'status' => $status,
			)
		);
		if ( ! $saved ) {
			return new WP_Error( 'pnchat_email_save', 'Το e-mail δεν αποθηκεύτηκε. Δοκιμάστε ξανά σε λίγο.', array( 'status' => 500 ) );
		}
		self::notify_admin( (int) $q['id'], (string) $q['question'], $email, $name );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => $thanks,
			)
		);
	}

	/**
	 * The reply to small talk. Only a complaint is logged (as «Δεν βοήθησε»,
	 * with the e-mail form): the rest is not a question for the log.
	 *
	 * @param string          $kind     Kind of remark.
	 * @param string          $question Visitor's text.
	 * @param string          $context  Conversation topic.
	 * @param WP_REST_Request $req      Request.
	 * @return WP_REST_Response
	 */
	private static function smalltalk_response( $kind, $question, $context, WP_REST_Request $req ) {
		$id    = 0;
		$token = '';
		if ( 'complaint' === $kind ) {
			$token = wp_generate_password( 32, false );
			$id    = PNChat_Store::log_question(
				array(
					'question'   => $question,
					'status'     => 'unhelpful',
					'unmatched'  => array( '' !== $context ? $question . ' (' . $context . ')' : $question ),
					'user_id'    => get_current_user_id(),
					'token_hash' => hash( 'sha256', $token ),
					'page_url'   => self::page_url( (string) $req->get_param( 'page' ) ),
				)
			);
		}
		return rest_ensure_response(
			array(
				'id'               => $id,
				'token'            => $id ? $token : '',
				'status'           => 'smalltalk',
				'items'            => array(),
				'intro'            => PNChat_Smalltalk::reply( $kind, $context ),
				'topic'            => $context,
				'message'          => '',
				'ask_email'        => (bool) $id,
				'feedback'         => false,
				'user_email'       => self::user_email(),
				'show_suggestions' => 'praise' === $kind || 'ok' === $kind,
			)
		);
	}

	/**
	 * Status of a question once the visitor left an e-mail: open questions,
	 * AI answers waiting for review and trained ones keep theirs (they are
	 * all in «Περιμένουν e-mail»); the rest are open again.
	 *
	 * @param string $status Current status.
	 * @return string
	 */
	private static function status_after_email( $status ) {
		return in_array( $status, array_merge( PNChat_Store::open_statuses(), array( 'ai', 'trained' ) ), true ) ? $status : 'unanswered';
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
		if ( ! $helpful && in_array( $q['status'], array( 'answered', 'partial', 'site' ), true ) && ! PNChat_Store::update_question( (int) $q['id'], array( 'status' => 'unhelpful' ) ) ) {
			return new WP_Error( 'pnchat_feedback_save', 'Δεν αποθηκεύτηκε. Δοκιμάστε ξανά σε λίγο.', array( 'status' => 500 ) );
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
	 * Answers a question, in a conversation topic when it names none.
	 *
	 * The plain answer stays when it is a refusal, or a general entry with no
	 * topic (greetings, thanks). Otherwise the question is asked again with
	 * the topic added, and that answer wins when it is about the topic.
	 *
	 * @param string $question Question.
	 * @param string $context  Topic of the conversation ('' for none).
	 * @return array<string,mixed> Matcher result.
	 */
	public static function answer_in_context( $question, $context, $prev = 0 ) {
		$m     = PNChat_Brain::matcher();
		$plain = $m->ask( $question );
		if ( 'blocked' === $plain['status'] ) {
			return $plain;
		}
		if ( '' === $context ) {
			return 'unanswered' === $plain['status'] ? self::after_previous( $question, $context, $prev, $plain ) : $plain;
		}
		// A question that is word for word a trained one of no topic is that
		// entry («Γεια σας», «Ευχαριστώ»), whatever the conversation.
		if ( 'answered' === $plain['status'] && (float) $plain['items'][0]['score'] >= 0.97 && '' === PNChat_Topics::of_entry( (int) $plain['items'][0]['id'] ) ) {
			return $plain;
		}
		// «Και πώς τη διαγράφω;»: «τη» is the previous answer, before the topic.
		if ( $prev && self::points_back( $question ) ) {
			$after = self::after_previous( $question, $context, $prev, array() );
			if ( $after ) {
				return $after;
			}
		}
		// The topic goes inside the question («Είναι δωρεάν QR ReBuilder»),
		// not after its question mark, which would split it in two.
		$ctx = $m->ask( rtrim( $question, " \t?;;.!" ) . ' ' . $context );
		// The entry must also relate to the question's own words: the added
		// topic words alone would pull the topic's general entry («Τι είναι
		// το QR ReBuilder») for any vague question («Έχει εφαρμογή για iPhone;»).
		$own_score = 0.0;
		if ( 'answered' === $ctx['status'] ) {
			foreach ( $m->rank( $question ) as $r ) {
				if ( (int) $r['id'] === (int) $ctx['items'][0]['id'] ) {
					$own_score = (float) $r['score'];
					break;
				}
			}
		}
		// Or every word of its own is in that entry («Πόσο κοστίζει;» →
		// «Κόστος συμμετοχής» of the Viber community).
		$covered = 'answered' === $ctx['status'] && $m->coverage( $question, (int) $ctx['items'][0]['id'] ) >= 0.6;
		if ( 'answered' === $ctx['status'] && 'answer' === $ctx['items'][0]['kind'] && ( $own_score >= 0.25 || $covered ) && PNChat_Topics::of_entry( (int) $ctx['items'][0]['id'] ) === $context ) {
			// One answer: the added topic words would also bring its general
			// entry («Τι είναι το …») as a second one.
			$ctx['items'] = array_slice( $ctx['items'], 0, 1 );
			return $ctx;
		}
		$plain_topic = 'answered' === $plain['status'] ? PNChat_Topics::of_entry( (int) $plain['items'][0]['id'] ) : '';
		if ( 'answered' === $plain['status'] && $plain_topic === $context ) {
			return $plain;
		}
		// «Και πώς τη διαγράφω;» after an answer: the previous answer first.
		$after = self::after_previous( $question, $context, $prev, array() );
		if ( $after ) {
			return $after;
		}
		// An entry of no topic (greetings, «Εγγραφή στην PharmacyNeeds»),
		// or a clear match in another topic: the visitor moved on.
		if ( 'answered' === $plain['status'] && ( '' === $plain_topic || (float) $plain['items'][0]['score'] >= 0.85 ) ) {
			return $plain;
		}
		// A vague question with nothing about this topic: an answer about
		// another topic would be wrong here, so it is reported as unanswered.
		if ( in_array( $plain['status'], array( 'answered', 'partial' ), true ) ) {
			$plain = array(
				'status'    => 'unanswered',
				'items'     => array(),
				'unmatched' => array( $question ),
				'parts'     => $plain['parts'],
			);
		}
		return $plain;
	}

	/**
	 * The question refers to something said before («τη», «το», «αυτό»).
	 *
	 * @param string $question Question.
	 * @return bool
	 */
	private static function points_back( $question ) {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( (string) $question, 'UTF-8' ), -1, PREG_SPLIT_NO_EMPTY );
		return (bool) array_intersect( (array) $words, array( 'τη', 'την', 'τον', 'το', 'τα', 'τις', 'τους', 'αυτό', 'αυτή', 'αυτήν', 'αυτόν', 'αυτά', 'αυτές', 'αυτούς' ) );
	}

	/**
	 * A follow-up that points at the previous answer, not only its topic:
	 * after «Πώς ανοίγω περίοδο υποβολής;», «Και πώς την ακυρώνω;» is read
	 * as «…ακυρώνω περίοδο υποβολής» and finds «Ακύρωση ανοίγματος
	 * περιόδου». Accepted only for another entry of the same topic whose
	 * words cover the question's own.
	 *
	 * @param string              $question Question.
	 * @param string              $context  Conversation topic.
	 * @param int                 $prev     Entry that answered last.
	 * @param array<string,mixed> $fallback Result to keep otherwise (array() = none).
	 * @return array<string,mixed>
	 */
	private static function after_previous( $question, $context, $prev, array $fallback ) {
		$p = $prev ? PNChat_Store::entry( (int) $prev ) : null;
		if ( ! $p || 'answer' !== $p['kind'] || empty( $p['active'] ) ) {
			return $fallback;
		}
		$about = (string) $p['title'];
		$pre   = PNChat_Topics::title_prefix( $about );
		if ( '' !== $pre ) {
			$about = trim( mb_substr( $about, mb_strlen( $pre ) + 1 ) );
		}
		$m = PNChat_Brain::matcher();
		$r = $m->ask( rtrim( $question, " \t?;;.!" ) . ' ' . $about . ( '' !== $context ? ' ' . $context : '' ) );
		if ( 'answered' !== $r['status'] || 'answer' !== $r['items'][0]['kind'] ) {
			return $fallback;
		}
		$top = (int) $r['items'][0]['id'];
		if ( $top === (int) $prev || $m->coverage( $question, $top ) < 0.5 || PNChat_Topics::of_entry( $top ) !== PNChat_Topics::of_entry( (int) $prev ) ) {
			return $fallback;
		}
		$r['items'] = array_slice( $r['items'], 0, 1 );
		return $r;
	}

	/**
	 * The entry of a tapped «Μήπως εννοείτε» button, as a matcher result;
	 * null when the request is not one (or not for a question it was
	 * offered for, by the visitor who asked it).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array<string,mixed>|null
	 */
	private static function picked( WP_REST_Request $req ) {
		if ( 'didyoumean' !== $req->get_param( 'via' ) ) {
			return null;
		}
		$pick  = absint( $req->get_param( 'pick' ) );
		$from  = PNChat_Store::question( absint( $req->get_param( 'from' ) ) );
		$token = (string) $req->get_param( 'from_token' );
		if ( ! $pick || ! $from || '' === $token || '' === (string) $from['token_hash'] || ! hash_equals( (string) $from['token_hash'], hash( 'sha256', $token ) ) ) {
			return null;
		}
		if ( ! in_array( $pick, array_map( 'intval', explode( ',', (string) $from['offered'] ) ), true ) ) {
			return null;
		}
		$e = PNChat_Store::entry( $pick );
		if ( ! $e || 'answer' !== $e['kind'] || empty( $e['active'] ) ) {
			return null;
		}
		$conv = self::conv_hash( (string) $req->get_param( 'conv' ) );
		if ( '' !== $conv && $conv === (string) $from['conv'] && (int) $from['hint_entry'] !== $pick ) {
			PNChat_Store::update_question( (int) $from['id'], array( 'hint_entry' => $pick ) );
			PNChat_Lessons::record( self::wording( $from ), $pick, $conv, 'click' );
		}
		return array(
			'status'    => 'answered',
			'items'     => array(
				array(
					'id'     => $pick,
					'kind'   => 'answer',
					'title'  => (string) $e['title'],
					'answer' => (string) $e['answer'],
					'score'  => 1.0,
				),
			),
			'unmatched' => array(),
			'parts'     => array(),
		);
	}

	/**
	 * What a logged question taught: its text, with the conversation topic
	 * when it was a follow-up («Πρέπει να πληρώσω; (QR ReBuilder)»).
	 *
	 * @param array<string,mixed>|null $q Question row.
	 * @return string
	 */
	private static function wording( $q ) {
		if ( ! $q ) {
			return '';
		}
		$parts = PNChat_Store::lines( (string) $q['unmatched'] );
		return $parts ? $parts[0] : (string) $q['question'];
	}

	/**
	 * Stored form of the conversation id the widget sends (a random string
	 * kept in the visitor's tab): hashed, so the log never holds it.
	 *
	 * @param string $conv Conversation id.
	 * @return string '' when missing or malformed.
	 */
	private static function conv_hash( $conv ) {
		return preg_match( '/^[A-Za-z0-9]{16,64}$/', $conv ) ? substr( hash( 'sha256', 'pnchat|' . $conv ), 0, 32 ) : '';
	}

	/**
	 * «Σχετικές ερωτήσεις» after an answer: other entries of the same group
	 * (title prefix «eΔΑΠΥ: …», else the conversation topic), the closest to
	 * the answered one first, without the ones already shown.
	 *
	 * @param int        $entry_id Answered entry.
	 * @param array<int> $seen     Entries already shown in the conversation.
	 * @param int        $max      How many (0 = none).
	 * @return array<int,array{id:int,text:string}>
	 */
	public static function related( $entry_id, array $seen, $max ) {
		if ( $max < 1 || ! $entry_id ) {
			return array();
		}
		$all = PNChat_Store::entries( 'answer', true );
		$cur = null;
		foreach ( $all as $e ) {
			if ( (int) $e['id'] === (int) $entry_id ) {
				$cur = $e;
				break;
			}
		}
		if ( ! $cur ) {
			return array();
		}
		$pre   = PNChat_Text::fold( PNChat_Topics::title_prefix( (string) $cur['title'] ) );
		$topic = '' === $pre ? PNChat_Topics::of_entry_data( $cur ) : '';
		if ( '' === $pre && '' === $topic ) {
			return array();
		}
		$skip  = array_flip( array_map( 'intval', array_merge( $seen, array( $entry_id ) ) ) );
		$group = array();
		foreach ( $all as $e ) {
			if ( isset( $skip[ (int) $e['id'] ] ) || ! $e['phrasings'] ) {
				continue;
			}
			$same = '' !== $pre
				? PNChat_Text::fold( PNChat_Topics::title_prefix( (string) $e['title'] ) ) === $pre
				: PNChat_Topics::of_entry_data( $e ) === $topic;
			if ( $same ) {
				$group[ (int) $e['id'] ] = $e;
			}
		}
		if ( ! $group ) {
			return array();
		}
		// Closest first: the matcher's score of the answered entry's words.
		$score = array();
		foreach ( PNChat_Brain::matcher()->rank( $cur['title'] . ' ' . implode( ' ', array_slice( (array) $cur['phrasings'], 0, 3 ) ) ) as $r ) {
			$score[ (int) $r['id'] ] = (float) $r['score'];
		}
		uksort(
			$group,
			function ( $a, $b ) use ( $score, $group ) {
				$d = ( $score[ $b ] ?? 0 ) <=> ( $score[ $a ] ?? 0 );
				return $d ? $d : ( (int) $group[ $b ]['hits'] <=> (int) $group[ $a ]['hits'] );
			}
		);
		$out = array();
		foreach ( array_slice( $group, 0, $max, true ) as $id => $e ) {
			$out[] = array(
				'id'   => (int) $id,
				'text' => (string) $e['phrasings'][0],
			);
		}
		return $out;
	}

	/**
	 * Id of the first trained answer (0 for none).
	 *
	 * @param array<string,mixed> $result Matcher result.
	 * @return int
	 */
	private static function first_answer( array $result ) {
		foreach ( $result['items'] as $i ) {
			if ( 'answer' === $i['kind'] ) {
				return (int) $i['id'];
			}
		}
		return 0;
	}

	/**
	 * The conversation topic after this answer.
	 *
	 * @param array<string,mixed> $result  Matcher result.
	 * @param string              $own     Topic the question named.
	 * @param string              $context Topic carried over ('' for none).
	 * @return string
	 */
	private static function topic_of( array $result, $own, $context ) {
		if ( '' !== $own ) {
			return $own;
		}
		foreach ( $result['items'] as $i ) {
			if ( 'answer' === $i['kind'] ) {
				$t = PNChat_Topics::of_entry( (int) $i['id'] );
				if ( '' !== $t ) {
					return $t;
				}
			}
		}
		return $context;
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
	 * Rows of the site's tables for a name in the question that the answer
	 * does not mention.
	 *
	 * @param array<string,mixed> $result   Brain result.
	 * @param string              $question Question.
	 * @return array<int,array{kind:string,title:string,html:string,id:int}>
	 */
	public static function table_rows( array $result, $question ) {
		if ( empty( PNChat_Settings::value( 'site_search' ) ) || 'ai' === $result['status'] ) {
			return array();
		}
		$covered = '';
		foreach ( $result['items'] as $i ) {
			$covered .= ' ' . ( $i['title'] ?? '' ) . ' ' . wp_strip_all_tags( (string) ( $i['answer'] ?? '' ) );
		}
		return PNChat_Site_Search::render_lookup( PNChat_Site_Search::lookup( $question, $covered ) );
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
	 * The page the question was asked on: this site's address without its
	 * query string or fragment (they may hold e-mails or tokens).
	 *
	 * @param string $url Address sent by the browser.
	 * @return string
	 */
	public static function page_url( $url ) {
		$p    = wp_parse_url( esc_url_raw( (string) $url ) );
		$home = wp_parse_url( home_url() );
		if ( ! is_array( $p ) || empty( $p['host'] ) || ! in_array( $p['scheme'] ?? '', array( 'http', 'https' ), true ) || strtolower( $p['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return '';
		}
		$out = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' ) . ( $p['path'] ?? '/' );
		return mb_substr( $out, 0, 255 );
	}

	/**
	 * The AI never answers this question: it is not about the site's tools
	 * or has a medical sign (PNChat_AI::may_answer()), or it is close to a
	 * refusal («Απαγορεύσεις») even below the strictness. Safer for a
	 * pharmacy site.
	 *
	 * @param string $question Question.
	 * @return bool
	 */
	private static function near_block( $question ) {
		if ( ! PNChat_AI::may_answer( $question ) ) {
			return true;
		}
		$floor = (float) apply_filters( 'pnchat_ai_block_guard', 0.3 );
		foreach ( PNChat_Brain::matcher()->rank( $question ) as $r ) {
			if ( 'block' === $r['kind'] && (float) $r['score'] >= $floor ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The visitor's IP. Behind Cloudflare or another proxy every visitor
	 * arrives from the proxy's address; wp-config.php can then name the
	 * header that carries the real one, e.g.
	 * define( 'PNCHAT_IP_HEADER', 'HTTP_CF_CONNECTING_IP' );
	 * Only for a header the proxy always sets: visitors can forge any other.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip     = '';
		$header = defined( 'PNCHAT_IP_HEADER' ) ? (string) PNCHAT_IP_HEADER : '';
		if ( '' !== $header && isset( $_SERVER[ $header ] ) ) {
			$first = trim( explode( ',', sanitize_text_field( wp_unslash( (string) $_SERVER[ $header ] ) ) )[0] );
			$ip    = filter_var( $first, FILTER_VALIDATE_IP ) ? $first : '';
		}
		if ( '' === $ip && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}
		return (string) apply_filters( 'pnchat_client_ip', $ip );
	}

	/**
	 * Fixed-window rate limit per visitor (IP hash, or user id). Atomic: two
	 * requests at the same moment cannot both take the last use.
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
		// Ended limits go first: their IP hashes are not kept any longer.
		PNChat_Counter::purge();
		$who = get_current_user_id() ? 'u' . get_current_user_id() : 'ip' . self::client_ip();
		return PNChat_Counter::take( 'rl:' . $bucket . ':' . substr( hash_hmac( 'sha256', $who, wp_salt( 'nonce' ) ), 0, 24 ), $limit, $window );
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
		$to = PNChat_Settings::notify_address();
		if ( empty( $s['notify_on_email'] ) || ! is_email( $to ) ) {
			return;
		}
		$link = admin_url( 'admin.php?page=pn-chat-questions&filter=email#q-' . $id );
		$body = "Νέα ερώτηση στο PN Chat περιμένει απάντηση με e-mail.\n\n"
			. 'Ερώτηση: ' . $question . "\n"
			. 'Από: ' . ( '' !== $name ? $name . ' ' : '' ) . '<' . $email . ">\n\n"
			. 'Απαντήστε ή εκπαιδεύστε τον βοηθό εδώ: ' . $link . "\n";
		wp_mail( $to, '[PN Chat] Νέα ερώτηση χωρίς απάντηση', $body, array( 'Reply-To: ' . $email ) );
	}
}
