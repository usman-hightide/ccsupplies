<?php
/**
 * Vendor price sync — fetch Amazon/G&T, hourly cron, admin tools.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CC_VENDOR_PRICES_SYNC_LOCK', 'cc_vendor_prices_sync_lock' );
define( 'CC_VENDOR_PRICES_SYNC_LOG', 'cc_vendor_prices_sync_log' );
define( 'CC_VENDOR_PRICES_BATCH', 15 );
define( 'CC_VENDOR_PRICES_HOOK', 'cc_vendor_prices_hourly_sync' );

/**
 * Map compare fetch result → upsert payload.
 *
 * @param array  $row    Normalized vendor result.
 * @param string $vendor amazon|gt.
 * @return array<string, mixed>
 */
function cc_vendor_prices_row_from_fetch( array $row, $vendor ) {
	$status_raw = (string) ( $row['status'] ?? 'error' );
	$error      = (string) ( $row['error'] ?? '' );
	$sku        = (string) ( $row['product_identifier'] ?? '' );

	$status = 'error';
	if ( $status_raw === 'ok' && isset( $row['price'] ) && $row['price'] !== null && is_numeric( $row['price'] ) ) {
		$status = 'ok';
	} elseif ( $status_raw === 'unavailable' ) {
		$status = ( $sku === '' && false !== stripos( $error, 'No ' ) ) ? 'missing_id' : 'not_found';
		if ( false !== stripos( $error, 'No Amazon ASIN' ) || false !== stripos( $error, 'No Grand & Toy part' ) ) {
			$status = 'missing_id';
		}
	}

	$hash_src = wp_json_encode(
		array(
			'p' => $row['price'] ?? null,
			'c' => $row['currency'] ?? '',
			's' => $status,
			'e' => $error,
			'i' => $sku,
		)
	);

	return array(
		'vendor_sku'    => $sku,
		'price'         => ( $status === 'ok' ) ? (float) $row['price'] : null,
		'currency'      => (string) ( $row['currency'] ?? 'CAD' ),
		'status'        => $status,
		'error_message' => $error !== '' ? $error : null,
		'raw_hash'      => $hash_src ? substr( sha1( $hash_src ), 0, 40 ) : null,
	);
}

/**
 * Sync one product for both vendors (soft-fail per vendor).
 *
 * @param int $product_id Product ID.
 * @return array<string, mixed>
 */
function cc_vendor_prices_sync_product( $product_id ) {
	$product_id = (int) $product_id;
	$result     = array(
		'product_id' => $product_id,
		'amazon'     => null,
		'gt'         => null,
	);

	if ( $product_id <= 0 || ! function_exists( 'cc_vendor_prices_upsert' ) ) {
		return $result;
	}

	// Amazon.
	if ( function_exists( 'cc_vendor_fetch_amazon_for_product' ) ) {
		$amazon = cc_vendor_fetch_amazon_for_product( $product_id );
		$payload = cc_vendor_prices_row_from_fetch( $amazon, 'amazon' );
		cc_vendor_prices_upsert( $product_id, 'amazon', $payload );
		$result['amazon'] = $payload['status'];
	} else {
		cc_vendor_prices_upsert(
			$product_id,
			'amazon',
			array(
				'status'        => 'error',
				'error_message' => 'Amazon fetch helper missing.',
			)
		);
		$result['amazon'] = 'error';
	}

	// Grand & Toy.
	if ( function_exists( 'cc_vendor_fetch_gt_for_product' ) ) {
		$gt      = cc_vendor_fetch_gt_for_product( $product_id, false );
		$payload = cc_vendor_prices_row_from_fetch( $gt, 'gt' );
		cc_vendor_prices_upsert( $product_id, 'gt', $payload );
		$result['gt'] = $payload['status'];
	} else {
		cc_vendor_prices_upsert(
			$product_id,
			'gt',
			array(
				'status'        => 'error',
				'error_message' => 'Grand & Toy fetch helper missing.',
			)
		);
		$result['gt'] = 'error';
	}

	return $result;
}

/**
 * Product IDs eligible for sync (published products; simple + variations with parent published).
 *
 * @param int $limit  Batch size.
 * @param int $offset Offset.
 * @return int[]
 */
function cc_vendor_prices_product_ids( $limit = 50, $offset = 0 ) {
	$limit  = max( 1, (int) $limit );
	$offset = max( 0, (int) $offset );

	$q = new WP_Query(
		array(
			'post_type'              => array( 'product', 'product_variation' ),
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'offset'                 => $offset,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	$ids = array_map( 'intval', (array) $q->posts );

	// Skip variations whose parent is not published.
	$out = array();
	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			continue;
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent || $parent->get_status() !== 'publish' ) {
				continue;
			}
		}
		if ( $product->is_type( 'variable' ) ) {
			// Parent variable: sync children via variation posts; skip parent price shell.
			continue;
		}
		$out[] = $id;
	}
	return $out;
}

/**
 * Acquire short lock.
 *
 * @param int $ttl Seconds.
 * @return bool
 */
