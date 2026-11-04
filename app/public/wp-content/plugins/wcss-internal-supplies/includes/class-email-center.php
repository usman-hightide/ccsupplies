<?php
/**
 * Email Management: who gets which Supply Ops notification, plus manual sending.
 *
 * Recipients live in the option `wcss_email_recipients`:
 *   [ [ 'email' => 'a@b.c', 'name' => 'Alyshia', 'active' => true, 'events' => [ 'order_approved', … ] ], … ]
 * Each event below is sent only to the active recipients that ticked it.
 * Store employee and vendor emails are not configured here (see WCSS_Notifications).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_Email_Center {

    const OPTION = 'wcss_email_recipients';
    const MAX_RECIPIENTS = 50;

    /** Event key => [ group, label, description ]. */
    public static function events(): array {
        return [
            'order_new'            => [ 'Orders',  'New order placed',            'A store places an order.' ],
            'order_needs_approval' => [ 'Orders',  'Order needs approval',        'Over budget, outside the window or a second order this month.' ],
            'order_approved'       => [ 'Orders',  'Order approved',              'Approved automatically or by Supply Ops.' ],
            'order_rejected'       => [ 'Orders',  'Order rejected',              'Supply Ops rejected an order.' ],
            'order_reopened'       => [ 'Orders',  'Order moved back to pending', 'Supply Ops reopened an approved or rejected order.' ],
            'vendor_submitted'     => [ 'Vendors', 'Vendor approved order',       'Amazon or Grand & Toy accepted the order.' ],
            'vendor_failed'        => [ 'Vendors', 'Vendor rejected order',       'The vendor rejected the order, or sending it failed.' ],
            'vendor_changed'       => [ 'Vendors', 'Vendor details changed',      'A vendor is added, edited, removed or its budget changes.' ],
            'policy_changed'       => [ 'System',  'Ordering policy changed',     'Budget, orders per month or ordering window changes.' ],
        ];
    }

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'routes' ] );

        add_action( 'woocommerce_new_order', [ $this, 'on_new_order' ], 20, 2 );
        add_action( 'wcss_order_policy_recorded', [ $this, 'on_policy_recorded' ], 10, 2 );
        add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 20, 4 );
        add_action( 'cc_auto_vendor_submit_done', [ $this, 'on_vendor_result' ], 10, 2 );
        add_action( 'wcss_vendor_changed', [ $this, 'on_vendor_changed' ], 10, 4 );
        add_action( 'wcss_policy_updated', [ $this, 'on_policy_updated' ], 10, 3 );
    }

    /* ===================== Recipients ===================== */

    /**
     * Saved recipients. The first time, seed from today's behaviour (every shop manager
     * got every order email) so nothing silently stops until a manager edits the list.
     */
    public static function recipients(): array {
        $list = get_option( self::OPTION, null );
        if ( is_array( $list ) ) {
            // Drop notification types that no longer exist (e.g. lists saved before an event was retired).
            $valid = array_keys( self::events() );
            foreach ( $list as &$r ) {
                $r['events'] = array_values( array_intersect( (array) ( $r['events'] ?? [] ), $valid ) );
            }
            return $list;
        }

        $seed   = [];
        $events = [ 'order_new', 'order_needs_approval', 'order_approved', 'order_rejected', 'order_reopened', 'vendor_failed' ];
        foreach ( get_users( [ 'role' => 'shop_manager' ] ) as $u ) {
            if ( is_email( $u->user_email ) ) {
                $seed[ strtolower( $u->user_email ) ] = [ 'email' => $u->user_email, 'name' => $u->display_name, 'active' => true, 'events' => $events ];
            }
        }
        $opts = get_option( 'wcss_settings', [] );
        foreach ( preg_split( '/[,\s]+/', (string) ( $opts['operations_email'] ?? '' ) ) as $e ) {
            $e = sanitize_email( $e );
            if ( is_email( $e ) && ! isset( $seed[ strtolower( $e ) ] ) ) {
                $seed[ strtolower( $e ) ] = [ 'email' => $e, 'name' => 'Operations', 'active' => true, 'events' => [ 'order_rejected' ] ];
            }
        }
        $seed = array_values( $seed );
        update_option( self::OPTION, $seed, false );
        return $seed;
    }

    /** @return array|WP_Error Clean list, or an error naming the first bad row. */
    public static function clean_recipients( $rows ) {
        if ( ! is_array( $rows ) ) return new WP_Error( 'wcss_bad', 'Recipients must be a list.', [ 'status' => 400 ] );
        if ( count( $rows ) > self::MAX_RECIPIENTS ) {
            return new WP_Error( 'wcss_bad', sprintf( 'You can add up to %d email addresses.', self::MAX_RECIPIENTS ), [ 'status' => 400 ] );
        }
        $valid = array_keys( self::events() );
        $out   = [];
        foreach ( $rows as $r ) {
            $email = sanitize_email( trim( (string) ( $r['email'] ?? '' ) ) );
            if ( ! is_email( $email ) ) {
                return new WP_Error( 'wcss_bad_email', sprintf( '“%s” is not a valid email address.', (string) ( $r['email'] ?? '' ) ), [ 'status' => 400 ] );
            }
            $key = strtolower( $email );
            if ( isset( $out[ $key ] ) ) {
                return new WP_Error( 'wcss_dup_email', sprintf( '%s is listed twice.', $email ), [ 'status' => 400 ] );
            }
            $out[ $key ] = [
                'email'  => $email,
                'name'   => sanitize_text_field( (string) ( $r['name'] ?? '' ) ),
                'active' => ! isset( $r['active'] ) || (bool) $r['active'],
                'events' => array_values( array_intersect( $valid, (array) ( $r['events'] ?? [] ) ) ),
            ];
        }
        return array_values( $out );
    }

    public static function recipients_for( string $event ): array {
        return array_values( array_filter( self::recipients(), function ( $r ) use ( $event ) {
            return ! empty( $r['active'] ) && in_array( $event, (array) $r['events'], true );
        } ) );
    }

    /* ===================== Sending ===================== */

    /** Send one event to everyone subscribed to it. Returns how many were sent. */
    public static function notify( string $event, array $ctx ): int {
        $to = self::recipients_for( $event );
        if ( ! $to ) return 0;
        $mail = self::build( $event, $ctx );
        if ( ! $mail ) return 0;

        $label = self::events()[ $event ][1];
        $sent  = 0;
        foreach ( $to as $r ) {
            $args = $mail['args'] + [
                'reason'      => sprintf( 'You get this because %s is set to receive “%s” in Supply Manager → Emails.', $r['email'], $label ),
                'manage_link' => true,
            ];
            $html = WCSS_Email_Template::render( $args );
            if ( self::defer_ok() ) {
                self::$queue[] = [ $r['email'], $mail['subject'], $html, 'wcss_event_' . $event ];
                $sent++;
            } elseif ( self::send( $r['email'], $mail['subject'], $html, 'wcss_event_' . $event )['ok'] ) {
                $sent++;
            }
        }
        return $sent;
    }

    /** @var array Notifications waiting to go out after the response (see flush_queue). */
    private static $queue = [];

    /**
     * In a browser request, notifications are sent after the response is returned, so
     * saving a policy or approving an order doesn't wait on the mail server.
     * CLI (tests, cron) sends straight away.
     */
    private static function defer_ok(): bool {
        static $hooked = false;
        if ( PHP_SAPI === 'cli' || ! apply_filters( 'wcss_email_defer', true ) ) return false;
        if ( ! $hooked ) {
            add_action( 'shutdown', [ __CLASS__, 'flush_queue' ], 1 );
            $hooked = true;
        }
        return true;
    }

    public static function flush_queue() {
        if ( ! self::$queue ) return;
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request(); // response is already with the browser
        }
        ignore_user_abort( true );
        $q = self::$queue;
        self::$queue = [];
        foreach ( $q as $m ) {
            self::send( $m[0], $m[1], $m[2], $m[3] );
        }
    }

    /** @return array{ok:bool,error:string} */
    public static function send( string $to, string $subject, string $html, string $context ): array {
        $error = '';
        $catch = function ( $e ) use ( &$error ) { $error = $e instanceof WP_Error ? $e->get_error_message() : 'Unknown mail error'; };
        add_action( 'wp_mail_failed', $catch );
        $ok = wp_mail( $to, $subject, $html, [ 'Content-Type: text/html; charset=UTF-8', 'X-WCSS-Context: ' . $context ] );
        remove_action( 'wp_mail_failed', $catch );
        return [ 'ok' => (bool) $ok, 'error' => $ok ? '' : ( $error ?: 'The mail server did not accept the message.' ) ];
    }

    /* ===================== Triggers ===================== */

    public function on_new_order( $order_id, $order = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( $order ) self::notify( 'order_new', [ 'order' => $order ] );
    }

    public function on_policy_recorded( $order, $res ) {
        if ( $order instanceof WC_Order && empty( $res['in_policy'] ) ) {
            self::notify( 'order_needs_approval', [ 'order' => $order, 'policy' => $res ] );
        }
    }

    public function on_status_changed( $order_id, $old, $new, $order = null ) {
        $map = [ 'approved' => 'order_approved', 'rejected' => 'order_rejected' ];
        if ( $new === 'pending' && in_array( $old, [ 'approved', 'rejected' ], true ) ) {
            $map['pending'] = 'order_reopened';
        }
        if ( ! isset( $map[ $new ] ) ) return;
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( $order ) self::notify( $map[ $new ], [ 'order' => $order, 'old' => $old ] );
    }

    public function on_vendor_result( $order_id, $summary ) {
        $status = (string) ( $summary['status'] ?? '' );
        $order  = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( $status === 'submitted' ) {
            self::notify( 'vendor_submitted', [ 'order' => $order, 'summary' => $summary ] );
        } elseif ( in_array( $status, [ 'failed', 'partial' ], true ) ) {
            self::notify( 'vendor_failed', [ 'order' => $order, 'summary' => $summary ] );
        }
    }

    public function on_vendor_changed( $action, $vendor_id, $name, $changes = [] ) {
        self::notify( 'vendor_changed', [ 'action' => $action, 'vendor_id' => (int) $vendor_id, 'name' => (string) $name, 'changes' => (array) $changes ] );
    }

    public function on_policy_updated( $what, $before, $after ) {
        self::notify( 'policy_changed', [ 'what' => $what, 'before' => (array) $before, 'after' => (array) $after ] );
    }

    /* ===================== Email content ===================== */

    /** @return array{subject:string,args:array}|null */
    public static function build( string $event, array $ctx ) {
        $label = self::events()[ $event ][1] ?? '';
        $by    = wp_get_current_user();
        $by    = $by && $by->exists() ? $by->display_name : 'System';

        if ( strpos( $event, 'order_' ) === 0 || in_array( $event, [ 'vendor_submitted', 'vendor_failed' ], true ) ) {
            $order = $ctx['order'] ?? null;
            if ( ! $order instanceof WC_Order ) return null;
            $no    = $order->get_order_number();
            $store = self::store_name( $order );
            $a     = [
                'eyebrow' => $label,
                'details' => self::order_details( $order ),
                'items'   => WCSS_Email_Template::order_items( $order ),
                'action'  => [ 'label' => 'Open order', 'url' => home_url( '/manager/orders/view/' . $order->get_id() ) ],
            ];
            switch ( $event ) {
                case 'order_new':
                    $subject = sprintf( 'New order #%s from %s', $no, $store );
                    $a += [ 'title' => sprintf( 'New order #%s', $no ), 'status' => [ 'label' => 'New', 'tone' => 'info' ],
                            'intro' => sprintf( '%s placed an order for %s.', $store, self::money( $order ) ) ];
                    break;
                case 'order_needs_approval':
                    $p = $ctx['policy'] ?? (array) $order->get_meta( '_wcss_policy' );
                    $subject = sprintf( 'Approval needed: order #%s from %s', $no, $store );
                    $a['action']['label'] = 'Review order';
                    $a += [ 'title' => sprintf( 'Order #%s needs your approval', $no ), 'status' => [ 'label' => 'Needs approval', 'tone' => 'warn' ],
                            'intro' => 'This order is outside the store’s ordering policy, so it was not sent to a vendor. Approve or reject it in Supply Manager.',
                            'note'  => [ 'title' => 'Why it needs approval', 'lines' => (array) ( $p['messages'] ?? [] ), 'tone' => 'warn' ] ];
                    break;
                case 'order_approved':
                    $override = (bool) $order->get_meta( '_wcss_policy_override' );
                    $subject  = sprintf( 'Order #%s approved', $no );
                    $a += [ 'title' => sprintf( 'Order #%s approved', $no ), 'status' => [ 'label' => $override ? 'Approved as an exception' : 'Approved', 'tone' => 'ok' ],
                            'intro' => $override ? sprintf( 'Supply Ops approved %s’s order even though it is outside the ordering policy.', $store )
                                                 : sprintf( '%s’s order is approved and will be sent to the vendor.', $store ) ];
                    break;
                case 'order_rejected':
                    $note    = self::latest_note( $order );
                    $subject = sprintf( 'Order #%s rejected', $no );
                    $a += [ 'title' => sprintf( 'Order #%s rejected', $no ), 'status' => [ 'label' => 'Rejected', 'tone' => 'bad' ],
                            'intro' => sprintf( '%s’s order was rejected and will not be sent to a vendor.', $store ) ];
                    if ( $note !== '' ) $a['note'] = [ 'title' => 'Note', 'lines' => [ $note ], 'tone' => 'bad' ];
                    break;
                case 'order_reopened':
                    $subject = sprintf( 'Order #%s moved back to pending', $no );
                    $a += [ 'title' => sprintf( 'Order #%s moved back to pending', $no ), 'status' => [ 'label' => 'Pending', 'tone' => 'warn' ],
                            'intro' => sprintf( 'Supply Ops reopened %s’s order (it was %s). It needs a new decision.', $store, strtolower( wc_get_order_status_name( (string) ( $ctx['old'] ?? 'approved' ) ) ) ) ];
                    break;
                case 'vendor_submitted':
                case 'vendor_failed':
                    $s      = (array) ( $ctx['summary'] ?? $order->get_meta( '_wcss_auto_vendor_submit' ) );
                    $vendor = self::vendor_label( $s['winning_vendor'] ?? '' );
                    $lines  = [];
                    foreach ( (array) ( $s['results'] ?? [] ) as $key => $res ) {
                        $lines[] = self::vendor_label( $key ) . ': ' . ( ! empty( $res['ok'] ) ? 'accepted' : 'not accepted' ) . ( ! empty( $res['message'] ) ? ' — ' . wp_strip_all_tags( (string) $res['message'] ) : '' );
                    }
                    if ( ! $lines && ! empty( $s['message'] ) ) $lines[] = wp_strip_all_tags( (string) $s['message'] );
                    if ( $event === 'vendor_submitted' ) {
                        $subject = sprintf( '%s accepted order #%s', $vendor, $no );
                        $a += [ 'title' => sprintf( '%s accepted order #%s', $vendor, $no ), 'status' => [ 'label' => 'Sent to vendor', 'tone' => 'ok' ],
                                'intro' => sprintf( '%s’s order was sent to %s and accepted.', $store, $vendor ),
                                'note'  => [ 'title' => 'Vendor response', 'lines' => $lines, 'tone' => 'ok' ] ];
                    } else {
                        $partial = ( $s['status'] ?? '' ) === 'partial';
                        $subject = sprintf( 'Action needed: order #%s was not %s by %s', $no, $partial ? 'fully accepted' : 'accepted', $vendor );
                        $a['action']['label'] = 'Fix in Supply Manager';
                        $a += [ 'title' => sprintf( 'Order #%s was not %s', $no, $partial ? 'fully accepted' : 'accepted' ), 'status' => [ 'label' => $partial ? 'Partly sent' : 'Vendor failed', 'tone' => 'bad' ],
                                'intro' => 'Sending this order to the vendor did not complete. Check the vendor response below and place it again or contact the vendor.',
                                'note'  => [ 'title' => 'Vendor response', 'lines' => $lines, 'tone' => 'bad' ] ];
                    }
                    break;
                default:
                    return null;
            }
            return [ 'subject' => '[Supply Manager] ' . $subject, 'args' => $a ];
        }

        if ( $event === 'vendor_changed' ) {
            $verbs   = [ 'created' => 'added', 'updated' => 'updated', 'deleted' => 'removed', 'budget' => 'budget changed' ];
            $verb    = $verbs[ $ctx['action'] ?? 'updated' ] ?? 'updated';
            $name    = html_entity_decode( (string) ( $ctx['name'] ?? '' ), ENT_QUOTES );
            $details = [ [ 'Vendor', $name ], [ 'Change', ucfirst( $verb ) ], [ 'Changed by', $by ] ];
            foreach ( (array) ( $ctx['changes'] ?? [] ) as $k => $v ) {
                $details[] = [ ucfirst( str_replace( '_', ' ', (string) $k ) ), is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ];
            }
            return [
                'subject' => sprintf( '[Supply Manager] Vendor %s: %s', $verb, $name ),
                'args'    => [
                    'eyebrow' => 'Vendor details changed',
                    'title'   => sprintf( 'Vendor %s: %s', $verb, $name ),
                    'status'  => [ 'label' => ucfirst( $verb ), 'tone' => ( $ctx['action'] ?? '' ) === 'deleted' ? 'bad' : 'info' ],
                    'details' => $details,
                    'action'  => [ 'label' => 'Open vendors', 'url' => home_url( '/manager/vendorslist' ) ],
                ],
            ];
        }

        if ( $event === 'policy_changed' ) {
            $names = [
                'default_monthly_budget' => 'Default monthly budget', 'default_monthly_quota' => 'Default orders per month',
                'order_window_start' => 'Ordering window opens (day)', 'order_window_end' => 'Ordering window closes (day)',
                'order_window_from' => 'Ordering window from', 'order_window_to' => 'Ordering window to', 'order_window_repeat' => 'Repeat every month',
                'limit_mode' => 'Orders outside the policy', 'budget' => 'Monthly budget', 'quota' => 'Orders per month',
                'window_label' => 'Ordering window', 'mode' => 'If an order breaks a rule',
            ];
            $apply = in_array( $ctx['what'] ?? '', [ 'apply_all', 'apply_some' ], true );
            $before  = (array) ( $ctx['before'] ?? [] );
            $details = [];
            foreach ( (array) ( $ctx['after'] ?? [] ) as $k => $v ) {
                $was = array_key_exists( $k, $before ) ? (string) $before[ $k ] : '';
                if ( $was === (string) $v && ! $apply ) continue;
                $details[] = [ $names[ $k ] ?? $k, ( $was !== '' ? $was . ' → ' : '' ) . $v ];
            }
            if ( ! $details ) return null;
            $n         = (int) ( $ctx['after']['stores'] ?? 0 );
            $head      = ( $ctx['what'] ?? '' ) === 'apply_all' ? 'Limits applied to all stores' : sprintf( 'Limits applied to %d store%s', $n, $n === 1 ? '' : 's' );
            if ( ( $ctx['what'] ?? '' ) === 'store' ) {
                $apply = true;
                $head  = 'Ordering rules changed for ' . html_entity_decode( (string) ( $ctx['after']['store'] ?? 'a store' ), ENT_QUOTES );
                $details = array_values( array_filter( $details, function ( $d ) { return $d[0] !== 'store'; } ) );
            }
            $details[] = [ 'Changed by', $by ];
            return [
                'subject' => '[Supply Manager] ' . ( $apply ? $head : 'Ordering policy changed' ),
                'args'    => [
                    'eyebrow' => 'Ordering policy changed',
                    'title'   => $apply ? $head : 'Ordering policy changed',
                    'status'  => [ 'label' => 'Updated', 'tone' => 'info' ],
                    'intro'   => ( $ctx['what'] ?? '' ) === 'store' ? 'This store’s own ordering rules were updated.' : ( $apply ? sprintf( 'These values were set on %d store%s.', $n, $n === 1 ? '' : 's' ) : 'The store ordering policy was updated.' ),
                    'details' => array_values( array_filter( $details, function ( $d ) { return $d[0] !== 'stores'; } ) ),
                    'action'  => [ 'label' => 'Open stores', 'url' => home_url( '/manager/stores' ) ],
                ],
            ];
        }
        return null;
    }

    /** $with_vendor = false for store emails: stores never see which vendor won. */
    public static function order_details( WC_Order $order, bool $with_vendor = true ): array {
        $store_id = (int) $order->get_meta( '_wcss_store_id' );
        $store    = self::store_name( $order );
        if ( $store_id ) {
            $bits  = array_filter( [ WCSS_Store_CPT::get_field( $store_id, 'code' ), WCSS_Store_CPT::get_field( $store_id, 'city' ) ] );
            $store .= $bits ? ' (' . implode( ', ', $bits ) . ')' : '';
        }
        $who     = trim( $order->get_formatted_billing_full_name() );
        $email   = $order->get_billing_email();
        $created = $order->get_date_created();
        $rows    = [
            [ 'Order', '#' . $order->get_order_number() ],
            [ 'Store', $store ],
            [ 'Requested by', implode( ' · ', array_filter( [ $who, $email ] ) ) ?: '—' ],
            [ 'Placed', $created ? wp_date( 'j M Y, g:i A', $created->getTimestamp() ) : '—' ],
            [ 'Status', wc_get_order_status_name( $order->get_status() ) ],
            [ 'Total', self::money( $order ) ],
        ];
        $s = $order->get_meta( '_wcss_auto_vendor_submit' );
        if ( $with_vendor && is_array( $s ) && ! empty( $s['winning_vendor'] ) ) {
            $rows[] = [ 'Vendor', self::vendor_label( $s['winning_vendor'] ) ];
        }
        return $rows;
    }

    private static function store_name( WC_Order $order ): string {
        $id = (int) $order->get_meta( '_wcss_store_id' );
        return $id ? html_entity_decode( get_the_title( $id ), ENT_QUOTES ) : 'A store';
    }

    private static function money( WC_Order $order ): string {
        return WCSS_Email_Template::money( $order->get_total(), $order->get_currency() );
    }

    private static function vendor_label( $key ): string {
        $map = [ 'amazon' => 'Amazon', 'gt' => 'Grand & Toy', 'mixed' => 'Amazon and Grand & Toy' ];
        return $map[ (string) $key ] ?? ( (string) $key ?: 'The vendor' );
    }

    /**
     * Reason typed by Supply Ops when changing status. Woo appends "Order status changed from …"
     * to the same note; drop that and the portal's default text so only a real reason shows.
     */
    public static function latest_note( WC_Order $order ): string {
        $notes = wc_get_order_notes( [ 'order_id' => $order->get_id(), 'limit' => 1 ] );
        if ( empty( $notes[0]->content ) ) return '';
        $text = trim( preg_replace( '/\s*Order status changed from .*$/is', '', wp_strip_all_tags( (string) $notes[0]->content ) ) );
        return in_array( $text, [ '', 'Status updated (Supply Manager)', 'Status updated (Manager)' ], true ) ? '' : $text;
    }

    /** Context for previews and test sends: a real recent order where one is needed. */
    private static function sample_ctx( string $event ) {
        if ( $event === 'vendor_changed' ) {
            return [ 'action' => 'updated', 'name' => 'Grand & Toy', 'changes' => [ 'email' => 'orders@grandandtoy.example', 'contact' => 'Account team' ] ];
        }
        if ( $event === 'policy_changed' ) {
            $o = get_option( 'wcss_settings', [] );
            return [ 'what' => 'policy', 'before' => [ 'default_monthly_budget' => 160 ], 'after' => [ 'default_monthly_budget' => (float) ( $o['default_monthly_budget'] ?? 125 ) ?: 125 ] ];
        }
        $ids = wc_get_orders( [ 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids', 'meta_key' => '_wcss_store_id', 'meta_compare' => 'EXISTS' ] );
        $order = $ids ? wc_get_order( $ids[0] ) : null;
        if ( ! $order ) return new WP_Error( 'wcss_no_order', 'There are no orders yet to build a sample from.', [ 'status' => 404 ] );
        $ctx = [ 'order' => $order ];
        if ( $event === 'order_needs_approval' ) {
            $ctx['policy'] = [ 'messages' => [ 'This store has already ordered this month (1 of 1).', 'This order goes over the CA$125.00 monthly budget.' ] ];
        }
        if ( in_array( $event, [ 'vendor_submitted', 'vendor_failed' ], true ) ) {
            $ok = $event === 'vendor_submitted';
            $ctx['summary'] = [ 'status' => $ok ? 'submitted' : 'failed', 'winning_vendor' => 'amazon',
                'results' => [ 'amazon' => [ 'ok' => $ok, 'message' => $ok ? 'Order placed. Amazon order 113-0000000-0000000.' : 'HTTP 400: The offer is no longer available.' ] ] ];
        }
        return $ctx;
    }

    /* ===================== REST ===================== */

    public function can_manage(): bool {
        return current_user_can( 'wcss_manage_portal' ) || current_user_can( 'manage_options' );
    }

    public function routes() {
        $ns = 'wcss/v1';
        $p  = [ $this, 'can_manage' ];
        register_rest_route( $ns, '/emails/settings', [ 'methods' => 'GET', 'permission_callback' => $p, 'callback' => [ $this, 'rest_settings' ] ] );
        register_rest_route( $ns, '/emails/recipients', [ 'methods' => 'PUT', 'permission_callback' => $p, 'callback' => [ $this, 'rest_save' ] ] );
        register_rest_route( $ns, '/emails/send', [ 'methods' => 'POST', 'permission_callback' => $p, 'callback' => [ $this, 'rest_send' ] ] );
        register_rest_route( $ns, '/emails/preview', [ 'methods' => 'GET', 'permission_callback' => $p, 'callback' => [ $this, 'rest_preview' ] ] );
        register_rest_route( $ns, '/emails/test', [ 'methods' => 'POST', 'permission_callback' => $p, 'callback' => [ $this, 'rest_test' ] ] );
        register_rest_route( $ns, '/emails/log', [ 'methods' => 'GET', 'permission_callback' => $p, 'callback' => [ $this, 'rest_log' ] ] );
        register_rest_route( $ns, '/emails/log/(?P<id>\d+)', [ 'methods' => 'GET', 'permission_callback' => $p, 'callback' => [ $this, 'rest_log_one' ] ] );
    }

    public function rest_settings() {
        $events = [];
        foreach ( self::events() as $key => $e ) {
            $events[] = [ 'key' => $key, 'group' => $e[0], 'label' => $e[1], 'help' => $e[2] ];
        }
        return rest_ensure_response( [ 'events' => $events, 'recipients' => self::recipients(), 'max' => self::MAX_RECIPIENTS ] );
    }

    public function rest_save( WP_REST_Request $req ) {
        $clean = self::clean_recipients( ( $req->get_json_params() ?: [] )['recipients'] ?? null );
        if ( is_wp_error( $clean ) ) return $clean;
        update_option( self::OPTION, $clean, false );
        return rest_ensure_response( [ 'ok' => true, 'recipients' => $clean ] );
    }

    public function rest_send( WP_REST_Request $req ) {
        $b       = $req->get_json_params() ?: [];
        $subject = trim( sanitize_text_field( (string) ( $b['subject'] ?? '' ) ) );
        $body    = wp_kses_post( (string) ( $b['message'] ?? '' ) );
        $to      = array_values( array_unique( array_filter( array_map( 'trim', preg_split( '/[,;\s]+/', (string) ( $b['to'] ?? '' ) ) ) ) ) );
        $me      = wp_get_current_user();
        $layout  = function ( $subject, $body ) use ( $me ) {
            return WCSS_Email_Template::render( [
                'eyebrow'   => 'Message from Supply Ops',
                'title'     => $subject,
                'body_html' => $body,
                'reason'    => sprintf( 'Sent by %s from Supply Manager.', $me->display_name ?: $me->user_login ),
            ] );
        };

        // "Preview" on the Send email tab: same layout, nothing sent.
        if ( ! empty( $b['preview'] ) ) {
            return rest_ensure_response( [ 'subject' => $subject, 'html' => $layout( $subject !== '' ? $subject : '(No subject)', $body ) ] );
        }

        if ( ! $to ) return new WP_Error( 'wcss_bad', 'Add at least one recipient.', [ 'status' => 400 ] );
        if ( count( $to ) > 25 ) return new WP_Error( 'wcss_bad', 'Send to 25 addresses or fewer at a time.', [ 'status' => 400 ] );
        foreach ( $to as $e ) {
            if ( ! is_email( $e ) ) return new WP_Error( 'wcss_bad_email', sprintf( '“%s” is not a valid email address.', $e ), [ 'status' => 400 ] );
        }
        if ( $subject === '' ) return new WP_Error( 'wcss_bad', 'Add a subject.', [ 'status' => 400 ] );
        if ( trim( wp_strip_all_tags( $body ) ) === '' ) return new WP_Error( 'wcss_bad', 'Write a message.', [ 'status' => 400 ] );

        $html = $layout( $subject, $body );

        $sent = []; $failed = [];
        foreach ( $to as $e ) {
            $r = self::send( $e, $subject, $html, 'wcss_manual' );
            if ( $r['ok'] ) { $sent[] = $e; } else { $failed[] = [ 'email' => $e, 'error' => $r['error'] ]; }
        }
        if ( ! $sent ) {
            return new WP_Error( 'wcss_mail_failed', 'The email could not be sent: ' . $failed[0]['error'], [ 'status' => 502, 'failed' => $failed ] );
        }
        return rest_ensure_response( [ 'ok' => true, 'sent' => $sent, 'failed' => $failed ] );
    }

    public function rest_preview( WP_REST_Request $req ) {
        $event = (string) $req->get_param( 'event' );
        if ( ! isset( self::events()[ $event ] ) ) return new WP_Error( 'wcss_bad', 'Unknown notification.', [ 'status' => 400 ] );
        $ctx = self::sample_ctx( $event );
        if ( is_wp_error( $ctx ) ) return $ctx;
        $mail = self::build( $event, $ctx );
        if ( ! $mail ) return new WP_Error( 'wcss_bad', 'Nothing to preview.', [ 'status' => 400 ] );
        $label = self::events()[ $event ][1];
        $html  = WCSS_Email_Template::render( $mail['args'] + [ 'reason' => sprintf( 'You get this because you are set to receive “%s” in Supply Manager → Emails.', $label ), 'manage_link' => true ] );
        return rest_ensure_response( [ 'subject' => $mail['subject'], 'html' => $html ] );
    }

    public function rest_test( WP_REST_Request $req ) {
        $b     = $req->get_json_params() ?: [];
        $event = (string) ( $b['event'] ?? '' );
        $to    = sanitize_email( (string) ( $b['to'] ?? '' ) );
        if ( ! is_email( $to ) ) return new WP_Error( 'wcss_bad_email', 'Enter a valid email address.', [ 'status' => 400 ] );
        $events = $event === 'all' ? array_keys( self::events() ) : [ $event ];
        $sent   = 0;
        foreach ( $events as $ev ) {
            if ( ! isset( self::events()[ $ev ] ) ) return new WP_Error( 'wcss_bad', 'Unknown notification.', [ 'status' => 400 ] );
            $ctx = self::sample_ctx( $ev );
            if ( is_wp_error( $ctx ) ) return $ctx;
            $mail = self::build( $ev, $ctx );
            if ( ! $mail ) continue;
            $html = WCSS_Email_Template::render( $mail['args'] + [ 'reason' => 'This is a test of the “' . self::events()[ $ev ][1] . '” email. No real order changed.', 'manage_link' => true ] );
            $r    = self::send( $to, '[TEST] ' . $mail['subject'], $html, 'wcss_test_' . $ev );
            if ( ! $r['ok'] ) return new WP_Error( 'wcss_mail_failed', 'The test email could not be sent: ' . $r['error'], [ 'status' => 502 ] );
            $sent++;
        }
        return rest_ensure_response( [ 'ok' => true, 'sent' => $sent, 'to' => $to ] );
    }

    private function log_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'wcss_email_logs';
    }

    public function rest_log( WP_REST_Request $req ) {
        global $wpdb;
        $t = $this->log_table();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) return rest_ensure_response( [ 'items' => [], 'total' => 0 ] );
        $per  = 25;
        $page = max( 1, (int) $req->get_param( 'page' ) );
        $q    = trim( (string) $req->get_param( 'q' ) );
        $where = '1=1';
        $args  = [];
        if ( $q !== '' ) {
            $like  = '%' . $wpdb->esc_like( $q ) . '%';
            $where = '(mailed_to LIKE %s OR subject LIKE %s)';
            $args  = [ $like, $like ];
        }
        $total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $where", $args ) : "SELECT COUNT(*) FROM $t" );
        $sql   = "SELECT id, mailed_to, subject, context, created_at FROM $t WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, [ $per, ( $page - 1 ) * $per ] ) ), ARRAY_A );
        return rest_ensure_response( [ 'items' => $rows, 'total' => $total, 'per_page' => $per, 'page' => $page ] );
    }

    public function rest_log_one( WP_REST_Request $req ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, mailed_to, subject, message, context, created_at FROM ' . $this->log_table() . ' WHERE id = %d', (int) $req['id'] ), ARRAY_A );
        if ( ! $row ) return new WP_Error( 'not_found', 'Email not found.', [ 'status' => 404 ] );
        return rest_ensure_response( $row );
    }
}
