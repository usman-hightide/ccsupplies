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
  // Real orders may already exist on the test store this month; set limits on top of them so
  // "first order" means the first order this test places.
  [$U0, $A0] = WCSS_Order_Policy::month_usage($SID);
  $Q1 = $U0 + 1; $B1 = round($A0 + 125, 2);
  update_post_meta($SID, WCSS_Store_CPT::META_QUOTA, $Q1); update_post_meta($SID, WCSS_Store_CPT::META_BUDGET, $B1);
  $o = get_option('wcss_settings'); $o['order_window_start'] = 1; $o['order_window_end'] = 31; $o['limit_mode'] = 'review'; $o['default_monthly_budget'] = 125; $o['order_window_from'] = ''; $o['order_window_to'] = ''; update_option('wcss_settings', $o);

  // B1 fallback: empty store budget -> default 125
  update_post_meta($SID, WCSS_Store_CPT::META_BUDGET, '');
  ok('B1 empty store budget falls back to $125 default', WCSS_Order_Policy::store_limits($SID)['budget'] == 125.0, json_encode(WCSS_Order_Policy::store_limits($SID)));
  update_post_meta($SID, WCSS_Store_CPT::META_BUDGET, $B1);

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
  update_post_meta($SID, WCSS_Store_CPT::META_QUOTA, $U0 + 5);
  $c = mk_order(3); // $171 + earlier $114 used
  $rc = WCSS_Order_Policy::evaluate_order($c);
  ok('C1 over $125 budget -> over_budget', in_array('over_budget', $rc['reasons'], true), json_encode($rc));
  update_post_meta($SID, WCSS_Store_CPT::META_QUOTA, $Q1);

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

  // date window (Ordering policy pop-up)
  $today = wp_date('Y-m-d'); $o = get_option('wcss_settings');
  $o['order_window_from'] = wp_date('Y-m-d', strtotime('+3 days')); $o['order_window_to'] = wp_date('Y-m-d', strtotime('+6 days')); $o['order_window_repeat'] = 0; update_option('wcss_settings', $o);
  ok('Date window (one-off) in the future → today outside', ! WCSS_Order_Policy::in_window());
  $o['order_window_from'] = wp_date('Y-m-d', strtotime('-1 day')); $o['order_window_to'] = wp_date('Y-m-d', strtotime('+1 day')); update_option('wcss_settings', $o);
  ok('Date window (one-off) around today → inside', WCSS_Order_Policy::in_window());
  $o['order_window_from'] = wp_date('Y-m-d', strtotime('-40 days')); $o['order_window_to'] = wp_date('Y-m-d', strtotime('-35 days')); $o['order_window_repeat'] = 0; update_option('wcss_settings', $o);
  ok('Old one-off dates → outside', ! WCSS_Order_Policy::in_window());
  $d = (int) wp_date('j'); $o['order_window_from'] = wp_date('Y-m-', strtotime('-60 days')) . sprintf('%02d', min(28, $d)); $o['order_window_to'] = wp_date('Y-m-', strtotime('-60 days')) . sprintf('%02d', min(28, $d)); $o['order_window_repeat'] = 1; update_option('wcss_settings', $o);
  ok('Repeat every month uses the days of the dates (today\'s day → inside)', $d > 28 || WCSS_Order_Policy::in_window());
  ok('Window label reads naturally', (bool) preg_match('/of every month|all month|\d{4}/', WCSS_Order_Policy::window_label()), WCSS_Order_Policy::window_label());
  $o['order_window_from'] = ''; $o['order_window_to'] = ''; update_option('wcss_settings', $o);
  [$st] = rest('PUT', '/wcss/v1/policy', ['order_window_from' => '2026-11-10', 'order_window_to' => '']);
  ok('PUT /policy rejects only one date', $st === 400);
  [$st] = rest('PUT', '/wcss/v1/policy', ['order_window_from' => '2026-11-10', 'order_window_to' => '2026-11-05']);
  ok('PUT /policy rejects end before start', $st === 400);
  [$st, $d2] = rest('PUT', '/wcss/v1/policy', ['order_window_from' => '2026-11-07', 'order_window_to' => '2026-11-14', 'order_window_repeat' => 1]);
  ok('PUT /policy saves dates and syncs days 7–14', $st === 200 && $d2['order_window_from'] === '2026-11-07' && (int) get_option('wcss_settings')['order_window_start'] === 7 && (int) get_option('wcss_settings')['order_window_end'] === 14, json_encode($d2));
  [$st] = rest('PUT', '/wcss/v1/policy', ['order_window_from' => '', 'order_window_to' => '']);
  ok('"Open all month" clears dates → days 1–31', $st === 200 && (int) get_option('wcss_settings')['order_window_start'] === 1 && (int) get_option('wcss_settings')['order_window_end'] === 31 && WCSS_Order_Policy::in_window());
  // apply limits to selected stores only
  $other = (int) get_posts(['post_type' => 'store_location', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'post__not_in' => [$SID]])[0];
  $bk_other = [get_post_meta($other, WCSS_Store_CPT::META_QUOTA, true), get_post_meta($other, WCSS_Store_CPT::META_BUDGET, true)];
  [$st, $d3] = rest('POST', '/wcss/v1/stores/apply-limits', ['budget' => 77, 'quota' => 3, 'store_ids' => [$SID]]);
  ok('apply-limits with store_ids updates only that store', $st === 200 && $d3['stores'] === 1 && (float) get_post_meta($SID, WCSS_Store_CPT::META_BUDGET, true) == 77 && get_post_meta($other, WCSS_Store_CPT::META_BUDGET, true) === $bk_other[1], json_encode($d3));
  ok('apply-limits rejects an empty selection', rest('POST', '/wcss/v1/stores/apply-limits', ['budget' => 77, 'store_ids' => []])[0] === 400);
  ok('apply-limits rejects unknown store ids', rest('POST', '/wcss/v1/stores/apply-limits', ['budget' => 77, 'store_ids' => [999999]])[0] === 400);

  // per-store rules (Edit store page) override the policy for that store only
  $sp = function ($m, $b = null) use ($SID) { return rest($m, '/wcss/v1/stores/' . $SID . '/policy', $b); };
  [$st, $g] = $sp('GET');
  ok('GET store policy shows store, defaults, effective and usage', $st === 200 && isset($g['store'], $g['defaults'], $g['effective'], $g['usage']));
  $f = wp_date('Y-m-d', strtotime('+3 days')); $t = wp_date('Y-m-d', strtotime('+5 days'));
  [$st, $g] = $sp('PUT', ['use_default_window' => false, 'window_from' => $f, 'window_to' => $t, 'window_repeat' => 0, 'limit_mode' => 'block']);
  ok('Store-only window: this store is outside, the policy is not', $st === 200 && ! WCSS_Order_Policy::in_window(null, $SID) && WCSS_Order_Policy::in_window(), json_encode($g['effective'] ?? $g));
  ok('Store-only rule: block for this store, policy stays review', WCSS_Order_Policy::mode($SID) === 'block' && WCSS_Order_Policy::mode() === 'review');
  ok('Store window feeds the approval check (outside_window)', in_array('outside_window', WCSS_Order_Policy::evaluate($SID, 1.0)['reasons'], true));
  [$st] = $sp('PUT', ['use_default_window' => false, 'window_from' => $t, 'window_to' => $f]);
  ok('Store window rejects closing before opening', $st === 400);
  [$st] = $sp('PUT', ['use_default_limits' => false, 'budget' => '', 'quota' => 1]);
  ok('Store limits reject an empty budget', $st === 400);
  [$st, $g] = $sp('PUT', ['use_default_limits' => true, 'use_default_window' => true, 'limit_mode' => '']);
  ok('"Use the default" clears every store override', $st === 200 && $g['store']['budget'] === '' && $g['store']['window_from'] === '' && $g['store']['limit_mode'] === '' && $g['effective']['window_source'] === 'policy', json_encode($g['store']));
  ok('Default limits come from the policy', (float) $g['effective']['budget'] === 125.0);
  update_post_meta($SID, WCSS_Store_CPT::META_QUOTA, $Q1); update_post_meta($SID, WCSS_Store_CPT::META_BUDGET, $B1);

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
  foreach (['_wcss_window_from', '_wcss_window_to', '_wcss_window_repeat', '_wcss_limit_mode'] as $k) delete_post_meta($SID, $k);
  update_option('wcss_settings', $bk_opts);
  echo "cleanup: deleted ", count($created), " test orders; store 8177 restored to quota=", get_post_meta($SID, WCSS_Store_CPT::META_QUOTA, true), " budget=", get_post_meta($SID, WCSS_Store_CPT::META_BUDGET, true), "; settings restored\n";
  echo "emails blocked during test: ", $GLOBALS['mails_blocked'], "\n"; echo "RESULT: $pass passed, $fail failed\n";
}
