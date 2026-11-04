<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Store employee and vendor emails.
 *
 * Supply Ops / manager emails are configured per address in Supply Manager → Emails
 * and sent by WCSS_Email_Center. All emails use WCSS_Email_Template.
 */
class WCSS_Notifications {

    public function __construct() {
        // New order placed
        add_action( 'woocommerce_new_order', [ $this, 'handle_new_order' ], 10, 2 );

        // Order status changed (pending → approved / rejected / etc)
        add_action( 'woocommerce_order_status_changed', [ $this, 'handle_status_change' ], 10, 4 );

        // Each vendor gets only their own lines once the order is approved / completed.
        add_action( 'woocommerce_order_status_completed', [ $this, 'notify_vendors_on_status_change' ], 10, 2 );
        add_action( 'woocommerce_order_status_approved',  [ $this, 'notify_vendors_on_status_change' ], 10, 2 );
    }

    /**
     * New order: tell the store employee and any vendor on the order.
     * Each recipient gets a separate email (no shared "To" list).
     */
    public function handle_new_order( $order_id, $order = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $no = $order->get_order_number();

        $store_employee_email = $this->get_store_employee_email_for_order( $order );
        if ( $store_employee_email ) {
            $this->send(
                $store_employee_email,
                sprintf( '[Internal Supply] We received your order #%s', $no ),
                [
                    'eyebrow' => 'Order received',
                    'title'   => sprintf( 'We received order #%s', $no ),
                    'status'  => [ 'label' => wc_get_order_status_name( $order->get_status() ), 'tone' => 'info' ],
                    'intro'   => __( 'Thanks — your store’s order is in. We’ll email you when it is approved and sent to the supplier.', 'wcss' ),
                    'details' => WCSS_Email_Center::order_details( $order, false ),
                    'items'   => WCSS_Email_Template::order_items( $order ),
                    'reason'  => __( 'You get this because you placed an order for your store.', 'wcss' ),
                ],
                'wcss_new_order_store_employee'
            );
        }

        foreach ( $this->get_vendor_emails_for_order( $order ) as $email ) {
            $this->send(
                $email,
                sprintf( '[Internal Supply] New order #%s', $no ),
                [
                    'eyebrow' => 'New order',
                    'title'   => sprintf( 'New order #%s', $no ),
                    'status'  => [ 'label' => wc_get_order_status_name( $order->get_status() ), 'tone' => 'info' ],
                    'intro'   => __( 'A new order has been placed that includes items from your catalog. You will receive the items to supply once it is approved.', 'wcss' ),
                    'details' => $this->vendor_details( $order ),
                    'items'   => WCSS_Email_Template::order_items( $order, null, false ),
                ],
                'wcss_new_order_vendor'
            );
        }
    }

    /**
     * Status change: tell the store employee and vendors about approval / rejection etc.
     */
    public function handle_status_change( $order_id, $old_status, $new_status, $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $interesting = [ 'approved', 'rejected', 'completed', 'cancelled' ];
        if ( ! in_array( $new_status, $interesting, true ) ) {
            return;
        }

        $no    = $order->get_order_number();
        $label = wc_get_order_status_name( $new_status );
        $tones = [ 'approved' => 'ok', 'completed' => 'ok', 'rejected' => 'bad', 'cancelled' => 'neutral' ];
        $intro = [
            'approved'  => __( 'Good news — your store’s order is approved and is being sent to the supplier.', 'wcss' ),
            'rejected'  => __( 'Your store’s order was rejected and will not be sent to a supplier. Contact Supply Ops if you have questions.', 'wcss' ),
            'completed' => __( 'Your store’s order is complete.', 'wcss' ),
            'cancelled' => __( 'Your store’s order was cancelled.', 'wcss' ),
        ];

        $store_employee_email = $this->get_store_employee_email_for_order( $order );
        if ( $store_employee_email ) {
            $args = [
                'eyebrow' => 'Order update',
                'title'   => sprintf( 'Order #%s %s', $no, strtolower( $label ) ),
                'status'  => [ 'label' => $label, 'tone' => $tones[ $new_status ] ],
                'intro'   => $intro[ $new_status ],
                'details' => WCSS_Email_Center::order_details( $order, false ),
                'items'   => WCSS_Email_Template::order_items( $order ),
                'reason'  => __( 'You get this because you placed this order for your store.', 'wcss' ),
            ];
            $note = 'rejected' === $new_status ? WCSS_Email_Center::latest_note( $order ) : '';
            if ( $note !== '' ) {
                $args['note'] = [ 'title' => __( 'Reason', 'wcss' ), 'lines' => [ $note ], 'tone' => 'bad' ];
            }
            $this->send(
                $store_employee_email,
                'rejected' === $new_status
                    ? sprintf( '[Internal Supply] Order #%s was rejected', $no )
                    : sprintf( '[Internal Supply] Order #%s status updated for your store: %s', $no, $label ),
                $args,
                'wcss_status_store_employee'
            );
        }

        foreach ( $this->get_vendor_emails_for_order( $order ) as $email ) {
            $this->send(
                $email,
                sprintf( '[Internal Supply] Order #%s status updated: %s', $no, $label ),
                [
                    'eyebrow' => 'Order update',
                    'title'   => sprintf( 'Order #%s %s', $no, strtolower( $label ) ),
                    'status'  => [ 'label' => $label, 'tone' => $tones[ $new_status ] ],
                    'intro'   => __( 'The status of an order containing your items has been updated.', 'wcss' ),
                    'details' => $this->vendor_details( $order ),
                    'items'   => WCSS_Email_Template::order_items( $order, null, false ),
                ],
                'wcss_status_vendor'
            );
        }
    }

