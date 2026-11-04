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
        // Cart → "Proceed to checkout": the theme reads this marker and asks the store to confirm first.
        add_action( 'woocommerce_proceed_to_checkout', [ $this, 'cart_policy_flag' ], 1 );
        add_filter( 'woocommerce_thankyou_order_received_text', [ $this, 'thankyou_text' ], 20, 2 );
        add_action( 'rest_api_init', [ $this, 'routes' ] );
    }

    /* ---------------- settings ---------------- */

    public static function settings(): array {
        return [
            'default_monthly_budget' => (float) wcss_get_option( 'default_monthly_budget', 125 ),
            'default_monthly_quota'  => (int) wcss_get_option( 'default_monthly_quota', 1 ),
            'order_window_start'     => max( 1, min( 31, (int) wcss_get_option( 'order_window_start', 1 ) ) ),
            'order_window_end'       => max( 1, min( 31, (int) wcss_get_option( 'order_window_end', 31 ) ) ),
            // Date window (Supply Manager → Ordering policy). Empty = use the day window above.
            'order_window_from'      => self::ymd( (string) wcss_get_option( 'order_window_from', '' ) ),
            'order_window_to'        => self::ymd( (string) wcss_get_option( 'order_window_to', '' ) ),
            'order_window_repeat'    => (bool) wcss_get_option( 'order_window_repeat', 1 ),
            'limit_mode'             => wcss_get_option( 'limit_mode', 'review' ) === 'block' ? 'block' : 'review',
            'budget_enforcement'     => (bool) wcss_get_option( 'budget_enforcement', 1 ),
        ];
    }

    /** Per-store overrides (Edit store page). Empty value = follow the ordering policy. */
    const STORE_WINDOW_FROM   = '_wcss_window_from';
    const STORE_WINDOW_TO     = '_wcss_window_to';
    const STORE_WINDOW_REPEAT = '_wcss_window_repeat';
    const STORE_LIMIT_MODE    = '_wcss_limit_mode';

    /** Rule for orders outside the policy; a store can override the policy's rule. */
    public static function mode( int $store_id = 0 ): string {
        if ( $store_id ) {
            $m = (string) get_post_meta( $store_id, self::STORE_LIMIT_MODE, true );
            if ( $m === 'review' || $m === 'block' ) return $m;
        }
        return self::settings()['limit_mode'];
    }

    /** Store of the logged-in store employee (cart / checkout). */
    public static function cart_store_id(): int {
        return class_exists( 'WCSS_User_Store_Map' ) ? (int) WCSS_User_Store_Map::get_user_store_id() : 0;
    }

    /**
     * Effective ordering window for a store: its own dates when set, else the policy's.
     * @return array{from:string,to:string,repeat:bool,start:int,end:int,source:string}
     */
    public static function window_config( int $store_id = 0 ): array {
        $s = self::settings();
        if ( $store_id ) {
            $from = self::ymd( (string) get_post_meta( $store_id, self::STORE_WINDOW_FROM, true ) );
            $to   = self::ymd( (string) get_post_meta( $store_id, self::STORE_WINDOW_TO, true ) );
            if ( $from !== '' && $to !== '' ) {
                $rep = get_post_meta( $store_id, self::STORE_WINDOW_REPEAT, true );
                return [ 'from' => $from, 'to' => $to, 'repeat' => $rep === '' ? true : (bool) $rep,
                    'start' => (int) substr( $from, 8, 2 ), 'end' => (int) substr( $to, 8, 2 ), 'source' => 'store' ];
            }
        }
        $dates = $s['order_window_from'] !== '' && $s['order_window_to'] !== '';
        return [
            'from'   => $dates ? $s['order_window_from'] : '',
            'to'     => $dates ? $s['order_window_to'] : '',
            'repeat' => $dates ? (bool) $s['order_window_repeat'] : true,
            'start'  => $dates ? (int) substr( $s['order_window_from'], 8, 2 ) : $s['order_window_start'],
            'end'    => $dates ? (int) substr( $s['order_window_to'], 8, 2 ) : $s['order_window_end'],
            'source' => 'policy',
        ];
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

    private static function ymd( string $v ): string {
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
    }

    /** True when the (store's or policy's) window uses dates instead of day numbers. */
    public static function uses_dates( int $store_id = 0 ): bool {
        return self::window_config( $store_id )['from'] !== '';
    }

    /**
     * Dates set + repeat  → same days every month (from's day → to's day, may wrap the month end).
     * Dates set, no repeat → only between the two dates (inclusive).
     * No dates            → day-of-month window (order_window_start / _end).
     */
    public static function in_window( ?DateTimeInterface $when = null, int $store_id = 0 ): bool {
        $w    = self::window_config( $store_id );
        $when = $when ?: new DateTimeImmutable( 'now', wp_timezone() );
        if ( $w['from'] !== '' && ! $w['repeat'] ) {
            $d = $when->format( 'Y-m-d' );
            return $d >= $w['from'] && $d <= $w['to'];
        }
        $day = (int) $when->format( 'j' );
        return $w['start'] <= $w['end']
            ? ( $day >= $w['start'] && $day <= $w['end'] )
            : ( $day >= $w['start'] || $day <= $w['end'] ); // e.g. 25 → 5
    }

    /** Plain description of the window, e.g. "7–14 of every month" or "7 Nov 2026 – 14 Nov 2026". */
    public static function window_label( int $store_id = 0 ): string {
        $w = self::window_config( $store_id );
        if ( $w['from'] !== '' && ! $w['repeat'] ) {
            return date_i18n( 'j M Y', strtotime( $w['from'] ) ) . ' – ' . date_i18n( 'j M Y', strtotime( $w['to'] ) );
        }
        [ $a, $b ] = [ $w['start'], $w['end'] ];
        if ( $a === 1 && $b === 31 ) return __( 'all month', 'wcss' );
        /* translators: 1: first day, 2: last day */
        return sprintf( __( 'days %1$d–%2$d of every month', 'wcss' ), $a, $b );
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

        if ( ! self::in_window( $when, $store_id ) ) {
            $reasons[] = 'outside_window';
            /* translators: %s: window, e.g. "days 7–14 of every month" */
            $msgs[]    = sprintf( __( 'Placed outside the ordering window (%s).', 'wcss' ), self::window_label( $store_id ) );
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
                'window_label'  => self::window_label( $store_id ),
                'mode'          => self::mode( $store_id ),
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
        if ( ! is_user_logged_in() || self::mode( self::cart_store_id() ) !== 'review' ) return;
        $res = self::evaluate_cart();
        if ( $res['in_policy'] || ! $res['messages'] ) return;
        $lines = array_values( array_filter( array_map( [ __CLASS__, 'store_facing' ], $res['reasons'] ) ) );
        ?>
        <section class="cc-approval-alert" role="status" aria-labelledby="cc-approval-title">
            <span class="cc-approval-alert__icon" aria-hidden="true">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.01"/></svg>
            </span>
            <div class="cc-approval-alert__body">
                <p class="cc-approval-alert__eyebrow"><?php esc_html_e( 'Approval required', 'wcss' ); ?></p>
                <h2 class="cc-approval-alert__title" id="cc-approval-title"><?php esc_html_e( 'This order will need Supply Ops approval', 'wcss' ); ?></h2>
                <?php if ( $lines ) : ?>
                <ul class="cc-approval-alert__reasons">
                    <?php foreach ( $lines as $l ) : ?><li><?php echo esc_html( $l ); ?></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <p class="cc-approval-alert__text"><?php esc_html_e( 'You can still place it. Nothing is sent to the supplier until Supply Ops approves it.', 'wcss' ); ?></p>
                <ol class="cc-approval-alert__steps" aria-label="<?php esc_attr_e( 'What happens next', 'wcss' ); ?>">
                    <li class="is-now"><span>1</span><?php esc_html_e( 'You place the order', 'wcss' ); ?></li>
                    <li><span>2</span><?php esc_html_e( 'Supply Ops reviews it', 'wcss' ); ?></li>
                    <li><span>3</span><?php esc_html_e( 'Approved orders go to the supplier — we email you', 'wcss' ); ?></li>
                </ol>
            </div>
            <a class="cc-approval-alert__link" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'Review cart', 'wcss' ); ?></a>
        </section>
        <?php
    }

    /**
     * Hidden marker next to the checkout button. It sits inside the cart totals, which Woo
     * re-renders after every cart change, so it always matches the current cart.
     */
    public function cart_policy_flag() {
        if ( ! is_user_logged_in() || self::mode( self::cart_store_id() ) !== 'review' ) return;
        $res = self::evaluate_cart();
        printf(
            '<span class="cc-policy-flag" hidden data-in-policy="%1$s" data-reasons="%2$s" data-lines="%3$s"></span>',
            $res['in_policy'] ? '1' : '0',
            esc_attr( implode( ',', $res['reasons'] ) ),
            esc_attr( wp_json_encode( array_values( array_filter( array_map( [ __CLASS__, 'store_facing' ], $res['reasons'] ) ) ) ) )
        );
    }

    /** Thank-you page: say plainly whether the order went for approval or to the supplier. */
    public function thankyou_text( $text, $order ) {
        if ( ! $order instanceof WC_Order ) return $text;
        $res = $order->get_meta( self::META );
        if ( ! is_array( $res ) || ! isset( $res['in_policy'] ) ) return $text;
        if ( empty( $res['in_policy'] ) && ! $order->get_meta( '_wcss_policy_override' ) ) {
            return esc_html__( 'Thank you. Your order was sent to Supply Ops for approval. Once it is approved, it will be placed with the supplier — we will email you.', 'wcss' );
        }
        return esc_html__( 'Thank you. Your order is approved and is being placed with the supplier.', 'wcss' );
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
        register_rest_route( 'wcss/v1', '/stores/(?P<id>\d+)/policy', [
            [ 'methods' => 'GET', 'permission_callback' => [ $this, 'can_manage' ], 'callback' => [ $this, 'rest_store_get' ] ],
            [ 'methods' => 'PUT', 'permission_callback' => [ $this, 'can_manage' ], 'callback' => [ $this, 'rest_store_put' ] ],
        ]);
    }

    /* ---------------- per-store policy (Edit store page) ---------------- */

    private function store_or_error( int $id ) {
        $p = get_post( $id );
        return ( $p && $p->post_type === WCSS_Store_CPT::POST_TYPE ) ? $p : new WP_Error( 'not_found', 'Store not found.', [ 'status' => 404 ] );
    }

    public function rest_store_get( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $p  = $this->store_or_error( $id );
        if ( is_wp_error( $p ) ) return $p;
        $s      = self::settings();
        $quota  = WCSS_Store_CPT::get_field( $id, 'quota' );
        $budget = WCSS_Store_CPT::get_field( $id, 'budget' );
        $lim    = self::store_limits( $id );
        $win    = self::window_config( $id );
        [ $used_n, $used_amt ] = self::month_usage( $id );
        return rest_ensure_response( [
            'store'     => [
                'budget'        => $budget === '' ? '' : (float) $budget,
                'quota'         => $quota === '' ? '' : (int) $quota,
                'window_from'   => self::ymd( (string) get_post_meta( $id, self::STORE_WINDOW_FROM, true ) ),
                'window_to'     => self::ymd( (string) get_post_meta( $id, self::STORE_WINDOW_TO, true ) ),
                'window_repeat' => get_post_meta( $id, self::STORE_WINDOW_REPEAT, true ) === '' ? true : (bool) get_post_meta( $id, self::STORE_WINDOW_REPEAT, true ),
                'limit_mode'    => (string) get_post_meta( $id, self::STORE_LIMIT_MODE, true ),
            ],
            'defaults'  => [
                'budget' => $s['default_monthly_budget'], 'quota' => $s['default_monthly_quota'],
                'window_label' => self::window_label( 0 ), 'mode' => $s['limit_mode'],
            ],
            'effective' => [
                'budget' => $lim['budget'], 'quota' => $lim['quota'], 'window_label' => self::window_label( $id ),
                'window_source' => $win['source'], 'mode' => self::mode( $id ), 'in_window_today' => self::in_window( null, $id ),
            ],
            'usage'     => [ 'orders' => (int) $used_n, 'spent' => round( (float) $used_amt, 2 ) ],
            'today'     => wp_date( 'Y-m-d' ),
        ] );
    }

    /**
     * Body: use_default_limits, budget, quota, use_default_window, window_from, window_to,
     * window_repeat, limit_mode ('' = follow the policy). Only keys sent are changed.
     */
    public function rest_store_put( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $p  = $this->store_or_error( $id );
        if ( is_wp_error( $p ) ) return $p;
        $b      = $req->get_json_params() ?: [];
        $before = $this->rest_store_get( $req )->get_data()['effective'];

        if ( array_key_exists( 'use_default_limits', $b ) ) {
            if ( ! empty( $b['use_default_limits'] ) ) {
                foreach ( [ WCSS_Store_CPT::META_QUOTA, WCSS_Store_CPT::META_BUDGET, '_store_quota', '_store_budget' ] as $k ) delete_post_meta( $id, $k );
            } else {
                $budget = $b['budget'] ?? '';
                $quota  = $b['quota'] ?? '';
                if ( $budget === '' || ! is_numeric( $budget ) || $budget < 0 ) return new WP_Error( 'wcss_bad', 'Monthly budget must be 0 or more.', [ 'status' => 400 ] );
                if ( $quota === '' || ! is_numeric( $quota ) || $quota < 0 || (int) $quota != $quota ) return new WP_Error( 'wcss_bad', 'Orders per month must be a whole number, 0 or more.', [ 'status' => 400 ] );
                update_post_meta( $id, WCSS_Store_CPT::META_BUDGET, wc_format_decimal( $budget ) );
                update_post_meta( $id, WCSS_Store_CPT::META_QUOTA, (int) $quota );
                delete_post_meta( $id, '_store_quota' );
                delete_post_meta( $id, '_store_budget' );
            }
        }
        if ( array_key_exists( 'use_default_window', $b ) ) {
            if ( ! empty( $b['use_default_window'] ) ) {
                foreach ( [ self::STORE_WINDOW_FROM, self::STORE_WINDOW_TO, self::STORE_WINDOW_REPEAT ] as $k ) delete_post_meta( $id, $k );
            } else {
                $from = self::ymd( (string) ( $b['window_from'] ?? '' ) );
                $to   = self::ymd( (string) ( $b['window_to'] ?? '' ) );
                if ( $from === '' || $to === '' ) return new WP_Error( 'wcss_bad', 'Choose both an opening date and a closing date for this store.', [ 'status' => 400 ] );
                if ( $to < $from ) return new WP_Error( 'wcss_bad', 'The closing date must be on or after the opening date.', [ 'status' => 400 ] );
                update_post_meta( $id, self::STORE_WINDOW_FROM, $from );
                update_post_meta( $id, self::STORE_WINDOW_TO, $to );
                update_post_meta( $id, self::STORE_WINDOW_REPEAT, ! empty( $b['window_repeat'] ) ? 1 : 0 );
            }
        }
        if ( array_key_exists( 'limit_mode', $b ) ) {
            $m = (string) $b['limit_mode'];
            if ( $m === 'review' || $m === 'block' ) update_post_meta( $id, self::STORE_LIMIT_MODE, $m );
            else delete_post_meta( $id, self::STORE_LIMIT_MODE );
        }

        $res   = $this->rest_store_get( $req );
        $after = $res->get_data()['effective'];
        $keys  = [ 'budget', 'quota', 'window_label', 'mode' ];
        do_action( 'wcss_policy_updated', 'store', array_intersect_key( $before, array_flip( $keys ) ) + [ 'store' => $p->post_title ], array_intersect_key( $after, array_flip( $keys ) ) + [ 'store' => $p->post_title ] );
        return $res;
    }

    public function rest_get() {
        $s = self::settings();
        unset( $s['budget_enforcement'] );
        $s['in_window_today'] = self::in_window();
        $s['window_label']    = self::window_label();
        $s['today']           = wp_date( 'Y-m-d' );
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
        if ( array_key_exists( 'order_window_from', $b ) || array_key_exists( 'order_window_to', $b ) ) {
            $from = (string) ( $b['order_window_from'] ?? '' );
            $to   = (string) ( $b['order_window_to'] ?? '' );
            if ( ( $from === '' ) !== ( $to === '' ) ) return new WP_Error( 'wcss_bad', 'Choose both a start date and an end date, or leave both empty.', [ 'status' => 400 ] );
            if ( $from !== '' && ( self::ymd( $from ) === '' || self::ymd( $to ) === '' ) ) return new WP_Error( 'wcss_bad', 'Dates must be valid (YYYY-MM-DD).', [ 'status' => 400 ] );
            if ( $from !== '' && $to < $from ) return new WP_Error( 'wcss_bad', 'The end date must be on or after the start date.', [ 'status' => 400 ] );
            $opts['order_window_from'] = $from;
            $opts['order_window_to']   = $to;
            if ( $from !== '' ) {
                // Keep the day window in step, so older screens and messages show the same days.
                $opts['order_window_start'] = (int) substr( $from, 8, 2 );
                $opts['order_window_end']   = (int) substr( $to, 8, 2 );
            } elseif ( ! isset( $b['order_window_start'] ) ) {
                $opts['order_window_start'] = 1; // "Open all month"
                $opts['order_window_end']   = 31;
            }
        }
        if ( isset( $b['order_window_repeat'] ) ) {
            $opts['order_window_repeat'] = $b['order_window_repeat'] ? 1 : 0;
        }
        if ( isset( $b['limit_mode'] ) ) {
            $opts['limit_mode'] = $b['limit_mode'] === 'block' ? 'block' : 'review';
        }
        update_option( 'wcss_settings', $opts );
        $keys = [ 'default_monthly_budget', 'default_monthly_quota', 'order_window_start', 'order_window_end', 'order_window_from', 'order_window_to', 'order_window_repeat', 'limit_mode' ];
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
        // Optional store_ids: only those stores (picked in the Ordering policy pop-up). Missing = every store.
        if ( isset( $b['store_ids'] ) ) {
            $want = array_values( array_unique( array_filter( array_map( 'absint', (array) $b['store_ids'] ) ) ) );
            if ( ! $want ) return new WP_Error( 'wcss_bad', 'Pick at least one store.', [ 'status' => 400 ] );
            $ids = array_values( array_intersect( array_map( 'intval', $ids ), $want ) );
            if ( count( $ids ) !== count( $want ) ) return new WP_Error( 'wcss_bad', 'Some selected stores no longer exist. Reload and try again.', [ 'status' => 400 ] );
        }
        foreach ( $ids as $id ) {
            if ( $budget !== null ) update_post_meta( $id, WCSS_Store_CPT::META_BUDGET, wc_format_decimal( $budget ) );
            if ( $quota !== null )  update_post_meta( $id, WCSS_Store_CPT::META_QUOTA, (int) $quota );
        }
        do_action( 'wcss_policy_updated', isset( $b['store_ids'] ) ? 'apply_some' : 'apply_all', [], array_filter( [ 'budget' => $budget, 'quota' => $quota, 'stores' => count( $ids ) ], function ( $v ) { return $v !== null; } ) );
        return rest_ensure_response( [ 'ok' => true, 'stores' => count( $ids ) ] );
    }
}
