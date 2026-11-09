<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/ink-and-delete-test.php
 * Ink order policy, ink vs the stationery budget, order delete, and vendor assignment.
 * Blocks every email and outbound HTTP call, turns auto vendor submit off, and cleans up after itself.
 * Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }

add_filter('pre_wp_mail', '__return_false', 1);
add_filter('pre_http_request', function () { return new WP_Error('blocked', 'No outbound HTTP in tests'); }, 1);
add_filter('cc_auto_vendor_submit_enabled', '__return_false');

$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
$owner = get_user_by('login', 'storeowner')->ID;
wp_set_current_user($admin);

$SID = 8177; $PROD = 8176;
$ink_ids = get_objects_in_term(WCSS_Ink_Order::category_ids(), 'product_cat');
$INK = 0; foreach ($ink_ids as $id) { $p = wc_get_product($id); if ($p && $p->is_type('simple') && $p->get_status() === 'publish') { $INK = (int) $id; break; } }
$created = []; $products = []; $pass = 0; $fail = 0; $warnings = [];
set_error_handler(function ($no, $msg, $file, $line) use (&$warnings) { if (strpos($file, 'wcss-internal-supplies') !== false) $warnings[] = "$msg @ " . basename($file) . ":$line"; return false; }, E_WARNING | E_NOTICE);

function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }
function near($a, $b) { return abs($a - $b) < 0.01; }
function rest($m, $p, $b = null) { $r = new WP_REST_Request($m, $p); if ($b !== null) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($b)); } $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; }
function mk($pid, $ink, $status = 'awaiting-approval') { global $SID, $owner, $created;
  $o = wc_create_order(['customer_id' => $owner]); $o->add_product(wc_get_product($pid), 1); $o->calculate_totals();
  $o->update_meta_data('_wcss_store_id', $SID);
  if ($ink) $o->update_meta_data(WCSS_Ink_Order::META, WCSS_Ink_Order::TYPE_INK);
  $o->save(); $o->set_status($status); $o->save(); $created[] = $o->get_id(); return wc_get_order($o->get_id()); }
function ledger() { global $SID; $r = WCSS_Ledger::get($SID, (new WC_DateTime())->date_i18n('Y-m')); return [(int) $r['orders_count'], (float) $r['spend_total']]; }

