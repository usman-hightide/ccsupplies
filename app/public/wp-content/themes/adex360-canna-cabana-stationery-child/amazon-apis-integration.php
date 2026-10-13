<?php
/*
Template Name: Amazon Catalog
*/

// ======================================
// AMAZON ACCESS TOKEN
// ======================================
function wp_amazon_get_access_token() {

    $response = wp_remote_post(
        'https://api.amazon.com/auth/o2/token',
        array(
            'timeout' => 30,

            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
            ),

            'body' => array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => AMAZON_REFRESH_TOKEN,
                'client_id'     => AMAZON_CLIENT_ID,
                'client_secret' => AMAZON_CLIENT_SECRET,
            ),
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code( $response );

    $body = json_decode(
        wp_remote_retrieve_body( $response ),
        true
    );

    if ( $status_code !== 200 || empty( $body['access_token'] ) ) {

        return new WP_Error(
            'amazon_auth_error',
            'Unable to get Amazon access token.',
            array(
                'status'   => $status_code,
                'response' => $body,
            )
        );
    }

    return $body['access_token'];
}


// ======================================
// AMAZON BUSINESS PRODUCT SEARCH
// ======================================
function wp_amazon_business_search_products(
    $keywords = 'HP 410A - Black',
    $page_size = 10
) {

    $access_token = wp_amazon_get_access_token();

    // Check if token retrieval failed.
    if ( is_wp_error( $access_token ) ) {
        return $access_token;
    }

    $user_email = 'ecom@hightideinc.com';

    $endpoint = 'https://na.business-api.amazon.com/products/2020-08-26/products';

    $query_args = array(
        'locale'        => 'en_US',
        'productRegion' => 'CA',
        'facets'        => 'OFFERS,IMAGES',
        'pageSize'      => $page_size,
        'keywords'      => $keywords,
        'category'      => 'OFFICE_PRODUCTS',
    );

    $url = add_query_arg(
        $query_args,
        $endpoint
    );

    $response = wp_remote_get(
        $url,
        array(
            'timeout' => 30,

            'headers' => array(
                'x-amz-access-token' => $access_token,
                'x-amz-user-email'   => $user_email,
                'Accept'             => 'application/json',
            ),
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code( $response );

    $body = wp_remote_retrieve_body( $response );

    $data = json_decode(
        $body,
        true
    );

    if ( $status_code < 200 || $status_code >= 300 ) {

        return new WP_Error(
            'amazon_product_error',
            'Amazon Business API request failed.',
            array(
                'status'   => $status_code,
                'response' => $data,
                'raw'      => $body,
            )
        );
    }

    return $data;
}


// ======================================
// AMAZON CART SETTINGS
// ======================================
if ( ! defined( 'AMAZON_CART_ID' ) ) {
    define( 'AMAZON_CART_ID', 'cart-wxXmutF5kiXGRb9J' );
}

function wp_amazon_business_cart_request( $method, $url, $access_token, $body = null ) {

    $args = array(
        'method'  => $method,
        'timeout' => 30,
        'headers' => array(
            'x-amz-access-token' => $access_token,
            'x-amz-user-email'   => 'ecom@hightideinc.com',
            'Accept'             => 'application/json',
        ),
    );

    if ( null !== $body ) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode( $body );
    }

    $response = wp_remote_request( $url, $args );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $raw_body    = wp_remote_retrieve_body( $response );
    $data        = json_decode( $raw_body, true );

    if ( $status_code < 200 || $status_code >= 300 ) {
        return new WP_Error(
            'amazon_cart_api_error',
            'Amazon Cart API request failed.',
            array(
                'status'   => $status_code,
                'response' => $data,
                'raw'      => $raw_body,
            )
        );
    }

    return is_array( $data ) ? $data : array();
}

function wp_amazon_business_get_cart_id( $access_token ) {

    if ( AMAZON_CART_ID ) {
        return AMAZON_CART_ID;
    }

    $url = add_query_arg(
        array(
            'region'   => 'CA',
            'pageSize' => 10,
        ),
        'https://na.business-api.amazon.com/cart/2025-04-30/carts'
    );

    $data = wp_amazon_business_cart_request( 'GET', $url, $access_token );

    if ( is_wp_error( $data ) ) {
        return $data;
    }

    $carts = $data['cartDetailsList'] ?? array();

    if ( empty( $carts ) ) {
        return new WP_Error(
            'amazon_cart_not_found',
            'No Amazon Business cart was found for this customer.'
        );
    }

    foreach ( $carts as $cart ) {
        if ( ! empty( $cart['id'] ) && ( $cart['cartType'] ?? '' ) === 'AMAZON_WEBSITE_CART' ) {
            return $cart['id'];
        }
    }

    foreach ( $carts as $cart ) {
        if ( ! empty( $cart['id'] ) && ( $cart['cartType'] ?? '' ) === 'DEFAULT' ) {
            return $cart['id'];
        }
    }

    return ! empty( $carts[0]['id'] )
        ? $carts[0]['id']
        : new WP_Error( 'amazon_cart_not_found', 'No usable Amazon cart ID was returned.' );
}

function wp_amazon_find_nested_value( $data, $wanted_key ) {

    if ( ! is_array( $data ) ) {
        return '';
    }

    foreach ( $data as $key => $value ) {

        if ( $key === $wanted_key && ! is_array( $value ) && $value !== '' ) {
            return (string) $value;
        }

        if ( is_array( $value ) ) {
            $found = wp_amazon_find_nested_value( $value, $wanted_key );

            if ( $found !== '' ) {
                return $found;
            }
        }
    }

    return '';
}

function wp_amazon_add_item_to_cart_ajax() {

    check_ajax_referer( 'amazon_add_to_cart', 'nonce' );

    $asin     = isset( $_POST['asin'] ) ? sanitize_text_field( wp_unslash( $_POST['asin'] ) ) : '';
    $woo_product_id = isset( $_POST['woo_product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_product_id'] ) ) : '';
    $buying_option_id = isset( $_POST['buying_option_id'] ) ? sanitize_text_field( wp_unslash( $_POST['buying_option_id'] ) ) : '';
    $offer_id         = isset( $_POST['offer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['offer_id'] ) ) : '';
    if ( ! $buying_option_id ) {
        $buying_option_id = $offer_id;
    }
    $quantity = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1;
    $title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

    if ( ! $asin || ! $buying_option_id ) {
        wp_send_json_error(
            array( 'message' => 'Amazon ASIN or buying option identifier is missing.' ),
            400
        );
    }

    $quantity = max( 1, $quantity );

    $access_token = wp_amazon_get_access_token();

    if ( is_wp_error( $access_token ) ) {
        wp_send_json_error(
            array( 'message' => $access_token->get_error_message() ),
            500
        );
    }

    $cart_id = wp_amazon_business_get_cart_id( $access_token );

    if ( is_wp_error( $cart_id ) ) {
        wp_send_json_error(
            array( 'message' => $cart_id->get_error_message() ),
            500
        );
    }

    $url = add_query_arg(
        array( 'region' => 'CA' ),
        'https://na.business-api.amazon.com/cart/2025-04-30/carts/' . rawurlencode( $cart_id ) . '/items'
    );

    $body = array(
        'items' => array(
            array(
                'productIdentifier'      => $asin,
                'buyingOptionIdentifier' => $buying_option_id,
                'quantity'               => $quantity,
                'externalId'             => 'ccsupplies-' . wp_generate_uuid4(),
            ),
        ),
    );

    $result = wp_amazon_business_cart_request(
        'POST',
        $url,
        $access_token,
        $body
    );

    if ( is_wp_error( $result ) ) {

        $error_data = $result->get_error_data();
        $message = $result->get_error_message();

        if ( ! empty( $error_data['response']['errors'][0]['message'] ) ) {
            $message = $error_data['response']['errors'][0]['message'];
        }

        wp_send_json_error(
            array(
                'message' => $message,
                'details' => $error_data,
            ),
            500
        );
    }

    if ( ! empty( $result['rejectedItems'] ) ) {
        wp_send_json_error(
            array(
                'message' => 'Amazon rejected this item.',
                'cart'    => $result,
            ),
            409
        );
    }

    if ( $woo_product_id ) {
        update_post_meta( (int) $woo_product_id, 'amazon_asin', $asin );
    }

    if ( $title && function_exists( 'wcss_amazon_remember_asin_title' ) ) {
        wcss_amazon_remember_asin_title( $asin, $title );
    }

    $cart_items = array();
    if ( isset( $result['items'] ) && is_array( $result['items'] ) ) {
        $cart_items = $result['items'];
    } elseif ( isset( $result['cart']['items'] ) && is_array( $result['cart']['items'] ) ) {
        $cart_items = $result['cart']['items'];
    }

    wp_send_json_success(
        array(
            'message' => $title
                ? $title . ' was added to the Amazon cart.'
                : 'Item was added to the Amazon cart.',
            'cart_id' => $cart_id,
            'item_count' => count( $cart_items ),
            'cart'    => $result,
        )
    );
}

/**
 * Published store products that belong in Amazon search.
 *
 * Drafts stay out, including the test product. The empty "Product 1"
 * placeholder is not a real catalog item.
 *
 * @return array<int,array{id:int,name:string,sku:string}>
 */
function wcss_amazon_catalog_store_products() {

    $product_ids = get_posts(
        array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'orderby'                => 'title',
            'order'                  => 'ASC',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        )
    );

    $products = array();

    foreach ( $product_ids as $product_id ) {

        $product = wc_get_product( $product_id );

        if ( ! $product || 'publish' !== $product->get_status() ) {
            continue;
        }

        $name = $product->get_name();
        $sku  = (string) $product->get_sku();

        if ( wcss_amazon_catalog_is_placeholder( $name, $sku ) ) {
            continue;
        }

        $products[] = array(
            'id'   => $product->get_id(),
            'name' => $name,
            'sku'  => $sku,
        );
    }

    return $products;
}

/**
 * @param string $name Product name.
 * @param string $sku  Product SKU.
 */
function wcss_amazon_catalog_is_placeholder( $name, $sku ) {

    $name = strtolower( trim( (string) $name ) );
    $sku  = strtolower( trim( (string) $sku ) );

    if ( in_array( $name, array( 'test product', 'product 1' ), true ) ) {
        return true;
    }

    return in_array( $sku, array( 'sku-111', '12345' ), true );
}

/**
 * Search Amazon for one published store product.
 */
function wp_amazon_search_store_product_ajax() {

    if (
        ! is_user_logged_in()
        || ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) )
    ) {
        wp_send_json_error(
            array( 'message' => 'You do not have permission to search the Amazon catalog.' ),
            403
        );
    }

    check_ajax_referer( 'amazon_search_store_product', 'nonce' );

    $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
    $product    = $product_id ? wc_get_product( $product_id ) : false;

    if (
        ! $product
        || 'publish' !== $product->get_status()
        || wcss_amazon_catalog_is_placeholder( $product->get_name(), (string) $product->get_sku() )
    ) {
        wp_send_json_error(
            array( 'message' => 'That product is not in the store catalog.' ),
            404
        );
    }

    $amazon_result = wp_amazon_business_search_products( $product->get_name(), 1 );

    if ( is_wp_error( $amazon_result ) ) {
        wp_send_json_error(
            array( 'message' => $amazon_result->get_error_message() ),
            502
        );
    }

    if ( empty( $amazon_result['products'][0] ) || ! is_array( $amazon_result['products'][0] ) ) {
        wp_send_json_error(
            array( 'message' => 'No Amazon product found for ' . $product->get_name() . '.' ),
            404
        );
    }

    $amazon_product = $amazon_result['products'][0];
    $offer          = array();

    if ( ! empty( $amazon_product['includedDataTypes']['OFFERS'][0] ) && is_array( $amazon_product['includedDataTypes']['OFFERS'][0] ) ) {
        $offer = $amazon_product['includedDataTypes']['OFFERS'][0];
    }

    $image = '';

    if ( ! empty( $amazon_product['includedDataTypes']['IMAGES'][0]['medium']['url'] ) ) {
        $image = $amazon_product['includedDataTypes']['IMAGES'][0]['medium']['url'];
    } elseif ( ! empty( $amazon_product['includedDataTypes']['IMAGES'][0]['large']['url'] ) ) {
        $image = $amazon_product['includedDataTypes']['IMAGES'][0]['large']['url'];
    }

    $offer_id         = isset( $offer['offerId'] ) ? (string) $offer['offerId'] : '';
    $buying_option_id = wp_amazon_find_nested_value( $amazon_product, 'buyingOptionIdentifier' );

    if ( ! $buying_option_id ) {
        $buying_option_id = $offer_id;
    }

    $price = '';

    if ( isset( $offer['price']['value']['amount'] ) ) {
        $price = $offer['price']['value']['amount'];
    }

    wp_send_json_success(
        array(
            'woo_product_id'   => $product->get_id(),
            'woo_product_name' => $product->get_name(),
            'asin'             => isset( $amazon_product['asin'] ) ? (string) $amazon_product['asin'] : '',
            'title'            => isset( $amazon_product['title'] ) ? (string) $amazon_product['title'] : '',
            'image'            => $image,
            'url'              => isset( $amazon_product['url'] ) ? (string) $amazon_product['url'] : '',
            'price'            => $price,
            'currency'         => isset( $offer['price']['value']['currencyCode'] ) ? (string) $offer['price']['value']['currencyCode'] : 'CAD',
            'availability'     => isset( $offer['availability'] ) ? (string) $offer['availability'] : '',
            'offer_id'         => $offer_id,
            'buying_option_id' => $buying_option_id,
        )
    );
}

