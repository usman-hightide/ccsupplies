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
 * Normalize a product title / search term for exact comparisons.
 * Collapses whitespace and treats -, –, —, _ as the same.
 *
 * @param string $text Raw text.
 * @return string
 */
function cc_product_search_normalize_title( $text ) {
	$text = wp_strip_all_tags( (string) $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = str_replace( array( '–', '—', '−', '_' ), '-', $text );
	$text = preg_replace( '/\s+/u', ' ', $text );
	$text = preg_replace( '/\s*-\s*/u', '-', $text );
	return strtolower( trim( (string) $text ) );
}

/**
 * Map of normalized product title => product ID (request-cached).
 *
 * @return array<string,int>
 */
function cc_product_search_title_map() {
	static $map = null;
	if ( is_array( $map ) ) {
		return $map;
	}

	$cached = get_transient( 'cc_product_search_title_map_v1' );
	if ( is_array( $cached ) ) {
		$map = $cached;
		return $map;
	}

	global $wpdb;
	$map = array();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		"SELECT ID, post_title FROM {$wpdb->posts}
		WHERE post_type = 'product' AND post_status = 'publish'"
	);
	foreach ( (array) $rows as $row ) {
		$key = cc_product_search_normalize_title( $row->post_title );
		if ( $key === '' || isset( $map[ $key ] ) ) {
			continue;
		}
		$map[ $key ] = absint( $row->ID );
	}
	set_transient( 'cc_product_search_title_map_v1', $map, 10 * MINUTE_IN_SECONDS );
	return $map;
}

/**
 * Bust title map when products change.
 */
function cc_product_search_bust_title_map() {
	delete_transient( 'cc_product_search_title_map_v1' );
}
add_action( 'save_post_product', 'cc_product_search_bust_title_map' );
add_action( 'deleted_post', 'cc_product_search_bust_title_map' );
add_action( 'woocommerce_update_product', 'cc_product_search_bust_title_map' );

/**
 * Find a published product whose title exactly matches the search term (normalized).
 *
 * @param string $term Search term.
 * @return int Product ID or 0.
 */
function cc_product_search_find_exact_title_id( $term ) {
	$needle = cc_product_search_normalize_title( $term );
	if ( $needle === '' ) {
		return 0;
	}
	$map = cc_product_search_title_map();
	return isset( $map[ $needle ] ) ? (int) $map[ $needle ] : 0;
}

/**
 * Prefer products in search results. Pin exact title matches to a single product.
 *
 * @param WP_Query $query Query.
 */
function cc_product_search_pre_get_posts( $query ) {
	if ( ! empty( $query->get( 'cc_skip_exact_title' ) ) ) {
		return;
	}
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

	// Exact title → only that product (so Woo single-result redirect fires).
	if ( $query->is_main_query() || $query->is_search() ) {
		$exact_id = cc_product_search_find_exact_title_id( (string) $query->get( 's' ) );
		if ( $exact_id ) {
			$query->set( 'post__in', array( $exact_id ) );
			$query->set( 'orderby', 'post__in' );
			$query->set( 's', '' ); // Avoid WP tokenizing away the exact pin.
		}
	}
}
add_action( 'pre_get_posts', 'cc_product_search_pre_get_posts', 30 );

/**
 * Hard redirect when the typed term is an exact product title.
 */