ok('Fixtures: a published ink product exists', $INK > 0);
$ym = (new WC_DateTime())->date_i18n('Y-m');
$bk_ledger = WCSS_Ledger::get($SID, $ym);
try {
  // ---------- ink policy ----------
  [$c0, $a0] = WCSS_Order_Policy::month_usage($SID);
  $i = mk($INK, true);
  $res = WCSS_Order_Policy::evaluate_order($i);
  ok('Ink order always needs review', ! $res['in_policy'] && $res['reasons'] === ['ink_review'], json_encode($res));
  $missing = array_diff(['quota', 'budget', 'used_orders', 'used_amount', 'order_amount', 'store_id'], array_keys($res['checks']));
  ok('Ink result keeps every check callers read', ! $missing, json_encode($missing));
  ok('Ink result is tagged', ($res['checks']['order_type'] ?? '') === 'ink');
  [$c1, $a1] = WCSS_Order_Policy::month_usage($SID);
  ok('Ink does not use stationery quota / budget', $c1 === $c0 && near($a1, $a0), "$c0/$a0 -> $c1/$a1");

  // ---------- approving ink ----------
  $L0 = ledger();
  [$st, $d] = rest('POST', "/wcss/v1/orders/{$i->get_id()}/status", ['status' => 'approved']);
  ok('Ink approve asks first (409)', $st === 409, "$st");
  ok('Ink approve message is about ink, not a policy breach', strpos($d['message'] ?? '', 'Ink orders always need') === 0 && strpos($d['message'], 'outside') === false, $d['message'] ?? '');
  ok('Ink 409 carries the reason the dialog reads', in_array('ink_review', (array) ($d['data']['reasons'] ?? []), true), json_encode($d['data'] ?? null));
  [$st] = rest('POST', "/wcss/v1/orders/{$i->get_id()}/status", ['status' => 'approved', 'override' => 1]);
  ok('Ink approve with confirmation goes through', $st === 200 && wc_get_order($i->get_id())->get_status() === 'approved', "$st");
  ok('Approved ink stays out of the store ledger', ledger() === $L0, json_encode([$L0, ledger()]));
  $gate = apply_filters('cc_auto_vendor_submit_policy', ['allowed' => true], wc_get_order($i->get_id()));
  ok('Approved ink may go to the vendor', ! empty($gate['allowed']), json_encode($gate));
  $h = mk($INK, true);
  $gate = apply_filters('cc_auto_vendor_submit_policy', ['allowed' => true], $h);
  ok('Unapproved ink is held from the vendor', empty($gate['allowed']), json_encode($gate));

  // ---------- budget figures ----------
  $f = WCSS_Reports::filters(['range' => 'this_month', 'store' => (string) $SID, 'type' => 'stores']);
  $r = WCSS_Reports::run($f)['rows'][0] ?? [];
  $before_used = $r['used'] ?? 0; $before_spend = $r['spend'] ?? 0;
  $i2 = mk($INK, true, 'approved');
  $r = WCSS_Reports::run($f)['rows'][0] ?? [];
  ok('Reports: ink counts as spend', near($r['spend'] - $before_spend, (float) $i2->get_total()), json_encode($r));
  ok('Reports: ink stays out of budget used', near($r['used'], $before_used), json_encode($r));

  // ---------- cart banner ----------
  wp_set_current_user($owner);
  if (function_exists('wc_load_cart')) wc_load_cart();
  add_filter('woocommerce_is_purchasable', '__return_true', 99); // fixture product has no vendor; ordering rules are tested in vendor-required-test.php
  WC()->cart->empty_cart(); WC()->cart->add_to_cart($INK, 1); WC()->cart->calculate_totals();
  ok('Fixture: cart has the ink item', WC()->cart->get_cart_contents_count() === 1);
  ob_start(); (new WCSS_Quota_Usage_UI())->render_banner(); $banner = ob_get_clean();
  ok('Ink cart banner explains approval and budget', strpos($banner, 'Ink order') !== false && strpos($banner, 'does not use your monthly budget') !== false, $banner);
  ok('Ink cart policy agrees', WCSS_Order_Policy::evaluate_cart()['reasons'] === ['ink_review']);
  ok('Cart holding ink is an ink order', WCSS_Ink_Order::cart_is_ink());
  $ids = function () { return array_values(array_map('intval', wp_list_pluck(WC()->cart->get_cart(), 'product_id'))); };
  WC()->cart->add_to_cart($PROD, 1);
  ok('Adding stationery replaces an ink cart', $ids() === [$PROD], json_encode($ids()));
  ok('That cart is no longer ink', ! WCSS_Ink_Order::cart_is_ink());
  apply_filters('woocommerce_add_to_cart_validation', true, $INK, 1);
  WC()->cart->add_to_cart($INK, 1);
  ok('Adding ink replaces a stationery cart', $ids() === [$INK], json_encode($ids()));
  $html = apply_filters('wc_add_to_cart_message_html', '<div class="cc-atc__actions"></div>', [$INK => 1], false);
  ok('Popup explains the cleared cart', strpos($html, 'cc-atc__note') !== false && strpos($html, 'removed from the cart') !== false, $html);
  WC()->cart->add_to_cart($INK, 1);
  ok('Same type just adds', $ids() === [$INK]);
  ok('Stock-in-cart message is empty', apply_filters('woocommerce_cart_product_not_enough_stock_already_in_cart_message', 'x') === '');
  wc_clear_notices();
  WC()->cart->empty_cart();
  ok('Empty cart is not an ink order', ! WCSS_Ink_Order::cart_is_ink());

  // ---------- delete: permissions + confirmation ----------
  $s = mk($PROD, false);
  [$st] = rest('DELETE', "/wcss/v1/orders/{$s->get_id()}", ['confirm' => $s->get_id()]);
  ok('Delete: store user refused', $st === 403, "$st");
  wp_set_current_user($admin);
  [$st] = rest('DELETE', "/wcss/v1/orders/{$s->get_id()}", []);
  ok('Delete: needs confirm', $st === 400, "$st");
  [$st] = rest('DELETE', "/wcss/v1/orders/{$s->get_id()}", ['confirm' => $s->get_id() + 1]);
  ok('Delete: confirm must match the order', $st === 400 && wc_get_order($s->get_id()), "$st");

  // ---------- delete: ledger + records ----------
  global $wpdb;
  $L1 = ledger();
  $s->set_status('approved'); $s->save();
  $L2 = ledger();
  ok('Approved stationery order enters the ledger', $L2[0] === $L1[0] + 1 && near($L2[1] - $L1[1], (float) $s->get_total()), json_encode([$L1, $L2]));
  WCSS_Order_Events::log($s->get_id(), 'note', ['note' => 'test']);
  $sid = $s->get_id();
  [$st, $d] = rest('DELETE', "/wcss/v1/orders/$sid", ['confirm' => $sid]);
  ok('Delete: succeeds', $st === 200 && ! empty($d['ok']), json_encode($d));
  ok('Delete: spend and count leave the store ledger', ledger() === $L1, json_encode([$L1, ledger()]));
  ok('Delete: order gone', ! wc_get_order($sid));
  foreach (['wc_orders' => 'id', 'wc_orders_meta' => 'order_id', 'woocommerce_order_items' => 'order_id', 'wcss_order_events' => 'order_id'] as $t => $col) {
    ok("Delete: nothing left in $t", 0 === (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}$t WHERE $col = %d", $sid)));
  }
  $L3 = ledger();
  $iid = $i2->get_id();
  [$st, $d] = rest('DELETE', "/wcss/v1/orders/$iid", ['confirm' => $iid]);
  ok('Delete approved ink leaves the ledger alone', $st === 200 && ledger() === $L3 && empty($d['removed']['monthly_ledger']), json_encode([$L3, ledger(), $d]));
  $p = mk($PROD, false);
  $L4 = ledger();
  [$st] = rest('DELETE', "/wcss/v1/orders/{$p->get_id()}", ['confirm' => $p->get_id()]);
  ok('Delete of a never-approved order leaves the ledger alone', $st === 200 && ledger() === $L4);
  [$st] = rest('DELETE', "/wcss/v1/orders/999999999", ['confirm' => 999999999]);
  ok('Delete: unknown order is 404', $st === 404, "$st");

  // ---------- one product → many vendors ----------
  $terms = []; foreach (get_terms(['taxonomy' => 'wcss_vendor', 'hide_empty' => false]) as $t) { $k = cc_vendor_prices_normalize_vendor($t->slug); if ($k) $terms[$k] = (int) $t->term_id; }
  [$st, $d] = rest('POST', '/wcss/v1/products', ['name' => 'TEST vendor picker', 'price' => '12.99', 'status' => 'draft', 'vendor_ids' => [$terms['amazon'], $terms['gt']], 'amazon_asin' => 'b0test1234', 'gt_part_id' => 'GT-1']);
  $pid = (int) ($d['id'] ?? 0); if ($pid) $products[] = $pid;
  ok('Create: both vendors assigned', $st === 200 && ($d['eligibility'] ?? '') === 'both', json_encode($d['eligibility'] ?? $d));
  ok('Create: price keeps its cents', (string) wc_get_product($pid)->get_regular_price() === '12.99', wc_get_product($pid)->get_regular_price());
  ok('Create: ASIN normalised, part ID saved', ($d['amazon_asin'] ?? '') === 'B0TEST1234' && ($d['gt_part_id'] ?? '') === 'GT-1');
  ok('Create: prices fetched right away', ! empty($d['price_sync']['ran']));
  [$st, $d] = rest('PUT', "/wcss/v1/products/$pid", ['vendor_ids' => [$terms['gt']]]);
  ok('Edit: untick Amazon', $st === 200 && $d['eligibility'] === 'gt' && $d['assigned_vendor_ids'] === [$terms['gt']], json_encode($d['eligibility'] ?? $d));
  [$st, $d] = rest('PUT', "/wcss/v1/products/$pid", ['vendor_ids' => []]);
  ok('Edit: neither live vendor = none, and not priced by either', $d['eligibility'] === 'none' && ! wcss_product_amazon_procurement_allowed($pid) && ! wcss_product_gt_procurement_allowed($pid));
  update_post_meta($pid, 'wcss_gt_part_id', 'GT-1');
  [$st, $d] = rest('POST', "/wcss/v1/products/$pid/refresh-prices");
  ok('Refresh prices: runs and reports', $st === 200 && ! empty($d['price_sync']['ran']), json_encode($d['price_sync'] ?? $d));

  ok('No PHP warnings from the plugin', ! $warnings, implode(' | ', array_unique($warnings)));
} finally {
  restore_error_handler();
  wp_set_current_user($admin);
  foreach ($created as $id) { if (wc_get_order($id)) WCSS_Order_Delete::purge($id); }
  foreach ($products as $id) { wp_delete_post($id, true); $GLOBALS['wpdb']->delete($GLOBALS['wpdb']->prefix . 'cc_vendor_prices', ['product_id' => $id]); }
  // Test churn must not move the store's real totals.
  $wpdb = $GLOBALS['wpdb'];
  $wpdb->update($wpdb->prefix . 'wcss_store_monthly_ledger', ['orders_count' => $bk_ledger['orders_count'], 'spend_total' => $bk_ledger['spend_total']], ['store_id' => $SID, 'ym' => $ym]);
  echo "cleanup: deleted ", count($created), " test orders, ", count($products), " test products\n";
  echo "RESULT: $pass passed, $fail failed\n";
}
