<?php
/**
 * Supply Manager → Reports: filterable spend / exception / coverage reports,
 * saved presets, and branded exports (Excel, CSV, print / PDF).
 *
 * Spend = item totals before tax and shipping (same rule as store budgets in WCSS_Order_Policy).
 * Vendor per line = cheapest-vendor tag on the line (_cc_vendor), else the product's vendor, else "Unassigned".
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_Reports {

    const PRESETS = 'wcss_report_presets';
    const MAX_PRESETS = 60;

    const STATUS_SETS = [
        'approved' => [ 'approved', 'processing', 'completed' ],
        'awaiting' => [ 'pending', 'awaiting-approval', 'on-hold' ],
        'rejected' => [ 'rejected', 'cancelled' ],
    ];

    const REASONS = [
        'outside_window' => 'Outside ordering window',
        'over_quota'     => 'Second order this month',
        'over_budget'    => 'Over monthly budget',
        'no_store'       => 'No store linked',
        'ink_review'     => 'Ink order — Supply Ops review',
    ];

    /** Report key => [ title, description, default status ]. */
    public static function types(): array {
        return [
            'vendors'    => [ 'Spend by vendor', 'Amazon vs Grand & Toy (and any other vendor): spend, orders and share.', 'approved' ],
            'stores'     => [ 'Spend by store', 'What each store spent against its monthly budget.', 'approved' ],
            'products'   => [ 'Spend by product', 'Units and spend per product and SKU.', 'approved' ],
            'users'      => [ 'Orders by user', 'Orders and spend by the store employee who placed them.', 'approved' ],
            'exceptions' => [ 'Exceptions', 'Orders outside the policy or rejected: reason and outcome.', 'all' ],
            'coverage'   => [ 'Stores ordered / not ordered', 'Which stores ordered in the period and which did not.', 'all' ],
            'orders'     => [ 'Order detail', 'Every order with store, user, vendor, items and total.', 'all' ],
        ];
    }

    const RANGES = [
        'this_month'   => 'This month',
        'last_month'   => 'Last month',
        'this_quarter' => 'This quarter',
        'last_quarter' => 'Last quarter',
        'ytd'          => 'Year to date',
        'last_12'      => 'Last 12 months',
        'custom'       => 'Custom dates',
    ];

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'routes' ] );
        // Downloads run before the portal shell renders: /manager/reports/export/?format=xlsx|csv|print
        add_action( 'template_redirect', [ $this, 'maybe_export' ], 1 );
    }

    /* ===================== Filters ===================== */

    /** Resolve a relative range to [ from, to ] (Y-m-d, inclusive) in site time. */
    public static function range_dates( string $range ): array {
        $tz  = wp_timezone();
        $now = new DateTimeImmutable( 'now', $tz );
        $q   = intdiv( (int) $now->format( 'n' ) - 1, 3 );
        $qs  = $now->setDate( (int) $now->format( 'Y' ), $q * 3 + 1, 1 );
        switch ( $range ) {
            case 'last_month':   $s = $now->modify( 'first day of last month' ); return [ $s->format( 'Y-m-01' ), $s->format( 'Y-m-t' ) ];
            case 'this_quarter': return [ $qs->format( 'Y-m-d' ), $now->format( 'Y-m-d' ) ];
            case 'last_quarter': $ls = $qs->modify( '-3 months' ); return [ $ls->format( 'Y-m-d' ), $qs->modify( '-1 day' )->format( 'Y-m-d' ) ];
            case 'ytd':          return [ $now->format( 'Y-01-01' ), $now->format( 'Y-m-d' ) ];
            case 'last_12':      return [ $now->modify( 'first day of this month' )->modify( '-11 months' )->format( 'Y-m-d' ), $now->format( 'Y-m-d' ) ];
            default:             return [ $now->format( 'Y-m-01' ), $now->format( 'Y-m-d' ) ];
        }
    }

    private static function ids( $v ): array {
        $v = is_array( $v ) ? $v : explode( ',', (string) $v );
        return array_values( array_filter( array_map( 'absint', $v ) ) );
    }

    private static function keys( $v ): array {
        $v = is_array( $v ) ? $v : explode( ',', (string) $v );
        return array_values( array_filter( array_map( 'sanitize_key', $v ) ) );
    }

    /** Normalise request params into a filter set. */
    public static function filters( array $p ): array {
        $type  = isset( self::types()[ $p['type'] ?? '' ] ) ? $p['type'] : 'vendors';
        $range = isset( self::RANGES[ $p['range'] ?? '' ] ) ? $p['range'] : 'this_month';
        [ $from, $to ] = self::range_dates( $range );
        if ( $range === 'custom' ) {
            $f = (string) ( $p['from'] ?? '' );
            $t = (string) ( $p['to'] ?? '' );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f ) ) $from = $f;
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $t ) ) $to = $t;
            if ( $from > $to ) [ $from, $to ] = [ $to, $from ];
        }
        $status = (string) ( $p['status'] ?? '' );
        if ( ! isset( self::STATUS_SETS[ $status ] ) && $status !== 'all' ) {
            $status = self::types()[ $type ][2];
        }
        $coverage = in_array( $p['coverage'] ?? '', [ 'ordered', 'not_ordered' ], true ) ? $p['coverage'] : 'all';
        return [
            'type'     => $type,
            'range'    => $range,
            'from'     => $from,
            'to'       => $to,
            'status'   => $status,
            'vendor'   => self::keys( $p['vendor'] ?? '' ),
            'store'    => self::ids( $p['store'] ?? '' ),
            'product'  => self::ids( $p['product'] ?? '' ),
            'user'     => self::ids( $p['user'] ?? '' ),
            'province' => sanitize_text_field( (string) ( $p['province'] ?? '' ) ),
            'reason'   => self::keys( $p['reason'] ?? '' ),
            'coverage' => $coverage,
        ];
    }

    /* ===================== Data ===================== */

    private static $vendor_terms = null;
    private static $store_cache = [];

    /** @return array{key:string,label:string} */
    public static function vendor_of_line( WC_Order_Item_Product $it ): array {
        $tag = strtolower( (string) $it->get_meta( '_cc_vendor' ) );
        if ( $tag === 'amazon' ) return [ 'key' => 'amazon', 'label' => 'Amazon' ];
        if ( in_array( $tag, [ 'gt', 'grand-toy', 'grandtoy' ], true ) ) return [ 'key' => 'gt', 'label' => 'Grand & Toy' ];

        $pid = $it->get_product_id() ?: $it->get_variation_id();
        if ( $pid ) {
            $terms = wp_get_post_terms( $pid, 'wcss_vendor' );
            if ( ! is_wp_error( $terms ) && $terms ) {
                $slug = $terms[0]->slug;
                if ( $slug === 'amazon' ) return [ 'key' => 'amazon', 'label' => 'Amazon' ];
                if ( $slug === 'grand-toy' ) return [ 'key' => 'gt', 'label' => 'Grand & Toy' ];
                return [ 'key' => $slug, 'label' => html_entity_decode( $terms[0]->name, ENT_QUOTES ) ];
            }
        }
        return [ 'key' => 'unassigned', 'label' => 'Unassigned' ];
    }

    public static function vendor_options(): array {
        $out = [ [ 'key' => 'amazon', 'label' => 'Amazon' ], [ 'key' => 'gt', 'label' => 'Grand & Toy' ] ];
        foreach ( get_terms( [ 'taxonomy' => 'wcss_vendor', 'hide_empty' => false ] ) as $t ) {
            if ( in_array( $t->slug, [ 'amazon', 'grand-toy' ], true ) ) continue;
            $out[] = [ 'key' => $t->slug, 'label' => html_entity_decode( $t->name, ENT_QUOTES ) ];
        }
        $out[] = [ 'key' => 'unassigned', 'label' => 'Unassigned' ];
        return $out;
    }

    private static function store( int $id ): array {
        if ( ! $id ) return [ 'id' => 0, 'name' => 'No store', 'code' => '', 'city' => '', 'province' => '', 'budget' => 0.0 ];
        if ( ! isset( self::$store_cache[ $id ] ) ) {
            $limits = class_exists( 'WCSS_Order_Policy' ) ? WCSS_Order_Policy::store_limits( $id ) : [ 'budget' => 0 ];
            self::$store_cache[ $id ] = [
                'id'       => $id,
                'name'     => html_entity_decode( get_the_title( $id ) ?: '#' . $id, ENT_QUOTES ),
                'code'     => (string) WCSS_Store_CPT::get_field( $id, 'code' ),
                'city'     => (string) WCSS_Store_CPT::get_field( $id, 'city' ),
                'province' => (string) WCSS_Store_CPT::get_field( $id, 'state' ),
                'budget'   => (float) ( $limits['budget'] ?? 0 ),
            ];
        }
        return self::$store_cache[ $id ];
    }

    private static function user_of( WC_Order $o ): array {
        $uid = (int) $o->get_customer_id();
        $u   = $uid ? get_userdata( $uid ) : null;
        return [
            'id'    => $uid,
            'name'  => $u ? ( $u->display_name ?: $u->user_login ) : ( trim( $o->get_formatted_billing_full_name() ) ?: 'Guest' ),
            'email' => $u ? $u->user_email : $o->get_billing_email(),
        ];
    }

    /** Exception facts for one order (null when it is a normal in-policy order). */
    public static function exception_of( WC_Order $o ) {
        $policy   = $o->get_meta( '_wcss_policy' );
        $override = $o->get_meta( '_wcss_policy_override' );
        $status   = $o->get_status();
        $reasons  = [];
        if ( is_array( $policy ) && empty( $policy['in_policy'] ) ) $reasons = (array) ( $policy['reasons'] ?? [] );
        if ( ! $reasons && is_array( $override ) ) $reasons = (array) ( $override['reasons'] ?? [] );
        $rejected = in_array( $status, self::STATUS_SETS['rejected'], true );
        if ( ! $reasons && ! $rejected ) return null; // An approval with no policy reason (a Manual order, a normal status change) is not an exception.

        if ( $override && in_array( 'ink_review', $reasons, true ) ) {
            $outcome = 'Approved by Supply Ops'; // Every ink order is reviewed; that isn't an exception.
        } elseif ( $override ) {
            $outcome = 'Approved as exception';
        } elseif ( $rejected ) {
            $outcome = 'Rejected';
        } elseif ( in_array( $status, self::STATUS_SETS['approved'], true ) ) {
            $outcome = 'Approved';
        } else {
            $outcome = 'Waiting for Supply Ops';
        }
        $by = '';
        if ( is_array( $override ) && ! empty( $override['by'] ) ) {
            $u  = get_userdata( (int) $override['by'] );
            $by = $u ? $u->display_name : '';
        }
        return [
            'reasons' => $reasons ?: ( $rejected ? [ 'rejected' ] : [] ),
            'labels'  => $reasons ? array_map( function ( $r ) { return self::REASONS[ $r ] ?? $r; }, $reasons ) : [ 'Rejected by Supply Ops' ],
            'outcome' => $outcome,
            'by'      => $by,
        ];
    }

    /**
     * Orders in the date range (and status, store, user filters), each with its matching lines.
     * Vendor / product filters apply to lines; an order with no matching line is dropped.
     */
    public static function orders( array $f, bool $ignore_status = false ): array {
        $statuses = array_map( function ( $s ) { return 'wc-' === substr( $s, 0, 3 ) ? substr( $s, 3 ) : $s; }, array_keys( wc_get_order_statuses() ) );
        if ( ! $ignore_status && $f['status'] !== 'all' ) {
            $statuses = self::STATUS_SETS[ $f['status'] ];
        }
        $tz    = wp_timezone();
        $start = ( new DateTimeImmutable( $f['from'] . ' 00:00:00', $tz ) )->getTimestamp();
        $end   = ( new DateTimeImmutable( $f['to'] . ' 23:59:59', $tz ) )->getTimestamp();

        $ids = wc_get_orders( [
            'type'         => 'shop_order',
            'status'       => $statuses,
            'date_created' => $start . '...' . $end,
            'limit'        => -1,
            'return'       => 'ids',
            'orderby'      => 'date',
            'order'        => 'DESC',
        ] );

        $out = [];
        foreach ( $ids as $id ) {
            $o = wc_get_order( $id );
            if ( ! $o instanceof WC_Order ) continue;
            $sid = (int) $o->get_meta( '_wcss_store_id' );
            if ( $f['store'] && ! in_array( $sid, $f['store'], true ) ) continue;
            $store = self::store( $sid );
            if ( $f['province'] !== '' && strcasecmp( $store['province'], $f['province'] ) !== 0 ) continue;
            $user = self::user_of( $o );
            if ( $f['user'] && ! in_array( $user['id'], $f['user'], true ) ) continue;

            $lines = [];
            foreach ( $o->get_items( 'line_item' ) as $it ) {
                $v   = self::vendor_of_line( $it );
                $pid = (int) ( $it->get_product_id() ?: $it->get_variation_id() );
                if ( $f['vendor'] && ! in_array( $v['key'], $f['vendor'], true ) ) continue;
                if ( $f['product'] && ! in_array( $pid, $f['product'], true ) ) continue;
                $p = $it->get_product();
                $lines[] = [
                    'product_id' => $pid,
                    'name'       => html_entity_decode( $it->get_name(), ENT_QUOTES ),
                    'sku'        => $p ? (string) $p->get_sku() : '',
                    'vendor'     => $v['key'],
                    'vendor_l'   => $v['label'],
                    'qty'        => (int) $it->get_quantity(),
                    'amount'     => (float) $it->get_total(),
                ];
            }
            if ( ! $lines ) continue;

            $exc = self::exception_of( $o );
            if ( $f['reason'] && ( ! $exc || ! array_intersect( $f['reason'], $exc['reasons'] ) ) ) continue;

            $created = $o->get_date_created();
            $out[]   = [
                'id'         => $o->get_id(),
                'number'     => $o->get_order_number(),
                'ts'         => $created ? $created->getTimestamp() : 0,
                'date'       => $created ? wp_date( 'Y-m-d H:i', $created->getTimestamp() ) : '',
                'status'     => $o->get_status(),
                'status_l'   => wc_get_order_status_name( $o->get_status() ),
                'order_type' => ( class_exists( 'WCSS_Ink_Order' ) && WCSS_Ink_Order::order_is_ink( $o ) ) ? 'ink' : 'stationery',
                'store'      => $store,
                'user'       => $user,
                'lines'      => $lines,
                'amount'     => array_sum( array_column( $lines, 'amount' ) ),
                'units'      => array_sum( array_column( $lines, 'qty' ) ),
                'exception'  => $exc,
            ];
        }
        return $out;
    }

    private static function months_in( array $f ): int {
        $a = new DateTimeImmutable( substr( $f['from'], 0, 7 ) . '-01' );
        $b = new DateTimeImmutable( substr( $f['to'], 0, 7 ) . '-01' );
        $d = $a->diff( $b );
        return $d->y * 12 + $d->m + 1;
    }

    /* ===================== Reports ===================== */

    /**
     * @return array{type,title,description,filters,columns,rows,totals,summary,chart,period}
     */
    public static function run( array $f ): array {
        $orders = self::orders( $f );
        $cols = []; $rows = []; $chart = null;
        $money = 'money'; $int = 'int'; $pct = 'pct'; $text = 'text';

        switch ( $f['type'] ) {
            case 'vendors':
                $agg = []; $total = 0.0; $distinct = [ 'orders' => [], 'stores' => [] ];
                foreach ( $orders as $o ) {
                    $seen = [];
                    $distinct['orders'][ $o['id'] ] = 1;
                    $distinct['stores'][ $o['store']['id'] ] = 1;
                    foreach ( $o['lines'] as $l ) {
                        $k = $l['vendor'];
                        $agg[ $k ] = $agg[ $k ] ?? [ 'vendor' => $l['vendor_l'], 'key' => $k, 'orders' => 0, 'units' => 0, 'spend' => 0.0, 'stores' => [] ];
                        if ( empty( $seen[ $k ] ) ) { $agg[ $k ]['orders']++; $seen[ $k ] = 1; }
                        $agg[ $k ]['units'] += $l['qty'];
                        $agg[ $k ]['spend'] += $l['amount'];
                        $agg[ $k ]['stores'][ $o['store']['id'] ] = 1;
                        $total += $l['amount'];
                    }
                }
                foreach ( $agg as $a ) {
                    $rows[] = [ 'vendor' => $a['vendor'], 'key' => $a['key'], 'orders' => $a['orders'], 'stores' => count( $a['stores'] ), 'units' => $a['units'],
                        'spend' => round( $a['spend'], 2 ), 'share' => $total > 0 ? $a['spend'] / $total : 0, 'avg' => $a['orders'] ? round( $a['spend'] / $a['orders'], 2 ) : 0 ];
                }
                usort( $rows, function ( $a, $b ) { return $b['spend'] <=> $a['spend']; } );
                $cols = [ [ 'vendor', 'Vendor', $text ], [ 'orders', 'Orders', $int ], [ 'stores', 'Stores', $int ], [ 'units', 'Units', $int ], [ 'spend', 'Spend', $money ], [ 'share', 'Share of spend', $pct ], [ 'avg', 'Avg per order', $money ] ];
                $chart = self::vendor_months( $orders, $f );
                break;

            case 'stores':
                $agg = []; $months = self::months_in( $f );
                foreach ( $orders as $o ) {
                    $s = $o['store'];
                    $agg[ $s['id'] ] = $agg[ $s['id'] ] ?? [ 's' => $s, 'orders' => 0, 'units' => 0, 'spend' => 0.0, 'budget_spend' => 0.0, 'exc' => 0, 'last' => '' ];
                    $agg[ $s['id'] ]['orders']++;
                    $agg[ $s['id'] ]['units'] += $o['units'];
                    $agg[ $s['id'] ]['spend'] += $o['amount'];
                    if ( $o['order_type'] !== 'ink' ) $agg[ $s['id'] ]['budget_spend'] += $o['amount'];
                    $agg[ $s['id'] ]['exc']   += $o['exception'] ? 1 : 0;
                    $agg[ $s['id'] ]['last']   = max( $agg[ $s['id'] ]['last'], $o['date'] );
                }
                foreach ( $agg as $a ) {
                    $budget = $a['s']['budget'] * $months;
                    $rows[] = [ 'store' => $a['s']['name'], 'code' => $a['s']['code'], 'city' => $a['s']['city'], 'province' => $a['s']['province'],
                        'orders' => $a['orders'], 'units' => $a['units'], 'spend' => round( $a['spend'], 2 ), 'budget' => round( $budget, 2 ),
                        'used' => $budget > 0 ? $a['budget_spend'] / $budget : 0, 'exceptions' => $a['exc'], 'last' => $a['last'] ];
                }
                usort( $rows, function ( $a, $b ) { return $b['spend'] <=> $a['spend']; } );
                $cols = [ [ 'store', 'Store', $text ], [ 'code', 'Code', $text ], [ 'city', 'City', $text ], [ 'province', 'Province', $text ], [ 'orders', 'Orders', $int ], [ 'units', 'Units', $int ],
                    [ 'spend', 'Spend', $money ], [ 'budget', $months > 1 ? "Budget ({$months} mo)" : 'Budget', $money ], [ 'used', 'Budget used (excl. ink)', $pct ], [ 'exceptions', 'Exceptions', $int ], [ 'last', 'Last order', $text ] ];
                $chart = self::top_bars( $rows, 'store', 'spend' );
                break;

            case 'products':
                $agg = [];
                foreach ( $orders as $o ) {
                    foreach ( $o['lines'] as $l ) {
                        $k = $l['product_id'] ?: 'n:' . $l['name'];
                        $agg[ $k ] = $agg[ $k ] ?? [ 'product' => $l['name'], 'sku' => $l['sku'], 'vendors' => [], 'units' => 0, 'orders' => [], 'spend' => 0.0 ];
                        $agg[ $k ]['vendors'][ $l['vendor_l'] ] = 1;
                        $agg[ $k ]['units'] += $l['qty'];
                        $agg[ $k ]['orders'][ $o['id'] ] = 1;
                        $agg[ $k ]['spend'] += $l['amount'];
                    }
                }
                foreach ( $agg as $a ) {
                    $rows[] = [ 'product' => $a['product'], 'sku' => $a['sku'], 'vendor' => implode( ', ', array_keys( $a['vendors'] ) ), 'units' => $a['units'],
                        'orders' => count( $a['orders'] ), 'spend' => round( $a['spend'], 2 ), 'avg' => $a['units'] ? round( $a['spend'] / $a['units'], 2 ) : 0 ];
                }
                usort( $rows, function ( $a, $b ) { return $b['spend'] <=> $a['spend']; } );
                $cols = [ [ 'product', 'Product', $text ], [ 'sku', 'SKU', $text ], [ 'vendor', 'Vendor', $text ], [ 'units', 'Units', $int ], [ 'orders', 'Orders', $int ], [ 'spend', 'Spend', $money ], [ 'avg', 'Avg unit price', $money ] ];
                $chart = self::top_bars( $rows, 'product', 'spend' );
                break;

            case 'users':
                $agg = [];
                foreach ( $orders as $o ) {
                    $k = $o['user']['id'] ?: 'e:' . $o['user']['email'];
                    $agg[ $k ] = $agg[ $k ] ?? [ 'u' => $o['user'], 'stores' => [], 'orders' => 0, 'units' => 0, 'spend' => 0.0, 'last' => '' ];
                    $agg[ $k ]['stores'][ $o['store']['name'] ] = 1;
                    $agg[ $k ]['orders']++;
                    $agg[ $k ]['units'] += $o['units'];
                    $agg[ $k ]['spend'] += $o['amount'];
                    $agg[ $k ]['last']   = max( $agg[ $k ]['last'], $o['date'] );
                }
                foreach ( $agg as $a ) {
                    $rows[] = [ 'user' => $a['u']['name'], 'email' => $a['u']['email'], 'store' => implode( ', ', array_keys( $a['stores'] ) ), 'orders' => $a['orders'],
                        'units' => $a['units'], 'spend' => round( $a['spend'], 2 ), 'last' => $a['last'] ];
                }
                usort( $rows, function ( $a, $b ) { return $b['spend'] <=> $a['spend']; } );
                $cols = [ [ 'user', 'User', $text ], [ 'email', 'Email', $text ], [ 'store', 'Store', $text ], [ 'orders', 'Orders', $int ], [ 'units', 'Units', $int ], [ 'spend', 'Spend', $money ], [ 'last', 'Last order', $text ] ];
                $chart = self::top_bars( $rows, 'user', 'spend' );
                break;

            case 'exceptions':
                $by_reason = [];
                foreach ( $orders as $o ) {
                    if ( ! $o['exception'] ) continue;
                    foreach ( $o['exception']['labels'] as $lbl ) $by_reason[ $lbl ] = ( $by_reason[ $lbl ] ?? 0 ) + 1;
                    $rows[] = [ 'order' => '#' . $o['number'], 'id' => $o['id'], 'date' => $o['date'], 'store' => $o['store']['name'], 'user' => $o['user']['name'],
                        'total' => round( $o['amount'], 2 ), 'reasons' => implode( '; ', $o['exception']['labels'] ), 'outcome' => $o['exception']['outcome'], 'by' => $o['exception']['by'] ];
                }
                $cols = [ [ 'order', 'Order', $text ], [ 'date', 'Placed', $text ], [ 'store', 'Store', $text ], [ 'user', 'User', $text ], [ 'total', 'Total', $money ],
                    [ 'reasons', 'Reason', $text ], [ 'outcome', 'Outcome', $text ], [ 'by', 'Approved by', $text ] ];
                arsort( $by_reason );
                $chart = [ 'kind' => 'bars', 'money' => false, 'items' => array_map( function ( $k, $v ) { return [ 'label' => $k, 'value' => $v ]; }, array_keys( $by_reason ), $by_reason ) ];
                break;

            case 'coverage':
                $placed = [];
                foreach ( $orders as $o ) {
                    if ( in_array( $o['status'], self::STATUS_SETS['rejected'], true ) ) continue;
                    $sid = $o['store']['id'];
                    $placed[ $sid ] = $placed[ $sid ] ?? [ 'orders' => 0, 'spend' => 0.0, 'last' => '' ];
                    $placed[ $sid ]['orders']++;
                    $placed[ $sid ]['spend'] += $o['amount'];
                    $placed[ $sid ]['last']   = max( $placed[ $sid ]['last'], $o['date'] );
                }
                $sids = get_posts( [ 'post_type' => WCSS_Store_CPT::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC' ] );
                $users = self::store_users();
                foreach ( $sids as $sid ) {
                    if ( $f['store'] && ! in_array( (int) $sid, $f['store'], true ) ) continue;
                    $s = self::store( (int) $sid );
                    if ( $f['province'] !== '' && strcasecmp( $s['province'], $f['province'] ) !== 0 ) continue;
                    $p  = $placed[ $sid ] ?? null;
                    if ( $f['coverage'] === 'ordered' && ! $p ) continue;
                    if ( $f['coverage'] === 'not_ordered' && $p ) continue;
                    $rows[] = [ 'store' => $s['name'], 'code' => $s['code'], 'city' => $s['city'], 'province' => $s['province'], 'ordered' => $p ? 'Ordered' : 'Not ordered',
                        'orders' => $p ? $p['orders'] : 0, 'spend' => $p ? round( $p['spend'], 2 ) : 0, 'last' => $p ? $p['last'] : '', 'user' => $users[ (int) $sid ] ?? '' ];
                }
                usort( $rows, function ( $a, $b ) { return [ $a['ordered'] === 'Ordered', $a['store'] ] <=> [ $b['ordered'] === 'Ordered', $b['store'] ]; } );
                $cols = [ [ 'store', 'Store', $text ], [ 'code', 'Code', $text ], [ 'city', 'City', $text ], [ 'province', 'Province', $text ], [ 'ordered', 'Status', $text ],
                    [ 'orders', 'Orders', $int ], [ 'spend', 'Spend', $money ], [ 'last', 'Last order', $text ], [ 'user', 'Store user', $text ] ];
                $yes = count( array_filter( $rows, function ( $r ) { return $r['ordered'] === 'Ordered'; } ) );
                $chart = [ 'kind' => 'split', 'items' => [ [ 'label' => 'Ordered', 'value' => $yes ], [ 'label' => 'Not ordered', 'value' => count( $rows ) - $yes ] ] ];
                break;

            case 'orders':
                foreach ( $orders as $o ) {
                    $rows[] = [ 'order' => '#' . $o['number'], 'id' => $o['id'], 'date' => $o['date'], 'type' => ( ( $o['order_type'] ?? '' ) === 'ink' ) ? 'Ink' : 'Stationery',
                        'store' => $o['store']['name'], 'user' => $o['user']['name'], 'status' => $o['status_l'],
                        'vendor' => implode( ', ', array_unique( array_column( $o['lines'], 'vendor_l' ) ) ), 'items' => count( $o['lines'] ), 'units' => $o['units'], 'total' => round( $o['amount'], 2 ),
                        'exception' => $o['exception'] ? implode( '; ', $o['exception']['labels'] ) : '' ];
                }
                $cols = [ [ 'order', 'Order', $text ], [ 'date', 'Placed', $text ], [ 'type', 'Type', $text ], [ 'store', 'Store', $text ], [ 'user', 'User', $text ], [ 'status', 'Status', $text ], [ 'vendor', 'Vendor', $text ],
                    [ 'items', 'Lines', $int ], [ 'units', 'Units', $int ], [ 'total', 'Total', $money ], [ 'exception', 'Exception', $text ] ];
                break;
        }

        $columns = array_map( function ( $c ) { return [ 'key' => $c[0], 'label' => $c[1], 'type' => $c[2] ]; }, $cols );
        $totals  = [];
        foreach ( $columns as $c ) {
            if ( in_array( $c['type'], [ 'money', 'int' ], true ) && ! in_array( $c['key'], [ 'avg' ], true ) ) {
                $totals[ $c['key'] ] = round( array_sum( array_column( $rows, $c['key'] ) ), 2 );
            }
        }
        if ( isset( $totals['budget'], $totals['spend'] ) && $totals['budget'] > 0 ) $totals['used'] = $totals['spend'] / $totals['budget'];
        if ( $f['type'] === 'vendors' && $rows ) {
            // An order with Amazon and G&T lines appears under both vendors; count it once in the total.
            $totals['share']  = 1;
            $totals['orders'] = count( $distinct['orders'] );
            $totals['stores'] = count( $distinct['stores'] );
        }

        $type = self::types()[ $f['type'] ];
        return [
            'type'        => $f['type'],
            'title'       => $type[0],
            'description' => $type[1],
            'filters'     => $f,
            'filters_text'=> self::describe( $f ),
            'period'      => self::period_label( $f ),
            'columns'     => $columns,
            'rows'        => $rows,
            'totals'      => $totals,
            'summary'     => self::summary( $f ),
            'chart'       => $chart,
            'generated'   => wp_date( 'j M Y, g:i A' ),
        ];
    }

    /** Headline numbers for the period (approved spend, whatever report is open). */
    public static function summary( array $f ): array {
        $sf = array_merge( $f, [ 'status' => 'approved' ] );
        $spend = [ 'amazon' => 0.0, 'gt' => 0.0, 'other' => 0.0 ];
        $stores = [];
        $approved = self::orders( $sf );
        foreach ( $approved as $o ) {
            $stores[ $o['store']['id'] ] = 1;
            foreach ( $o['lines'] as $l ) {
                $k = in_array( $l['vendor'], [ 'amazon', 'gt' ], true ) ? $l['vendor'] : 'other';
                $spend[ $k ] += $l['amount'];
            }
        }
        $all  = self::orders( $f, true );
        $exc  = count( array_filter( $all, function ( $o ) { return (bool) $o['exception']; } ) );
        $wait = count( array_filter( $all, function ( $o ) { return in_array( $o['status'], self::STATUS_SETS['awaiting'], true ); } ) );
        $total = array_sum( $spend );
        return [
            'spend'         => round( $total, 2 ),
            'amazon'        => round( $spend['amazon'], 2 ),
            'gt'            => round( $spend['gt'], 2 ),
            'other'         => round( $spend['other'], 2 ),
            'orders'        => count( $approved ),
            'avg_order'     => $approved ? round( $total / count( $approved ), 2 ) : 0,
            'stores_ordered'=> count( $stores ),
            'stores_total'  => (int) wp_count_posts( WCSS_Store_CPT::POST_TYPE )->publish,
            'exceptions'    => $exc,
            'awaiting'      => $wait,
        ];
    }

    /** Monthly Amazon / G&T / other spend for the vendor chart. */
    private static function vendor_months( array $orders, array $f ): array {
        $months = [];
        $cur    = new DateTimeImmutable( substr( $f['from'], 0, 7 ) . '-01' );
        $last   = substr( $f['to'], 0, 7 );
        while ( $cur->format( 'Y-m' ) <= $last && count( $months ) < 24 ) {
            $months[ $cur->format( 'Y-m' ) ] = [ 'label' => $cur->format( 'M Y' ), 'amazon' => 0.0, 'gt' => 0.0, 'other' => 0.0 ];
            $cur = $cur->modify( '+1 month' );
        }
        foreach ( $orders as $o ) {
            $ym = wp_date( 'Y-m', $o['ts'] );
            if ( ! isset( $months[ $ym ] ) ) continue;
            foreach ( $o['lines'] as $l ) {
                $k = in_array( $l['vendor'], [ 'amazon', 'gt' ], true ) ? $l['vendor'] : 'other';
                $months[ $ym ][ $k ] += $l['amount'];
            }
        }
        return [ 'kind' => 'months', 'items' => array_values( array_map( function ( $m ) {
            return [ 'label' => $m['label'], 'amazon' => round( $m['amazon'], 2 ), 'gt' => round( $m['gt'], 2 ), 'other' => round( $m['other'], 2 ) ];
        }, $months ) ) ];
    }

    private static function top_bars( array $rows, string $label, string $value ): array {
        return [ 'kind' => 'bars', 'money' => true, 'items' => array_map( function ( $r ) use ( $label, $value ) {
            return [ 'label' => $r[ $label ], 'value' => $r[ $value ] ];
        }, array_slice( $rows, 0, 10 ) ) ];
    }

    private static function store_users(): array {
        $out = [];
        foreach ( get_users( [ 'meta_key' => '_wcss_store_id', 'fields' => [ 'ID', 'display_name', 'user_email' ] ] ) as $u ) {
            $sid = (int) get_user_meta( $u->ID, '_wcss_store_id', true );
            if ( $sid && ! isset( $out[ $sid ] ) ) $out[ $sid ] = $u->user_email;
        }
        return $out;
    }

    public static function period_label( array $f ): string {
        $a = new DateTimeImmutable( $f['from'] );
        $b = new DateTimeImmutable( $f['to'] );
        $range = $f['range'] !== 'custom' ? self::RANGES[ $f['range'] ] . ' · ' : '';
        return $range . $a->format( 'j M Y' ) . ' – ' . $b->format( 'j M Y' );
    }

    /** Human list of active filters, e.g. [ 'Vendor: Amazon', 'Status: Approved' ]. */
    public static function describe( array $f ): array {
        $out = [];
        $st  = [ 'approved' => 'Approved (spend)', 'awaiting' => 'Awaiting approval', 'rejected' => 'Rejected', 'all' => 'All statuses' ];
        $out[] = 'Status: ' . $st[ $f['status'] ];
        if ( $f['vendor'] ) {
            $labels = wp_list_pluck( self::vendor_options(), 'label', 'key' );
            $out[]  = 'Vendor: ' . implode( ', ', array_map( function ( $k ) use ( $labels ) { return $labels[ $k ] ?? $k; }, $f['vendor'] ) );
        }
        if ( $f['store'] ) $out[] = 'Store: ' . implode( ', ', array_map( function ( $id ) { return self::store( $id )['name']; }, $f['store'] ) );
        if ( $f['province'] !== '' ) $out[] = 'Province: ' . $f['province'];
        if ( $f['product'] ) $out[] = 'Product: ' . implode( ', ', array_map( function ( $id ) { return html_entity_decode( get_the_title( $id ) ?: '#' . $id, ENT_QUOTES ); }, $f['product'] ) );
        if ( $f['user'] ) $out[] = 'User: ' . implode( ', ', array_map( function ( $id ) { $u = get_userdata( $id ); return $u ? $u->display_name : '#' . $id; }, $f['user'] ) );
        if ( $f['reason'] ) $out[] = 'Reason: ' . implode( ', ', array_map( function ( $r ) { return self::REASONS[ $r ] ?? $r; }, $f['reason'] ) );
        if ( $f['type'] === 'coverage' && $f['coverage'] !== 'all' ) $out[] = $f['coverage'] === 'ordered' ? 'Only stores that ordered' : 'Only stores that did not order';
        return $out;
    }

    /* ===================== Presets ===================== */

    public static function builtin_presets(): array {
        return [
            [ 'id' => 'b-vendors-month', 'name' => 'Amazon vs Grand & Toy · this month', 'builtin' => true, 'filters' => [ 'type' => 'vendors', 'range' => 'this_month' ] ],
            [ 'id' => 'b-vendors-12', 'name' => 'Vendor spend · last 12 months', 'builtin' => true, 'filters' => [ 'type' => 'vendors', 'range' => 'last_12' ] ],
            [ 'id' => 'b-exceptions-month', 'name' => 'Exceptions · this month', 'builtin' => true, 'filters' => [ 'type' => 'exceptions', 'range' => 'this_month', 'status' => 'all' ] ],
            [ 'id' => 'b-not-ordered', 'name' => 'Stores not ordered · this month', 'builtin' => true, 'filters' => [ 'type' => 'coverage', 'range' => 'this_month', 'coverage' => 'not_ordered' ] ],
            [ 'id' => 'b-stores-last', 'name' => 'Spend by store · last month', 'builtin' => true, 'filters' => [ 'type' => 'stores', 'range' => 'last_month' ] ],
            [ 'id' => 'b-products-q', 'name' => 'Top products · this quarter', 'builtin' => true, 'filters' => [ 'type' => 'products', 'range' => 'this_quarter' ] ],
        ];
    }

    public static function presets(): array {
        $saved = get_option( self::PRESETS, [] );
        return array_merge( self::builtin_presets(), is_array( $saved ) ? array_values( $saved ) : [] );
    }

    /* ===================== REST ===================== */

    public function can_manage(): bool {
        return current_user_can( 'wcss_manage_portal' ) || current_user_can( 'manage_options' );
    }

    public function routes() {
        $p = [ $this, 'can_manage' ];
        register_rest_route( 'wcss/v1', '/reports/meta', [ 'methods' => 'GET', 'permission_callback' => $p, 'callback' => [ $this, 'rest_meta' ] ] );
        register_rest_route( 'wcss/v1', '/reports/run', [ 'methods' => 'GET', 'permission_callback' => $p, 'callback' => [ $this, 'rest_run' ] ] );
        register_rest_route( 'wcss/v1', '/reports/presets', [
            [ 'methods' => 'GET', 'permission_callback' => $p, 'callback' => function () { return rest_ensure_response( self::presets() ); } ],
            [ 'methods' => 'POST', 'permission_callback' => $p, 'callback' => [ $this, 'rest_save_preset' ] ],
        ] );
        register_rest_route( 'wcss/v1', '/reports/presets/(?P<id>[a-z0-9-]+)', [ 'methods' => 'DELETE', 'permission_callback' => $p, 'callback' => [ $this, 'rest_delete_preset' ] ] );
    }

    public function rest_meta() {
        $types = [];
        foreach ( self::types() as $k => $t ) $types[] = [ 'key' => $k, 'label' => $t[0], 'help' => $t[1], 'status' => $t[2] ];
        $ranges = [];
        foreach ( self::RANGES as $k => $l ) $ranges[] = [ 'key' => $k, 'label' => $l, 'dates' => $k === 'custom' ? null : self::range_dates( $k ) ];

        $stores = []; $provinces = [];
        foreach ( get_posts( [ 'post_type' => WCSS_Store_CPT::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC' ] ) as $sid ) {
            $s = self::store( (int) $sid );
            $stores[] = [ 'id' => $s['id'], 'name' => $s['name'], 'code' => $s['code'] ];
            if ( $s['province'] !== '' ) $provinces[ $s['province'] ] = 1;
        }
        ksort( $provinces );

        // Products and users that appear on orders (the only ones a filter can match).
        $products = []; $users = [];
        foreach ( wc_get_orders( [ 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ] ) as $id ) {
            $o = wc_get_order( $id );
            if ( ! $o ) continue;
            $uid = (int) $o->get_customer_id();
            if ( $uid && ! isset( $users[ $uid ] ) && ( $u = get_userdata( $uid ) ) ) $users[ $uid ] = [ 'id' => $uid, 'name' => $u->display_name ?: $u->user_login, 'email' => $u->user_email ];
            foreach ( $o->get_items( 'line_item' ) as $it ) {
                $pid = (int) ( $it->get_product_id() ?: $it->get_variation_id() );
                if ( $pid && ! isset( $products[ $pid ] ) ) $products[ $pid ] = [ 'id' => $pid, 'name' => html_entity_decode( $it->get_name(), ENT_QUOTES ) ];
            }
        }
        $sort = function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); };
        $products = array_values( $products ); usort( $products, $sort );
        $users = array_values( $users ); usort( $users, $sort );

        $reasons = [];
        foreach ( self::REASONS as $k => $l ) $reasons[] = [ 'key' => $k, 'label' => $l ];
        $reasons[] = [ 'key' => 'rejected', 'label' => 'Rejected by Supply Ops' ];

        return rest_ensure_response( [
            'types' => $types, 'ranges' => $ranges, 'vendors' => self::vendor_options(), 'stores' => $stores, 'provinces' => array_keys( $provinces ),
            'products' => $products, 'users' => $users, 'reasons' => $reasons, 'presets' => self::presets(),
        ] );
    }

    public function rest_run( WP_REST_Request $req ) {
        return rest_ensure_response( self::run( self::filters( $req->get_params() ) ) );
    }

    public function rest_save_preset( WP_REST_Request $req ) {
        $b    = $req->get_json_params() ?: [];
        $name = trim( sanitize_text_field( (string) ( $b['name'] ?? '' ) ) );
        if ( $name === '' ) return new WP_Error( 'wcss_bad', 'Give the preset a name.', [ 'status' => 400 ] );
        if ( mb_strlen( $name ) > 80 ) return new WP_Error( 'wcss_bad', 'Keep the name under 80 characters.', [ 'status' => 400 ] );
        $saved = get_option( self::PRESETS, [] );
        $saved = is_array( $saved ) ? $saved : [];
        foreach ( array_merge( self::builtin_presets(), $saved ) as $p ) {
            if ( strcasecmp( $p['name'], $name ) === 0 ) return new WP_Error( 'wcss_dup', 'A preset with that name already exists.', [ 'status' => 409 ] );
        }
        if ( count( $saved ) >= self::MAX_PRESETS ) return new WP_Error( 'wcss_bad', 'Too many saved presets. Delete one first.', [ 'status' => 400 ] );

        // Store only meaningful filters; relative ranges stay relative (e.g. "this month").
        $f    = self::filters( (array) ( $b['filters'] ?? [] ) );
        $keep = [ 'type' => $f['type'], 'range' => $f['range'], 'status' => $f['status'] ];
        if ( $f['range'] === 'custom' ) { $keep['from'] = $f['from']; $keep['to'] = $f['to']; }
        foreach ( [ 'vendor', 'store', 'product', 'user', 'reason' ] as $k ) if ( $f[ $k ] ) $keep[ $k ] = $f[ $k ];
        if ( $f['province'] !== '' ) $keep['province'] = $f['province'];
        if ( $f['coverage'] !== 'all' ) $keep['coverage'] = $f['coverage'];

        $me     = wp_get_current_user();
        $preset = [ 'id' => 'p-' . strtolower( wp_generate_password( 10, false ) ), 'name' => $name, 'builtin' => false, 'filters' => $keep,
            'by' => $me->display_name ?: $me->user_login, 'at' => current_time( 'mysql' ) ];
        $saved[] = $preset;
        update_option( self::PRESETS, array_values( $saved ), false );
        return rest_ensure_response( $preset );
    }

    public function rest_delete_preset( WP_REST_Request $req ) {
        $id = (string) $req['id'];
        if ( strpos( $id, 'b-' ) === 0 ) return new WP_Error( 'wcss_bad', 'Built-in presets can’t be deleted.', [ 'status' => 400 ] );
        $saved = get_option( self::PRESETS, [] );
        $left  = array_values( array_filter( (array) $saved, function ( $p ) use ( $id ) { return ( $p['id'] ?? '' ) !== $id; } ) );
        if ( count( $left ) === count( (array) $saved ) ) return new WP_Error( 'not_found', 'Preset not found.', [ 'status' => 404 ] );
        update_option( self::PRESETS, $left, false );
        return rest_ensure_response( [ 'ok' => true ] );
    }

    /* ===================== Exports ===================== */

    public function maybe_export() {
        if ( get_query_var( 'wcss' ) !== 'reports' || get_query_var( 'wcss_action' ) !== 'export' ) return;
        if ( ! is_user_logged_in() || ! $this->can_manage() ) {
            auth_redirect();
            exit;
        }
        $format = sanitize_key( $_GET['format'] ?? 'xlsx' );
        $report = self::run( self::filters( wp_unslash( $_GET ) ) );
        $file   = sanitize_file_name( 'canna-cabana-' . $report['type'] . '-' . $report['filters']['from'] . '-to-' . $report['filters']['to'] );

        nocache_headers();
        if ( $format === 'csv' ) {
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="' . $file . '.csv"' );
            echo self::csv( $report ); // phpcs:ignore
            exit;
        }
        if ( $format === 'print' ) {
            header( 'Content-Type: text/html; charset=utf-8' );
            echo self::print_html( $report, ! empty( $_GET['autoprint'] ) ); // phpcs:ignore
            exit;
        }
        $path = self::xlsx( $report );
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $file . '.xlsx"' );
        header( 'Content-Length: ' . filesize( $path ) );
        readfile( $path );
        @unlink( $path );
        exit;
    }

    private static function cell_text( $v, string $type ): string {
        if ( $type === 'money' ) return number_format( (float) $v, 2, '.', '' );
        if ( $type === 'pct' ) return number_format( (float) $v * 100, 1, '.', '' ) . '%';
        return (string) $v;
    }

    /** Plain data table (Excel opens it as UTF-8 thanks to the BOM). */
    public static function csv( array $r ): string {
        $h = fopen( 'php://temp', 'w+' );
        fwrite( $h, "\xEF\xBB\xBF" );
        fputcsv( $h, array_column( $r['columns'], 'label' ) );
        foreach ( $r['rows'] as $row ) {
            fputcsv( $h, array_map( function ( $c ) use ( $row ) { return self::csv_safe( self::cell_text( $row[ $c['key'] ] ?? '', $c['type'] ) ); }, $r['columns'] ) );
        }
        if ( $r['totals'] && $r['rows'] ) {
            fputcsv( $h, array_map( function ( $c, $i ) use ( $r ) {
                return $i === 0 ? 'Total' : ( isset( $r['totals'][ $c['key'] ] ) ? self::cell_text( $r['totals'][ $c['key'] ], $c['type'] ) : '' );
            }, $r['columns'], array_keys( $r['columns'] ) ) );
        }
        rewind( $h );
        $out = stream_get_contents( $h );
        fclose( $h );
        return $out;
    }

    /** Stop spreadsheet formula injection from user-entered names. */
    private static function csv_safe( string $s ): string {
        return preg_match( '/^[=+\-@\t\r]/', $s ) && ! is_numeric( $s ) ? "'" . $s : $s;
    }

    public static function xlsx( array $r ): string {
        $x    = new WCSS_Xlsx();
        $me   = wp_get_current_user();
        $by   = $me && $me->exists() ? ( $me->display_name ?: $me->user_login ) : 'Supply Manager';
        $cols = $r['columns'];
        $n    = max( 4, count( $cols ) );
        $last = WCSS_Xlsx::col( $n - 1 );
        $s    = $r['summary'];

        // ---- Summary sheet ----
        $sum = [
            [ [ 'CANNA Cabana  ·  Supply Manager', WCSS_Xlsx::S_BAND ], [ '', WCSS_Xlsx::S_BAND ], [ '', WCSS_Xlsx::S_BAND ], [ 'Report', WCSS_Xlsx::S_BAND_SUB ] ],
            [],
            [ [ $r['title'], WCSS_Xlsx::S_TITLE ] ],
            [ [ $r['description'], WCSS_Xlsx::S_MUTED ] ],
            [],
            [ [ 'Period', WCSS_Xlsx::S_LABEL ], [ $r['period'], WCSS_Xlsx::S_CELL ] ],
            [ [ 'Filters', WCSS_Xlsx::S_LABEL ], [ implode( ' · ', $r['filters_text'] ), WCSS_Xlsx::S_CELL ] ],
            [ [ 'Generated', WCSS_Xlsx::S_LABEL ], [ $r['generated'] . ' by ' . $by, WCSS_Xlsx::S_CELL ] ],
            [ [ 'Spend basis', WCSS_Xlsx::S_LABEL ], [ 'Approved orders, item totals before tax and shipping', WCSS_Xlsx::S_CELL ] ],
            [],
            [ [ 'Key figures', WCSS_Xlsx::S_SECTION ] ],
            [ [ 'Metric', WCSS_Xlsx::S_HEAD ], [ 'Value', WCSS_Xlsx::S_HEAD ] ],
            [ [ 'Total spend', WCSS_Xlsx::S_CELL ], [ (float) $s['spend'], WCSS_Xlsx::S_MONEY ] ],
            [ [ 'Amazon', WCSS_Xlsx::S_CELL ], [ (float) $s['amazon'], WCSS_Xlsx::S_MONEY ] ],
            [ [ 'Grand & Toy', WCSS_Xlsx::S_CELL ], [ (float) $s['gt'], WCSS_Xlsx::S_MONEY ] ],
            [ [ 'Other vendors / unassigned', WCSS_Xlsx::S_CELL ], [ (float) $s['other'], WCSS_Xlsx::S_MONEY ] ],
            [ [ 'Approved orders', WCSS_Xlsx::S_CELL ], [ (int) $s['orders'], WCSS_Xlsx::S_INT ] ],
            [ [ 'Average order', WCSS_Xlsx::S_CELL ], [ (float) $s['avg_order'], WCSS_Xlsx::S_MONEY ] ],
            [ [ 'Stores that ordered', WCSS_Xlsx::S_CELL ], [ $s['stores_ordered'] . ' of ' . $s['stores_total'], WCSS_Xlsx::S_CELL ] ],
            [ [ 'Exceptions', WCSS_Xlsx::S_CELL ], [ (int) $s['exceptions'], WCSS_Xlsx::S_INT ] ],
            [ [ 'Awaiting approval', WCSS_Xlsx::S_CELL ], [ (int) $s['awaiting'], WCSS_Xlsx::S_INT ] ],
        ];
        $x->add_sheet( 'Summary', $sum, [ 'widths' => [ 30, 60, 4, 14 ], 'merges' => [ 'A1:C1', 'B6:D6', 'B7:D7', 'B8:D8', 'B9:D9', 'A3:D3', 'A4:D4' ], 'heights' => [ 1 => 30, 3 => 26 ] ] );

        // ---- Report sheet ----
        $band   = array_fill( 0, $n, [ '', WCSS_Xlsx::S_BAND ] );
        $band[0] = [ 'CANNA Cabana  ·  Supply Manager', WCSS_Xlsx::S_BAND ];
        $band[ $n - 1 ] = [ $r['title'], WCSS_Xlsx::S_BAND_SUB ];
        $rows   = [ $band, [ [ $r['title'], WCSS_Xlsx::S_TITLE ] ], [ [ $r['period'] . '   ·   ' . implode( ' · ', $r['filters_text'] ), WCSS_Xlsx::S_MUTED ] ], [] ];
        $head   = count( $rows ) + 1;
        $rows[] = array_map( function ( $c ) { return [ $c['label'], WCSS_Xlsx::S_HEAD ]; }, $cols );
        $map    = [ 'money' => WCSS_Xlsx::S_MONEY, 'int' => WCSS_Xlsx::S_INT, 'pct' => WCSS_Xlsx::S_PCT, 'text' => WCSS_Xlsx::S_CELL ];
        foreach ( $r['rows'] as $row ) {
            $rows[] = array_map( function ( $c ) use ( $row, $map ) {
                $v = $row[ $c['key'] ] ?? '';
                if ( $c['type'] === 'money' || $c['type'] === 'pct' ) $v = (float) $v;
                if ( $c['type'] === 'int' ) $v = (int) $v;
                return [ $v, $map[ $c['type'] ] ];
            }, $cols );
        }
        $end = count( $rows );
        if ( $r['rows'] && $r['totals'] ) {
            $tmap   = [ 'money' => WCSS_Xlsx::S_TOT_MONEY, 'int' => WCSS_Xlsx::S_TOT_INT, 'pct' => WCSS_Xlsx::S_TOT_PCT, 'text' => WCSS_Xlsx::S_TOT_LABEL ];
            $rows[] = array_map( function ( $c, $i ) use ( $r, $tmap ) {
                if ( $i === 0 ) return [ 'Total', WCSS_Xlsx::S_TOT_LABEL ];
                return isset( $r['totals'][ $c['key'] ] ) ? [ $c['type'] === 'int' ? (int) $r['totals'][ $c['key'] ] : (float) $r['totals'][ $c['key'] ], $tmap[ $c['type'] ] ] : [ '', WCSS_Xlsx::S_TOT_LABEL ];
            }, $cols, array_keys( $cols ) );
        }
        if ( ! $r['rows'] ) $rows[] = [ [ 'No data for these filters.', WCSS_Xlsx::S_MUTED ] ];

        $widths = array_map( function ( $c ) use ( $r ) {
            if ( $c['type'] !== 'text' ) return 15;
            $max = mb_strlen( $c['label'] );
            foreach ( array_slice( $r['rows'], 0, 300 ) as $row ) $max = max( $max, mb_strlen( (string) ( $row[ $c['key'] ] ?? '' ) ) );
            return min( 48, max( 12, $max + 2 ) );
        }, $cols );
        $x->add_sheet( $r['title'], $rows, [
            'widths' => $widths, 'freeze' => $head, 'landscape' => true, 'heights' => [ 1 => 30, 2 => 26, $head => 22 ],
            'filter' => $r['rows'] ? 'A' . $head . ':' . $last . $end : '',
            'merges' => [ 'A1:' . WCSS_Xlsx::col( max( 0, $n - 2 ) ) . '1', 'A2:' . $last . '2', 'A3:' . $last . '3' ],
        ] );

        if ( $r['type'] === 'vendors' && ! empty( $r['chart']['items'] ) ) {
            $mrows = [ [ [ 'Month', WCSS_Xlsx::S_HEAD ], [ 'Amazon', WCSS_Xlsx::S_HEAD ], [ 'Grand & Toy', WCSS_Xlsx::S_HEAD ], [ 'Other / unassigned', WCSS_Xlsx::S_HEAD ], [ 'Total', WCSS_Xlsx::S_HEAD ] ] ];
            foreach ( $r['chart']['items'] as $m ) {
                $mrows[] = [ [ $m['label'], WCSS_Xlsx::S_CELL ], [ (float) $m['amazon'], WCSS_Xlsx::S_MONEY ], [ (float) $m['gt'], WCSS_Xlsx::S_MONEY ], [ (float) $m['other'], WCSS_Xlsx::S_MONEY ], [ (float) ( $m['amazon'] + $m['gt'] + $m['other'] ), WCSS_Xlsx::S_MONEY ] ];
            }
            $x->add_sheet( 'By month', $mrows, [ 'widths' => [ 14, 16, 16, 20, 16 ], 'freeze' => 1 ] );
        }
        return $x->save();
    }

    /** Branded, print-ready page. "Save as PDF" in the print dialog gives the PDF. */
    public static function print_html( array $r, bool $autoprint ): string {
        $s  = $r['summary'];
        $me = wp_get_current_user();
        $m  = function ( $v ) { return 'CA$' . number_format( (float) $v, 2 ); };
        $fmt = function ( $v, $t ) use ( $m ) {
            if ( $t === 'money' ) return $m( $v );
            if ( $t === 'pct' ) return number_format( (float) $v * 100, 1 ) . '%';
            if ( $t === 'int' ) return number_format( (int) $v );
            return esc_html( (string) $v );
        };
        $split = $s['spend'] > 0 ? [ $s['amazon'] / $s['spend'] * 100, $s['gt'] / $s['spend'] * 100 ] : [ 0, 0 ];
        ob_start();
        ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $r['title'] . ' — ' . $r['period'] ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
@page{size:A4 landscape;margin:12mm}
*{box-sizing:border-box}
body{margin:0;font:13px/1.45 Inter,system-ui,sans-serif;color:#16181D;background:#F4F5F7}
.sheet{max-width:1180px;margin:24px auto;background:#fff;border:1px solid #E4E7EC;border-radius:12px;overflow:hidden}
.band{background:#121417;color:#fff;padding:16px 28px;display:flex;justify-content:space-between;align-items:center;border-bottom:4px solid #C8372D}
.brand{font-weight:700;letter-spacing:.14em}.brand i{font-weight:500;letter-spacing:.02em}
.band small{color:#9AA1AC}
.body{padding:24px 28px}
h1{font-size:24px;margin:0 0 4px}
.sub{color:#5B6270;margin:0 0 4px}
.chips{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0 20px}
.chip{background:#EEF0F3;color:#4A515C;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600}
.kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px}
.kpi{border:1px solid #E4E7EC;border-radius:10px;padding:12px 14px}
.kpi b{display:block;font-size:20px;margin-top:2px}
.kpi span{font-size:12px;color:#5B6270}
.split{display:flex;height:10px;border-radius:999px;overflow:hidden;background:#EEF0F3;margin:6px 0 20px}
.split i{display:block}
.legend{display:flex;gap:16px;font-size:12px;color:#4A515C}
.legend em{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:-1px}
table{width:100%;border-collapse:collapse;font-size:12.5px}
th{background:#16181D;color:#fff;text-align:left;font-weight:600;padding:9px 10px;white-space:nowrap}
td{padding:8px 10px;border-bottom:1px solid #E4E7EC;vertical-align:top}
tr:nth-child(even) td{background:#FAFAFB}
.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
tfoot td{font-weight:700;background:#F4F5F7 !important;border-top:2px solid #16181D;border-bottom:2px solid #16181D}
.empty{padding:32px;text-align:center;color:#5B6270}
.foot{display:flex;justify-content:space-between;color:#5B6270;font-size:11px;padding:14px 28px;border-top:1px solid #E4E7EC;background:#F9FAFB}
.bar{position:sticky;top:0;background:#16181D;color:#fff;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;gap:12px}
.bar button{font:600 13px Inter,sans-serif;border:0;border-radius:8px;padding:8px 14px;background:#fff;color:#16181D;cursor:pointer}
@media print{body{background:#fff}.sheet{margin:0;border:0;border-radius:0;max-width:none}.bar{display:none}thead{display:table-header-group}tr{break-inside:avoid}
  .band,th,tfoot td,.split i,.legend em,tr:nth-child(even) td{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
@media (max-width:760px){.kpis{grid-template-columns:repeat(2,1fr)}.body{padding:16px}.table-wrap{overflow-x:auto}}
</style></head><body>
<div class="bar"><span>Print preview — choose <strong>Save as PDF</strong> in the print dialog to get a PDF.</span><button type="button" onclick="window.print()">Print / Save PDF</button></div>
<div class="sheet">
  <div class="band"><span class="brand">CANNA <i>Cabana</i></span><small>Supply Manager · Report</small></div>
  <div class="body">
    <h1><?php echo esc_html( $r['title'] ); ?></h1>
    <p class="sub"><?php echo esc_html( $r['period'] ); ?></p>
    <div class="chips"><?php foreach ( $r['filters_text'] as $t ) echo '<span class="chip">' . esc_html( $t ) . '</span>'; ?></div>
    <div class="kpis">
      <div class="kpi"><span>Total spend</span><b><?php echo esc_html( $m( $s['spend'] ) ); ?></b></div>
      <div class="kpi"><span>Amazon</span><b><?php echo esc_html( $m( $s['amazon'] ) ); ?></b></div>
      <div class="kpi"><span>Grand &amp; Toy</span><b><?php echo esc_html( $m( $s['gt'] ) ); ?></b></div>
      <div class="kpi"><span>Approved orders</span><b><?php echo (int) $s['orders']; ?></b></div>
      <div class="kpi"><span>Stores ordered</span><b><?php echo (int) $s['stores_ordered']; ?> <small style="font-size:12px;color:#5B6270">of <?php echo (int) $s['stores_total']; ?></small></b></div>
    </div>
    <div class="legend"><span><em style="background:#16181D"></em>Amazon <?php echo esc_html( number_format( $split[0], 1 ) ); ?>%</span><span><em style="background:#C8372D"></em>Grand &amp; Toy <?php echo esc_html( number_format( $split[1], 1 ) ); ?>%</span><span><em style="background:#C9CDD4"></em>Other / unassigned</span></div>
    <div class="split"><i style="width:<?php echo esc_attr( $split[0] ); ?>%;background:#16181D"></i><i style="width:<?php echo esc_attr( $split[1] ); ?>%;background:#C8372D"></i><i style="flex:1;background:#C9CDD4"></i></div>
    <div class="table-wrap">
    <?php if ( ! $r['rows'] ) : ?>
      <div class="empty">No data for these filters.</div>
    <?php else : ?>
    <table>
      <thead><tr><?php foreach ( $r['columns'] as $c ) echo '<th class="' . ( $c['type'] !== 'text' ? 'num' : '' ) . '">' . esc_html( $c['label'] ) . '</th>'; ?></tr></thead>
      <tbody>
      <?php foreach ( $r['rows'] as $row ) : ?>
        <tr><?php foreach ( $r['columns'] as $c ) echo '<td class="' . ( $c['type'] !== 'text' ? 'num' : '' ) . '">' . $fmt( $row[ $c['key'] ] ?? '', $c['type'] ) . '</td>'; // phpcs:ignore ?></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ( $r['totals'] ) : ?>
      <tfoot><tr><?php foreach ( $r['columns'] as $i => $c ) echo '<td class="' . ( $c['type'] !== 'text' ? 'num' : '' ) . '">' . ( $i === 0 ? 'Total' : ( isset( $r['totals'][ $c['key'] ] ) ? $fmt( $r['totals'][ $c['key'] ], $c['type'] ) : '' ) ) . '</td>'; // phpcs:ignore ?></tr></tfoot>
      <?php endif; ?>
    </table>
    <?php endif; ?>
    </div>
  </div>
  <div class="foot"><span>Generated <?php echo esc_html( $r['generated'] ); ?> by <?php echo esc_html( $me->display_name ?: $me->user_login ); ?> · Spend = approved orders, item totals before tax and shipping</span><span>Canna Cabana Supply Operations</span></div>
</div>
<?php if ( $autoprint ) : ?><script>window.addEventListener('load',function(){setTimeout(function(){window.print()},400)})</script><?php endif; ?>
</body></html>
        <?php
        return (string) ob_get_clean();
    }
}