// The template handles its own AJAX request. A page template is not loaded
// during /wp-admin/admin-ajax.php, so registering wp_ajax hooks here would
// result in WordPress returning 400/0 before this template is executed.
if ( isset( $_POST['amazon_catalog_action'] ) ) {

    $amazon_catalog_action = sanitize_key( wp_unslash( $_POST['amazon_catalog_action'] ) );

    if ( 'amazon_add_to_cart' === $amazon_catalog_action ) {
        wp_amazon_add_item_to_cart_ajax();
        exit;
    }

    if ( 'amazon_search_store_product' === $amazon_catalog_action ) {
        wp_amazon_search_store_product_ajax();
        exit;
    }
}

// ======================================
// AMAZON CATALOG FOR WOOCOMMERCE ORDER
// ======================================
$order_id = isset( $_GET['order-id'] ) ? absint( $_GET['order-id'] ) : 0;

$products       = array();
$order          = false;
$results        = null;
$store_products = function_exists( 'wc_get_product' ) ? wcss_amazon_catalog_store_products() : array();
$cart_url       = $order_id
    ? add_query_arg( 'order-id', $order_id, home_url( '/amazon-cart/' ) )
    : home_url( '/amazon-cart/' );

if ( $order_id ) {

    $order = wc_get_order( $order_id );

    if ( ! $order ) {

        $results = new WP_Error(
            'invalid_order',
            'WooCommerce order could not be found.'
        );

    } else {

        $results = array();

        /*
         * Search Amazon one WooCommerce order item at a time.
         * pageSize = 1 returns the top matching Amazon product.
         */
        foreach ( $order->get_items() as $order_item_id => $order_item ) {

            $woo_product = $order_item->get_product();

            if ( ! $woo_product ) {
                continue;
            }

            $woo_product_title = $woo_product->get_name();
            $quantity           = $order_item->get_quantity();

            $amazon_result = wp_amazon_business_search_products(
                $woo_product_title,
                1
            );

            if ( is_wp_error( $amazon_result ) ) {

                $products[] = array(
                    '_amazon_error'      => $amazon_result->get_error_message(),
                    '_woo_order_item_id' => $order_item_id,
                    '_woo_product_id'    => $woo_product->get_id(),
                    '_woo_product_title' => $woo_product_title,
                    '_woo_quantity'      => $quantity,
                );

                continue;
            }

            if ( empty( $amazon_result['products'] ) ) {

                $products[] = array(
                    '_amazon_error'      => 'No Amazon product found.',
                    '_woo_order_item_id' => $order_item_id,
                    '_woo_product_id'    => $woo_product->get_id(),
                    '_woo_product_title' => $woo_product_title,
                    '_woo_quantity'      => $quantity,
                );

                continue;
            }

            $amazon_product = $amazon_result['products'][0];

            // Keep WooCommerce context with the Amazon result.
            $amazon_product['_woo_order_item_id'] = $order_item_id;
            $amazon_product['_woo_product_id']    = $woo_product->get_id();
            $amazon_product['_woo_product_title'] = $woo_product_title;
            $amazon_product['_woo_quantity']      = $quantity;

            $products[] = $amazon_product;
        }
    }
}

