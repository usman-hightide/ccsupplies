<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/order-edit-test.php
 * Supply Ops edits items on an order awaiting approval (SOW 1.16): quantities, remove, add,
 * then total, budget, order limit and vendor are rechecked.
 * Blocks every email and outbound HTTP call, turns auto vendor submit off, and cleans up after itself.
 * Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }

$GLOBALS['mails'] = 0;
add_filter('pre_wp_mail', function () { $GLOBALS['mails']++; return false; }, 1);
add_filter('pre_http_request', function () { return new WP_Error('blocked', 'No outbound HTTP in tests'); }, 1);
add_filter('cc_auto_vendor_submit_enabled', '__return_false');
// Ordering weeks are tested in province-weeks-test.php.
add_filter('option_wcss_settings', function ($o) { $o['province_weeks_on'] = 0; $o['order_window_start'] = 1; $o['order_window_end'] = 31; $o['order_window_from'] = ''; $o['order_window_to'] = ''; return $o; });

$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
$owner = get_user_by('login', 'storeowner')->ID;
wp_set_current_user($admin);
$SID = 8177; $pass = 0; $fail = 0; $products = []; $orders = [];
$ym = (new WC_DateTime())->date_i18n('Y-m'); $bk_ledger = WCSS_Ledger::get($SID, $ym);

function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }
function rest($m, $p, $b = null, $q = []) { $r = new WP_REST_Request($m, $p); foreach ($q as $k => $v) $r->set_param($k, $v); if ($b !== null) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($b)); } $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; }
function product($name, $el, $cats = []) { global $products; $p = new WC_Product_Simple(); $p->set_name($name); $p->set_status('publish'); $p->set_regular_price(0); if ($cats) $p->set_category_ids($cats); $p->save(); $id = $p->get_id(); $products[] = $id;
  update_post_meta($id, 'wcss_procurement_eligibility', $el); cc_vendor_prices_recompute_cheapest($id); return $id; }
function live($pid, $v, $price) { cc_vendor_prices_upsert($pid, $v, ['status' => 'ok', 'price' => $price, 'currency' => 'CAD', 'vendor_sku' => 'SKU' . $v]); cc_vendor_prices_recompute_cheapest($pid); }
function order_with($pid, $qty, $price, $ink = false) { global $SID, $owner, $orders;
  $o = wc_create_order(['customer_id' => $owner]); $t = $price * $qty;
  $iid = $o->add_product(wc_get_product($pid), $qty, ['subtotal' => $t, 'total' => $t]);
  $it = $o->get_item($iid); $it->update_meta_data('_cc_vendor', 'amazon'); $it->update_meta_data('_cc_vendor_price', $price); $it->save();
  $o->update_meta_data('_wcss_store_id', $SID); $o->update_meta_data(WCSS_Ink_Order::META, $ink ? WCSS_Ink_Order::TYPE_INK : WCSS_Ink_Order::TYPE_STATION);
  $o->calculate_totals(); $o->save(); $o->set_status('awaiting-approval'); $o->save();
  $o->update_meta_data(WCSS_Order_Policy::META, WCSS_Order_Policy::evaluate_order($o)); $o->save();
  $orders[] = $o->get_id(); return $o->get_id(); }
function lines_of($id) { $out = []; foreach (wc_get_order($id)->get_items() as $iid => $it) $out[$it->get_name()] = ['item_id' => $iid, 'qty' => (int) $it->get_quantity(), 'total' => (float) $it->get_total(), 'vendor' => (string) $it->get_meta('_cc_vendor')]; return $out; }

$ink_cat = WCSS_Ink_Order::category_ids()[0] ?? 0;
$A   = product('TEST edit notebook', 'amazon');  update_post_meta($A, 'amazon_asin', 'B0EDIT0001'); live($A, 'amazon', 10);
$B   = product('TEST edit binder', 'amazon');    update_post_meta($B, 'amazon_asin', 'B0EDIT0002'); live($B, 'amazon', 25);
$G   = product('TEST edit stapler', 'gt');       update_post_meta($G, 'wcss_gt_part_id', 'GTEDIT1'); live($G, 'gt', 18);
$NOP = product('TEST edit noprice', 'none');
$INK = product('TEST edit toner', 'amazon', [$ink_cat]); update_post_meta($INK, 'amazon_asin', 'B0EDIT0003'); live($INK, 'amazon', 80);

