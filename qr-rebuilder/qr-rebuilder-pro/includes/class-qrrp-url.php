<?php
/**
 * Έλεγχος προέλευσης URL, κοινός για QRRP_Mailer και QRRP_Shortcode. Κλάση και
 * όχι global συνάρτηση, ώστε να φορτώνεται και από το uninstall.php.
 *
 * @package QR_ReBuilder_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QRRP_Url {

	/**
	 * Ανήκει το URL στον ίδιο ιστότοπο; Απορρίπτει μη αναλύσιμο URL, credentials
	 * (`https://x@site.gr/`), scheme εκτός http/https ή διαφορετικό από του
	 * home_url() — και πίσω από proxy, fail-closed —, άλλο host και άλλη ενεργή
	 * θύρα (η προεπιλεγμένη κανονικοποιείται). Το path ελέγχει ο καλών.
	 *
	 * @param string $url URL προς έλεγχο.
	 * @return bool
	 */
	public static function is_same_site( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );

		if ( ! is_array( $parts ) || ! is_array( $home ) ) {
			return false;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$expect = isset( $home['scheme'] ) ? strtolower( (string) $home['scheme'] ) : '';

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || $scheme !== $expect ) {
			return false;
		}

		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$dest = isset( $home['host'] ) ? strtolower( (string) $home['host'] ) : '';

		if ( '' === $host || '' === $dest || $host !== $dest ) {
			return false;
		}

		$port     = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		$expected = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === $expect ? 443 : 80 );

		return $port === $expected;
	}
}
