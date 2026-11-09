<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/shipment-test.php
 * SOW 1.23: Supply Ops adds carrier, tracking and expected delivery; Amazon's delivery window fills
 * the date by itself; the store sees it in My Account and gets an email.
 * Captures every email instead of sending, blocks outbound HTTP, cleans up. Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }

$MAIL = [];
remove_all_filters('wp_mail', PHP_INT_MAX); // Keep the real recipients; nothing is sent.
add_filter('pre_wp_mail', function ($r, $a) { global $MAIL; $h = is_array($a['headers']) ? implode("\n", $a['headers']) : (string) $a['headers']; preg_match('/X-WCSS-Context:\s*(\S+)/i', $h, $m);
  $MAIL[] = ['to' => is_array($a['to']) ? implode(',', $a['to']) : $a['to'], 'subject' => $a['subject'], 'ctx' => $m[1] ?? '', 'html' => $a['message']]; return false; }, 1, 2);
add_filter('pre_http_request', function () { return new WP_Error('blocked', 'No outbound HTTP in tests'); }, 1);
add_filter('cc_auto_vendor_submit_enabled', '__return_false');

$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
$owner = get_user_by('login', 'storeowner');
wp_set_current_user($admin);
$SID = 8177; $pass = 0; $fail = 0; $orders = [];
$ym = (new WC_DateTime())->date_i18n('Y-m'); $bk_ledger = WCSS_Ledger::get($SID, $ym);

function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }
function rest($m, $p, $b = null) { $r = new WP_REST_Request($m, $p); if ($b !== null) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($b)); } $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; }
function mk($status) { global $SID, $owner, $orders; $o = wc_create_order(['customer_id' => $owner->ID]); $o->add_product(wc_get_product(7597), 1, ['subtotal' => 7.22, 'total' => 7.22]);
  $o->update_meta_data('_wcss_store_id', $SID); $o->calculate_totals(); $o->save(); $orders[] = $o->get_id(); $o->set_status($status); $o->save(); return $o->get_id(); }
function store_mails() { global $MAIL, $owner; return array_values(array_filter($MAIL, function ($m) use ($owner) { return $m['ctx'] === 'wcss_store_shipment' && strcasecmp($m['to'], $owner->user_email) === 0; })); }
function ship($id, $c, $t, $e) { return rest('POST', "/wcss/v1/orders/$id/shipment", ['carrier' => $c, 'tracking' => $t, 'edd' => $e]); }
function account_html($id) { ob_start(); (new WCSS_MyAccount_Customize())->view_order_delivery(wc_get_order($id)); return ob_get_clean(); }

