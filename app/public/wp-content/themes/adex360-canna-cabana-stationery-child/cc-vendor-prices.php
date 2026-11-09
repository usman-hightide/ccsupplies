<?php
/**
 * Vendor price cache (Amazon + Grand & Toy) — schema, CRUD, cheapest compute.
 *
 * Frontend reads cached rows / `_cc_cheapest_*` meta only. Cron sync writes here.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CC_VENDOR_PRICES_DB_VERSION', '1.0.0' );
define( 'CC_VENDOR_PRICES_OPTION', 'cc_vendor_prices_db_version' );

/**
 * Table name.
 *
 * @return string
 */
function cc_vendor_prices_table() {
	global $wpdb;
	return $wpdb->prefix . 'cc_vendor_prices';
}

/**
 * Create / upgrade table.
 */
function cc_vendor_prices_install() {
	global $wpdb;
	$table   = cc_vendor_prices_table();
	$charset = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		product_id BIGINT UNSIGNED NOT NULL,
		vendor VARCHAR(32) NOT NULL,
		vendor_sku VARCHAR(64) NOT NULL DEFAULT '',
		price DECIMAL(18,4) NULL,
		currency CHAR(3) NOT NULL DEFAULT 'CAD',
		status VARCHAR(20) NOT NULL DEFAULT 'missing_id',
		error_message TEXT NULL,
		raw_hash CHAR(40) NULL,
		fetched_at DATETIME NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY product_vendor (product_id, vendor),
		KEY vendor_status (vendor, status),
		KEY fetched_at (fetched_at)
	) {$charset};";

	dbDelta( $sql );
	update_option( CC_VENDOR_PRICES_OPTION, CC_VENDOR_PRICES_DB_VERSION, false );
}

/**
 * Ensure table exists (theme switch / first load).
 */
function cc_vendor_prices_maybe_install() {
	$installed = get_option( CC_VENDOR_PRICES_OPTION, '' );
	if ( $installed === CC_VENDOR_PRICES_DB_VERSION ) {
		global $wpdb;
		$table = cc_vendor_prices_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists === $table ) {
			return;
		}
	}
	cc_vendor_prices_install();
}
add_action( 'after_setup_theme', 'cc_vendor_prices_maybe_install', 5 );
add_action( 'admin_init', 'cc_vendor_prices_maybe_install', 5 );

/**
 * Allowed vendor keys stored in DB (map G&T compare key → gt).
 *
 * @return string[]
 */
function cc_vendor_prices_allowed_vendors() {
	return array( 'amazon', 'gt' );
}

/**
 * Normalize vendor key from compare helpers.
 *
 * @param string $vendor Raw vendor.
 * @return string amazon|gt|''
 */
function cc_vendor_prices_normalize_vendor( $vendor ) {
	$vendor = sanitize_key( (string) $vendor );
	if ( $vendor === 'grand_and_toy' || $vendor === 'grand-toy' ) {
		return 'gt';
	}
	return in_array( $vendor, cc_vendor_prices_allowed_vendors(), true ) ? $vendor : '';
}

/**
 * Upsert one vendor price row.
 *
 * @param int                  $product_id Product ID.
 * @param string               $vendor     amazon|gt.
 * @param array<string, mixed> $data       Row fields.
 * @return bool
 */
