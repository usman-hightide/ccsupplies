<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * One-line monthly limit banner on the cart.
 *
 * Uses WCSS_Order_Policy (same rules that decide approval), so the banner and
 * the approval decision always agree.
 */
class WCSS_Quota_Usage_UI {

    public function __construct() {
        // Cart only — checkout layout already shows policy notices.
        add_action( 'woocommerce_before_cart', [ $this, 'render_banner' ], 5 );
    }

    public function render_banner() {
        if ( ! is_user_logged_in() ) return;

        $store_id = WCSS_User_Store_Map::get_user_store_id();
        if ( ! $store_id ) return;

        $res    = WCSS_Order_Policy::evaluate_cart();
        $c      = $res['checks'];
        $review = WCSS_Order_Policy::mode( (int) $store_id ) === 'review';
        $money  = function ( $v ) { return html_entity_decode( wp_strip_all_tags( wc_price( $v ) ) ); };
        $left   = $c['budget'] > 0 ? max( 0.0, $c['budget'] - $c['used_amount'] ) : 0.0;
        $next   = $review ? __( 'Your next order will need Supply Ops approval.', 'wcss' ) : __( 'New orders can’t be placed right now.', 'wcss' );

        if ( in_array( 'over_quota', $res['reasons'], true ) ) {
            $tone = 'warn';
            $text = __( 'You have reached your monthly order limit.', 'wcss' ) . ' ' . $next;
        } elseif ( in_array( 'over_budget', $res['reasons'], true ) ) {
            $tone = 'warn';
            /* translators: %s: remaining budget */
            $text = sprintf( __( 'This cart is over your monthly budget (%s left).', 'wcss' ), $money( $left ) ) . ' '
                . ( $review ? __( 'It will need Supply Ops approval.', 'wcss' ) : __( 'Remove items to continue.', 'wcss' ) );
        } elseif ( in_array( 'outside_window', $res['reasons'], true ) ) {
            $tone = 'warn';
            /* translators: %s: window, e.g. "days 7–14 of every month" */
            $text = sprintf( __( 'Ordering is open %s.', 'wcss' ), $c['window_label'] ?? WCSS_Order_Policy::window_label() ) . ' ' . $next;
        } else {
            $tone  = 'ok';
            $parts = [];
            if ( $c['budget'] > 0 ) {
                /* translators: 1: remaining budget, 2: monthly budget */
                $parts[] = sprintf( __( '%1$s of your %2$s monthly budget left', 'wcss' ), $money( $left ), $money( $c['budget'] ) );
            }
            if ( $c['quota'] > 0 ) {
                /* translators: 1: orders used, 2: order limit */
                $parts[] = sprintf( __( '%1$d of %2$d orders used this month', 'wcss' ), $c['used_orders'], $c['quota'] );
            }
            if ( ! $parts ) return;
            $text = implode( ' · ', $parts );
        }
        ?>
        <div class="wcss-usage wcss-usage--line is-<?php echo esc_attr( $tone ); ?>" role="status">
            <span class="wcss-usage__icon" aria-hidden="true">
                <?php if ( $tone === 'warn' ) : ?>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.01"/></svg>
                <?php else : ?>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.5v.01"/></svg>
                <?php endif; ?>
            </span>
            <span class="wcss-usage__text"><?php echo esc_html( $text ); ?></span>
        </div>
        <?php
    }
}
