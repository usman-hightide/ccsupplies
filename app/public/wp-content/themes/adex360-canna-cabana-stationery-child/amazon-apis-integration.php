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

/**
 * Look up Amazon Business products by ASIN list (max 30).
 *
 * Amazon docs: POST /products/2020-08-26/products/getProductsByAsins
 * Required body: productIds, productRegion, locale. CA is a valid region.
 *
 * @param string[] $asins ASIN list.
 * @return array|WP_Error Same family of product payload as keyword search, plus notFoundAsins.
 */
function wp_amazon_business_get_products_by_asins( $asins ) {

    $access_token = wp_amazon_get_access_token();

    if ( is_wp_error( $access_token ) ) {
        return $access_token;
    }

    $product_ids = array();

    foreach ( (array) $asins as $asin ) {
        $asin = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $asin ) );

        if ( $asin !== '' ) {
            $product_ids[] = $asin;
        }
    }

    $product_ids = array_values( array_unique( $product_ids ) );

    if ( empty( $product_ids ) ) {
        return new WP_Error(
            'amazon_asin_empty',
            'No valid Amazon ASIN was provided.'
        );
    }

    if ( count( $product_ids ) > 30 ) {
        $product_ids = array_slice( $product_ids, 0, 30 );
    }

    $user_email = 'ecom@hightideinc.com';
    $endpoint   = 'https://na.business-api.amazon.com/products/2020-08-26/products/getProductsByAsins';

    $response = wp_remote_post(
        $endpoint,
        array(
            'timeout' => 30,
            'headers' => array(
                'x-amz-access-token' => $access_token,
                'x-amz-user-email'   => $user_email,
                'Accept'             => 'application/json',
                'Content-Type'       => 'application/json',
            ),
            'body'    => wp_json_encode(
                array(
                    'productIds'    => $product_ids,
                    'productRegion' => 'CA',
                    'locale'        => 'en_US',
                    'facets'        => array( 'OFFERS', 'IMAGES' ),
                )
            ),
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body        = wp_remote_retrieve_body( $response );
    $data        = json_decode( $body, true );

    if ( $status_code < 200 || $status_code >= 300 ) {
        return new WP_Error(
            'amazon_asin_error',
            'Amazon Business ASIN lookup failed.',
            array(
                'status'   => $status_code,
                'response' => $data,
                'raw'      => $body,
            )
        );
    }

    if ( ! is_array( $data ) ) {
        return new WP_Error(
            'amazon_asin_error',
            'Amazon Business ASIN lookup returned an invalid response.'
        );
    }

    return $data;
}

/**
 * Look up one Amazon product by ASIN.
 *
 * @param string $asin Amazon ASIN.
 * @return array|WP_Error Keyword-search-shaped array with products[0], or error.
 */
function wp_amazon_business_get_product_by_asin( $asin ) {

    $result = wp_amazon_business_get_products_by_asins( array( $asin ) );

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    $asin_norm = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $asin ) );

    if ( ! empty( $result['notFoundAsins'] ) && is_array( $result['notFoundAsins'] ) ) {
        foreach ( $result['notFoundAsins'] as $missing ) {
            if ( strtoupper( (string) $missing ) === $asin_norm ) {
                return new WP_Error(
                    'amazon_asin_not_found',
                    'Amazon did not return a product for ASIN ' . $asin_norm . '.'
                );
            }
        }
    }

    if ( empty( $result['products'] ) || ! is_array( $result['products'] ) ) {
        return new WP_Error(
            'amazon_asin_not_found',
            'Amazon did not return a product for ASIN ' . $asin_norm . '.'
        );
    }

    foreach ( $result['products'] as $product ) {
        if ( ! is_array( $product ) ) {
            continue;
        }

        $product_asin = isset( $product['asin'] ) ? strtoupper( (string) $product['asin'] ) : '';

        if ( $product_asin === $asin_norm ) {
            return array(
                'products' => array( $product ),
            );
        }
    }

    // Some responses may omit asin on the row; take the first product for a single-ASIN call.
    if ( count( $result['products'] ) === 1 && is_array( $result['products'][0] ) ) {
        return array(
            'products' => array( $result['products'][0] ),
        );
    }

    return new WP_Error(
        'amazon_asin_not_found',
        'Amazon did not return a matching product for ASIN ' . $asin_norm . '.'
    );
}

/**
 * ASIN linked to a WooCommerce store product.
 *
 * @param WC_Product|false $product Product.
 * @return string
 */
function wcss_amazon_get_linked_asin_for_product( $product ) {

    if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
        return '';
    }

    $asin = get_post_meta( (int) $product->get_id(), 'amazon_asin', true );
    $asin = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $asin ) );

    return $asin;
}

/**
 * Normalize a title for exact ASIN-sheet matching only.
 *
 * @param string $title Title.
 * @return string
 */
function wcss_amazon_normalize_title_for_asin_match( $title ) {
    $title = strtolower( wp_strip_all_tags( (string) $title ) );
    $title = preg_replace( '/[^a-z0-9]+/i', ' ', $title );
    $title = trim( preg_replace( '/\s+/', ' ', $title ) );

    return $title;
}

