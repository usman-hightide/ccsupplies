<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/amazon-reliability-test.php
 * Creates throwaway orders on "Test store" (#8177), blocks every email, and cleans up after itself.
 * Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }
$ledger_ym = (new WC_DateTime())->date_i18n('Y-m'); $ledger_bk = WCSS_Ledger::get(8177, $ledger_ym); // Throwaway orders must not change Test store's month totals.
wp_set_current_user((int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0]);
$GLOBALS['mails'] = 0; add_filter('pre_wp_mail', function () { $GLOBALS['mails']++; return false; }, 1);

// ---------- HTTP mock: every outbound request is answered here; nothing leaves the machine ----------
$M = ['calls' => [], 'unexpected' => [], 'order_mode' => ['ok'], 'order_bodies' => []];
function resp($code, $body) { return ['headers' => ['x-amzn-requestid' => 'req-' . wp_generate_uuid4()], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => (string) $code], 'cookies' => [], 'filename' => null]; }
add_filter('pre_http_request', function ($pre, $args, $url) {
  global $M; $method = strtoupper($args['method'] ?? 'GET'); $M['calls'][] = "$method $url";
  $host = (string) wp_parse_url($url, PHP_URL_HOST);
  if ($host === 'api.amazon.com') return resp(200, ['access_token' => 'TEST-TOKEN', 'expires_in' => 3600, 'token_type' => 'bearer']);
  if ($host !== 'na.business-api.amazon.com') { $M['unexpected'][] = "$method $url"; return new WP_Error('blocked', 'blocked by test'); }
  if (strpos($url, '/ordering/') !== false && $method === 'POST') {
    $M['order_bodies'][] = json_decode($args['body'], true);
    $mode = array_shift($M['order_mode']) ?: 'ok';
    if ($mode === '503') return resp(503, ['errors' => [['message' => 'Service Unavailable']]]);
    if ($mode === 'net') return new WP_Error('http_request_failed', 'cURL error 28: timed out');
    return resp(201, ['orderIdentifier' => 'AMZ-TEST-1', 'acceptanceArtifacts' => []]);
  }
  if (strpos($url, '/cart/') !== false && $method === 'POST') return resp(200, ['items' => []]);
  if (strpos($url, '/cart/') !== false && $method === 'GET') return resp(200, ['items' => [
    ['productIdentifier' => 'LEFTOVER999', 'buyingOptionIdentifier' => 'OFF-L', 'quantity' => 4, 'price' => ['amount' => 9.5, 'currencyCode' => 'CAD']],
    ['productIdentifier' => 'B07FDHJ73F', 'buyingOptionIdentifier' => 'OFF-B07FDHJ73F', 'quantity' => 9, 'price' => ['amount' => 204.15, 'currencyCode' => 'CAD']],
  ]]);
  // product lookup by ASIN
  return resp(200, ['products' => [['asin' => 'B07FDHJ73F', 'title' => 'Lexmark Cyan', 'includedDataTypes' => ['OFFERS' => [['offerId' => 'OFF-B07FDHJ73F', 'price' => ['value' => 204.15]]]]]]]);
}, 1, 3);
// cached token transients would skip the token call; harmless either way.