get_header();

?>

<style>
/* =========================================================
   AMAZON CATALOG - FULLY SCOPED STYLES
========================================================= */
.amazon-catalog-page,
.amazon-catalog-page * {
    box-sizing: border-box;
}

.amazon-catalog-page {
    width: 100%;
    max-width: 1280px;
    margin: 40px auto;
    padding: 0 20px 60px;
    font-family: inherit;
    color: #161616;
}

.amazon-catalog-page .ac-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 22px;
    padding: 22px 26px;
    border-radius: 14px;
    background: #000;
    color: #fff;
}

.amazon-catalog-page .ac-header-copy {
    min-width: 0;
}

.amazon-catalog-page .ac-header h1 {
    margin: 0 0 8px !important;
    padding: 0 !important;
    color: #fff !important;
    font-size: 30px !important;
    line-height: 1.25 !important;
}

.amazon-catalog-page .ac-header p {
    margin: 0 !important;
    max-width: 640px;
    color: #f0f0f0;
    font-size: 15px;
    line-height: 1.45;
}

.amazon-catalog-page .ac-header-cart {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
    flex: 0 0 auto;
}

.amazon-catalog-page .ac-view-cart {
    display: inline-flex !important;
    align-items: center;
    gap: 10px;
    min-height: 48px;
    padding: 12px 18px !important;
    border-radius: 8px;
    background: #e1251c !important;
    color: #fff !important;
    text-decoration: none !important;
    font-size: 15px !important;
    font-weight: 700 !important;
    line-height: 1 !important;
    box-shadow: 0 8px 18px rgba(225, 37, 28, .28);
}

.amazon-catalog-page .ac-view-cart:hover {
    background: #c61d16 !important;
    color: #fff !important;
}

.amazon-catalog-page .ac-view-cart-count {
    display: none;
    min-width: 24px;
    height: 24px;
    padding: 0 7px;
    border-radius: 999px;
    background: #fff;
    color: #e1251c;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 800;
}

.amazon-catalog-page .ac-view-cart-count.is-visible {
    display: inline-flex;
}

.amazon-catalog-page .ac-view-cart.is-updated {
    transform: translateY(-1px);
}

.amazon-catalog-page .ac-cart-note {
    margin: 0 !important;
    color: #fff !important;
    font-size: 13px !important;
    font-weight: 600;
    text-align: right;
}

.amazon-catalog-page .ac-error {
    padding: 18px;
    border: 1px solid #e0b4b4;
    border-radius: 8px;
    background: #fff6f6;
    color: #8a1f1f;
}

.amazon-catalog-page .ac-empty {
    padding: 30px;
    text-align: center;
    border: 1px solid #ddd;
    border-radius: 10px;
    background: #fff;
}

.amazon-catalog-page .ac-list {
    display: flex;
    flex-direction: column;
    gap: 22px;
}

.amazon-catalog-page .ac-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 70px minmax(0, 1fr);
    gap: 18px;
    align-items: stretch;
}

