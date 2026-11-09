<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/budget-125-test.php
 * SOW 1.8: the one-time update moves the default budget and every store at $121 to the agreed $125,
 * leaves other budgets alone, runs once, and every screen and rule then uses $125.
 * Uses throwaway stores, blocks every email, restores settings. Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }

add_filter('pre_wp_mail', '__return_false', 1);
$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
wp_set_current_user($admin);
$pass = 0; $fail = 0; $stores = [];
$bk_settings = get_option('wcss_settings'); $bk_flag = get_option(WCSS_Order_Policy::BUDGET_125_DONE, null);

function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }
function rest($m, $p) { $res = rest_do_request(new WP_REST_Request($m, $p)); return [$res->get_status(), $res->get_data()]; }
function store($budget, $legacy = null) { global $stores; $id = wp_insert_post(['post_type' => WCSS_Store_CPT::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'TEST budget store ' . wp_generate_password(4, false)]); $stores[] = $id;
  if ($budget !== null) update_post_meta($id, WCSS_Store_CPT::META_BUDGET, $budget); if ($legacy !== null) update_post_meta($id, '_store_budget', $legacy); update_post_meta($id, WCSS_Store_CPT::META_QUOTA, 1); return $id; }
function budget($id) { return WCSS_Order_Policy::store_limits($id)['budget']; }

try {
  // The live local data after the update ran on first page load.
  $flag = get_option(WCSS_Order_Policy::BUDGET_125_DONE);
  ok('update ran on this site', is_array($flag) && ! empty($flag['at']), wp_json_encode($flag));
  ok('default budget is $125', (float) WCSS_Order_Policy::settings()['default_monthly_budget'] === 125.0);
  global $wpdb;
  $left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'store_location' WHERE pm.meta_key IN ('_wcss_monthly_budget','_store_budget') AND pm.meta_value + 0 = 121");
  ok('no store is left at $121', $left === 0, (string) $left);
  ok('Test store keeps its own $1,000', budget(8177) === 1000.0);
  ok('a normal store now has $125', budget(8081) === 125.0);

  // Fresh run on throwaway stores.
  $a = store('121'); $b = store('121.00', '121'); $c = store('300'); $d = store(null); $e = store('0');
  $o = get_option('wcss_settings'); $o['default_monthly_budget'] = 121; update_option('wcss_settings', $o);
  delete_option(WCSS_Order_Policy::BUDGET_125_DONE);
  WCSS_Order_Policy::migrate_budget_125();
  $r = get_option(WCSS_Order_Policy::BUDGET_125_DONE);
  ok('default $121 becomes $125', (float) get_option('wcss_settings')['default_monthly_budget'] === 125.0);
  ok('store at $121 becomes $125', get_post_meta($a, WCSS_Store_CPT::META_BUDGET, true) === '125' && budget($a) === 125.0);
  ok('"121.00" and the legacy key both move to $125', budget($b) === 125.0 && get_post_meta($b, '_store_budget', true) === '125');
  ok('a store with its own budget is left alone', budget($c) === 300.0);
  ok('a store with no budget follows the new default', budget($d) === 125.0);
  ok('a $0 store is left alone', get_post_meta($e, WCSS_Store_CPT::META_BUDGET, true) === '0');
  ok('result is recorded with a store count', $r['default'] === true && $r['stores'] === 2, wp_json_encode($r));
  ok('other settings untouched', get_option('wcss_settings')['default_monthly_quota'] == $bk_settings['default_monthly_quota'] && get_option('wcss_settings')['limit_mode'] === $bk_settings['limit_mode']);

  // Runs once: a budget set back to $121 later on purpose stays.
  update_post_meta($a, WCSS_Store_CPT::META_BUDGET, '121');
  WCSS_Order_Policy::migrate_budget_125();
  ok('runs only once', get_post_meta($a, WCSS_Store_CPT::META_BUDGET, true) === '121');

  // A default someone changed on purpose is not overwritten.
  update_post_meta($a, WCSS_Store_CPT::META_BUDGET, '121');
  $o = get_option('wcss_settings'); $o['default_monthly_budget'] = 150; update_option('wcss_settings', $o);
  delete_option(WCSS_Order_Policy::BUDGET_125_DONE);
  WCSS_Order_Policy::migrate_budget_125();
  ok('a default other than $121 is kept', (float) get_option('wcss_settings')['default_monthly_budget'] === 150.0);
  $o['default_monthly_budget'] = 125; update_option('wcss_settings', $o);

  // Screens and rules read $125.
  [$st, $pol] = rest('GET', '/wcss/v1/policy');
  ok('Ordering policy pop-up shows $125', $st === 200 && (float) $pol['default_monthly_budget'] === 125.0);
  [$st, $sp] = rest('GET', '/wcss/v1/stores/8081/policy');
  ok('Edit store limits show $125', $st === 200 && (float) ($sp['effective']['budget'] ?? $sp['budget'] ?? 0) === 125.0, wp_json_encode($sp['effective'] ?? $sp));
  [$st, $sd] = rest('GET', '/wcss/v1/stores/8081');
  ok('Stores list / store record shows $125', $st === 200 && (float) ($sd['budget'] ?? 0) === 125.0, wp_json_encode(array_intersect_key((array) $sd, ['budget' => 1, 'budget_effective' => 1])));
  $req = new WP_REST_Request('GET', '/wcss/v1/new-order/policy'); $req->set_param('store', 8081); $req->set_param('amount', 1);
  $np = rest_do_request($req)->get_data();
  ok('New order limits use $125', (float) $np['budget'] === 125.0);
  ok('order over $125 is flagged over budget', in_array('over_budget', WCSS_Order_Policy::evaluate(8129, 125.01)['reasons'], true));
  ok('order of exactly $125 is within budget', ! in_array('over_budget', WCSS_Order_Policy::evaluate(8129, 125.00)['reasons'], true));
} finally {
  foreach ($stores as $id) wp_delete_post($id, true);
  update_option('wcss_settings', $bk_settings);
  if ($bk_flag === null) delete_option(WCSS_Order_Policy::BUDGET_125_DONE); else update_option(WCSS_Order_Policy::BUDGET_125_DONE, $bk_flag, false);
}
echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
