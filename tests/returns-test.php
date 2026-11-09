<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/returns-test.php
 * SOW 1.24: a store requests a return from My Account; Supply Ops sees it in Supply Manager → Returns,
 * approves or rejects it with a note, then closes it. The store is emailed at each step.
 * Captures every email instead of sending, blocks outbound HTTP, cleans up. Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }

$MAIL = [];
remove_all_filters('wp_mail', PHP_INT_MAX); // Keep the real recipients; nothing is sent.
add_filter('pre_wp_mail', function ($r, $a) { global $MAIL; $h = is_array($a['headers']) ? implode("\n", $a['headers']) : (string) $a['headers']; preg_match('/X-WCSS-Context:\s*(\S+)/i', $h, $m);
  $MAIL[] = ['to' => is_array($a['to']) ? implode(',', $a['to']) : $a['to'], 'subject' => $a['subject'], 'ctx' => $m[1] ?? '', 'html' => $a['message']]; return true; }, 1, 2);
add_filter('pre_http_request', function () { return new WP_Error('blocked', 'No outbound HTTP in tests'); }, 1);
add_filter('cc_auto_vendor_submit_enabled', '__return_false');

$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
$owner = get_user_by('login', 'storeowner');
wp_set_current_user($admin);
$SID = 8177; $pass = 0; $fail = 0; $orders = []; $users = [];
$ym = (new WC_DateTime())->date_i18n('Y-m'); $bk_ledger = WCSS_Ledger::get($SID, $ym);
$bk_rcpt = get_option(WCSS_Email_Center::OPTION); $bk_flag = get_option('wcss_return_event_added', null);
$before_ids = get_posts(['post_type' => WCSS_Returns::POST_TYPE, 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'any']);

function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }
function rest($m, $p, $b = null) { $r = new WP_REST_Request($m, $p); if ($b !== null) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($b)); } $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; }
function mk($status, $qtys = [3, 2]) { global $SID, $owner, $orders; $o = wc_create_order(['customer_id' => $owner->ID]);
  $o->add_product(wc_get_product(7597), $qtys[0], ['subtotal' => 7.22 * $qtys[0], 'total' => 7.22 * $qtys[0]]);
  if (isset($qtys[1])) $o->add_product(wc_get_product(7597), $qtys[1], ['subtotal' => 5.00 * $qtys[1], 'total' => 5.00 * $qtys[1]]);
  $o->update_meta_data('_wcss_store_id', $SID); $o->calculate_totals(); $o->save(); $orders[] = $o->get_id(); $o->set_status($status); $o->save(); return wc_get_order($o->get_id()); }
function items($o) { return array_keys($o->get_items('line_item')); }
function mails($ctx) { global $MAIL; return array_values(array_filter($MAIL, function ($m) use ($ctx) { return $m['ctx'] === $ctx; })); }
// One object without its constructor, so the test doesn't add a second set of hooks (and second emails).
$ACC = (new ReflectionClass('WCSS_Returns_Account'))->newInstanceWithoutConstructor();
function card($o) { global $ACC; ob_start(); $ACC->render_card($o->get_id()); return ob_get_clean(); }