.amazon-catalog-page .ac-panel {
    min-width: 0;
    border: 1px solid #dedede;
    border-radius: 12px;
    background: #fff;
    overflow: hidden;
}

.amazon-catalog-page .ac-panel-header {
    padding: 13px 16px;
    border-bottom: 1px solid #e8e8e8;
    background: #f7f7f7;
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.amazon-catalog-page .ac-panel-header.woo {
    background: #f1f6ff;
}

.amazon-catalog-page .ac-panel-header.amazon {
    background: #fff7e8;
}

.amazon-catalog-page .ac-panel-body {
    padding: 18px;
}

.amazon-catalog-page .ac-product {
    display: grid;
    grid-template-columns: 150px minmax(0, 1fr);
    gap: 18px;
    align-items: start;
}

.amazon-catalog-page .ac-image {
    width: 150px;
    height: 150px;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.amazon-catalog-page .ac-image img {
    display: block;
    max-width: 100%;
    max-height: 100%;
    width: auto;
    height: auto;
    object-fit: contain;
}

.amazon-catalog-page .ac-image-placeholder {
    color: #999;
    font-size: 13px;
}

.amazon-catalog-page .ac-title {
    margin: 0 0 12px !important;
    padding: 0 !important;
    font-size: 18px !important;
    line-height: 1.4 !important;
    font-weight: 700 !important;
}

.amazon-catalog-page .ac-meta {
    display: grid;
    grid-template-columns: 120px minmax(0, 1fr);
    gap: 7px 10px;
    margin: 0;
    font-size: 14px;
}

.amazon-catalog-page .ac-meta dt {
    margin: 0;
    font-weight: 700;
    color: #555;
}

.amazon-catalog-page .ac-meta dd {
    margin: 0;
    min-width: 0;
    overflow-wrap: anywhere;
}

.amazon-catalog-page .ac-price {
    font-size: 20px;
    font-weight: 700;
    margin-top: 14px;
}

.amazon-catalog-page .ac-qty {
    display: inline-block;
    margin-top: 12px;
    padding: 5px 9px;
    border-radius: 5px;
    background: #f1f1f1;
    font-size: 13px;
    font-weight: 600;
}

.amazon-catalog-page .ac-arrow {
    align-self: center;
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: #f4f4f4;
    border: 1px solid #ddd;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #555;
    font-size: 28px;
    font-weight: 400;
}

.amazon-catalog-page .ac-amazon-title {
    font-size: 18px !important;
}

.amazon-catalog-page .ac-amazon-actions {
    margin-top: 18px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.amazon-catalog-page .ac-button {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    min-height: 42px;
    padding: 10px 16px !important;
    border: 0 !important;
    border-radius: 6px !important;
    background: #ff9900 !important;
    color: #111 !important;
    text-decoration: none !important;
    cursor: pointer;
    font-size: 14px !important;
    font-weight: 700 !important;
    line-height: 1.2 !important;
}

.amazon-catalog-page .ac-button:hover {
    background: #e68a00 !important;
    color: #111 !important;
}

.amazon-catalog-page .ac-button.secondary {
    background: #333 !important;
    color: #fff !important;
}

.amazon-catalog-page .ac-button.secondary:hover {
    background: #111 !important;
    color: #fff !important;
}

.amazon-catalog-page .ac-not-found {
    padding: 20px;
}

.amazon-catalog-page .ac-not-found strong {
    display: block;
    margin-bottom: 7px;
    font-size: 16px;
}

.amazon-catalog-page .ac-not-found span {
    color: #777;
    font-size: 14px;
}

.amazon-catalog-page .ac-order-summary {
    margin-top: 28px;
    padding: 16px 18px;
    border: 1px solid #ddd;
    border-radius: 10px;
    background: #fafafa;
    font-size: 14px;
}

.amazon-catalog-page .ac-store-search {
    margin-bottom: 36px;
    padding: 0 0 22px;
    border: 1px solid #ececec;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 10px 28px rgba(0, 0, 0, .06);
    overflow: hidden;
}

.amazon-catalog-page .ac-store-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 22px;
    background: #000;
}

.amazon-catalog-page .ac-store-search h2 {
    margin: 0 !important;
    color: #fff !important;
    font-size: 20px !important;
    line-height: 1.3 !important;
}

.amazon-catalog-page .ac-store-total {
    display: inline-flex;
    align-items: center;
    min-height: 28px;
    padding: 4px 10px;
    border-radius: 999px;
    background: #e1251c;
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
}

.amazon-catalog-page .ac-store-search > p {
    margin: 18px 22px 14px !important;
    color: #3a3a3a;
    font-size: 15px;
}

.amazon-catalog-page .ac-store-input {
    display: block;
    width: calc(100% - 44px);
    max-width: none;
    min-height: 48px;
    margin: 0 22px;
    padding: 12px 14px;
    border: 1px solid #d5d5d5;
    border-radius: 8px;
    background: #fff;
    font-size: 15px;
}

.amazon-catalog-page .ac-store-input:focus {
    outline: 2px solid #e1251c;
    border-color: #e1251c;
}

.amazon-catalog-page .ac-store-count {
    margin: 10px 22px 0 !important;
    color: #555;
    font-size: 13px;
    font-weight: 600;
}

.amazon-catalog-page .ac-store-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 14px 22px 0;
}

.amazon-catalog-page .ac-store-item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px 16px;
    align-items: center;
    padding: 12px;
    border: 1px solid #eee;
    border-radius: 8px;
    background: #fafafa;
}

.amazon-catalog-page .ac-store-item[hidden] {
    display: none !important;
}

.amazon-catalog-page .ac-store-name {
    display: block;
    font-size: 15px;
}

.amazon-catalog-page .ac-store-sku {
    display: block;
    margin-top: 3px;
    color: #777;
    font-size: 12px;
}

.amazon-catalog-page .ac-store-result {
    grid-column: 1 / -1;
    margin-top: 4px;
    padding: 12px;
    border: 1px solid #f0e2c4;
    border-radius: 8px;
    background: #fff;
}

.amazon-catalog-page .ac-store-result-grid {
    display: grid;
    grid-template-columns: 72px minmax(0, 1fr);
    gap: 12px;
    align-items: start;
}

.amazon-catalog-page .ac-store-result img {
    width: 72px;
    height: 72px;
    object-fit: contain;
    border: 1px solid #eee;
    border-radius: 6px;
    background: #fff;
}

.amazon-catalog-page .ac-store-empty {
    margin: 14px 22px 0;
    color: #777;
    font-size: 14px;
}

@media (max-width: 720px) {
    .amazon-catalog-page .ac-header,
    .amazon-catalog-page .ac-store-head {
        flex-direction: column;
        align-items: flex-start;
    }

    .amazon-catalog-page .ac-header-cart,
    .amazon-catalog-page .ac-cart-note {
        align-items: flex-start;
        text-align: left;
    }
}


