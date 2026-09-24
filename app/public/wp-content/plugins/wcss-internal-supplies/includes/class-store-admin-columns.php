<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Store_Admin_Columns {
    const TRANSIENT_PREFIX = 'wcss_usage_'; // wcss_usage_{storeId}_{Y_m}

    public function __construct() {
        add_filter( 'manage_' . WCSS_Store_CPT::POST_TYPE . '_posts_columns', [ $this, 'cols' ] );
        add_action( 'manage_' . WCSS_Store_CPT::POST_TYPE . '_posts_custom_column', [ $this, 'render' ], 10, 2 );
        add_filter( 'manage_edit-' . WCSS_Store_CPT::POST_TYPE . '_sortable_columns', [ $this, 'sortable' ] );

        // Invalidate cache when a store is saved
        add_action( 'save_post_' . WCSS_Store_CPT::POST_TYPE, [ $this, 'bust_cache' ], 10, 1 );
    }

    public function cols( $cols ) {
        // Keep title first; then add our metrics
        $in = [
            'wcss_quota'   => __( 'Monthly Quota', 'wcss' ),
            'wcss_budget'  => __( 'Monthly Budget', 'wcss' ),
            'wcss_usage'   => __( 'Usage (This Month)', 'wcss' ),
        ];
        // Insert after title
        $new = [];
        foreach ( $cols as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'title' ) {
                $new = array_merge( $new, $in );
            }
        }
        return $new;
    }

    public function sortable( $cols ) {
        $cols['wcss_quota']  = 'wcss_quota';
        $cols['wcss_budget'] = 'wcss_budget';
        return $cols;
    }

    public function render( $col, $post_id ) {
        switch ( $col ) {
            case 'wcss_quota':
                $quota_meta = get_post_meta( $post_id, WCSS_Store_CPT::META_QUOTA, true );
                $quota = ( $quota_meta === '' ) ? (int) wcss_get_option( 'default_monthly_quota', 0 ) : (int) $quota_meta;
                echo $quota > 0 ? esc_html( $quota ) : '<em>' . esc_html__( 'Unlimited', 'wcss' ) . '</em>';
                break;

            case 'wcss_budget':
                $enf    = (bool) wcss_get_option( 'budget_enforcement', 1 );
                $budget = get_post_meta( $post_id, WCSS_Store_CPT::META_BUDGET, true );
                if ( ! $enf || $budget === '' || (float) $budget <= 0 ) {
                    echo '<em>' . esc_html__( 'No limit', 'wcss' ) . '</em>';
                } else {
                    echo wp_kses_post( wc_price( (float) $budget ) );
                }
                break;

                case 'wcss_usage':
                    [ $used_count, $used_amount ] = $this->get_usage_for_store_this_month( $post_id );
                
                    $enf    = (bool) wcss_get_option( 'budget_enforcement', 1 );
                    $budget = get_post_meta( $post_id, WCSS_Store_CPT::META_BUDGET, true );
                    $has_budget = $enf && $budget !== '' && (float) $budget > 0;
                    $budget_val = $has_budget ? (float) $budget : 0.0;
                
                    $quota_meta = get_post_meta( $post_id, WCSS_Store_CPT::META_QUOTA, true );
                    $quota = ( $quota_meta === '' ) ? (int) wcss_get_option( 'default_monthly_quota', 0 ) : (int) $quota_meta;
                    $quota_label = $quota > 0 ? (string) $quota : __( '∞', 'wcss' );
                
                    // Visible summary
                    echo esc_html( $used_count . ' / ' . $quota_label );
                    echo ' &nbsp;•&nbsp; ';
                    echo wp_kses_post( wc_price( (float) $used_amount ) );
                    if ( $has_budget ) {
                        $remaining = max( 0.0, (float) $budget_val - (float) $used_amount );
                        echo ' ' . esc_html__( '(Remaining:', 'wcss' ) . ' ' . wp_kses_post( wc_price( $remaining ) ) . ')';
                    }
                
                    // Hidden payload for the highlighter (JS will read these)
                    printf(
                        '<span class="wcss-usage-meta" style="display:none"
                            data-used-count="%d"
                            data-quota="%d"
                            data-used-amount="%s"
                            data-budget="%s"></span>',
                        (int) $used_count,
                        (int) $quota,
                        esc_attr( (string) (float) $used_amount ),
                        esc_attr( (string) (float) $budget_val )
                    );
                break;
        }
    }

    private function get_usage_for_store_this_month( $store_id ) {
        $statuses_opt = (array) wcss_get_option( 'count_statuses', ['wc-awaiting-approval','wc-approved'] );
        $statuses     = array_map( fn($s)=> ltrim( preg_replace('/^wc-/', '', $s ), '-' ), $statuses_opt );

        $tz    = wp_timezone();
        $now   = new DateTimeImmutable( 'now', $tz );
        $ym    = $now->format('Y_m');
        $key   = self::TRANSIENT_PREFIX . $store_id . '_' . $ym;

        $cached = get_transient( $key );
        if ( is_array( $cached ) && count( $cached ) === 2 ) {
            return $cached;
        }

        $start = $now->modify('first day of this month')->setTime(0,0,0);
        $end   = $now->modify('last day of this month')->setTime(23,59,59);
        $range = $start->format('Y-m-d H:i:s') . '...' . $end->format('Y-m-d H:i:s');

        // Exempt product IDs for budget
        $exempt_ids_raw = (string) wcss_get_option( 'exempt_product_ids', '' );
        $exempt_ids = array_filter( array_map( 'intval',
            array_filter( array_map( 'trim', explode(',', $exempt_ids_raw ) ) )
        ) );

        // Aggregate
        $page=1; $per=50; $total=0; $sum=0.0;
        $base = [
            'type'         => 'shop_order',
            'status'       => $statuses,
            'limit'        => $per,
            'paginate'     => true,
            'return'       => 'objects',
            'meta_query'   => [[ 'key' => '_wcss_store_id','value'=>$store_id ]],
            'date_created' => $range,
        ];
        $res   = wc_get_orders( $base + [ 'page'=>$page ] );
        $total = (int) ( $res ? $res->total : 0 );
        if ( $res && ! empty( $res->orders ) ) {
            foreach ( $res->orders as $o ) { $sum += $this->order_amount_exempt_aware( $o, $exempt_ids ); }
        }
        $fetched = $res && ! empty( $res->orders ) ? count( $res->orders ) : 0;
        while ( $fetched < $total ) {
            $page++;
            $res = wc_get_orders( $base + [ 'page'=>$page ] );
            if ( ! $res || empty( $res->orders ) ) break;
            foreach ( $res->orders as $o ) { $sum += $this->order_amount_exempt_aware( $o, $exempt_ids ); }
            $fetched += count( $res->orders );
        }

        set_transient( $key, [ (int) $total, (float) $sum ], 10 * MINUTE_IN_SECONDS );
        return [ (int) $total, (float) $sum ];
    }

    private function order_amount_exempt_aware( WC_Order $order, array $exempt_ids ): float {
        $t=0.0;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $pid = (int) $item->get_product_id();
            if ( in_array( $pid, $exempt_ids, true ) ) continue;
            $t += (float) $item->get_total(); // excl tax/shipping
        }
        return $t;
    }

    public function bust_cache( $post_id ) {
        $tz  = wp_timezone();
        $now = new DateTimeImmutable( 'now', $tz );
        $ym  = $now->format('Y_m');
        delete_transient( self::TRANSIENT_PREFIX . $post_id . '_' . $ym );
    }
}