function cc_vendor_prices_acquire_lock( $ttl = 50 * MINUTE_IN_SECONDS ) {
	if ( get_transient( CC_VENDOR_PRICES_SYNC_LOCK ) ) {
		return false;
	}
	set_transient( CC_VENDOR_PRICES_SYNC_LOCK, time(), $ttl );
	return true;
}

/**
 * Release lock.
 */
function cc_vendor_prices_release_lock() {
	delete_transient( CC_VENDOR_PRICES_SYNC_LOCK );
}

/**
 * Persist last sync summary.
 *
 * @param array<string, mixed> $log Log payload.
 */
function cc_vendor_prices_write_log( array $log ) {
	$log['finished_at'] = gmdate( 'c' );
	update_option( CC_VENDOR_PRICES_SYNC_LOG, $log, false );
}

/**
 * Run one sync batch (or full small catalog).
 *
 * @param int  $batch_size Products per run.
 * @param bool $force      Ignore lock (admin).
 * @return array<string, mixed>
 */
function cc_vendor_prices_run_sync( $batch_size = CC_VENDOR_PRICES_BATCH, $force = false ) {
	$batch_size = max( 1, min( 40, (int) $batch_size ) );

	if ( ! $force && ! cc_vendor_prices_acquire_lock() ) {
		return array(
			'ok'      => false,
			'message' => 'Sync already running.',
			'synced'  => 0,
		);
	}

	$offset_key = 'cc_vendor_prices_sync_offset';
	$offset     = (int) get_option( $offset_key, 0 );
	$ids        = cc_vendor_prices_product_ids( $batch_size, $offset );

	$synced  = 0;
	$ok_a    = 0;
	$ok_g    = 0;
	$errors  = 0;
	$details = array();

	if ( ! $ids ) {
		update_option( $offset_key, 0, false );
		$summary = array(
			'ok'           => true,
			'message'      => 'No more products; offset reset.',
			'synced'       => 0,
			'offset'       => 0,
			'amazon_ok'    => 0,
			'gt_ok'        => 0,
			'errors'       => 0,
			'started_at'   => gmdate( 'c' ),
		);
		cc_vendor_prices_write_log( $summary );
		if ( ! $force ) {
			cc_vendor_prices_release_lock();
		} elseif ( $force ) {
			cc_vendor_prices_release_lock();
		}
		return $summary;
	}

	$started = gmdate( 'c' );
	foreach ( $ids as $pid ) {
		try {
			$r = cc_vendor_prices_sync_product( $pid );
			$synced++;
			if ( ( $r['amazon'] ?? '' ) === 'ok' ) {
				$ok_a++;
			}
			if ( ( $r['gt'] ?? '' ) === 'ok' ) {
				$ok_g++;
			}
			if ( ( $r['amazon'] ?? '' ) === 'error' || ( $r['gt'] ?? '' ) === 'error' ) {
				$errors++;
			}
			$details[] = $r;
		} catch ( Exception $e ) {
			$errors++;
			$details[] = array(
				'product_id' => $pid,
				'error'      => $e->getMessage(),
			);
		}
	}

	$next = $offset + count( $ids );
	// If we got fewer than batch, wrap.
	if ( count( $ids ) < $batch_size ) {
		$next = 0;
	}
	update_option( $offset_key, $next, false );

	$summary = array(
		'ok'         => true,
		'message'    => sprintf( 'Synced %d products (offset now %d).', $synced, $next ),
		'synced'     => $synced,
		'offset'     => $next,
		'amazon_ok'  => $ok_a,
		'gt_ok'      => $ok_g,
		'errors'     => $errors,
		'started_at' => $started,
		'sample'     => array_slice( $details, 0, 5 ),
	);
	cc_vendor_prices_write_log( $summary );
	cc_vendor_prices_release_lock();

	return $summary;
}

/**
 * Hourly sync callback.
 */
function cc_vendor_prices_hourly_sync_cb() {
	cc_vendor_prices_run_sync( CC_VENDOR_PRICES_BATCH, false );
}
add_action( CC_VENDOR_PRICES_HOOK, 'cc_vendor_prices_hourly_sync_cb' );

/**
 * Schedule hourly event (Action Scheduler preferred).
 */
function cc_vendor_prices_schedule_cron() {
	if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
		if ( ! as_has_scheduled_action( CC_VENDOR_PRICES_HOOK ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, HOUR_IN_SECONDS, CC_VENDOR_PRICES_HOOK, array(), 'cc-vendor-prices' );
		}
		// Clear legacy wp-cron if present.
		$ts = wp_next_scheduled( CC_VENDOR_PRICES_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, CC_VENDOR_PRICES_HOOK );
		}
		return;
	}

	if ( ! wp_next_scheduled( CC_VENDOR_PRICES_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', CC_VENDOR_PRICES_HOOK );
	}
}
add_action( 'init', 'cc_vendor_prices_schedule_cron', 20 );

/**
 * Admin: Tools submenu.
 */
function cc_vendor_prices_admin_menu() {
	add_management_page(
		__( 'Vendor Prices', 'adex360' ),
		__( 'Vendor Prices', 'adex360' ),
		'manage_woocommerce',
		'cc-vendor-prices',
		'cc_vendor_prices_admin_page'
	);
}
add_action( 'admin_menu', 'cc_vendor_prices_admin_menu' );

