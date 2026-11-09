<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/vendor-required-test.php
 * WooCommerce is not a vendor: cheapest price, ordering and order results only know real vendors.
 * Blocks every email and outbound HTTP call, and cleans up after itself.
 * Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }

$GLOBALS['mails'] = [];
add_filter('pre_wp_mail', function ($r, $a) { $GLOBALS['mails'][] = $a['subject'] ?? ''; return false; }, 1, 2);
add_filter('pre_http_request', function () { return new WP_Error('blocked', 'No outbound HTTP in tests'); }, 1);

add_filter('option_wcss_settings', function ($o) { $o['province_weeks_on'] = 0; return $o; }); // Ordering weeks are tested in province-weeks-test.php.
$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
$owner = get_user_by('login', 'storeowner')->ID;
wp_set_current_user($admin);
$SID = 8177; $orders = []; $products = []; $pass = 0; $fail = 0;
$ym = (new WC_DateTime())->date_i18n('Y-m'); $bk_ledger = WCSS_Ledger::get($SID, $ym);

function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }
function rest($m, $p, $b = null) { $r = new WP_REST_Request($m, $p); if ($b !== null) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($b)); } $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; }
function product($price) { global $products; $p = new WC_Product_Simple(); $p->set_name('TEST vendor required'); $p->set_status('publish'); $p->set_regular_price($price); $p->save(); $products[] = $p->get_id(); return $p->get_id(); }
function vendor_price($pid, $v, $price) { cc_vendor_prices_upsert($pid, $v, ['status' => 'ok', 'price' => $price, 'currency' => 'CAD', 'vendor_sku' => 'SKU' . $v]); }
function order($pids, $status = 'awaiting-approval') { global $SID, $owner, $orders;
  $o = wc_create_order(['customer_id' => $owner]); foreach ((array) $pids as $p) $o->add_product(wc_get_product($p), 1); $o->calculate_totals();
  $o->update_meta_data('_wcss_store_id', $SID); $o->save(); $o->set_status($status); $o->save(); $orders[] = $o->get_id(); return wc_get_order($o->get_id()); }
function auto($o) { return wc_get_order($o->get_id())->get_meta(WCSS_AUTO_VENDOR_SUBMIT_META, true); }
function fresh($id) { WC_Cache_Helper::invalidate_cache_group('product_' . $id); wp_cache_delete($id, 'post_meta'); return wc_get_product($id); }

