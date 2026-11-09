<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Store employee and vendor emails.
 *
 * Supply Ops / manager emails are configured per address in Supply Manager → Emails
 * and sent by WCSS_Email_Center. All emails use WCSS_Email_Template.
 *
 * Vendors (SOW 2.11) get one purchase order per order, only when it is approved: straight away
 * for an order inside the store's policy, or after Supply Ops approves it (with any edits).
 * Nothing goes to a vendor when an order is placed, and an order is never sent to a vendor twice.
 * If an order that was already sent is later cancelled or rejected, that vendor is told once.
 */
class WCSS_Notifications {

    /** Vendors already sent this order's PO: [ term_id => [ 'at', 'email', 'name', 'items' ] ]. */
    const PO_META = '_wcss_vendor_po';

    public function __construct() {
        // Store checkout: tell the store once its order has been checked against the ordering policy,
        // so the email can say whether it goes straight to the supplier or waits for Supply Ops.
        add_action( 'wcss_order_policy_recorded', [ $this, 'store_order_received' ], 10, 2 );

        // Supply Ops added or changed the delivery date or tracking: tell the store (SOW 1.23).
        add_action( 'wcss_shipment_updated', [ $this, 'store_shipment_updated' ], 10, 3 );

        // Order status changed (pending → approved / rejected / etc)
        add_action( 'woocommerce_order_status_changed', [ $this, 'handle_status_change' ], 10, 4 );

        // Each vendor gets only their own lines, once, when the order is approved.
        add_action( 'woocommerce_order_status_approved',  [ $this, 'notify_vendors_on_status_change' ], 10, 2 );
    }

