<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/vendor-po-test.php
 * SOW 2.11: a vendor gets one purchase order per order, only at approval, with only its own lines.
 * Nothing on submit, nothing on completed or re-approval, and one cancel notice if a sent order is called off.
 * Captures every email instead of sending, blocks outbound HTTP, turns auto vendor submit off, and cleans up.
 * Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }

$MAIL = [];
add_filter('pre_wp_mail', function ($r, $a) { global $MAIL; $h = is_array($a['headers']) ? implode("\n", $a['headers']) : (string) $a['headers']; preg_match('/X-WCSS-Context:\s*(\S+)/i', $h, $m);
  $MAIL[] = ['to' => is_array($a['to']) ? implode(',', $a['to']) : $a['to'], 'subject' => $a['subject'], 'ctx' => $m[1] ?? '', 'html' => $a['message']]; return empty($GLOBALS['MAIL_FAIL']); }, 1, 2); // Captured, not sent; reported as delivered unless MAIL_FAIL is set.
remove_all_filters('wp_mail', PHP_INT_MAX); // The local mu-plugin redirects every email to the developer; keep the real recipients.
add_filter('pre_http_request', function () { return new WP_Error('blocked', 'No outbound HTTP in tests'); }, 1);
add_filter('cc_auto_vendor_submit_enabled', '__return_false');

$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
$owner = get_user_by('login', 'storeowner')->ID;
wp_set_current_user($admin);
$SID = 8177; $pass = 0; $fail = 0; $products = []; $orders = []; $terms = [];
$ym = (new WC_DateTime())->date_i18n('Y-m'); $bk_ledger = WCSS_Ledger::get($SID, $ym);
$AZ = get_term_by('slug', 'amazon', 'wcss_vendor'); $bk_az_email = get_term_meta($AZ->term_id, 'wcss_vendor_email', true);

function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }
function to_vendor($email) { global $MAIL; return array_values(array_filter($MAIL, function ($m) use ($email) { return $m['to'] === $email; })); }
function product($name, $price, $vendor_ids, $el = 'none') { global $products; $p = new WC_Product_Simple(); $p->set_name($name); $p->set_status('publish'); $p->set_regular_price($price); $p->save(); $id = $p->get_id(); $products[] = $id;
  update_post_meta($id, 'wcss_procurement_eligibility', $el); wp_set_object_terms($id, $vendor_ids, 'wcss_vendor', false); cc_vendor_prices_recompute_cheapest($id); return $id; }
function order_of(array $lines, $status = 'awaiting-approval') { global $SID, $owner, $orders;
  $o = wc_create_order(['customer_id' => $owner]);
  foreach ($lines as [$pid, $qty, $price, $vendor]) { $t = $price * $qty; $iid = $o->add_product(wc_get_product($pid), $qty, ['subtotal' => $t, 'total' => $t]);
    if ($vendor) { $it = $o->get_item($iid); $it->update_meta_data('_cc_vendor', $vendor); $it->update_meta_data('_cc_vendor_price', $price); $it->save(); } }
  $o->update_meta_data('_wcss_store_id', $SID); $o->calculate_totals(); $o->save();
  $orders[] = $o->get_id(); $o->set_status($status); $o->save(); return $o->get_id(); }
function status($id, $s) { $o = wc_get_order($id); $o->set_status($s); $o->save(); }

$t = wp_insert_term('TEST PO Manual ' . wp_generate_password(5, false), 'wcss_vendor'); $M = (int) $t['term_id']; $terms[] = $M;
update_term_meta($M, 'wcss_vendor_email', 'po-manual@example.com');
$t = wp_insert_term('TEST PO Off ' . wp_generate_password(5, false), 'wcss_vendor'); $OFF = (int) $t['term_id']; $terms[] = $OFF;
update_term_meta($OFF, 'wcss_vendor_email', 'po-off@example.com'); WCSS_Vendor_Status::set_active($OFF, false);
update_term_meta($AZ->term_id, 'wcss_vendor_email', 'po-amazon@example.com');

$PM = product('TEST PO paper', 12, [$M]);
$PA = product('TEST PO pens', 0, [], 'amazon');
$PO = product('TEST PO off item', 9, [$OFF]);

