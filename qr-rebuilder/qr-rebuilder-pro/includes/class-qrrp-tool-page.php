<?php
/**
 * «Φιλοξενεί αυτή η ανάρτηση το εργαλείο;» — μία υλοποίηση για τον Shortcode
 * (αποκλεισμός page cache) και τον Mailer (στόχος του συνδέσμου στο email).
 *
 * @package QR_ReBuilder_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QRRP_Tool_Page {

	/** Το shortcode του εργαλείου (η QRRP_Shortcode::SHORTCODE_TAG δείχνει εδώ). */
	public const SHORTCODE_TAG = 'qr_rebuilder_pro';

	/** Βάθος φωλιασμένων synced patterns που ακολουθείται. */
	private const MAX_PATTERN_DEPTH = 2;

	/** Ανώτατο πλήθος διαφορετικών synced patterns που φορτώνονται ανά έλεγχο. */
	private const MAX_PATTERN_REFS = 20;

	/**
	 * Ρυθμισμένη σελίδα || ανίχνευση περιεχομένου, και μετά το φίλτρο
	 * qrrp_page_has_tool. Η ρύθμιση μόνο προσθέτει, δεν αφαιρεί δεύτερη σελίδα.
	 *
	 * @param mixed $post Υποψήφια ανάρτηση· οτιδήποτε άλλο από WP_Post → false.
	 * @return bool
	 */
	public static function post_has_tool( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		/* Το uninstall.php δεν φορτώνει το κεντρικό αρχείο· τότε μένει μόνο η ανίχνευση. */
		$tool_page_id = function_exists( 'qrrp_tool_page_id' ) ? qrrp_tool_page_id() : 0;

		$has_tool = ( $tool_page_id > 0 && $tool_page_id === (int) $post->ID )
			|| self::cached_content_has_tool( (int) $post->ID, (string) $post->post_content );

		/**
		 * Δηλώστε σελίδα εργαλείου που η ανίχνευση δεν βλέπει (π.χ. page builders
		 * με layout σε post meta). Επηρεάζει page cache και σύνδεσμο email.
		 *
		 * @param bool    $has_tool Το αποτέλεσμα της ανίχνευσης.
		 * @param WP_Post $post     Η ανάρτηση που εξετάζεται.
		 */
		return (bool) apply_filters( 'qrrp_page_has_tool', $has_tool, $post );
	}

	/**
	 * 2.15.7: η ανίχνευση τρέχει τουλάχιστον δύο φορές ανά αίτημα
	 * (template_redirect και wp_enqueue_scripts), με parse_blocks() και έως
	 * MAX_PATTERN_REFS get_post() κάθε φορά. Κρατιέται ανά αίτημα, με κλειδί το
	 * ID και το hash του περιεχομένου (αλλαγή περιεχομένου = νέος έλεγχος).
	 */
	private static function cached_content_has_tool( $post_id, $content ) {
		static $cache = array();

		$key = $post_id . ':' . md5( $content );

		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = self::content_has_tool( $content );
		}

		return $cache[ $key ];
	}

	/**
	 * Ψάχνει το shortcode στο περιεχόμενο και στα synced patterns (`wp:block`
	 * ref), που το has_shortcode() δεν βλέπει. Ακολουθούνται μόνο δημοσιευμένα
	 * wp_block χωρίς κωδικό (όσα αποδίδει ο core), έως MAX_PATTERN_REFS, μία
	 * φορά το καθένα (προστασία από κύκλους και N×M φόρτωση).
	 *
	 * @param string $content Το περιεχόμενο προς έλεγχο.
	 * @param int    $depth   Τρέχον βάθος φωλιάσματος.
	 * @return bool
	 */
	public static function content_has_tool( $content, $depth = 0 ) {
		$visited = array();

		return self::scan( (string) $content, (int) $depth, $visited );
	}

	/** @param array $visited ref => true για τα patterns που έχουν ήδη φορτωθεί. */
	private static function scan( $content, $depth, array &$visited ) {
		if ( '' === $content ) {
			return false;
		}

		if ( has_shortcode( $content, self::SHORTCODE_TAG ) ) {
			return true;
		}

		if ( $depth >= self::MAX_PATTERN_DEPTH || ! function_exists( 'parse_blocks' ) || false === strpos( $content, 'wp:block' ) ) {
			return false;
		}

		foreach ( self::block_refs( parse_blocks( $content ) ) as $ref ) {
			if ( isset( $visited[ $ref ] ) ) {
				continue;
			}

			if ( count( $visited ) >= self::MAX_PATTERN_REFS ) {
				return false;
			}

			$visited[ $ref ] = true;
			$reusable        = get_post( $ref );

			if ( ! $reusable instanceof WP_Post
				|| 'wp_block' !== $reusable->post_type
				|| 'publish' !== $reusable->post_status
				|| '' !== (string) $reusable->post_password ) {
				continue;
			}

			if ( self::scan( (string) $reusable->post_content, $depth + 1, $visited ) ) {
				return true;
			}
		}

		return false;
	}

	/** Μαζεύει τα ref όλων των core/block, όσο βαθιά κι αν είναι φωλιασμένα. */
	private static function block_refs( $blocks ) {
		$refs = array();

		foreach ( (array) $blocks as $block ) {
			if ( isset( $block['blockName'], $block['attrs']['ref'] ) && 'core/block' === $block['blockName'] ) {
				$refs[] = (int) $block['attrs']['ref'];
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$refs = array_merge( $refs, self::block_refs( $block['innerBlocks'] ) );
			}
		}

		return array_unique( array_filter( $refs ) );
	}
}
