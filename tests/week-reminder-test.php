<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/week-reminder-test.php
 * SOW 1.27: stores get one email when their ordering week opens.
 * Captures every email instead of sending, uses its own test stores and users, and restores settings,
 * the daily job and every store it touched. Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }

$MAIL = [];
add_filter('pre_wp_mail', function ($r, $a) { global $MAIL; $h = is_array($a['headers']) ? implode("\n", $a['headers']) : (string) $a['headers']; preg_match('/X-WCSS-Context:\s*(\S+)/i', $h, $m);
  $MAIL[] = ['to' => is_array($a['to']) ? implode(',', $a['to']) : $a['to'], 'subject' => $a['subject'], 'ctx' => $m[1] ?? '', 'html' => $a['message']]; return true; }, 1, 2);
remove_all_filters('wp_mail', PHP_INT_MAX); // The local mu-plugin redirects every email; keep the real recipients.

$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
$owner = get_user_by('login', 'storeowner')->ID;
wp_set_current_user($admin);
$pass = 0; $fail = 0;
$bk_settings = get_option('wcss_settings');
$bk_last     = get_option(WCSS_Week_Reminder::LAST_RUN, null);
$bk_sent     = get_option(WCSS_Week_Reminder::LAST_SENT, null);
$bk_next     = wp_next_scheduled(WCSS_Week_Reminder::HOOK);

function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }
function rest($m, $p, $b = null) { $r = new WP_REST_Request($m, $p); if ($b !== null) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($b)); } $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; }
function d($ymd) { return new DateTimeImmutable($ymd . ' 07:00:00', wp_timezone()); }
function mails($ctx) { global $MAIL; return array_values(array_filter($MAIL, function ($m) use ($ctx) { return $m['ctx'] === $ctx; })); }
function set_opt($k, $v) { $o = get_option('wcss_settings', []); $o[$k] = $v; update_option('wcss_settings', $o); }

$stores = []; $users = []; $orders = [];
function store($name, $state, $quota, $budget) { global $stores;
  $id = wp_insert_post(['post_type' => WCSS_Store_CPT::POST_TYPE, 'post_status' => 'publish', 'post_title' => $name]);
  update_post_meta($id, WCSS_Store_CPT::META_STATE, $state);
  update_post_meta($id, WCSS_Store_CPT::META_QUOTA, $quota);
  update_post_meta($id, WCSS_Store_CPT::META_BUDGET, $budget);
  $stores[] = $id; return $id; }
