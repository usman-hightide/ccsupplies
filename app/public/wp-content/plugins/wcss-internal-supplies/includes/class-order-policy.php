<?php
/**
 * Store ordering policy (29 Oct Ops decision):
 *   in-policy  = inside the ordering window + within the monthly budget + within the
 *                monthly order quota (default 1 = "one and done")
 *   in-policy  → auto-approve + auto-submit to the winning vendor (child theme
 *                cc-auto-vendor-submit.php asks via the `cc_auto_vendor_submit_policy` filter)
 *   otherwise  → order is still placed, stays "awaiting approval", flagged with reasons
 *                for Supply Ops to approve or reject (limit_mode = review, default),
 *                or checkout is blocked (limit_mode = block, the previous behaviour).
 *
 * Limits are per store (store meta, editable by managers); empty store meta falls back
 * to the plugin defaults (default_monthly_budget / default_monthly_quota).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Order_Policy {

    const META = '_wcss_policy';

    public function __construct() {
        // Evaluate once the order exists, before the gateway moves it to awaiting-approval.
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'record_for_order' ], 5, 1 );
        add_filter( 'cc_auto_vendor_submit_policy', [ $this, 'auto_submit_gate' ], 10, 2 );
        add_action( 'woocommerce_before_checkout_form', [ $this, 'checkout_notice' ], 5 );
        add_action( 'rest_api_init', [ $this, 'routes' ] );
    }

    /* ---------------- settings ---------------- */

    public static function settings(): array {
        return [
            'default_monthly_budget' => (float) wcss_get_option( 'default_monthly_budget', 125 ),
            'default_monthly_quota'  => (int) wcss_get_option( 'default_monthly_quota', 1 ),
            'order_window_start'     => max( 1, min( 31, (int) wcss_get_option( 'order_window_start', 1 ) ) ),
            'order_window_end'       => max( 1, min( 31, (int) wcss_get_option( 'order_window_end', 31 ) ) ),
            'limit_mode'             => wcss_get_option( 'limit_mode', 'review' ) === 'block' ? 'block' : 'review',
            'budget_enforcement'     => (bool) wcss_get_option( 'budget_enforcement', 1 ),
        ];
    }

    public static function mode(): string {
        return self::settings()['limit_mode'];
    }

    /** Store quota/budget: store meta wins; empty meta → plugin default. 0 = unlimited. */
    public static function store_limits( int $store_id ): array {
        $s      = self::settings();
        $quota  = WCSS_Store_CPT::get_field( $store_id, 'quota' );
        $budget = WCSS_Store_CPT::get_field( $store_id, 'budget' );
        return [
            'quota'  => $quota === '' ? $s['default_monthly_quota'] : (int) $quota,
            'budget' => $s['budget_enforcement'] ? ( $budget === '' ? $s['default_monthly_budget'] : (float) $budget ) : 0.0,
        ];
    }

    public static function in_window( ?DateTimeInterface $when = null ): bool {
        $s   = self::settings();
        $day = (int) ( $when ?: new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'j' );
        return $s['order_window_start'] <= $s['order_window_end']
            ? ( $day >= $s['order_window_start'] && $day <= $s['order_window_end'] )
            : ( $day >= $s['order_window_start'] || $day <= $s['order_window_end'] ); // e.g. 25 → 5
    }

    /* ---------------- usage ---------------- */

    private static function exempt_ids(): array {
        $raw = (string) wcss_get_option( 'exempt_product_ids', '' );
        return array_filter( array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $raw ) ) ) ) );
    }

    public static function order_amount( WC_Order $order ): float {
        $exempt = self::exempt_ids();
        $t = 0.0;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( in_array( (int) $item->get_product_id(), $exempt, true ) ) continue;
            $t += (float) $item->get_total(); // goods only, excl. tax/shipping
        }
        return $t;
    }

    public static function cart_amount(): float {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return 0.0;
        $exempt = self::exempt_ids();
        $t = 0.0;
        foreach ( WC()->cart->get_cart() as $ci ) {
            if ( in_array( (int) ( $ci['product_id'] ?? 0 ), $exempt, true ) ) continue;
            $t += (float) ( $ci['line_total'] ?? 0 );
        }
        return $t;
    }

    /**
     * Orders already counted against this store's month (the counted statuses from settings)
     * placed before $when, excluding one order (the one being evaluated). Bounding by $when
     * keeps an order's verdict stable even if it is re-checked after later orders arrive.
     *
     * @return array{0:int,1:float} [ count, amount ]
     */
    public static function month_usage( int $store_id, int $exclude_order_id = 0, ?DateTimeImmutable $when = null ): array {
        $when     = $when ?: new DateTimeImmutable( 'now', wp_timezone() );
        $start    = $when->modify( 'first day of this month' )->setTime( 0, 0, 0 );
        $end      = $when;
        $statuses = array_map( function ( $s ) { return preg_replace( '/^wc-/', '', $s ); },
            (array) wcss_get_option( 'count_statuses', [ 'wc-awaiting-approval', 'wc-approved' ] ) );

        $ids = wc_get_orders([
            'type'         => 'shop_order',
            'status'       => $statuses,
            'limit'        => -1,
            'return'       => 'ids',
            'meta_query'   => [ [ 'key' => '_wcss_store_id', 'value' => $store_id ] ],
            'date_created' => $start->format( 'Y-m-d H:i:s' ) . '...' . $end->format( 'Y-m-d H:i:s' ),
        ]);
        $count = 0; $sum = 0.0;
        foreach ( $ids as $id ) {
            // Only orders placed before the one being evaluated (ids increase; dates can tie within a second).
            if ( $exclude_order_id && (int) $id >= $exclude_order_id ) continue;
            $o = wc_get_order( $id );
            if ( ! $o ) continue;
            $count++;
            $sum += self::order_amount( $o );
        }
        return [ $count, $sum ];
    }

    /* ---------------- evaluation ---------------- */

    /**
     * @return array{in_policy:bool, reasons:array<string>, messages:array<string>, checks:array<string,mixed>}
     */
    public static function evaluate( int $store_id, float $amount, int $exclude_order_id = 0, ?DateTimeImmutable $when = null ): array {
        $when    = $when ?: new DateTimeImmutable( 'now', wp_timezone() );
        $s       = self::settings();
        $reasons = [];
        $msgs    = [];

        if ( ! $store_id ) {
            return [
                'in_policy' => false,
                'reasons'   => [ 'no_store' ],
                'messages'  => [ __( 'Order is not linked to a store.', 'wcss' ) ],
                'checks'    => [],
            ];
        }

        $limits = self::store_limits( $store_id );
        [ $used_count, $used_amount ] = self::month_usage( $store_id, $exclude_order_id, $when );

        if ( ! self::in_window( $when ) ) {
            $reasons[] = 'outside_window';
            $msgs[]    = sprintf( __( 'Placed outside the ordering window (days %1$d–%2$d of the month).', 'wcss' ), $s['order_window_start'], $s['order_window_end'] );
        }
        if ( $limits['quota'] > 0 && $used_count >= $limits['quota'] ) {
            $reasons[] = 'over_quota';
            $msgs[]    = sprintf( _n( 'Store already placed %1$d order this month (limit %2$d).', 'Store already placed %1$d orders this month (limit %2$d).', $used_count, 'wcss' ), $used_count, $limits['quota'] );
        }
        if ( $limits['budget'] > 0 && $used_amount + $amount > $limits['budget'] + 0.00001 ) {
            $reasons[] = 'over_budget';
            $msgs[]    = sprintf(
                __( 'Over monthly budget: %1$s used + %2$s this order > %3$s budget.', 'wcss' ),
                html_entity_decode( wp_strip_all_tags( wc_price( $used_amount ) ) ),
                html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ) ),
                html_entity_decode( wp_strip_all_tags( wc_price( $limits['budget'] ) ) )
            );
        }

        return [
            'in_policy' => ! $reasons,
            'reasons'   => $reasons,
            'messages'  => $msgs,
            'checks'    => [
                'store_id'      => $store_id,
                'quota'         => $limits['quota'],
                'budget'        => $limits['budget'],
                'used_orders'   => $used_count,
                'used_amount'   => round( $used_amount, 2 ),
                'order_amount'  => round( $amount, 2 ),
                'window'        => [ $s['order_window_start'], $s['order_window_end'] ],
                'evaluated_at'  => $when->format( 'Y-m-d H:i' ),
            ],
        ];
    }

    public static function evaluate_order( WC_Order $order ): array {
        $created = $order->get_date_created();
        $when    = $created ? new DateTimeImmutable( '@' . $created->getTimestamp() ) : null;
        if ( $when ) $when = $when->setTimezone( wp_timezone() );
        return self::evaluate( (int) $order->get_meta( '_wcss_store_id' ), self::order_amount( $order ), (int) $order->get_id(), $when );
    }

    /** Current shopper's cart (checkout preview / block mode). */
    public static function evaluate_cart(): array {
        $store_id = class_exists( 'WCSS_User_Store_Map' ) ? (int) WCSS_User_Store_Map::get_user_store_id() : 0;
        if ( ! $store_id ) return [ 'in_policy' => true, 'reasons' => [], 'messages' => [], 'checks' => [] ];
        return self::evaluate( $store_id, self::cart_amount() );
    }

    /* ---------------- hooks ---------------- */

    public function record_for_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $res = self::evaluate_order( $order );
        $order->update_meta_data( self::META, $res );
        if ( ! $res['in_policy'] ) {
            $order->add_order_note( __( 'Needs Supply Ops approval: ', 'wcss' ) . implode( ' ', $res['messages'] ) );
        }
        $order->save();
        if ( class_exists( 'WCSS_Order_Events' ) ) {
            WCSS_Order_Events::log( (int) $order_id, 'policy', [ 'in_policy' => $res['in_policy'], 'reasons' => $res['reasons'] ] );
        }
        do_action( 'wcss_order_policy_recorded', $order, $res );
    }

    /**
     * Auto-submit asks before approving + placing with the vendor.
     * An order a manager has approved (manual override) is allowed through.
     */
    public function auto_submit_gate( $gate, $order ) {
        if ( ! $order instanceof WC_Order ) return $gate;
        if ( $order->get_meta( '_wcss_policy_override' ) ) {
            return [ 'allowed' => true, 'reasons' => [], 'messages' => [ 'Approved by Supply Ops (override).' ] ];
        }
        $res = self::evaluate_order( $order );
        $order->update_meta_data( self::META, $res );
        $order->save();
        return [ 'allowed' => $res['in_policy'], 'reasons' => $res['reasons'], 'messages' => $res['messages'] ];
    }

    /** Review mode: tell the store up front that this order will wait for Supply Ops. */
    public function checkout_notice() {
        if ( self::mode() !== 'review' || ! is_user_logged_in() ) return;
        $res = self::evaluate_cart();
        if ( $res['in_policy'] || ! $res['messages'] ) return;
        $html = '<strong>' . esc_html__( 'This order will need Supply Ops approval.', 'wcss' ) . '</strong> '
            . esc_html( implode( ' ', array_map( [ __CLASS__, 'store_facing' ], $res['reasons'] ) ) )
            . ' ' . esc_html__( 'You can still place it — it will be reviewed before it is sent.', 'wcss' );
        wc_print_notice( $html, 'notice' );
    }

    /** Short wording for stores (no internal numbers beyond what they already see). */
    public static function store_facing( string $reason ): string {
        $map = [
            'outside_window' => __( 'It is outside your ordering window.', 'wcss' ),
            'over_quota'     => __( 'Your store has already ordered this month.', 'wcss' ),
            'over_budget'    => __( 'It goes over your monthly budget.', 'wcss' ),
            'no_store'       => __( 'Your account is not linked to a store.', 'wcss' ),
        ];
        return $map[ $reason ] ?? '';
    }

    /* ---------------- REST (manager) ---------------- */

    public function can_manage(): bool {
        return current_user_can( 'wcss_manage_portal' ) || current_user_can( 'manage_options' );
    }

    public function routes() {
        register_rest_route( 'wcss/v1', '/policy', [
            [ 'methods' => 'GET', 'permission_callback' => [ $this, 'can_manage' ], 'callback' => [ $this, 'rest_get' ] ],
            [ 'methods' => 'PUT', 'permission_callback' => [ $this, 'can_manage' ], 'callback' => [ $this, 'rest_put' ] ],
        ]);
        register_rest_route( 'wcss/v1', '/stores/apply-limits', [
            'methods' => 'POST', 'permission_callback' => [ $this, 'can_manage' ], 'callback' => [ $this, 'rest_apply_limits' ],
        ]);
    }

    public function rest_get() {
        $s = self::settings();
        unset( $s['budget_enforcement'] );
        $s['in_window_today'] = self::in_window();
        return rest_ensure_response( $s );
    }

    public function rest_put( WP_REST_Request $req ) {
        $b    = $req->get_json_params() ?: [];
        $opts = get_option( 'wcss_settings', [] );
        $before = $opts;
        if ( isset( $b['default_monthly_budget'] ) ) {
            if ( ! is_numeric( $b['default_monthly_budget'] ) || $b['default_monthly_budget'] < 0 ) return new WP_Error( 'wcss_bad', 'Default budget must be a positive number.', [ 'status' => 400 ] );
            $opts['default_monthly_budget'] = round( (float) $b['default_monthly_budget'], 2 );
        }
        if ( isset( $b['default_monthly_quota'] ) ) {
            if ( ! is_numeric( $b['default_monthly_quota'] ) || $b['default_monthly_quota'] < 0 ) return new WP_Error( 'wcss_bad', 'Orders per month must be 0 or more.', [ 'status' => 400 ] );
            $opts['default_monthly_quota'] = (int) $b['default_monthly_quota'];
        }
        foreach ( [ 'order_window_start', 'order_window_end' ] as $k ) {
            if ( isset( $b[ $k ] ) ) {
                $d = (int) $b[ $k ];
                if ( $d < 1 || $d > 31 ) return new WP_Error( 'wcss_bad', 'Window days must be between 1 and 31.', [ 'status' => 400 ] );
                $opts[ $k ] = $d;
            }
        }
        if ( isset( $b['limit_mode'] ) ) {
            $opts['limit_mode'] = $b['limit_mode'] === 'block' ? 'block' : 'review';
        }
        update_option( 'wcss_settings', $opts );
        $keys = [ 'default_monthly_budget', 'default_monthly_quota', 'order_window_start', 'order_window_end', 'limit_mode' ];
        do_action( 'wcss_policy_updated', 'policy', array_intersect_key( $before, array_flip( $keys ) ), array_intersect_key( $opts, array_flip( $keys ) ) );
        return $this->rest_get();
    }

    /** B1/B2: set budget and/or quota on every store in one go (managers can still edit stores individually). */
    public function rest_apply_limits( WP_REST_Request $req ) {
        $b      = $req->get_json_params() ?: [];
        $budget = $b['budget'] ?? null;
        $quota  = $b['quota'] ?? null;
        if ( $budget === null && $quota === null ) return new WP_Error( 'wcss_bad', 'Nothing to apply.', [ 'status' => 400 ] );
        if ( $budget !== null && ( ! is_numeric( $budget ) || $budget < 0 ) ) return new WP_Error( 'wcss_bad', 'Budget must be a positive number.', [ 'status' => 400 ] );
        if ( $quota !== null && ( ! is_numeric( $quota ) || $quota < 0 ) ) return new WP_Error( 'wcss_bad', 'Orders per month must be 0 or more.', [ 'status' => 400 ] );

        $ids = get_posts( [ 'post_type' => WCSS_Store_CPT::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ] );
        foreach ( $ids as $id ) {
            if ( $budget !== null ) update_post_meta( $id, WCSS_Store_CPT::META_BUDGET, wc_format_decimal( $budget ) );
            if ( $quota !== null )  update_post_meta( $id, WCSS_Store_CPT::META_QUOTA, (int) $quota );
        }
        do_action( 'wcss_policy_updated', 'apply_all', [], array_filter( [ 'budget' => $budget, 'quota' => $quota, 'stores' => count( $ids ) ], function ( $v ) { return $v !== null; } ) );
        return rest_ensure_response( [ 'ok' => true, 'stores' => count( $ids ) ] );
    }
}
