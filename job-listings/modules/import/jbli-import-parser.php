<?php
/**
 * Import: fetch an external job ad and turn it into listing fields.
 *
 * Reads the schema.org JobPosting JSON-LD that job boards publish for
 * Google Jobs (jobfind.gr, kariera.gr, xe.gr, …); falls back to OpenGraph /
 * <title> / meta description when a page has none. Everything returned is
 * sanitized and ready to save.
 *
 * @package JobListings
 * @since   9.9.53
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lower-case, accent-free Greek/Latin text for matching.
 *
 * @param string $jbli_text Text.
 * @return string
 */
function jbli_import_norm( $jbli_text ) {

	$jbli_text = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $jbli_text, 'UTF-8' ) : strtolower( (string) $jbli_text );

	return strtr( $jbli_text, array(
		'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ϊ' => 'ι', 'ΐ' => 'ι', 'ό' => 'ο',
		'ύ' => 'υ', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ώ' => 'ω', 'ς' => 'σ',
	) );

}

/**
 * Fetch a URL (external hosts only) and return its HTML.
 *
 * @param string $jbli_url URL.
 * @return string|WP_Error
 */
function jbli_import_fetch( $jbli_url ) {

	$jbli_response = wp_safe_remote_get(
		$jbli_url,
		array(
			'timeout'             => 20,
			'redirection'         => 5,
			/* 9.9.57: an ad page is a few hundred KB; don't load huge responses into memory. */
			'limit_response_size' => 3 * MB_IN_BYTES,
			/* A regular browser UA: many sites' firewalls answer 403 to unknown bots. */
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
			'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'el-GR,el;q=0.9,en;q=0.6' ),
		)
	);

	if ( is_wp_error( $jbli_response ) ) { return $jbli_response; }

	$jbli_code = (int) wp_remote_retrieve_response_code( $jbli_response );

	if ( 200 !== $jbli_code )
	{
		/* translators: %d: HTTP status code */
		return new WP_Error( 'jbli_import_http', sprintf( __( 'Η σελίδα απάντησε με κωδικό %d.', 'job-listings' ), $jbli_code ) );
	}

	$jbli_html = (string) wp_remote_retrieve_body( $jbli_response );

	if ( '' === trim( $jbli_html ) ) { return new WP_Error( 'jbli_import_empty', __( 'Η σελίδα είναι κενή.', 'job-listings' ) ); }

	return $jbli_html;

}

/**
 * Find the first JobPosting object in the page's JSON-LD blocks.
 *
 * @param string $jbli_html Page HTML.
 * @return array JobPosting data, or empty array.
 */
