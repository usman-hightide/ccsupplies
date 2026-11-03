<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/policy-test.php
 * Creates throwaway orders on "Test store" (#8177), blocks every email, and cleans up after itself.
 * Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }
$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
wp_set_current_user($admin);
// Never email anyone (vendors/stores) from tests.
$GLOBALS['mails_blocked'] = 0; add_filter('pre_wp_mail', function () { $GLOBALS['mails_blocked']++; return false; }, 1);
$SID = 8177; $PROD = 8176; // Thermal roll $57
$created = []; $pass = 0; $fail = 0;
function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }
function mk_order($qty, $status = 'awaiting-approval') { global $SID, $PROD, $created;
  $o = wc_create_order(['customer_id' => 243]); $o->add_product(wc_get_product($PROD), $qty); $o->calculate_totals();
  $o->update_meta_data('_wcss_store_id', $SID); $o->save(); $o->set_status($status); $o->save(); $created[] = $o->get_id(); return wc_get_order($o->get_id()); }
function rest($m, $p, $b = null) { $r = new WP_REST_Request($m, $p); if ($b !== null) { $r->set_header('content-type','application/json'); $r->set_body(wp_json_encode($b)); } $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; }

// --- backup ---
$bk_q = get_post_meta($SID, WCSS_Store_CPT::META_QUOTA, true); $bk_b = get_post_meta($SID, WCSS_Store_CPT::META_BUDGET, true);
$bk_opts = get_option('wcss_settings');
try {
  update_post_meta($SID, WCSS_Store_CPT::META_QUOTA, 1); update_post_meta($SID, WCSS_Store_CPT::META_BUDGET, 125);
  $o = get_option('wcss_settings'); $o['order_window_start'] = 1; $o['order_window_end'] = 31; $o['limit_mode'] = 'review'; update_option('wcss_settings', $o);

  // B1 fallback: empty store budget -> default 125
  update_post_meta($SID, WCSS_Store_CPT::META_BUDGET, '');
  ok('B1 empty store budget falls back to $125 default', WCSS_Order_Policy::store_limits($SID)['budget'] == 125.0, json_encode(WCSS_Order_Policy::store_limits($SID)));
  update_post_meta($SID, WCSS_Store_CPT::META_BUDGET, 125);

  // C1 first order under budget -> in policy
  $a = mk_order(1); // $57
  $ra = WCSS_Order_Policy::evaluate_order($a);
  ok('C1 first order $57 is in policy', $ra['in_policy'], json_encode($ra['reasons']));

  // B2 second order -> over_quota
  $b = mk_order(1);
  $rb = WCSS_Order_Policy::evaluate_order($b);
  ok('B2 second order this month -> over_quota', in_array('over_quota', $rb['reasons'], true), json_encode($rb));
  ok('C1 verdict for first order stays stable after 2nd order exists', WCSS_Order_Policy::evaluate_order($a)['in_policy']);

  // over budget: first-ever order of $171 when quota allows
  update_post_meta($SID, WCSS_Store_CPT::META_QUOTA, 5);
  $c = mk_order(3); // $171 + earlier $114 used
  $rc = WCSS_Order_Policy::evaluate_order($c);
  ok('C1 over $125 budget -> over_budget', in_array('over_budget', $rc['reasons'], true), json_encode($rc));
  update_post_meta($SID, WCSS_Store_CPT::META_QUOTA, 1);

  // window
  $day = (int) wp_date('j'); $o = get_option('wcss_settings'); $o['order_window_start'] = ($day % 28) + 2; $o['order_window_end'] = ($day % 28) + 3; update_option('wcss_settings', $o);
  ok('C1 outside window -> outside_window', in_array('outside_window', WCSS_Order_Policy::evaluate_order($a)['reasons'], true));
  $o['order_window_start'] = 25; $o['order_window_end'] = 5; update_option('wcss_settings', $o); // wraps month end
  ok('Window 25 -> 5 wraps month end correctly for day ' . $day, WCSS_Order_Policy::in_window() === ($day >= 25 || $day <= 5));
  $o['order_window_start'] = 1; $o['order_window_end'] = 31; update_option('wcss_settings', $o);

  // auto-submit gate holds out-of-policy order
  $sb = cc_auto_vendor_submit_run($b->get_id());
  $b = wc_get_order($b->get_id());
  ok('Auto-submit holds 2nd order as needs_approval', ($sb['status'] ?? '') === 'needs_approval', json_encode($sb));
  ok('Held order stays awaiting-approval (not auto-approved)', $b->get_status() === 'awaiting-approval', $b->get_status());

  // in-policy order passes gate (no vendor tags -> skipped after gate, not needs_approval)
  $sa = cc_auto_vendor_submit_run($a->get_id());
  ok('Auto-submit lets in-policy order through the gate', ($sa['status'] ?? '') !== 'needs_approval', json_encode($sa['status'] ?? null));

  // manager approve without override -> 409 with reasons
  [$st, $d] = rest('POST', '/wcss/v1/orders/' . $b->get_id() . '/status', ['status' => 'approved']);
  ok('Manager approve of held order w/o override -> 409', $st === 409, "$st " . json_encode($d['message'] ?? $d));
  ok('409 explains reasons', isset($d['data']['reasons']) && in_array('over_quota', $d['data']['reasons'], true), json_encode($d['data'] ?? null));
  // with override -> approved + override meta + resubmitted
  [$st, $d] = rest('POST', '/wcss/v1/orders/' . $b->get_id() . '/status', ['status' => 'approved', 'override' => 1]);
  $b = wc_get_order($b->get_id());
  ok('Manager "Approve anyway" -> approved', $st === 200 && $b->get_status() === 'approved', "$st " . $b->get_status());
  ok('Approval records override', (bool) $b->get_meta('_wcss_policy_override'));
  $sb2 = cc_auto_vendor_submit_run($b->get_id());
  ok('After approval, auto-submit runs past the gate', ($sb2['status'] ?? '') !== 'needs_approval', json_encode($sb2['status'] ?? null));

  // reject path: held order rejected stays unsent
  $r = mk_order(1); cc_auto_vendor_submit_run($r->get_id());
  [$st] = rest('POST', '/wcss/v1/orders/' . $r->get_id() . '/status', ['status' => 'rejected']);
  $rm = wc_get_order($r->get_id())->get_meta('_wcss_auto_vendor_submit');
  ok('Rejected held order is not submitted', $st === 200 && ($rm['status'] ?? '') === 'needs_approval', json_encode($rm['status'] ?? null));

  // orders API exposes policy
  [$st, $d] = rest('GET', '/wcss/v1/orders/' . $r->get_id());
  ok('Orders API exposes policy verdict', isset($d['policy']['in_policy']) && $d['policy']['in_policy'] === false, json_encode($d['policy'] ?? null));

  // checkout: review mode never blocks; block mode blocks (as store employee with cart)
  wp_set_current_user(243);
  if (function_exists('wc_load_cart')) wc_load_cart();
  WC()->cart->empty_cart(); WC()->cart->add_to_cart($PROD, 1); WC()->cart->calculate_totals();
  ok('Review mode: checkout/gateway not blocked', WCSS_Order_Quota::get_violations_messages() === [], json_encode(WCSS_Order_Quota::get_violations_messages()));
  $o = get_option('wcss_settings'); $o['limit_mode'] = 'block'; update_option('wcss_settings', $o);
  $msgs = WCSS_Order_Quota::get_violations_messages();
  ok('Block mode: over-quota cart is blocked at checkout', count($msgs) > 0, json_encode($msgs));
  $o['limit_mode'] = 'review'; update_option('wcss_settings', $o);
  WC()->cart->empty_cart();
  wp_set_current_user($admin);

  // policy REST
  [$st, $d] = rest('GET', '/wcss/v1/policy');
  ok('GET /policy', $st === 200 && isset($d['default_monthly_budget']), json_encode($d));
  [$st, $d] = rest('PUT', '/wcss/v1/policy', ['order_window_start' => 40]);
  ok('PUT /policy rejects day 40', $st === 400);
  [$st, $d] = rest('POST', '/wcss/v1/stores/apply-limits', ['budget' => -5]);
  ok('apply-limits rejects negative budget', $st === 400);
} finally {
  // --- cleanup ---
  if ($created) { global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}wcss_email_logs WHERE subject REGEXP '#(" . implode('|', array_map('intval', $created)) . ")([^0-9]|$)'"); } // blocked test emails, never sent
  foreach ($created as $id) { if (function_exists('as_unschedule_all_actions')) as_unschedule_all_actions('cc_auto_vendor_submit_async', ['order_id' => $id], 'cc-auto-vendor'); $x = wc_get_order($id); if ($x) $x->delete(true); }
  update_post_meta($SID, WCSS_Store_CPT::META_QUOTA, $bk_q); update_post_meta($SID, WCSS_Store_CPT::META_BUDGET, $bk_b);
  update_option('wcss_settings', $bk_opts);
  echo "cleanup: deleted ", count($created), " test orders; store 8177 restored to quota=", get_post_meta($SID, WCSS_Store_CPT::META_QUOTA, true), " budget=", get_post_meta($SID, WCSS_Store_CPT::META_BUDGET, true), "; settings restored\n";
  echo "emails blocked during test: ", $GLOBALS['mails_blocked'], "\n"; echo "RESULT: $pass passed, $fail failed\n";
}