function cc_product_search_exact_title_redirect() {
	if ( is_admin() || wp_doing_ajax() || ! isset( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$term = sanitize_text_field( wp_unslash( $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $term === '' ) {
		return;
	}
	$pt = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $pt !== '' && $pt !== 'product' && $pt !== 'any' ) {
		return;
	}

	$exact_id = cc_product_search_find_exact_title_id( $term );
	if ( ! $exact_id ) {
		return;
	}
	$url = get_permalink( $exact_id );
	if ( ! $url ) {
		return;
	}
	wp_safe_redirect( $url, 302 );
	exit;
}
add_action( 'template_redirect', 'cc_product_search_exact_title_redirect', 5 );

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
 * Woo shop/product archive: when `?s=` is present, keep the search term on the product query.
 *
 * @param WP_Query $q Query.
 */
function cc_product_search_woocommerce_product_query( $q ) {
	if ( is_admin() ) {
		return;
	}
	$s = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $s === '' ) {
		return;
	}
	$q->set( 's', $s );
}
add_action( 'woocommerce_product_query', 'cc_product_search_woocommerce_product_query', 20 );

/**
 * Resolve product IDs for a search term (exact title first, then WP product search).
 *
 * @param string $term Search term.
 * @param int    $limit Max results.
 * @return int[]
 */
function cc_product_search_resolve_ids( $term, $limit = 8 ) {
	$term = trim( (string) $term );
	if ( $term === '' ) {
		return array();
	}

	$exact = cc_product_search_find_exact_title_id( $term );
	if ( $exact ) {
		return array( $exact );
	}

	$q = new WP_Query(
		array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			's'                      => $term,
			'posts_per_page'         => $limit,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'cc_skip_exact_title'    => true,
		)
	);

	return array_values( array_filter( array_map( 'absint', (array) $q->posts ) ) );
}

/**
 * Render live-search result cards for matching products.
 *
 * @param int[] $ids Product IDs.
 * @return string HTML.
 */
function cc_product_search_render_result_cards( $ids ) {
	$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	if ( ! $ids ) {
		return '<div class="e-search-nothing-found-message cc-search-empty">No products found</div>';
	}

	ob_start();
	echo '<div class="cc-search-results">';
	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			continue;
		}
		$url        = get_permalink( $id );
		$title      = $product->get_name();
		$price      = $product->get_price_html();
		$img        = $product->get_image(
			'woocommerce_thumbnail',
			array(
				'loading' => 'lazy',
				'alt'     => $title,
			)
		);
		$purchasable = $product->is_purchasable() && $product->is_in_stock();
		$atc_url     = $product->add_to_cart_url();
		$atc_text    = $product->add_to_cart_text();
		$atc_class   = implode(
			' ',
			array_filter(
				array(
					'button',
					'cc-search-hit__atc',
					'product_type_' . $product->get_type(),
					$purchasable && $product->supports( 'ajax_add_to_cart' ) ? 'add_to_cart_button ajax_add_to_cart' : '',
				)
			)
		);
		?>
		<article class="product type-product cc-search-hit" data-product-id="<?php echo esc_attr( (string) $id ); ?>">
			<a class="cc-search-hit__media" href="<?php echo esc_url( $url ); ?>" aria-hidden="true" tabindex="-1">
				<?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
			<div class="cc-search-hit__content">
				<a class="cc-search-hit__title" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a>
				<div class="cc-search-hit__price"><?php echo $price; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="cc-search-hit__actions">
					<?php if ( $purchasable ) : ?>
						<a
							href="<?php echo esc_url( $atc_url ); ?>"
							class="<?php echo esc_attr( $atc_class ); ?>"
							data-product_id="<?php echo esc_attr( (string) $id ); ?>"
							data-product_sku="<?php echo esc_attr( $product->get_sku() ); ?>"
							data-quantity="1"
							aria-label="<?php echo esc_attr( sprintf( /* translators: %s product name */ __( 'Add “%s” to your cart', 'woocommerce' ), $title ) ); ?>"
							rel="nofollow"
						><?php echo esc_html( $atc_text ); ?></a>
					<?php else : ?>
						<a class="button cc-search-hit__atc cc-search-hit__atc--view" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'View product', 'woocommerce' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</article>
		<?php
	}
	echo '</div>';
	return (string) ob_get_clean();
}

/**
 * Serve live-search markup ourselves (before Elementor renders Loop Item #7260).
 * Elementor's template previously used a Woo Products widget (always Thermal roll)
 * and can fatally error after repair — so we short-circuit refresh-search entirely.
 *
 * @param mixed           $result  Dispatch result.
 * @param WP_REST_Server  $server  Server.
 * @param WP_REST_Request $request Request.
 * @return mixed
 */