/**
 * Load Tomas ASIN catalog JSON shipped with the theme.
 *
 * @return array<int,array{asin:string,description:string}>
 */
function wcss_amazon_load_asin_catalog_rows() {

    $path = get_stylesheet_directory() . '/amazon-asin-catalog.json';

    if ( ! is_readable( $path ) ) {
        return array();
    }

    $decoded = json_decode( (string) file_get_contents( $path ), true );

    if ( ! is_array( $decoded ) ) {
        return array();
    }

    $rows = array();

    foreach ( $decoded as $row ) {
        if ( empty( $row['asin'] ) || empty( $row['description'] ) ) {
            continue;
        }

        $rows[] = array(
            'asin'        => strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $row['asin'] ) ),
            'description' => (string) $row['description'],
        );
    }

    return $rows;
}

/**
 * Extract OEM / cartridge-style codes from a title or SKU for Tomas matching.
 *
 * @param string $text Text.
 * @return string[] Uppercase codes.
 */
function wcss_amazon_extract_oem_codes_for_asin_match( $text ) {
    $text  = (string) $text;
    $codes = array();
    $patterns = array(
        '/\b(CE\d{3}[A-Z])\b/i',
        '/\b(CF\d{3}[A-Z])\b/i',
        '/\b(W\d{4}[A-Z])\b/i',
        '/\b(T\d{2,}[A-Z0-9]*)\b/i',
        '/\b(MK-?\d{3,}[A-Z]*)\b/i',
        '/\b(M-K\d{3,}[A-Z]*)\b/i',
        '/\((\d{4,6})\)/',
    );

    foreach ( $patterns as $pattern ) {
        if ( preg_match_all( $pattern, $text, $m ) ) {
            foreach ( $m[1] as $code ) {
                $codes[] = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $code ) );
            }
        }
    }

    if ( preg_match( '/M-?K231/i', $text ) ) {
        $codes[] = 'MK231';
    }

    return array_values( array_unique( array_filter( $codes ) ) );
}

/**
 * Single ink/toner colour word in text, if exactly one.
 *
 * @param string $text Text.
 * @return string Empty or black|cyan|magenta|yellow.
 */
function wcss_amazon_detect_ink_color_for_asin_match( $text ) {
    $text  = strtolower( (string) $text );
    $found = array();

    foreach ( array( 'black', 'cyan', 'magenta', 'yellow' ) as $color ) {
        if ( preg_match( '/\b' . $color . '\b/', $text ) ) {
            $found[] = $color;
        }
    }

    return count( $found ) === 1 ? $found[0] : '';
}

/**
 * Resolve one Tomas ASIN for a store product: exact title, else OEM/SKU code (+ colour).
 * Does not guess weak fuzzy title matches.
 *
 * @param WC_Product                                                            $product Product.
 * @param array<string,string[]>                                                $by_title Normalized title → ASINs.
 * @param array<int,array{asin:string,description:string,codes:string[],color:string}> $catalog_rows Catalog.
 * @return array{asin?:string,method?:string,error?:string}
 */
