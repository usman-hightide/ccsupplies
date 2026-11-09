<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/gt-order-id-test.php
 * Grand & Toy answers "500 Parsing error" when the PO orderID is over 20 characters (found in M13, 9 Nov).
 * Checks: the automatic PO number fits, a long orderID is refused before sending, and G&T's own
 * status text is kept as the error when a send fails. No real HTTP: every request is answered here.
 * Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }

$pass = 0; $fail = 0;
function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }

// Answer every G&T post locally: valid ack when orderID <= 20 characters, G&T's parsing error otherwise.
$SENT = [];
add_filter('pre_http_request', function ($r, $args, $url) {
  global $SENT;
  preg_match('/orderID="([^"]*)"/', (string) ($args['body'] ?? ''), $m);
  $id = $m[1] ?? ''; $SENT[] = $id;
  $body = strlen($id) <= 20
    ? '<?xml version="1.0"?><Acknowledgement><OrderID>' . $id . '</OrderID><LineNumber value="001"><SupplierPartID>213B</SupplierPartID><Status>Valid</Status><QuantityOrdered>1</QuantityOrdered></LineNumber></Acknowledgement>'
    : '<?xml version="1.0"?><cXML payloadID="x"><Response><Status code="500" text="Parsing error"/></Response></cXML>';
  return ['headers' => [], 'body' => $body, 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 1, 3);

$ship = ['name' => 'Test store', 'deliver_to' => 'Test store', 'streets' => ['11127 15 Street Northeast'], 'city' => 'Calgary', 'state' => 'AB', 'postal_code' => 'T3K 2M4', 'country' => 'CA'];
function po($id) { global $ship; return ['sender_identity' => '588', 'order_id' => $id, 'backorder_method' => 'BO', 'ship_to' => $ship, 'bill_to' => $ship,
  'lines' => [['quantity' => 1, 'line_number' => 1, 'supplier_part_id' => '213B', 'unit_price' => '2.38', 'uom' => 'EA', 'description' => 'Okford Loose Leaf Dividers']]]; }

// 1. A 21-character orderID is refused before anything is sent.
$SENT = [];
$r = cc_gt_submit_order(po('AUTO8246T261108191334'));
ok('21-character orderID refused', empty($r['ok']) && preg_grep('/1-20/', (array) $r['errors']), wp_json_encode($r['errors'] ?? []));
ok('nothing sent for a too-long orderID', $SENT === []);

// 2. 20 characters is accepted.
$r = cc_gt_submit_order(po('TEST2611081925001234'));
ok('20-character orderID accepted', ! empty($r['ok']), wp_json_encode($r['errors'] ?? []));

// 3. The automatic submit makes a PO number that fits, for 4- to 6-digit order IDs.
$o = wc_create_order(); $o->save();
$lines = [['vendor_id' => '213B', 'qty' => 1, 'unit_price' => 2.38, 'name' => 'Okford Loose Leaf Dividers']];
$r = cc_auto_vendor_submit_gt($o, $lines, $ship);
ok('auto PO number is 20 characters or less', strlen((string) $r['po_id']) <= 20, $r['po_id']);
ok('auto PO number format A<order>T<time>', (bool) preg_match('/^A' . $o->get_id() . 'T\d{12}$/', (string) $r['po_id']), $r['po_id']);
ok('auto submit succeeds with the short number', ! empty($r['ok']), $r['message']);
ok('six-digit order IDs still fit', strlen('A' . '999999' . 'T' . gmdate('ymdHis')) <= 20);
$o->delete(true);

// 4. When G&T answers with an error status, its reason is kept (not a blank "PO failed").
remove_all_filters('pre_http_request');
add_filter('pre_http_request', function () {
  return ['headers' => [], 'body' => '<?xml version="1.0"?><cXML payloadID="x"><Response><Status code="500" text="Parsing error"/></Response></cXML>', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 0);
$r = cc_gt_submit_order(po('TEST26110819250'));
ok('failed send keeps G&T reason', empty($r['ok']) && preg_grep('/500 Parsing error/', (array) $r['errors']), wp_json_encode($r['errors'] ?? []));

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