function user($login, $store) { global $users;
  $id = wp_insert_user(['user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'customer']);
  update_user_meta($id, WCSS_User_Store_Map::USER_META_STORE_ID, $store); $users[] = $id; return $id; }

try {
  // Start from a known policy: province weeks on, reminder off, policy open all month.
  set_opt('province_weeks_on', 1); set_opt('week_reminder_on', 0);
  set_opt('order_window_from', ''); set_opt('order_window_to', ''); set_opt('order_window_start', 1); set_opt('order_window_end', 31);
  $o = get_option('wcss_settings'); unset($o['province_weeks']); update_option('wcss_settings', $o); // Default days: AB 8–14, MB 15–21.

  $ab   = store('TEST Reminder Alberta', 'AB', 2, 120);
  $mb   = store('TEST Reminder Manitoba', 'MB', 2, 120);
  $full = store('TEST Reminder Full', 'AB', 1, 120);
  $lone = store('TEST Reminder No users', 'AB', 2, 120);
  $own  = store('TEST Reminder Own dates', 'AB', 2, 120);
  update_post_meta($own, WCSS_Order_Policy::STORE_WINDOW_FROM, '2026-11-25');
  update_post_meta($own, WCSS_Order_Policy::STORE_WINDOW_TO, '2026-12-05');
  update_post_meta($own, WCSS_Order_Policy::STORE_WINDOW_REPEAT, 1);
  user('wr_ab_one', $ab); user('wr_ab_two', $ab); user('wr_mb', $mb); user('wr_full', $full); user('wr_own', $own);
  // $full already used its 1 order this month.
  $ord = wc_create_order(['customer_id' => end($users)]); $ord->update_meta_data('_wcss_store_id', $full); $ord->set_total(10); $ord->set_status('approved'); $ord->save(); $orders[] = $ord->get_id();
  update_user_meta(end($users), WCSS_User_Store_Map::USER_META_STORE_ID, $full);

  add_filter('wcss_week_reminder_store_ids', function () { global $stores; return $stores; });

  // ---- Which day is due ----
  ok('AB due on its first day (8th)', ($w = WCSS_Week_Reminder::due_window($ab, d('2026-12-08'))) && $w['days_open'] === 0 && $w['key'] === '2026-12', wp_json_encode($w));
  ok('AB still due 2 days later if a run was missed', ($w = WCSS_Week_Reminder::due_window($ab, d('2026-12-10'))) && $w['days_open'] === 2);
  ok('AB not due 3 days in (no late reminders)', WCSS_Week_Reminder::due_window($ab, d('2026-12-11')) === null);
  ok('AB not due the day before', WCSS_Week_Reminder::due_window($ab, d('2026-12-07')) === null);
  ok('AB not due after its week', WCSS_Week_Reminder::due_window($ab, d('2026-12-15')) === null);
  ok('window closes on the 14th', ($w = WCSS_Week_Reminder::due_window($ab, d('2026-12-08'))) && $w['close']->format('Y-m-d') === '2026-12-14');
  ok('MB due on the 15th, not the 8th', WCSS_Week_Reminder::due_window($mb, d('2026-12-15')) && ! WCSS_Week_Reminder::due_window($mb, d('2026-12-08')));
  ok('window over the month end: due on the 25th', ($w = WCSS_Week_Reminder::due_window($own, d('2026-11-25'))) && $w['close']->format('Y-m-d') === '2026-12-05' && $w['key'] === '2026-11', wp_json_encode($w));
  ok('window over the month end: not due in its tail', WCSS_Week_Reminder::due_window($own, d('2026-12-02')) === null);
  set_opt('province_weeks_on', 0);
  ok('store open all month is never due', WCSS_Week_Reminder::due_window($ab, d('2026-12-01')) === null && WCSS_Week_Reminder::due_window($ab, d('2026-12-08')) === null);
  set_opt('order_window_from', '2027-01-04'); set_opt('order_window_to', '2027-01-09'); set_opt('order_window_repeat', 0);
  ok('one-off dates: due on the first date', ($w = WCSS_Week_Reminder::due_window($ab, d('2027-01-04'))) && $w['key'] === 'once:2027-01-04');
  set_opt('order_window_from', ''); set_opt('order_window_to', ''); set_opt('order_window_repeat', 1); set_opt('province_weeks_on', 1);

  // ---- Off: nothing happens ----
  $r = WCSS_Week_Reminder::run(d('2026-12-08'));
  ok('off: nothing sent', $r['emails'] === 0 && ! mails(WCSS_Week_Reminder::CONTEXT));
  WCSS_Week_Reminder::sync_schedule();
  ok('off: no daily job', ! wp_next_scheduled(WCSS_Week_Reminder::HOOK));

  // ---- Turn on from the Ordering policy ----
  $MAIL = [];
  list($c, $p) = rest('PUT', '/wcss/v1/policy', ['week_reminder_on' => 1]);
  ok('turned on through the policy', $c === 200 && ! empty($p['week_reminder']['on']), wp_json_encode($p['week_reminder'] ?? null));
  $next = wp_next_scheduled(WCSS_Week_Reminder::HOOK);
  ok('daily job scheduled at 7:00 AM site time', $next && wp_date('H:i', $next) === '07:00', $next ? wp_date('Y-m-d H:i', $next) : 'none');
  ok('policy shows the next run', ($p['week_reminder']['next'] ?? '') !== '');
  $pc = array_values(array_filter($MAIL, function ($m) { return strpos($m['subject'], 'Ordering policy changed') !== false; }));
  ok('Supply Ops told the reminder was turned on', $pc && strpos($pc[0]['html'], 'Email stores when their week opens') !== false && strpos($pc[0]['html'], 'Off → On') !== false, count($pc) . ' policy emails');

  // ---- AB opens on the 8th ----
  $MAIL = [];
  $r = WCSS_Week_Reminder::run(d('2026-12-08'));
  $m = mails(WCSS_Week_Reminder::CONTEXT);
  $to = array_column($m, 'to'); sort($to);
  ok('both Alberta users emailed, one email each', $to === ['wr_ab_one@example.test', 'wr_ab_two@example.test'], wp_json_encode($to));
  ok('Manitoba not emailed on the 8th', ! in_array('wr_mb@example.test', $to, true));
  ok('store with no orders left not emailed', ! in_array('wr_full@example.test', $to, true) && $r['skipped']['no_orders_left'] === 1, wp_json_encode($r));
  ok('store with no users counted, not failed', $r['skipped']['no_users'] === 1 && $r['failed'] === 0);
  ok('run summary', $r['stores'] === 1 && $r['emails'] === 2, wp_json_encode($r));
  $h = $m[0]['html'];
  ok('subject says when it closes', $m[0]['subject'] === '[Internal Supply] Your ordering week is open until Monday 14 Dec', $m[0]['subject']);
  ok('email names the store and days', strpos($h, 'TEST Reminder Alberta') !== false && strpos($h, '8–14 Dec 2026') !== false);
  ok('email shows orders and budget left', strpos($h, 'Orders left this month') !== false && strpos($h, '>2<') !== false && strpos($h, 'CA$120.00') !== false);
  ok('email links to the shop', strpos($h, home_url('/shop/')) !== false && strpos($h, 'Start your order') !== false);
  ok('store remembers the window', get_post_meta($ab, WCSS_Week_Reminder::META_KEY, true) === '2026-12');
  ok('last run saved for the policy pop-up', ($l = get_option(WCSS_Week_Reminder::LAST_RUN)) && $l['emails'] === 2);

  // ---- Never twice ----
  $MAIL = [];
  WCSS_Week_Reminder::run(d('2026-12-08'));
  WCSS_Week_Reminder::run(d('2026-12-09'));
  ok('second run the same day sends nothing', ! mails(WCSS_Week_Reminder::CONTEXT));
  ok('an empty run keeps "last sent"', ($ls = get_option(WCSS_Week_Reminder::LAST_SENT)) && $ls['emails'] === 2 && get_option(WCSS_Week_Reminder::LAST_RUN)['emails'] === 0);
  ok('next day (catch-up day) sends nothing again', ! mails(WCSS_Week_Reminder::CONTEXT));

  // ---- Missed run: caught up within 2 days ----
  $MAIL = [];
  WCSS_Week_Reminder::run(d('2026-12-17'));
  ok('Manitoba caught up on the 17th after a missed 15th', array_column(mails(WCSS_Week_Reminder::CONTEXT), 'to') === ['wr_mb@example.test']);

  // ---- A user linked later gets the next run ----
  $MAIL = [];
  user('wr_lone', $lone);
  WCSS_Week_Reminder::run(d('2026-12-09'));
  ok('store with a newly linked user is emailed on the next run', array_column(mails(WCSS_Week_Reminder::CONTEXT), 'to') === ['wr_lone@example.test']);

  // ---- Next month ----
  $MAIL = [];
  WCSS_Week_Reminder::run(d('2027-01-08'));
  $to = array_column(mails(WCSS_Week_Reminder::CONTEXT), 'to');
  ok('Alberta reminded again next month', in_array('wr_ab_one@example.test', $to, true) && get_post_meta($ab, WCSS_Week_Reminder::META_KEY, true) === '2027-01');

  // ---- The real daily job (WordPress fires the hook itself, sometimes with an empty argument) ----
  $MAIL = [];
  delete_post_meta($ab, WCSS_Week_Reminder::META_KEY);
  add_filter('wcss_week_reminder_store_ids', function () { global $ab; return [$ab]; }, 20);
  $err = '';
  try { do_action(WCSS_Week_Reminder::HOOK); do_action_ref_array(WCSS_Week_Reminder::HOOK, []); } catch (Throwable $e) { $err = $e->getMessage(); }
  ok('daily job runs from the WordPress hook without errors', $err === '', $err);
  remove_all_filters('wcss_week_reminder_store_ids', 20);
  update_post_meta($ab, WCSS_Week_Reminder::META_KEY, '2027-01');

  // ---- Lock: two runs at once ----
  $MAIL = [];
  set_transient('wcss_week_reminder_lock', 1, 60);
  ok('a run already in progress blocks a second one', WCSS_Week_Reminder::run(d('2027-02-08'))['emails'] === 0 && ! mails(WCSS_Week_Reminder::CONTEXT));
  delete_transient('wcss_week_reminder_lock');

  // ---- Preview ----
  $MAIL = [];
  $before = get_post_meta($ab, WCSS_Week_Reminder::META_KEY, true);
  list($c, $p) = rest('POST', '/wcss/v1/policy/week-reminder/preview', ['store_id' => $ab]);
  $pm = mails(WCSS_Week_Reminder::CONTEXT . '_preview');
  ok('preview sent to the signed-in manager only', $c === 200 && count($MAIL) === 1 && $pm && $pm[0]['to'] === wp_get_current_user()->user_email, wp_json_encode([$c, $p]));
  ok('preview subject marked', $pm && strpos($pm[0]['subject'], '[Preview] ') === 0);
  ok('preview does not use up the store’s reminder', get_post_meta($ab, WCSS_Week_Reminder::META_KEY, true) === $before);
  wp_set_current_user($owner);
  ok('store owner cannot send a preview', in_array(rest('POST', '/wcss/v1/policy/week-reminder/preview', [])[0], [401, 403], true));
  wp_set_current_user($admin);

  // ---- Turn off ----
  list($c, $p) = rest('PUT', '/wcss/v1/policy', ['week_reminder_on' => 0]);
  ok('turned off: daily job removed', $c === 200 && ! wp_next_scheduled(WCSS_Week_Reminder::HOOK) && empty($p['week_reminder']['on']));
  $MAIL = [];
  WCSS_Week_Reminder::run(d('2027-03-08'));
  ok('off: run sends nothing', ! mails(WCSS_Week_Reminder::CONTEXT));
} finally {
  update_option('wcss_settings', $bk_settings);
  if ($bk_last === null) delete_option(WCSS_Week_Reminder::LAST_RUN); else update_option(WCSS_Week_Reminder::LAST_RUN, $bk_last, false);
  if ($bk_sent === null) delete_option(WCSS_Week_Reminder::LAST_SENT); else update_option(WCSS_Week_Reminder::LAST_SENT, $bk_sent, false);
  wp_clear_scheduled_hook(WCSS_Week_Reminder::HOOK);
  if ($bk_next) wp_schedule_event($bk_next, 'daily', WCSS_Week_Reminder::HOOK);
  delete_transient('wcss_week_reminder_lock');
  foreach ($orders as $id) { $o = wc_get_order($id); if ($o) $o->delete(true); }
  require_once ABSPATH . 'wp-admin/includes/user.php';
  foreach ($users as $id) wp_delete_user($id);
  foreach ($stores as $id) wp_delete_post($id, true);
}
ok('settings restored', get_option('wcss_settings') == $bk_settings);
ok('daily job back as it was', (bool) wp_next_scheduled(WCSS_Week_Reminder::HOOK) === (bool) $bk_next);
echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
