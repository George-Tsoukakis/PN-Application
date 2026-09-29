<?php

namespace JobListings\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot of a job listing for display and logic.
 */
final class Listing {

	/**
	 * @var int
	 */
	private $jbli_id;

	/**
	 * @var \WP_Post|null
	 */
	private $jbli_post;

	/**
	 * Raw post meta map (meta_key => array of values).
	 *
	 * @var array<string, array>
	 */
	private $jbli_meta;

	/**
	 * Category term names.
	 *
	 * @var string[]
	 */
	private $jbli_categories;

	/**
	 * Nomos term names.
	 *
	 * @var string[]
	 */
	private $jbli_nomoi;

	/**
	 * @param int                 $jbli_id         Post ID.
	 * @param \WP_Post|null       $jbli_post       Optional post object.
	 * @param array<string,array> $jbli_meta       Optional prefetched meta.
	 * @param string[]            $jbli_categories Category names.
	 * @param string[]            $jbli_nomoi      Nomos names.
	 */
	private function __construct(
		$jbli_id,
		$jbli_post = null,
		array $jbli_meta = array(),
		array $jbli_categories = array(),
		array $jbli_nomoi = array()
	)
	{

		$this->jbli_id         = absint( $jbli_id );
		$this->jbli_post       = $jbli_post instanceof \WP_Post ? $jbli_post : null;
		$this->jbli_meta       = $jbli_meta;
		$this->jbli_categories = $jbli_categories;
		$this->jbli_nomoi      = $jbli_nomoi;

	}

	/**
	 * Hydrate a Listing from a post ID.
	 *
	 * @param int        $jbli_post_id Post ID.
	 * @param array|null $jbli_terms   Optional ['cats'=>[], 'jbli_nomoi'=>[]].
	 * @param array|null $jbli_meta    Optional prefetched meta map.
	 * @return self|null
	 */
	public static function jbli_from_id( $jbli_post_id, ?array $jbli_terms = null, ?array $jbli_meta = null ) {

		$jbli_post_id = absint( $jbli_post_id );

		if ( $jbli_post_id <= 0 ) { return null; }

		$jbli_post = get_post( $jbli_post_id );

		if ( ! $jbli_post instanceof \WP_Post ) { return null; }

		if ( defined( 'JBLI_CPT' ) && JBLI_CPT !== $jbli_post->post_type ) { return null; }

		if ( null === $jbli_meta )
		{
			$jbli_meta = get_post_meta( $jbli_post_id );
			$jbli_meta = is_array( $jbli_meta ) ? $jbli_meta : array();
		}

		if ( null !== $jbli_terms && isset( $jbli_terms['cats'], $jbli_terms['jbli_nomoi'] ) )
		{
			$jbli_cats  = is_array( $jbli_terms['cats'] ) ? $jbli_terms['cats'] : array();
			$jbli_nomoi = is_array( $jbli_terms['jbli_nomoi'] ) ? $jbli_terms['jbli_nomoi'] : array();
		}

		else
		{
			$jbli_cats = wp_get_post_terms( $jbli_post_id, 'job_category', array( 'fields' => 'names' ) );
			$jbli_cats = ! is_wp_error( $jbli_cats ) && is_array( $jbli_cats ) ? $jbli_cats : array();

			$jbli_nomoi = wp_get_post_terms( $jbli_post_id, 'job_nomos', array( 'fields' => 'names' ) );
			$jbli_nomoi = ! is_wp_error( $jbli_nomoi ) && is_array( $jbli_nomoi ) ? $jbli_nomoi : array();
		}

		return new self(
			$jbli_post_id,
			$jbli_post,
			$jbli_meta,
			$jbli_cats,
			$jbli_nomoi
		);

	}

	/**
	 * @param \WP_Post   $jbli_post  Post object.
	 * @param array|null $jbli_terms Optional bulk terms.
	 * @return self|null
	 */
	public static function jbli_from_post( \WP_Post $jbli_post, ?array $jbli_terms = null ) {

		return self::jbli_from_id( (int) $jbli_post->ID, $jbli_terms );

	}

	/**
	 * @return int
	 */
	public function jbli_id() {

		return $this->jbli_id;

	}

	/**
	 * @return \WP_Post|null
	 */
	public function jbli_post() {

		if ( null === $this->jbli_post )
		{
			$jbli_post = get_post( $this->jbli_id );
			$this->jbli_post = $jbli_post instanceof \WP_Post ? $jbli_post : null;
		}

		return $this->jbli_post;

	}

