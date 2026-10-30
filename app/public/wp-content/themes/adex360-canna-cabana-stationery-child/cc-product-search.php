<?php
/**
 * Header / site search relevance — products by title, SKU, ASIN, G&T part.
 * No UI changes to Elementor search (#search-d17f53a).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether this request is a front-end search we should enhance.
 *
 * @param WP_Query $query Query.
 * @return bool
 */
function cc_product_search_is_target_query( $query ) {
	if ( ! $query || ( is_admin() && ! wp_doing_ajax() ) ) {
		return false;
	}
	$s = $query->get( 's' );
	if ( ! is_string( $s ) || trim( $s ) === '' ) {
		return false;
	}
	// Classic search results, or Elementor/AJAX queries that pass `s`.
	if ( $query->is_search() ) {
		return true;
	}
	$pt = $query->get( 'post_type' );
	if ( empty( $pt ) || $pt === 'any' ) {
		return true;
	}
	$pt_list = is_array( $pt ) ? $pt : array( $pt );
	return (bool) array_intersect( $pt_list, array( 'product', 'product_variation', 'any' ) );
}

/**
 * Prefer products in search results.
 *
 * @param WP_Query $query Query.
 */
function cc_product_search_pre_get_posts( $query ) {
	if ( ! cc_product_search_is_target_query( $query ) ) {
		return;
	}

	$post_type = $query->get( 'post_type' );
	if ( empty( $post_type ) || $post_type === 'any' ) {
		$query->set( 'post_type', array( 'product' ) );
	} elseif ( is_string( $post_type ) && $post_type !== 'product' && $post_type !== 'product_variation' ) {
		// Keep multi-type searches but ensure product is included.
		$query->set( 'post_type', array_unique( array( $post_type, 'product' ) ) );
	}

	$query->set( 'post_status', 'publish' );
}
add_action( 'pre_get_posts', 'cc_product_search_pre_get_posts', 30 );

/**
 * Extend search SQL to match SKU / ASIN / G&T part meta.
 *
 * @param string   $search Search SQL.
 * @param WP_Query $query  Query.
 * @return string
 */
function cc_product_search_posts_search( $search, $query ) {
	global $wpdb;

	if ( ! ( $query instanceof WP_Query ) || ! cc_product_search_is_target_query( $query ) ) {
		return $search;
	}

	$term = trim( (string) $query->get( 's' ) );
	if ( $term === '' || ! is_string( $search ) || $search === '' ) {
		return $search;
	}

	$like = '%' . $wpdb->esc_like( $term ) . '%';

	// Resolve matching product IDs by SKU / ASIN / G&T part (avoids broken paren injection).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			WHERE meta_key IN ('_sku', 'amazon_asin', 'wcss_gt_part_id')
			AND meta_value LIKE %s
			LIMIT 200",
			$like
		)
	);
	$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	if ( ! $ids ) {
		return $search;
	}

	$id_list = implode( ',', $ids );
	// Wrap: AND ( (ID IN (...)) OR <original search expression without leading AND> )
	$original = preg_replace( '/^\s*AND\s+/i', '', ltrim( $search ), 1 );
	$search   = " AND ( ({$wpdb->posts}.ID IN ({$id_list})) OR {$original} )";

	return $search;
}
add_filter( 'posts_search', 'cc_product_search_posts_search', 20, 2 );

/**
 * Elementor / Woo product data store searches (AJAX typeahead).
 *
 * @param array $args     WP_Query args / WC args.
 * @param array $request  Request.
 * @return array
 */
function cc_product_search_wc_data_store_query( $args, $request = array() ) {
	// Ensure SKU search flag when searching.
	if ( empty( $args['s'] ) && empty( $request['s'] ) ) {
		return $args;
	}
	$args['search_sku'] = true;
	return $args;
}
add_filter( 'woocommerce_product_data_store_cpt_get_products_query', 'cc_product_search_wc_data_store_query', 20, 2 );

/**
 * Include SKU in Woo product search SQL used by data store.
 *
 * @param string $search_query SQL where fragment.
 * @return string
 */
function cc_product_search_woocommerce_sku( $search_query ) {
	// Woo already has sku search when search_sku — leave default; meta ASIN/part covered via posts_search for WP queries.
	return $search_query;
}