function wcss_amazon_resolve_tomas_asin_for_product( $product, $by_title, $catalog_rows ) {
    $name = $product->get_name();
    $sku  = (string) $product->get_sku();
    $key  = wcss_amazon_normalize_title_for_asin_match( $name );

    if ( $key !== '' && ! empty( $by_title[ $key ] ) ) {
        $asins = array_values( array_unique( $by_title[ $key ] ) );
        if ( count( $asins ) === 1 ) {
            return array(
                'asin'   => $asins[0],
                'method' => 'exact_title',
            );
        }
        return array( 'error' => 'ambiguous_title' );
    }

    $woo_codes = array_merge(
        wcss_amazon_extract_oem_codes_for_asin_match( $name ),
        wcss_amazon_extract_oem_codes_for_asin_match( $sku )
    );
    $sku_norm = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $sku ) );
    if ( $sku_norm !== '' ) {
        $woo_codes[] = $sku_norm;
    }
    $woo_codes = array_values( array_unique( array_filter( $woo_codes ) ) );

    $candidates = array();

    foreach ( $catalog_rows as $row ) {
        if ( ! is_array( $row ) || empty( $row['asin'] ) ) {
            continue;
        }
        // Allow callers that pass raw catalog JSON rows (asin + description only).
        if ( ! isset( $row['codes'] ) || ! is_array( $row['codes'] ) ) {
            $row['codes'] = wcss_amazon_extract_oem_codes_for_asin_match( (string) ( $row['description'] ?? '' ) );
        }
        if ( ! isset( $row['color'] ) || ! is_string( $row['color'] ) ) {
            $row['color'] = wcss_amazon_detect_ink_color_for_asin_match( (string) ( $row['description'] ?? '' ) );
        }

        $hit = false;
        foreach ( $woo_codes as $code ) {
            if ( in_array( $code, $row['codes'], true ) ) {
                $hit = true;
                break;
            }
            // Series: Woo "T902XL" vs Tomas "T902XL220".
            foreach ( $row['codes'] as $tc ) {
                if ( $code !== '' && ( str_starts_with( $tc, $code ) || str_starts_with( $code, $tc ) ) ) {
                    $hit = true;
                    break 2;
                }
            }
        }
        if ( ! $hit && preg_match( '/\bEpson\s*502\b/i', $name ) ) {
            $has_t502 = false;
            foreach ( $row['codes'] as $tc ) {
                if ( str_starts_with( $tc, 'T502' ) ) {
                    $has_t502 = true;
                    break;
                }
            }
            if ( false !== stripos( (string) ( $row['description'] ?? '' ), '502' ) && ( false !== stripos( (string) ( $row['description'] ?? '' ), 'EcoTank' ) || $has_t502 ) ) {
                $hit = true;
            }
        }
        if ( $hit ) {
            $candidates[ $row['asin'] ] = $row;
        }
    }

    if ( empty( $candidates ) ) {
        return array( 'error' => 'unmatched' );
    }

    $woo_color = wcss_amazon_detect_ink_color_for_asin_match( $name );
    if ( $woo_color !== '' ) {
        $filtered = array();
        foreach ( $candidates as $asin => $row ) {
            if ( $row['color'] === $woo_color ) {
                $filtered[ $asin ] = $row;
            }
        }
        if ( count( $filtered ) === 1 ) {
            $asin = array_key_first( $filtered );
            return array(
                'asin'   => $asin,
                'method' => 'oem_code_color',
            );
        }
        if ( count( $filtered ) > 1 ) {
            return array( 'error' => 'ambiguous_color' );
        }
        return array( 'error' => 'color_mismatch' );
    }

    if ( count( $candidates ) === 1 ) {
        $asin = array_key_first( $candidates );
        return array(
            'asin'   => $asin,
            'method' => 'oem_code',
        );
    }

    return array( 'error' => 'ambiguous' );
}

/**
 * Link amazon_asin meta from Tomas list: exact title, then OEM/SKU + colour.
 * Does not guess weak fuzzy title matches.
 *
 * @return array{linked:int,skipped_existing:int,unmatched:int,ambiguous:int,details:array<int,string>}
 */
