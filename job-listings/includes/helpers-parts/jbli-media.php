<?php
/**
 * Helpers: side media panel (image / video / YouTube / Vimeo).
 *
 * Shared by the new-listing form (9.9.45) and the single listing page
 * (9.9.51). Loaded by jbli-helpers.php.
 *
 * @package JobListings
 * @since   9.9.51
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'jbli_form_media_type' ) )
{
	/**
	 * Classify a media URL.
	 *
	 * @since 9.9.45 (moved here in 9.9.51)
	 *
	 * @param string $jbli_url Media URL from the settings.
	 * @return array{type:string,embed?:string} type is 'image', 'video', 'embed' or '' (none).
	 */
	function jbli_form_media_type( $jbli_url ) {

		$jbli_url = trim( (string) $jbli_url );

		if ( '' === $jbli_url ) { return array( 'type' => '' ); }

		if ( preg_match( '~(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{6,})~', $jbli_url, $jbli_m ) )
		{
			$jbli_id = $jbli_m[1];

			return array(
				'type'  => 'embed',
				'embed' => 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $jbli_id )
					. '?autoplay=1&mute=1&loop=1&controls=0&modestbranding=1&rel=0&playsinline=1&playlist=' . rawurlencode( $jbli_id ),
			);
		}

		if ( preg_match( '~vimeo\.com/(?:video/)?(\d+)~', $jbli_url, $jbli_m ) )
		{
			return array(
				'type'  => 'embed',
				'embed' => 'https://player.vimeo.com/video/' . rawurlencode( $jbli_m[1] ) . '?background=1&autoplay=1&muted=1&loop=1',
			);
		}

		$jbli_path = strtolower( (string) wp_parse_url( $jbli_url, PHP_URL_PATH ) );

		if ( preg_match( '~\.(mp4|webm|ogv|mov)$~', $jbli_path ) ) { return array( 'type' => 'video' ); }

		/* Images, and CDN URLs without an extension. */
		return array( 'type' => 'image' );

	}
}

/**
 * Render the media panel: the media (or a built-in illustration) with a
 * glass card overlaid at the bottom.
 *
 * @since 9.9.51
 *
 * @param string $jbli_url  Media URL ('' = illustration).
 * @param array  $jbli_card {
 *     @type string   $kicker Small uppercase line.
 *     @type string   $title  Card title.
 *     @type string[] $items  Bullet list (checkmarks).
 *     @type string   $html   Extra trusted HTML after the list (e.g. a button).
 * }
 * @param string $jbli_class Extra class on the wrapper.
 * @return string
 */
function jbli_media_panel_html( $jbli_url, array $jbli_card, $jbli_class = '' ) {

	$jbli_url   = esc_url_raw( trim( (string) $jbli_url ) );
	$jbli_media = jbli_form_media_type( $jbli_url );
	$jbli_type  = '' !== $jbli_media['type'] ? $jbli_media['type'] : 'default';

	ob_start();
	?>
	<div class="jbli_form_media jbli_form_media_<?php echo esc_attr( $jbli_type ); ?> <?php echo esc_attr( (string) $jbli_class ); ?>">

		<?php if ( 'image' === $jbli_type ) { ?>

			<img class="jbli_form_media_el" src="<?php echo esc_url( $jbli_url ); ?>" alt="" loading="lazy" decoding="async">

		<?php } elseif ( 'video' === $jbli_type ) { ?>

			<video class="jbli_form_media_el" src="<?php echo esc_url( $jbli_url ); ?>" autoplay muted loop playsinline preload="metadata"></video>

		<?php } elseif ( 'embed' === $jbli_type ) { ?>

			<iframe class="jbli_form_media_el" src="<?php echo esc_url( $jbli_media['embed'] ); ?>" title="" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture" tabindex="-1"></iframe>

		<?php } else { ?>

			<div class="jbli_form_media_art" aria-hidden="true">
				<svg viewBox="0 0 320 260" width="100%" height="100%" fill="none" preserveAspectRatio="xMidYMid meet">
					<circle cx="250" cy="52" r="70" fill="rgba(255,255,255,.08)"/>
					<circle cx="54" cy="214" r="90" fill="rgba(255,255,255,.06)"/>
					<rect x="92" y="62" width="136" height="150" rx="22" fill="rgba(255,255,255,.14)" stroke="rgba(255,255,255,.4)" stroke-width="2"/>
					<rect x="130" y="44" width="60" height="30" rx="10" fill="rgba(255,255,255,.22)" stroke="rgba(255,255,255,.45)" stroke-width="2"/>
					<path d="M160 104v48M136 128h48" stroke="#fff" stroke-width="12" stroke-linecap="round"/>
					<rect x="116" y="170" width="88" height="8" rx="4" fill="rgba(255,255,255,.45)"/>
					<rect x="130" y="186" width="60" height="8" rx="4" fill="rgba(255,255,255,.3)"/>
					<circle cx="236" cy="176" r="26" fill="#34d399" stroke="#fff" stroke-width="3"/>
					<path d="m224 176 8 8 16-16" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</div>

		<?php } ?>

		<div class="jbli_form_media_shade"></div>

		<div class="jbli_form_media_card">
			<?php if ( ! empty( $jbli_card['kicker'] ) ) { ?><p class="jbli_form_media_kicker"><?php echo esc_html( (string) $jbli_card['kicker'] ); ?></p><?php } ?>
			<?php if ( ! empty( $jbli_card['title'] ) ) { ?><p class="jbli_form_media_title"><?php echo esc_html( (string) $jbli_card['title'] ); ?></p><?php } ?>
			<?php if ( ! empty( $jbli_card['items'] ) ) { ?>
				<ul class="jbli_form_media_list">
					<?php foreach ( (array) $jbli_card['items'] as $jbli_item ) { ?>
						<li><?php echo esc_html( (string) $jbli_item ); ?></li>
					<?php } ?>
				</ul>
			<?php } ?>
			<?php
				if ( ! empty( $jbli_card['html'] ) ) {
					echo $jbli_card['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by the caller from escaped parts.
				}
			?>
		</div>

	</div>
	<?php
	return (string) ob_get_clean();

}
