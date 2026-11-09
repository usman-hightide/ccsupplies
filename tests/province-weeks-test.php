<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/province-weeks-test.php
 * Province ordering weeks: each store orders in its province's days. Restores the settings afterwards.
 * Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }

add_filter('pre_wp_mail', function () { return false; }, 1);
$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
$owner = get_user_by('login', 'storeowner')->ID;
wp_set_current_user($admin);
$pass = 0; $fail = 0;
$bk = get_option('wcss_settings');

function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }
function rest($m, $p, $b = null) { $r = new WP_REST_Request($m, $p); if ($b !== null) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($b)); } $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; }
function on($day) { return new DateTimeImmutable('2026-12-' . sprintf('%02d', $day) . ' 12:00:00', wp_timezone()); }
function open($store, $day) { return WCSS_Order_Policy::in_window(on($day), $store); }
function reasons($store, $day) { return WCSS_Order_Policy::evaluate($store, 1, 0, on($day))['reasons']; }

$by = []; $other = 0;
foreach (get_posts(['post_type' => WCSS_Store_CPT::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']) as $id) {
  $c = WCSS_Order_Policy::store_province((int) $id);
  if ($c === '') { $other = $other ?: (int) $id; continue; }
  if (! isset($by[$c]) && get_post_meta($id, WCSS_Order_Policy::STORE_WINDOW_FROM, true) === '') $by[$c] = (int) $id;
}
$tmp = 0;
try {
  ok('stores found for all 5 provinces', count($by) === 5, wp_json_encode($by));
  $o = get_option('wcss_settings'); $o['province_weeks_on'] = 0; update_option('wcss_settings', $o); // Start from off, whatever the site is set to.
  ok('off by default: all month', open($by['ON'], 20));

  list($c, $d) = rest('PUT', '/wcss/v1/policy', ['province_weeks_on' => 1]);
  ok('turned on', $c === 200 && $d['province_weeks_on'] === true, wp_json_encode($d));
  $sweep = 0;
  foreach ($by as $code => $store) { $w = WCSS_Order_Policy::province_weeks()[$code];
    for ($day = 1; $day <= 31; $day++) { if (open($store, $day) === ($day >= $w['start'] && $day <= $w['end'])) $sweep++; } }
  ok('every province is open only on its own days, all month', $sweep === 5 * 31, (string) $sweep);
  $expect = ['ON' => [1, 7], 'AB' => [8, 14], 'MB' => [15, 21], 'SK' => [22, 28], 'BC' => [22, 28]];
  foreach ($expect as $code => $w) {
    $s = $by[$code]; $name = get_the_title($s);
    ok("$code open on first day", open($s, $w[0]));
    ok("$code open on last day", open($s, $w[1]));
    ok("$code closed the day before", $w[0] === 1 || ! open($s, $w[0] - 1));
    ok("$code closed the day after", ! open($s, $w[1] + 1));
    ok("$code breaks the rule outside its week", in_array('outside_window', reasons($s, $w[1] + 1), true));
    ok("$code passes the window rule inside its week", ! in_array('outside_window', reasons($s, $w[0]), true));
    ok("$code label", WCSS_Order_Policy::window_label($s) === sprintf('days %d–%d of every month', $w[0], $w[1]), WCSS_Order_Policy::window_label($s));
    ok("$code source", WCSS_Order_Policy::window_config($s)['source'] === 'province');
  }
  foreach ([29, 30, 31] as $day) foreach ($by as $code => $s) ok("$code closed on the $day", ! open($s, $day));

  // Province spelling
  ok('full province name matches', WCSS_Order_Policy::store_province($by['AB']) === 'AB');
  $tmp = wp_insert_post(['post_type' => WCSS_Store_CPT::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'TEST province']);
  foreach (['AB' => 'AB', 'alberta' => 'AB', 'Ontario' => 'ON', 'British Columbia' => 'BC'] as $raw => $code) { update_post_meta($tmp, WCSS_Store_CPT::META_STATE, $raw); ok("'$raw' reads as $code", WCSS_Order_Policy::store_province($tmp) === $code); }

  // A store with no recognised province follows the general window
  update_post_meta($tmp, WCSS_Store_CPT::META_STATE, 'ONTAIRO');
  ok('unknown province has no week', WCSS_Order_Policy::store_province($tmp) === '' && WCSS_Order_Policy::window_config($tmp)['source'] === 'policy');
  ok('unknown province open all month', open($tmp, 20));
  list(, $d) = rest('GET', '/wcss/v1/policy');
  ok('unknown province is listed', in_array('ONTAIRO', array_column($d['province_weeks_other'], 'state'), true));
  $counts = array_column($d['province_weeks'], 'stores', 'code');
  ok('store counts shown', $counts['ON'] > 0 && $counts['AB'] > 0, wp_json_encode($counts));

  // A store's own dates win
  update_post_meta($tmp, WCSS_Store_CPT::META_STATE, 'ON');
  update_post_meta($tmp, WCSS_Order_Policy::STORE_WINDOW_FROM, '2026-11-20');
  update_post_meta($tmp, WCSS_Order_Policy::STORE_WINDOW_TO, '2026-11-25');
  update_post_meta($tmp, WCSS_Order_Policy::STORE_WINDOW_REPEAT, 1);
  ok('store dates beat the province week', open($tmp, 22) && ! open($tmp, 3) && WCSS_Order_Policy::window_config($tmp)['source'] === 'store');
  foreach ([WCSS_Order_Policy::STORE_WINDOW_FROM, WCSS_Order_Policy::STORE_WINDOW_TO, WCSS_Order_Policy::STORE_WINDOW_REPEAT] as $k) delete_post_meta($tmp, $k);
  list(, $d) = rest('GET', '/wcss/v1/stores/' . $tmp . '/policy');
  if (isset($d['defaults'])) ok('Edit store shows the province week', $d['defaults']['window_label'] === 'days 1–7 of every month' && $d['effective']['window_source'] === 'province', wp_json_encode($d['defaults']));

  // Editing days
  $weeks = ['ON' => ['start' => 2, 'end' => 9], 'AB' => ['start' => 8, 'end' => 14], 'MB' => ['start' => 15, 'end' => 21], 'SK' => ['start' => 22, 'end' => 28], 'BC' => ['start' => 25, 'end' => 31]];
  list($c,) = rest('PUT', '/wcss/v1/policy', ['province_weeks' => $weeks]);
  ok('days can be changed', $c === 200 && open($by['ON'], 9) && ! open($by['ON'], 1) && open($by['BC'], 31) && ! open($by['SK'], 31));
  $bad = $weeks; $bad['ON'] = ['start' => 9, 'end' => 2];
  ok('first day after last day refused', rest('PUT', '/wcss/v1/policy', ['province_weeks' => $bad])[0] === 400);
  $bad['ON'] = ['start' => 0, 'end' => 32];
  ok('days outside 1–31 refused', rest('PUT', '/wcss/v1/policy', ['province_weeks' => $bad])[0] === 400);
  ok('a refused save changes nothing', open($by['ON'], 9));

  // Rule for breaking the window
  update_option('wcss_settings', array_merge(get_option('wcss_settings'), ['limit_mode' => 'block']));
  ok('block mode still reports outside window', in_array('outside_window', reasons($by['SK'], 3), true) && WCSS_Order_Policy::mode($by['SK']) === 'block');
  update_option('wcss_settings', array_merge(get_option('wcss_settings'), ['limit_mode' => 'review']));

  wp_set_current_user($owner);
  ok('store owner cannot change the policy', in_array(rest('PUT', '/wcss/v1/policy', ['province_weeks_on' => 0])[0], [401, 403], true));
  wp_set_current_user($admin);

  rest('PUT', '/wcss/v1/policy', ['province_weeks_on' => 0]);
  ok('off again: all month', open($by['ON'], 20) && WCSS_Order_Policy::window_config($by['ON'])['source'] === 'policy');
  ok('saved days kept while off', WCSS_Order_Policy::province_weeks()['ON']['start'] === 2);
} finally {
  update_option('wcss_settings', $bk);
  if ($tmp) wp_delete_post($tmp, true);
}
echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