try {
  // Supply Ops recipient for the "Return requested" email.
  update_option(WCSS_Email_Center::OPTION, [['email' => 'ops-test@ccsupplies.local', 'name' => 'Ops', 'active' => true, 'events' => ['return_requested']]], false);

  // ---------- Who and which orders ----------
  $W = mk('awaiting-approval');
  $r = WCSS_Returns::create($W, $owner->ID, 'damaged', 'Box crushed', [items($W)[0] => 1]);
  ok('order not yet approved: return refused', is_wp_error($r) && $r->get_error_code() === 'not_returnable');
  wp_set_current_user($owner->ID);
  ok('store sees no return card on an unapproved order', card($W) === '');
  wp_set_current_user($admin);

  $A = mk('approved'); [$i1, $i2] = items($A);
  $other = wp_insert_user(['user_login' => 'ret_other_' . wp_generate_password(4, false), 'user_pass' => wp_generate_password(), 'user_email' => 'ret-other-' . wp_generate_password(4, false) . '@ccsupplies.local', 'role' => 'customer']);
  $users[] = $other; update_user_meta($other, '_wcss_store_id', 8081);
  $r = WCSS_Returns::create($A, $other, 'damaged', 'x', [$i1 => 1]);
  ok('another store’s user is refused', is_wp_error($r) && $r->get_error_code() === 'forbidden');

  // ---------- Form checks ----------
  $bad = function ($reason, $note, $qty) use ($A, $owner) { $r = WCSS_Returns::create($A, $owner->ID, $reason, $note, $qty); return is_wp_error($r) ? $r->get_error_code() : 'created'; };
  ok('no reason refused', $bad('', '', [$i1 => 1]) === 'bad_reason');
  ok('unknown reason refused', $bad('stolen', '', [$i1 => 1]) === 'bad_reason');
  ok('damaged needs a note', $bad('damaged', '  ', [$i1 => 1]) === 'need_note');
  ok('wrong item needs a note', $bad('wrong_item', '', [$i1 => 1]) === 'need_note');
  ok('other needs a note', $bad('other', '', [$i1 => 1]) === 'need_note');
  ok('note over 1000 characters refused', $bad('other', str_repeat('a', 1001), [$i1 => 1]) === 'long_note');
  ok('no items chosen refused', $bad('ordered_in_error', '', [$i1 => 0, $i2 => 0]) === 'no_items');
  ok('more than ordered refused', $bad('ordered_in_error', '', [$i1 => 4]) === 'bad_qty');
  ok('negative quantity refused', $bad('ordered_in_error', '', [$i1 => -1]) === 'bad_qty');
  ok('item from another order refused', $bad('ordered_in_error', '', [999999 => 1]) === 'bad_item');
  ok('nothing was saved by the refusals', ! WCSS_Returns::for_order($A->get_id()));

  // ---------- Request ----------
  $MAIL = [];
  $id = WCSS_Returns::create($A, $owner->ID, 'damaged', "Box crushed\nTwo pens broken", [$i1 => 2, $i2 => 0]);
  ok('store request saved', is_int($id) && $id > 0, is_wp_error($id) ? $id->get_error_message() : '');
  $R = WCSS_Returns::get($id);
  ok('request details', $R['status'] === 'submitted' && $R['number'] === 'R-' . $id && $R['items'] === 2 && count($R['lines']) === 1 && $R['reason_label'] === 'Delivered damaged' && $R['note'] === "Box crushed\nTwo pens broken");
  ok('value from the order price', abs($R['value'] - 14.44) < 0.01, (string) $R['value']);
  ok('store and requester recorded', $R['store']['id'] === $SID && $R['requested_by']['email'] === $owner->user_email);
  $m = mails('wcss_store_return');
  ok('store emailed "received"', count($m) === 1 && strcasecmp($m[0]['to'], $owner->user_email) === 0 && strpos($m[0]['subject'], 'We received return request R-' . $id) !== false && strpos($m[0]['html'], 'Box crushed') !== false);
  $m = mails('wcss_event_return_requested');
  ok('Supply Ops emailed with store, reason and a Review link', count($m) === 1 && $m[0]['to'] === 'ops-test@ccsupplies.local' && strpos($m[0]['html'], 'Delivered damaged') !== false && strpos($m[0]['html'], '/manager/returns') !== false, wp_json_encode(array_column($MAIL, 'ctx')));
  ok('no vendor emailed', ! array_filter($MAIL, function ($x) { return strpos($x['ctx'], 'vendor') !== false; }));
  $ev = array_column(WCSS_Order_Events::for_order($A->get_id(), 20), 'type');
  ok('order History records the request', in_array('return_requested', $ev, true));
  ok('order note added', (bool) array_filter(wc_get_order_notes(['order_id' => $A->get_id()]), function ($n) use ($id) { return strpos($n->content, 'R-' . $id) !== false; }));

  // Open quantity goes down; a second request can only take what is left.
  $open = WCSS_Returns::returnable($A);
  ok('open quantity left: 1 and 2', $open[$i1]['open'] === 1 && $open[$i2]['open'] === 2);
  ok('asking for 2 more of the first line refused', $bad('ordered_in_error', '', [$i1 => 2]) === 'bad_qty');
  $id2 = WCSS_Returns::create($A, $owner->ID, 'ordered_in_error', '', [$i1 => 1, $i2 => 2]);
  ok('second request for the rest saved', is_int($id2));
  ok('everything requested: nothing left', array_sum(array_column(WCSS_Returns::returnable($A), 'open')) === 0);

  // ---------- My Account ----------
  wp_set_current_user($owner->ID);
  $h = card($A);
  ok('My Account card lists both requests with status', strpos($h, 'R-' . $id) !== false && strpos($h, 'R-' . $id2) !== false && strpos($h, 'Waiting for review') !== false);
  ok('no form when every item is already requested', strpos($h, 'Request a return') === false && strpos($h, 'already in a return request') !== false);
  ob_start(); $ACC->list_line($A); $line = ob_get_clean();
  ok('orders list shows the return status', strpos($line, 'Return: waiting for review') !== false);
  $B = mk('approved', [1]);
  $h = card($B);
  ok('new order: form with item, reason and note', strpos($h, 'Request a return') !== false && strpos($h, 'wcss_return_qty[') !== false && strpos($h, 'wcss_return_reason') !== false && strpos($h, '_wcss_return_nonce') !== false);
  // A bad submit keeps what the store typed and shows why.
  $bi = items($B)[0];
  $_POST = ['wcss_return_submit' => '1', 'wcss_return_order' => $B->get_id(), '_wcss_return_nonce' => wp_create_nonce(WCSS_Returns_Account::NONCE . $B->get_id()),
            'wcss_return_qty' => [$bi => '5'], 'wcss_return_reason' => 'other', 'wcss_return_note' => 'Too many'];
  $ACC->handle_submit();
  $h = card($B);
  ok('form error shown, input kept', strpos($h, 'You can return up to 1') !== false && strpos($h, 'value="5"') !== false && strpos($h, 'Too many') !== false && strpos($h, ' open>') !== false);
  $_POST['_wcss_return_nonce'] = 'bad';
  $rf = new ReflectionProperty('WCSS_Returns_Account', 'errors'); $rf->setAccessible(true); $rf->setValue(null, []);
  $ACC->handle_submit();
  ok('expired form refused', strpos(implode(' ', $rf->getValue()), 'expired') !== false && ! WCSS_Returns::for_order($B->get_id()));
  $_POST = []; $rf->setValue(null, []);
  ok('store user cannot open the Returns queue', in_array(rest('GET', '/wcss/v1/returns')[0], [401, 403], true));
  ok('store user cannot decide', in_array(rest('POST', "/wcss/v1/returns/$id/decision", ['status' => 'approved'])[0], [401, 403], true));
  wp_set_current_user($admin);

  // ---------- Supply Ops queue ----------
  [$c, $d] = rest('GET', '/wcss/v1/returns', null);
  $mine = array_values(array_filter($d['items'] ?? [], function ($x) use ($id, $id2) { return in_array($x['id'], [$id, $id2], true); }));
  ok('queue lists the requests, newest first', $c === 200 && count($mine) === 2 && $mine[0]['id'] === $id2);
  ok('queue counts open requests', ($d['counts']['submitted'] ?? 0) >= 2 && ($d['counts']['open'] ?? 0) >= 2);
  [$c, $d] = rest('GET', '/wcss/v1/returns', null);
  [, $one] = rest('GET', "/wcss/v1/returns/$id");
  ok('one request with lines, supplier and next steps', $one['id'] === $id && $one['next'] === ['approved', 'rejected']);
  ok('menu badge counts requests waiting', WCSS_Returns::waiting_count() >= 2);
  [, $od] = rest('GET', '/wcss/v1/orders/' . $A->get_id());
  ok('order page gets its returns', count($od['returns'] ?? []) === 2);

  // ---------- Decisions ----------
  ok('closing before approval refused', rest('POST', "/wcss/v1/returns/$id/decision", ['status' => 'closed'])[0] === 409);
  ok('rejecting without a reason refused', rest('POST', "/wcss/v1/returns/$id2/decision", ['status' => 'rejected', 'note' => ' '])[0] === 400);
  ok('unknown action refused', rest('POST', "/wcss/v1/returns/$id/decision", ['status' => 'refunded'])[0] === 400);
  ok('unknown request is 404', rest('POST', '/wcss/v1/returns/999999999/decision', ['status' => 'approved'])[0] === 404);

  $MAIL = [];
  [$c, $r] = rest('POST', "/wcss/v1/returns/$id/decision", ['status' => 'approved', 'note' => 'Amazon will email a return label. Keep the box.']);
  ok('approved with instructions', $c === 200 && $r['status'] === 'approved' && $r['reply'] === 'Amazon will email a return label. Keep the box.' && $r['next'] === ['closed', 'rejected']);
  $m = mails('wcss_store_return');
  ok('store emailed the approval with the instructions', count($m) === 1 && strpos($m[0]['subject'], 'approved') !== false && strpos($m[0]['html'], 'return label') !== false);
  ok('Supply Ops not emailed about their own decision', ! mails('wcss_event_return_requested'));

  $MAIL = [];
  [$c, $r] = rest('POST', "/wcss/v1/returns/$id/decision", ['status' => 'closed', 'note' => 'Amazon refunded CA$14.44']);
  ok('closed', $c === 200 && $r['status'] === 'closed' && $r['next'] === []);
  ok('store emailed that it is complete', count(mails('wcss_store_return')) === 1 && strpos(mails('wcss_store_return')[0]['html'], 'refunded') !== false);
  ok('a closed request can’t change again', rest('POST', "/wcss/v1/returns/$id/decision", ['status' => 'approved'])[0] === 409);

  $MAIL = [];
  [$c, $r] = rest('POST', "/wcss/v1/returns/$id2/decision", ['status' => 'rejected', 'note' => 'Opened items can’t be returned.']);
  ok('rejected with a reason', $c === 200 && $r['status'] === 'rejected');
  $m = mails('wcss_store_return');
  ok('store emailed the rejection with the reason', count($m) === 1 && strpos($m[0]['subject'], 'not approved') !== false && strpos($m[0]['html'], 'Opened items') !== false);
  ok('rejected items can be requested again', WCSS_Returns::returnable($A)[$i2]['open'] === 2 && WCSS_Returns::returnable($A)[$i1]['open'] === 1);
  $hist = array_column(WCSS_Returns::get($id)['history'], 'status');
  ok('request history: submitted → approved → closed', $hist === ['submitted', 'approved', 'closed']);
  $ev = array_column(WCSS_Order_Events::for_order($A->get_id(), 30), 'type');
  ok('order History has each decision', in_array('return_approved', $ev, true) && in_array('return_closed', $ev, true) && in_array('return_rejected', $ev, true));

  // ---------- Emails setting ----------
  update_option(WCSS_Email_Center::OPTION, [['email' => 'a@ccsupplies.local', 'name' => 'A', 'active' => true, 'events' => ['order_needs_approval']], ['email' => 'b@ccsupplies.local', 'name' => 'B', 'active' => true, 'events' => ['order_new']]], false);
  delete_option('wcss_return_event_added');
  WCSS_Email_Center::add_return_event_once();
  $list = get_option(WCSS_Email_Center::OPTION);
  ok('existing approvers start getting "Return requested"', in_array('return_requested', $list[0]['events'], true) && ! in_array('return_requested', $list[1]['events'], true));
  $list[0]['events'] = ['order_needs_approval']; update_option(WCSS_Email_Center::OPTION, $list, false);
  WCSS_Email_Center::add_return_event_once();
  ok('runs once: an untick later stays', ! in_array('return_requested', get_option(WCSS_Email_Center::OPTION)[0]['events'], true));
  ok('"Return requested" is listed in Supply Manager → Emails', isset(WCSS_Email_Center::events()['return_requested']));

  // ---------- Deleting the order removes its requests ----------
  WCSS_Order_Delete::purge($A->get_id());
  ok('deleting the order removes its return requests', ! get_post($id) && ! get_post($id2));
} finally {
  $_POST = [];
  foreach ($orders as $oid) if (wc_get_order($oid)) WCSS_Order_Delete::purge($oid);
  foreach (get_posts(['post_type' => WCSS_Returns::POST_TYPE, 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'any']) as $pid) if (! in_array($pid, $before_ids, true)) wp_delete_post($pid, true);
  foreach ($users as $u) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($u); }
  update_option(WCSS_Email_Center::OPTION, $bk_rcpt, false);
  if ($bk_flag === null) delete_option('wcss_return_event_added'); else update_option('wcss_return_event_added', $bk_flag, false);
  $now = WCSS_Ledger::get($SID, $ym); WCSS_Ledger::bump($SID, $ym, (int) $bk_ledger['orders_count'] - (int) $now['orders_count'], (float) $bk_ledger['spend_total'] - (float) $now['spend_total']);
}
ok('store ledger back to where it was', wp_json_encode(WCSS_Ledger::get($SID, $ym)) === wp_json_encode($bk_ledger));
ok('no test return requests left', count(get_posts(['post_type' => WCSS_Returns::POST_TYPE, 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'any'])) === count($before_ids));
echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