/* =========================================================
   QUICK VIEW MODAL
========================================================= */
.amazon-catalog-page .ac-quick-view {
    margin-top: 12px;
}

.amazon-catalog-page .ac-modal {
    display: none;
    position: fixed;
    z-index: 999999;
    inset: 0;
    padding: 30px 20px;
    background: rgba(0,0,0,.65);
    overflow-y: auto;
}

.amazon-catalog-page .ac-modal.is-open {
    display: flex;
    align-items: center;
    justify-content: center;
}

.amazon-catalog-page .ac-modal-content {
    position: relative;
    width: 100%;
    max-width: 1000px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 28px;
    border-radius: 12px;
    background: #fff;
}

.amazon-catalog-page .ac-modal-close {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 36px;
    height: 36px;
    padding: 0 !important;
    border: 0 !important;
    border-radius: 50% !important;
    background: #eee !important;
    color: #222 !important;
    font-size: 24px !important;
    line-height: 36px !important;
    cursor: pointer;
}

.amazon-catalog-page .ac-modal-grid {
    display: grid;
    grid-template-columns: 42% 58%;
    gap: 30px;
}

.amazon-catalog-page .ac-modal-image {
    width: 100%;
    height: 360px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #eee;
    border-radius: 8px;
}

.amazon-catalog-page .ac-modal-image img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.amazon-catalog-page .ac-modal-title {
    margin: 0 45px 18px 0 !important;
    padding: 0 !important;
    font-size: 24px !important;
    line-height: 1.35 !important;
}

.amazon-catalog-page .ac-modal-section {
    margin-top: 22px;
}

.amazon-catalog-page .ac-modal-section h3 {
    margin: 0 0 10px !important;
    font-size: 17px !important;
}

.amazon-catalog-page .ac-modal-features {
    margin: 0;
    padding-left: 20px;
}

.amazon-catalog-page .ac-modal-features li {
    margin-bottom: 7px;
}

.amazon-catalog-page .ac-modal-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 24px;
}

.amazon-catalog-page .ac-button.is-loading {
    opacity: .65;
    pointer-events: none;
}

.amazon-catalog-page .ac-button:disabled {
    opacity: .65;
    cursor: wait;
}

.amazon-catalog-page .ac-cart-message {
    width: 100%;
    margin-top: 10px;
    font-size: 14px;
    font-weight: 600;
}

@media (max-width: 700px) {
    .amazon-catalog-page .ac-modal-grid {
        grid-template-columns: 1fr;
    }

    .amazon-catalog-page .ac-modal-content {
        padding: 20px;
    }

    .amazon-catalog-page .ac-modal-image {
        height: 280px;
    }
}

@media (max-width: 900px) {
    .amazon-catalog-page .ac-row {
        grid-template-columns: 1fr;
    }

    .amazon-catalog-page .ac-arrow {
        margin: -2px auto;
        transform: rotate(90deg);
    }
}

@media (max-width: 600px) {
    .amazon-catalog-page {
        margin-top: 25px;
        padding: 0 12px 40px;
    }

    .amazon-catalog-page .ac-product {
        grid-template-columns: 1fr;
    }

    .amazon-catalog-page .ac-image {
        width: 100%;
        height: 190px;
    }

    .amazon-catalog-page .ac-meta {
        grid-template-columns: 1fr;
        gap: 3px;
    }

    .amazon-catalog-page .ac-meta dt {
        margin-top: 8px;
    }

    .amazon-catalog-page .ac-meta dd {
        margin-bottom: 2px;
    }
}
</style>