    /**
     * Each vendor gets only their own items from the order, plus where to ship.
     *
     * @param int           $order_id
     * @param WC_Order|null $order
     */
    public function notify_vendors_on_status_change( $order_id, $order = null ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        $vendors = []; // vendor_id => [ 'email' => string, 'name' => string, 'items' => [ item_id => true ] ]
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $product_id = $item->get_product_id() ?: $item->get_variation_id();
            if ( ! $product_id ) {
                continue;
            }
            $terms = wp_get_post_terms( $product_id, 'wcss_vendor' );
            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }
            $vid = (int) $terms[0]->term_id;
            if ( empty( $vendors[ $vid ] ) ) {
                $email = get_term_meta( $vid, 'wcss_vendor_email', true );
                if ( ! is_email( $email ) ) {
                    continue; // No email → skip this vendor.
                }
                $vendors[ $vid ] = [ 'email' => $email, 'name' => html_entity_decode( $terms[0]->name, ENT_QUOTES ), 'items' => [] ];
            }
            $vendors[ $vid ]['items'][ $item_id ] = true;
        }

        foreach ( $vendors as $data ) {
            $ship_to = $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address();
            $ship_to = trim( preg_replace( '/\s*<br\s*\/?>\s*/i', ', ', (string) $ship_to ), ', ' );
            $this->send(
                $data['email'],
                sprintf( 'New order #%s from Internal Supply – %s', $order->get_order_number(), $data['name'] ),
                [
                    'eyebrow' => 'Purchase order',
                    'title'   => sprintf( 'Please supply order #%s', $order->get_order_number() ),
                    'status'  => [ 'label' => 'Approved', 'tone' => 'ok' ],
                    'intro'   => sprintf( __( 'Hello %s, please supply the following items and ship them to the store below.', 'wcss' ), $data['name'] ),
                    'details' => array_merge( $this->vendor_details( $order ), [ [ 'Ship to', wp_strip_all_tags( $ship_to ) ?: '—' ] ] ),
                    'items'   => WCSS_Email_Template::order_items( $order, $data['items'], false ),
                    'reason'  => __( 'Thank you — Canna Cabana Supply Operations.', 'wcss' ),
                ],
                'wcss_vendor_notification'
            );
        }
    }

    /* ===================== Helpers ===================== */

    private function send( string $to, string $subject, array $args, string $context ) {
        WCSS_Email_Center::send( $to, $subject, WCSS_Email_Template::render( $args ), $context );
    }

    /** Order facts a vendor needs (no budget or internal policy details). */
    private function vendor_details( WC_Order $order ): array {
        $store_id = (int) $order->get_meta( '_wcss_store_id' );
        $created  = $order->get_date_created();
        return [
            [ 'Order', '#' . $order->get_order_number() ],
            [ 'Store', $store_id ? html_entity_decode( get_the_title( $store_id ), ENT_QUOTES ) : '—' ],
            [ 'Placed', $created ? wp_date( 'j M Y, g:i A', $created->getTimestamp() ) : '—' ],
        ];
    }

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
        return array_values( array_unique( array_filter( $emails ) ) );
    }

}