try {
  // 1. submit: no vendor email
  $MAIL = [];
  $A = order_of([[$PM, 2, 12, ''], [$PA, 3, 4, 'amazon']]);
  ok('placing an order emails no vendor', ! to_vendor('po-manual@example.com') && ! to_vendor('po-amazon@example.com'), wp_json_encode(array_column($MAIL, 'to')));
  ok('no "new order" vendor email exists any more', ! array_filter($MAIL, function ($m) { return in_array($m['ctx'], ['wcss_new_order_vendor', 'wcss_status_vendor'], true); }));

  // 2. rejected before approval: still nothing
  $MAIL = [];
  status($A, 'rejected');
  ok('rejecting an unsent order emails no vendor', ! to_vendor('po-manual@example.com') && ! to_vendor('po-amazon@example.com'));

  // 3. approval: one PO per vendor, only its own lines
  $B = order_of([[$PM, 2, 12, ''], [$PA, 3, 4, 'amazon']]);
  $MAIL = [];
  status($B, 'approved');
  $pm = to_vendor('po-manual@example.com'); $pa = to_vendor('po-amazon@example.com');
  ok('approval sends one PO to the manual vendor', count($pm) === 1 && $pm[0]['ctx'] === 'wcss_vendor_notification', wp_json_encode($pm));
  ok('approval sends one PO to the line\'s live vendor', count($pa) === 1);
  ok('manual vendor PO has only its line', strpos($pm[0]['html'], 'TEST PO paper') !== false && strpos($pm[0]['html'], 'TEST PO pens') === false);
  ok('live vendor PO has only its line', strpos($pa[0]['html'], 'TEST PO pens') !== false && strpos($pa[0]['html'], 'TEST PO paper') === false);
  ok('no extra status email to vendors at approval', count(array_filter($MAIL, function ($m) { return strpos($m['to'], 'po-') === 0; })) === 2);
  $sent = wc_get_order($B)->get_meta(WCSS_Notifications::PO_META);
  ok('PO record saved per vendor', isset($sent[$M], $sent[$AZ->term_id]) && $sent[$M]['email'] === 'po-manual@example.com', wp_json_encode($sent));
  $ev = array_values(array_filter(WCSS_Order_Events::for_order($B, 20), function ($e) { return $e['type'] === 'vendor_po'; }));
  ok('History records the PO', $ev && count($ev[0]['payload']['vendors']) === 2);

  // 4. completed, re-approval: nothing again
  $MAIL = [];
  status($B, 'completed');
  ok('completing emails no vendor', ! to_vendor('po-manual@example.com') && ! to_vendor('po-amazon@example.com'));
  status($B, 'awaiting-approval'); status($B, 'approved');
  ok('re-approving never sends a second PO', ! to_vendor('po-manual@example.com') && ! to_vendor('po-amazon@example.com'));

  // 5. called off after sending (rejected after approval): one notice per vendor, once
  $MAIL = [];
  status($B, 'rejected');
  $cm = to_vendor('po-manual@example.com');
  ok('calling off a sent order tells each vendor once', count($cm) === 1 && $cm[0]['ctx'] === 'wcss_vendor_cancelled' && count(to_vendor('po-amazon@example.com')) === 1);
  ok('cancel notice lists only that vendor\'s lines', strpos($cm[0]['html'], 'TEST PO paper') !== false && strpos($cm[0]['html'], 'TEST PO pens') === false);
  $MAIL = [];
  status($B, 'approved'); status($B, 'rejected');
  ok('a second cancel sends nothing', ! to_vendor('po-manual@example.com') && ! to_vendor('po-amazon@example.com'));

  // 6. single-vendor order: one PO; inactive vendor: none
  $C = order_of([[$PM, 1, 12, '']]);
  $MAIL = [];
  status($C, 'approved');
  ok('single-vendor order: exactly one email to vendors', count(to_vendor('po-manual@example.com')) === 1 && ! to_vendor('po-amazon@example.com'));
  $D = order_of([[$PO, 1, 9, '']]);
  $MAIL = [];
  status($D, 'approved');
  ok('inactive vendor gets no PO', ! to_vendor('po-off@example.com'));

  // 6b. a PO whose email fails is not marked sent, so the next approval tries again
  $G = order_of([[$PM, 1, 12, '']]);
  $GLOBALS['MAIL_FAIL'] = true; $MAIL = [];
  status($G, 'approved');
  ok('failed PO email is not recorded as sent', count(to_vendor('po-manual@example.com')) === 1 && ! wc_get_order($G)->get_meta(WCSS_Notifications::PO_META));
  $GLOBALS['MAIL_FAIL'] = false; $MAIL = [];
  status($G, 'awaiting-approval'); status($G, 'approved');
  ok('next approval sends it again', count(to_vendor('po-manual@example.com')) === 1 && wc_get_order($G)->get_meta(WCSS_Notifications::PO_META));

  // 7. live vendor not on the line's price gets nothing, even if assigned to the product
  wp_set_object_terms($PM, [$M, $AZ->term_id], 'wcss_vendor', false);
  $E = order_of([[$PM, 1, 12, '']]);
  $MAIL = [];
  status($E, 'approved');
  ok('a manual line goes to the manual vendor, not Amazon', count(to_vendor('po-manual@example.com')) === 1 && ! to_vendor('po-amazon@example.com'));

  // 8. edited in approval, then approved: the PO carries the edited quantity
  $F = order_of([[$PM, 1, 12, '']]);
  $iid = array_key_first(wc_get_order($F)->get_items());
  $r = new WP_REST_Request('PUT', "/wcss/v1/orders/$F/items"); $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode(['lines' => [['item_id' => $iid, 'qty' => 7]]]));
  ok('fixture: edit saved', rest_do_request($r)->get_status() === 200);
  $MAIL = [];
  status($F, 'approved');
  $pm = to_vendor('po-manual@example.com');
  ok('PO after an edit is sent once', count($pm) === 1);
  ok('PO after an edit shows the edited quantity', (bool) preg_match('/\b7\b/', wp_strip_all_tags($pm[0]['html'] ?? '')) && wc_get_order($F)->get_items()[$iid]->get_quantity() === 7);
} finally {
  foreach ($orders as $id) if (wc_get_order($id)) WCSS_Order_Delete::purge($id);
  global $wpdb;
  foreach ($products as $id) { $wpdb->delete(cc_vendor_prices_table(), ['product_id' => $id]); wp_delete_post($id, true); }
  foreach ($terms as $id) wp_delete_term($id, 'wcss_vendor');
  if ($bk_az_email === '') delete_term_meta($AZ->term_id, 'wcss_vendor_email'); else update_term_meta($AZ->term_id, 'wcss_vendor_email', $bk_az_email);
  WCSS_Vendor_Status::refresh_all();
  $now = WCSS_Ledger::get($SID, $ym); WCSS_Ledger::bump($SID, $ym, (int) $bk_ledger['orders_count'] - (int) $now['orders_count'], (float) $bk_ledger['spend_total'] - (float) $now['spend_total']);
}
ok('Amazon email restored', get_term_meta($AZ->term_id, 'wcss_vendor_email', true) === $bk_az_email);
ok('store ledger back to where it was', wp_json_encode(WCSS_Ledger::get($SID, $ym)) === wp_json_encode($bk_ledger));
echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
