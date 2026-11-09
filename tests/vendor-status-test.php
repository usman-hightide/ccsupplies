<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/vendor-status-test.php
 * Deactivating a vendor removes it and hides products with no other active vendor; activating restores all.
 * Blocks every email and outbound HTTP call, and cleans up after itself.
 * Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }

add_filter('pre_wp_mail', function () { return false; }, 1);
add_filter('pre_http_request', function () { return new WP_Error('blocked', 'No outbound HTTP in tests'); }, 1);

$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
wp_set_current_user($admin);
$pass = 0; $fail = 0; $products = []; $orders = []; $terms = [];

function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }
function rest($m, $p, $b = null, $q = []) { $r = new WP_REST_Request($m, $p); foreach ($q as $k => $v) $r->set_param($k, $v); if ($b !== null) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($b)); } $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; }
function vendor($n) { global $terms; $t = wp_insert_term($n, 'wcss_vendor'); $terms[] = (int) $t['term_id']; return (int) $t['term_id']; }
function product($price, $vendor_ids, $el = 'none') { global $products; $p = new WC_Product_Simple(); $p->set_name('TEST vendor status'); $p->set_status('publish'); $p->set_regular_price($price); $p->save(); $id = $p->get_id(); $products[] = $id;
  update_post_meta($id, 'wcss_procurement_eligibility', $el); wp_set_object_terms($id, $vendor_ids, 'wcss_vendor', false); cc_vendor_prices_recompute_cheapest($id); return $id; }
