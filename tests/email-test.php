<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/email-test.php
 * Tests Supply Manager → Emails: recipient settings, every notification trigger, manual send,
 * previews, test sends and the log API. Blocks every email (records who would get it instead),
 * uses throwaway orders/vendors on "Test store" (#8177) and restores settings afterwards.
 * Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }
wp_set_current_user((int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0]);

// Capture instead of sending. $FAIL_NEXT makes the next send fail like a refused SMTP server.
$MAIL = []; $FAIL_NEXT = false;
add_filter('pre_wp_mail', function ($r, $a) {
  global $MAIL, $FAIL_NEXT;
  $h = is_array($a['headers']) ? implode("\n", $a['headers']) : (string) $a['headers'];
  preg_match('/X-WCSS-Context:\s*(\S+)/i', $h, $m);
  if ($FAIL_NEXT) { $FAIL_NEXT = false; do_action('wp_mail_failed', new WP_Error('wp_mail_failed', 'SMTP connect() failed.')); return false; }
  $MAIL[] = ['to' => is_array($a['to']) ? implode(',', $a['to']) : $a['to'], 'subject' => $a['subject'], 'ctx' => $m[1] ?? '', 'html' => $a['message']];
  return true;
}, 1, 2);
// The local redirect (mu-plugin) would rewrite every "to"; take it out so we see the real routing.
remove_all_filters('wp_mail', PHP_INT_MAX);

$SID = 8177; $PROD = 8176;
$pass = 0; $fail = 0; $orders = []; $terms = [];
function ok($n, $c, $i = '') { global $pass, $fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n  $i\n"; } }
function rest($m, $p, $b = null, $q = []) { $r = new WP_REST_Request($m, $p); foreach ($q as $k => $v) $r->set_param($k, $v);
  if ($b !== null) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($b)); }
  $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; }
function sent($ctx = null, $to = null) { global $MAIL; return array_values(array_filter($MAIL, function ($m) use ($ctx, $to) {
  return ($ctx === null || $m['ctx'] === $ctx) && ($to === null || strcasecmp($m['to'], $to) === 0); })); }
function mk_order($status = 'awaiting-approval') { global $SID, $PROD, $orders;
  // Like checkout: store + items are set before the first save, so "new order" sees them.
  $o = new WC_Order(); $o->set_customer_id(243); $o->set_billing_email('storeowner.gt@ccsupplies.local'); $o->add_product(wc_get_product($PROD), 1);
  $o->update_meta_data('_wcss_store_id', $SID); $o->calculate_totals(); $o->save(); $orders[] = $o->get_id();
  if ($status) { $o->set_status($status); $o->save(); } return wc_get_order($o->get_id()); }

