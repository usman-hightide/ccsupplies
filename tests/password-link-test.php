<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/password-link-test.php
 * Set-password emails: the link works, the password is untouched, and the link never reaches the email log.
 * Blocks every email and creates no users. Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }
global $wpdb;
$GLOBALS['mail'] = []; $GLOBALS['mail_ok'] = true;
add_filter('pre_wp_mail', function ($r, $a) { $GLOBALS['mail'][] = $a; return $GLOBALS['mail_ok']; }, 1, 2);
$pass = 0; $fail = 0;
function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }
function rest($m, $p) { $res = rest_do_request(new WP_REST_Request($m, $p)); return [$res->get_status(), $res->get_data()]; }
$logs = $wpdb->prefix . 'wcss_email_logs';
$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
$manager = get_user_by('login', 'Supply_Manager');
$user = get_user_by('login', 'storeowner');
$hash = $user->user_pass; $key_before = $user->user_activation_key;
$tokens = get_user_meta($user->ID, 'session_tokens', true);
$log_from = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM $logs");
try {
  wp_set_current_user($manager->ID);
  [$st, $d] = rest('POST', "/wcss/v1/users/{$user->ID}/send-password-link");
  ok('Manager can send a link', $st === 200 && ! empty($d['ok']), json_encode($d));
  $sent = end($GLOBALS['mail']);
  ok('One email, right subject', count($GLOBALS['mail']) === 1 && strpos($sent['subject'], 'Set your Canna Cabana') !== false, $sent['subject'] ?? '');
  preg_match('/href="([^"]*lost-password[^"]*)"/', $sent['message'], $m);
  $url = html_entity_decode($m[1] ?? '');
  parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
  ok('Email has a link with key and user', ! empty($q['key']) && (int) ($q['id'] ?? 0) === $user->ID, $url === '' ? 'no link' : 'bad query');
  ok('Key is valid for this user', ! is_wp_error(check_password_reset_key($q['key'] ?? '', $user->user_login)));
  ok('Key is not valid for another user', is_wp_error(check_password_reset_key($q['key'] ?? '', $manager->user_login)));
  ok('Password itself is unchanged', get_userdata($user->ID)->user_pass === $hash);

  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $logs WHERE id > %d ORDER BY id DESC LIMIT 1", $log_from));
  ok('Send is logged', $row && strpos($row->subject, 'Set your') === 0);
  ok('Log has no key or link', $row && strpos($row->message, (string) ($q['key'] ?? 'x')) === false && strpos($row->message, 'lost-password') === false, (string) ($row->message ?? ''));
  apply_filters('wp_mail', ['to' => 'a@b.c', 'subject' => 'WC reset', 'message' => '<a href="https://x/my-account/lost-password/?key=abc123&id=1">r</a>', 'headers' => '']);
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $logs WHERE id > %d ORDER BY id DESC LIMIT 1", $log_from));
  ok('WooCommerce reset emails are also kept out of the log', strpos($row->message, 'abc123') === false, $row->message);

  $GLOBALS['mail_ok'] = false;
  [$st, $d] = rest('POST', "/wcss/v1/users/{$user->ID}/send-password-link");
  ok('A failed send is reported', $st === 502, "$st");
  $GLOBALS['mail_ok'] = true;
  [$st] = rest('POST', '/wcss/v1/users/999999999/send-password-link');
  ok('Unknown user is 404', $st === 404, "$st");
  [$st] = rest('POST', "/wcss/v1/users/$admin/send-password-link");
  ok('Manager cannot send one for an administrator', $st === 403, "$st");

  $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
  $r = new WP_REST_Request('POST', '/wcss/v1/users'); $r->set_header('content-type', 'application/json');
  $r->set_body(wp_json_encode(['first_name' => 'Short', 'email' => 'short-password@ccsupplies.local', 'password' => 'abc']));
  $res = rest_do_request($r);
  ok('Short password is refused, no account created', $res->get_status() === 400 && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}") === $before, (string) $res->get_status());
  $args = rest_get_server()->get_routes()['/wcss/v1/users'] ?? [];
  $has = false; foreach ($args as $h) if (isset($h['args']['password']['sanitize_callback'])) $has = true;
  ok('Password is not altered by a sanitizer', ! $has);

  // Edit user: a throwaway value on storeowner, restored in finally. Sessions and password go back exactly.
  $put = function ($id, $body) { $r = new WP_REST_Request('PUT', "/wcss/v1/users/$id"); $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); return rest_do_request($r); };
  $me = ['name' => trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name, 'email' => $user->user_email];
  $tmp = 'T<e%st ' . wp_generate_password(14, false);
  $res = $put($user->ID, $me + ['password' => 'abc']);
  ok('Edit: short password refused, nothing changed', $res->get_status() === 400 && get_userdata($user->ID)->user_pass === $hash, (string) $res->get_status());
  $res = $put($user->ID, $me);
  ok('Edit: blank keeps the password', $res->get_status() === 200 && get_userdata($user->ID)->user_pass === $hash, (string) $res->get_status());
  $res = $put($user->ID, $me + ['password' => $tmp]);
  clean_user_cache($user->ID);
  ok('Edit: new password works exactly as typed', $res->get_status() === 200 && wp_check_password($tmp, get_userdata($user->ID)->user_pass, $user->ID), (string) $res->get_status());
  ok('Edit: existing logins are signed out', ! WP_Session_Tokens::get_instance($user->ID)->get_all());
  $other = get_users(['role' => 'shop_manager', 'number' => 2, 'exclude' => [$manager->ID], 'fields' => 'ID']);
  if ($other) { $o = get_userdata($other[0]); $h = $o->user_pass; $res = $put($o->ID, ['name' => $o->display_name, 'email' => $o->user_email, 'password' => $tmp]); clean_user_cache($o->ID);
    ok('Edit: manager cannot change another manager', $res->get_status() === 403 && get_userdata($o->ID)->user_pass === $h, (string) $res->get_status()); }
  $res = $put($manager->ID, ['name' => $manager->display_name, 'email' => $manager->user_email, 'password' => $tmp]);
  ok('Edit: cannot change your own here', in_array($res->get_status(), [400, 403], true), (string) $res->get_status());
  $wpdb->update($wpdb->users, ['user_pass' => $hash], ['ID' => $user->ID]); clean_user_cache($user->ID);

  update_user_meta($user->ID, WCSS_REST_Stores::META_INACTIVE, 1);
  [$st] = rest('POST', "/wcss/v1/users/{$user->ID}/send-password-link");
  ok('Inactive user is refused', $st === 409, "$st");
  delete_user_meta($user->ID, WCSS_REST_Stores::META_INACTIVE);

  wp_set_current_user($user->ID);
  [$st] = rest('POST', "/wcss/v1/users/{$manager->ID}/send-password-link");
  ok('Store employee cannot use it', in_array($st, [401, 403], true), "$st");
} finally {
  delete_user_meta($user->ID, WCSS_REST_Stores::META_INACTIVE);
  $wpdb->update($wpdb->users, ['user_activation_key' => $key_before, 'user_pass' => $hash], ['ID' => $user->ID]);
  if ($tokens) update_user_meta($user->ID, 'session_tokens', $tokens);
  clean_user_cache($user->ID);
  $wpdb->query($wpdb->prepare("DELETE FROM $logs WHERE id > %d AND subject IN ('Set your Canna Cabana supply password', 'WC reset')", $log_from));
  echo "RESULT: $pass passed, $fail failed\n";
}