<div class="amazon-catalog-page">

    <div class="ac-header">
        <div class="ac-header-copy">
            <h1>
                <?php if ( $order_id ) : ?>
                    Order #<?php echo esc_html( $order_id ); ?> — Amazon Product Matching
                <?php else : ?>
                    Amazon Product Search
                <?php endif; ?>
            </h1>

            <p>
                Search the published store catalog, then look up the top Amazon match for a product.
                <?php if ( $order ) : ?>
                    Order items are still matched below.
                <?php endif; ?>
            </p>
        </div>

        <div class="ac-header-cart">
            <a class="ac-view-cart" id="ac-view-cart" href="<?php echo esc_url( $cart_url ); ?>">
                <span>View Cart</span>
                <span class="ac-view-cart-count" id="ac-view-cart-count" hidden>0</span>
            </a>
            <p class="ac-cart-note" id="ac-cart-note" hidden></p>
        </div>
    </div>

    <section class="ac-store-search">
        <div class="ac-store-head">
            <h2>Store catalog</h2>
            <span class="ac-store-total"><?php echo esc_html( count( $store_products ) ); ?> published products</span>
        </div>
        <p>Type a name or SKU, then search Amazon for that product.</p>

        <input
            id="ac-store-search-input"
            class="ac-store-input"
            type="search"
            placeholder="Search by product name or SKU"
            autocomplete="off"
        >

        <p class="ac-store-count" id="ac-store-count">Type at least 2 letters to search the catalog.</p>

        <div class="ac-store-list" id="ac-store-list">
            <?php foreach ( $store_products as $store_product ) : ?>
                <?php
                $store_search = strtolower( $store_product['name'] . ' ' . $store_product['sku'] );
                ?>
                <article
                    class="ac-store-item"
                    hidden
                    data-search="<?php echo esc_attr( $store_search ); ?>"
                >
                    <div>
                        <strong class="ac-store-name"><?php echo esc_html( $store_product['name'] ); ?></strong>
                        <?php if ( $store_product['sku'] !== '' ) : ?>
                            <span class="ac-store-sku">SKU <?php echo esc_html( $store_product['sku'] ); ?></span>
                        <?php endif; ?>
                    </div>

                    <button
                        type="button"
                        class="ac-button ac-search-amazon"
                        data-product-id="<?php echo esc_attr( $store_product['id'] ); ?>"
                    >
                        Search Amazon
                    </button>

                    <div class="ac-store-result" hidden></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ( is_wp_error( $results ) ) : ?>

        <div class="ac-error">
            <strong><?php echo esc_html( $results->get_error_message() ); ?></strong>
        </div>

    <?php elseif ( $order && empty( $products ) ) : ?>

        <div class="ac-empty">
            No products were found in this WooCommerce order.
        </div>

    <?php elseif ( $order ) : ?>

        <div class="ac-list">

            <?php foreach ( $products as $index => $product ) : ?>

                <?php
                /*
                 * WooCommerce product information
                 */
                $woo_title    = $product['_woo_product_title'] ?? '';
                $woo_quantity = $product['_woo_quantity'] ?? 1;
                $woo_product_id = $product['_woo_product_id'] ?? '';

                /*
                 * Amazon result information
                 */
                $has_amazon_error = ! empty( $product['_amazon_error'] );

                $asin  = $product['asin'] ?? '';
                $title = $product['title'] ?? 'Amazon Product';

                $image = '';

                if ( ! empty( $product['includedDataTypes']['IMAGES'][0]['large']['url'] ) ) {
                    $image = $product['includedDataTypes']['IMAGES'][0]['large']['url'];
                } elseif ( ! empty( $product['includedDataTypes']['IMAGES'][0]['medium']['url'] ) ) {
                    $image = $product['includedDataTypes']['IMAGES'][0]['medium']['url'];
                }

                $offer = array();

                if ( ! empty( $product['includedDataTypes']['OFFERS'][0] ) ) {
                    $offer = $product['includedDataTypes']['OFFERS'][0];
                }

                $price = '';

                if ( isset( $offer['price']['value']['amount'] ) ) {
                    $price = $offer['price']['value']['amount'];
                }

                $currency = $offer['price']['value']['currencyCode'] ?? 'USD';
                $availability = $offer['availability'] ?? '';
                $offer_id = $offer['offerId'] ?? '';
                $buying_option_id = wp_amazon_find_nested_value( $product, 'buyingOptionIdentifier' );

                if ( ! $buying_option_id ) {
                    $buying_option_id = $offer_id;
                }
                ?>

                <div class="ac-row">

                    <!-- ==========================
                         WOOCOMMERCE PRODUCT
                    =========================== -->
                    <section class="ac-panel">

                        <div class="ac-panel-header woo">
                            Product in WooCommerce Order
                        </div>

                        <div class="ac-panel-body">

                            <div class="ac-product">

                                <div class="ac-image">

                                    <?php
                                    $woo_image = '';

                                    if ( $woo_product_id ) {
                                        $woo_product_object = wc_get_product( $woo_product_id );

                                        if ( $woo_product_object ) {
                                            $woo_image = wp_get_attachment_image_url(
                                                $woo_product_object->get_image_id(),
                                                'medium'
                                            );
                                        }
                                    }
                                    ?>

                                    <?php if ( $woo_image ) : ?>

                                        <img
                                            src="<?php echo esc_url( $woo_image ); ?>"
                                            alt="<?php echo esc_attr( $woo_title ); ?>"
                                            loading="lazy"
                                        >

                                    <?php else : ?>

                                        <span class="ac-image-placeholder">No image</span>

                                    <?php endif; ?>

                                </div>

                                <div>

                                    <h2 class="ac-title">
                                        <?php echo esc_html( $woo_title ); ?>
                                    </h2>

                                    <dl class="ac-meta">

                                        <?php if ( $woo_product_id ) : ?>
                                            <dt>Product ID</dt>
                                            <dd><?php echo esc_html( $woo_product_id ); ?></dd>
                                        <?php endif; ?>

                                        <dt>Quantity</dt>
                                        <dd><?php echo esc_html( $woo_quantity ); ?></dd>

                                    </dl>

                                    <span class="ac-qty">
                                        Ordered quantity: <?php echo esc_html( $woo_quantity ); ?>
                                    </span>

                                </div>

                            </div>

                        </div>

                    </section>

                    <!-- MATCH ARROW -->
                    <div class="ac-arrow" aria-hidden="true">
                        →
                    </div>

                    <!-- ==========================
                         AMAZON MATCH
                    =========================== -->
                    <section class="ac-panel">

                        <div class="ac-panel-header amazon">
                            <?php echo $has_amazon_error ? 'Amazon Search Result' : 'Relevant Amazon Product'; ?>
                        </div>

                        <?php if ( $has_amazon_error ) : ?>

                            <div class="ac-not-found">

                                <strong>
                                    No Amazon product found
                                </strong>

                                <span>
                                    <?php echo esc_html( $product['_amazon_error'] ); ?>
                                </span>

                            </div>

                        <?php else : ?>

                            <div class="ac-panel-body">

                                <div class="ac-product">

                                    <div class="ac-image">

                                        <?php if ( $image ) : ?>

                                            <img
                                                src="<?php echo esc_url( $image ); ?>"
                                                alt="<?php echo esc_attr( $title ); ?>"
                                                loading="lazy"
                                            >

                                        <?php else : ?>

                                            <span class="ac-image-placeholder">No image</span>

                                        <?php endif; ?>

                                    </div>

                                    <div>

                                        <h2 class="ac-title ac-amazon-title">
                                            <?php echo esc_html( $title ); ?>
                                        </h2>

                                        <dl class="ac-meta">

                                            <?php if ( $asin ) : ?>
                                                <dt>ASIN</dt>
                                                <dd><?php echo esc_html( $asin ); ?></dd>
                                            <?php endif; ?>

                                            <?php if ( $availability ) : ?>
                                                <dt>Availability</dt>
                                                <dd><?php echo esc_html( $availability ); ?></dd>
                                            <?php endif; ?>

                                        </dl>

                                        <?php if ( $price !== '' ) : ?>

                                            <div class="ac-price">
                                                <?php echo esc_html( $currency ); ?>
                                                <?php echo esc_html( number_format( (float) $price, 2 ) ); ?>
                                            </div>

                                        <?php endif; ?>

                                        <div class="ac-amazon-actions">

                                            <button
                                                type="button"
                                                class="ac-button secondary ac-quick-view"
                                                data-modal="ac-modal-<?php echo esc_attr( $index ); ?>"
                                            >
                                                Quick View
                                            </button>

                                            <button
                                                type="button"
                                                class="ac-button ac-add-to-cart"
                                                data-asin="<?php echo esc_attr( $asin ); ?>"
                                                data-offer-id="<?php echo esc_attr( $offer_id ); ?>"
                                                data-buying-option-id="<?php echo esc_attr( $buying_option_id ); ?>"
                                                data-title="<?php echo esc_attr( $title ); ?>"
                                                data-quantity="<?php echo esc_attr( $woo_quantity ); ?>"
                                                data-woo_product_id="<?php echo esc_attr( $woo_product_id ); ?>"
                                            >
                                                Add to Amazon Cart
                                            </button>

                                            <?php if ( ! empty( $product['url'] ) ) : ?>

                                                <a
                                                    class="ac-button secondary"
                                                    href="<?php echo esc_url( $product['url'] ); ?>"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    View on Amazon
                                                </a>

                                            <?php endif; ?>

                                            <div class="ac-cart-message" aria-live="polite"></div>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        <?php endif; ?>

                    </section>


                </div>

                <!-- ==========================
                     AMAZON QUICK VIEW MODAL
                =========================== -->
                <div
                    id="ac-modal-<?php echo esc_attr( $index ); ?>"
                    class="ac-modal"
                    aria-hidden="true"
                >
                    <div class="ac-modal-content">

                        <button
                            type="button"
                            class="ac-modal-close"
                            aria-label="Close"
                        >
                            &times;
                        </button>

                        <div class="ac-modal-grid">

                            <div>
                                <div class="ac-modal-image">

                                    <?php if ( $image ) : ?>

                                        <img
                                            src="<?php echo esc_url( $image ); ?>"
                                            alt="<?php echo esc_attr( $title ); ?>"
                                        >

                                    <?php else : ?>

                                        <span class="ac-image-placeholder">No image</span>

                                    <?php endif; ?>

                                </div>
                            </div>

                            <div>

                                <h2 class="ac-modal-title">
                                    <?php echo esc_html( $title ); ?>
                                </h2>

                                <dl class="ac-meta">

                                    <?php if ( $asin ) : ?>
                                        <dt>ASIN</dt>
                                        <dd><?php echo esc_html( $asin ); ?></dd>
                                    <?php endif; ?>

                                    <?php if ( $price !== '' ) : ?>
                                        <dt>Price</dt>
                                        <dd>
                                            <strong>
                                                <?php echo esc_html( $currency ); ?>
                                                <?php echo esc_html( number_format( (float) $price, 2 ) ); ?>
                                            </strong>
                                        </dd>
                                    <?php endif; ?>

                                    <?php if ( $availability ) : ?>
                                        <dt>Availability</dt>
                                        <dd><?php echo esc_html( $availability ); ?></dd>
                                    <?php endif; ?>

                                    <?php if ( ! empty( $offer['merchant']['name'] ) ) : ?>
                                        <dt>Seller</dt>
                                        <dd><?php echo esc_html( $offer['merchant']['name'] ); ?></dd>
                                    <?php endif; ?>

                                    <?php if ( ! empty( $offer['fulfiller']['name'] ) ) : ?>
                                        <dt>Fulfilled By</dt>
                                        <dd><?php echo esc_html( $offer['fulfiller']['name'] ); ?></dd>
                                    <?php endif; ?>

                                </dl>

                                <?php
                                $overview = $product['productOverview'] ?? array();
                                $features = $product['features'] ?? array();
                                $description = $product['productDescription'] ?? '';
                                ?>

                                <?php if ( ! empty( $overview ) ) : ?>

                                    <div class="ac-modal-section">

                                        <h3>Product Details</h3>

                                        <dl class="ac-meta">

                                            <?php foreach ( $overview as $label => $value ) : ?>

                                                <?php
                                                if ( is_array( $value ) || $value === '' ) {
                                                    continue;
                                                }
                                                ?>

                                                <dt><?php echo esc_html( $label ); ?></dt>
                                                <dd><?php echo esc_html( $value ); ?></dd>

                                            <?php endforeach; ?>

                                        </dl>

                                    </div>

                                <?php endif; ?>

                                <?php if ( ! empty( $features ) ) : ?>

                                    <div class="ac-modal-section">

                                        <h3>Key Features</h3>

                                        <ul class="ac-modal-features">

                                            <?php foreach ( $features as $feature ) : ?>

                                                <li>
                                                    <?php echo esc_html( $feature ); ?>
                                                </li>

                                            <?php endforeach; ?>

                                        </ul>

                                    </div>

                                <?php endif; ?>

                                <?php if ( $description ) : ?>

                                    <div class="ac-modal-section">

                                        <h3>Description</h3>

                                        <div>
                                            <?php echo esc_html( $description ); ?>
                                        </div>

                                    </div>

                                <?php endif; ?>

                                <div class="ac-modal-actions">

                                    <button
                                        type="button"
                                        class="ac-button ac-add-to-cart"
                                        data-asin="<?php echo esc_attr( $asin ); ?>"
                                                data-offer-id="<?php echo esc_attr( $offer_id ); ?>"
                                                data-buying-option-id="<?php echo esc_attr( $buying_option_id ); ?>"
                                        data-title="<?php echo esc_attr( $title ); ?>"
                                        data-quantity="<?php echo esc_attr( $woo_quantity ); ?>"
                                    >
                                        Add to Amazon Cart
                                    </button>

                                    <?php if ( ! empty( $product['url'] ) ) : ?>

                                        <a
                                            class="ac-button secondary"
                                            href="<?php echo esc_url( $product['url'] ); ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            View on Amazon
                                        </a>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </div>
                </div>

            <?php endforeach; ?>

        </div>

        <div class="ac-order-summary">
            <strong>Order #<?php echo esc_html( $order_id ); ?></strong>
            &nbsp; — &nbsp;
            <?php echo esc_html( count( $products ) ); ?> product(s) matched against Amazon.
        </div>

    <?php endif; ?>