    /**
     * Store order placed. In policy: "we received it, it goes to the supplier". Needs approval
     * (ink, over budget, outside the ordering week, second order): "waiting for Supply Ops", with why.
     * Vendors hear nothing here; they get the PO only at approval. Sent once per order.
     */
    public function store_order_received( $order, $res ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order || $order->get_meta( '_wcss_store_received_mail' ) ) {
            return;
        }
        $to = $this->get_store_employee_email_for_order( $order );
        if ( ! $to ) {
            return;
        }
        $no     = $order->get_order_number();
        $review = empty( $res['in_policy'] );
        $ink    = in_array( 'ink_review', (array) ( $res['reasons'] ?? [] ), true );
        // At checkout the order is still "pending"; show the status the store will see next.
        $details = array_map( static function ( $row ) use ( $review ) {
            return ( is_array( $row ) && ( $row[0] ?? '' ) === 'Status' ) ? [ 'Status', $review ? 'Awaiting approval' : 'Received' ] : $row;
        }, WCSS_Email_Center::order_details( $order, false ) );
        $args   = [
            'eyebrow' => 'Order received',
            'details' => $details,
            'items'   => WCSS_Email_Template::order_items( $order ),
            'reason'  => __( 'You get this because you placed an order for your store.', 'wcss' ),
        ];
        if ( $review ) {
            $subject = sprintf( '[Internal Supply] Order #%s is waiting for Supply Ops approval', $no );
            $args   += [
                'title'  => sprintf( 'Order #%s is waiting for approval', $no ),
                'status' => [ 'label' => 'Awaiting approval', 'tone' => 'warn' ],
                'intro'  => __( 'Thanks — we received your order. Supply Ops needs to approve it first, so nothing goes to the supplier yet. We’ll email you as soon as it is approved or rejected.', 'wcss' ),
                'note'   => [
                    'title' => $ink ? __( 'Why: ink order', 'wcss' ) : __( 'Why it needs approval', 'wcss' ),
                    'lines' => $ink ? [ __( 'Ink orders are always checked by Supply Ops. They don’t use your monthly budget.', 'wcss' ) ] : array_values( (array) ( $res['messages'] ?? [] ) ),
                    'tone'  => 'warn',
                ],
            ];
        } else {
            $subject = sprintf( '[Internal Supply] We received your order #%s', $no );
            $args   += [
                'title'  => sprintf( 'We received order #%s', $no ),
                'status' => [ 'label' => 'Received', 'tone' => 'info' ],
                'intro'  => __( 'Thanks — your order is within your store’s ordering policy, so it goes to the supplier automatically. We’ll email you when it is approved and sent.', 'wcss' ),
            ];
        }
        $this->send( $to, $subject, $args, $review ? 'wcss_store_needs_approval' : 'wcss_new_order_store_employee' );
        $order->update_meta_data( '_wcss_store_received_mail', current_time( 'mysql' ) );
        $order->save_meta_data();
    }

    /** Delivery details changed by Supply Ops. Nothing is sent when they were only cleared. */
    public function store_shipment_updated( $order, $before, $after ) {
        if ( ! $order instanceof WC_Order || ( $after['tracking'] === '' && $after['edd_source'] !== 'manual' ) ) {
            return;
        }
        $to = $this->get_store_employee_email_for_order( $order );
        if ( ! $to ) return;
        $no      = $order->get_order_number();
        $details = [ [ 'Order', '#' . $no ] ];
        if ( $after['edd_label'] !== '' ) $details[] = [ 'Expected delivery', $after['edd_label'] ];
        if ( $after['carrier_label'] !== '' ) $details[] = [ 'Carrier', $after['carrier_label'] ];
        if ( $after['tracking'] !== '' ) $details[] = [ 'Tracking number', $after['tracking'] ];
        $args = [
            'eyebrow' => 'Delivery update',
            'title'   => $after['tracking'] !== '' ? sprintf( 'Order #%s is on its way', $no ) : sprintf( 'Delivery date for order #%s', $no ),
            'status'  => [ 'label' => $after['tracking'] !== '' ? 'Shipped' : 'Delivery date set', 'tone' => 'ok' ],
            'intro'   => $before['has'] && ( $before['tracking'] !== '' || $before['edd_source'] === 'manual' )
                ? __( 'The delivery details for your store’s order have changed. Here is the latest.', 'wcss' )
                : __( 'Good news — here are the delivery details for your store’s order.', 'wcss' ),
            'details' => $details,
            'items'   => WCSS_Email_Template::order_items( $order ),
            'reason'  => __( 'You get this because you placed this order for your store. You can also see it in My Account → Orders.', 'wcss' ),
        ];
        if ( $after['url'] ) {
            $args['action'] = [ 'label' => __( 'Track your delivery', 'wcss' ), 'url' => $after['url'] ];
        }
        $this->send( $to, sprintf( '[Internal Supply] Delivery update for order #%s', $no ), $args, 'wcss_store_shipment' );
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

        if ( 'approved' === $new_status ) {
            $override = $order->get_meta( '_wcss_policy_override' );
            $intro['approved'] = $override
                ? __( 'Good news — Supply Ops approved your store’s order. It is being sent to the supplier now.', 'wcss' )
                : __( 'Good news — your store’s order is within policy and was approved automatically. It is being sent to the supplier now.', 'wcss' );
        }

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
            if ( 'approved' === $new_status && class_exists( 'WCSS_Order_Events' ) ) {
                $edited = array_filter( WCSS_Order_Events::for_order( $order->get_id(), 50 ), static function ( $e ) { return ( $e['type'] ?? '' ) === 'items_edit'; } );
                if ( $edited ) {
                    $args['note'] = [ 'title' => __( 'Supply Ops changed your order', 'wcss' ), 'lines' => [ __( 'Some items or quantities were changed before approval. The list below is what will be sent to the supplier.', 'wcss' ) ], 'tone' => 'info' ];
                }
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

        // A vendor only hears about a status change when it already has the PO and the order is called off.
        if ( in_array( $new_status, [ 'cancelled', 'rejected' ], true ) ) {
            $this->notify_vendors_cancelled( $order, $label );
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

        $sent    = self::po_record( $order );
        $vendors = array_diff_key( $this->vendors_for_order( $order ), $sent );
        if ( ! $vendors ) {
            return;
        }

        $ok = [];
        foreach ( $vendors as $vid => $data ) {
            $ship_to = $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address();
            $ship_to = trim( preg_replace( '/\s*<br\s*\/?>\s*/i', ', ', (string) $ship_to ), ', ' );
            $res = $this->send(
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
            if ( ! empty( $res['ok'] ) ) $ok[ $vid ] = true; // Only a PO that actually went out counts as sent; a failed one is tried again next approval.
        }
        $vendors = array_intersect_key( $vendors, $ok );
        if ( ! $vendors ) {
            return;
        }

        foreach ( $vendors as $vid => $data ) {
            $sent[ $vid ] = [ 'at' => current_time( 'mysql' ), 'email' => $data['email'], 'name' => $data['name'], 'items' => array_keys( $data['items'] ) ];
        }
        $order->update_meta_data( self::PO_META, $sent );
        $order->save_meta_data();
        if ( class_exists( 'WCSS_Order_Events' ) ) {
            WCSS_Order_Events::log( $order->get_id(), 'vendor_po', [ 'vendors' => array_values( wp_list_pluck( $vendors, 'name' ) ) ] );
        }
    }

    private static function po_record( WC_Order $order ): array {
        $v = $order->get_meta( self::PO_META );
        return is_array( $v ) ? $v : [];
    }

    /** Tell vendors that already have the PO that the order is off. Each is told once. */
    private function notify_vendors_cancelled( WC_Order $order, string $label ) {
        $sent = self::po_record( $order );
        $changed = false;
        foreach ( $sent as $vid => $po ) {
            if ( ! is_array( $po ) || ! empty( $po['cancelled'] ) || ! is_email( $po['email'] ?? '' ) ) continue;
            $no = $order->get_order_number();
            $this->send(
                $po['email'],
                sprintf( 'Order #%s from Internal Supply is cancelled – please do not supply', $no ),
                [
                    'eyebrow' => 'Order cancelled',
                    'title'   => sprintf( 'Please do not supply order #%s', $no ),
                    'status'  => [ 'label' => $label, 'tone' => 'bad' ],
                    'intro'   => sprintf( __( 'Hello %s, the purchase order we sent you for this order has been cancelled. Please do not ship it.', 'wcss' ), $po['name'] ?? '' ),
                    'details' => $this->vendor_details( $order ),
                    'items'   => WCSS_Email_Template::order_items( $order, array_fill_keys( (array) ( $po['items'] ?? [] ), true ), false ),
                    'reason'  => __( 'Thank you — Canna Cabana Supply Operations.', 'wcss' ),
                ],
                'wcss_vendor_cancelled'
            );
            $sent[ $vid ]['cancelled'] = current_time( 'mysql' );
            $changed = true;
        }
        if ( $changed ) {
            $order->update_meta_data( self::PO_META, $sent );
            $order->save_meta_data();
        }
    }

    /**
     * Which vendor supplies each line: the vendor the line was priced with (Amazon or Grand & Toy),
     * otherwise the product's active manual vendor. Only vendors with an email address are returned.
     *
     * @return array<int, array{email:string, name:string, items:array<int,bool>}>
     */
    private function vendors_for_order( WC_Order $order ): array {
        $slug_of = [ 'amazon' => 'amazon', 'gt' => 'grand-toy' ];
        $vendors = [];
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $term = null;
            $key  = sanitize_key( (string) $item->get_meta( '_cc_vendor', true ) );
            if ( isset( $slug_of[ $key ] ) ) {
                $t = get_term_by( 'slug', $slug_of[ $key ], 'wcss_vendor' );
                if ( $t && WCSS_Vendor_Status::is_active( $t->term_id ) ) $term = $t;
            }
            if ( ! $term ) {
                $product_id = $item->get_product_id() ?: $item->get_variation_id();
                $terms      = $product_id ? wp_get_post_terms( $product_id, 'wcss_vendor' ) : [];
                foreach ( is_wp_error( $terms ) ? [] : WCSS_Vendor_Status::only_active( $terms ) as $t ) {
                    if ( in_array( $t->slug, $slug_of, true ) ) continue; // Live vendors only supply lines priced with them.
                    $term = $t;
                    break;
                }
            }
            if ( ! $term ) continue;
            $vid = (int) $term->term_id;
            if ( empty( $vendors[ $vid ] ) ) {
                $email = get_term_meta( $vid, 'wcss_vendor_email', true );
                if ( ! is_email( $email ) ) continue; // No email → nothing to send.
                $vendors[ $vid ] = [ 'email' => $email, 'name' => html_entity_decode( $term->name, ENT_QUOTES ), 'items' => [] ];
            }
            $vendors[ $vid ]['items'][ $item_id ] = true;
        }
        return $vendors;
    }

    /* ===================== Helpers ===================== */

    private function send( string $to, string $subject, array $args, string $context ): array {
        return WCSS_Email_Center::send( $to, $subject, WCSS_Email_Template::render( $args ), $context );
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


}