function cc_vendor_prices_upsert( $product_id, $vendor, array $data ) {
	global $wpdb;

	$product_id = (int) $product_id;
	$vendor     = cc_vendor_prices_normalize_vendor( $vendor );
	if ( $product_id <= 0 || $vendor === '' ) {
		return false;
	}

	$table   = cc_vendor_prices_table();
	$now     = current_time( 'mysql', true );
	$status  = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'error';
	$allowed = array( 'ok', 'missing_id', 'not_found', 'error', 'unavailable' );
	if ( ! in_array( $status, $allowed, true ) ) {
		$status = 'error';
	}

	$price = null;
	if ( isset( $data['price'] ) && $data['price'] !== '' && $data['price'] !== null && is_numeric( $data['price'] ) ) {
		$price = round( (float) $data['price'], 4 );
	}
	// Never persist $0 / negative as a successful vendor price.
	if ( $price !== null && $price <= 0 ) {
		$price  = null;
		$status = ( $status === 'ok' ) ? 'not_found' : $status;
		if ( empty( $data['error_message'] ) ) {
			$data['error_message'] = 'Vendor returned no usable unit price.';
		}
	}

	$vendor_sku = isset( $data['vendor_sku'] ) ? substr( sanitize_text_field( (string) $data['vendor_sku'] ), 0, 64 ) : '';
	$currency   = isset( $data['currency'] ) && $data['currency'] !== ''
		? strtoupper( substr( sanitize_text_field( (string) $data['currency'] ), 0, 3 ) )
		: 'CAD';
	$error_message = isset( $data['error_message'] ) && $data['error_message'] !== ''
		? (string) $data['error_message']
		: '';
	$raw_hash = isset( $data['raw_hash'] ) ? substr( sanitize_text_field( (string) $data['raw_hash'] ), 0, 40 ) : '';

	$row = array(
		'product_id'    => $product_id,
		'vendor'        => $vendor,
		'vendor_sku'    => $vendor_sku,
		'currency'      => $currency,
		'status'        => $status,
		'error_message' => $error_message,
		'raw_hash'      => $raw_hash,
		'fetched_at'    => $now,
		'updated_at'    => $now,
	);
	// Store unavailable prices as empty string then normalize via SQL NULL after write if needed.
	$row['price'] = ( $price === null ) ? null : $price;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$existing_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE product_id = %d AND vendor = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$product_id,
			$vendor
		)
	);

	if ( $price === null ) {
		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = false !== $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET vendor_sku = %s, price = NULL, currency = %s, status = %s, error_message = %s, raw_hash = %s, fetched_at = %s, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$vendor_sku,
					$currency,
					$status,
					$error_message,
					$raw_hash,
					$now,
					$now,
					(int) $existing_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = false !== $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (product_id, vendor, vendor_sku, price, currency, status, error_message, raw_hash, fetched_at, updated_at) VALUES (%d, %s, %s, NULL, %s, %s, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$product_id,
					$vendor,
					$vendor_sku,
					$currency,
					$status,
					$error_message,
					$raw_hash,
					$now,
					$now
				)
			);
		}
	} elseif ( $existing_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = false !== $wpdb->update(
			$table,
			array(
				'vendor_sku'    => $vendor_sku,
				'price'         => $price,
				'currency'      => $currency,
				'status'        => $status,
				'error_message' => $error_message,
				'raw_hash'      => $raw_hash,
				'fetched_at'    => $now,
				'updated_at'    => $now,
			),
			array( 'id' => (int) $existing_id ),
			array( '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = false !== $wpdb->insert(
			$table,
			array(
				'product_id'    => $product_id,
				'vendor'        => $vendor,
				'vendor_sku'    => $vendor_sku,
				'price'         => $price,
				'currency'      => $currency,
				'status'        => $status,
				'error_message' => $error_message,
				'raw_hash'      => $raw_hash,
				'fetched_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	if ( $ok ) {
		cc_vendor_prices_recompute_cheapest( $product_id );
	}

	return (bool) $ok;
}

/**
 * Get all vendor rows for a product.
 *
 * @param int $product_id Product ID.
 * @return array<int, object>
 */
function cc_vendor_prices_get_for_product( $product_id ) {
	global $wpdb;
	$product_id = (int) $product_id;
	if ( $product_id <= 0 ) {
		return array();
	}
	$table = cc_vendor_prices_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE product_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$product_id
		)
	);
	return is_array( $rows ) ? $rows : array();
}

/**
 * Catalog (Woo) unit price for compare — sale if active, else regular/price.
 *
 * @param WC_Product $product Product.
 * @return float|null
 */
function cc_vendor_prices_woo_unit_price( $product ) {
	if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
		return null;
	}
	// Avoid recursion with our own get_price filter.
	$price = $product->get_price( 'edit' );
	if ( $price === '' || $price === null ) {
		$price = $product->get_regular_price( 'edit' );
	}
	if ( $price === '' || $price === null || ! is_numeric( $price ) ) {
		return null;
	}
	return (float) $price;
}

