<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/reports-test.php
 * Tests Supply Manager → Reports: every report type, filters, totals, presets, CSV / Excel / print exports
 * and permissions. Adds known throwaway orders on "Test store" (#8177) and checks the change they make,
 * so existing data doesn't matter. Blocks every email and deletes everything it created.
 * Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }
$ledger_ym = (new WC_DateTime())->date_i18n('Y-m'); $ledger_bk = WCSS_Ledger::get(8177, $ledger_ym); // Throwaway orders must not change Test store's month totals.
$ADMIN = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
wp_set_current_user($ADMIN);
add_filter('pre_wp_mail', '__return_false', 1);
// Keep auto-submit away from real vendors while orders change status.
add_filter('cc_auto_vendor_submit_enabled', '__return_false');

$SID = 8177; $PROD = 8176; $USER = 243;
$pass = 0; $fail = 0; $orders = []; $presets_before = get_option(WCSS_Reports::PRESETS, null);
function ok($n, $c, $i = '') { global $pass, $fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n  $i\n"; } }
function near($a, $b) { return abs((float) $a - (float) $b) < 0.005; }
function rest($m, $p, $b = null, $q = []) { $r = new WP_REST_Request($m, $p); foreach ($q as $k => $v) $r->set_param($k, $v);
  if ($b !== null) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($b)); }
  $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; }
function run($q) { return WCSS_Reports::run(WCSS_Reports::filters($q)); }
function row($r, $key, $val) { foreach ($r['rows'] as $x) if (($x[$key] ?? null) === $val) return $x; return null; }
/** One-line order with a fixed line total and vendor tag. */
function mk($total, $vendor, $status, $policy = null) { global $SID, $PROD, $USER, $orders;
  $o = new WC_Order(); $o->set_customer_id($USER);
  $id = $o->add_product(wc_get_product($PROD), 2, ['subtotal' => $total, 'total' => $total]);
  if ($vendor) { $it = $o->get_item($id); $it->add_meta_data('_cc_vendor', $vendor, true); $it->save(); }
  $o->update_meta_data('_wcss_store_id', $SID);
  if ($policy) $o->update_meta_data('_wcss_policy', $policy);
  $o->calculate_totals(); $o->save(); $orders[] = $o->get_id();
  $o->set_status($status); $o->save(); return $o->get_id(); }

