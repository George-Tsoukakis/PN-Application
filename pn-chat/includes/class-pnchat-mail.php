<?php
/**
 * The e-mail reply to a visitor: a light HTML message (one card, the site's
 * colour, no images) with the plain text as the alternative part.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reply e-mail.
 */
final class PNChat_Mail {

	/**
	 * Sends the reply.
	 *
	 * @param string $to      Address.
	 * @param string $subject Subject.
	 * @param string $text    Text the administrator wrote (plain text).
	 * @return bool
	 */
	public static function send_reply( $to, $subject, $text ) {
		$alt = function ( $mailer ) use ( $text ) {
			$mailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer.
		};
		add_action( 'phpmailer_init', $alt );
		$sent = wp_mail( $to, $subject, self::html( $subject, $text ), array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'phpmailer_init', $alt );
		return $sent;
	}

	/**
	 * The HTML message.
	 *
	 * @param string $subject Subject (title of the message).
	 * @param string $text    Plain text.
	 * @return string
	 */
	public static function html( $subject, $text ) {
		$color = (string) PNChat_Settings::value( 'color' );
		$name  = get_bloginfo( 'name' );
		$home  = home_url( '/' );
		$host  = (string) wp_parse_url( $home, PHP_URL_HOST );
		$font  = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif";

		$out  = '<!DOCTYPE html><html lang="el"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $subject ) . '</title></head>';
		$out .= '<body style="margin:0;padding:0;background:#f3f5f7;">';
		$out .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f5f7;"><tr><td align="center" style="padding:24px 12px;">';
		$out .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;font-family:' . $font . ';">';
		// Header.
		$out .= '<tr><td style="background:' . esc_attr( $color ) . ';padding:20px 28px;color:#ffffff;">';
		$out .= '<div style="font-size:20px;font-weight:700;line-height:1.3;">' . esc_html( $name ) . '</div>';
		$out .= '<div style="font-size:14px;opacity:.9;margin-top:2px;">Απάντηση στην ερώτησή σας</div>';
		$out .= '</td></tr>';
		// Body.
		$out .= '<tr><td style="padding:24px 28px 8px;font-size:15px;line-height:1.6;color:#1f2937;">' . self::body( $text, $color ) . '</td></tr>';
		// Footer.
		$out .= '<tr><td style="padding:16px 28px 22px;border-top:1px solid #eef0f2;font-size:12px;line-height:1.5;color:#6b7280;">';
		$out .= 'Λάβατε αυτό το μήνυμα επειδή κάνατε μια ερώτηση στο chat του <a href="' . esc_url( $home ) . '" style="color:' . esc_attr( $color ) . ';text-decoration:none;">' . esc_html( $host ) . '</a>.';
		$out .= '</td></tr>';
		$out .= '</table></td></tr></table></body></html>';
		return $out;
	}

	/**
	 * Plain text to HTML: paragraphs, «question» as a quote, «- » lines as a
	 * list, a line with only a link as a button, other links clickable.
	 *
	 * @param string $text  Plain text.
	 * @param string $color Brand colour.
	 * @return string
	 */
	private static function body( $text, $color ) {
		$text   = trim( str_replace( "\r\n", "\n", (string) $text ) );
		$blocks = preg_split( "/\n\s*\n/", $text );
		$html   = '';
		foreach ( (array) $blocks as $block ) {
			$para = array();
			$list = array();
			foreach ( explode( "\n", (string) $block ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				if ( preg_match( '/^(?:[-•*·])\s+(.+)$/u', $line, $m ) ) {
					self::flush_para( $html, $para );
					$list[] = '<li style="margin:0 0 6px;">' . self::inline( $m[1], $color ) . '</li>';
					continue;
				}
				self::flush_list( $html, $list );
				if ( preg_match( '/^«(.+)»$/u', $line, $m ) ) {
					self::flush_para( $html, $para );
					$html .= '<div style="margin:0 0 14px;padding:10px 14px;background:#f6f8f9;border-left:4px solid ' . esc_attr( $color ) . ';border-radius:6px;font-style:italic;color:#374151;">' . esc_html( $m[1] ) . '</div>';
					continue;
				}
				if ( preg_match( '#^https?://\S+$#', $line ) ) {
					self::flush_para( $html, $para );
					$html .= '<p style="margin:4px 0 18px;"><a href="' . esc_url( $line ) . '" style="display:inline-block;background:' . esc_attr( $color ) . ';color:#ffffff;text-decoration:none;font-weight:600;padding:11px 20px;border-radius:8px;">Δείτε τη σελίδα &rarr;</a><br><span style="font-size:12px;color:#6b7280;word-break:break-all;">' . esc_html( $line ) . '</span></p>';
					continue;
				}
				// A short label such as «Απάντηση:».
				if ( ! $para && preg_match( '/^(\S+(?:\s\S+)?)\s*:$/u', $line, $m ) ) {
					$para[] = '<strong>' . esc_html( $m[1] ) . ':</strong>';
					continue;
				}
				$para[] = self::inline( $line, $color );
			}
			self::flush_list( $html, $list );
			self::flush_para( $html, $para );
		}
		return $html;
	}

	/**
	 * Closes an open paragraph.
	 *
	 * @param string   $html Output so far.
	 * @param string[] $para Lines of the paragraph (HTML).
	 * @return void
	 */
	private static function flush_para( &$html, array &$para ) {
		if ( $para ) {
			$html .= '<p style="margin:0 0 14px;">' . implode( '<br>', $para ) . '</p>';
			$para  = array();
		}
	}

	/**
	 * Closes an open list.
	 *
	 * @param string   $html Output so far.
	 * @param string[] $list Items (HTML).
	 * @return void
	 */
	private static function flush_list( &$html, array &$list ) {
		if ( $list ) {
			$html .= '<ul style="margin:0 0 14px;padding-left:22px;">' . implode( '', $list ) . '</ul>';
			$list  = array();
		}
	}

	/**
	 * Escapes a line and makes its links clickable.
	 *
	 * @param string $line  Text.
	 * @param string $color Brand colour.
	 * @return string
	 */
	private static function inline( $line, $color ) {
		$parts = preg_split( '#(https?://[^\s<>()«»"]+[^\s<>()«»".,;:!?])#u', $line, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		foreach ( (array) $parts as $i => $part ) {
			$out .= 1 === $i % 2
				? '<a href="' . esc_url( $part ) . '" style="color:' . esc_attr( $color ) . ';">' . esc_html( $part ) . '</a>'
				: esc_html( $part );
		}
		return $out;
	}
}