function cc_product_search_preempt_elementor_live_results( $result, $server, $request ) {
	if ( ! ( $request instanceof WP_REST_Request ) ) {
		return $result;
	}
	$route = $request->get_route();
	if ( ! is_string( $route ) || strpos( $route, 'refresh-search' ) === false ) {
		return $result;
	}

	$term = $request->get_param( 'search_term' );
	if ( ! is_string( $term ) ) {
		$term = '';
	}
	$term = trim( $term );
	if ( $term === '' ) {
		return new WP_REST_Response(
			array(
				'data'       => '<div class="e-search-nothing-found-message cc-search-empty">No products found</div>',
				'pagination' => '',
			),
			200
		);
	}

	$ids  = cc_product_search_resolve_ids( $term, 6 );
	$html = cc_product_search_render_result_cards( $ids );

	return new WP_REST_Response(
		array(
			'data'       => $html,
			'pagination' => '',
		),
		200
	);
}
add_filter( 'rest_pre_dispatch', 'cc_product_search_preempt_elementor_live_results', 5, 3 );

/**
 * Repair Elementor Loop Item #7260 if it still embeds the broken Products widget.
 */
function cc_product_search_repair_loop_template() {
	$template_id = 7260;
	$raw         = get_post_meta( $template_id, '_elementor_data', true );
	if ( ! is_string( $raw ) || $raw === '' ) {
		return;
	}
	if ( strpos( $raw, 'woocommerce-products' ) === false ) {
		return;
	}

	// Minimal loop card: featured image + title link + price (respects current loop product).
	$new = array(
		array(
			'id'       => 'ccsrch01',
			'elType'   => 'container',
			'settings' => array(
				'flex_direction' => 'column',
				'gap'            => array(
					'unit' => 'px',
					'size' => 8,
					'sizes'=> array(),
				),
			),
			'elements' => array(
				array(
					'id'         => 'ccsrch02',
					'elType'     => 'widget',
					'widgetType' => 'theme-post-featured-image',
					'settings'   => array(
						'thumbnail_size' => 'woocommerce_thumbnail',
					),
					'elements'   => array(),
				),
				array(
					'id'         => 'ccsrch03',
					'elType'     => 'widget',
					'widgetType' => 'theme-post-title',
					'settings'   => array(
						'header_size' => 'h2',
						'link_to'     => 'post',
					),
					'elements'   => array(),
				),
				array(
					'id'         => 'ccsrch04',
					'elType'     => 'widget',
					'widgetType' => 'woocommerce-product-price',
					'settings'   => array(),
					'elements'   => array(),
				),
			),
		),
	);

	update_post_meta( $template_id, '_elementor_data', wp_slash( wp_json_encode( $new ) ) );
	delete_post_meta( $template_id, '_elementor_css' );
	if ( class_exists( '\Elementor\Plugin' ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
}
add_action( 'init', 'cc_product_search_repair_loop_template', 30 );

/**
 * Ensure header Elementor search submits to a real product search URL,
 * and style the live-results dropdown so cards + Add to cart are readable.
 */
function cc_product_search_enqueue_submit_fix() {
	if ( is_admin() ) {
		return;
	}

	// Woo AJAX add-to-cart support inside the live dropdown.
	if ( function_exists( 'is_woocommerce' ) ) {
		wp_enqueue_script( 'wc-add-to-cart' );
	}

	$css = <<<'CSS'
/* Live search dropdown: a bit wider than the search field, not full page */
.elementor-location-header,
header.elementor-location-header {
	z-index: 100050 !important;
	overflow: visible !important;
}
.e-search,
.elementor-popup-modal .e-search {
	--e-search-results-columns: 2 !important;
	position: relative;
	z-index: 100060 !important;
	overflow: visible !important;
}
.e-search .e-search-results-container,
.elementor-popup-modal .e-search-results-container {
	background: #fff !important;
	border: 1px solid #e5e5e5 !important;
	border-radius: 10px !important;
	box-shadow: 0 14px 36px rgba(0, 0, 0, 0.2) !important;
	padding: 12px !important;
	max-height: min(560px, calc(100vh - 100px)) !important;
	height: auto !important;
	overflow-x: hidden !important;
	overflow-y: auto !important;
	-webkit-overflow-scrolling: touch;
	scrollbar-gutter: stable;
	z-index: 100070 !important;
	isolation: isolate;
	/* Closed until a search is in progress or results are inside. */
	display: none !important;
	/* Search width + a little extra (not full page) */
	width: calc(100% + 48px) !important;
	max-width: none !important;
	min-width: 0 !important;
	left: -24px !important;
	right: auto !important;
	box-sizing: border-box !important;
}
/* Show only while products are being searched, or once result cards are in the dropdown. */
.e-search .e-search-results-container:not(.hidden):is(:not(.hide-loader), :has(.cc-search-results), :has(.cc-search-empty), :has(.e-search-nothing-found-message), :has(.e-loop-item)),
.elementor-popup-modal .e-search-results-container:not(.hidden):is(:not(.hide-loader), :has(.cc-search-results), :has(.cc-search-empty), :has(.e-search-nothing-found-message), :has(.e-loop-item)) {
	display: block !important;
}
.e-search .e-search-results-container::-webkit-scrollbar {
	width: 8px;
}
.e-search .e-search-results-container::-webkit-scrollbar-thumb {
	background: #c8c8c8;
	border-radius: 8px;
}
.e-search .e-search-results,
.e-search .e-search-results-list,
.elementor-popup-modal .e-search-results,
.elementor-popup-modal .e-search-results-list {
	display: block !important;
	grid-template-columns: 1fr !important;
	width: 100% !important;
	max-width: 100% !important;
	overflow: visible !important;
}
.cc-search-results {
	display: grid !important;
	grid-template-columns: 1fr 1fr !important;
	gap: 12px;
	width: 100% !important;
	max-width: 100% !important;
	box-sizing: border-box;
}
.cc-search-results .cc-search-hit:only-child {
	grid-column: 1 / -1;
	max-width: 320px;
}
.cc-search-empty {
	grid-column: 1 / -1;
	padding: 18px 12px;
	text-align: center;
	color: #444;
	font-size: 14px;
}
.cc-search-hit {
	display: flex !important;
	flex-direction: column !important;
	align-items: stretch;
	gap: 10px;
	margin: 0 !important;
	padding: 12px !important;
	min-height: 240px;
	background: #f7f7f7;
	border: 1px solid #e8e8e8;
	border-radius: 8px;
	box-sizing: border-box;
	width: 100% !important;
	max-width: 100% !important;
	overflow: hidden;
}
.cc-search-hit:hover {
	background: #fff;
	border-color: #d4d4d4;
}
.cc-search-hit__media {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 120px;
	min-height: 120px;
	background: #fff;
	border: 1px solid #eee;
	border-radius: 6px;
	overflow: hidden;
	text-decoration: none;
}
.cc-search-hit__media img {
	display: block !important;
	width: auto !important;
	height: auto !important;
	max-width: 100% !important;
	max-height: 112px !important;
	object-fit: contain !important;
}
.cc-search-hit__content {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
	max-width: 100%;
	flex: 1 1 auto;
}
.cc-search-hit__title {
	display: block;
	font-size: 14px;
	font-weight: 700;
	line-height: 1.35;
	color: #111 !important;
	text-decoration: none !important;
	white-space: normal;
	overflow-wrap: anywhere;
	word-break: break-word;
	min-height: 2.7em;
}
.cc-search-hit__title:hover {
	color: #e1251c !important;
}
.cc-search-hit__price {
	font-size: 14px;
	font-weight: 700;
	color: #111 !important;
	line-height: 1.3;
}
.cc-search-hit__price .amount,
.cc-search-hit__price bdi {
	color: inherit !important;
}
.cc-search-hit__actions {
	display: flex;
	flex-direction: column;
	align-items: stretch;
	gap: 8px;
	width: 100%;
	margin-top: auto;
}
.cc-search-hit__actions a.added_to_cart,
.cc-search-hit__actions a.wc-forward {
	display: inline-flex !important;
	align-items: center !important;
	justify-content: center !important;
	box-sizing: border-box !important;
	width: 100% !important;
	max-width: 100% !important;
	min-height: 36px !important;
	margin: 0 !important;
	padding: 8px 14px !important;
	border: 1px solid #000 !important;
	border-radius: 4px !important;
	background: #000 !important;
	color: #fff !important;
	font-size: 13px !important;
	font-weight: 700 !important;
	line-height: 1.2 !important;
	text-align: center !important;
	text-decoration: none !important;
	text-transform: none !important;
	letter-spacing: 0 !important;
	white-space: nowrap;
	cursor: pointer;
}
.cc-search-hit__actions a.added_to_cart:hover,
.cc-search-hit__actions a.added_to_cart:focus,
.cc-search-hit__actions a.wc-forward:hover,
.cc-search-hit__actions a.wc-forward:focus {
	background: #222 !important;
	border-color: #222 !important;
	color: #fff !important;
	text-decoration: none !important;
}
.cc-search-hit__atc,
a.cc-search-hit__atc.button {
	display: inline-flex !important;
	align-items: center;
	justify-content: center;
	min-height: 36px;
	padding: 8px 14px !important;
	border-radius: 4px !important;
	background: #e1251c !important;
	border: 1px solid #e1251c !important;
	color: #fff !important;
	font-size: 13px !important;
	font-weight: 700 !important;
	line-height: 1.2 !important;
	text-decoration: none !important;
	text-transform: none !important;
	letter-spacing: 0 !important;
	cursor: pointer;
	width: 100% !important;
	max-width: 100% !important;
	box-sizing: border-box !important;
	white-space: nowrap;
}
.cc-search-hit__atc:hover,
a.cc-search-hit__atc.button:hover {
	background: #c41f18 !important;
	border-color: #c41f18 !important;
	color: #fff !important;
}
.cc-search-hit__atc.loading,
.cc-search-hit__atc.added {
	opacity: 0.85;
}
.cc-search-hit__atc--view {
	background: #222 !important;
	border-color: #222 !important;
}
@media (max-width: 767px) {
	.e-search .e-search-results-container,
	.elementor-popup-modal .e-search-results-container {
		width: 100% !important;
		left: 0 !important;
	}
	.cc-search-results {
		grid-template-columns: 1fr !important;
	}
	.cc-search-hit {
		min-height: 0;
	}
}
CSS;

	$js = <<<'JS'
(function () {
  function go(form) {
    if (!form) return;
    var input = form.querySelector('input[name="s"], input.e-search-input, input[type="search"]');
    if (!input) return;
    var term = (input.value || "").trim();
    if (!term) return;
    var base = form.getAttribute("action") || window.location.origin + "/";
    var url = new URL(base, window.location.origin);
    url.searchParams.set("s", term);
    url.searchParams.set("post_type", "product");
    window.location.assign(url.toString());
  }

  function stopAndGo(e, form) {
    e.preventDefault();
    e.stopPropagation();
    if (e.stopImmediatePropagation) e.stopImmediatePropagation();
    go(form);
  }

  document.addEventListener("submit", function (e) {
    var form = e.target && e.target.closest ? e.target.closest("form.e-search-form") : null;
    if (!form) return;
    stopAndGo(e, form);
  }, true);

  document.addEventListener("keydown", function (e) {
    if (e.key !== "Enter") return;
    var input = e.target;
    if (!input || !input.closest) return;
    var form = input.closest("form.e-search-form");
    if (!form || !input.matches("input[name='s'], input.e-search-input, input[type='search']")) return;
    stopAndGo(e, form);
  }, true);

  document.addEventListener("pointerdown", function (e) {
    // Don't hijack Add to cart clicks inside live results.
    if (e.target && e.target.closest && e.target.closest(".cc-search-hit__atc")) return;
    var btn = e.target && e.target.closest ? e.target.closest("form.e-search-form .e-search-submit, form.e-search-form button[type='submit']") : null;
    if (!btn) return;
    var form = btn.closest("form.e-search-form");
    if (!form) return;
    stopAndGo(e, form);
  }, true);
})();
JS;

	wp_register_style( 'cc-product-search', false, array(), '1.1.3' );
	wp_enqueue_style( 'cc-product-search' );
	wp_add_inline_style( 'cc-product-search', $css );

	wp_register_script( 'cc-product-search', false, array( 'jquery' ), '1.1.3', true );
	wp_enqueue_script( 'cc-product-search' );
	wp_add_inline_script( 'cc-product-search', $js );
}
add_action( 'wp_enqueue_scripts', 'cc_product_search_enqueue_submit_fix', 40 );
