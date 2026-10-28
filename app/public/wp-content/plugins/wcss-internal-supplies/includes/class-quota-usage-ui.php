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
        $quota_label  = $quota_count > 0 ? (string) $quota_count : __( '∞', 'wcss' );

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
        $budget_pct       = ( $has_budget && $budget_month > 0 )
            ? min( 100, (int) round( ( $used_amount / $budget_month ) * 100 ) )
            : 0;
        $orders_pct       = ( $quota_count > 0 )
            ? min( 100, (int) round( ( $used_count / $quota_count ) * 100 ) )
            : 0;

        ?>
        <div class="wcss-usage" role="region" aria-label="<?php esc_attr_e( 'Monthly store usage', 'wcss' ); ?>">
            <div class="wcss-usage__card wcss-usage__card--orders">
                <div class="wcss-usage__icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 6h11a1 1 0 0 1 1 1v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M9 10h8M9 14h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </div>
                <div class="wcss-usage__body">
                    <div class="wcss-usage__label"><?php esc_html_e( 'Orders used this month', 'wcss' ); ?></div>
                    <div class="wcss-usage__value">
                        <?php echo esc_html( (string) $used_count ); ?>
                        <span class="wcss-usage__sep">/</span>
                        <?php echo esc_html( $quota_label ); ?>
                    </div>
                    <?php if ( $quota_count > 0 ) : ?>
                    <div class="wcss-usage__bar" aria-hidden="true">
                        <span class="wcss-usage__bar-fill" style="width:<?php echo esc_attr( (string) $orders_pct ); ?>%"></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( $has_budget ) : ?>
            <div class="wcss-usage__card wcss-usage__card--budget">
                <div class="wcss-usage__icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 8.5A2.5 2.5 0 0 1 5.5 6h13A2.5 2.5 0 0 1 21 8.5v7a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 15.5v-7Z" stroke="currentColor" stroke-width="1.8"/><path d="M3 10h18M16 14h2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </div>
                <div class="wcss-usage__body">
                    <div class="wcss-usage__label"><?php esc_html_e( 'Budget used', 'wcss' ); ?></div>
                    <div class="wcss-usage__meta">
                        <?php
                        echo wp_kses_post( wc_price( $used_amount ) );
                        echo ' / ';
                        echo wp_kses_post( wc_price( $budget_month ) );
                        ?>
                        <span class="wcss-usage__remaining">
                            (<?php
                            /* translators: %s: remaining budget */
                            printf( esc_html__( 'Remaining: %s', 'wcss' ), wp_kses_post( wc_price( $remaining_amount ) ) );
                            ?>)
                        </span>
                    </div>
                    <div class="wcss-usage__bar" aria-hidden="true">
                        <span class="wcss-usage__bar-fill" style="width:<?php echo esc_attr( (string) $budget_pct ); ?>%"></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
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