function wcss_amazon_sync_asin_meta_exact_matches() {

    $report = array(
        'linked'           => 0,
        'skipped_existing' => 0,
        'unmatched'        => 0,
        'ambiguous'        => 0,
        'details'          => array(),
    );

    $rows = wcss_amazon_load_asin_catalog_rows();

    if ( empty( $rows ) ) {
        $report['details'][] = 'ASIN catalog JSON missing or empty.';
        return $report;
    }

    $by_title     = array();
    $catalog_rows = array();

    foreach ( $rows as $row ) {
        $key = wcss_amazon_normalize_title_for_asin_match( $row['description'] );

        if ( $key === '' || $row['asin'] === '' ) {
            continue;
        }

        if ( ! isset( $by_title[ $key ] ) ) {
            $by_title[ $key ] = array();
        }

        $by_title[ $key ][] = $row['asin'];

        $catalog_rows[] = array(
            'asin'        => $row['asin'],
            'description' => $row['description'],
            'codes'       => wcss_amazon_extract_oem_codes_for_asin_match( $row['description'] ),
            'color'       => wcss_amazon_detect_ink_color_for_asin_match( $row['description'] ),
        );
    }

    $products = get_posts(
        array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        )
    );

    foreach ( $products as $post ) {
        $product = wc_get_product( $post->ID );

        if ( ! $product || wcss_amazon_catalog_is_placeholder( $product->get_name(), (string) $product->get_sku() ) ) {
            continue;
        }

        $existing = wcss_amazon_get_linked_asin_for_product( $product );

        if ( $existing !== '' ) {
            $report['skipped_existing']++;
            continue;
        }

        $resolved = wcss_amazon_resolve_tomas_asin_for_product( $product, $by_title, $catalog_rows );

        if ( empty( $resolved['asin'] ) ) {
            $err = isset( $resolved['error'] ) ? $resolved['error'] : 'unmatched';
            if ( in_array( $err, array( 'ambiguous', 'ambiguous_title', 'ambiguous_color' ), true ) ) {
                $report['ambiguous']++;
                $report['details'][] = 'Ambiguous for product #' . $product->get_id() . ' "' . $product->get_name() . '"';
            } else {
                $report['unmatched']++;
            }
            continue;
        }

        update_post_meta( $product->get_id(), 'amazon_asin', $resolved['asin'] );
        $report['linked']++;
        $report['details'][] = 'Linked product #' . $product->get_id() . ' "' . $product->get_name() . '" → ' . $resolved['asin'] . ' (' . $resolved['method'] . ')';
    }

    return $report;
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
            'asin' => wcss_amazon_get_linked_asin_for_product( $product ),
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

    $asin = wcss_amazon_get_linked_asin_for_product( $product );

    if ( $asin === '' ) {
        wp_send_json_error(
            array(
                'message' => 'No Amazon ASIN is linked to ' . $product->get_name() . '. Link an ASIN from the High Tide product list first.',
            ),
            404
        );
    }

    // ASIN-based Product Search (getProductsByAsins). Keyword search is no longer used here.
    $amazon_result = wp_amazon_business_get_product_by_asin( $asin );

    if ( is_wp_error( $amazon_result ) ) {
        wp_send_json_error(
            array( 'message' => $amazon_result->get_error_message() ),
            502
        );
    }

    if ( empty( $amazon_result['products'][0] ) || ! is_array( $amazon_result['products'][0] ) ) {
        wp_send_json_error(
            array( 'message' => 'No Amazon product found for ASIN ' . $asin . ' (' . $product->get_name() . ').' ),
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

// Allow other theme modules to load Amazon helpers without rendering this page.
if ( defined( 'WCSS_AMAZON_API_BOOTSTRAP_ONLY' ) && WCSS_AMAZON_API_BOOTSTRAP_ONLY ) {
	return;
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
$checkout_url   = $order_id
    ? add_query_arg( 'order-id', $order_id, home_url( '/amazon-checkout/' ) )
    : home_url( '/amazon-checkout/' );
$amazon_view_url = $order_id
    ? home_url( '/manager/orders/amazon-view/' . $order_id . '/' )
    : '';
$has_amazon_snapshot = false;
$skipped_gt_items    = 0;
$fulfillable_count   = 0;

if ( $order_id ) {

    $order = wc_get_order( $order_id );

    if ( ! $order ) {

        $results = new WP_Error(
            'invalid_order',
            'WooCommerce order could not be found.'
        );

    } else {

        $results = array();
        $has_amazon_snapshot = (bool) $order->get_meta( '_wcss_amazon_order_data' );

        /*
         * Look up Amazon one WooCommerce order item at a time by linked ASIN.
         * Prefer lines the store owner marked as Amazon; skip explicit G&T lines.
         */
        foreach ( $order->get_items() as $order_item_id => $order_item ) {

            $woo_product = $order_item->get_product();

            if ( ! $woo_product ) {
                continue;
            }

            $line_vendor = sanitize_key( (string) $order_item->get_meta( '_cc_vendor', true ) );
            if ( $line_vendor === 'gt' ) {
                $skipped_gt_items++;
                continue;
            }

            $woo_product_title = $woo_product->get_name();
            $quantity           = $order_item->get_quantity();
            $asin               = wcss_amazon_get_linked_asin_for_product( $woo_product );

            if ( $asin === '' ) {
                $products[] = array(
                    '_amazon_error'      => 'No Amazon ASIN is linked to this store product.',
                    '_woo_order_item_id' => $order_item_id,
                    '_woo_product_id'    => $woo_product->get_id(),
                    '_woo_product_title' => $woo_product_title,
                    '_woo_quantity'      => $quantity,
                    '_woo_vendor'        => $line_vendor,
                );

                continue;
            }

            $amazon_result = wp_amazon_business_get_product_by_asin( $asin );

            if ( is_wp_error( $amazon_result ) ) {

                $products[] = array(
                    '_amazon_error'      => $amazon_result->get_error_message(),
                    '_woo_order_item_id' => $order_item_id,
                    '_woo_product_id'    => $woo_product->get_id(),
                    '_woo_product_title' => $woo_product_title,
                    '_woo_quantity'      => $quantity,
                    '_amazon_asin'       => $asin,
                    '_woo_vendor'        => $line_vendor,
                );

                continue;
            }

            if ( empty( $amazon_result['products'] ) ) {

                $products[] = array(
                    '_amazon_error'      => 'No Amazon product found for ASIN ' . $asin . '.',
                    '_woo_order_item_id' => $order_item_id,
                    '_woo_product_id'    => $woo_product->get_id(),
                    '_woo_product_title' => $woo_product_title,
                    '_woo_quantity'      => $quantity,
                    '_amazon_asin'       => $asin,
                    '_woo_vendor'        => $line_vendor,
                );

                continue;
            }

            $amazon_product = $amazon_result['products'][0];

            // Keep WooCommerce context with the Amazon result.
            $amazon_product['_woo_order_item_id'] = $order_item_id;
            $amazon_product['_woo_product_id']    = $woo_product->get_id();
            $amazon_product['_woo_product_title'] = $woo_product_title;
            $amazon_product['_woo_quantity']      = $quantity;
            $amazon_product['_amazon_asin']       = $asin;
            $amazon_product['_woo_vendor']        = $line_vendor;

            $products[] = $amazon_product;
            $fulfillable_count++;
        }
    }
}

$asin_sync_report = null;

if (
    isset( $_GET['wcss_sync_amazon_asins'] )
    && is_user_logged_in()
    && ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) )
) {
    check_admin_referer( 'wcss_sync_amazon_asins' );
    $asin_sync_report = wcss_amazon_sync_asin_meta_exact_matches();
    // Refresh store product list after linking ASINs.
    $store_products = function_exists( 'wc_get_product' ) ? wcss_amazon_catalog_store_products() : array();
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
    align-items: stretch;
    gap: 8px;
    flex: 0 0 auto;
    min-width: 190px;
}

.amazon-catalog-page .ac-header-cart > .ac-view-cart {
    width: 100%;
    justify-content: center;
    box-sizing: border-box;
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

.amazon-catalog-page .ac-view-cart.ac-sync-asins {
    background: #f5f2ea !important;
    color: #161616 !important;
    border: 1px solid #e4e0d6;
    box-shadow: none;
}

.amazon-catalog-page .ac-view-cart.ac-sync-asins:hover {
    background: #ebe6db !important;
    color: #161616 !important;
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

.amazon-catalog-page .ac-fulfill {
    margin: 0 0 32px;
    padding: 24px 26px 20px;
    border: 1px solid #e4e4e1;
    border-radius: 12px;
    background: linear-gradient(180deg, #fbfbfa 0%, #f4f4f1 100%);
    box-shadow: 0 1px 0 rgba(22, 22, 22, 0.04);
}

.amazon-catalog-page .ac-fulfill-eyebrow {
    margin: 0 0 6px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #6b6b66;
}

.amazon-catalog-page .ac-fulfill-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px 20px;
    margin-bottom: 16px;
}

.amazon-catalog-page .ac-fulfill-title-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px 12px;
}

.amazon-catalog-page .ac-fulfill-head h2 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 700;
    letter-spacing: -0.01em;
    color: #161616;
    line-height: 1.25;
}

.amazon-catalog-page .ac-fulfill-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    background: #161616;
    color: #fff;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1.2;
}

