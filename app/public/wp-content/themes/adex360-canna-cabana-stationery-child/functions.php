<?php
require_once get_stylesheet_directory() . '/amazon-order-storage.php';
require_once get_stylesheet_directory() . '/amazon-catalogItems.php';


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

// Grand and toy testing
add_action('template_redirect', function () {

    $file_name = isset($_GET['gtoy_test'])
        ? sanitize_file_name($_GET['gtoy_test'])
        : '';

    if (!$file_name) {
        return;
    }

    // Optional: only allow xml files
    if (!str_ends_with($file_name, '.xml')) {
        $file_name .= '.xml';
    }

    $upload_dir = wp_upload_dir();

    $file = $upload_dir['basedir'] . '/grand-and-toy/' . $file_name;

    if (!file_exists($file)) {
        wp_die('File not found: ' . esc_html($file_name));
    }

    $xml = file_get_contents($file);
    $response = wp_remote_post(
        'https://www.grandandtoy.com/B2BIntegration/SubmitPO/SubmitPO_wholesale.aspx',
        [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'text/xml',
                'Accept'       => 'text/xml',
            ],
            'body' => $xml,
        ]
    );

    header('Content-Type: text/plain');

    if (is_wp_error($response)) {
        echo "ERROR:\n" . $response->get_error_message();
    } else {
        echo "HTTP CODE: " . wp_remote_retrieve_response_code($response) . "\n\n";
        echo wp_remote_retrieve_body($response);
    }

    exit;
});