$manual = get_term_by('slug', 'valiant', 'wcss_vendor');
$manual_was_off = ! WCSS_Vendor_Status::is_active($manual->term_id); if ($manual_was_off) WCSS_Vendor_Status::set_active($manual->term_id, true); // Needs Valiant active; restored at the end.
try {
  // ---------- cheapest: real vendors only ----------
  $none = product(50);
  $r = cc_vendor_prices_resolve_cheapest($none);
  ok('No vendor: stored price is not a vendor offer', $r['vendor'] === 'none' && $r['price'] === null, json_encode($r));
  ok('No vendor: not orderable', ! cc_vendor_prices_product_is_orderable($none));
  ok('No vendor: not purchasable', ! fresh($none)->is_purchasable());
  update_post_meta($none, '_cc_cheapest_vendor', 'woo'); update_post_meta($none, '_cc_cheapest_price', '50');
  $r = cc_vendor_prices_resolve_cheapest($none);
  ok('Old "woo" cheapest meta no longer counts', $r['vendor'] === 'none', json_encode($r));
  ok('Old "woo" meta is repaired once', get_post_meta($none, '_cc_cheapest_vendor', true) === 'none');

  $live = product(5);
  vendor_price($live, 'amazon', 10); vendor_price($live, 'gt', 12);
  $r = cc_vendor_prices_resolve_cheapest($live);
  ok('Lower stored price cannot beat real vendors', $r['vendor'] === 'amazon' && (float) $r['price'] === 10.0, json_encode($r));
  ok('Live vendor: purchasable', fresh($live)->is_purchasable());
  vendor_price($live, 'gt', 10);
  ok('Tie goes to Amazon', cc_vendor_prices_resolve_cheapest($live)['vendor'] === 'amazon');

  wp_set_object_terms($live, [$manual->term_id], 'wcss_vendor');
  $r = cc_vendor_prices_resolve_cheapest($live);
  ok('Manual vendor competes with its stored price', $r['vendor'] === 'manual' && (float) $r['price'] === 5.0, json_encode($r));
  wp_set_object_terms($live, [], 'wcss_vendor');
  ok('Unticking the manual vendor recomputes', cc_vendor_prices_resolve_cheapest($live)['vendor'] === 'amazon');

  // ---------- add to cart ----------
  wp_set_current_user($owner);
  if (function_exists('wc_load_cart')) wc_load_cart();
  WC()->cart->empty_cart();
  $added = false; try { $added = WC()->cart->add_to_cart($none, 1); } catch (Exception $e) {}
  ok('Add to cart refused with no vendor', ! $added && WC()->cart->is_empty());
  wc_clear_notices();
  $added = WC()->cart->add_to_cart($live, 1); WC()->cart->calculate_totals();
  ok('Add to cart works with a vendor, at the vendor price', $added && (float) WC()->cart->get_subtotal() === 10.0, (string) WC()->cart->get_subtotal());
  WC()->cart->empty_cart();
  wp_set_current_user($admin);

  // ---------- no vendor: never quietly approved ----------
  $GLOBALS['mails'] = [];
  $o = order($none); $s = cc_auto_vendor_submit_run($o->get_id());
  ok('No vendor: result is failed, not Woo-only', ($s['status'] ?? '') === 'failed' && strpos($s['message'], 'No vendor assigned') === 0, json_encode($s));
  ok('No vendor: order is not approved', wc_get_order($o->get_id())->get_status() === 'awaiting-approval', wc_get_order($o->get_id())->get_status());
  ok('No vendor: Supply Ops alerted', count($GLOBALS['mails']) > 0, json_encode($GLOBALS['mails']));
  [$st, $d] = rest('POST', "/wcss/v1/orders/{$o->get_id()}/status", ['status' => 'approved', 'override' => 1]);
  [$st, $d] = rest('GET', "/wcss/v1/orders/{$o->get_id()}");
  ok('Approved anyway: still shown as not sent, with retry', ($d['vendor_receipt']['mode'] ?? '') === 'failed' && ! empty($d['vendor_receipt']['can_retry']), json_encode($d['vendor_receipt'] ?? null));

  // map the product afterwards: the order now finds its vendor
  $late = product(9); $o2 = order($late);
  ok('Before mapping: line is unassigned', count(cc_auto_vendor_submit_collect_lines($o2)['unassigned']) === 1);
  vendor_price($late, 'amazon', 11);
  $c = cc_auto_vendor_submit_collect_lines(wc_get_order($o2->get_id()));
  ok('After mapping: line goes to the vendor', $c['vendors'] === ['amazon'] && ! $c['unassigned'], json_encode($c['vendors']));
  ok('After mapping: vendor saved on the line', array_values(wc_get_order($o2->get_id())->get_items())[0]->get_meta('_cc_vendor') === 'amazon');

  // ---------- manual vendor ----------
  $man = product(7); wp_set_object_terms($man, [$manual->term_id], 'wcss_vendor');
  $o3 = order($man); $s = cc_auto_vendor_submit_run($o3->get_id());
  $s = auto($o3);
  ok('Manual vendor: approved with a place-by-hand result', ($s['status'] ?? '') === 'manual' && wc_get_order($o3->get_id())->get_status() === 'approved', json_encode($s));
  ok('Manual vendor: names the vendor and item', strpos($s['message'], 'Place with ' . $manual->name) === 0 && strpos($s['message'], 'TEST vendor required') !== false, $s['message']);
  [$st, $d] = rest('GET', "/wcss/v1/orders/{$o3->get_id()}");
  ok('Manual vendor: receipt mode', ($d['vendor_receipt']['mode'] ?? '') === 'manual', json_encode($d['vendor_receipt'] ?? null));
  global $wpdb;
  $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wcss_order_events WHERE order_id = %d AND type = 'auto_vendor_submit'", $o3->get_id()));
  ok('Manual vendor: logged once', $n === 1, "$n");

  // ---------- mixed + legacy ----------
  $c = cc_auto_vendor_submit_collect_lines(order([$live, $man, $none]));
  ok('Mixed order: each line in its own bucket', $c['vendors'] === ['amazon'] && count($c['manual']) === 1 && count($c['unassigned']) === 1, json_encode($c['vendors']));
  $o4 = order($none, 'approved');
  $o4 = wc_get_order($o4->get_id()); $o4->update_meta_data(WCSS_AUTO_VENDOR_SUBMIT_META, ['attempted' => true, 'status' => 'woo_only', 'message' => 'kept as a WooCommerce order']); $o4->save();
  [$st, $d] = rest('GET', "/wcss/v1/orders/{$o4->get_id()}");
  ok('Old Woo-only order shows as never sent, can retry', ($d['vendor_receipt']['mode'] ?? '') === 'failed' && ! empty($d['vendor_receipt']['can_retry']) && strpos($d['vendor_receipt']['message'], 'never sent') !== false, json_encode($d['vendor_receipt'] ?? null));
  $s = cc_auto_vendor_submit_retry($o4->get_id());
  ok('Retrying it stays failed until mapped', ($s['status'] ?? '') === 'failed', json_encode($s));

  // ---------- REST ----------
  [$st, $d] = rest('GET', "/wcss/v1/products/$none");
  ok('API: orderable flag', $st === 200 && $d['orderable'] === false, json_encode($d['orderable'] ?? $d));
  ok('API: no Woo price fields', ! array_key_exists('woo_price', $d) && ! array_key_exists('woo_price_html', $d));
  [$st, $d] = rest('POST', '/wcss/v1/products', ['name' => 'TEST vendor required', 'status' => 'draft', 'vendor_ids' => [], 'amazon_asin' => 'B0TEST1234']);
  if (! empty($d['id'])) $products[] = (int) $d['id'];
  ok('API: create needs no stored price', $st === 200, json_encode($d));
} finally {
  wp_set_current_user($admin);
  foreach ($orders as $id) { if (wc_get_order($id)) WCSS_Order_Delete::purge($id); }
  global $wpdb;
  foreach ($products as $id) { wp_delete_post($id, true); $wpdb->delete(cc_vendor_prices_table(), ['product_id' => $id]); }
  $wpdb->update($wpdb->prefix . 'wcss_store_monthly_ledger', ['orders_count' => $bk_ledger['orders_count'], 'spend_total' => $bk_ledger['spend_total']], ['store_id' => $SID, 'ym' => $ym]);
  echo "cleanup: deleted ", count($orders), " test orders, ", count($products), " test products\n";
if ($manual_was_off) WCSS_Vendor_Status::set_active($manual->term_id, false);
  echo "RESULT: $pass passed, $fail failed\n";
}
