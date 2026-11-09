<?php
/**
 * Store side of return requests (SOW 1.24): the "Returns" card on an order in My Account,
 * the request form, the status line in the orders list, and the emails the store gets.
 * The form posts back to the same page, so it works without JavaScript.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_Returns_Account {
    const NONCE = 'wcss_return_request';

    /** Errors from a submit on this request, shown above the form with the store's input kept. */
    private static $errors = [];

    public function __construct() {
        add_action( 'template_redirect', [ $this, 'handle_submit' ], 5 );
        add_action( 'woocommerce_view_order', [ $this, 'render_card' ], 20 );
        add_action( 'woocommerce_my_account_my_orders_column_order-status', [ $this, 'list_line' ], 20 );
        add_action( 'wcss_return_changed', [ $this, 'email_store' ], 10, 2 );
    }

    /* ===================== Submit ===================== */

    public function handle_submit() {
        if ( empty( $_POST['wcss_return_submit'] ) || ! is_user_logged_in() ) return;
        $order_id = absint( $_POST['wcss_return_order'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wcss_return_nonce'] ?? '' ) ), self::NONCE . $order_id ) ) {
            self::$errors[] = __( 'This form expired. Reload the page and try again.', 'wcss' );
            return;
        }
        $qty = [];
        foreach ( (array) wp_unslash( $_POST['wcss_return_qty'] ?? [] ) as $item_id => $n ) {
            $n = trim( (string) $n );
            $qty[ absint( $item_id ) ] = $n === '' ? 0 : ( ctype_digit( $n ) ? (int) $n : -1 );
        }
        $res = WCSS_Returns::create(
            $order,
            get_current_user_id(),
            sanitize_key( wp_unslash( $_POST['wcss_return_reason'] ?? '' ) ),
            (string) wp_unslash( $_POST['wcss_return_note'] ?? '' ),
            $qty
        );
        if ( is_wp_error( $res ) ) {
            self::$errors[] = $res->get_error_message();
            return;
        }
        wc_add_notice( sprintf( __( 'Return request %s sent. Supply Ops will review it and email you.', 'wcss' ), WCSS_Returns::number( (int) $res ) ), 'success' );
        wp_safe_redirect( $order->get_view_order_url() . '#wcss-returns' );
        exit;
    }

    /* ===================== Order page card ===================== */

    public function render_card( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! WCSS_Returns::user_can_request( $order, get_current_user_id() ) ) return;

        $returns = WCSS_Returns::for_order( $order->get_id() );
        $blocker = WCSS_Returns::order_blocker( $order );
        $lines   = WCSS_Returns::returnable( $order );
        $open    = array_sum( array_column( $lines, 'open' ) );
        // Nothing to show on an order that can't be returned yet and has no requests.
        if ( $blocker && ! $returns ) return;

        $posted = self::$errors && absint( $_POST['wcss_return_order'] ?? 0 ) === $order->get_id();
        $p_qty  = $posted ? (array) wp_unslash( $_POST['wcss_return_qty'] ?? [] ) : [];
        $p_rsn  = $posted ? sanitize_key( wp_unslash( $_POST['wcss_return_reason'] ?? '' ) ) : '';
        $p_note = $posted ? sanitize_textarea_field( wp_unslash( $_POST['wcss_return_note'] ?? '' ) ) : '';
        $tones  = [ 'submitted' => 'is-wait', 'approved' => 'is-ok', 'rejected' => 'is-bad', 'closed' => 'is-neutral' ];
        ?>
        <div class="wcss-acc-card wcss-acc-returns" id="wcss-returns">
          <h3><?php esc_html_e( 'Returns', 'wcss' ); ?></h3>

          <?php foreach ( $returns as $r ) : ?>
            <div class="wcss-ret">
              <div class="wcss-ret-top">
                <strong><?php echo esc_html( $r['number'] ); ?></strong>
                <span class="wcss-status <?php echo esc_attr( $tones[ $r['status'] ] ?? 'is-neutral' ); ?>"><?php echo esc_html( $r['status_label'] ); ?></span>
              </div>
              <p class="wcss-acc-muted"><?php echo esc_html( sprintf( __( '%1$s · requested %2$s', 'wcss' ), $r['reason_label'], wp_date( 'M j, Y', strtotime( $r['created_at'] ) ) ) ); ?></p>
              <ul class="wcss-ret-lines">
                <?php foreach ( $r['lines'] as $l ) : ?>
                  <li><?php echo esc_html( $l['qty'] . ' × ' . $l['name'] ); ?></li>
                <?php endforeach; ?>
              </ul>
              <?php if ( $r['reply'] !== '' ) : ?>
                <p class="wcss-ret-reply"><span><?php esc_html_e( 'Supply Ops:', 'wcss' ); ?></span> <?php echo esc_html( $r['reply'] ); ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <?php if ( $blocker ) : ?>
            <p class="wcss-acc-muted"><?php echo esc_html( $blocker ); ?></p>
          <?php elseif ( $open <= 0 ) : ?>
            <p class="wcss-acc-muted"><?php esc_html_e( 'Every item on this order is already in a return request.', 'wcss' ); ?></p>
          <?php else : ?>
            <details class="wcss-ret-form"<?php echo $posted ? ' open' : ''; ?>>
              <summary class="wcss-ret-btn"><?php esc_html_e( 'Request a return', 'wcss' ); ?></summary>
              <form method="post" action="<?php echo esc_url( $order->get_view_order_url() . '#wcss-returns' ); ?>" novalidate>
                <?php if ( $posted ) : ?>
                  <div class="wcss-ret-err" role="alert"><?php echo esc_html( implode( ' ', self::$errors ) ); ?></div>
                <?php endif; ?>
                <p class="wcss-acc-muted"><?php esc_html_e( 'Enter how many of each item you want to return. Leave the others at 0.', 'wcss' ); ?></p>
                <table class="wcss-ret-table">
                  <thead><tr><th><?php esc_html_e( 'Item', 'wcss' ); ?></th><th><?php esc_html_e( 'Ordered', 'wcss' ); ?></th><th><?php esc_html_e( 'Return', 'wcss' ); ?></th></tr></thead>
                  <tbody>
                  <?php foreach ( $lines as $item_id => $l ) : if ( $l['open'] <= 0 ) continue; ?>
                    <tr>
                      <td><label for="wcss-rq-<?php echo (int) $item_id; ?>"><?php echo esc_html( $l['name'] ); ?></label><?php if ( $l['sku'] ) : ?><span class="wcss-acc-items"><?php echo esc_html( $l['sku'] ); ?></span><?php endif; ?></td>
                      <td><?php echo (int) $l['ordered']; ?><?php if ( $l['open'] < $l['ordered'] ) : ?><span class="wcss-acc-items"><?php echo esc_html( sprintf( __( '%d left', 'wcss' ), $l['open'] ) ); ?></span><?php endif; ?></td>
                      <td><input type="number" inputmode="numeric" id="wcss-rq-<?php echo (int) $item_id; ?>" name="wcss_return_qty[<?php echo (int) $item_id; ?>]" min="0" max="<?php echo (int) $l['open']; ?>" step="1" value="<?php echo esc_attr( isset( $p_qty[ $item_id ] ) ? (string) $p_qty[ $item_id ] : '0' ); ?>"></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
                <p class="wcss-ret-field">
                  <label for="wcss-rr"><?php esc_html_e( 'Why are you returning these?', 'wcss' ); ?></label>
                  <select id="wcss-rr" name="wcss_return_reason" required>
                    <option value=""><?php esc_html_e( 'Choose a reason', 'wcss' ); ?></option>
                    <?php foreach ( WCSS_Returns::REASONS as $key => $r ) : ?>
                      <option value="<?php echo esc_attr( $key ); ?>"<?php selected( $p_rsn, $key ); ?>><?php echo esc_html( $r[0] ); ?></option>
                    <?php endforeach; ?>
                  </select>
                </p>
                <p class="wcss-ret-field">
                  <label for="wcss-rn"><?php esc_html_e( 'What happened?', 'wcss' ); ?> <span class="wcss-acc-muted"><?php esc_html_e( '(needed for damaged, wrong item or other)', 'wcss' ); ?></span></label>
                  <textarea id="wcss-rn" name="wcss_return_note" rows="3" maxlength="<?php echo (int) WCSS_Returns::NOTE_MAX; ?>"><?php echo esc_textarea( $p_note ); ?></textarea>
                </p>
                <input type="hidden" name="wcss_return_order" value="<?php echo (int) $order->get_id(); ?>">
                <?php wp_nonce_field( self::NONCE . $order->get_id(), '_wcss_return_nonce' ); ?>
                <button type="submit" name="wcss_return_submit" value="1" class="wcss-ret-btn is-primary"><?php esc_html_e( 'Send return request', 'wcss' ); ?></button>
              </form>
            </details>
          <?php endif; ?>
        </div>
        <?php
    }

    /** Orders list: "Return waiting for review" under the status when the order has a request. */
    public function list_line( $order ) {
        if ( ! $order instanceof WC_Order ) return;
        $r = WCSS_Returns::for_order( $order->get_id() );
        if ( ! $r ) return;
        echo '<span class="wcss-acc-items">' . esc_html( sprintf( __( 'Return: %s', 'wcss' ), strtolower( $r[0]['status_label'] ) ) ) . '</span>';
    }

    /* ===================== Store emails ===================== */

    /** One email to the person who asked, at every step. */
    public function email_store( array $ret, string $old ) {
        $to   = $ret['requested_by']['email'];
        if ( ! is_email( $to ) ) return;
        $order = wc_get_order( $ret['order_id'] );
        $url   = $order ? $order->get_view_order_url() . '#wcss-returns' : wc_get_account_endpoint_url( 'orders' );
        $items = array_map( function ( $l ) {
            return [ 'name' => $l['name'], 'qty' => (int) $l['qty'], 'sku' => (string) ( $l['sku'] ?? '' ) ];
        }, $ret['lines'] );
        $details = [
            [ 'Return', $ret['number'] ],
            [ 'Order', '#' . $ret['order_number'] ],
            [ 'Store', $ret['store']['name'] ?: '—' ],
            [ 'Reason', $ret['reason_label'] ],
        ];
        $base = [
            'eyebrow' => 'Return request',
            'details' => $details,
            'items'   => $items,
            'action'  => [ 'label' => 'View your order', 'url' => $url ],
            'reason'  => __( 'You get this because you asked to return items from this order.', 'wcss' ),
        ];
        switch ( $ret['status'] ) {
            case 'submitted':
                $subject = sprintf( 'We received return request %s for order #%s', $ret['number'], $ret['order_number'] );
                $a = [
                    'title'  => sprintf( 'Return request %s received', $ret['number'] ),
                    'status' => [ 'label' => 'Waiting for review', 'tone' => 'warn' ],
                    'intro'  => __( 'Thanks — Supply Ops will review your request and email you with the next steps. Please keep the items until then.', 'wcss' ),
                ];
                if ( $ret['note'] !== '' ) $a['note'] = [ 'title' => 'What you told us', 'lines' => [ $ret['note'] ], 'tone' => 'info' ];
                break;
            case 'approved':
                $subject = sprintf( 'Return %s approved', $ret['number'] );
                $a = [
                    'title'  => sprintf( 'Return %s approved', $ret['number'] ),
                    'status' => [ 'label' => 'Approved', 'tone' => 'ok' ],
                    'intro'  => __( 'Supply Ops approved your return. Follow their instructions below to send the items back.', 'wcss' ),
                ];
                if ( $ret['reply'] !== '' ) $a['note'] = [ 'title' => 'Instructions from Supply Ops', 'lines' => [ $ret['reply'] ], 'tone' => 'ok' ];
                break;
            case 'rejected':
                $subject = sprintf( 'Return %s was not approved', $ret['number'] );
                $a = [
                    'title'  => sprintf( 'Return %s was not approved', $ret['number'] ),
                    'status' => [ 'label' => 'Rejected', 'tone' => 'bad' ],
                    'intro'  => __( 'Supply Ops could not approve this return. Their reason is below. Contact Supply Ops if you have questions.', 'wcss' ),
                    'note'   => [ 'title' => 'Reason', 'lines' => [ $ret['reply'] ], 'tone' => 'bad' ],
                ];
                break;
            case 'closed':
                $subject = sprintf( 'Return %s is complete', $ret['number'] );
                $a = [
                    'title'  => sprintf( 'Return %s is complete', $ret['number'] ),
                    'status' => [ 'label' => 'Closed', 'tone' => 'neutral' ],
                    'intro'  => __( 'Supply Ops has finished this return with the supplier.', 'wcss' ),
                ];
                if ( $ret['reply'] !== '' ) $a['note'] = [ 'title' => 'Note from Supply Ops', 'lines' => [ $ret['reply'] ], 'tone' => 'info' ];
                break;
            default:
                return;
        }
        WCSS_Email_Center::send( $to, '[Internal Supply] ' . $subject, WCSS_Email_Template::render( $base + $a ), 'wcss_store_return' );
    }
}