try {
  $A = mk('approved');
  $tomorrow = wp_date('Y-m-d', strtotime('+3 days'));

  // empty state
  list($c, $d) = rest('GET', "/wcss/v1/orders/$A");
  ok('order API has an editable, empty delivery block', $c === 200 && $d['shipment']['editable'] === true && $d['shipment']['has'] === false && ! empty($d['carriers']['purolator']));
  ok('store sees "with the supplier" before details exist', strpos(account_html($A), 'with the supplier') !== false);

  // add tracking + date
  $MAIL = [];
  list($c, $r) = ship($A, 'purolator', ' 3355 1234 5678 ', $tomorrow);
  $sh = $r['shipment'] ?? [];
  ok('saved', $c === 200 && $sh['changed'] === true, wp_json_encode($r));
  ok('tracking spaces removed', $sh['tracking'] === '335512345678');
  ok('carrier label and tracking link', $sh['carrier_label'] === 'Purolator' && $sh['url'] === 'https://www.purolator.com/en/shipping/tracker?pin=335512345678');
  ok('date saved as entered', $sh['edd'] === $tomorrow && $sh['edd_source'] === 'manual' && $sh['edd_label'] !== '');
  $m = store_mails();
  ok('store emailed once', count($m) === 1, wp_json_encode(array_column($MAIL, 'ctx')));
  ok('email has date, carrier, tracking and a Track button', $m && strpos($m[0]['html'], '335512345678') !== false && strpos($m[0]['html'], 'Purolator') !== false && strpos($m[0]['html'], 'Track your delivery') !== false && strpos($m[0]['subject'], 'Delivery update') !== false);
  ok('no vendor emailed', ! array_filter($MAIL, function ($x) { return strpos($x['ctx'], 'vendor') !== false; }));
  $ev = array_values(array_filter(WCSS_Order_Events::for_order($A, 20), function ($e) { return $e['type'] === 'shipment_update'; }));
  ok('History records the change', $ev && $ev[0]['payload']['tracking'] === '335512345678');
  list(, $d) = rest('GET', "/wcss/v1/orders/$A");
  ok('order API and list show the date', $d['shipment']['tracking'] === '335512345678' && $d['edd'] === $sh['edd_label']);
  $h = account_html($A);
  ok('My Account shows date, carrier and tracking link', strpos($h, 'Expected delivery') !== false && strpos($h, 'Purolator') !== false && strpos($h, 'purolator.com') !== false);
  ob_start(); (new WCSS_MyAccount_Customize())->order_delivery_line(wc_get_order($A)); $line = ob_get_clean();
  ok('My Account order list shows "Expected …"', strpos($line, 'Expected') !== false);

  // same again: no email
  $MAIL = [];
  list($c, $r) = ship($A, 'purolator', '335512345678', $tomorrow);
  ok('saving the same details changes nothing and sends nothing', $c === 200 && $r['shipment']['changed'] === false && ! store_mails());
  // change: email says changed
  $MAIL = [];
  ship($A, 'ups', '1Z999AA10123456784', $tomorrow);
  $m = store_mails();
  ok('a change emails the store with the new details', count($m) === 1 && strpos($m[0]['html'], 'have changed') !== false && strpos($m[0]['html'], '1Z999AA10123456784') !== false);
  // clear: no email
  $MAIL = [];
  list($c, $r) = ship($A, '', '', '');
  ok('clearing works and sends nothing', $c === 200 && $r['shipment']['has'] === false && ! store_mails());

  // refusals
  ok('unknown carrier refused', ship($A, 'pigeon', '123', '')[0] === 400);
  ok('tracking without a carrier refused', ship($A, '', 'ABC123', '')[0] === 400);
  ok('odd characters in tracking refused', ship($A, 'ups', 'ABC#123', '')[0] === 400);
  list(, $r) = ship($A, 'ups', 'AB<b>C</b>12', ''); ok('HTML in tracking is stripped, not stored', ($r['shipment']['tracking'] ?? '') === 'ABC12', wp_json_encode($r));
  ship($A, '', '', '');
  ok('tracking over 64 characters refused', ship($A, 'ups', str_repeat('A', 65), '')[0] === 400);
  ok('not a date refused', ship($A, 'ups', '1Z1', '2026-02-30')[0] === 400);
  ok('date before the order refused', ship($A, 'ups', '1Z1', '2020-01-01')[0] === 400);
  $W = mk('awaiting-approval');
  ok('order not yet approved refused', ship($W, 'ups', '1Z1', '')[0] === 409);
  list(, $d) = rest('GET', "/wcss/v1/orders/$W");
  ok('order not yet approved: block not editable', $d['shipment']['editable'] === false);
  ok('store sees nothing on an unapproved order', account_html($W) === '');
  wp_set_current_user($owner->ID);
  ok('store user cannot set delivery details', in_array(ship($A, 'ups', '1Z1', '')[0], [401, 403], true));
  wp_set_current_user($admin);

  // Amazon window fills the date by itself; Supply Ops date wins
  $Z = mk('approved'); $o = wc_get_order($Z);
  $body = ['lineItems' => [['acceptedItems' => [['artifacts' => [['acceptanceArtifactType' => 'DeliveryTimeRange', 'lowerBoundary' => '2026-12-03T08:00:00Z', 'upperBoundary' => '2026-12-04T07:59:59Z']]]]], ['acceptedItems' => [['artifacts' => [['acceptanceArtifactType' => 'DeliveryTimeRange', 'lowerBoundary' => '2026-12-02T08:00:00Z', 'upperBoundary' => '2026-12-05T07:59:59Z']]]]]]];
  $o->update_meta_data('_wcss_amazon_order_data', ['amazon_response' => ['raw_body' => wp_json_encode($body)]]); $o->save();
  $sh = WCSS_Shipment::get(wc_get_order($Z));
  ok('Amazon delivery window used', $sh['edd_source'] === 'amazon' && $sh['edd'] === wp_date('Y-m-d', strtotime('2026-12-02T08:00:00Z')) && $sh['edd_to'] === wp_date('Y-m-d', strtotime('2026-12-05T07:59:59Z')), wp_json_encode($sh));
  ok('Amazon window shown as a range', strpos($sh['edd_label'], '–') !== false);
  ok('store sees Amazon\'s date', strpos(account_html($Z), 'Expected delivery') !== false);
  $MAIL = [];
  ship($Z, 'amazon', 'TBA123456789', '');
  $sh = WCSS_Shipment::get(wc_get_order($Z));
  ok('tracking added, Amazon date kept', $sh['edd_source'] === 'amazon' && $sh['tracking'] === 'TBA123456789' && $sh['url'] === '');
  ok('store emailed for the tracking number', count(store_mails()) === 1);
  ship($Z, 'amazon', 'TBA123456789', '2026-12-10');
  ok('a date Supply Ops enters wins over Amazon\'s', WCSS_Shipment::get(wc_get_order($Z))['edd'] === '2026-12-10' && WCSS_Shipment::get(wc_get_order($Z))['edd_source'] === 'manual');

  // older field still read
  $L = mk('approved'); $o = wc_get_order($L); $o->update_meta_data('_wcss_edd', '2026-12-20'); $o->save();
  ok('older delivery date field still shows', WCSS_Shipment::get(wc_get_order($L))['edd'] === '2026-12-20');
} finally {
  foreach ($orders as $id) if (wc_get_order($id)) WCSS_Order_Delete::purge($id);
  $now = WCSS_Ledger::get($SID, $ym); WCSS_Ledger::bump($SID, $ym, (int) $bk_ledger['orders_count'] - (int) $now['orders_count'], (float) $bk_ledger['spend_total'] - (float) $now['spend_total']);
}
ok('store ledger back to where it was', wp_json_encode(WCSS_Ledger::get($SID, $ym)) === wp_json_encode($bk_ledger));
echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
