<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WCSS_Notifications {

    public function __construct() {
        // New order placed
        add_action( 'woocommerce_new_order', [ $this, 'handle_new_order' ], 10, 2 );

        // Order status changed (pending → approved / rejected / etc)
        add_action(
            'woocommerce_order_status_changed',
            [ $this, 'handle_status_change' ],
            10,
            4
        );
        // add_action( 'woocommerce_order_status_processing', [ $this, 'notify_vendors_on_status_change' ], 10, 2 );
        add_action( 'woocommerce_order_status_completed',  [ $this, 'notify_vendors_on_status_change' ], 10, 2 );
        // If you also want it on your custom "approved" status:
        add_action( 'woocommerce_order_status_approved',   [ $this, 'notify_vendors_on_status_change' ], 10, 2 );

    }

    /**
     * Handle new order: notify store employee, supply managers, and vendors.
     * Each recipient gets a separate email (no shared "To" list).
     */
    public function handle_new_order( $order_id, $order = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $order_number = $order->get_order_number();
        $status_label = wc_get_order_status_name( $order->get_status() );

        // 1) Store employee (based on _wcss_store_id meta)
        $store_employee_email = $this->get_store_employee_email_for_order( $order );
        if ( $store_employee_email ) {
            $subject = sprintf(
                '[Internal Supply] New order #%s for your store (%s)',
                $order_number,
                $status_label
            );

            $message = $this->build_new_order_email_body( $order, 'store_employee' );
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'X-WCSS-Context: wcss_new_order_store_employee',
            ];

            if ( function_exists( 'wc_mail' ) ) {
                wc_mail( $store_employee_email, $subject, $message, $headers );
            } else {
                wp_mail( $store_employee_email, $subject, $message, $headers );
            }
        }

        // 2) Supply managers (shop managers)
        $manager_emails = $this->get_supply_manager_emails();
        if ( ! empty( $manager_emails ) ) {
            $subject = sprintf(
                '[Internal Supply] New order #%s placed (%s)',
                $order_number,
                $status_label
            );

            $message = $this->build_new_order_email_body( $order, 'shop_manager' );
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'X-WCSS-Context: wcss_new_order_shop_manager',
            ];

            foreach ( array_unique( array_filter( $manager_emails ) ) as $email ) {
                if ( function_exists( 'wc_mail' ) ) {
                    wc_mail( $email, $subject, $message, $headers );
                } else {
                    wp_mail( $email, $subject, $message, $headers );
                }
            }
        }

        // 3) Vendors on this order (generic new-order email, one per vendor address)
        $vendor_emails = $this->get_vendor_emails_for_order( $order );
        if ( ! empty( $vendor_emails ) ) {
            $subject = sprintf(
                '[Internal Supply] New order #%s (%s)',
                $order_number,
                $status_label
            );

            $message = $this->build_new_order_email_body( $order, 'vendor' );
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'X-WCSS-Context: wcss_new_order_vendor',
            ];

            foreach ( array_unique( array_filter( $vendor_emails ) ) as $email ) {
                if ( function_exists( 'wc_mail' ) ) {
                    wc_mail( $email, $subject, $message, $headers );
                } else {
                    wp_mail( $email, $subject, $message, $headers );
                }
            }
        }
    }

    /**
     * Handle order status change: notify about approval / rejection etc.
     * Mirrors the new-order behaviour: separate emails per audience with a shared design.
     */
    public function handle_status_change( $order_id, $old_status, $new_status, $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Only notify for certain transitions if you want (e.g. approved / rejected)
        $interesting = [ 'approved', 'rejected', 'completed', 'cancelled' ];
        if ( ! in_array( $new_status, $interesting, true ) ) {
            return;
        }

        $order_number = $order->get_order_number();
        $new_label    = wc_get_order_status_name( $new_status );

        // 1) Store employee
        $store_employee_email = $this->get_store_employee_email_for_order( $order );
        if ( $store_employee_email ) {
            $subject = sprintf(
                '[Internal Supply] Order #%s status updated for your store: %s',
                $order_number,
                $new_label
            );

            $message = $this->build_status_change_email_body( $order, $old_status, $new_status, 'store_employee' );
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'X-WCSS-Context: wcss_status_store_employee',
            ];

            if ( function_exists( 'wc_mail' ) ) {
                wc_mail( $store_employee_email, $subject, $message, $headers );
            } else {
                wp_mail( $store_employee_email, $subject, $message, $headers );
            }
        }

        // 2) Supply managers
        $manager_emails = $this->get_supply_manager_emails();
        if ( ! empty( $manager_emails ) ) {
            $subject = sprintf(
                '[Internal Supply] Order #%s status updated: %s',
                $order_number,
                $new_label
            );

            $message = $this->build_status_change_email_body( $order, $old_status, $new_status, 'shop_manager' );
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'X-WCSS-Context: wcss_status_shop_manager',
            ];

            foreach ( array_unique( array_filter( $manager_emails ) ) as $email ) {
                if ( function_exists( 'wc_mail' ) ) {
                    wc_mail( $email, $subject, $message, $headers );
                } else {
                    wp_mail( $email, $subject, $message, $headers );
                }
            }
        }

        // 3) Vendors (status update summary for all items, one per address)
        $vendor_emails = $this->get_vendor_emails_for_order( $order );
        if ( ! empty( $vendor_emails ) ) {
            $subject = sprintf(
                '[Internal Supply] Order #%s status updated: %s',
                $order_number,
                $new_label
            );

            $message = $this->build_status_change_email_body( $order, $old_status, $new_status, 'vendor' );
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'X-WCSS-Context: wcss_status_vendor',
            ];

            foreach ( array_unique( array_filter( $vendor_emails ) ) as $email ) {
                if ( function_exists( 'wc_mail' ) ) {
                    wc_mail( $email, $subject, $message, $headers );
                } else {
                    wp_mail( $email, $subject, $message, $headers );
                }
            }
        }
    }

    /* ===================== Helpers ===================== */

    /**
     * Find the store employee email for this order.
     * Uses _wcss_store_id on the order + user meta _wcss_store_id.
     */
    private function get_store_employee_email_for_order( WC_Order $order ): string {
        $store_id = (int) $order->get_meta( '_wcss_store_id' );
        if ( ! $store_id ) return '';

        $users = get_users( [
            'meta_key'   => '_wcss_store_id',
            'meta_value' => $store_id,
            'number'     => 1,
            'fields'     => [ 'user_email' ],
        ] );

        if ( empty( $users ) || empty( $users[0]->user_email ) ) {
            return '';
        }
        return $users[0]->user_email;
    }

    /**
     * Supply manager emails.
     * You can later wire this to plugin settings; for now:
     * - try option 'wcss_supply_manager_emails'
     * - fallback to site admin_email
     */
    private function get_supply_manager_emails_old(): array {
        $emails = [];

        $opt = get_option( 'wcss_supply_manager_emails', '' );
        if ( is_string( $opt ) && $opt !== '' ) {
            $parts = preg_split( '/[,\s]+/', $opt );
            foreach ( $parts as $e ) {
                $e = trim( $e );
                if ( is_email( $e ) ) $emails[] = $e;
            }
        }

        if ( empty( $emails ) ) {
            $admin = get_option( 'admin_email' );
            if ( is_email( $admin ) ) {
                $emails[] = $admin;
            }
        }

        return array_unique( array_filter( $emails ) );
    }


        /**
     * Supply manager emails.
     * Gets emails of all users with the "shop_manager" role.
     */
    
    private function get_supply_manager_emails(): array {
        $emails = [];

        $users = get_users( [
            'role'   => 'shop_manager',
            'fields' => [ 'user_email' ],
        ] );

        foreach ( $users as $user ) {
            if ( ! empty( $user->user_email ) && is_email( $user->user_email ) ) {
                $emails[] = $user->user_email;
            }
        }

        return array_unique( array_filter( $emails ) );
    }


    /**
     * Collect vendor emails for all vendors who appear in line items.
     * Assumes:
     *  - taxonomy: wcss_vendor
     *  - term meta: wcss_vendor_email
     */
    private function get_vendor_emails_for_order( WC_Order $order ): array {
        $emails = [];
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $pid = $item->get_product_id() ?: $item->get_variation_id();
            if ( ! $pid ) continue;

            $terms = wp_get_post_terms( $pid, 'wcss_vendor' );
            if ( is_wp_error( $terms ) || empty( $terms ) ) continue;

            foreach ( $terms as $term ) {
                $email = get_term_meta( $term->term_id, 'wcss_vendor_email', true );
                if ( is_email( $email ) ) {
                    $emails[] = $email;
                }
            }
        }
        return array_unique( array_filter( $emails ) );
    }

    /**
     * Simple HTML email for NEW order.
     *
     * @param WC_Order $order
     * @param string   $audience store_employee|shop_manager|vendor|generic
     */
    private function build_new_order_email_body( WC_Order $order, string $audience = 'generic' ): string {
        $store_id   = (int) $order->get_meta( '_wcss_store_id' );
        $store_name = $store_id ? get_the_title( $store_id ) : '';
        $store_code = $store_id ? get_post_meta( $store_id, WCSS_Store_CPT::META_CODE, true ) : '';
        $store_city = $store_id ? get_post_meta( $store_id, WCSS_Store_CPT::META_CITY, true ) : '';

        $heading = sprintf(
            'New order #%s',
            $order->get_order_number()
        );

        if ( 'store_employee' === $audience ) {
            $intro = __( 'A new order has been placed for your store in the Internal Supply portal.', 'wcss' );
        } elseif ( 'shop_manager' === $audience ) {
            $intro = __( 'A new order has been placed in the Internal Supply portal.', 'wcss' );
        } elseif ( 'vendor' === $audience ) {
            $intro = __( 'A new order has been placed that includes items from your catalog.', 'wcss' );
        } else {
            $intro = __( 'A new order has been placed.', 'wcss' );
        }

        ob_start();
        ?>
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:#f5f5f7; padding:20px 0;">
            <tr>
                <td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e2e2e7;">
                        <tr>
                            <td style="background:#111827;color:#ffffff;padding:16px 24px;font-size:18px;font-weight:600;">
                                <?php echo esc_html( $heading ); ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:16px 24px;font-size:14px;line-height:1.5;color:#111827;">
                                <p style="margin:0 0 8px 0;"><?php echo esc_html( $intro ); ?></p>
                                <p style="margin:0;">
                                    <strong><?php esc_html_e( 'Date:', 'wcss' ); ?></strong>
                                    <?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '' ); ?><br>
                                    <strong><?php esc_html_e( 'Status:', 'wcss' ); ?></strong>
                                    <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?><br>
                                    <strong><?php esc_html_e( 'Total:', 'wcss' ); ?></strong>
                                    <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?><br>
                                    <strong><?php esc_html_e( 'Customer:', 'wcss' ); ?></strong>
                                    <?php echo esc_html( $order->get_billing_email() ); ?>
                                </p>
                            </td>
                        </tr>

                        <?php if ( $store_id ) : ?>
                        <tr>
                            <td style="padding:0 24px 16px 24px;">
                                <h3 style="font-size:14px;margin:0 0 8px 0;color:#111827;"><?php esc_html_e( 'Store', 'wcss' ); ?></h3>
                                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:13px;color:#374151;">
                                    <tr>
                                        <td width="25%" style="padding:2px 0;"><strong><?php esc_html_e( 'Name:', 'wcss' ); ?></strong></td>
                                        <td style="padding:2px 0;"><?php echo esc_html( $store_name ?: '#' . $store_id ); ?></td>
                                    </tr>
                                    <tr>
                                        <td width="25%" style="padding:2px 0;"><strong><?php esc_html_e( 'Code:', 'wcss' ); ?></strong></td>
                                        <td style="padding:2px 0;"><?php echo esc_html( $store_code ); ?></td>
                                    </tr>
                                    <tr>
                                        <td width="25%" style="padding:2px 0;"><strong><?php esc_html_e( 'City:', 'wcss' ); ?></strong></td>
                                        <td style="padding:2px 0;"><?php echo esc_html( $store_city ); ?></td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <tr>
                            <td style="padding:0 24px 24px 24px;">
                                <h3 style="font-size:14px;margin:0 0 8px 0;color:#111827;"><?php esc_html_e( 'Items', 'wcss' ); ?></h3>
                                <table width="100%" cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;">
                                    <thead>
                                        <tr style="background:#f3f4f6;">
                                            <th align="left" style="border:1px solid #e5e7eb;"><?php esc_html_e( 'Product', 'wcss' ); ?></th>
                                            <th align="left" style="border:1px solid #e5e7eb;"><?php esc_html_e( 'Qty', 'wcss' ); ?></th>
                                            <th align="left" style="border:1px solid #e5e7eb;"><?php esc_html_e( 'Total', 'wcss' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ( $order->get_items() as $item ) : ?>
                                        <tr>
                                            <td style="border:1px solid #e5e7eb;"><?php echo esc_html( $item->get_name() ); ?></td>
                                            <td style="border:1px solid #e5e7eb;"><?php echo esc_html( $item->get_quantity() ); ?></td>
                                            <td style="border:1px solid #e5e7eb;"><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
        <?php
        return ob_get_clean();
    }

    /**
     * Simple HTML email for STATUS CHANGE.
     *
     * @param WC_Order $order
     * @param string   $old_status
     * @param string   $new_status
     * @param string   $audience store_employee|shop_manager|vendor|generic
     */
    private function build_status_change_email_body( WC_Order $order, string $old_status, string $new_status, string $audience = 'generic' ): string {
        $old_label = wc_get_order_status_name( $old_status );
        $new_label = wc_get_order_status_name( $new_status );

        $store_id   = (int) $order->get_meta( '_wcss_store_id' );
        $store_name = $store_id ? get_the_title( $store_id ) : '';
        $store_code = $store_id ? get_post_meta( $store_id, WCSS_Store_CPT::META_CODE, true ) : '';
        $store_city = $store_id ? get_post_meta( $store_id, WCSS_Store_CPT::META_CITY, true ) : '';

        $heading = sprintf(
            'Order #%s status updated',
            $order->get_order_number()
        );

        if ( 'store_employee' === $audience ) {
            $intro = __( 'The status of an order for your store has been updated in the Internal Supply portal.', 'wcss' );
        } elseif ( 'shop_manager' === $audience ) {
            $intro = __( 'The status of an order has been updated in the Internal Supply portal.', 'wcss' );
        } elseif ( 'vendor' === $audience ) {
            $intro = __( 'The status of an order containing your items has been updated.', 'wcss' );
        } else {
            $intro = __( 'The status of an order has been updated.', 'wcss' );
        }

        ob_start();
        ?>
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:#f5f5f7; padding:20px 0;">
            <tr>
                <td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e2e2e7;">
                        <tr>
                            <td style="background:#111827;color:#ffffff;padding:16px 24px;font-size:18px;font-weight:600;">
                                <?php echo esc_html( $heading ); ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:16px 24px;font-size:14px;line-height:1.5;color:#111827;">
                                <p style="margin:0 0 8px 0;"><?php echo esc_html( $intro ); ?></p>
                                <p style="margin:0;">
                                    <strong><?php esc_html_e( 'From:', 'wcss' ); ?></strong>
                                    <?php echo esc_html( $old_label ); ?><br>
                                    <strong><?php esc_html_e( 'To:', 'wcss' ); ?></strong>
                                    <?php echo esc_html( $new_label ); ?><br>
                                    <strong><?php esc_html_e( 'Total:', 'wcss' ); ?></strong>
                                    <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?><br>
                                    <strong><?php esc_html_e( 'Customer:', 'wcss' ); ?></strong>
                                    <?php echo esc_html( $order->get_billing_email() ); ?><br>
                                    <strong><?php esc_html_e( 'Updated at:', 'wcss' ); ?></strong>
                                    <?php echo esc_html( current_time( 'Y-m-d H:i' ) ); ?>
                                </p>
                            </td>
                        </tr>

                        <?php if ( $store_id ) : ?>
                        <tr>
                            <td style="padding:0 24px 16px 24px;">
                                <h3 style="font-size:14px;margin:0 0 8px 0;color:#111827;"><?php esc_html_e( 'Store', 'wcss' ); ?></h3>
                                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:13px;color:#374151;">
                                    <tr>
                                        <td width="25%" style="padding:2px 0;"><strong><?php esc_html_e( 'Name:', 'wcss' ); ?></strong></td>
                                        <td style="padding:2px 0;"><?php echo esc_html( $store_name ?: '#' . $store_id ); ?></td>
                                    </tr>
                                    <tr>
                                        <td width="25%" style="padding:2px 0;"><strong><?php esc_html_e( 'Code:', 'wcss' ); ?></strong></td>
                                        <td style="padding:2px 0;"><?php echo esc_html( $store_code ); ?></td>
                                    </tr>
                                    <tr>
                                        <td width="25%" style="padding:2px 0;"><strong><?php esc_html_e( 'City:', 'wcss' ); ?></strong></td>
                                        <td style="padding:2px 0;"><?php echo esc_html( $store_city ); ?></td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <tr>
                            <td style="padding:0 24px 24px 24px;">
                                <h3 style="font-size:14px;margin:0 0 8px 0;color:#111827;"><?php esc_html_e( 'Items', 'wcss' ); ?></h3>
                                <table width="100%" cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;">
                                    <thead>
                                        <tr style="background:#f3f4f6;">
                                            <th align="left" style="border:1px solid #e5e7eb;"><?php esc_html_e( 'Product', 'wcss' ); ?></th>
                                            <th align="left" style="border:1px solid #e5e7eb;"><?php esc_html_e( 'Qty', 'wcss' ); ?></th>
                                            <th align="left" style="border:1px solid #e5e7eb;"><?php esc_html_e( 'Total', 'wcss' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ( $order->get_items() as $item ) : ?>
                                        <tr>
                                            <td style="border:1px solid #e5e7eb;"><?php echo esc_html( $item->get_name() ); ?></td>
                                            <td style="border:1px solid #e5e7eb;"><?php echo esc_html( $item->get_quantity() ); ?></td>
                                            <td style="border:1px solid #e5e7eb;"><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
        <?php
        return ob_get_clean();
    }


    /**
     * Notify each vendor with only their own items from the order.
     *
     * @param int       $order_id
     * @param WC_Order|null $order
     */

    public function notify_vendors_on_status_change( $order_id, $order = null ) {
            if ( ! $order instanceof WC_Order ) {
                $order = wc_get_order( $order_id );
            }
            if ( ! $order ) {
                return;
            }

            // Build vendor → items map
            $vendors = []; // vendor_id => [ 'term' => WP_Term, 'email' => string, 'name' => string, 'items' => [] ]

            foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
                $product_id = $item->get_product_id() ?: $item->get_variation_id();
                if ( ! $product_id ) {
                    continue;
                }

                // Get vendor term from product
                $terms = wp_get_post_terms( $product_id, 'wcss_vendor' );
                if ( is_wp_error( $terms ) || empty( $terms ) ) {
                    continue;
                }

                $term = $terms[0];
                $vid  = (int) $term->term_id;

                if ( empty( $vendors[ $vid ] ) ) {
                    // 🔁 Adjust meta key to whatever you actually use for vendor email
                    $email = get_term_meta( $vid, 'wcss_vendor_email', true );
                    $name  = $term->name;

                    // If you store vendor email elsewhere, change it here.
                    if ( ! $email ) {
                        // No email → skip this vendor entirely
                        // or fall back to a default notification address if you want.
                        continue;
                    }

                    $vendors[ $vid ] = [
                        'term'  => $term,
                        'email' => $email,
                        'name'  => $name,
                        'items' => [],
                    ];
                }

                $vendors[ $vid ]['items'][ $item_id ] = $item;
            }

            if ( empty( $vendors ) ) {
                return;
            }

            // Shared info
            $subject_base = sprintf(
                'New order #%s from Internal Supply',
                $order->get_order_number()
            );

            foreach ( $vendors as $vendor_id => $data ) {
                $to      = $data['email'];
                $subject = $subject_base . ' – ' . $data['name'];
                $message = $this->build_vendor_email_html( $order, $data );
                $headers = [
                    'Content-Type: text/html; charset=UTF-8',
                    // Adjust "from" as needed:
                    'From: Internal Supply <no-reply@' . wp_parse_url( home_url(), PHP_URL_HOST ) . '>',
                    'X-WCSS-Context: wcss_vendor_notification',
                ];

                wp_mail( $to, $subject, $message, $headers );
            }
    }

}