$Q = ['range' => 'this_month', 'store' => (string) $SID];
try {
  // ---------- baseline, then add known orders ----------
  $before = [ 'v' => run($Q + ['type' => 'vendors']), 's' => run($Q + ['type' => 'stores']), 'e' => run($Q + ['type' => 'exceptions']), 'o' => run($Q + ['type' => 'orders']), 'vr' => run($Q + ['type' => 'vendors', 'status' => 'rejected']) ];
  $amz0 = $before['v']['summary']['amazon']; $gt0 = $before['v']['summary']['gt'];
  $a = mk(20.00, 'amazon', 'approved');
  $g = mk(30.00, 'gt', 'approved');
  $x = mk(99.00, 'gt', 'rejected', ['in_policy' => false, 'reasons' => ['over_budget'], 'messages' => ['Over budget']]);
  $w = mk(15.00, 'amazon', 'awaiting-approval', ['in_policy' => false, 'reasons' => ['over_quota'], 'messages' => ['Second order']]);

  // ---------- vendors ----------
  $v = run($Q + ['type' => 'vendors']);
  ok('Amazon spend +20 (approved only)', near($v['summary']['amazon'] - $amz0, 20), $v['summary']['amazon'] . ' vs ' . $amz0);
  ok('Grand & Toy spend +30 (rejected 99 not counted)', near($v['summary']['gt'] - $gt0, 30));
  ok('Vendor rows: Amazon and Grand & Toy present', row($v, 'key', 'amazon') && row($v, 'key', 'gt'));
  ok('Vendor shares add up to 100%', near(array_sum(array_column($v['rows'], 'share')), 1));
  ok('Vendor total spend = sum of rows', near($v['totals']['spend'], array_sum(array_column($v['rows'], 'spend'))));
  ok('Vendor totals count each order once', $v['totals']['orders'] <= array_sum(array_column($v['rows'], 'orders')) && $v['totals']['orders'] === count(WCSS_Reports::orders(WCSS_Reports::filters($Q + ['type' => 'vendors']))));
  ok('Vendor chart has a month series', ($v['chart']['kind'] ?? '') === 'months' && count($v['chart']['items']) === 1);
  $va = run($Q + ['type' => 'vendors', 'vendor' => 'amazon']);
  ok('Vendor filter = Amazon → only Amazon row', count($va['rows']) === 1 && $va['rows'][0]['key'] === 'amazon');
  $vr = run($Q + ['type' => 'vendors', 'status' => 'rejected']);
  ok('Status = Rejected: G&T rejected spend +99', near((row($vr, 'key', 'gt')['spend'] ?? 0) - (row($before['vr'], 'key', 'gt')['spend'] ?? 0), 99));

  // ---------- stores / products / users ----------
  $s = run($Q + ['type' => 'stores']);
  $srow = $s['rows'][0] ?? [];
  $prev = $before['s']['rows'][0]['spend'] ?? 0;
  ok('Store report: Test store spend +50', count($s['rows']) === 1 && near($srow['spend'] - $prev, 50), json_encode($srow));
  $ink = array_sum(array_column(array_filter(run($Q + ['type' => 'orders', 'status' => 'approved'])['rows'], function ($r) { return $r['type'] === 'Ink'; }), 'total'));
  ok('Store report: budget used = non-ink spend / budget', $srow['budget'] > 0 && near($srow['used'], ($srow['spend'] - $ink) / $srow['budget']));
  $p = run($Q + ['type' => 'products', 'product' => (string) $PROD]);
  ok('Product filter: one product row with units counted', count($p['rows']) === 1 && $p['rows'][0]['units'] >= 4);
  $u = run($Q + ['type' => 'users', 'user' => (string) $USER]);
  ok('User filter: only the store employee', count($u['rows']) === 1 && $u['rows'][0]['email'] === get_userdata($USER)->user_email);
  ok('Province filter with no match → empty', count(run($Q + ['type' => 'stores', 'province' => 'Nowhere'])['rows']) === 0);

  // ---------- exceptions ----------
  $e = run($Q + ['type' => 'exceptions']);
  $ex = row($e, 'id', $x); $ew = row($e, 'id', $w);
  ok('Exceptions: rejected over-budget order listed as Rejected', $ex && $ex['outcome'] === 'Rejected' && strpos($ex['reasons'], 'Over monthly budget') !== false, json_encode($ex));
  ok('Exceptions: second-order hold listed as Waiting', $ew && $ew['outcome'] === 'Waiting for Supply Ops' && strpos($ew['reasons'], 'Second order') !== false);
  ok('Exceptions: normal approved order not listed', ! row($e, 'id', $a));
  $ov = wc_get_order($w); $ov->update_meta_data('_wcss_policy_override', ['by' => get_current_user_id(), 'at' => 'now', 'reasons' => ['over_quota']]); $ov->set_status('approved'); $ov->save();
  $ew2 = row(run($Q + ['type' => 'exceptions']), 'id', $w);
  ok('Exceptions: override shows "Approved as exception" and who approved', $ew2 && $ew2['outcome'] === 'Approved as exception' && $ew2['by'] !== '');
  $er = run($Q + ['type' => 'exceptions', 'reason' => 'over_budget']);
  ok('Reason filter = Over budget → only matching orders', $er['rows'] && ! array_filter($er['rows'], function ($r) { return strpos($r['reasons'], 'Over monthly budget') === false; }));

  // ---------- coverage / orders ----------
  $c = run(['type' => 'coverage', 'range' => 'this_month']);
  ok('Coverage lists every published store', count($c['rows']) === (int) wp_count_posts('store_location')->publish);
  ok('Coverage: Test store shows Ordered', (row($c, 'store', html_entity_decode(get_the_title($SID), ENT_QUOTES))['ordered'] ?? '') === 'Ordered');
  $cn = run(['type' => 'coverage', 'range' => 'this_month', 'coverage' => 'not_ordered']);
  ok('Coverage "not ordered" excludes Test store', ! row($cn, 'store', html_entity_decode(get_the_title($SID), ENT_QUOTES)) && ! array_filter($cn['rows'], function ($r) { return $r['ordered'] === 'Ordered'; }));
  $o = run($Q + ['type' => 'orders']);
  ok('Order detail: all 4 new orders, any status', count($o['rows']) - count($before['o']['rows']) === 4);

  // ---------- dates ----------
  $cust = WCSS_Reports::filters(['range' => 'custom', 'from' => '2026-03-31', 'to' => '2026-03-01']);
  ok('Custom range: swapped dates are put in order', $cust['from'] === '2026-03-01' && $cust['to'] === '2026-03-31');
  [$lf, $lt] = WCSS_Reports::range_dates('last_month');
  ok('Last month = whole previous month', substr($lf, 8) === '01' && $lt === wp_date('Y-m-t', strtotime($lf)));
  ok('Old period has none of the new orders', count(run(['type' => 'orders', 'range' => 'custom', 'from' => '2001-01-01', 'to' => '2001-12-31'])['rows']) === 0);

  // ---------- REST + presets ----------
  [$st, $m] = rest('GET', '/wcss/v1/reports/meta');
  ok('Meta API: 7 report types, vendors incl. Amazon + G&T, stores, presets', $st === 200 && count($m['types']) === 7 && count($m['stores']) > 0 && in_array('gt', array_column($m['vendors'], 'key'), true) && count($m['presets']) >= 6);
  [$st, $d] = rest('GET', '/wcss/v1/reports/run', null, $Q + ['type' => 'vendors']);
  ok('Run API returns the same numbers as the engine', $st === 200 && near($d['summary']['amazon'], run($Q + ['type' => 'vendors'])['summary']['amazon']) && near($d['summary']['amazon'] - $amz0, 35));
  [$st, $pr] = rest('POST', '/wcss/v1/reports/presets', ['name' => 'QA preset · Amazon', 'filters' => ['type' => 'vendors', 'range' => 'last_month', 'vendor' => 'amazon', 'store' => '']]);
  ok('Save preset', $st === 200 && ($pr['filters']['vendor'] ?? null) === ['amazon'] && ! isset($pr['filters']['store']), json_encode($pr));
  ok('Preset keeps the range relative', ($pr['filters']['range'] ?? '') === 'last_month' && ! isset($pr['filters']['from']));
  ok('Duplicate preset name refused', rest('POST', '/wcss/v1/reports/presets', ['name' => 'qa preset · amazon', 'filters' => []])[0] === 409);
  ok('Empty preset name refused', rest('POST', '/wcss/v1/reports/presets', ['name' => '  ', 'filters' => []])[0] === 400);
  ok('Built-in preset can’t be deleted', rest('DELETE', '/wcss/v1/reports/presets/b-vendors-month')[0] === 400);
  ok('Delete saved preset', rest('DELETE', '/wcss/v1/reports/presets/' . $pr['id'])[0] === 200 && ! in_array($pr['id'], array_column(WCSS_Reports::presets(), 'id'), true));

  // ---------- dashboard matches the reports (SOW 1.28) ----------
  // The test orders above include a rejected G&T order (99.00) and an awaiting Amazon one (15.00): neither is spend.
  [$st, $ov] = rest('GET', '/wcss/v1/reports/overview');
  $vm = run(['type' => 'vendors', 'range' => 'this_month']);
  $ovk = []; foreach ($ov['vendors'] as $r) $ovk[$r['name']] = $r['revenue'];
  $ok_all = $st === 200 && count($ov['vendors']) === count($vm['rows']);
  foreach ($vm['rows'] as $r) $ok_all = $ok_all && isset($ovk[$r['vendor']]) && near($ovk[$r['vendor']], $r['spend']);
  ok('Dashboard top vendors = Spend by vendor report, per vendor', $ok_all, wp_json_encode([$ov['vendors'], $vm['rows']]));
  ok('Dashboard vendor spend adds up to the approved spend', near(array_sum(array_column($ov['vendors'], 'revenue')), $vm['summary']['spend']));
  ok('Rejected and waiting orders are not counted as vendor spend', near($vm['summary']['gt'] - run(['type' => 'vendors', 'range' => 'this_month', 'store' => '', 'vendor' => 'gt'])['summary']['gt'], 0) && ! near(array_sum(array_column($ov['vendors'], 'revenue')), $vm['summary']['spend'] + 99));

  // ---------- exports ----------
  $csv = WCSS_Reports::csv($v);
  $lines = array_map('str_getcsv', array_filter(explode("\n", trim(substr($csv, 3)))));
  ok('CSV: UTF-8 BOM, header row, one row per vendor, totals row', substr($csv, 0, 3) === "\xEF\xBB\xBF" && $lines[0][0] === 'Vendor' && count($lines) === count($v['rows']) + 2 && end($lines)[0] === 'Total');
  $evil = $v; $evil['rows'][0]['vendor'] = '=HYPERLINK("x")';
  ok('CSV: formula injection neutralised', strpos(WCSS_Reports::csv($evil), "'=HYPERLINK") !== false);
  $path = WCSS_Reports::xlsx($v);
  $zip = new ZipArchive(); $zok = $zip->open($path) === true;
  $wb = $zok ? $zip->getFromName('xl/workbook.xml') : '';
  $sh = $zok ? $zip->getFromName('xl/worksheets/sheet2.xml') : '';
  ok('Excel: valid file with Summary, report and By month sheets', $zok && strpos($wb, 'Summary') !== false && strpos($wb, 'Spend by vendor') !== false && strpos($wb, 'By month') !== false);
  ok('Excel: frozen header, filter and numeric cells', strpos($sh, 'state="frozen"') !== false && strpos($sh, '<autoFilter') !== false && preg_match('/<c r="E6" s="5"><v>[0-9.]+<\/v>/', $sh));
  if ($zok) $zip->close(); @unlink($path);
  $html = WCSS_Reports::print_html($v, true);
  ok('Print/PDF: branded page with title, totals and auto-print', strpos($html, 'CANNA') !== false && strpos($html, 'Spend by vendor') !== false && strpos($html, '<tfoot>') !== false && strpos($html, 'window.print()') !== false);

  // ---------- permissions ----------
  wp_set_current_user($USER);
  ok('Store employee cannot run reports', rest('GET', '/wcss/v1/reports/run', null, ['type' => 'vendors'])[0] === 403);
  ok('Store employee cannot save presets', rest('POST', '/wcss/v1/reports/presets', ['name' => 'x', 'filters' => []])[0] === 403);
} finally {
  $ledger_now = WCSS_Ledger::get(8177, $ledger_ym); WCSS_Ledger::bump(8177, $ledger_ym, (int) $ledger_bk['orders_count'] - (int) $ledger_now['orders_count'], (float) $ledger_bk['spend_total'] - (float) $ledger_now['spend_total']);
  wp_set_current_user($ADMIN);
  global $wpdb;
  if ($orders) $wpdb->query("DELETE FROM {$wpdb->prefix}wcss_email_logs WHERE subject REGEXP '#(" . implode('|', array_map('intval', $orders)) . ")([^0-9]|$)'");
  foreach ($orders as $id) { if (function_exists('as_unschedule_all_actions')) as_unschedule_all_actions('cc_auto_vendor_submit_async', ['order_id' => $id], 'cc-auto-vendor'); $z = wc_get_order($id); if ($z) $z->delete(true); }
  if ($presets_before === null) delete_option(WCSS_Reports::PRESETS); else update_option(WCSS_Reports::PRESETS, $presets_before, false);
  echo "cleanup: deleted ", count($orders), " test orders and their log rows; presets restored; nothing was emailed\n";
  echo "RESULT: $pass passed, $fail failed\n";
}
