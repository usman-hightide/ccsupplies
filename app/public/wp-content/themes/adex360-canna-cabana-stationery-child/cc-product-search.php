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
	$id = isset( $map[ $needle ] ) ? (int) $map[ $needle ] : 0;
	// Products of inactive vendors must not be found or redirected to.
	if ( $id && class_exists( 'WCSS_Vendor_Status' ) && in_array( $id, WCSS_Vendor_Status::hidden_ids(), true ) ) {
		return 0;
	}
	return $id;
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
		// Prefer cheapest vendor unit (same as cart / product page).
		if ( function_exists( 'cc_vendor_cheapest_unit_price' ) ) {
			$unit = cc_vendor_cheapest_unit_price( $product );
			$price = $unit !== null
				? wc_price( wc_get_price_to_display( $product, array( 'price' => $unit ) ) )
				: $product->get_price_html();
		} else {
			$price = $product->get_price_html();
		}
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

	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();
	$css_path = $dir . '/cc-product-search.css';
	$js_path  = $dir . '/cc-product-search.js';

	// Real stylesheet URL (inline-only handles with src=false are often stripped by CDN/optimizers).
	wp_enqueue_style(
		'cc-product-search',
		$uri . '/cc-product-search.css',
		array(),
		file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.2.0'
	);

	wp_enqueue_script(
		'cc-product-search',
		$uri . '/cc-product-search.js',
		array( 'jquery' ),
		file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.2.0',
		true
	);
}
add_action( 'wp_enqueue_scripts', 'cc_product_search_enqueue_submit_fix', 40 );

/**
 * Hard fallback: some hosts/CDN setups drop enqueued stylesheets from HTML.
 * Runs late in wp_head — if our handle never printed, emit a direct link.
 */
function cc_product_search_print_css_fallback() {
	if ( is_admin() || wp_style_is( 'cc-product-search', 'done' ) ) {
		return;
	}
	$path = get_stylesheet_directory() . '/cc-product-search.css';
	if ( ! file_exists( $path ) ) {
		return;
	}
	$href = get_stylesheet_directory_uri() . '/cc-product-search.css?ver=' . rawurlencode( (string) filemtime( $path ) );
	echo '<link rel="stylesheet" id="cc-product-search-fallback-css" href="' . esc_url( $href ) . '" media="all" />' . "\n";
}
add_action( 'wp_head', 'cc_product_search_print_css_fallback', 99 );