try {
  ok('fixture: ink category exists', $ink_cat > 0);
  $O = order_with($A, 1, 10);
  $P = '/wcss/v1/orders/' . $O;
  $a = lines_of($O)['TEST edit notebook'];

  // product search
  list($c, $d) = rest('GET', "$P/edit-products", null, ['search' => 'TEST edit']);
  $names = array_column($d['items'] ?? [], 'name');
  ok('search lists orderable stationery', $c === 200 && in_array('TEST edit binder', $names, true) && in_array('TEST edit stapler', $names, true), wp_json_encode($d));
  ok('search hides the product already on the order', ! in_array('TEST edit notebook', $names, true));
  ok('search hides products with no vendor price', ! in_array('TEST edit noprice', $names, true));
  ok('search hides ink on a stationery order', ! in_array('TEST edit toner', $names, true));
  $gtrow = array_values(array_filter($d['items'], function ($x) { return $x['name'] === 'TEST edit stapler'; }))[0] ?? [];
  ok('search shows today\'s best price and vendor', (float) ($gtrow['price'] ?? 0) === 18.0 && ($gtrow['vendor_label'] ?? '') === 'Grand & Toy', wp_json_encode($gtrow));

  // quantity change
  list($c, $r) = rest('PUT', "$P/items", ['lines' => [['item_id' => $a['item_id'], 'qty' => 3]]]);
  $L = lines_of($O);
  ok('quantity change saved', $c === 200 && $L['TEST edit notebook']['qty'] === 3, wp_json_encode($r));
  ok('line total uses the original unit price', abs($L['TEST edit notebook']['total'] - 30) < 0.01);
  ok('order total recalculated', abs((float) wc_get_order($O)->get_total() - 30) < 0.01 && abs($r['after'] - 30) < 0.01 && abs($r['before'] - 10) < 0.01);
  ok('policy rechecked and saved', is_array(wc_get_order($O)->get_meta(WCSS_Order_Policy::META)) && abs(wc_get_order($O)->get_meta(WCSS_Order_Policy::META)['checks']['order_amount'] - 30) < 0.01);
  ok('order stays awaiting approval', wc_get_order($O)->get_status() === 'awaiting-approval');
  $notes = wc_get_order_notes(['order_id' => $O]);
  ok('order note records the edit', $notes && strpos($notes[0]->content, 'TEST edit notebook 1 → 3') !== false, $notes ? $notes[0]->content : '');
  $ev = array_values(array_filter(WCSS_Order_Events::for_order($O, 20), function ($e) { return $e['type'] === 'items_edit'; }));
  ok('history event logged with the change', $ev && ($ev[0]['payload']['changes'][0]['to'] ?? 0) === 3, wp_json_encode($ev));

  // add products: live vendor saved on the line
  list($c, $r) = rest('PUT', "$P/items", ['lines' => [['item_id' => $a['item_id'], 'qty' => 3], ['product_id' => $B, 'qty' => 2], ['product_id' => $G, 'qty' => 1]]]);
  $L = lines_of($O);
  ok('products added', $c === 200 && isset($L['TEST edit binder'], $L['TEST edit stapler']) && $L['TEST edit binder']['qty'] === 2, wp_json_encode($r));
  ok('added lines take the best vendor price', abs($L['TEST edit binder']['total'] - 50) < 0.01 && abs($L['TEST edit stapler']['total'] - 18) < 0.01);
  ok('added lines get their vendor', $L['TEST edit binder']['vendor'] === 'amazon' && $L['TEST edit stapler']['vendor'] === 'gt');
  ok('vendor recheck splits by vendor', ($r['vendors'] ?? []) === ['amazon', 'gt'] && ! $r['unassigned'], wp_json_encode($r['vendors'] ?? null));
  $lines = cc_auto_vendor_submit_collect_lines(wc_get_order($O));
  ok('approval would send the edited quantities', ($lines['amazon'][0]['qty'] ?? 0) === 3 && count($lines['amazon']) === 2 && count($lines['gt']) === 1);
  ok('total = 30 + 50 + 18', abs((float) wc_get_order($O)->get_total() - 98) < 0.01);

  // adding an existing product adds to its line
  $L = lines_of($O);
  list($c) = rest('PUT', "$P/items", ['lines' => [['item_id' => $L['TEST edit notebook']['item_id'], 'qty' => 3], ['item_id' => $L['TEST edit binder']['item_id'], 'qty' => 2], ['item_id' => $L['TEST edit stapler']['item_id'], 'qty' => 1], ['product_id' => $A, 'qty' => 1]]]);
  ok('adding a product already on the order raises its quantity', $c === 200 && lines_of($O)['TEST edit notebook']['qty'] === 4 && count(lines_of($O)) === 3);

  // remove a line
  $L = lines_of($O);
  list($c, $r) = rest('PUT', "$P/items", ['lines' => [['item_id' => $L['TEST edit notebook']['item_id'], 'qty' => 4], ['item_id' => $L['TEST edit binder']['item_id'], 'qty' => 0]]]);
  $L = lines_of($O);
  ok('qty 0 and left-out lines are removed', $c === 200 && count($L) === 1 && isset($L['TEST edit notebook']), wp_json_encode(array_keys($L)));
  ok('vendor recheck after removing G&T', ($r['vendors'] ?? []) === ['amazon']);
  $note = wc_get_order_notes(['order_id' => $O])[0]->content;
  ok('note names removed items', strpos($note, 'removed TEST edit binder') !== false && strpos($note, 'removed TEST edit stapler') !== false, $note);
  list($c, $r) = rest('PUT', "$P/items", ['lines' => [['item_id' => $L['TEST edit notebook']['item_id'], 'qty' => 4]]]);
  ok('saving with no change changes nothing', $c === 200 && $r['changed'] === false);

  // budget recheck
  $budget = (float) WCSS_Order_Policy::store_limits($SID)['budget'];
  $big = (int) ceil(($budget + 1) / 10);
  list($c, $r) = rest('PUT', "$P/items", ['lines' => [['item_id' => $L['TEST edit notebook']['item_id'], 'qty' => min(999, $big)]]]);
  ok('over budget after the edit is flagged', $c === 200 && $budget > 0 && in_array('over_budget', $r['policy']['reasons'], true), wp_json_encode([$budget, $big, $r['policy']['reasons'] ?? null]));
  list($c, $r) = rest('PUT', "$P/items", ['lines' => [['item_id' => $L['TEST edit notebook']['item_id'], 'qty' => 1]]]);
  ok('back within budget clears the flag', $c === 200 && ! in_array('over_budget', $r['policy']['reasons'], true), wp_json_encode($r['policy']['reasons'] ?? null));
  ok('edits never touch the monthly ledger', wp_json_encode(WCSS_Ledger::get($SID, $ym)) === wp_json_encode($bk_ledger));

  // refusals
  $iid = lines_of($O)['TEST edit notebook']['item_id'];
  ok('ink refused on a stationery order', rest('PUT', "$P/items", ['lines' => [['item_id' => $iid, 'qty' => 1], ['product_id' => $INK, 'qty' => 1]]])[0] === 400);
  ok('product with no vendor price refused', rest('PUT', "$P/items", ['lines' => [['item_id' => $iid, 'qty' => 1], ['product_id' => $NOP, 'qty' => 1]]])[0] === 400);
  ok('quantity over 999 refused', rest('PUT', "$P/items", ['lines' => [['item_id' => $iid, 'qty' => 1000]]])[0] === 400);
  ok('negative quantity refused', rest('PUT', "$P/items", ['lines' => [['item_id' => $iid, 'qty' => -1]]])[0] === 400);
  ok('removing every item refused', rest('PUT', "$P/items", ['lines' => [['item_id' => $iid, 'qty' => 0]]])[0] === 400);
  ok('unknown item refused', rest('PUT', "$P/items", ['lines' => [['item_id' => 999999, 'qty' => 1]]])[0] === 409);
  ok('missing lines refused', rest('PUT', "$P/items", ['x' => 1])[0] === 400);
  ok('refusals left the order unchanged', count(lines_of($O)) === 1 && lines_of($O)['TEST edit notebook']['qty'] === 1);

  // permissions
  wp_set_current_user($owner);
  ok('store user cannot edit', in_array(rest('PUT', "$P/items", ['lines' => [['item_id' => $iid, 'qty' => 5]]])[0], [401, 403], true));
  ok('store user cannot search', in_array(rest('GET', "$P/edit-products")[0], [401, 403], true));
  wp_set_current_user($admin);
  ok('nothing changed by the store user', lines_of($O)['TEST edit notebook']['qty'] === 1);

  // ink order: only ink can be added
  $I = order_with($INK, 1, 80, true);
  list($c, $d) = rest('GET', "/wcss/v1/orders/$I/edit-products", null, ['search' => 'TEST edit']);
  ok('ink order search lists only ink', $c === 200 && $d['ink'] === true && ! in_array('TEST edit binder', array_column($d['items'], 'name'), true));
  $ii = lines_of($I)['TEST edit toner']['item_id'];
  ok('stationery refused on an ink order', rest('PUT', "/wcss/v1/orders/$I/items", ['lines' => [['item_id' => $ii, 'qty' => 1], ['product_id' => $B, 'qty' => 1]]])[0] === 400);
  list($c, $r) = rest('PUT', "/wcss/v1/orders/$I/items", ['lines' => [['item_id' => $ii, 'qty' => 2]]]);
  ok('ink order stays an ink review after edit', $c === 200 && $r['policy']['reasons'] === ['ink_review']);

  // only orders in approval
  $o = wc_get_order($O); $o->set_status('approved'); $o->save();
  ok('approved order cannot be edited', rest('PUT', "$P/items", ['lines' => [['item_id' => $iid, 'qty' => 2]]])[0] === 409);
  ok('approved order cannot be searched', rest('GET', "$P/edit-products")[0] === 409);
  ok('missing order is 404', rest('PUT', '/wcss/v1/orders/99999999/items', ['lines' => []])[0] === 404);
} finally {
  foreach ($orders as $id) if (wc_get_order($id)) WCSS_Order_Delete::purge($id);
  global $wpdb;
  foreach ($products as $id) { $wpdb->delete(cc_vendor_prices_table(), ['product_id' => $id]); wp_delete_post($id, true); }
  $now = WCSS_Ledger::get($SID, $ym); WCSS_Ledger::bump($SID, $ym, (int) $bk_ledger['orders_count'] - (int) $now['orders_count'], (float) $bk_ledger['spend_total'] - (float) $now['spend_total']);
}
ok('store ledger back to where it was', wp_json_encode(WCSS_Ledger::get($SID, $ym)) === wp_json_encode($bk_ledger));
echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