/**
 * Recompute `_cc_cheapest_*` meta. Only real vendors compete: Amazon / G&T live rows, plus the
 * stored product price when a manual vendor is assigned (it has no price feed).
 * Tie-break: amazon → gt → manual. No winner = vendor "none", and the product can't be ordered.
 *
 * @param int $product_id Product ID.
 * @return array{price:float|null,vendor:string}
 */
function cc_vendor_prices_recompute_cheapest( $product_id ) {
	$product_id = (int) $product_id;
	$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
	if ( ! $product ) {
		return array( 'price' => null, 'vendor' => 'none' );
	}

	$candidates = array();
	$stored     = cc_vendor_prices_woo_unit_price( $product );
	// Ignore $0 stored prices — they are placeholders, not real offers.
	if ( $stored !== null && $stored > 0 && cc_vendor_prices_manual_vendors( $product_id ) ) {
		$candidates[] = array(
			'vendor' => 'manual',
			'price'  => $stored,
			'rank'   => 2,
		);
	}

	foreach ( cc_vendor_prices_get_for_product( $product_id ) as $row ) {
		if ( ! $row || (string) $row->status !== 'ok' ) {
			continue;
		}
		if ( $row->price === null || $row->price === '' || ! is_numeric( $row->price ) ) {
			continue;
		}
		$price = (float) $row->price;
		if ( $price <= 0 ) {
			continue;
		}
		$v = cc_vendor_prices_normalize_vendor( (string) $row->vendor );
		if ( $v === '' || ! cc_vendor_prices_vendor_is_active( $v ) ) {
			continue;
		}
		$candidates[] = array(
			'vendor' => $v,
			'price'  => $price,
			'rank'   => ( $v === 'amazon' ) ? 0 : 1,
		);
	}

	if ( ! $candidates ) {
		delete_post_meta( $product_id, '_cc_cheapest_price' );
		update_post_meta( $product_id, '_cc_cheapest_vendor', 'none' );
		update_post_meta( $product_id, '_cc_cheapest_synced_at', gmdate( 'c' ) );
		return array( 'price' => null, 'vendor' => 'none' );
	}

	usort(
		$candidates,
		static function ( $a, $b ) {
			if ( $a['price'] === $b['price'] ) {
				return $a['rank'] <=> $b['rank'];
			}
			return $a['price'] <=> $b['price'];
		}
	);

	$winner = $candidates[0];
	update_post_meta( $product_id, '_cc_cheapest_price', wc_format_decimal( $winner['price'], 4 ) );
	update_post_meta( $product_id, '_cc_cheapest_vendor', $winner['vendor'] );
	update_post_meta( $product_id, '_cc_cheapest_synced_at', gmdate( 'c' ) );

	return array(
		'price'  => (float) $winner['price'],
		'vendor' => (string) $winner['vendor'],
	);
}

/**
 * Resolved cheapest price for display / cart. vendor is amazon|gt|manual, or none when no vendor can supply it.
 *
 * @param int|WC_Product $product Product or ID.
 * @return array{price:float|null,vendor:string,source:string}
 */
function cc_vendor_prices_resolve_cheapest( $product ) {
	if ( is_numeric( $product ) ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product ) : null;
	}
	if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
		return array( 'price' => null, 'vendor' => 'none', 'source' => 'none' );
	}

	$pid      = (int) $product->get_id();
	$cached_p = get_post_meta( $pid, '_cc_cheapest_price', true );
	$cached_v = get_post_meta( $pid, '_cc_cheapest_vendor', true );

	// Positive cached price only — $0 was wrongly saved from empty G&T/Amazon responses.
	$vendor = (string) $cached_v === 'manual' ? 'manual' : cc_vendor_prices_normalize_vendor( (string) $cached_v );
	if ( $vendor !== '' && $cached_p !== '' && $cached_p !== null && is_numeric( $cached_p ) && (float) $cached_p > 0 ) {
		return array(
			'price'  => (float) $cached_p,
			'vendor' => $vendor,
			'source' => 'meta',
		);
	}
	if ( (string) $cached_v === 'none' ) {
		return array( 'price' => null, 'vendor' => 'none', 'source' => 'none' );
	}

	// Stale meta (a $0 price, or the old "woo" vendor): work it out from the real vendors.
	$fixed = cc_vendor_prices_recompute_cheapest( $pid );
	return array(
		'price'  => $fixed['price'],
		'vendor' => (string) $fixed['vendor'],
		'source' => 'recompute',
	);
}

