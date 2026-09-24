<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Order_Quota {

    public function __construct() {
        // Classic checkout
        add_action( 'woocommerce_after_checkout_validation', [ $this, 'classic_validate' ], 10, 2 );

        // Blocks – validate both cart and checkout stages
        add_filter( 'woocommerce_store_api_cart_errors',     [ $this, 'blocks_cart_errors' ], 10, 2 );      // pre-check
        add_filter( 'woocommerce_store_api_checkout_errors', [ $this, 'blocks_checkout_errors' ], 10, 2 );  // final gate
    }

    /* ===== PUBLIC: Reusable check for other components (e.g., gateway) ===== */
    public static function get_violations_messages(): array {
        $self = new self();                         // stateless; safe
        return $self->evaluate_limits();
    }

    /* ===== Classic ===== */
    public function classic_validate( $data, $errors ) {
        if ( ! is_user_logged_in() ) { return; }
        $msgs = $this->evaluate_limits();
        foreach ( $msgs as $m ) { $errors->add( 'wcss_limit', $m ); }
    }

    /* ===== Blocks – cart stage ===== */
    public function blocks_cart_errors( $wp_error, $cart ) {
        if ( ! is_user_logged_in() ) { return $wp_error; }
        $msgs = $this->evaluate_limits();
        if ( empty( $msgs ) ) { return $wp_error; }
        if ( ! $wp_error instanceof WP_Error ) { $wp_error = new WP_Error(); }
        foreach ( $msgs as $m ) { $wp_error->add( 'wcss_limit', $m, [ 'status' => 400 ] ); }
        return $wp_error;
    }

    /* ===== Blocks – checkout stage ===== */
    public function blocks_checkout_errors( $wp_error, $request ) {
        if ( ! is_user_logged_in() ) { return $wp_error; }
        $msgs = $this->evaluate_limits();
        if ( empty( $msgs ) ) { return $wp_error; }
        if ( ! $wp_error instanceof WP_Error ) { $wp_error = new WP_Error(); }
        foreach ( $msgs as $m ) { $wp_error->add( 'wcss_limit', $m, [ 'status' => 400 ] ); }
        return $wp_error;
    }

    /* ===== Core logic ===== */
    /*
    private function evaluate_limits(): array {
        $store_id = WCSS_User_Store_Map::get_user_store_id();
        if ( ! $store_id ) { return []; }

        $count_statuses_opt = (array) wcss_get_option( 'count_statuses', ['wc-awaiting-approval','wc-approved'] );
        $count_statuses     = array_map( fn($s) => ltrim( preg_replace('/^wc-/', '', $s ), '-' ), $count_statuses_opt );

        $tz    = wp_timezone();
        $now   = new DateTimeImmutable( 'now', $tz );
        $start = $now->modify('first day of this month')->setTime(0,0,0);
        $end   = $now->modify('last day of this month')->setTime(23,59,59);

        // Quota (count)
        $quota_meta = get_post_meta( $store_id, WCSS_Store_CPT::META_QUOTA, true );
        if ( $quota_meta === '' ) {                      // empty on CPT → fallback to plugin default
            $quota_count = (int) wcss_get_option( 'default_monthly_quota', 0 );
        } else {                                         // CPT set (0 allowed = unlimited)
            $quota_count = (int) $quota_meta;
        }
        $has_count_limit = $quota_count > 0;

        // Budget (amount)
        $budget_enforced = (bool) wcss_get_option( 'budget_enforcement', 1 );
        $budget_meta     = get_post_meta( $store_id, WCSS_Store_CPT::META_BUDGET, true );
        if ( $budget_meta === '' || (float) $budget_meta <= 0 || ! $budget_enforced ) {
            $has_budget_limit = false;
            $budget_monthly   = 0.0;
        } else {
            $has_budget_limit = true;
            $budget_monthly   = (float) $budget_meta;
        }

        // Exempt product IDs for budget
        $exempt_ids_raw = (string) wcss_get_option( 'exempt_product_ids', '' );
        $exempt_ids = array_filter( array_map( 'intval',
            array_filter( array_map( 'trim', explode(',', $exempt_ids_raw ) ) )
        ) );

        // Aggregate current month
        [ $used_count, $used_amount ] = $this->aggregate_orders( $store_id, $count_statuses, $start, $end, $exempt_ids );
        $cart_amount = $this->cart_amount_exempt_aware( $exempt_ids );

        $msgs = [];

        if ( $has_count_limit && $used_count >= $quota_count ) {
            $msgs[] = $this->msg_count( $used_count, $quota_count, $now );
        }

        if ( $has_budget_limit ) {
            $projected = $used_amount + $cart_amount;
            if ( $projected > $budget_monthly + 0.00001 ) {
                $msgs[] = $this->msg_budget( $budget_monthly, $used_amount, $cart_amount );
            }
        }

        if ( count( $msgs ) > 1 ) {
            array_unshift( $msgs, __( 'Your order can’t be placed because monthly limits have been exceeded:', 'wcss' ) );
        }

        return $msgs;
    }
    */
    private function evaluate_limits(): array {
        $store_id = WCSS_User_Store_Map::get_user_store_id();
        if ( ! $store_id ) { return []; }
    
        $count_statuses_opt = (array) wcss_get_option( 'count_statuses', ['wc-awaiting-approval','wc-approved'] );
        $count_statuses     = array_map( fn($s) => ltrim( preg_replace('/^wc-/', '', $s ), '-' ), $count_statuses_opt );
    
        $tz    = wp_timezone();
        $now   = new DateTimeImmutable( 'now', $tz );
        $start = $now->modify('first day of this month')->setTime(0,0,0);
        $end   = $now->modify('last day of this month')->setTime(23,59,59);
    
        // QUOTA (count): CPT value takes precedence; empty falls back to plugin default
        $quota_meta = get_post_meta( $store_id, WCSS_Store_CPT::META_QUOTA, true );
        $quota_count = ($quota_meta === '') ? (int) wcss_get_option( 'default_monthly_quota', 0 )
                                            : (int) $quota_meta;
        $has_count_limit = $quota_count > 0;
    
        // BUDGET (amount): enforced only if positive + enforcement enabled
        $budget_enforced = (bool) wcss_get_option( 'budget_enforcement', 1 );
        $budget_meta     = get_post_meta( $store_id, WCSS_Store_CPT::META_BUDGET, true );
        $has_budget_limit = $budget_enforced && $budget_meta !== '' && (float) $budget_meta > 0;
        $budget_monthly   = $has_budget_limit ? (float) $budget_meta : 0.0;
    
        // Exempt product IDs for budget
        $exempt_ids_raw = (string) wcss_get_option( 'exempt_product_ids', '' );
        $exempt_ids = array_filter( array_map( 'intval',
            array_filter( array_map( 'trim', explode(',', $exempt_ids_raw ) ) )
        ) );
    
        // Aggregate month usage
        [ $used_count, $used_amount ] = $this->aggregate_orders(
            $store_id, $count_statuses,
            $start, $end, $exempt_ids
        );
        $cart_amount = $this->cart_amount_exempt_aware( $exempt_ids );
    
        // ---- Evaluate each limit (independently) ----
        $msgs = [];
        $count_violation  = false;
        $budget_violation = false;
    
        if ( $has_count_limit && $used_count >= $quota_count ) {
            $count_violation = true;
            $msgs[] = $this->msg_count( $used_count, $quota_count, $now );
        }
    
        if ( $has_budget_limit ) {
            $projected = $used_amount + $cart_amount;
            if ( $projected > $budget_monthly + 0.00001 ) {
                $budget_violation = true;
                $msgs[] = $this->msg_budget( $budget_monthly, $used_amount, $cart_amount );
            }
        }
    
        // If both are hit, prepend a header (optional)
        if ( $count_violation && $budget_violation ) {
            array_unshift( $msgs, __( 'Your order can’t be placed because monthly limits have been exceeded:', 'wcss' ) );
        }
    
        // Returning ANY messages means "block" (logical OR)
        return $msgs;
    }

    private function aggregate_orders( $store_id, array $statuses, DateTimeImmutable $start, DateTimeImmutable $end, array $exempt_ids ): array {
        $page=1; $per=50; $total=0; $sum=0.0;
        $base = [
            'type'         => 'shop_order',
            'status'       => $statuses,
            'limit'        => $per,
            'paginate'     => true,
            'return'       => 'objects',
            'meta_query'   => [[ 'key' => '_wcss_store_id','value'=>$store_id ]],
            'date_created' => $start->format('Y-m-d H:i:s') . '...' . $end->format('Y-m-d H:i:s'),
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
            $t += (float) $item->get_total(); // excl tax/shipping
        }
        return $t;
    }

    private function cart_amount_exempt_aware( array $exempt_ids ): float {
        if ( ! function_exists('WC') || ! WC()->cart ) return 0.0;
        $t=0.0;
        foreach ( WC()->cart->get_cart() as $ci ) {
            $pid = (int) ( $ci['product_id'] ?? 0 );
            if ( in_array( $pid, $exempt_ids, true ) ) continue;
            $t += isset( $ci['line_total'] ) ? (float) $ci['line_total'] : 0.0;
        }
        return $t;
    }

    private function msg_count( int $used, int $quota, DateTimeImmutable $now ): string {
        $next = $now->modify('first day of next month')->format('F j, Y');
        return sprintf(
            __( 'Order limit reached: %1$d of %2$d orders used this month. You can place the next order on %3$s.', 'wcss' ),
            $used, $quota, esc_html( $next )
        );
    }

    private function msg_budget( float $budget, float $used, float $cart ): string {
        $fmt = fn($n) => wp_kses_post( wc_price( (float) $n ) );
        $remaining = max( 0.0, $budget - $used );
        return sprintf(
            __( 'Budget exceeded. Monthly budget: %1$s, Used so far: %2$s, Current cart: %3$s, Remaining: %4$s. Please reduce items or request an exception.', 'wcss' ),
            $fmt($budget), $fmt($used), $fmt($cart), $fmt($remaining)
        );
    }
}