</div>


<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ==========================================
       QUICK VIEW
    ========================================== */
    document.querySelectorAll('.amazon-catalog-page .ac-quick-view').forEach(function (button) {

        button.addEventListener('click', function () {

            var modalId = this.getAttribute('data-modal');
            var modal = document.getElementById(modalId);

            if (!modal) {
                return;
            }

            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

        });

    });

    function closeModal(modal) {

        if (!modal) {
            return;
        }

        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';

    }

    document.querySelectorAll('.amazon-catalog-page .ac-modal-close').forEach(function (button) {

        button.addEventListener('click', function () {
            closeModal(this.closest('.ac-modal'));
        });

    });

    document.querySelectorAll('.amazon-catalog-page .ac-modal').forEach(function (modal) {

        modal.addEventListener('click', function (event) {

            if (event.target === modal) {
                closeModal(modal);
            }

        });

    });

    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {

            document.querySelectorAll('.amazon-catalog-page .ac-modal.is-open').forEach(function (modal) {
                closeModal(modal);
            });

        }

    });

    /* ==========================================
       STORE CATALOG SEARCH
       ========================================== */
    var amazonCartAjaxUrl = <?php echo wp_json_encode( get_permalink() ); ?>;
    var amazonCartNonce  = <?php echo wp_json_encode( wp_create_nonce( 'amazon_add_to_cart' ) ); ?>;
    var amazonSearchNonce = <?php echo wp_json_encode( wp_create_nonce( 'amazon_search_store_product' ) ); ?>;
    var storeSearchInput = document.getElementById('ac-store-search-input');
    var storeCount = document.getElementById('ac-store-count');
    var storeItems = document.querySelectorAll('.amazon-catalog-page .ac-store-item');

    function filterStoreCatalog() {
        var query = storeSearchInput ? storeSearchInput.value.trim().toLowerCase() : '';
        var visible = 0;

        storeItems.forEach(function (item) {
            var haystack = item.getAttribute('data-search') || '';
            var show = query.length >= 2 && haystack.indexOf(query) !== -1;
            item.hidden = !show;
            if (show) {
                visible += 1;
            }
        });

        if (!storeCount) {
            return;
        }

        if (query.length < 2) {
            storeCount.textContent = 'Type at least 2 letters to search the catalog.';
            return;
        }

        storeCount.textContent = visible
            ? visible + ' product' + (visible === 1 ? '' : 's') + ' match.'
            : 'No store products match that search.';
    }

    if (storeSearchInput) {
        storeSearchInput.addEventListener('input', filterStoreCatalog);
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[character];
        });
    }

    function renderAmazonMatch(result) {
        var price = result.price === '' || result.price == null
            ? ''
            : escapeHtml(result.currency || 'CAD') + ' ' + Number(result.price).toFixed(2);
        var image = result.image
            ? '<img src="' + escapeHtml(result.image) + '" alt="">'
            : '';

        return ''
            + '<div class="ac-store-result-grid">'
            + image
            + '<div>'
            + '<strong class="ac-store-name">' + escapeHtml(result.title || 'Amazon product') + '</strong>'
            + '<dl class="ac-meta">'
            + (result.asin ? '<dt>ASIN</dt><dd>' + escapeHtml(result.asin) + '</dd>' : '')
            + (price ? '<dt>Price</dt><dd>' + price + '</dd>' : '')
            + (result.availability ? '<dt>Availability</dt><dd>' + escapeHtml(result.availability) + '</dd>' : '')
            + '</dl>'
            + '<div class="ac-amazon-actions">'
            + '<button type="button" class="ac-button ac-add-to-cart"'
            + ' data-asin="' + escapeHtml(result.asin) + '"'
            + ' data-offer-id="' + escapeHtml(result.offer_id) + '"'
            + ' data-buying-option-id="' + escapeHtml(result.buying_option_id) + '"'
            + ' data-title="' + escapeHtml(result.title) + '"'
            + ' data-quantity="1"'
            + ' data-woo_product_id="' + escapeHtml(result.woo_product_id) + '">'
            + 'Add to Amazon Cart</button>'
            + '<div class="ac-cart-message" aria-live="polite"></div>'
            + '</div>'
            + '</div>'
            + '</div>';
    }

    document.querySelectorAll('.amazon-catalog-page .ac-search-amazon').forEach(function (button) {
        button.addEventListener('click', function () {
            var item = button.closest('.ac-store-item');
            var resultBox = item ? item.querySelector('.ac-store-result') : null;
            var productId = button.getAttribute('data-product-id') || '';

            if (!resultBox || !productId) {
                return;
            }

            button.disabled = true;
            resultBox.hidden = false;
            resultBox.textContent = 'Searching Amazon...';

            var formData = new FormData();
            formData.append('amazon_catalog_action', 'amazon_search_store_product');
            formData.append('nonce', amazonSearchNonce);
            formData.append('product_id', productId);

            fetch(amazonCartAjaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (response) {
                if (response.data && response.data.success && response.data.data) {
                    resultBox.innerHTML = renderAmazonMatch(response.data.data);
                    return;
                }

                var message = 'No Amazon product found.';
                if (response.data && response.data.data && response.data.data.message) {
                    message = response.data.data.message;
                }
                resultBox.textContent = message;
            })
            .catch(function () {
                resultBox.textContent = 'Unable to connect to Amazon search.';
            })
            .finally(function () {
                button.disabled = false;
            });
        });
    });

    /* ==========================================
       AMAZON CART
       ========================================== */
    function showAmazonCartCount(count) {
        var badge = document.getElementById('ac-view-cart-count');
        var note = document.getElementById('ac-cart-note');
        var link = document.getElementById('ac-view-cart');
        var total = parseInt(count, 10);

        if (!badge) {
            return;
        }

        if (!total || total < 1) {
            total = (parseInt(badge.textContent || '0', 10) || 0) + 1;
        }

        badge.hidden = false;
        badge.classList.add('is-visible');
        badge.textContent = String(total);

        if (link) {
            link.classList.add('is-updated');
        }

        if (note) {
            note.hidden = false;
            note.textContent = total === 1
                ? '1 product is in the Amazon cart.'
                : total + ' products are in the Amazon cart.';
        }
    }

    document.querySelector('.amazon-catalog-page').addEventListener('click', function (event) {

        var button = event.target.closest('.ac-add-to-cart');

        if (!button || button.disabled) {
            return;
        }

            var container = button.closest('.ac-amazon-actions, .ac-modal-actions');
            var message = container ? container.querySelector('.ac-cart-message') : null;

            var asin = button.getAttribute('data-asin') || '';
            var offerId = button.getAttribute('data-offer-id') || '';
            var buyingOptionId = button.getAttribute('data-buying-option-id') || offerId;
            var title = button.getAttribute('data-title') || '';
            var quantity = parseInt(button.getAttribute('data-quantity') || '1', 10);
            var woo_product_id = button.getAttribute("data-woo_product_id");

            if (!asin || !buyingOptionId) {
                if (message) {
                    message.textContent = 'Amazon buying option information is missing for this product.';
                }
                return;
            }

            button.disabled = true;

            if (message) {
                message.textContent = 'Adding to Amazon cart...';
            }

            var formData = new FormData();
            formData.append('amazon_catalog_action', 'amazon_add_to_cart');
            formData.append('nonce', amazonCartNonce);
            formData.append("woo_product_id", woo_product_id),
            formData.append('asin', asin);
            formData.append('offer_id', offerId);
            formData.append('buying_option_id', buyingOptionId);
            formData.append('title', title);
            formData.append('quantity', quantity);

            fetch(amazonCartAjaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (response) {

                if (response.success) {

                    if (message) {
                        message.textContent = response.data.message || 'Added to Amazon cart.';
                    }

                    button.textContent = 'Added to Amazon Cart';
                    showAmazonCartCount(response.data && response.data.item_count);

                } else {

                    var errorMessage = 'Unable to add item to Amazon cart.';

                    if (response.data && response.data.message) {
                        errorMessage = response.data.message;
                    }

                    if (message) {
                        message.textContent = errorMessage;
                    }
                }

            })
            .catch(function () {

                if (message) {
                    message.textContent = 'Unable to connect to the Amazon Cart API.';
                }

            })
            .finally(function () {
                button.disabled = false;
            });

    });

});
</script>

<?php get_footer(); ?>
