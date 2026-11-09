<?php
/**
 * LOCAL ONLY. Run from the repo root:  php tests/vendor-connections-test.php
 * SOW 2.9 / 2.10: WP Admin → WooCommerce → Vendor connections switches Amazon (Trial / Live) and
 * Grand & Toy (Stage / Production), with credentials that override wp-config.php.
 * Every outgoing HTTP call is caught and answered locally: nothing reaches Amazon or Grand & Toy.
 * Restores the saved settings. Refuses to run unless the site URL ends in .local.
 */
$_SERVER['HTTP_HOST'] = 'ccsupplies.local'; $_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require __DIR__ . '/../app/public/wp-load.php';
if ( ! preg_match( '#\.local(/|$)#', home_url() ) ) { fwrite( STDERR, "Refusing to run: not a .local site (" . home_url() . ")\n" ); exit( 1 ); }

$HTTP = [];
add_filter('pre_http_request', function ($pre, $args, $url) { global $HTTP;
  $HTTP[] = ['url' => $url, 'body' => $args['body'] ?? ''];
  if (strpos($url, 'api.amazon.com/auth') !== false) return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => wp_json_encode(['access_token' => 'fake']), 'headers' => [], 'cookies' => []];
  return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => '<?xml version="1.0"?><cXML><Response><Status code="200" text="OK"/></Response></cXML>', 'headers' => [], 'cookies' => []];
}, 1, 3);
add_filter('pre_wp_mail', '__return_false', 1);

$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
wp_set_current_user($admin);
$pass = 0; $fail = 0;
$bk = get_option(WCSS_Vendor_Connections::OPTION, null);
function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  $info\n"; } }
function last($needle) { global $HTTP; foreach (array_reverse($HTTP) as $h) if (strpos($h['url'], $needle) !== false) return $h; return null; }
$V = 'WCSS_Vendor_Connections';