$bk_recipients = get_option(WCSS_Email_Center::OPTION, null);
$bk_settings   = get_option('wcss_settings');
$A = 'ops-a@example.com'; $B = 'ops-b@example.com'; $C = 'paused@example.com';
try {
  // ---------- settings / seeding ----------
  delete_option(WCSS_Email_Center::OPTION);
  [$st, $d] = rest('GET', '/wcss/v1/emails/settings');
  $mgr = array_map(function ($u) { return strtolower($u->user_email); }, get_users(['role' => 'shop_manager']));
  ok('Settings API lists all 9 notification events', $st === 200 && count($d['events']) === 9, $st);
  ok('First load seeds recipients from shop managers', count($d['recipients']) === count($mgr) && in_array(strtolower($d['recipients'][0]['email']), $mgr, true), json_encode(array_column($d['recipients'], 'email')));

  [$st] = rest('PUT', '/wcss/v1/emails/recipients', ['recipients' => [['email' => 'not-an-email', 'events' => []]]]);
  ok('Save rejects an invalid address', $st === 400);
  [$st] = rest('PUT', '/wcss/v1/emails/recipients', ['recipients' => [['email' => $A], ['email' => strtoupper($A)]]]);
  ok('Save rejects a duplicate address', $st === 400);
  [$st, $d] = rest('PUT', '/wcss/v1/emails/recipients', ['recipients' => [
    ['email' => $A, 'name' => 'Ops A', 'active' => true, 'events' => ['order_new', 'order_approved', 'order_rejected', 'vendor_submitted', 'vendor_failed', 'vendor_changed', 'policy_changed', 'bogus_event']],
    ['email' => $B, 'name' => 'Ops B', 'active' => true, 'events' => ['order_needs_approval', 'order_reopened']],
    ['email' => $C, 'name' => 'Paused', 'active' => false, 'events' => array_keys(WCSS_Email_Center::events())],
  ]]);
  ok('Save stores 3 recipients and drops unknown events', $st === 200 && count($d['recipients']) === 3 && ! in_array('bogus_event', $d['recipients'][0]['events'], true), json_encode($d));
  ok('Saved settings read back unchanged', rest('GET', '/wcss/v1/emails/settings')[1]['recipients'] === $d['recipients']);

  // ---------- order triggers ----------
  $MAIL = [];
  $o = mk_order();
  ok('New order → only A (ticked "New order placed")', count(sent('wcss_event_order_new')) === 1 && sent('wcss_event_order_new', $A), json_encode(array_column(sent('wcss_event_order_new'), 'to')));
  ok('Paused recipient gets nothing', ! sent(null, $C));
  ok('Store employee still gets their order confirmation', (bool) sent('wcss_new_order_store_employee'));
  $store_mail = sent('wcss_new_order_store_employee')[0]['html'] ?? '';
  ok('Store email never names the vendor', stripos($store_mail, '>Vendor<') === false);
  ok('No old-style shop_manager emails any more', ! array_filter($MAIL, function ($m) { return strpos($m['ctx'], 'shop_manager') !== false; }));

  $MAIL = [];
  do_action('wcss_order_policy_recorded', $o, ['in_policy' => false, 'reasons' => ['over_quota'], 'messages' => ['This store has already ordered this month (2 of 1).']]);
  $na = sent('wcss_event_order_needs_approval');
  ok('Needs approval → only B', count($na) === 1 && $na[0]['to'] === $B, json_encode(array_column($na, 'to')));
  ok('Needs-approval email explains why', strpos($na[0]['html'] ?? '', 'already ordered this month') !== false);
  $MAIL = [];
  do_action('wcss_order_policy_recorded', $o, ['in_policy' => true, 'reasons' => [], 'messages' => []]);
  ok('In-policy order sends no approval request', ! sent('wcss_event_order_needs_approval'));

  foreach (['approved' => [$A], 'rejected' => [$A]] as $status => $who) {
    $x = mk_order(); $MAIL = [];
    $x->update_status($status, 'Test ' . $status);
    $got = array_column(sent('wcss_event_order_' . $status), 'to');
    ok("Order $status → " . implode(',', $who) . ' only', $got === $who, json_encode($got));
    $MAIL = [];
    $x->update_status('pending');
    $got = array_column(sent('wcss_event_order_reopened'), 'to');
    ok("$status → pending: \"moved back to pending\" → B only", $got === [$B], json_encode($got));
  }
  $MAIL = [];
  $x = mk_order('pending'); $x->update_status('awaiting-approval'); $x->update_status('pending');
  ok('Pending ↔ awaiting approval is not a "reopen"', ! sent('wcss_event_order_reopened'));
  $MAIL = [];
  $r = mk_order(); $MAIL = [];
  $r->update_status('rejected', 'Over budget — please reorder next month.');
  $rh = sent('wcss_event_order_rejected')[0]['html'] ?? '';
  ok('Rejected email includes the reason, without Woo\'s "status changed" text', strpos($rh, 'please reorder next month') !== false && strpos($rh, 'Order status changed') === false);
  $MAIL = [];
  $r2 = mk_order(); $MAIL = []; $r2->update_status('rejected', 'Status updated (Supply Manager)');
  ok('Default portal note is not shown as a reason', strpos(sent('wcss_event_order_rejected')[0]['html'] ?? '', 'Status updated (Supply Manager)') === false);
  ok('Store employee gets the rejection too', (bool) sent('wcss_status_store_employee'));

  // ---------- vendor triggers ----------
  $MAIL = [];
  do_action('cc_auto_vendor_submit_done', $o->get_id(), ['status' => 'submitted', 'winning_vendor' => 'amazon', 'results' => ['amazon' => ['ok' => true, 'message' => 'Placed. Amazon order 113-1.']]]);
  ok('Vendor accepted → A, names Amazon', count(sent('wcss_event_vendor_submitted', $A)) === 1 && strpos(sent('wcss_event_vendor_submitted')[0]['subject'], 'Amazon accepted') !== false);
  $MAIL = [];
  do_action('cc_auto_vendor_submit_done', $o->get_id(), ['status' => 'failed', 'winning_vendor' => 'gt', 'results' => ['gt' => ['ok' => false, 'message' => 'PO rejected: invalid ship-to']]]);
  $vf = sent('wcss_event_vendor_failed');
  ok('Vendor failed → A, with the vendor response', count($vf) === 1 && strpos($vf[0]['html'], 'invalid ship-to') !== false);
  $MAIL = [];
  do_action('cc_auto_vendor_submit_done', $o->get_id(), ['status' => 'skipped']);
  ok('Skipped auto-submit sends nothing', ! $MAIL);

  $MAIL = [];
  [$st, $v] = rest('POST', '/wcss/v1/vendors/create', ['name' => 'Email Test Vendor ' . wp_generate_password(4, false), 'email' => 'v@example.com', 'strict' => 1]);
  $terms[] = (int) ($v['id'] ?? 0);
  ok('Vendor added → A gets "Vendor details changed"', $st === 200 && count(sent('wcss_event_vendor_changed', $A)) === 1, $st . json_encode($v));
  $MAIL = [];
  rest('PUT', '/wcss/v1/vendors/' . $v['id'], ['email' => 'v@example.com']);
  ok('Vendor saved with no real change → no email', ! sent('wcss_event_vendor_changed'));
  rest('PUT', '/wcss/v1/vendors/' . $v['id'], ['phone' => '403-555-0199']);
  ok('Vendor edited → email lists the changed field', strpos(sent('wcss_event_vendor_changed')[0]['html'] ?? '', '403-555-0199') !== false);
  $MAIL = [];
  rest('POST', '/wcss/v1/vendors/' . $v['id'] . '/budget', ['month' => wp_date('Y-m'), 'amount' => 900]);
  ok('Vendor budget changed → email', count(sent('wcss_event_vendor_changed')) === 1);
  $MAIL = [];
  rest('DELETE', '/wcss/v1/vendors/' . $v['id']);
  ok('Vendor removed → email', count(sent('wcss_event_vendor_changed')) === 1 && strpos(sent('wcss_event_vendor_changed')[0]['subject'], 'removed') !== false);

  // ---------- policy trigger ----------
  $MAIL = [];
  $cur = (int) (get_option('wcss_settings')['order_window_end'] ?? 31);
  rest('PUT', '/wcss/v1/policy', ['order_window_end' => $cur === 30 ? 29 : 30]);
  ok('Ordering policy changed → A, shows old → new', count(sent('wcss_event_policy_changed', $A)) === 1 && strpos(sent('wcss_event_policy_changed')[0]['html'], '→') !== false);
  $MAIL = [];
  rest('PUT', '/wcss/v1/policy', []);
  ok('Policy saved with no change → no email', ! sent('wcss_event_policy_changed'));
  $MAIL = [];
  do_action('wcss_policy_updated', 'apply_all', [], ['budget' => 125, 'quota' => 1, 'stores' => 223]);
  ok('Apply-to-all-stores → email', strpos(sent('wcss_event_policy_changed')[0]['subject'] ?? '', 'all stores') !== false);

  // ---------- template ----------
  $html = sent('wcss_event_policy_changed')[0]['html'] ?? '';
  ok('Template: branded header, title, date and settings link', strpos($html, 'CANNA') !== false && strpos($html, 'Supply Manager') !== false && strpos($html, 'Sent ') !== false && strpos($html, '/manager/emails') !== false);

  // ---------- manual send ----------
  $MAIL = [];
  [$st, $d] = rest('POST', '/wcss/v1/emails/send', ['to' => 'x@example.com, y@example.com', 'subject' => 'Window opens', 'message' => '<p>Hello <strong>team</strong></p><script>alert(1)</script>']);
  ok('Manual send → 2 separate emails', $st === 200 && count($d['sent']) === 2 && count(sent('wcss_manual')) === 2, json_encode($d));
  ok('Manual message keeps formatting, strips scripts', strpos(sent('wcss_manual')[0]['html'], '<strong>team</strong>') !== false && strpos(sent('wcss_manual')[0]['html'], '<script') === false);
  ok('Manual send rejects a bad address', rest('POST', '/wcss/v1/emails/send', ['to' => 'nope', 'subject' => 's', 'message' => 'm'])[0] === 400);
  ok('Manual send needs a subject', rest('POST', '/wcss/v1/emails/send', ['to' => 'x@example.com', 'subject' => '', 'message' => 'm'])[0] === 400);
  ok('Manual send needs a message', rest('POST', '/wcss/v1/emails/send', ['to' => 'x@example.com', 'subject' => 's', 'message' => '<p> </p>'])[0] === 400);
  $FAIL_NEXT = true;
  [$st, $d] = rest('POST', '/wcss/v1/emails/send', ['to' => 'x@example.com', 'subject' => 's', 'message' => 'm']);
  ok('Mail server failure → clear error message', $st === 502 && strpos($d['message'], 'SMTP connect() failed') !== false, $st . ' ' . ($d['message'] ?? ''));
  [$st, $d] = rest('POST', '/wcss/v1/emails/send', ['preview' => 1, 'subject' => 'Hi', 'message' => '<p>Body</p>']);
  ok('Manual preview returns HTML and sends nothing', $st === 200 && strpos($d['html'], 'Body') !== false && count(sent('wcss_manual')) === 2);

  // ---------- previews / tests / log ----------
  $all_prev = true;
  foreach (array_keys(WCSS_Email_Center::events()) as $ev) {
    [$st, $d] = rest('GET', '/wcss/v1/emails/preview', null, ['event' => $ev]);
    if ($st !== 200 || strpos($d['html'] ?? '', '<html') === false) { $all_prev = false; echo "   preview $ev → $st\n"; }
  }
  ok('Preview works for all 9 notifications', $all_prev);
  $MAIL = [];
  [$st, $d] = rest('POST', '/wcss/v1/emails/test', ['event' => 'all', 'to' => 'qa@example.com']);
  ok('"Send all tests" sends 9 [TEST] emails to one address', $st === 200 && $d['sent'] === 9 && count(sent(null, 'qa@example.com')) === 9 && strpos($MAIL[0]['subject'], '[TEST]') === 0, json_encode($d));
  [$st, $d] = rest('GET', '/wcss/v1/emails/log', null, ['q' => 'qa@example.com']);
  ok('Log API lists sent emails', $st === 200 && isset($d['items']));
  if (! empty($d['items'][0]['id'])) {
    [$st, $one] = rest('GET', '/wcss/v1/emails/log/' . $d['items'][0]['id']);
    ok('Log API opens one email', $st === 200 && ! empty($one['message']));
  }

  // ---------- permissions ----------
  wp_set_current_user(243);
  ok('Store employee cannot read email settings', rest('GET', '/wcss/v1/emails/settings')[0] === 403);
  ok('Store employee cannot send email', rest('POST', '/wcss/v1/emails/send', ['to' => 'x@example.com', 'subject' => 's', 'message' => 'm'])[0] === 403);
} finally {
  wp_set_current_user((int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0]);
  foreach ($orders as $id) { if (function_exists('as_unschedule_all_actions')) as_unschedule_all_actions('cc_auto_vendor_submit_async', ['order_id' => $id], 'cc-auto-vendor'); $x = wc_get_order($id); if ($x) $x->delete(true); }
  foreach ($terms as $t) if ($t && term_exists($t, 'wcss_vendor')) wp_delete_term($t, 'wcss_vendor');
  if ($bk_recipients === null) delete_option(WCSS_Email_Center::OPTION); else update_option(WCSS_Email_Center::OPTION, $bk_recipients, false);
  update_option('wcss_settings', $bk_settings);
  global $wpdb; // Drop the log rows this test wrote (example.com addresses only).
  $wpdb->query("DELETE FROM {$wpdb->prefix}wcss_email_logs WHERE mailed_to LIKE '%@example.com%'");
  if ($orders) $wpdb->query("DELETE FROM {$wpdb->prefix}wcss_email_logs WHERE subject REGEXP '#(" . implode('|', array_map('intval', $orders)) . ")([^0-9]|$)'");
  echo "cleanup: deleted ", count($orders), " test orders, test vendors, restored recipients + settings; nothing was emailed\n";
  echo "RESULT: $pass passed, $fail failed\n";
}