function jbli_import_find_jobposting( $jbli_html ) {

	if ( ! preg_match_all( '~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $jbli_html, $jbli_m ) ) { return array(); }

	foreach ( $jbli_m[1] as $jbli_raw ) {

		$jbli_data = json_decode( html_entity_decode( trim( $jbli_raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );

		if ( null === $jbli_data ) { $jbli_data = json_decode( trim( $jbli_raw ), true ); }

		$jbli_found = jbli_import_walk_jobposting( $jbli_data );

		if ( $jbli_found ) { return $jbli_found; }

	}

	return array();

}

/**
 * Recursively look for "@type": "JobPosting" (arrays, @graph, nesting).
 *
 * @param mixed $jbli_node JSON node.
 * @return array
 */
function jbli_import_walk_jobposting( $jbli_node ) {

	if ( ! is_array( $jbli_node ) ) { return array(); }

	$jbli_type = $jbli_node['@type'] ?? '';

	if ( 'JobPosting' === $jbli_type || ( is_array( $jbli_type ) && in_array( 'JobPosting', $jbli_type, true ) ) ) { return $jbli_node; }

	foreach ( $jbli_node as $jbli_child ) {

		$jbli_found = jbli_import_walk_jobposting( $jbli_child );

		if ( $jbli_found ) { return $jbli_found; }

	}

	return array();

}

/**
 * Read a <meta> tag value (property= or name=).
 *
 * @param string $jbli_html Page HTML.
 * @param string $jbli_key  og:title, description, …
 * @return string
 */
function jbli_import_meta( $jbli_html, $jbli_key ) {

	$jbli_key = preg_quote( $jbli_key, '~' );

	if ( preg_match( '~<meta[^>]+(?:property|name)=["\']' . $jbli_key . '["\'][^>]*content=["\']([^"\']*)["\']~i', $jbli_html, $jbli_m )
		|| preg_match( '~<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']' . $jbli_key . '["\']~i', $jbli_html, $jbli_m ) )
	{
		return trim( html_entity_decode( $jbli_m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	return '';

}

/**
 * Plain string from a JSON-LD value that may be a string, object or list.
 *
 * @param mixed  $jbli_value Value.
 * @param string $jbli_key   Key to read from an object (e.g. 'name').
 * @return string
 */
function jbli_import_str( $jbli_value, $jbli_key = 'name' ) {

	if ( is_string( $jbli_value ) || is_numeric( $jbli_value ) ) { return trim( (string) $jbli_value ); }

	if ( is_array( $jbli_value ) )
	{
		if ( isset( $jbli_value[ $jbli_key ] ) ) { return jbli_import_str( $jbli_value[ $jbli_key ], $jbli_key ); }

		if ( isset( $jbli_value[0] ) ) { return jbli_import_str( $jbli_value[0], $jbli_key ); }
	}

	return '';

}

/**
 * Whether a (normalized) word or word-start appears in the text.
 * "αρτα" matches "Άρτα"/"Άρτας" but not "κάρτα".
 *
 * @param string $jbli_text   Normalized text.
 * @param string $jbli_needle Normalized word start.
 * @return bool
 */
function jbli_import_has_word( $jbli_text, $jbli_needle ) {

	return '' !== $jbli_needle && 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $jbli_needle, '/' ) . '/u', $jbli_text );

}

/**
 * Map a place (region/city text) to a job_nomos term ID.
 *
 * @param string $jbli_text Region, city, address or free text.
 * @return int Term ID or 0.
 */
function jbli_import_match_nomos( $jbli_text ) {

	$jbli_text = ' ' . jbli_import_norm( $jbli_text ) . ' ';

	if ( '' === trim( $jbli_text ) ) { return 0; }

	/* City / English name → nomos term name. */
	$jbli_aliases = array(
		'αθηνα' => 'Αττική', 'athens' => 'Αττική', 'athina' => 'Αττική', 'attica' => 'Αττική', 'attiki' => 'Αττική', 'πειραια' => 'Αττική', 'piraeus' => 'Αττική',
		'γλυφαδα' => 'Αττική', 'μαρουσι' => 'Αττική', 'κηφισια' => 'Αττική', 'χαλανδρι' => 'Αττική', 'περιστερι' => 'Αττική', 'καλλιθεα' => 'Αττική', 'αιγαλεω' => 'Αττική', 'νεα σμυρνη' => 'Αττική',
		'thessaloniki' => 'Θεσσαλονίκη', 'πατρα' => 'Αχαΐα', 'patra' => 'Αχαΐα', 'heraklion' => 'Ηράκλειο', 'irakleio' => 'Ηράκλειο', 'larisa' => 'Λάρισα', 'larissa' => 'Λάρισα',
		'βολο' => 'Μαγνησία', 'volos' => 'Μαγνησία', 'ioannina' => 'Ιωάννινα', 'chania' => 'Χανιά', 'ροδο' => 'Δωδεκάνησος', 'rhodes' => 'Δωδεκάνησος',
		'corfu' => 'Κέρκυρα', 'kerkyra' => 'Κέρκυρα', 'καλαματα' => 'Μεσσηνία', 'kalamata' => 'Μεσσηνία', 'κομοτηνη' => 'Ροδόπη', 'kavala' => 'Καβάλα', 'serres' => 'Σέρρες',
		'κατερινη' => 'Πιερία', 'βεροια' => 'Ημαθία', 'λαμια' => 'Φθιώτιδα', 'χαλκιδα' => 'Ευβοία', 'ευβοια' => 'Ευβοία', 'τριπολη' => 'Αρκαδία', 'αλεξανδρουπολη' => 'Έβρος',
		'αγιοσ νικολαοσ' => 'Λασίθι', 'μυτιληνη' => 'Λέσβος', 'κορινθο' => 'Κορινθία', 'ναυπλιο' => 'Αργολίδα', 'αργοσ' => 'Αργολίδα', 'πυργοσ' => 'Ηλεία',
		'αγρινιο' => 'Αιτωλία & Ακαρνανία', 'μεσολογγι' => 'Αιτωλία & Ακαρνανία', 'αιτωλοακαρνανια' => 'Αιτωλία & Ακαρνανία', 'λιβαδεια' => 'Βοιωτία', 'θηβα' => 'Βοιωτία',
		'σπαρτη' => 'Λακωνία', 'γιαννιτσα' => 'Πέλλα', 'εδεσσα' => 'Πέλλα', 'ηγουμενιτσα' => 'Θεσπρωτία', 'αργοστολι' => 'Κεφαλληνία', 'κεφαλονια' => 'Κεφαλληνία',
		'συροσ' => 'Κυκλάδες', 'ερμουπολη' => 'Κυκλάδες', 'σαντορινη' => 'Κυκλάδες', 'μυκονο' => 'Κυκλάδες', 'παρο' => 'Κυκλάδες', 'ναξο' => 'Κυκλάδες',
		'πολυγυρο' => 'Χαλκιδική', 'αμφισσα' => 'Φωκίδα', 'καρπενησι' => 'Ευρυτανία',
	);

	$jbli_terms = function_exists( 'jbli_get_terms' ) ? jbli_get_terms( 'job_nomos' ) : get_terms( array( 'taxonomy' => 'job_nomos', 'hide_empty' => false ) );
	$jbli_terms = is_array( $jbli_terms ) ? $jbli_terms : array();

	/* 1. The nomos name itself appears in the text (first 5 letters, so cases/endings still match). */
	foreach ( $jbli_terms as $jbli_term ) {

		if ( ! $jbli_term instanceof WP_Term ) { continue; }

		$jbli_stem = function_exists( 'mb_substr' ) ? mb_substr( jbli_import_norm( $jbli_term->name ), 0, 5, 'UTF-8' ) : substr( jbli_import_norm( $jbli_term->name ), 0, 5 );

		if ( '' !== $jbli_stem && jbli_import_has_word( $jbli_text, $jbli_stem ) ) { return (int) $jbli_term->term_id; }

	}

	/* 2. A known city / English name. */
	foreach ( $jbli_aliases as $jbli_alias => $jbli_nomos_name ) {

		if ( ! jbli_import_has_word( $jbli_text, trim( $jbli_alias ) ) ) { continue; }

		foreach ( $jbli_terms as $jbli_term ) {

			if ( $jbli_term instanceof WP_Term && jbli_import_norm( $jbli_term->name ) === jbli_import_norm( $jbli_nomos_name ) ) { return (int) $jbli_term->term_id; }

		}

	}

	return 0;

}

/**
 * Guess the job_category from the title (then the description).
 *
 * @param string $jbli_title Title.
 * @param string $jbli_desc  Description (plain text).
 * @return int Term ID or 0.
 */
function jbli_import_match_category( $jbli_title, $jbli_desc ) {

	/* Keyword → category name; order matters (most specific first). */
	$jbli_rules = array(
		'παραφαρμακ'          => 'Υπεύθυνος Παραφαρμακείου',
		'e-shop'              => 'Υπεύθυνος E-shop Φαρμακείου',
		'eshop'               => 'Υπεύθυνος E-shop Φαρμακείου',
		'ηλεκτρονικου καταστ' => 'Υπεύθυνος E-shop Φαρμακείου',
		'delivery'            => 'Delivery / Διανομέας',
		'διανομ'              => 'Delivery / Διανομέας',
		'κουριερ'             => 'Delivery / Διανομέας',
		'αισθητικ'            => 'Αισθητικός / Beauty Advisor',
		'beauty'              => 'Αισθητικός / Beauty Advisor',
		'καλλυντικ'           => 'Αισθητικός / Beauty Advisor',
		'αποθηκ'              => 'Αποθηκάριος',
		'τεχνικ'              => 'Τεχνικός Φαρμάκων',
		'βοηθ'                => 'Βοηθός Φαρμακείου',
		'υπευθυνοσ φαρμακει'  => 'Υπεύθυνος Φαρμακείου',
		'φαρμακοποι'          => 'Φαρμακοποιός',
		'pharmacist'          => 'Φαρμακοποιός',
	);

	$jbli_terms = function_exists( 'jbli_get_terms' ) ? jbli_get_terms( 'job_category' ) : get_terms( array( 'taxonomy' => 'job_category', 'hide_empty' => false ) );
	$jbli_terms = is_array( $jbli_terms ) ? $jbli_terms : array();

	foreach ( array( $jbli_title, $jbli_desc ) as $jbli_source ) {

		$jbli_text = jbli_import_norm( $jbli_source );

		foreach ( $jbli_rules as $jbli_needle => $jbli_cat_name ) {

			if ( false === strpos( $jbli_text, $jbli_needle ) ) { continue; }

			foreach ( $jbli_terms as $jbli_term ) {

				if ( $jbli_term instanceof WP_Term && jbli_import_norm( $jbli_term->name ) === jbli_import_norm( $jbli_cat_name ) ) { return (int) $jbli_term->term_id; }

			}

		}

	}

	return 0;

}

/**
 * Employment type key from schema.org employmentType or the text.
 *
 * @param mixed  $jbli_employment JSON-LD employmentType.
 * @param string $jbli_text       Title + description.
 * @return string Option key ('' when unknown).
 */
function jbli_import_match_type( $jbli_employment, $jbli_text ) {

	$jbli_employment = strtoupper( implode( ' ', (array) $jbli_employment ) );
	$jbli_text       = jbli_import_norm( $jbli_text );

	if ( false !== strpos( $jbli_employment, 'PART_TIME' ) || false !== strpos( $jbli_text, 'μερικη απασχ' ) ) { $jbli_key = 'meriki-apasxolisi'; }
	elseif ( false !== strpos( $jbli_employment, 'TEMPORARY' ) || false !== strpos( $jbli_employment, 'SEASONAL' ) || false !== strpos( $jbli_text, 'εποχικ' ) ) { $jbli_key = 'epoximaki-apasxolisi'; }
	elseif ( false !== strpos( $jbli_employment, 'FULL_TIME' ) || false !== strpos( $jbli_text, 'πληρη απασχ' ) || false !== strpos( $jbli_text, 'πληρουσ απασχ' ) ) { $jbli_key = 'plires-apasxolisi'; }
	else { return ''; }

	return array_key_exists( $jbli_key, jbli_type_options() ) ? $jbli_key : '';

}

/**
 * Salary option key from schema.org baseSalary (monthly amount).
 *
 * @param mixed $jbli_base baseSalary.
 * @return string Option key ('negotiable' when unknown and available).
 */
function jbli_import_match_salary( $jbli_base ) {

	$jbli_options = jbli_salary_options();
	$jbli_amount  = 0.0;

	if ( is_array( $jbli_base ) )
	{
		$jbli_value = $jbli_base['value'] ?? $jbli_base;
		$jbli_unit  = strtoupper( (string) ( $jbli_value['unitText'] ?? $jbli_base['unitText'] ?? 'MONTH' ) );
		$jbli_min   = (float) ( $jbli_value['minValue'] ?? $jbli_value['value'] ?? 0 );
		$jbli_max   = (float) ( $jbli_value['maxValue'] ?? $jbli_min );
		$jbli_amount = $jbli_min && $jbli_max ? ( $jbli_min + $jbli_max ) / 2 : max( $jbli_min, $jbli_max );

		if ( 'YEAR' === $jbli_unit ) { $jbli_amount /= 14; }
		if ( 'HOUR' === $jbli_unit ) { $jbli_amount *= 160; }
	}

	if ( $jbli_amount > 0 )
	{
		foreach ( array_keys( $jbli_options ) as $jbli_key ) {

			if ( preg_match( '~^(\d+)-(\d+)$~', (string) $jbli_key, $jbli_r ) && $jbli_amount >= (float) $jbli_r[1] && $jbli_amount <= (float) $jbli_r[2] ) { return (string) $jbli_key; }

			if ( preg_match( '~^(\d+)\+$~', (string) $jbli_key, $jbli_r ) && $jbli_amount >= (float) $jbli_r[1] ) { return (string) $jbli_key; }

		}
	}

	return array_key_exists( 'negotiable', $jbli_options ) ? 'negotiable' : (string) ( array_key_first( $jbli_options ) ?? '' );

}

/**
 * Parse an external ad page into listing fields.
 *
 * @param string $jbli_html Page HTML.
 * @param string $jbli_url  Page URL (for the fallback title / source).
 * @return array|WP_Error {
 *     position, description (HTML), pharmacy, nomos_id, category_id, type, salary,
 *     address, phone, email, expires (Y-m-d H:i:s or ''), warnings (string[]), via ('jsonld'|'meta')
 * }
 */
function jbli_import_parse( $jbli_html, $jbli_url ) {

	$jbli_job      = jbli_import_find_jobposting( $jbli_html );
	$jbli_warnings = array();

	if ( $jbli_job )
	{
		$jbli_via      = 'jsonld';
		$jbli_position = jbli_import_str( $jbli_job['title'] ?? '' );
		$jbli_desc     = (string) ( $jbli_job['description'] ?? '' );
		$jbli_pharmacy = jbli_import_str( $jbli_job['hiringOrganization'] ?? '' );

		$jbli_loc      = $jbli_job['jobLocation'] ?? array();
		$jbli_loc      = isset( $jbli_loc[0] ) ? $jbli_loc[0] : $jbli_loc;
		$jbli_addr     = is_array( $jbli_loc ) ? ( $jbli_loc['address'] ?? array() ) : array();
		$jbli_addr     = is_array( $jbli_addr ) ? $jbli_addr : array( 'streetAddress' => (string) $jbli_addr );
		$jbli_region   = trim( implode( ', ', array_filter( array(
			jbli_import_str( $jbli_addr['addressRegion'] ?? '' ),
			jbli_import_str( $jbli_addr['addressLocality'] ?? '' ),
		) ) ) );
		$jbli_address  = trim( implode( ', ', array_filter( array(
			jbli_import_str( $jbli_addr['streetAddress'] ?? '' ),
			jbli_import_str( $jbli_addr['addressLocality'] ?? '' ),
		) ) ) );

		$jbli_employment = $jbli_job['employmentType'] ?? '';
		$jbli_salary_src = $jbli_job['baseSalary'] ?? null;
		$jbli_valid      = jbli_import_str( $jbli_job['validThrough'] ?? '' );
	} else {
		$jbli_via        = 'meta';
		$jbli_position   = jbli_import_meta( $jbli_html, 'og:title' );
		$jbli_desc       = jbli_import_meta( $jbli_html, 'og:description' ) ?: jbli_import_meta( $jbli_html, 'description' );
		$jbli_pharmacy   = '';
		$jbli_region     = '';
		$jbli_address    = '';
		$jbli_employment = '';
		$jbli_salary_src = null;
		$jbli_valid      = '';

		if ( '' === $jbli_position && preg_match( '~<title[^>]*>(.*?)</title>~is', $jbli_html, $jbli_t ) )
		{
			$jbli_position = trim( html_entity_decode( wp_strip_all_tags( $jbli_t[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		$jbli_warnings[] = __( 'Η σελίδα δεν έχει δομημένα δεδομένα αγγελίας· χρησιμοποιήθηκαν μόνο τίτλος και περιγραφή. Ελέγξτε όλα τα πεδία.', 'job-listings' );
	}

	/* Titles from boards often carry " - Company - Location | Site" tails. */
	$jbli_position = trim( (string) preg_replace( '~\s*[|–—]\s*[^|–—]*$~u', '', html_entity_decode( $jbli_position, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	$jbli_position = sanitize_text_field( $jbli_position );

	if ( '' === $jbli_position ) { return new WP_Error( 'jbli_import_notitle', __( 'Δεν βρέθηκε τίτλος αγγελίας στη σελίδα.', 'job-listings' ) ); }

	$jbli_desc_html  = wp_kses( html_entity_decode( $jbli_desc, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), jbli_allowed_html() );
	$jbli_desc_plain = trim( wp_strip_all_tags( $jbli_desc_html ) );

	if ( '' === $jbli_desc_plain )
	{
		$jbli_desc_html  = esc_html__( 'Δείτε την πλήρη περιγραφή στην αρχική αγγελία.', 'job-listings' );
		$jbli_warnings[] = __( 'Δεν βρέθηκε περιγραφή.', 'job-listings' );
	}

	if ( '' === $jbli_desc_plain || false === strpos( $jbli_desc_html, '<' ) ) { $jbli_desc_html = wpautop( $jbli_desc_html ); }

	$jbli_all_text = $jbli_position . ' ' . $jbli_desc_plain;

	$jbli_nomos_id = jbli_import_match_nomos( $jbli_region . ' ' . $jbli_address );

	if ( ! $jbli_nomos_id ) { $jbli_nomos_id = jbli_import_match_nomos( $jbli_all_text ); }

	if ( ! $jbli_nomos_id ) { $jbli_warnings[] = __( 'Δεν αναγνωρίστηκε νομός — ορίστε τον χειροκίνητα.', 'job-listings' ); }

	$jbli_category_id = jbli_import_match_category( $jbli_position, $jbli_desc_plain );

	if ( ! $jbli_category_id ) { $jbli_warnings[] = __( 'Δεν αναγνωρίστηκε κατηγορία — ορίστε την χειροκίνητα.', 'job-listings' ); }

	$jbli_type = jbli_import_match_type( $jbli_employment, $jbli_all_text );

	if ( '' === $jbli_type )
	{
		$jbli_type       = array_key_exists( 'plires-apasxolisi', jbli_type_options() ) ? 'plires-apasxolisi' : (string) array_key_first( jbli_type_options() );
		$jbli_warnings[] = __( 'Ο τύπος απασχόλησης δεν αναφερόταν — ορίστηκε «Πλήρης».', 'job-listings' );
	}

	$jbli_salary = jbli_import_match_salary( $jbli_salary_src );

	/* Contact details, if the ad text shows them. */
	$jbli_email = '';
	$jbli_phone = '';

	if ( preg_match( '~[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}~i', $jbli_desc_plain, $jbli_e ) && is_email( $jbli_e[0] ) ) { $jbli_email = sanitize_email( $jbli_e[0] ); }

	if ( preg_match( '~(?:\+30[\s-]?)?(?:2\d|69)\d(?:[\s-]?\d){7}~', $jbli_desc_plain, $jbli_p ) ) { $jbli_phone = jbli_sanitize_phone( $jbli_p[0] ); }

	$jbli_expires = '';

	/* validThrough may carry its own offset; a bare date is read as site-local (9.9.57). */
	$jbli_valid_ts = '' !== $jbli_valid ? jbli_local_datetime_to_ts( $jbli_valid ) : false;

	if ( false !== $jbli_valid_ts && $jbli_valid_ts > time() )
	{
		$jbli_expires = wp_date( 'Y-m-d H:i:s', $jbli_valid_ts );
	}

	return array(
		'position'    => $jbli_position,
		'description' => $jbli_desc_html,
		'pharmacy'    => sanitize_text_field( $jbli_pharmacy ),
		'nomos_id'    => $jbli_nomos_id,
		'category_id' => $jbli_category_id,
		'type'        => $jbli_type,
		'salary'      => $jbli_salary,
		'address'     => sanitize_text_field( $jbli_address ),
		'phone'       => $jbli_phone,
		'email'       => $jbli_email,
		'expires'     => $jbli_expires,
		'warnings'    => $jbli_warnings,
		'via'         => $jbli_via,
	);

}
