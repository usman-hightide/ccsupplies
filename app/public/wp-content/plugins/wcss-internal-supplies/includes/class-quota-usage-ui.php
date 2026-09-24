<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Quota_Usage_UI {

    public function __construct() {
        add_action( 'woocommerce_before_cart', [ $this, 'render_banner' ], 5 );
        add_action( 'woocommerce_before_checkout_form', [ $this, 'render_banner' ], 5 );
    }

    public function render_banner() {
        if ( ! is_user_logged_in() ) return;

        $store_id = WCSS_User_Store_Map::get_user_store_id();
        if ( ! $store_id ) return;

        // Read settings
        $statuses_opt = (array) wcss_get_option( 'count_statuses', ['wc-awaiting-approval','wc-approved'] );
        $statuses     = array_map( fn($s)=> ltrim( preg_replace('/^wc-/', '', $s ), '-' ), $statuses_opt );

        $tz    = wp_timezone();
        $now   = new DateTimeImmutable( 'now', $tz );
        $start = $now->modify('first day of this month')->setTime(0,0,0);
        $end   = $now->modify('last day of this month')->setTime(23,59,59);

        // Limits
        $quota_meta   = get_post_meta( $store_id, WCSS_Store_CPT::META_QUOTA, true );
        $quota_count  = ($quota_meta === '') ? (int) wcss_get_option( 'default_monthly_quota', 0 ) : (int) $quota_meta;
        $quota_label  = $quota_count > 0 ? (string) $quota_count : __( 'unlimited', 'wcss' );

        $budget_enf   = (bool) wcss_get_option( 'budget_enforcement', 1 );
        $budget_meta  = get_post_meta( $store_id, WCSS_Store_CPT::META_BUDGET, true );
        $has_budget   = $budget_enf && $budget_meta !== '' && (float) $budget_meta > 0;
        $budget_month = $has_budget ? (float) $budget_meta : 0.0;

        // Exempt product IDs for budget
        $exempt_ids_raw = (string) wcss_get_option( 'exempt_product_ids', '' );
        $exempt_ids = array_filter( array_map( 'intval', array_filter( array_map( 'trim', explode(',', $exempt_ids_raw ) ) ) ) );

        // Aggregate usage this month
        [ $used_count, $used_amount ] = $this->aggregate_orders(
            $store_id, $statuses, $start, $end, $exempt_ids
        );

        $remaining_amount = $has_budget ? max( 0.0, $budget_month - $used_amount ) : 0.0;

        // Render
        ?>
        <style>
            .wcss-usage {border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin:0 0 14px;background:#fafafa}
            .wcss-usage__row{display:flex;gap:18px;flex-wrap:wrap}
            .wcss-usage__pill{padding:6px 10px;border-radius:999px;background:#fff;border:1px solid #e5e7eb}
        </style>
        <div class="wcss-usage">
            <div class="wcss-usage__row">
                <div class="wcss-usage__pill">
                    <?php
                    printf(
                        /* translators: 1: used orders, 2: quota label */
                        esc_html__( 'Orders used this month: %1$s / %2$s', 'wcss' ),
                        esc_html( (string) $used_count ),
                        esc_html( $quota_label )
                    );
                    ?>
                </div>
                <?php if ( $has_budget ) : ?>
                <div class="wcss-usage__pill">
                    <?php
                    printf(
                        /* translators: 1: used amount, 2: budget, 3: remaining */
                        esc_html__( 'Budget used: %1$s / %2$s (Remaining: %3$s)', 'wcss' ),
                        wp_kses_post( wc_price( $used_amount ) ),
                        wp_kses_post( wc_price( $budget_month ) ),
                        wp_kses_post( wc_price( $remaining_amount ) )
                    );
                    ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function aggregate_orders( $store_id, array $statuses, DateTimeImmutable $start, DateTimeImmutable $end, array $exempt_ids ): array {
        $page=1; $per=50; $total=0; $sum=0.0;
        $range = $start->format('Y-m-d H:i:s') . '...' . $end->format('Y-m-d H:i:s');

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
        return [ $total, (float) $sum ];
    }

    private function order_amount_exempt_aware( WC_Order $order, array $exempt_ids ): float {
        $t=0.0;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $pid = (int) $item->get_product_id();
            if ( in_array( $pid, $exempt_ids, true ) ) continue;
            $t += (float) $item->get_total(); // excl. tax/shipping
        }
        return $t;
    }
}