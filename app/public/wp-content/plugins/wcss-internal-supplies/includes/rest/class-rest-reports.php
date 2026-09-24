<?php
if ( ! defined('ABSPATH') ) exit;

class WCSS_REST_Reports {
    const NS = 'wcss/v1';

    public function __construct() { add_action('rest_api_init', [ $this, 'routes' ] ); }
    public function can_manage( $r = null ): bool {
        return current_user_can('wcss_manage_portal') || current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    public function routes() {
        register_rest_route( self::NS, '/reports/overview', [
            'methods'  => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'dashboard' ],
            'args'     => [
                'month' => [ 'sanitize_callback' => 'sanitize_text_field' ], // YYYY-MM
                'range' => [ 'sanitize_callback' => 'sanitize_text_field' ], // default 6m
            ],
        ]);


        register_rest_route(self::NS, '/reports/overview.csv', [
            'methods' => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'overview_csv' ],
            'args' => ['month'=>['sanitize_callback'=>'sanitize_text_field']],
          ]);
          
          register_rest_route(self::NS, '/reports/overview.pdf', [
            'methods' => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'overview_pdf' ],
            'args' => ['month'=>['sanitize_callback'=>'sanitize_text_field']],
          ]);


    }

    public function overview_csv( WP_REST_Request $req ) {
        // $data = $this->dashboard($req); // call your existing dashboard()’s core logic (extract it into a shared method), or just re-run its queries

        $data = $this->dashboard( $req );
        if ( $data instanceof WP_REST_Response ) {
            $data = $data->get_data(); // <-- unwrap to the plain array
        }
        $rows = [];
        $rows[] = ['Month',$data['month']];
        $rows[] = [];
        $rows[] = ['Status','Count'];
        foreach ($data['counts'] as $k=>$v) $rows[] = [$k,$v];
        $rows[] = [];
        $rows[] = ['Stores Total',$data['stores']['total']??0,'Active',$data['stores']['active']??0];
        $rows[] = ['Orders',$data['sales']['orders']??0,'Revenue',$data['sales']['revenue']??0,$data['sales']['currency']??''];
        $rows[] = [];
        $rows[] = ['Trend (ym)','orders'];
        foreach ($data['trend'] as $t) $rows[] = [$t['ym'],$t['orders']];
        $rows[] = [];
        $rows[] = ['Top Vendors: name','orders','revenue'];
        foreach ($data['vendors'] as $v) $rows[] = [$v['name'],$v['orders'],$v['revenue']];
        $rows[] = [];
        $rows[] = ['Ledger: store','orders','spend','quota','budget'];
        foreach ($data['ledger'] as $r) $rows[] = [ $r['store'], $r['orders']??$r['used_orders']??0, $r['spend']??$r['used_amount']??0, $r['quota']??0, $r['budget']??0 ];
      
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=dashboard-'.$data['month'].'.csv');
        $out = fopen('php://output', 'w');
        foreach ($rows as $row) fputcsv($out, $row);
        fclose($out);
        exit;
      }
      
    //   public function overview_pdf( WP_REST_Request $req ) {
    //     $data = $this->dashboard( $req );
    //     if ( $data instanceof WP_REST_Response ) {
    //         $data = $data->get_data(); // <-- unwrap to the plain array
    //     }
    //     header('Content-Type: application/octet-stream');
    //     header('Content-Disposition: attachment; filename=dashboard-'.$data['month'].'.html');
    //     echo '<h1>Dashboard '.$data['month'].'</h1>';
    //     echo '<pre>'.esc_html(print_r($data,true)).'</pre>';
    //     exit;
    //   }

    public function overview_pdf( WP_REST_Request $req ) {

        $data = $this->dashboard( $req );

        if ( $data instanceof WP_REST_Response ) {
            $data = $data->get_data(); 
        }

        // var_dump($data);
        // exit;
    
        ob_start(); ?>
        <!doctype html>
        <html>
        <head>
          <meta charset="utf-8">
          <title>Dashboard Report — <?php echo esc_html($data['month']); ?></title>
          <style>
            body {
              font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
              margin: 40px;
              background: #f7f8fa;
              color: #222;
            }
            h1 { font-size: 24px; margin-bottom: 20px; }
            h2 { font-size: 18px; margin: 24px 0 12px; }
    
            /* Header Stats */
            .stats-grid {
              display: grid;
              grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
              gap: 16px;
              margin-bottom: 24px;
            }
            .stat-box {
              background: #fff;
              border: 1px solid #e5e7eb;
              border-radius: 10px;
              padding: 16px;
              text-align: center;
              box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            }
            .stat-box h3 {
              font-size: 15px;
              color: #6b7280;
              margin: 0 0 8px;
            }
            .stat-box p {
              font-size: 20px;
              font-weight: 600;
              margin: 0;
              color: #111827;
            }
    
            /* Panels */
            .panel {
              background: #fff;
              border-radius: 10px;
              border: 1px solid #e5e7eb;
              padding: 16px 20px;
              margin-bottom: 24px;
              box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            }
    
            /* Tables */
            table {
              width: 100%;
              border-collapse: collapse;
              margin-top: 10px;
            }
            th, td {
              border: 1px solid #e5e7eb;
              padding: 8px 10px;
              text-align: left;
              font-size: 14px;
            }
            th {
              background: #f3f4f6;
              color: #374151;
              font-weight: 600;
            }
            td { color: #111827; }
    
            /* Small notes */
            .muted {
              font-size: 13px;
              color: #6b7280;
            }
    
          </style>
        </head>
        <body>
    
          <h1>Dashboard Report — <?php echo esc_html($data['month']); ?></h1>
    
          <div class="stats-grid">
            <div class="stat-box">
              <h3>Pending</h3><p><?php echo (int)($data['counts']['pending'] ?? 0); ?></p>
            </div>
            <div class="stat-box">
              <h3>Approved</h3><p><?php echo (int)($data['counts']['approved'] ?? 0); ?></p>
            </div>
            <div class="stat-box">
              <h3>Rejected</h3><p><?php echo (int)($data['counts']['rejected'] ?? 0); ?></p>
            </div>
            <div class="stat-box">
              <h3>Orders Cost (this month)</h3><p><?php echo esc_html($data['sales']['currency'] ?? '') . number_format((float)($data['sales']['revenue'] ?? 0), 2); ?></p>
            </div>
          </div>
    
          <div class="panel">
            <h2>Stores</h2>
            <p>Total: <?php echo (int)($data['stores']['total'] ?? 0); ?></p>
            <p>Active this month: <?php echo (int)($data['stores']['active'] ?? 0); ?></p>
          </div>
    
          <div class="panel">
            <h2>Top Vendors (this month)</h2>
            <table>
              <thead><tr><th>Vendor</th><th>Orders</th><th>Revenue</th></tr></thead>
              <tbody>
                <?php foreach ( ($data['vendors'] ?? []) as $v ): ?>
                  <tr>
                    <td><?php echo esc_html($v['name'] ?? '-'); ?></td>
                    <td><?php echo (int)($v['orders'] ?? 0); ?></td>
                    <td><?php echo esc_html($data['sales']['currency'] ?? '') . number_format((float)($v['revenue'] ?? 0), 2); ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($data['vendors'])): ?>
                  <tr><td colspan="3" class="muted">No vendor data available</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
    
          <div class="panel">
            <h2>Orders Trend (last 6 months)</h2>
            <table>
              <thead><tr><th>Month</th><th>Orders</th></tr></thead>
              <tbody>
                <?php foreach ( ($data['trend'] ?? []) as $t ): ?>
                  <tr>
                    <td><?php echo esc_html($t['ym'] ?? ''); ?></td>
                    <td><?php echo (int)($t['orders'] ?? 0); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
    
          <div class="panel">
            <h2>Store Budgets (active this month)</h2>
            <table>
              <thead><tr>
                <th>Store Name</th>
                <th>Store ID</th>
                <th>Orders</th>
                <th>Quota</th>
                <th>Spend</th>
                <th>Budget</th>
              </tr></thead>
              <tbody>
                <?php foreach ( ($data['ledger'] ?? []) as $r ): ?>
                  <tr>
                    <td><?php echo esc_html($r['store'] ?? ''); ?></td>
                    <td><?php echo esc_html($r['store_id'] ?? ''); ?></td>
                    <td><?php echo (int)($r['used_orders'] ?? $r['orders'] ?? 0); ?></td>
                    <td><?php echo (int)($r['quota'] ?? 0); ?></td>
                    <td><?php echo esc_html($data['sales']['currency'] ?? '') . number_format((float)($r['spend'] ?? $r['used_amount'] ?? 0), 2); ?></td>
                    <td><?php echo esc_html($data['sales']['currency'] ?? '') . number_format((float)($r['budget'] ?? 0), 2); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
    
          <p class="muted">Generated on <?php echo esc_html( date('Y-m-d H:i') ); ?></p>
    
        </body>
        </html>
        <?php
        $html = ob_get_clean();
        nocache_headers();
        status_header(200);

        if ( function_exists('fastcgi_finish_request') ) { /* noop, just avoid buffering issues */ }
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="dashboard-report.html"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        // Ensure nothing else is in the buffer
        while ( ob_get_level() ) { ob_end_clean(); }
        echo $html;
        exit;


        // $resp = new WP_REST_Response( $html, 200 );
        // $resp->set_headers([
        //     'Content-Type'        => 'text/html; charset=utf-8',
        //     'Content-Disposition' => 'attachment; filename="dashboard-report.html"',
        //     'Cache-Control'       => 'no-store, no-cache, must-revalidate, max-age=0',
        // ]);
        // return $resp;
    }


    public function dashboard( WP_REST_Request $req ) {
        // ---- helpers ----
        $status_slugs = [ 'pending','awaiting-approval','approved','rejected','processing','completed','cancelled','on-hold' ];
        $paid_like    = [ 'processing','completed','approved' ];

        $tz = wp_timezone();
        $ym_param = sanitize_text_field( (string) $req->get_param('month') );
        if ( $ym_param && preg_match('/^\d{4}-\d{2}$/', $ym_param) ) {
            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ym_param . '-01 00:00:00', $tz);
        } else {
            $start = (new DateTimeImmutable('first day of this month', $tz))->setTime(0,0,0);
        }
        $end   = $start->modify('first day of next month');

        $from = $start->format('Y-m-d H:i:s');
        $to   = $end->format('Y-m-d H:i:s');
        $ym   = $start->format('Y-m');

        // count helper
        $count_status = function( string $st ) use ( $from, $to ) : int {
            $q = wc_get_orders([
                'type'         => 'shop_order',
                'status'       => [ $st ],
                'date_created' => $from.'...'.$to,
                'paginate'     => true,
                'limit'        => 1,
                'return'       => 'ids',
            ]);
            return (int) ( is_object($q) ? $q->total : 0 );
        };

        // ---- counts by status ----
        $counts = [];
        foreach ( $status_slugs as $st ) {
            $counts[$st] = $count_status($st);
        }

        // ---- sales (orders + revenue) this month ----
        $orders_total = 0;
        $revenue = 0.0;
        $page = 1;
        do {
            $res = wc_get_orders([
                'type'         => 'shop_order',
                'status'       => $paid_like,
                'date_created' => $from.'...'.$to,
                'paginate'     => true,
                'limit'        => 200,
                'page'         => $page,
                'return'       => 'objects',
            ]);
            $orders_total = (int) ( is_object($res) ? $res->total : $orders_total );
            $orders_page  = is_object($res) ? $res->orders : [];
            foreach ( $orders_page as $o ) {
                $revenue += (float) $o->get_total();
            }
            $page++;
        } while ( is_object($res) && $page <= max(1,(int)$res->max_num_pages) );

        // ---- stores: total + active (had orders this month) ----
        $stores_total = (int) ( new WP_Query([
            'post_type'      => 'store_location',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ]) )->found_posts;

        $active_store_ids = [];
        $page = 1;
        do {
            $scan = wc_get_orders([
                'type'         => 'shop_order',
                'date_created' => $from.'...'.$to,
                'status'       => array_keys( wc_get_order_statuses() ),
                'paginate'     => true,
                'limit'        => 200,
                'page'         => $page,
                'return'       => 'objects',
            ]);
            $orders_page = is_object($scan) ? $scan->orders : [];
            foreach ( $orders_page as $o ) {
                $sid = (int) $o->get_meta('_wcss_store_id');
                if ( $sid ) { $active_store_ids[$sid] = true; }
            }
            $page++;
        } while ( is_object($scan) && $page <= max(1,(int)$scan->max_num_pages) );
        $stores_active = count($active_store_ids);

        // ---- trend: last 6 months count ----
        $trend = [];
        $first = (new DateTimeImmutable('first day of this month', $tz))->modify('-5 months');
        for ( $i=0; $i<6; $i++ ) {
            $mStart = $first->modify("+{$i} months")->setTime(0,0,0);
            $mEnd   = $mStart->modify('first day of next month');
            $q = wc_get_orders([
                'type'         => 'shop_order',
                'date_created' => $mStart->format('Y-m-d H:i:s').'...'.$mEnd->format('Y-m-d H:i:s'),
                'paginate'     => true,
                'limit'        => 1,
                'return'       => 'ids',
            ]);
            $trend[] = [
                'ym'     => $mStart->format('Y-m'),
                'orders' => (int) ( is_object($q) ? $q->total : 0 ),
            ];
        }

        // ---- top vendors (this month) ----
        $vendorAgg = [];  // name => [orders, revenue]
        $vendorId  = [];  // name => term_id
        $page = 1;
        do {
            $scan = wc_get_orders([
                'type'         => 'shop_order',
                'date_created' => $from.'...'.$to,
                'status'       => array_keys( wc_get_order_statuses() ),
                'paginate'     => true,
                'limit'        => 100,
                'page'         => $page,
                'return'       => 'objects',
            ]);
            $orders_page = is_object($scan) ? $scan->orders : [];
            foreach ( $orders_page as $o ) {
                $seen = [];
                foreach ( $o->get_items('line_item') as $it ) {
                    $pid = $it->get_product_id() ?: $it->get_variation_id();
                    if ( ! $pid ) continue;
                    $terms = wp_get_post_terms( $pid, 'wcss_vendor' );
                    if ( is_wp_error($terms) || empty($terms) ) continue;
                    $t = $terms[0];
                    $name = $t->name;
                    $vendorId[$name] = (int) $t->term_id;
                    if ( empty($vendorAgg[$name]) ) $vendorAgg[$name] = ['orders'=>0,'revenue'=>0.0];
                    if ( empty($seen[$name]) ) { $vendorAgg[$name]['orders']++; $seen[$name]=true; }
                    $vendorAgg[$name]['revenue'] += (float) $it->get_total();
                }
            }
            $page++;
        } while ( is_object($scan) && $page <= max(1,(int)$scan->max_num_pages) );

        $vendors = [];
        foreach ( $vendorAgg as $name => $agg ) {
            $vendors[] = [
                'id'      => $vendorId[$name] ?? 0,
                'name'    => $name,
                'orders'  => (int) $agg['orders'],
                'revenue' => (float) $agg['revenue'],
            ];
        }
        usort($vendors, fn($a,$b) => $b['revenue'] <=> $a['revenue']);
        $vendors = array_slice($vendors, 0, 10);

        // ---- per-store ledger for month (WITH store name, quota, budget) ----
        $ledger_map = []; // store_id => row
        $page = 1;
        do {
            $scan2 = wc_get_orders([
                'type'         => 'shop_order',
                'date_created' => $from.'...'.$to,
                'status'       => array_keys( wc_get_order_statuses() ),
                'paginate'     => true,
                'limit'        => 200,
                'page'         => $page,
                'return'       => 'objects',
            ]);
            $orders_page = is_object($scan2) ? $scan2->orders : [];
            foreach ( $orders_page as $o ) {

                // $sid = (int) $o->get_meta('_wcss_store_id');

                // $sid = (int) $o->get_meta('_wcss_store_id');


                $store_name = get_the_title( $sid );
                if ( ! $sid ) continue;

                if ( empty($ledger_map[$sid]) ) {
                    $quota  = (int) get_post_meta( $sid, '_store_quota',  true );
                    $budget = (float) get_post_meta( $sid, '_store_budget', true );

                    $ledger_map[$sid] = [
                        'store_id' => $sid,
                        'store'    => get_the_title($sid) ?: ('#'.$sid), // <-- store name here
                        'orders'   => 0,
                        'spend'    => 0.0,
                        'quota'    => $quota,
                        'budget'   => $budget,
                        'used_orders'  => 0,     // for compatibility with older UI if needed
                        'used_amount'  => 0.0,   // "
                    ];
                }
                $ledger_map[$sid]['orders']      += 1;
                $ledger_map[$sid]['spend']       += (float) $o->get_total();
                $ledger_map[$sid]['used_orders'] += 1;                 // compat
                $ledger_map[$sid]['used_amount'] += (float) $o->get_total(); // compat
            }
            $page++;
        } while ( is_object($scan2) && $page <= max(1,(int)$scan2->max_num_pages) );

        $ledger = array_values($ledger_map);

        return rest_ensure_response([
            'month'  => $ym,
            'counts' => $counts,
            'stores' => [ 'total' => (int)$stores_total, 'active' => (int)$stores_active ],
            'sales'  => [ 'orders' => (int)$orders_total, 'revenue' => (float)$revenue, 'currency' => get_woocommerce_currency() ],
            'trend'  => $trend,
            'vendors'=> $vendors,
            'ledger' => $ledger,
        ]);
    }

} 
