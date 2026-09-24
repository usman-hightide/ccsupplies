<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Email_Logger {

    /**
     * Hooked into wp_mail filter to capture all outgoing emails.
     *
     * @param array $args {
     *     @type string|array $to
     *     @type string       $subject
     *     @type string       $message
     *     @type string|array $headers
     *     @type array        $attachments
     * }
     * @return array
     */
    public static function capture_wp_mail( $args ) {
        // Basic shape check.
        if ( empty( $args['to'] ) || empty( $args['subject'] ) ) {
            return $args;
        }

        $to           = is_array( $args['to'] ) ? implode( ',', $args['to'] )   : (string) $args['to'];
        $headers_raw  = $args['headers'] ?? '';
        $headers      = is_array( $headers_raw ) ? implode( "\n", $headers_raw ) : (string) $headers_raw;

        // Optional context header e.g. "X-WCSS-Context: wcss_new_order".
        $context = '';
        if ( $headers ) {
            if ( preg_match( '/^X-WCSS-Context:\s*(.+)$/mi', $headers, $m ) ) {
                $context = trim( $m[1] );
            }
        }

        self::log_email(
            $to,
            (string) $args['subject'],
            (string) ( $args['message'] ?? '' ),
            $headers,
            $context
        );

        return $args;
    }

    /**
     * Insert a row into the email logs table.
     */
    public static function log_email( string $to, string $subject, string $message = '', string $headers = '', string $context = '' ) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcss_email_logs';

        // In case someone updated without running activation again, fail silently.
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
            return;
        }

        $current_user_id = get_current_user_id() ?: null;
        $request_uri     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        $wpdb->insert(
            $table,
            [
                'mailed_to'   => $to,
                'subject'     => $subject,
                'message'     => $message,
                'headers'     => $headers,
                'context'     => $context,
                'sent_by'     => $current_user_id ?: null,
                'request_uri' => $request_uri,
                'created_at'  => current_time( 'mysql' ),
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
            ]
        );
    }

    /**
     * Fetch logs for the admin page with simple pagination.
     *
     * @param int $page
     * @param int $per_page
     * @return array{items: array, total: int}
     */
    public static function get_logs( int $page = 1, int $per_page = 50 ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'wcss_email_logs';

        $page      = max( 1, $page );
        $per_page  = max( 1, $per_page );
        $offset    = ( $page - 1 ) * $per_page;

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

        return [
            'items' => $items ?: [],
            'total' => $total,
        ];
    }
}

