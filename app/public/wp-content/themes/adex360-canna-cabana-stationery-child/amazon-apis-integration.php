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

    update_post_meta($woo_product_id, "amazon_asin", $asin, true );

    wp_send_json_success(
        array(
            'message' => $title
                ? $title . ' was added to the Amazon cart.'
                : 'Item was added to the Amazon cart.',
            'cart_id' => $cart_id,
            'cart'    => $result,
        )
    );
}

// The template handles its own AJAX request. A page template is not loaded
// during /wp-admin/admin-ajax.php, so registering wp_ajax hooks here would
// result in WordPress returning 400/0 before this template is executed.
if (
    isset( $_POST['amazon_catalog_action'] )
    && 'amazon_add_to_cart' === sanitize_key( wp_unslash( $_POST['amazon_catalog_action'] ) )
) {
    wp_amazon_add_item_to_cart_ajax();
    exit;
}

// ======================================
// AMAZON CATALOG FOR WOOCOMMERCE ORDER
// ======================================
$order_id = isset( $_GET['order-id'] ) ? absint( $_GET['order-id'] ) : 0;

$products = array();
$order = false;

if ( ! $order_id ) {

    $results = new WP_Error(
        'missing_order_id',
        'Order ID is missing from the URL.'
    );

} else {

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
    font-family: Arial, Helvetica, sans-serif;
    color: #222;
}

.amazon-catalog-page .ac-header {
    margin-bottom: 28px;
}

.amazon-catalog-page .ac-header h1 {
    margin: 0 0 8px !important;
    padding: 0 !important;
    font-size: 30px !important;
    line-height: 1.25 !important;
}

.amazon-catalog-page .ac-header p {
    margin: 0 !important;
    color: #666;
    font-size: 14px;
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
        <h1>
            Order #<?php echo esc_html( $order_id ); ?> — Amazon Product Matching
        </h1>

        <p>
            Each WooCommerce order item is shown beside the single top Amazon result found using the WooCommerce product title. <a href="<?php echo esc_url( add_query_arg( 'order-id', $order_id, home_url( '/amazon-cart/' ) ) ); ?>">View Cart</a>
        </p>
    </div>

    <?php if ( is_wp_error( $results ) ) : ?>

        <div class="ac-error">
            <strong><?php echo esc_html( $results->get_error_message() ); ?></strong>
        </div>

    <?php elseif ( empty( $products ) ) : ?>

        <div class="ac-empty">
            No products were found in this WooCommerce order.
        </div>

    <?php else : ?>

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
       AMAZON CART
       ========================================== */
    var amazonCartAjaxUrl = <?php echo wp_json_encode( get_permalink() ); ?>;
    var amazonCartNonce  = <?php echo wp_json_encode( wp_create_nonce( 'amazon_add_to_cart' ) ); ?>;

    document.querySelectorAll('.amazon-catalog-page .ac-add-to-cart').forEach(function (button) {

        button.addEventListener('click', function () {

            var button = this;
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

});
</script>

<?php get_footer(); ?>