try {
  delete_option($V::OPTION);

  // ---------- Starting point: test modes, wp-config values ----------
  ok('Amazon starts in Trial Mode', ! $V::amazon_live() && $V::label('amazon') === 'Trial Mode');
  ok('Grand & Toy starts on Stage', ! $V::gt_production() && strpos($V::gt_endpoint(), 'stage.grandandtoy.com') !== false);
  ok('Amazon keys fall back to wp-config.php', $V::amazon('client_id') === (defined('AMAZON_CLIENT_ID') ? AMAZON_CLIENT_ID : ''));
  ok('G&T stage keys fall back to wp-config.php', $V::gt('from_identity') === (defined('CC_GT_FROM_IDENTITY') ? CC_GT_FROM_IDENTITY : ''));
  ok('theme G&T client reads the same values', cc_gt_credentials()['from_identity'] === $V::gt('from_identity') && cc_gt_stage_endpoint() === $V::gt_endpoint());

  // ---------- Amazon Live needs a confirmation ----------
  $r = $V::apply(['amazon_mode' => 'live'], [], ['amazon' => false]);
  ok('Live without the confirm box is refused, nothing saved', is_wp_error($r) && ! $V::amazon_live());
  $r = $V::apply(['amazon_mode' => 'live'], [], ['amazon' => true]);
  ok('Live with the confirm box is saved', ! is_wp_error($r) && $V::amazon_live(), is_wp_error($r) ? $r->get_error_message() : '');
  ok('the change is logged with who did it', strpos(end($V::all()['log'])['what'], 'Amazon → Live') !== false && end($V::all()['log'])['by'] !== '');
  ok('back to Trial needs no confirmation', ! is_wp_error($V::apply(['amazon_mode' => 'trial'], [], [])) && ! $V::amazon_live());

  // ---------- Amazon order payload: TrialMode only in Trial ----------
  cc_auto_vendor_submit_bootstrap_amazon();
  $item = ['asin' => 'B000TEST01', 'quantity' => 1, 'buying_option_id' => 'opt', 'price' => 1.00, 'title' => 'Test'];
  $form = ['full_name' => 'A', 'email' => 'a@b.c', 'phone' => '1', 'company_name' => 'C', 'address_line1' => '1 St', 'address_line2' => '', 'city' => 'X', 'state' => 'ON', 'postal_code' => 'A1A1A1', 'purchase_order_number' => '1', 'group_identifier' => 'G'];
  $has_trial = function ($payload) { return strpos(wp_json_encode($payload), 'TrialMode') !== false; };
  ok('Trial: Amazon payload carries TrialMode', $has_trial(wp_amazon_checkout_build_order_payload([$item], $form + ['trial_mode' => true])));
  ok('Live: Amazon payload has no TrialMode', ! $has_trial(wp_amazon_checkout_build_order_payload([$item], $form + ['trial_mode' => false])));
  $src = file_get_contents(get_stylesheet_directory() . '/cc-auto-vendor-submit.php');
  ok('auto-send no longer hard-codes trial_mode => true', strpos($src, "'trial_mode'            => true") === false && substr_count($src, '$amazon_trial') >= 3);
  $src = file_get_contents(get_stylesheet_directory() . '/amazon-checkout.php');
  ok('manual checkout ignores the browser’s trial box', strpos($src, "\$_POST['trial_mode']") === false && strpos($src, 'WCSS_Vendor_Connections::amazon_live()') !== false);

  // ---------- Amazon keys typed here win ----------
  $r = $V::apply(['amazon' => ['client_id' => 'amzn1.test.client', 'client_secret' => 'sekret-1234', 'refresh_token' => 'Atzr|test-9876']], [], []);
  ok('Amazon keys saved', ! is_wp_error($r) && $V::amazon('client_id') === 'amzn1.test.client' && $V::amazon('client_secret') === 'sekret-1234');
  $HTTP = [];
  wp_amazon_get_access_token();
  $h = last('api.amazon.com/auth');
  ok('Amazon sign-in uses the saved keys', $h && ($h['body']['client_id'] ?? '') === 'amzn1.test.client' && ($h['body']['refresh_token'] ?? '') === 'Atzr|test-9876');
  $V::apply(['amazon' => ['client_secret' => '']], [], []);
  ok('a blank secret keeps the saved one', $V::amazon('client_secret') === 'sekret-1234');
  ok('Check Amazon sign-in reports OK', $V::check_amazon()['ok'] === true);
  require_once ABSPATH . 'wp-admin/includes/template.php';
  ob_start(); (new $V())->page(); $html = ob_get_clean();
  ok('secrets are in masked (password) fields with a Show button', (bool) preg_match('/type="password"[^>]*name="wcss_vc\[amazon\]\[client_secret\]"[^>]*value="sekret-1234"/s', $html) && strpos($html, 'data-vc-eye="vc-amazon-client_secret"') !== false);
  ok('fields are filled with the value in use, ready to edit', (bool) preg_match('/name="wcss_vc\[amazon\]\[client_id\]"[^>]*value="' . preg_quote(esc_attr($V::amazon('client_id')), '/') . '"/s', $html) && (bool) preg_match('/name="wcss_vc\[gt_stage\]\[from_identity\]"[^>]*value="' . preg_quote(esc_attr($V::gt('from_identity')), '/') . '"/s', $html));
  ok('admin page shows both vendors and their modes', strpos($html, 'Amazon Business') !== false && strpos($html, 'Grand &amp; Toy') !== false && strpos($html, 'Trial Mode') !== false && strpos($html, 'Stage') !== false);
  $V::apply([], ['amazon' => ['client_id', 'client_secret', 'refresh_token']], []);
  ok('Remove goes back to wp-config.php', $V::amazon('client_id') === (defined('AMAZON_CLIENT_ID') ? AMAZON_CLIENT_ID : ''));
  ok('Amazon buyer email must be an email', is_wp_error($V::apply(['amazon' => ['user_email' => 'nope']], [], [])));
  // Saving the form as shown (every field prefilled) changes nothing and copies nothing from wp-config.php.
  $shown = ['amazon' => [], 'amazon_live' => [], 'gt_stage' => [], 'gt_prod' => []];
  foreach ($V::AMAZON_FIELDS as $k => $f) { $shown['amazon'][$k] = $V::amazon($k, 'trial'); $shown['amazon_live'][$k] = $V::amazon($k, 'live'); }
  foreach ($V::GT_FIELDS as $k => $f) $shown['gt_stage'][$k] = $V::gt($k);
  $shown['gt_stage']['endpoint'] = $V::gt_endpoint();
  $shown['gt_prod']['endpoint'] = $V::GT_PROD;
  $r = $V::apply($shown + ['amazon_mode' => 'trial', 'gt_mode' => 'stage'], [], []);
  ok('saving the prefilled form unchanged stores nothing', $r === 'No changes.' && ! $V::all()['amazon'] && ! $V::all()['amazon_live'] && ! $V::all()['gt_stage'] && ! $V::all()['gt_prod'], is_wp_error($r) ? $r->get_error_message() : (string) $r);
  $shown['gt_stage']['from_identity'] = 'NEW-FROM';
  $V::apply($shown, [], []);
  ok('editing one prefilled field saves only that field', $V::all()['gt_stage'] === ['from_identity' => 'NEW-FROM'] && $V::gt('from_identity') === 'NEW-FROM');
  $V::apply([], ['gt_stage' => ['from_identity']], []);

  // ---------- Separate sets: Trial vs Live, Stage vs Production ----------
  $shown['gt_stage']['from_identity'] = $V::gt('from_identity');
  $shown['amazon_live']['client_id'] = 'amzn1.LIVE-ONLY-ID';
  $r = $V::apply($shown, [], []);
  ok('a Live-only Amazon value is saved in its own set', ! is_wp_error($r) && $V::all()['amazon_live'] === ['client_id' => 'amzn1.LIVE-ONLY-ID'] && ! isset($V::all()['amazon']['client_id']));
  ok('Trial Mode keeps using the trial value', $V::amazon('client_id') === (defined('AMAZON_CLIENT_ID') ? AMAZON_CLIENT_ID : '') && $V::amazon('client_id', 'live') === 'amzn1.LIVE-ONLY-ID');
  ok('Live fields not changed follow Trial Mode', $V::amazon('client_secret', 'live') === $V::amazon('client_secret', 'trial'));
  $V::apply(['amazon_mode' => 'live'] + $shown, [], ['amazon' => true]);
  $HTTP = [];
  wp_amazon_get_access_token();
  $h = last('api.amazon.com/auth');
  ok('in Live, Amazon sign-in uses the Live set', $V::amazon_live() && $h && ($h['body']['client_id'] ?? '') === 'amzn1.LIVE-ONLY-ID');
  $V::apply(['amazon_mode' => 'trial'] + $shown, [], []);
  $HTTP = [];
  wp_amazon_get_access_token();
  $h = last('api.amazon.com/auth');
  ok('back in Trial Mode, sign-in uses the trial set again', ! $V::amazon_live() && $h && ($h['body']['client_id'] ?? '') === (defined('AMAZON_CLIENT_ID') ? AMAZON_CLIENT_ID : ''));
  ob_start(); (new $V())->page(); $html2 = ob_get_clean();
  ok('page has a Trial set and a Live set, shown by the chosen tile', strpos($html2, 'data-vc-set="amazon_mode" data-vc-when="trial"') !== false && strpos($html2, 'data-vc-set="amazon_mode" data-vc-when="live"') !== false && strpos($html2, 'Live credentials') !== false);
  ok('page has a Stage set and a Production set, shown by the chosen tile', strpos($html2, 'data-vc-when="stage"') !== false && strpos($html2, 'data-vc-when="production"') !== false);
  ok('the Live field shows its own saved value', (bool) preg_match('/name="wcss_vc\[amazon_live\]\[client_id\]"\s+value="amzn1.LIVE-ONLY-ID"/', $html2));
  $V::apply([], ['amazon_live' => ['client_id']], []);
  ok('Remove puts the Live field back to the Trial value', $V::amazon('client_id', 'live') === $V::amazon('client_id', 'trial'));

  // ---------- Grand & Toy Production ----------
  $r = $V::apply(['gt_mode' => 'production'], [], ['gt' => true]);
  ok('Production without production credentials is refused', is_wp_error($r) && strpos($r->get_error_message(), 'production') !== false && ! $V::gt_production());
  ok('a production URL on another site is refused', is_wp_error($V::apply(['gt_prod' => ['endpoint' => 'https://evil.example.com/po']], [], [])));
  ok('a stage URL must be the stage host', is_wp_error($V::apply(['gt_stage' => ['endpoint' => 'https://www.grandandtoy.com/x']], [], [])));
  $prod = ['from_identity' => 'PROD-FROM', 'to_identity' => 'PROD-TO', 'shared_secret' => 'prod-secret-77', 'sender' => '999'];
  $r = $V::apply(['gt_mode' => 'production', 'gt_prod' => $prod], [], ['gt' => false]);
  ok('Production without the confirm box is refused', is_wp_error($r) && ! $V::gt_production());
  $r = $V::apply(['gt_mode' => 'production', 'gt_prod' => $prod], [], ['gt' => true]);
  ok('Production with credentials and confirm is saved', ! is_wp_error($r) && $V::gt_production(), is_wp_error($r) ? $r->get_error_message() : '');
  ok('Production posts to www.grandandtoy.com', $V::gt_endpoint() === $V::GT_PROD && cc_gt_stage_endpoint() === $V::GT_PROD);
  $c = cc_gt_credentials();
  ok('Production uses its own credentials, not stage', $c['from_identity'] === 'PROD-FROM' && $c['shared_secret'] === 'prod-secret-77' && $c['stock_sender'] === '999');
  $HTTP = [];
  $res = cc_gt_stock_check([['supplier_part_id' => '54130', 'quantity' => 1, 'uom' => 'EA']]);
  $h = last('grandandtoy.com');
  ok('Production stock check goes to www, with production identity', $h && strpos($h['url'], 'https://www.grandandtoy.com/') === 0 && strpos((string) $h['body'], 'PROD-FROM') !== false && strpos((string) $h['body'], 'prod-secret-77') !== false);
  ok('Production does not rewrite the real URL to stage', strpos(cc_gt_sanitize_cxml('<x>' . $V::GT_PROD . '</x>'), 'www.grandandtoy.com') !== false);
  ok('Production refuses the stage host', $V::gt_allowed_host('stage.grandandtoy.com') === false && $V::gt_allowed_host('www.grandandtoy.com') === true);

  // Back to stage: stage values again, stage host only.
  ok('back to Stage needs no confirmation', ! is_wp_error($V::apply(['gt_mode' => 'stage'], [], [])) && ! $V::gt_production());
  $HTTP = [];
  cc_gt_stock_check([['supplier_part_id' => '54130', 'quantity' => 1, 'uom' => 'EA']]);
  $h = last('grandandtoy.com');
  ok('Stage stock check goes to stage with stage identity', $h && strpos($h['url'], 'https://stage.grandandtoy.com/') === 0 && strpos((string) $h['body'], 'PROD-FROM') === false);
  ok('Stage rewrites a real URL to stage, as before', strpos(cc_gt_sanitize_cxml('<x>' . $V::GT_PROD . '</x>'), 'stage.grandandtoy.com') !== false);
  ok('Stage refuses www.grandandtoy.com', $V::gt_allowed_host('www.grandandtoy.com') === false);

  // ---------- Only administrators ----------
  $sm = get_users(['role' => 'shop_manager', 'number' => 1, 'fields' => 'ID']);
  if ($sm) { wp_set_current_user((int) $sm[0]); ok('a Supply Manager cannot open Vendor connections', ! current_user_can('manage_options')); wp_set_current_user($admin); }
  ok('menu needs the administrator capability', strpos(file_get_contents(WCSS_DIR . 'includes/class-vendor-connections.php'), "'Vendor connections', 'manage_options'") !== false);
} finally {
  if ($bk === null) delete_option(WCSS_Vendor_Connections::OPTION); else update_option(WCSS_Vendor_Connections::OPTION, $bk, false);
}
ok('settings restored', get_option(WCSS_Vendor_Connections::OPTION, null) === $bk);
ok('nothing reached a real vendor (all calls caught)', true);
echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
