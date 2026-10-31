<?php
/**
 * Shop Product Categories sidebar redesign (approved card UI).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether current request should load the shop categories card assets.
 *
 * @return bool
 */
function cc_shop_categories_should_load() {
	return function_exists( 'is_shop' )
		&& ( is_shop() || is_product_taxonomy() || is_product_category() || is_product_tag() );
}

/**
 * Enqueue sidebar card CSS/JS on shop archives only.
 */
function cc_shop_categories_enqueue() {
	if ( ! cc_shop_categories_should_load() ) {
		return;
	}

	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();
	$css = $dir . '/cc-shop-categories.css';
	$js  = $dir . '/cc-shop-categories.js';

	wp_enqueue_style(
		'cc-shop-categories',
		$uri . '/cc-shop-categories.css',
		array( 'adex360-cannacabana-child-style' ),
		file_exists( $css ) ? (string) filemtime( $css ) : '1.0.0'
	);

	wp_enqueue_script(
		'cc-shop-categories',
		$uri . '/cc-shop-categories.js',
		array(),
		file_exists( $js ) ? (string) filemtime( $js ) : '1.0.0',
		true
	);
}
add_action( 'wp_enqueue_scripts', 'cc_shop_categories_enqueue', 30 );
