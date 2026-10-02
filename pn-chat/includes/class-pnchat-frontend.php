<?php
/**
 * The chat on the site: floating button on every page, or [pn_chat] inline.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front end.
 */
final class PNChat_Frontend {

	/**
	 * The shortcode was used on this page.
	 *
	 * @var bool
	 */
	private static $inline = false;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'pn_chat', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'early' ) );
		add_action( 'wp_footer', array( __CLASS__, 'floating' ) );
	}

	/**
	 * Loads the style and script in the normal place (the style in <head>),
	 * where caching and optimisation plugins expect them, whenever the chat
	 * will be on the page: the floating button, or a post with [pn_chat].
	 * The shortcode and the footer still load them late if needed.
	 *
	 * @return void
	 */
	public static function early() {
		if ( ! self::visible() ) {
			return;
		}
		$post = get_post();
		if ( 'floating' === PNChat_Settings::value( 'placement' ) || ( is_singular() && $post && has_shortcode( (string) $post->post_content, 'pn_chat' ) ) ) {
			self::enqueue();
		}
	}

	/**
	 * The visitor may see the chat.
	 *
	 * @return bool
	 */
	public static function visible() {
		$s = PNChat_Settings::get();
		if ( empty( $s['enabled'] ) ) {
			return false;
		}
		if ( 'logged_in' === $s['visibility'] && ! is_user_logged_in() ) {
			return false;
		}
		return (bool) apply_filters( 'pnchat_visible', true );
	}

	/**
	 * Loads the script and style once.
	 *
	 * @return void
	 */
	private static function enqueue() {
		if ( wp_script_is( 'pn-chat', 'enqueued' ) ) {
			return;
		}
		$s = PNChat_Settings::get();
		wp_enqueue_style( 'pn-chat', PNCHAT_URL . 'assets/css/pn-chat.css', array(), PNCHAT_VERSION );
		wp_add_inline_style( 'pn-chat', '.pnchat{--pnchat-accent:' . sanitize_hex_color( (string) $s['color'] ) . ';}' );
		wp_enqueue_script( 'pn-chat', PNCHAT_URL . 'assets/js/pn-chat.js', array(), PNCHAT_VERSION, true );

		$config = array(
			'api'         => esc_url_raw( rest_url( PNChat_Rest::NS ) ),
			// The REST nonce only matters for logged-in visitors (it tells
			// WordPress who they are). Guests send none, so a cached page
			// with an old nonce cannot break the chat.
			'nonce'       => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'title'       => (string) $s['title'],
			'subtitle'    => (string) $s['subtitle'],
			'welcome'     => (string) $s['welcome'],
			'placeholder' => (string) $s['placeholder'],
			'privacy'     => (string) $s['privacy_note'],
			'suggestions' => PNChat_Settings::suggestion_groups( (string) $s['suggestions'] ),
			'position'    => (string) $s['position'],
			'maxLength'   => PNChat_Rest::MAX_QUESTION,
			'aiWait'      => PNChat_AI::chat_enabled() ? (string) $s['ai_chat_wait'] : '',
			// Fixed buttons the chat button must not cover; it sits above them.
			'avoid'       => array_values( array_filter( (array) apply_filters( 'pnchat_avoid_selectors', array( '#plandose-trigger' ) ), 'is_string' ) ),
		);
		wp_add_inline_script( 'pn-chat', 'window.PNChatConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * [pn_chat] — the chat inside the page.
	 *
	 * @return string
	 */
	public static function shortcode() {
		if ( ! self::visible() ) {
			return '';
		}
		self::$inline = true;
		self::enqueue();
		return '<div class="pnchat-inline-host" data-pnchat-inline></div>';
	}

	/**
	 * The floating button, unless the page has the shortcode.
	 *
	 * @return void
	 */
	public static function floating() {
		if ( self::$inline || ! self::visible() ) {
			return;
		}
		if ( 'floating' !== PNChat_Settings::value( 'placement' ) ) {
			return;
		}
		self::enqueue();
		// wp_footer runs before the footer scripts are printed, so the
		// script enqueued here still loads on this page.
		echo '<div class="pnchat-floating-host" data-pnchat-floating></div>';
	}
}
