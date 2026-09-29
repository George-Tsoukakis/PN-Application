<?php
/**
 * Helpers: Greek Slugs
 *
 * Greek-to-Latin transliteration and unique post slug generation.
 * Loaded by jbli-helpers.php.
 *
 * @package JobListings
 * @since   9.9.27
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the Greek → Latin transliteration map.
 *
 * Used to build readable, SEO-friendly slugs from Greek post titles.
 *
 * @since 9.9.1
 * @return array<string, string>
 */
function jbli_greek_transliteration_map() {

	return array(

		'Α' => 'a',  'Β' => 'v',  'Γ' => 'g',  'Δ' => 'd',  'Ε' => 'e',
		'Ζ' => 'z',  'Η' => 'i',  'Θ' => 'th', 'Ι' => 'i',  'Κ' => 'k',
		'Λ' => 'l',  'Μ' => 'm',  'Ν' => 'n',  'Ξ' => 'x',  'Ο' => 'o',
		'Π' => 'p',  'Ρ' => 'r',  'Σ' => 's',  'Τ' => 't',  'Υ' => 'y',
		'Φ' => 'f',  'Χ' => 'ch', 'Ψ' => 'ps', 'Ω' => 'o',

		'Ά' => 'a', 'Έ' => 'e', 'Ή' => 'i', 'Ί' => 'i', 'Ό' => 'o',
		'Ύ' => 'y', 'Ώ' => 'o', 'Ϊ' => 'i', 'Ϋ' => 'y',

		'α' => 'a',  'β' => 'v',  'γ' => 'g',  'δ' => 'd',  'ε' => 'e',
		'ζ' => 'z',  'η' => 'i',  'θ' => 'th', 'ι' => 'i',  'κ' => 'k',
		'λ' => 'l',  'μ' => 'm',  'ν' => 'n',  'ξ' => 'x',  'ο' => 'o',
		'π' => 'p',  'ρ' => 'r',  'σ' => 's',  'ς' => 's',  'τ' => 't',
		'υ' => 'y',  'φ' => 'f',  'χ' => 'ch', 'ψ' => 'ps', 'ω' => 'o',

		'ά' => 'a', 'έ' => 'e', 'ή' => 'i', 'ί' => 'i', 'ό' => 'o',
		'ύ' => 'y', 'ώ' => 'o', 'ϊ' => 'i', 'ϋ' => 'y', 'ΐ' => 'i', 'ΰ' => 'y',

		'—' => '-', '–' => '-', '·' => '-',
	);

}

/**
 * Transliterate a Greek string to a Latin URL slug.
 *
 * @since 9.9.1
 *
 * @param string $jbli_text Raw string (may contain Greek, Latin, numbers, symbols).
 * @return string URL-safe slug.
 */
function jbli_greek_to_slug( $jbli_text ) {

	$jbli_text = (string) $jbli_text;

	if ( '' === $jbli_text ) { return ''; }

	$jbli_map  = jbli_greek_transliteration_map();
	$jbli_text = str_replace( array_keys( $jbli_map ), array_values( $jbli_map ), $jbli_text );

	return sanitize_title_with_dashes( $jbli_text, '', 'save' );

}

/**
 * Build a unique, SEO-friendly slug for a job listing post.
 *
 * @since 9.9.1
 * @since 9.9.27 Moved to helpers-parts/jbli-slugs.php.
 *
 * @param string $jbli_title   The post title to slugify.
 * @param int    $jbli_post_id Post ID (0 for new posts).
 * @return string Unique, URL-safe slug.
 */
function jbli_build_post_slug( $jbli_title, $jbli_post_id = 0 ) {

	$jbli_title   = (string) $jbli_title;
	$jbli_post_id = absint( $jbli_post_id );

	$jbli_raw_slug = jbli_greek_to_slug( $jbli_title );

	if ( '' === $jbli_raw_slug ) { $jbli_raw_slug = 'aggelia'; }

	$post             = $jbli_post_id > 0 ? get_post( $jbli_post_id ) : null;
	$jbli_post_status = ( $post instanceof WP_Post ) ? $post->post_status : 'publish';

	return wp_unique_post_slug( $jbli_raw_slug, $jbli_post_id, $jbli_post_status, JBLI_CPT, 0 );

}