/**
 * Handle admin sync actions.
 */
function cc_vendor_prices_admin_handle() {
	if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	if ( empty( $_GET['page'] ) || $_GET['page'] !== 'cc-vendor-prices' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( empty( $_GET['cc_vp_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	check_admin_referer( 'cc_vendor_prices_admin' );

	$action = sanitize_key( wp_unslash( $_GET['cc_vp_action'] ) );
	if ( $action === 'sync_batch' ) {
		$summary = cc_vendor_prices_run_sync( CC_VENDOR_PRICES_BATCH, true );
		set_transient( 'cc_vendor_prices_admin_notice', $summary, 60 );
	} elseif ( $action === 'sync_product' ) {
		$pid = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		if ( $pid ) {
			$r = cc_vendor_prices_sync_product( $pid );
			set_transient(
				'cc_vendor_prices_admin_notice',
				array(
					'ok'      => true,
					'message' => sprintf( 'Synced product #%d (amazon=%s, gt=%s).', $pid, $r['amazon'], $r['gt'] ),
				),
				60
			);
		}
	} elseif ( $action === 'install_table' ) {
		cc_vendor_prices_install();
		set_transient(
			'cc_vendor_prices_admin_notice',
			array( 'ok' => true, 'message' => 'Table installed / upgraded.' ),
			60
		);
	}

	wp_safe_redirect( remove_query_arg( array( 'cc_vp_action', 'product_id', '_wpnonce' ) ) );
	exit;
}
add_action( 'admin_init', 'cc_vendor_prices_admin_handle' );

/**
 * Admin page markup.
 */
function cc_vendor_prices_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$notice = get_transient( 'cc_vendor_prices_admin_notice' );
	if ( $notice ) {
		delete_transient( 'cc_vendor_prices_admin_notice' );
	}
	$log = get_option( CC_VENDOR_PRICES_SYNC_LOG, array() );
	$next_as = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( CC_VENDOR_PRICES_HOOK ) : false;
	$next_wp = wp_next_scheduled( CC_VENDOR_PRICES_HOOK );

	$sync_url = wp_nonce_url(
		add_query_arg(
			array(
				'page'         => 'cc-vendor-prices',
				'cc_vp_action' => 'sync_batch',
			),
			admin_url( 'tools.php' )
		),
		'cc_vendor_prices_admin'
	);
	$install_url = wp_nonce_url(
		add_query_arg(
			array(
				'page'         => 'cc-vendor-prices',
				'cc_vp_action' => 'install_table',
			),
			admin_url( 'tools.php' )
		),
		'cc_vendor_prices_admin'
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Vendor Prices', 'adex360' ); ?></h1>
		<p><?php esc_html_e( 'Cached Amazon and Grand & Toy unit prices. Frontend shows the cheapest (Woo fallback). Hourly cron refreshes in batches.', 'adex360' ); ?></p>

		<?php if ( is_array( $notice ) ) : ?>
			<div class="notice notice-<?php echo ! empty( $notice['ok'] ) ? 'success' : 'warning'; ?> is-dismissible">
				<p><?php echo esc_html( (string) ( $notice['message'] ?? '' ) ); ?></p>
			</div>
		<?php endif; ?>

		<p>
			<a class="button button-primary" href="<?php echo esc_url( $sync_url ); ?>"><?php esc_html_e( 'Sync next batch now', 'adex360' ); ?></a>
			<a class="button" href="<?php echo esc_url( $install_url ); ?>"><?php esc_html_e( 'Install / repair table', 'adex360' ); ?></a>
		</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>" style="margin:16px 0;">
			<input type="hidden" name="page" value="cc-vendor-prices" />
			<input type="hidden" name="cc_vp_action" value="sync_product" />
			<?php wp_nonce_field( 'cc_vendor_prices_admin' ); ?>
			<label>
				<?php esc_html_e( 'Product ID', 'adex360' ); ?>
				<input type="number" name="product_id" min="1" required />
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Sync this product', 'adex360' ); ?></button>
		</form>

		<h2><?php esc_html_e( 'Schedule', 'adex360' ); ?></h2>
		<ul>
			<li><?php echo esc_html( 'Action Scheduler next: ' . ( $next_as ? gmdate( 'c', (int) $next_as ) : 'not scheduled / N/A' ) ); ?></li>
			<li><?php echo esc_html( 'WP-Cron next: ' . ( $next_wp ? gmdate( 'c', (int) $next_wp ) : 'not scheduled' ) ); ?></li>
			<li><?php echo esc_html( 'Table: ' . cc_vendor_prices_table() ); ?></li>
			<li><?php echo esc_html( 'DB version: ' . (string) get_option( CC_VENDOR_PRICES_OPTION, '' ) ); ?></li>
		</ul>

		<h2><?php esc_html_e( 'Last sync log', 'adex360' ); ?></h2>
		<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:900px;overflow:auto;"><?php echo esc_html( wp_json_encode( $log, JSON_PRETTY_PRINT ) ); ?></pre>
	</div>
	<?php
}