.amazon-catalog-page .ac-fulfill-badge.is-approved {
    background: #1f7a3a;
    color: #fff;
}

.amazon-catalog-page .ac-fulfill-meta {
    margin: 8px 0 0;
    font-size: 0.9rem;
    color: #555;
    line-height: 1.4;
}

.amazon-catalog-page .ac-fulfill-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.amazon-catalog-page .ac-fulfill-actions .ac-button,
.amazon-catalog-page .ac-fulfill-actions .ac-view-cart {
    margin: 0;
}

.amazon-catalog-page .ac-fulfill-actions .ac-button-secondary {
    background: #fff !important;
    color: #161616 !important;
    border: 1px solid #cfcfc9 !important;
    box-shadow: none !important;
}

.amazon-catalog-page .ac-fulfill-actions .ac-button-secondary:hover {
    background: #161616 !important;
    color: #fff !important;
    border-color: #161616 !important;
}

.amazon-catalog-page .ac-fulfill-details {
    margin: 0 0 14px;
    padding: 14px 16px;
    border: 1px solid #e8e8e4;
    border-radius: 10px;
    background: #fff;
}

.amazon-catalog-page .ac-fulfill-dl {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px 20px;
    margin: 0;
}

.amazon-catalog-page .ac-fulfill-dl > div {
    min-width: 0;
}

.amazon-catalog-page .ac-fulfill-dl dt {
    margin: 0 0 3px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #7a7a74;
}

.amazon-catalog-page .ac-fulfill-dl dd {
    margin: 0;
    font-size: 0.92rem;
    font-weight: 500;
    color: #161616;
    line-height: 1.4;
    word-break: break-word;
}

.amazon-catalog-page .ac-fulfill-dl dd.ac-fulfill-address {
    white-space: pre-line;
}

.amazon-catalog-page .ac-fulfill-note {
    margin: 0;
    padding: 12px 14px;
    border-left: 3px solid #161616;
    border-radius: 0 8px 8px 0;
    background: rgba(22, 22, 22, 0.04);
    font-size: 0.9rem;
    font-style: italic;
    font-weight: 500;
    color: #2a2a2a;
    line-height: 1.5;
}

.amazon-catalog-page .ac-fulfill-note strong {
    font-style: normal;
    font-weight: 700;
    color: #161616;
}

.amazon-catalog-page .ac-fulfill-status {
    margin: 12px 0 0;
    font-size: 0.92rem;
    color: #161616;
    font-weight: 600;
}

.amazon-catalog-page .ac-fulfill-status.is-error {
    color: #8a1f1f;
}

.amazon-catalog-page .ac-error {
    padding: 18px;
    border: 1px solid #e0b4b4;
    border-radius: 8px;
    background: #fff6f6;
    color: #8a1f1f;
}

