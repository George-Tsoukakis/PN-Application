<?php
/**
 * The brain as a whole: the matcher built from the trained entries, the
 * download (backup) and the upload (restore), and automatic snapshots taken
 * before anything replaces the brain.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brain service.
 */
final class PNChat_Brain {

	const FORMAT        = 'pn-chat-brain';
	const FORMAT_VER    = 1;
	const SNAPSHOTS     = 'pnchat_snapshots';
	const MAX_SNAPSHOTS = 5;

	/**
	 * Settings that belong to the brain (texts and behaviour, not mail or privacy).
	 *
	 * @var string[]
	 */
	const BRAIN_SETTINGS = array( 'title', 'subtitle', 'welcome', 'placeholder', 'fallback', 'partial', 'unhelpful', 'email_thanks', 'suggestions', 'synonyms', 'strictness', 'max_answers', 'privacy_note' );

	/**
	 * Matcher of this request.
	 *
	 * @var PNChat_Matcher|null
	 */
	private static $matcher = null;

	/**
	 * Matcher on the active entries and the synonyms.
	 *
	 * @param bool $fresh Rebuild even if one exists.
	 * @return PNChat_Matcher
	 */
	public static function matcher( $fresh = false ) {
		if ( null === self::$matcher || $fresh ) {
			$s             = PNChat_Settings::get();
			self::$matcher = new PNChat_Matcher(
				PNChat_Store::entries( null, true ),
				PNChat_Matcher::parse_synonyms( (string) $s['synonyms'] ),
				PNChat_Settings::threshold(),
				(int) $s['max_answers']
			);
		}
		return self::$matcher;
	}

	/**
	 * HTML allowed in answers.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_html() {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'u'      => array(),
			'br'     => array(),
			'p'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
		);
	}

	/**
	 * Answer text as safe HTML for the chat.
	 *
	 * @param string $answer Stored answer.
	 * @return string
	 */
	public static function render_answer( $answer ) {
		$html = wpautop( wp_kses( (string) $answer, self::allowed_html() ) );
		// Links in answers open in a new tab and do not leak the opener.
		$html = (string) preg_replace( '/<a\s/i', '<a target="_blank" rel="noopener noreferrer" ', $html );
		return wp_kses( $html, self::allowed_html() );
	}