/**
 * Is Amazon / G&T active? Inactive vendors are never priced or ordered from.
 *
 * @param string $key amazon|gt.
 * @return bool
 */
function cc_vendor_prices_vendor_is_active( $key ) {
	return ! class_exists( 'WCSS_Vendor_Status' ) || WCSS_Vendor_Status::key_is_active( (string) $key );
}

/**
 * Names of the manual (non Amazon / G&T) vendors assigned to a product.
 *
 * @param int $product_id Product or variation ID.
 * @return string[]
 */
function cc_vendor_prices_manual_vendors( $product_id ) {
	$product_id = (int) $product_id;
	$parent     = (int) wp_get_post_parent_id( $product_id );
	$terms      = taxonomy_exists( 'wcss_vendor' ) ? wp_get_object_terms( $parent ? $parent : $product_id, 'wcss_vendor' ) : array();
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	$names = array();
	foreach ( $terms as $term ) {
		if ( class_exists( 'WCSS_Vendor_Status' ) && ! WCSS_Vendor_Status::is_active( $term->term_id ) ) {
			continue;
		}
		if ( cc_vendor_prices_normalize_vendor( $term->slug ) === '' ) {
			$names[] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
		}
	}
	return $names;
}

/**
 * Every order must go to a vendor: a product can be ordered only when a real vendor can supply it.
 *
 * @param int|WC_Product $product Product or ID.
 * @return bool
 */
function cc_vendor_prices_product_is_orderable( $product ) {
	$resolved = cc_vendor_prices_resolve_cheapest( $product );
	return $resolved['vendor'] !== 'none' && $resolved['price'] !== null && (float) $resolved['price'] > 0;
}

/**
 * Ticking or unticking a manual vendor changes who can supply the product.
 *
 * @param int    $object_id Product ID.
 * @param array  $terms     Terms.
 * @param array  $tt_ids    Term taxonomy IDs.
 * @param string $taxonomy  Taxonomy.
 */
function cc_vendor_prices_on_vendor_terms_set( $object_id, $terms, $tt_ids, $taxonomy ) {
	if ( $taxonomy === 'wcss_vendor' ) {
		cc_vendor_prices_recompute_cheapest( (int) $object_id );
	}
}
add_action( 'set_object_terms', 'cc_vendor_prices_on_vendor_terms_set', 10, 4 );

/**
 * Human label for cheapest vendor key.
 *
 * @param string $vendor amazon|gt|manual|none.
 * @return string
 */
function cc_vendor_prices_vendor_label( $vendor ) {
	$vendor = sanitize_key( (string) $vendor );
	if ( $vendor === 'amazon' ) {
		return __( 'Amazon', 'adex360' );
	}
	if ( $vendor === 'gt' ) {
		return __( 'Grand & Toy', 'adex360' );
	}
	if ( $vendor === 'manual' ) {
		return __( 'Manual vendor', 'adex360' );
	}
	return __( 'No vendor', 'adex360' );
}

/**
 * When a product is saved, refresh cheapest meta from cached vendor rows.
 *
 * @param int $product_id Product ID.
 */
function cc_vendor_prices_on_product_save( $product_id ) {
	$product_id = (int) $product_id;
	if ( $product_id <= 0 ) {
		return;
	}
	cc_vendor_prices_recompute_cheapest( $product_id );
}
add_action( 'woocommerce_update_product', 'cc_vendor_prices_on_product_save', 30 );
add_action( 'woocommerce_new_product', 'cc_vendor_prices_on_product_save', 30 );
