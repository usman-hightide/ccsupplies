<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Email_Log_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
    }

    public function register_menu() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        add_submenu_page(
            'woocommerce',
            __( 'Email Logs', 'wcss' ),
            __( 'Email Logs', 'wcss' ),
            'manage_woocommerce',
            'wcss-email-logs',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'wcss' ) );
        }

        $page      = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $per_page  = 50;
        $result    = WCSS_Email_Logger::get_logs( $page, $per_page );
        $items     = $result['items'];
        $total     = $result['total'];
        $total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

        $base_url = remove_query_arg( [ 'paged' ] );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Email Logs', 'wcss' ); ?></h1>

            <p><?php esc_html_e( 'This table lists emails sent from the site (captured via wp_mail).', 'wcss' ); ?></p>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'wcss' ); ?></th>
                        <th><?php esc_html_e( 'To', 'wcss' ); ?></th>
                        <th><?php esc_html_e( 'Subject', 'wcss' ); ?></th>
                        <th><?php esc_html_e( 'Context', 'wcss' ); ?></th>
                        <th><?php esc_html_e( 'Sent By', 'wcss' ); ?></th>
                        <th><?php esc_html_e( 'View', 'wcss' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $items ) ) : ?>
                    <tr>
                        <td colspan="6"><?php esc_html_e( 'No email logs found.', 'wcss' ); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $items as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row['created_at'] ); ?></td>
                            <td><?php echo esc_html( $row['mailed_to'] ); ?></td>
                            <td><?php echo esc_html( mb_strimwidth( $row['subject'], 0, 80, '…' ) ); ?></td>
                            <td><?php echo esc_html( $row['context'] ); ?></td>
                            <td>
                                <?php
                                if ( ! empty( $row['sent_by'] ) ) {
                                    $user = get_user_by( 'id', (int) $row['sent_by'] );
                                    echo $user ? esc_html( $user->user_login ) : esc_html( $row['sent_by'] );
                                } else {
                                    esc_html_e( 'System', 'wcss' );
                                }
                                ?>
                            </td>
                            <td>
                                <button type="button" class="button view-email-log" data-log='<?php echo esc_attr( wp_json_encode( $row ) ); ?>'>
                                    <?php esc_html_e( 'View', 'wcss' ); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(
                            [
                                'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                                'format'    => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total'     => $total_pages,
                                'current'   => $page,
                            ]
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div id="wcss-email-log-modal" style="display:none;">
            <div class="wcss-email-log-modal-inner" style="background:#fff;max-width:900px;margin:40px auto;padding:20px;border:1px solid #ccd0d4;">
                <h2><?php esc_html_e( 'Email Details', 'wcss' ); ?></h2>
                <pre id="wcss-email-log-details" style="white-space:pre-wrap;max-height:400px;overflow:auto;"></pre>
                <p><button type="button" class="button" id="wcss-email-log-close"><?php esc_html_e( 'Close', 'wcss' ); ?></button></p>
            </div>
        </div>

        <script>
        (function($){
            $(document).on('click', '.view-email-log', function(e){
                e.preventDefault();
                var data = $(this).data('log');
                var text = '';
                if (data) {
                    text += "Date: " + (data.created_at || '') + "\n";
                    text += "To: " + (data.mailed_to || '') + "\n";
                    text += "Subject: " + (data.subject || '') + "\n";
                    text += "Context: " + (data.context || '') + "\n";
                    text += "Request URI: " + (data.request_uri || '') + "\n";
                    text += "Headers:\n" + (data.headers || '') + "\n\n";
                    text += "Message:\n" + (data.message || '');
                }
                $('#wcss-email-log-details').text(text);
                $('#wcss-email-log-modal').show();
            });

            $(document).on('click', '#wcss-email-log-close', function(){
                $('#wcss-email-log-modal').hide();
            });
        })(jQuery);
        </script>
        <?php
    }
}