	/**
	 * @return string
	 */
	public function jbli_position() {

		return $this->jbli_meta_string( defined( 'JBLI_META_POSITION' ) ? JBLI_META_POSITION : 'jbli_position' );

	}

	/**
	 * @return string
	 */
	public function jbli_pharmacy_name() {

		return $this->jbli_meta_string( defined( 'JBLI_META_PHARMACY_NAME' ) ? JBLI_META_PHARMACY_NAME : 'jbli_pharmacy_name' );

	}

	/**
	 * @return string
	 */
	public function jbli_salary() {

		return $this->jbli_meta_string( defined( 'JBLI_META_SALARY' ) ? JBLI_META_SALARY : 'jbli_salary' );

	}

	/**
	 * @return string
	 */
	public function jbli_type() {

		return $this->jbli_meta_string( defined( 'JBLI_META_TYPE' ) ? JBLI_META_TYPE : 'jbli_type' );

	}

	/**
	 * @return string
	 */
	public function jbli_address() {

		return $this->jbli_meta_string( defined( 'JBLI_META_ADDRESS' ) ? JBLI_META_ADDRESS : 'jbli_address' );

	}

	/**
	 * @return string
	 */
	public function jbli_contact_phone() {

		return $this->jbli_meta_string( defined( 'JBLI_META_CONTACT_PHONE' ) ? JBLI_META_CONTACT_PHONE : 'jbli_contact_phone' );

	}

	/**
	 * @return string
	 */
	public function jbli_email() {

		return $this->jbli_meta_string( defined( 'JBLI_META_EMAIL' ) ? JBLI_META_EMAIL : 'jbli_email' );

	}

	/**
	 * @return bool
	 */
	public function jbli_is_featured() {

		$jbli_key = defined( 'JBLI_META_FEATURED' ) ? JBLI_META_FEATURED : 'jbli_featured';

		return 1 === (int) $this->jbli_meta_string( $jbli_key );

	}

	/**
	 * @return bool
	 */
	public function jbli_is_expired() {

		$jbli_key = defined( 'JBLI_META_EXPIRED' ) ? JBLI_META_EXPIRED : 'jbli_expired';

		if ( $this->jbli_meta_string( $jbli_key ) ) { return true; }

		$jbli_post = $this->jbli_post();

		return $jbli_post && 'job-expired' === $jbli_post->post_status;

	}

	/**
	 * @return int
	 */
	public function jbli_views() {

		$jbli_key = defined( 'JBLI_META_VIEWS' ) ? JBLI_META_VIEWS : 'jbli_views';

		return absint( $this->jbli_meta_string( $jbli_key ) );

	}

	/**
	 * @return string[]
	 */
	public function jbli_categories() {

		return $this->jbli_categories;

	}

	/**
	 * @return string[]
	 */
	public function jbli_nomoi() {

		return $this->jbli_nomoi;

	}

	/**
	 * @return string
	 */
	public function jbli_permalink() {

		$jbli_url = get_permalink( $this->jbli_id );

		return is_string( $jbli_url ) ? $jbli_url : '';

	}

	/**
	 * @return string
	 */
	public function jbli_title() {

		$jbli_position = $this->jbli_position();

		if ( '' !== $jbli_position ) { return $jbli_position; }

		$jbli_post = $this->jbli_post();

		return $jbli_post ? (string) $jbli_post->post_title : '';

	}

	/**
	 * Card-oriented data array (compatible with jbli_get_card_data()).
	 *
	 * @return array<string, mixed>
	 */
	public function jbli_to_card_array() {

		return array(
			'jbli_id'          => $this->jbli_id,
			'jbli_position'    => $this->jbli_position(),
			'pharmacy'         => $this->jbli_pharmacy_name(),
			'jbli_salary'      => $this->jbli_salary(),
			'jbli_type'        => $this->jbli_type(),
			'cats'             => $this->jbli_categories,
			'jbli_nomoi'       => $this->jbli_nomoi,
			'jbli_is_featured' => $this->jbli_is_featured(),
			'jbli_views'       => $this->jbli_views(),
		);

	}

	/**
	 * @param string $jbli_key Meta key.
	 * @return string
	 */
	private function jbli_meta_string( $jbli_key ) {

		if ( ! isset( $this->jbli_meta[ $jbli_key ][0] ) ) { return ''; }

		return (string) $this->jbli_meta[ $jbli_key ][0];

	}

}