.amazon-catalog-page .ac-asin-sync-report {
    margin-bottom: 32px;
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

.amazon-catalog-page .ac-product > div {
    min-width: 0;
    max-width: 100%;
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
    display: flex !important;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-start;
    gap: 10px;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    overflow: hidden;
}

/* Row 1: Quick View + View on Amazon (auto width, no stretch) */
.amazon-catalog-page .ac-amazon-actions > .ac-button {
    flex: 0 1 auto !important;
    width: auto !important;
    max-width: 100% !important;
    min-width: 0 !important;
}

/* Row 2: Add to Amazon Cart */
.amazon-catalog-page .ac-amazon-actions > .ac-add-to-cart {
    flex: 1 0 100% !important;
    width: auto !important;
    max-width: max-content !important;
}

.amazon-catalog-page .ac-amazon-actions > .ac-cart-message {
    flex: 1 0 100%;
    width: 100%;
    margin-top: 0;
}

.amazon-catalog-page .ac-button {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    box-sizing: border-box !important;
    width: auto !important;
    height: 42px !important;
    min-height: 42px !important;
    max-height: 42px !important;
    padding: 0 16px !important;
    border: 0 !important;
    border-radius: 6px !important;
    background: #ff9900 !important;
    color: #111 !important;
    text-decoration: none !important;
    cursor: pointer;
    font-size: 14px !important;
    font-weight: 700 !important;
    line-height: 1 !important;
    white-space: nowrap !important;
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
                Search the published store catalog, then look up Amazon by the linked ASIN.
                Keyword search is no longer used on this page.
                <?php if ( $order ) : ?>
                    Order items below also use linked ASINs.
                <?php endif; ?>
            </p>
        </div>

        <div class="ac-header-cart">
            <a class="ac-view-cart" id="ac-view-cart" href="<?php echo esc_url( $cart_url ); ?>">
                <span>View Cart</span>
                <span class="ac-view-cart-count" id="ac-view-cart-count" hidden>0</span>
            </a>
            <p class="ac-cart-note" id="ac-cart-note" hidden></p>
            <?php if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) : ?>
                <?php
                $sync_url = wp_nonce_url(
                    add_query_arg( 'wcss_sync_amazon_asins', '1' ),
                    'wcss_sync_amazon_asins'
                );
                ?>
                <a class="ac-view-cart ac-sync-asins" href="<?php echo esc_url( $sync_url ); ?>">
                    <span>Sync ASIN matches</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( is_array( $asin_sync_report ) ) : ?>
        <div class="ac-error ac-asin-sync-report" style="background:#f3f3f3; border-color:#161616; color:#161616;">
            <strong>ASIN sync (exact title or OEM/SKU + colour)</strong><br>
            Linked: <?php echo esc_html( (string) $asin_sync_report['linked'] ); ?> ·
            Already linked: <?php echo esc_html( (string) $asin_sync_report['skipped_existing'] ); ?> ·
            Unmatched: <?php echo esc_html( (string) $asin_sync_report['unmatched'] ); ?> ·
            Ambiguous: <?php echo esc_html( (string) $asin_sync_report['ambiguous'] ); ?>
            <?php if ( ! empty( $asin_sync_report['details'] ) ) : ?>
                <ul style="margin:12px 0 0; padding-left:18px;">
                    <?php foreach ( array_slice( $asin_sync_report['details'], 0, 40 ) as $detail_line ) : ?>
                        <li><?php echo esc_html( $detail_line ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ( $order ) : ?>
        <?php
        $ship_name = trim( $order->get_formatted_shipping_full_name() );
        if ( $ship_name === '' ) {
            $ship_name = trim( $order->get_formatted_billing_full_name() );
        }
        $ship_company = (string) $order->get_shipping_company();
        if ( $ship_company === '' ) {
            $ship_company = (string) $order->get_billing_company();
        }
        $ship_address = $order->get_formatted_shipping_address();
        if ( $ship_address === '' ) {
            $ship_address = $order->get_formatted_billing_address();
        }
        $order_status = $order->get_status();
        $status_label = function_exists( 'wc_get_order_status_name' )
            ? wc_get_order_status_name( $order_status )
            : $order_status;
        $badge_class  = 'ac-fulfill-badge';
        if ( 'approved' === $order_status || 'wc-approved' === $order_status ) {
            $badge_class .= ' is-approved';
        }
        ?>
        <section class="ac-fulfill" id="ac-fulfill" data-fulfillable="<?php echo esc_attr( (string) $fulfillable_count ); ?>">
            <p class="ac-fulfill-eyebrow">Approved store order</p>

            <div class="ac-fulfill-head">
                <div>
                    <div class="ac-fulfill-title-row">
                        <h2>Order #<?php echo esc_html( (string) $order->get_order_number() ); ?></h2>
                        <span class="<?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
                    </div>
                    <p class="ac-fulfill-meta">
                        <?php echo esc_html( (string) $fulfillable_count ); ?> Amazon item<?php echo $fulfillable_count === 1 ? '' : 's'; ?> ready to fulfill
                        <?php if ( $skipped_gt_items > 0 ) : ?>
                            · <?php echo esc_html( (string) $skipped_gt_items ); ?> G&amp;T item<?php echo $skipped_gt_items === 1 ? '' : 's'; ?> skipped here
                        <?php endif; ?>
                    </p>
                </div>
                <div class="ac-fulfill-actions">
                    <button
                        type="button"
                        class="ac-button"
                        id="ac-fulfill-store-order"
                        <?php disabled( $fulfillable_count < 1 ); ?>
                    >
                        Use store details → Checkout
                    </button>
                    <?php if ( $has_amazon_snapshot && $amazon_view_url ) : ?>
                        <a class="ac-view-cart ac-button-secondary" href="<?php echo esc_url( $amazon_view_url ); ?>">
                            Amazon order view
                        </a>
                    <?php endif; ?>
                    <a class="ac-view-cart ac-button-secondary" href="<?php echo esc_url( $cart_url ); ?>">
                        Open Amazon cart
                    </a>
                </div>
            </div>

            <?php
            $has_fulfill_details = (
                $ship_name !== ''
                || $ship_company !== ''
                || $ship_address !== ''
                || (bool) $order->get_billing_email()
                || (bool) $order->get_billing_phone()
            );
            ?>
            <?php if ( $has_fulfill_details ) : ?>
                <div class="ac-fulfill-details">
                    <dl class="ac-fulfill-dl">
                        <?php if ( $ship_name !== '' ) : ?>
                            <div>
                                <dt>Ship to</dt>
                                <dd><?php echo esc_html( $ship_name ); ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if ( $ship_company !== '' ) : ?>
                            <div>
                                <dt>Company</dt>
                                <dd><?php echo esc_html( $ship_company ); ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if ( $ship_address !== '' ) : ?>
                            <div>
                                <dt>Address</dt>
                                <dd class="ac-fulfill-address"><?php echo esc_html( wp_strip_all_tags( $ship_address ) ); ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if ( $order->get_billing_email() ) : ?>
                            <div>
                                <dt>Email</dt>
                                <dd><?php echo esc_html( $order->get_billing_email() ); ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if ( $order->get_billing_phone() ) : ?>
                            <div>
                                <dt>Phone</dt>
                                <dd><?php echo esc_html( $order->get_billing_phone() ); ?></dd>
                            </div>
                        <?php endif; ?>
                    </dl>
                </div>
            <?php endif; ?>

            <p class="ac-fulfill-note">
                <strong>What happens next:</strong>
                One click adds the store’s Amazon items to cart at the ordered quantities, then opens checkout with this store’s shipping details already filled in — no need to retype them.
            </p>
            <p class="ac-fulfill-status" id="ac-fulfill-status" hidden></p>
        </section>
    <?php endif; ?>

    <section class="ac-store-search">
        <div class="ac-store-head">
            <h2>Store catalog</h2>
            <span class="ac-store-total"><?php echo esc_html( count( $store_products ) ); ?> published products</span>
        </div>
        <p>Type a name or SKU, then search Amazon by the product’s linked ASIN.</p>

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
                $store_search = strtolower( $store_product['name'] . ' ' . $store_product['sku'] . ' ' . ( $store_product['asin'] ?? '' ) );
                $has_asin     = ! empty( $store_product['asin'] );
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
                        <?php if ( $has_asin ) : ?>
                            <span class="ac-store-sku">ASIN <?php echo esc_html( $store_product['asin'] ); ?></span>
                        <?php else : ?>
                            <span class="ac-store-sku">ASIN not linked</span>
                        <?php endif; ?>
                    </div>

                    <button
                        type="button"
                        class="ac-button ac-search-amazon"
                        data-product-id="<?php echo esc_attr( $store_product['id'] ); ?>"
                        <?php disabled( ! $has_asin ); ?>
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
    var catalogOrderId = <?php echo wp_json_encode( $order_id ? (string) $order_id : '' ); ?>;
    var amazonCheckoutUrl = <?php echo wp_json_encode( $checkout_url ); ?>;
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
        // Only allow Add when this catalog page is opened for a store order.
        // Without order-id, checkout blocks placement as "not linked to a store order".
        var actions = catalogOrderId
            ? (
                '<button type="button" class="ac-button ac-add-to-cart"'
                + ' data-asin="' + escapeHtml(result.asin) + '"'
                + ' data-offer-id="' + escapeHtml(result.offer_id) + '"'
                + ' data-buying-option-id="' + escapeHtml(result.buying_option_id) + '"'
                + ' data-title="' + escapeHtml(result.title) + '"'
                + ' data-quantity="1"'
                + ' data-woo_product_id="' + escapeHtml(result.woo_product_id) + '">'
                + 'Add to Amazon Cart</button>'
                + '<div class="ac-cart-message" aria-live="polite"></div>'
            )
            : '<p class="ac-cart-note">Open this catalog from a store order to add items to the Amazon cart.</p>';

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
            + actions
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

    function addAmazonProductFromButton(button, options) {
        options = options || {};
        var silent = !!options.silent;
        var container = button.closest('.ac-amazon-actions, .ac-modal-actions');
        var message = container ? container.querySelector('.ac-cart-message') : null;

        var asin = button.getAttribute('data-asin') || '';
        var offerId = button.getAttribute('data-offer-id') || '';
        var buyingOptionId = button.getAttribute('data-buying-option-id') || offerId;
        var title = button.getAttribute('data-title') || '';
        var quantity = parseInt(button.getAttribute('data-quantity') || '1', 10);
        var woo_product_id = button.getAttribute('data-woo_product_id');

        if (!asin || !buyingOptionId) {
            var missing = 'Amazon buying option information is missing for this product.';
            if (!silent && message) {
                message.textContent = missing;
            }
            return Promise.reject(new Error(missing));
        }

        if (!silent) {
            button.disabled = true;
            if (message) {
                message.textContent = 'Adding to Amazon cart...';
            }
        }

        var formData = new FormData();
        formData.append('amazon_catalog_action', 'amazon_add_to_cart');
        formData.append('nonce', amazonCartNonce);
        formData.append('woo_product_id', woo_product_id);
        formData.append('asin', asin);
        formData.append('offer_id', offerId);
        formData.append('buying_option_id', buyingOptionId);
        formData.append('title', title);
        formData.append('quantity', quantity);

        return fetch(amazonCartAjaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function (response) {
            return response.json();
        })
        .then(function (response) {
            if (response.success) {
                if (!silent && message) {
                    message.textContent = response.data.message || 'Added to Amazon cart.';
                }
                if (!silent) {
                    button.textContent = 'Added to Amazon Cart';
                }
                showAmazonCartCount(response.data && response.data.item_count);
                return response;
            }

            var errorMessage = 'Unable to add item to Amazon cart.';
            if (response.data && response.data.message) {
                errorMessage = response.data.message;
            }
            if (!silent && message) {
                message.textContent = errorMessage;
            }
            throw new Error(errorMessage);
        })
        .catch(function (error) {
            if (!silent && message && (!error || !error.message || error.message.indexOf('Unable') === -1)) {
                message.textContent = 'Unable to connect to the Amazon Cart API.';
            }
            throw error;
        })
        .finally(function () {
            if (!silent) {
                button.disabled = false;
            }
        });
    }

    document.querySelector('.amazon-catalog-page').addEventListener('click', function (event) {
        var button = event.target.closest('.ac-add-to-cart');

        if (!button || button.disabled) {
            return;
        }

        addAmazonProductFromButton(button).catch(function () {
            // Message already shown on the product row.
        });
    });

    var fulfillButton = document.getElementById('ac-fulfill-store-order');
    var fulfillStatus = document.getElementById('ac-fulfill-status');

    function setFulfillStatus(text, isError) {
        if (!fulfillStatus) {
            return;
        }
        fulfillStatus.hidden = !text;
        fulfillStatus.textContent = text || '';
        fulfillStatus.classList.toggle('is-error', !!isError);
    }

    if (fulfillButton) {
        fulfillButton.addEventListener('click', function () {
            var buttons = Array.prototype.slice.call(
                document.querySelectorAll('.amazon-catalog-page .ac-list .ac-add-to-cart')
            ).filter(function (btn) {
                return !btn.disabled && (btn.getAttribute('data-asin') || '') && (btn.getAttribute('data-buying-option-id') || btn.getAttribute('data-offer-id') || '');
            });

            if (!buttons.length) {
                setFulfillStatus('No Amazon-ready items found on this order to add.', true);
                return;
            }

            fulfillButton.disabled = true;
            setFulfillStatus('Adding ' + buttons.length + ' store item' + (buttons.length === 1 ? '' : 's') + ' to the Amazon cart…', false);

            var chain = Promise.resolve();
            var added = 0;
            var failed = [];

            buttons.forEach(function (btn, index) {
                chain = chain.then(function () {
                    setFulfillStatus(
                        'Adding item ' + (index + 1) + ' of ' + buttons.length + '…',
                        false
                    );
                    return addAmazonProductFromButton(btn, { silent: true })
                        .then(function () {
                            added += 1;
                            btn.textContent = 'Added to Amazon Cart';
                            btn.disabled = true;
                        })
                        .catch(function (error) {
                            failed.push((btn.getAttribute('data-title') || 'Item') + ': ' + (error && error.message ? error.message : 'failed'));
                        });
                });
            });

            chain.finally(function () {
                if (added < 1) {
                    fulfillButton.disabled = false;
                    setFulfillStatus(
                        failed.length
                            ? ('Could not add items. ' + failed[0])
                            : 'Could not add any store items to the Amazon cart.',
                        true
                    );
                    return;
                }

                if (failed.length) {
                    setFulfillStatus(
                        'Added ' + added + ' item' + (added === 1 ? '' : 's') + '. ' + failed.length + ' failed. Opening checkout…',
                        true
                    );
                } else {
                    setFulfillStatus(
                        'Added ' + added + ' item' + (added === 1 ? '' : 's') + '. Opening checkout with store details…',
                        false
                    );
                }

                window.setTimeout(function () {
                    window.location.href = amazonCheckoutUrl;
                }, failed.length ? 900 : 400);
            });
        });
    }

});
</script>

<?php get_footer(); ?>