function live($pid, $v, $price) { cc_vendor_prices_upsert($pid, $v, ['status' => 'ok', 'price' => $price, 'currency' => 'CAD', 'vendor_sku' => 'SKU' . $v]); }
function shop() { global $products; return (new WP_Query(['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'post__in' => $products]))->posts; }
function seen($id, $status = 200) { list(, $d) = rest("GET", "/wcss/v1/products", null, ["per_page" => 100, "search" => "TEST vendor"]); $items = $d["items"] ?? $d; if (getenv("DBG")) echo wp_json_encode(array_map(function ($x) { return $x["id"]; }, (array) $items)), " want $id\n"; return in_array($id, array_map(function ($x) { return (int) $x['id']; }, (array) $items), true); }
function toggle($id, $on) { return rest('POST', "/wcss/v1/vendors/$id/status", ['active' => $on]); }
function vendor_row($id) { foreach (rest('GET', '/wcss/v1/vendors')[1]['items'] as $v) if ((int) $v['id'] === $id) return $v; return null; }
function meta_has($id) { foreach (rest('GET', '/wcss/v1/products/meta')[1]['vendors'] as $v) if ((int) $v['id'] === $id) return true; return false; }

$gt = get_term_by('slug', 'grand-toy', 'wcss_vendor');
$gt_id = (int) $gt->term_id;
$was_gt_active = WCSS_Vendor_Status::is_active($gt_id);

$A = vendor('TEST Vendor A ' . wp_generate_password(6, false));
$B = vendor('TEST Vendor B ' . wp_generate_password(6, false));
$only_a = product(10, [$A]);
$a_and_b = product(20, [$A, $B]);
$amz_gt = product(0, [], 'both'); live($amz_gt, 'amazon', 30); live($amz_gt, 'gt', 25);
$only_gt = product(0, [], 'gt'); live($only_gt, 'gt', 40);
$none = product(5, []);

try {
  ok('new vendors start active', vendor_row($A)['active'] === true);
  ok('all test products visible at first', count(shop()) === 5, wp_json_encode(shop()));

  // Manual vendor
  list($code, $d) = toggle($A, false);
  ok('deactivate returns 200 + inactive', $code === 200 && $d['active'] === false, wp_json_encode($d));
  ok('vendor stays listed, marked inactive', vendor_row($A)['active'] === false);
  ok('inactive vendor not in product form', ! meta_has($A) && meta_has($B));
  ok('product with only that vendor hidden from shop', ! in_array($only_a, shop(), true));
  ok('hidden from Supply Manager product list', ! seen($only_a));
  ok('hidden from wc_get_products', ! in_array($only_a, wc_get_products(['include' => [$only_a], 'return' => 'ids']), true));
  ok('hidden from related products', ! in_array($only_a, apply_filters('woocommerce_related_products', [$only_a, $a_and_b]), true));
  ok('hidden product not visible', ! wc_get_product($a_and_b) || apply_filters('woocommerce_product_is_visible', true, $only_a) === false);
  ok('hidden product cannot be bought', ! cc_vendor_prices_product_is_orderable($only_a) && ! wc_get_product($only_a)->is_purchasable());
  wp_update_post(['ID' => $only_a, 'post_title' => 'TEST exact hidden']);
  ok('exact-title search finds nothing', ! cc_product_search_resolve_ids('TEST exact hidden') && ! (new WP_Query(['post_type' => 'product', 'fields' => 'ids', 's' => 'TEST exact hidden']))->posts);
  ok('product with another active vendor stays', in_array($a_and_b, shop(), true) && seen($a_and_b));
  ok('still sold through the active vendor', cc_vendor_prices_resolve_cheapest($a_and_b)['vendor'] === 'manual' && cc_vendor_prices_manual_vendors($a_and_b) === [get_term($B)->name]);
  ok('product with no vendor untouched', in_array($none, shop(), true));
  $dto = rest('GET', "/wcss/v1/products/$a_and_b")[1];
  ok('inactive vendor absent from product details', ! in_array($A, array_map('intval', $dto['vendor_id']), true) && ! in_array($A, $dto['assigned_vendor_ids'], true) && (int) $dto['vendor']['id'] === $B, wp_json_encode($dto['vendor']));

  // Saving the form (which only lists active vendors) must not lose the inactive one
  rest('PUT', "/wcss/v1/products/$a_and_b", ['vendor_ids' => [$B], 'name' => 'TEST vendor status']);
  ok('saving keeps the inactive vendor assignment', in_array($A, wp_get_object_terms($a_and_b, 'wcss_vendor', ['fields' => 'ids']), true));

  // Removing the last active vendor from a product hides it straight away
  wp_set_object_terms($a_and_b, [$A], 'wcss_vendor', false);
  ok('hides when last active vendor removed', ! in_array($a_and_b, shop(), true));
  wp_set_object_terms($a_and_b, [$A, $B], 'wcss_vendor', false);
  ok('returns when active vendor added', in_array($a_and_b, shop(), true));

  // Live vendor
  ok('G&T wins while active', cc_vendor_prices_resolve_cheapest($amz_gt)['vendor'] === 'gt');
  $o = wc_create_order(); $o->add_product(wc_get_product($amz_gt), 1); $o->calculate_totals(); $o->save(); $orders[] = $o->get_id();
  $it = current($o->get_items()); $it->update_meta_data('_cc_vendor', 'gt'); $it->update_meta_data('_cc_vendor_id', 'SKUgt'); $it->update_meta_data('_cc_vendor_price', 25); $it->save();
  toggle($gt_id, false);
  ok('next-cheapest vendor takes over', cc_vendor_prices_resolve_cheapest($amz_gt)['vendor'] === 'amazon' && (float) cc_vendor_prices_resolve_cheapest($amz_gt)['price'] === 30.0);
  ok('G&T-only product hidden', ! in_array($only_gt, shop(), true));
  ok('multi-vendor product stays', in_array($amz_gt, shop(), true));
  $dto = rest('GET', "/wcss/v1/products/$amz_gt")[1];
  ok('G&T price absent from product details', $dto['gt_price'] === null && ! isset($dto['vendor_prices']['gt']));
  list(, $gtlist) = rest('GET', '/wcss/v1/products', null, ['vendor' => 'grand-toy', 'per_page' => 100]);
  ok('G&T catalog empty', empty($gtlist['items'] ?? $gtlist), wp_json_encode($gtlist));
  ob_start(); $view = 'x'; $path = '/'; include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; $nav = ob_get_clean();
  ok('G&T gone from menu, Amazon stays', strpos($nav, 'Grand &amp; Toy') === false && strpos($nav, 'Amazon catalog') !== false);
  $lines = cc_auto_vendor_submit_collect_lines(wc_get_order($o->get_id()));
  $l = $lines['amazon'][0] ?? null;
  ok('open order moves off inactive vendor', ! $lines['gt'] && $l && $l['vendor_id'] !== 'SKUgt', wp_json_encode($lines));

  // Sync keeps writing rows while inactive; it must not bring the vendor back
  live($only_gt, 'gt', 39);
  ok('price sync does not revive it', cc_vendor_prices_resolve_cheapest($only_gt)['vendor'] === 'none' && ! in_array($only_gt, shop(), true));

  // Reactivate
  toggle($gt_id, true); toggle($A, true);
  ok('vendor back in product form', meta_has($A));
  ok('all products back', count(shop()) === 5, wp_json_encode(shop()));
  ok('prices back', cc_vendor_prices_resolve_cheapest($only_a)['vendor'] === 'manual' && cc_vendor_prices_resolve_cheapest($only_gt)['price'] == 39 && cc_vendor_prices_resolve_cheapest($amz_gt)['vendor'] === 'gt');
  ok('no hidden flags left on test products', ! array_intersect($products, WCSS_Vendor_Status::hidden_ids()));

  // Deleting an inactive vendor must not leave products hidden for ever
  toggle($A, false);
  rest('DELETE', "/wcss/v1/vendors/$A");
  ok('deleting inactive vendor unhides', ! in_array($only_a, WCSS_Vendor_Status::hidden_ids(), true));
} finally {
  if ($was_gt_active) WCSS_Vendor_Status::set_active($gt_id, true);
  foreach ($orders as $id) { $o = wc_get_order($id); if ($o) $o->delete(true); }
  global $wpdb;
  foreach ($products as $id) { $wpdb->delete(cc_vendor_prices_table(), ['product_id' => $id]); wp_delete_post($id, true); }
  foreach ($terms as $id) if (term_exists($id, 'wcss_vendor')) wp_delete_term($id, 'wcss_vendor');
  WCSS_Vendor_Status::refresh_all();
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
