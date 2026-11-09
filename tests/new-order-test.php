<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/new-order-test.php
 * New order from the Supply Manager portal, for any store with any active vendor.
 * Blocks every email and outbound HTTP call, and cleans up after itself.
 * Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }

$GLOBALS['mails'] = [];
add_filter('pre_wp_mail', function ($r, $a) { $GLOBALS['mails'][] = [ implode(',', (array) ($a['to'] ?? [])), $a['subject'] ?? '' ]; return false; }, 1, 2);
add_filter('pre_http_request', function () { return new WP_Error('blocked', 'No outbound HTTP in tests'); }, 1);

$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
$owner = get_user_by('login', 'storeowner')->ID;
wp_set_current_user($admin);
$SID = 8177; $pass = 0; $fail = 0; $products = []; $orders = []; $terms = [];
$ym = (new WC_DateTime())->date_i18n('Y-m'); $bk_ledger = WCSS_Ledger::get($SID, $ym);
$bk_gt = cc_gt_get_cart();

function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }
function rest($m, $p, $b = null, $q = []) { $r = new WP_REST_Request($m, $p); foreach ($q as $k => $v) $r->set_param($k, $v); if ($b !== null) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($b)); } $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; }
function product($price, $vendor_ids, $el = 'none', $name = 'TEST new order') { global $products; $p = new WC_Product_Simple(); $p->set_name($name); $p->set_status('publish'); $p->set_regular_price($price); $p->save(); $id = $p->get_id(); $products[] = $id;
  update_post_meta($id, 'wcss_procurement_eligibility', $el); wp_set_object_terms($id, $vendor_ids, 'wcss_vendor', false); cc_vendor_prices_recompute_cheapest($id); return $id; }
function live($pid, $v, $price) { cc_vendor_prices_upsert($pid, $v, ['status' => 'ok', 'price' => $price, 'currency' => 'CAD', 'vendor_sku' => 'SKU' . $v]); }
function place($store, $vendor, $items) { global $orders; list($c, $d) = rest('POST', '/wcss/v1/new-order/checkout', ['store_id' => $store, 'vendor_id' => $vendor, 'items' => $items]); if ($c === 200) $orders[] = (int) $d['id']; return [$c, $d]; }

$t = wp_insert_term('TEST Manual ' . wp_generate_password(6, false), 'wcss_vendor'); $M = (int) $t['term_id']; $terms[] = $M;
$t = wp_insert_term('TEST Off ' . wp_generate_password(6, false), 'wcss_vendor'); $OFF = (int) $t['term_id']; $terms[] = $OFF;
WCSS_Vendor_Status::set_active($OFF, false);
$GT = (int) get_term_by('slug', 'grand-toy', 'wcss_vendor')->term_id;
$AZ = (int) get_term_by('slug', 'amazon', 'wcss_vendor')->term_id;

$manual = product(12.5, [$M], 'none', 'TEST new order manual');
$other  = product(9, [$OFF], 'none', 'TEST new order other');
$gt     = product(0, [], 'gt', 'TEST new order gt'); update_post_meta($gt, 'wcss_gt_part_id', 'GTP1'); live($gt, 'gt', 21);
$az     = product(0, [], 'amazon', 'TEST new order amazon'); update_post_meta($az, 'amazon_asin', 'B0TEST1234'); live($az, 'amazon', 30);
$nop    = product(0, [$M], 'none', 'TEST new order noprice');