	/**
	 * Plain-text answer (e-mail replies, export previews).
	 *
	 * @param string $answer Stored answer.
	 * @return string
	 */
	public static function plain_answer( $answer ) {
		$text = (string) preg_replace( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/iu', '$2 ($1)', (string) $answer );
		$text = (string) preg_replace( '/<\/(p|li)>|<br\s*\/?>/i', "\n", $text );
		$text = (string) preg_replace( '/<li[^>]*>/i', '• ', $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/**
	 * The brain as an array (download).
	 *
	 * @param bool $with_questions Include the question log (personal data: e-mails).
	 * @return array<string,mixed>
	 */
	public static function export( $with_questions = false ) {
		$entries = array();
		foreach ( PNChat_Store::entries() as $e ) {
			$entries[] = array(
				'kind'       => $e['kind'],
				'title'      => $e['title'],
				'phrasings'  => $e['phrasings'],
				'keywords'   => $e['keywords'],
				'answer'     => $e['answer'],
				'active'     => (int) $e['active'],
				'hits'       => (int) $e['hits'],
				'created_at' => $e['created_at'],
			);
		}
		$settings = array_intersect_key( PNChat_Settings::get(), array_flip( self::BRAIN_SETTINGS ) );
		$out      = array(
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VER,
			'plugin_version' => PNCHAT_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'site'           => home_url(),
			'counts'         => array(
				'answers' => count( array_filter( $entries, fn( $e ) => 'answer' === $e['kind'] ) ),
				'blocks'  => count( array_filter( $entries, fn( $e ) => 'block' === $e['kind'] ) ),
			),
			'entries'        => $entries,
			'settings'       => $settings,
		);
		if ( $with_questions ) {
			$out['questions'] = PNChat_Store::all_questions();
		}
		return $out;
	}

	/**
	 * Checks a decoded download.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return true|WP_Error
	 */
	public static function validate( $data ) {
		if ( ! is_array( $data ) || ( $data['format'] ?? '' ) !== self::FORMAT ) {
			return new WP_Error( 'pnchat_format', 'Το αρχείο δεν είναι «εγκέφαλος» του PN Chat.' );
		}
		if ( (int) ( $data['format_version'] ?? 0 ) > self::FORMAT_VER ) {
			return new WP_Error( 'pnchat_newer', 'Το αρχείο φτιάχτηκε από νεότερη έκδοση του PN Chat. Ενημερώστε πρώτα το plugin.' );
		}
		if ( ! isset( $data['entries'] ) || ! is_array( $data['entries'] ) ) {
			return new WP_Error( 'pnchat_entries', 'Το αρχείο δεν έχει γνώσεις.' );
		}
		return true;
	}

	/**
	 * Restores a brain.
	 *
	 * @param array<string,mixed> $data           Validated data.
	 * @param string              $mode           'merge' (add what is new) or 'replace'.
	 * @param bool                $with_settings  Also restore texts and synonyms.
	 * @param bool                $with_questions Also restore the question log.
	 * @return array{added:int,skipped:int,questions:int}
	 */
	public static function import( array $data, $mode = 'merge', $with_settings = true, $with_questions = false ) {
		self::snapshot( 'replace' === $mode ? 'Πριν την αντικατάσταση από αρχείο' : 'Πριν την προσθήκη από αρχείο' );

		$existing = array();
		if ( 'replace' === $mode ) {
			PNChat_Store::delete_all_entries();
		} else {
			foreach ( PNChat_Store::entries() as $e ) {
				$existing[ self::entry_key( $e ) ] = true;
			}
		}

		$added   = 0;
		$skipped = 0;
		foreach ( $data['entries'] as $raw ) {
			$e = self::clean_entry( $raw );
			if ( ! $e ) {
				++$skipped;
				continue;
			}
			$key = self::entry_key( $e );
			if ( isset( $existing[ $key ] ) ) {
				++$skipped;
				continue;
			}
			if ( PNChat_Store::save_entry( $e ) ) {
				$existing[ $key ] = true;
				++$added;
			}
		}

		if ( $with_settings && isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$current  = PNChat_Settings::get();
			$incoming = array_intersect_key( $data['settings'], array_flip( self::BRAIN_SETTINGS ) );
			PNChat_Settings::save( PNChat_Settings::sanitize( array_merge( $current, $incoming ) ) );
		}

		$questions = 0;
		if ( $with_questions && isset( $data['questions'] ) && is_array( $data['questions'] ) ) {
			$questions = PNChat_Store::import_questions( $data['questions'] );
		}

		PNChat_Store::bump();
		self::$matcher = null;
		return array(
			'added'     => $added,
			'skipped'   => $skipped,
			'questions' => $questions,
		);
	}

	/**
	 * Cleans one imported entry.
	 *
	 * @param mixed $raw Entry from the file.
	 * @return array<string,mixed>|null
	 */
	public static function clean_entry( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$lines = function ( $v ) {
			$v = is_array( $v ) ? implode( "\n", array_map( 'strval', array_filter( $v, 'is_scalar' ) ) ) : (string) $v;
			return PNChat_Store::lines( sanitize_textarea_field( $v ) );
		};
		$e = array(
			'kind'       => ( isset( $raw['kind'] ) && 'block' === $raw['kind'] ) ? 'block' : 'answer',
			'title'      => sanitize_text_field( (string) ( $raw['title'] ?? '' ) ),
			'phrasings'  => $lines( $raw['phrasings'] ?? array() ),
			'keywords'   => $lines( $raw['keywords'] ?? array() ),
			'answer'     => wp_kses( (string) ( $raw['answer'] ?? '' ), self::allowed_html() ),
			'active'     => isset( $raw['active'] ) ? (int) (bool) $raw['active'] : 1,
			'hits'       => absint( $raw['hits'] ?? 0 ),
			'created_at' => (string) ( $raw['created_at'] ?? '' ),
		);
		if ( ( ! $e['phrasings'] && ! $e['keywords'] ) || '' === trim( $e['answer'] ) ) {
			return null;
		}
		if ( '' === $e['title'] ) {
			$e['title'] = $e['phrasings'] ? $e['phrasings'][0] : $e['keywords'][0];
		}
		return $e;
	}

	/**
	 * Identity of an entry for "merge" (same kind, title and main question).
	 *
	 * @param array<string,mixed> $e Entry.
	 * @return string
	 */
	private static function entry_key( array $e ) {
		$first = $e['phrasings'][0] ?? ( $e['keywords'][0] ?? '' );
		return $e['kind'] . '|' . PNChat_Text::fold( (string) $e['title'] ) . '|' . PNChat_Text::fold( (string) $first );
	}

	/**
	 * Keeps a copy of the brain (without the question log) before a change.
	 *
	 * @param string $label Why.
	 * @return void
	 */
	public static function snapshot( $label ) {
		$list = self::snapshots();
		array_unshift(
			$list,
			array(
				'id'    => wp_generate_password( 12, false ),
				'label' => sanitize_text_field( $label ),
				'time'  => time(),
				'user'  => get_current_user_id(),
				'data'  => self::export( false ),
			)
		);
		update_option( self::SNAPSHOTS, array_slice( $list, 0, self::MAX_SNAPSHOTS ), false );
	}

	/**
	 * Stored snapshots, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function snapshots() {
		$list = get_option( self::SNAPSHOTS, array() );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
	}

	/**
	 * One snapshot.
	 *
	 * @param string $id Snapshot id.
	 * @return array<string,mixed>|null
	 */
	public static function find_snapshot( $id ) {
		foreach ( self::snapshots() as $s ) {
			if ( isset( $s['id'] ) && hash_equals( (string) $s['id'], (string) $id ) ) {
				return $s;
			}
		}
		return null;
	}

	/**
	 * File name for a download.
	 *
	 * @param string $suffix Extra part.
	 * @return string
	 */
	public static function filename( $suffix = '' ) {
		return 'pn-chat-brain-' . gmdate( 'Y-m-d-His' ) . ( '' !== $suffix ? '-' . $suffix : '' ) . '.json';
	}
}
