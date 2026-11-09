<?php
/**
 * Active / inactive vendors.
 *
 * An inactive vendor is left out of pricing, the product form, filters and vendor emails.
 * A product is hidden everywhere on the front end when it has vendors and none of them is
 * active; a product with another active vendor stays and sells through that vendor.
 * Nothing is deleted: activating the vendor again brings everything back.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCSS_Vendor_Status {

	/** Term meta on wcss_vendor. Present = inactive, so new vendors start active. */
	const META = 'wcss_vendor_inactive';

	/** Post meta on products with no active vendor. */
	const HIDDEN = '_cc_vendor_hidden';

	private static $inactive = null;
	private static $hidden   = null;

	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'hide_from_query' ), 40 ); // After the exact-title pin in cc-product-search.php.
		add_action( 'woocommerce_product_query', array( __CLASS__, 'hide_from_wc_query' ), 20 );
		add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'is_visible' ), 20, 2 );
		add_filter( 'woocommerce_related_products', array( __CLASS__, 'drop_hidden' ), 20 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_terms_set' ), 20, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_change' ), 20, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_change' ), 20, 3 );
	}

	/* ---------- Status ---------- */

	/** @return int[] */
	public static function inactive_ids() {
		if ( self::$inactive === null ) {
			global $wpdb;
			self::$inactive = array_map(
				'intval',
				$wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s", self::META ) )
			);
		}
		return self::$inactive;
	}

	public static function is_active( $term_id ) {
		return ! in_array( (int) $term_id, self::inactive_ids(), true );
	}

	/** amazon | gt for live-feed vendors, '' for manual vendors. */
	public static function vendor_key( $slug ) {
		if ( function_exists( 'cc_vendor_prices_normalize_vendor' ) ) {
			return (string) cc_vendor_prices_normalize_vendor( $slug );
		}
		$slug = sanitize_key( (string) $slug );
		if ( $slug === 'amazon' ) {
			return 'amazon';
		}
		return ( $slug === 'grand-toy' || $slug === 'grand_and_toy' ) ? 'gt' : '';
	}

	/** @return array<string,int> live key => term id (first match). */
	public static function live_terms() {
		$out   = array();
		$terms = taxonomy_exists( 'wcss_vendor' ) ? get_terms( array( 'taxonomy' => 'wcss_vendor', 'hide_empty' => false ) ) : array();
		if ( is_wp_error( $terms ) ) {
			return $out;
		}
		foreach ( $terms as $t ) {
			$k = self::vendor_key( $t->slug );
			if ( $k !== '' && ! isset( $out[ $k ] ) ) {
				$out[ $k ] = (int) $t->term_id;
			}
		}
		return $out;
	}

	/** Is the Amazon / G&T vendor active? */
	public static function key_is_active( $key ) {
		if ( ! self::inactive_ids() ) {
			return true;
		}
		$live = self::live_terms();
		return ! isset( $live[ $key ] ) || self::is_active( $live[ $key ] );
	}

	/**
	 * @param WP_Term[] $terms Terms.
	 * @return WP_Term[]
	 */
	public static function only_active( $terms ) {
		return array_values(
			array_filter(
				(array) $terms,
				static function ( $t ) {
					return self::is_active( $t->term_id );
				}
			)
		);
	}

	/* ---------- Which vendors supply a product ---------- */

	/**
	 * Vendors assigned to a product. Manual vendors come from its vendor terms. Amazon / G&T follow
	 * the eligibility the price sync uses; legacy "unset" products count a live vendor when it is
	 * tagged, has its ID set, or already has a price.
	 *
	 * @param int $pid Product ID.
	 * @return int[]
	 */
	public static function assigned_ids( $pid ) {
		$pid      = (int) $pid;
		$term_ids = taxonomy_exists( 'wcss_vendor' ) ? wp_get_object_terms( $pid, 'wcss_vendor', array( 'fields' => 'ids' ) ) : array();
		$term_ids = is_wp_error( $term_ids ) ? array() : array_map( 'intval', $term_ids );
		$live     = self::live_terms();
		$out      = array_values( array_diff( $term_ids, array_values( $live ) ) );

		$el = function_exists( 'wcss_get_procurement_eligibility' ) ? wcss_get_procurement_eligibility( $pid ) : 'unset';
		if ( $el === 'unset' ) {
			$priced = self::priced_keys( $pid );
			$amz    = in_array( $live['amazon'] ?? 0, $term_ids, true ) || isset( $priced['amazon'] ) || get_post_meta( $pid, 'amazon_asin', true ) !== '';
			$gt     = in_array( $live['gt'] ?? 0, $term_ids, true ) || isset( $priced['gt'] ) || get_post_meta( $pid, 'wcss_gt_part_id', true ) !== '';
		} else {
			$amz = in_array( $el, array( 'amazon', 'both' ), true );
			$gt  = in_array( $el, array( 'gt', 'both' ), true );
		}
		if ( $amz && isset( $live['amazon'] ) ) {
			$out[] = $live['amazon'];
		}
		if ( $gt && isset( $live['gt'] ) ) {
			$out[] = $live['gt'];
		}
		return array_values( array_unique( array_map( 'intval', $out ) ) );
	}

	/** @return array<string,bool> */
	private static function priced_keys( $pid ) {
		$out = array();
		if ( ! function_exists( 'cc_vendor_prices_get_for_product' ) ) {
			return $out;
		}
		foreach ( cc_vendor_prices_get_for_product( $pid ) as $row ) {
			if ( $row && (string) $row->status === 'ok' && is_numeric( $row->price ) && (float) $row->price > 0 ) {
				$out[ self::vendor_key( (string) $row->vendor ) ] = true;
			}
		}
		return $out;
	}

	/** The form only lists active vendors, so a save must not drop inactive ones. */
	public static function keep_inactive( $pid, array $ids ) {
		$kept = array_intersect( self::assigned_ids( $pid ), self::inactive_ids() );
		return array_values( array_unique( array_merge( array_map( 'intval', $ids ), $kept ) ) );
	}

	/* ---------- Switching a vendor ---------- */

	/**
	 * @param int  $term_id Vendor term.
	 * @param bool $active  New status.
	 * @return int Products hidden from the front end after the change.
	 */
	public static function set_active( $term_id, $active ) {
		$term_id = (int) $term_id;
		if ( $active ) {
			delete_term_meta( $term_id, self::META );
		} else {
			update_term_meta( $term_id, self::META, '1' );
		}
		self::$inactive = null;
		return self::refresh_all( $term_id );
	}

	/**
	 * Re-check every product, and re-price those supplied by the vendor that changed.
	 *
	 * @param int $changed_term Vendor term, or 0 to only re-check.
	 * @return int Hidden products.
	 */
	public static function refresh_all( $changed_term = 0 ) {
		global $wpdb;
		self::$inactive = null;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status <> 'trash' AND post_status <> 'auto-draft'" );
		foreach ( $ids as $pid ) {
			$pid      = (int) $pid;
			$assigned = self::assigned_ids( $pid );
			self::apply_hidden( $pid, $assigned );
			if ( $changed_term && in_array( (int) $changed_term, $assigned, true ) && function_exists( 'cc_vendor_prices_recompute_cheapest' ) ) {
				cc_vendor_prices_recompute_cheapest( $pid );
				$kids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'", $pid ) );
				foreach ( $kids as $kid ) {
					cc_vendor_prices_recompute_cheapest( (int) $kid );
				}
			}
		}
		self::$hidden = null;
		return count( self::hidden_ids() );
	}

	private static function apply_hidden( $pid, array $assigned ) {
		$hide = $assigned && ! array_diff( $assigned, self::inactive_ids() );
		if ( $hide ) {
			update_post_meta( $pid, self::HIDDEN, '1' );
		} else {
			delete_post_meta( $pid, self::HIDDEN );
		}
		self::$hidden = null;
	}

	public static function on_terms_set( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( $taxonomy === 'wcss_vendor' && self::inactive_ids() ) {
			self::apply_hidden( (int) $object_id, self::assigned_ids( $object_id ) );
		}
	}

	public static function on_meta_change( $meta_id, $object_id, $meta_key ) {
		if ( $meta_key === 'wcss_procurement_eligibility' && self::inactive_ids() ) {
			self::apply_hidden( (int) $object_id, self::assigned_ids( $object_id ) );
		}
	}

	/* ---------- Hiding products ---------- */

	/** @return int[] */
	public static function hidden_ids() {
		if ( self::$hidden === null ) {
			global $wpdb;
			self::$hidden = array_map(
				'intval',
				$wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::HIDDEN ) )
			);
		}
		return self::$hidden;
	}

	private static function exclude( $query ) {
		$hidden = self::hidden_ids();
		if ( ! $hidden ) {
			return;
		}
		// WP_Query ignores post__not_in when post__in is set (product search and "include" use it).
		$in = (array) $query->get( 'post__in' );
		if ( $in ) {
			$left = array_diff( array_map( 'intval', $in ), $hidden );
			$query->set( 'post__in', $left ? array_values( $left ) : array( 0 ) );
			return;
		}
		$query->set( 'post__not_in', array_values( array_unique( array_merge( (array) $query->get( 'post__not_in' ), $hidden ) ) ) );
	}

	/** wp-admin and the background price sync still see every product. */
	public static function hide_from_query( $query ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$type = $query->get( 'post_type' );
		if ( $type === 'product' || $type === 'any' || ( is_array( $type ) && in_array( 'product', $type, true ) ) || $query->is_search() ) {
			self::exclude( $query );
		}
	}

	/** Category pages have no post type on the query yet. */
	public static function hide_from_wc_query( $query ) {
		self::exclude( $query );
	}

	public static function is_visible( $visible, $product_id ) {
		return $visible && ! in_array( (int) $product_id, self::hidden_ids(), true );
	}

	public static function drop_hidden( $ids ) {
		return array_values( array_diff( array_map( 'intval', (array) $ids ), self::hidden_ids() ) );
	}
}
WCSS_Vendor_Status::init();
