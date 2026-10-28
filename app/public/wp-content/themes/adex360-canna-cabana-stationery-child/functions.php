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
