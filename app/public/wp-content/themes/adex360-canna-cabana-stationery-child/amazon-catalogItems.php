<?php
// ======================================
// AMAZON SANDBOX API
// ======================================

function amazon_get_access_token() {

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
    $body        = json_decode(
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

/**
 * Search Amazon Business Catalog Items.
 *
 * @param array $args
 *
 * @return array|WP_Error
 */

function amazon_business_search_products( $keywords = 'HP ink cartridge', $page_size = 10 ) {

    $access_token = amazon_get_access_token();
    $user_email   = 'ecom@hightideinc.com';

    $endpoint = 'https://na.business-api.amazon.com/products/2020-08-26/products';

    $query_args = array(
        'locale'        => 'en_US',
        'productRegion' => 'US',
        'facets'        => 'OFFERS,IMAGES',
        'pageSize'      => $page_size,
        'keywords'      => $keywords,
        'category'      => 'OFFICE_PRODUCTS',
    );

    $url = add_query_arg( $query_args, $endpoint );

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

        return array(
            'success' => false,
            'error'   => $response->get_error_message(),
        );
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body        = wp_remote_retrieve_body( $response );

    $data = json_decode( $body, true );

    if ( $status_code < 200 || $status_code >= 300 ) {

        return array(
            'success'   => false,
            'http_code' => $status_code,
            'response'  => $data,
            'raw'       => $body,
        );
    }

    return array(
        'success'   => true,
        'http_code' => $status_code,
        'data'      => $data,
    );
}

add_action("template_redirect", "func_ccsupplies_amazon_sandbox_integration");
function func_ccsupplies_amazon_sandbox_integration()
{
    if (
        isset( $_GET['amazon_sandbox_testing'] ) &&
        'yup' === $_GET['amazon_sandbox_testing']
    ) {
        $token = amazon_get_access_token();

        if ( is_wp_error( $token ) ) {

            wp_die(
                '<pre>' . print_r( $token, true ) . '</pre>'
            );
        }

        wp_die(
            '<pre>Amazon token: ' . esc_html( $token ) . '</pre>'
        );
    }

    if (
        isset( $_GET['amazon_sandbox_testing'] ) &&
        'search' === $_GET['amazon_sandbox_testing']
    ) {

        $results = amazon_business_search_products();

        if ( is_wp_error( $results ) ) {

            wp_die(
                '<pre>' .
                esc_html( print_r( $results, true ) ) .
                '</pre>'
            );
        }

        wp_die(
            '<pre>' .
            esc_html( print_r( $results, true ) ) .
            '</pre>'
        );
    }
}