try {
  list($c, $m) = rest('GET', '/wcss/v1/new-order/meta');
  $ids = array_column($m['vendors'], 'id');
  ok('meta lists active vendors', $c === 200 && in_array($M, $ids, true) && in_array($AZ, $ids, true) && in_array($GT, $ids, true));
  ok('inactive vendor left out', ! in_array($OFF, $ids, true));
  ok('all stores listed', count($m['stores']) > 100);

  list(, $d) = rest('GET', '/wcss/v1/new-order/products', null, ['vendor' => $M, 'search' => 'TEST new order']);
  $names = array_column($d['items'], 'name');
  ok('catalog shows only that vendor', in_array('TEST new order manual', $names, true) && ! in_array('TEST new order gt', $names, true));
  ok('search filters', count(rest('GET', '/wcss/v1/new-order/products', null, ['vendor' => $M, 'search' => 'noprice'])[1]['items']) === 1);
  $row = array_values(array_filter($d['items'], function ($x) { return $x['name'] === 'TEST new order noprice'; }))[0];
  ok('no price means not orderable', $row['available'] === false);
  list(, $d) = rest('GET', '/wcss/v1/new-order/products', null, ['vendor' => $GT, 'search' => 'TEST new order gt']);
  ok('live vendor uses its own price', (float) $d['items'][0]['price'] === 21.0 && $d['items'][0]['available'] === true);
  ok('inactive vendor catalog refused', rest('GET', '/wcss/v1/new-order/products', null, ['vendor' => $OFF])[0] === 400);

  wp_set_current_user($owner);
  ok('store owner blocked: meta', in_array(rest('GET', '/wcss/v1/new-order/meta')[0], [401, 403], true));
  ok('store owner blocked: checkout', in_array(rest('POST', '/wcss/v1/new-order/checkout', ['store_id' => $SID, 'vendor_id' => $M, 'items' => [['product_id' => $manual, 'qty' => 1]]])[0], [401, 403], true));
  wp_set_current_user($admin);

  ok('no items refused', place($SID, $M, [])[0] === 400);
  ok('no store refused', place(0, $M, [['product_id' => $manual, 'qty' => 1]])[0] === 400);
  ok('wrong vendor product refused', place($SID, $M, [['product_id' => $gt, 'qty' => 1]])[0] === 400);
  ok('product with no price refused', place($SID, $M, [['product_id' => $nop, 'qty' => 1]])[0] === 400);
  $bad = 0; foreach (get_posts(['post_type' => WCSS_Store_CPT::POST_TYPE, 'numberposts' => -1, 'fields' => 'ids']) as $s) if (! WCSS_Checkout_Store_Lock::store_address_complete($s)) { $bad = $s; break; }
  if ($bad) ok('incomplete store address refused', place($bad, $M, [['product_id' => $manual, 'qty' => 1]])[0] === 400);

  // Manual vendor
  $before = count($orders);
  list($c, $d) = place($SID, $M, [['product_id' => $manual, 'qty' => 3]]);
  $o = wc_get_order($d['id']);
  ok('manual order created', $c === 200 && count($orders) === $before + 1, wp_json_encode($d));
  ok('approved at once', $o->get_status() === 'approved');
  ok('goes to order page', strpos($d['redirect'], '/manager/orders/view/' . $o->get_id()) !== false);
  ok('total uses vendor price', (float) $o->get_total() === 37.5, (string) $o->get_total());
  ok('order tied to store', (int) $o->get_meta('_wcss_store_id') === $SID);
  ok('ship-to is the store address', $o->get_shipping_address_1() === get_post_meta($SID, WCSS_Store_CPT::META_ADDR, true) && $o->get_shipping_city() !== '');
  ok('marked placed by Supply Ops', (bool) $o->get_meta('_wcss_manager_order') && (bool) $o->get_meta('_wcss_policy_override'));
  $a = $o->get_meta(WCSS_AUTO_VENDOR_SUBMIT_META, true);
  ok('receipt says place with the vendor', $a['status'] === 'manual' && strpos($a['manual_note'], 'Place with') === 0);
  list(, $dto) = rest('GET', '/wcss/v1/orders/' . $o->get_id());
  ok('order detail reads it', $dto['vendor_receipt']['mode'] === 'manual', wp_json_encode($dto['vendor_receipt'] ?? null));

  // Budget, order limit and window
  $sid_user = (int) get_users(['meta_key' => '_wcss_store_id', 'meta_value' => (string) $SID, 'role' => 'store_employee', 'number' => 1, 'fields' => 'ID'])[0];
  ok('order belongs to the store user', $o->get_customer_id() === $sid_user, (string) $o->get_customer_id());
  ok('store sees it in My Account', in_array($o->get_id(), wc_get_orders(['customer_id' => $sid_user, 'limit' => -1, 'return' => 'ids']), true));
  ok('policy result kept on the order', is_array($o->get_meta(WCSS_Order_Policy::META)) && isset($o->get_meta(WCSS_Order_Policy::META)['in_policy']));
  ok('store is emailed', (bool) array_filter($GLOBALS['mails'], function ($m) use ($o) { return stripos($m[1], (string) $o->get_order_number()) !== false; }), wp_json_encode(array_slice($GLOBALS['mails'], -3)));
  $before = WCSS_Order_Policy::month_usage($SID);
  $pol = rest('GET', '/wcss/v1/new-order/policy', null, ['store' => $SID, 'amount' => 10, 'ids' => $manual])[1];
  ok('limits endpoint reports usage', (float) $pol['used_amount'] === round((float) $before[1], 2) && (int) $pol['used_orders'] === (int) $before[0] && (float) $pol['order_amount'] === 10.0, wp_json_encode([$pol, $before]));
  list($c2, $d2) = place($SID, $M, [['product_id' => $manual, 'qty' => 2]]);
  $after = WCSS_Order_Policy::month_usage($SID);
  ok('order counts toward the month', (int) $after[0] === (int) $before[0] + 1 && abs((float) $after[1] - (float) $before[1] - 25.0) < 0.01, wp_json_encode([$before, $after]));
  $over = (float) $pol['budget'] + 1;
  $polo = rest('GET', '/wcss/v1/new-order/policy', null, ['store' => $SID, 'amount' => $over, 'ids' => $manual])[1];
  ok('over budget is flagged', $polo['in_policy'] === false && in_array('over_budget', $polo['reasons'], true), wp_json_encode($polo));
  list($c3, $d3) = place($SID, $M, [['product_id' => $manual, 'qty' => (int) ceil($over / 12.5)]]);
  $o3 = wc_get_order($d3['id']);
  ok('Supply Ops can still place it', $c3 === 200 && $o3->get_status() === 'approved');
  ok('over-budget order recorded as an exception', in_array('over_budget', (array) ($o3->get_meta(WCSS_Order_Policy::META)['reasons'] ?? []), true) && (bool) $o3->get_meta('_wcss_policy_override'));
  $inkp = product(6, [$M], 'none', 'TEST new order ink'); $cats = WCSS_Ink_Order::category_ids(); wp_set_object_terms($inkp, [(int) $cats[0]], 'product_cat', false);
  $u0 = WCSS_Order_Policy::month_usage($SID);
  $poli = rest('GET', '/wcss/v1/new-order/policy', null, ['store' => $SID, 'amount' => 6, 'ids' => $inkp])[1];
  ok('ink shown outside the budget', $poli['ink'] === true && (float) $poli['order_amount'] === 0.0, wp_json_encode($poli));
  list($c4, $d4) = place($SID, $M, [['product_id' => $inkp, 'qty' => 1]]);
  $u1 = WCSS_Order_Policy::month_usage($SID);
  ok('ink order stamped as ink', WCSS_Ink_Order::order_is_ink(wc_get_order($d4['id'])));
  ok('ink order does not use budget or order count', (int) $u0[0] === (int) $u1[0] && abs((float) $u0[1] - (float) $u1[1]) < 0.01, wp_json_encode([$u0, $u1]));
  WCSS_Order_Delete::purge((int) $d3['id']); $orders = array_diff($orders, [(int) $d3['id']]);
  $u2 = WCSS_Order_Policy::month_usage($SID);
  ok('deleting an order gives the budget back', (int) $u2[0] === (int) $u1[0] - 1, wp_json_encode([$u1, $u2]));

  // Grand & Toy
  list($c, $d) = place($SID, $GT, [['product_id' => $gt, 'qty' => 2]]);
  $o = wc_get_order($d['id']);
  ok('G&T goes to its checkout', $c === 200 && strpos($d['redirect'], '/gt-checkout/?order-id=' . $o->get_id()) !== false, wp_json_encode($d));
  $cart = cc_gt_get_cart();
  ok('G&T cart filled', count($cart) === 1 && $cart[0]['part_id'] === 'GTP1' && (int) $cart[0]['qty'] === 2 && (float) $cart[0]['unit_price'] === 21.0);
  $a = $o->get_meta(WCSS_AUTO_VENDOR_SUBMIT_META, true);
  ok('not auto-sent to G&T', $a['status'] === 'checkout' && ! $o->get_meta('_wcss_gt_order_data'), wp_json_encode($a));
  $line = current($o->get_items());
  ok('line stamped with vendor', $line->get_meta('_cc_vendor') === 'gt' && $line->get_meta('_cc_vendor_id') === 'GTP1');
  list(, $dto) = rest('GET', '/wcss/v1/orders/' . $o->get_id());
  ok('waiting state, not failed', $dto['vendor_receipt']['mode'] === 'pending', wp_json_encode($dto['vendor_receipt'] ?? null));

  // Amazon: HTTP is blocked, so the cart step fails and nothing must be left behind
  $n = count(wc_get_orders(['limit' => -1, 'return' => 'ids', 'meta_key' => '_wcss_store_id', 'meta_value' => (string) $SID]));
  list($c, $d) = place($SID, $AZ, [['product_id' => $az, 'qty' => 1]]);
  ok('Amazon failure reported', $c === 502, wp_json_encode($d));
  ok('Amazon failure leaves no order', count(wc_get_orders(['limit' => -1, 'return' => 'ids', 'meta_key' => '_wcss_store_id', 'meta_value' => (string) $SID])) === $n);

  // Amazon checkout helpers, on a hand-made order
  cc_auto_vendor_submit_bootstrap_amazon();
  $o = wc_create_order(); $orders[] = $o->get_id();
  $id = $o->add_product(wc_get_product($az), 2); $it = $o->get_item($id); $it->update_meta_data('_cc_vendor_id', 'B0TEST1234'); $it->save();
  $o->update_meta_data('_wcss_store_id', $SID); $o->save();
  $_GET['order-id'] = $o->get_id();
  $cart = [['productIdentifier' => 'B0TEST1234', 'quantity' => 9], ['productIdentifier' => 'B0DEMO0000', 'quantity' => 1]];
  ok('normal order: cart untouched', count(wcss_amazon_checkout_items_for_order($cart)) === 2);
  $o->update_meta_data('_wcss_manager_order', ['by' => 1]); $o->save();
  ok('Amazon account email used on checkout', wcss_amazon_checkout_prefill_from_order($o->get_id())['email'] === AMAZON_USER_EMAIL);
  $kept = wcss_amazon_checkout_items_for_order($cart);
  ok('other cart items are not ordered', count($kept) === 1 && $kept[0]['productIdentifier'] === 'B0TEST1234');
  ok('order quantity is used', $kept[0]['quantity'] === 2);
  $o->set_total(5000); foreach ($o->get_items() as $li) { $li->set_total(5000); $li->save(); } $o->save();
  ok('Supply Ops order skips the monthly cap', wcss_amazon_checkout_cap_block($o->get_id()) === null);
  unset($_GET['order-id']);
  $tpl = file_get_contents(get_stylesheet_directory() . '/amazon-checkout.php');
  ok('Calculate Shipping can never place the order', (bool) preg_match('/!\s*isset\(\s*\$_POST\[\'amazon_shipping_calculation\'\]\s*\)\s*&& isset\(\s*\$_POST\[\'amazon_checkout_action\'\]/', $tpl));
} finally {
  foreach ($orders as $id) if (wc_get_order($id)) WCSS_Order_Delete::purge($id);
  global $wpdb;
  foreach ($products as $id) { $wpdb->delete(cc_vendor_prices_table(), ['product_id' => $id]); wp_delete_post($id, true); }
  foreach ($terms as $id) wp_delete_term($id, 'wcss_vendor');
  WCSS_Vendor_Status::refresh_all();
  cc_gt_save_cart($bk_gt);
}
$after = WCSS_Ledger::get($SID, $ym);
ok('store ledger back to where it was', wp_json_encode($after) === wp_json_encode($bk_ledger), wp_json_encode([$bk_ledger, $after]));
echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
