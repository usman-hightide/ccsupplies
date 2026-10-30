<?php
require_once get_stylesheet_directory() . '/amazon-order-storage.php';
require_once get_stylesheet_directory() . '/amazon-catalogItems.php';
// Grand & Toy — separate from Amazon (cXML + procurement UI helpers).
require_once get_stylesheet_directory() . '/grand-and-toy-cxml.php';
require_once get_stylesheet_directory() . '/cc-gt-procurement.php';
// Shared product admin fields (ASIN + eligibility). Optional Ops compare kept off Amazon nav.
require_once get_stylesheet_directory() . '/cc-vendor-product-admin.php';
require_once get_stylesheet_directory() . '/cc-vendor-compare.php';
require_once get_stylesheet_directory() . '/cc-vendor-compare-ajax.php';
require_once get_stylesheet_directory() . '/cc-cart-ui.php';
require_once get_stylesheet_directory() . '/cc-cart-vendor.php';
// Cheapest vendor pricing (DB cache + hourly sync + storefront).
require_once get_stylesheet_directory() . '/cc-vendor-prices.php';
require_once get_stylesheet_directory() . '/cc-vendor-prices-sync.php';
require_once get_stylesheet_directory() . '/cc-vendor-cheapest.php';
// Header search relevance (backend only).
require_once get_stylesheet_directory() . '/cc-product-search.php';


// Enqueue parent and child styles
function adex360_cannacabana_child_enqueue_styles() {
    wp_enqueue_style( 'adex360-cannacabana-style', get_template_directory_uri() . '/style.css' );
    wp_enqueue_style(
        'adex360-cannacabana-child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array( 'adex360-cannacabana-style' ),
        wp_get_theme()->get('Version')
    );
}
add_action( 'wp_enqueue_scripts', 'adex360_cannacabana_child_enqueue_styles' );

/**
 * Shop sidebar category counts: show "5" instead of "(5)".
 *
 * @param string $output HTML from wp_list_categories / product cat walker.
 * @return string
 */
function cc_shop_sidebar_strip_category_count_brackets( $output ) {
	if ( ! is_string( $output ) || $output === '' ) {
		return $output;
	}
	return preg_replace(
		'/<span class="count">\s*\(\s*([0-9,]+)\s*\)\s*<\/span>/',
		'<span class="count">$1</span>',
		$output
	);
}
add_filter( 'wp_list_categories', 'cc_shop_sidebar_strip_category_count_brackets', 20 );