$pass = 0; $fail = 0; $created = [];
function ok($n, $c, $i = '') { global $pass, $fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n  $i\n"; } }
function mk_amz_order() { global $created;
  $o = wc_create_order(['customer_id' => 243]); $item_id = $o->add_product(wc_get_product(7654), 2);
  $it = $o->get_item($item_id); $it->add_meta_data('_cc_vendor', 'amazon', true); $it->add_meta_data('_cc_vendor_id', 'B07FDHJ73F', true); $it->add_meta_data('_cc_vendor_price', 204.15, true); $it->save();
  $o->set_address(['first_name' => 'Test', 'last_name' => 'Store', 'company' => 'Canna Cabana', 'address_1' => '1 Main St', 'city' => 'Calgary', 'state' => 'AB', 'postcode' => 'T2P 1J9', 'country' => 'CA', 'phone' => '4035550100', 'email' => 'x@example.com'], 'shipping');
  $o->set_address(['first_name' => 'Test', 'last_name' => 'Store', 'company' => 'Canna Cabana', 'address_1' => '1 Main St', 'city' => 'Calgary', 'state' => 'AB', 'postcode' => 'T2P 1J9', 'country' => 'CA', 'phone' => '4035550100', 'email' => 'x@example.com'], 'billing');
  $o->update_meta_data('_wcss_store_id', 8177); $o->update_meta_data('_wcss_policy_override', ['by' => 1, 'at' => 'test']);
  $o->calculate_totals(); $o->save(); $created[] = $o->get_id(); return wc_get_order($o->get_id()); }

try {
  ok('Amazon helpers load', cc_auto_vendor_submit_bootstrap_amazon());
  delete_option('cc_amazon_place_lock');

  // 1) intended lines only + 503 retry with the same externalId
  $o = mk_amz_order();
  $lines = cc_auto_vendor_submit_collect_lines($o)['amazon'];
  $fill = cc_auto_vendor_submit_amazon_fill_cart($lines);
  ok('Cart fill adds the 1 Amazon line', count($fill['added']) === 1, json_encode($fill['message']));
  $GLOBALS['M']['order_mode'] = ['503', 'ok']; $GLOBALS['M']['order_bodies'] = [];
  $place = cc_auto_vendor_submit_amazon_place($o, cc_auto_vendor_submit_ship_from_order($o), $fill['added']);
  $body = $GLOBALS['M']['order_bodies'][0] ?? [];
  $asins = array_map(fn($li) => $li['attributes'][0]['productReference']['id'] ?? ($li['lineItemAttributes'][0]['productReference']['id'] ?? '?'), $body['lineItems'] ?? []);
  $qtys = array_map(fn($li) => $li['quantity'] ?? null, $body['lineItems'] ?? []);
  ok('Places only this order\'s item (leftover cart item ignored)', count($body['lineItems'] ?? []) === 1, json_encode($body['lineItems'] ?? null));
  ok('Places this order\'s qty (2), not the cart\'s stale qty (9)', $qtys === [2], json_encode($qtys));
  ok('503 retried, then succeeds', ! empty($place['ok']) && count($GLOBALS['M']['order_bodies']) === 2, json_encode($place['snapshot']['attempts'] ?? null));
  ok('Retry reuses the same externalId', ($GLOBALS['M']['order_bodies'][0]['externalId'] ?? 'a') === ($GLOBALS['M']['order_bodies'][1]['externalId'] ?? 'b') && strpos($GLOBALS['M']['order_bodies'][0]['externalId'], 'CC-' . $o->get_id() . '-') === 0, $GLOBALS['M']['order_bodies'][0]['externalId'] ?? '');
  ok('Snapshot records attempts + ignored cart items', count($place['snapshot']['attempts'] ?? []) === 2 && ($place['snapshot']['ignored_cart_items'] ?? -1) === 1, json_encode([$place['snapshot']['attempts'] ?? null, $place['snapshot']['ignored_cart_items'] ?? null]));

  // 2) transport error is NOT retried
  $GLOBALS['M']['order_mode'] = ['net', 'ok']; $GLOBALS['M']['order_bodies'] = [];
  $place2 = cc_auto_vendor_submit_amazon_place($o, cc_auto_vendor_submit_ship_from_order($o), $fill['added']);
  ok('Network timeout not retried (order may exist on Amazon)', empty($place2['ok']) && count($GLOBALS['M']['order_bodies']) === 1, count($GLOBALS['M']['order_bodies']) . ' ' . ($place2['message'] ?? ''));

  // 3) lock busy -> order deferred, nothing sent
  $o2 = mk_amz_order();
  add_option('cc_amazon_place_lock', time(), '', 'no');
  $before = count($GLOBALS['M']['calls']);
  $r = cc_auto_vendor_submit_run($o2->get_id());
  $meta = wc_get_order($o2->get_id())->get_meta('_wcss_auto_vendor_submit');
  ok('Busy lock: order deferred, no Amazon calls made', $r === null && count($GLOBALS['M']['calls']) === $before, json_encode($meta));
  ok('Busy lock: status says waiting + retry scheduled', strpos((string) ($meta['message'] ?? ''), 'Waiting for another Amazon order') === 0 && (! function_exists('as_has_scheduled_action') || as_has_scheduled_action('cc_auto_vendor_submit_async', ['order_id' => $o2->get_id()], 'cc-auto-vendor')));
  ok('Busy lock: order not approved while waiting', wc_get_order($o2->get_id())->get_status() !== 'approved', wc_get_order($o2->get_id())->get_status());
  delete_option('cc_amazon_place_lock');

  // 4) full run with free lock -> submitted, lock released
  $GLOBALS['M']['order_mode'] = ['ok'];
  $r2 = cc_auto_vendor_submit_run($o2->get_id());
  ok('Full run: submitted to Amazon', ($r2['status'] ?? '') === 'submitted', json_encode([$r2['status'] ?? null, $r2['message'] ?? null]));
  ok('Full run: lock released afterwards', get_option('cc_amazon_place_lock', 'none') === 'none');
  ok('Full run: order approved', wc_get_order($o2->get_id())->get_status() === 'approved');

  ok('No request left the mock (no real Amazon/other calls)', empty($GLOBALS['M']['unexpected']), json_encode($GLOBALS['M']['unexpected']));
} finally {
  $ledger_now = WCSS_Ledger::get(8177, $ledger_ym); WCSS_Ledger::bump(8177, $ledger_ym, (int) $ledger_bk['orders_count'] - (int) $ledger_now['orders_count'], (float) $ledger_bk['spend_total'] - (float) $ledger_now['spend_total']);
  if ($created) { global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}wcss_email_logs WHERE subject REGEXP '#(" . implode('|', array_map('intval', $created)) . ")([^0-9]|$)'"); } // blocked test emails, never sent
  foreach ($created as $id) { if (function_exists('as_unschedule_all_actions')) as_unschedule_all_actions('cc_auto_vendor_submit_async', ['order_id' => $id], 'cc-auto-vendor'); $x = wc_get_order($id); if ($x) $x->delete(true); }
  delete_option('cc_amazon_place_lock');
  echo "cleanup: deleted ", count($created), " test orders, lock cleared; emails blocked: {$GLOBALS['mails']}; HTTP calls intercepted: ", count($GLOBALS['M']['calls']), "\n";
  echo "RESULT: $pass passed, $fail failed\n";
}
