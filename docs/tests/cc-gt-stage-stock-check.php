<?php
/**
 * Safe Grand & Toy STAGE stock-check only (no Purchase Order).
 *
 * Prefers full WordPress bootstrap when Local MySQL is up.
 * Falls back to a minimal bootstrap (wp-config constants + curl HTTP) so
 * connectivity can be verified when the site DB socket is offline.
 *
 * Run:
 *   php docs/tests/cc-gt-stage-stock-check.php
 *
 * Never prints SharedSecret or full credential values.
 * Does not call cc_gt_submit_order().
 */

$root     = dirname( __DIR__, 2 );
$wp_load  = $root . '/app/public/wp-load.php';
$wp_config = $root . '/app/public/wp-config.php';
$theme_dir = $root . '/app/public/wp-content/themes/adex360-canna-cabana-stationery-child';

$boot_mode = 'none';

// Probe Local MySQL socket from wp-config before requiring wp-load (wp_die exits the process).
$db_host = '';
if ( file_exists( $wp_config ) ) {
	$cfg_probe = file_get_contents( $wp_config );
	if ( preg_match( "/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $cfg_probe, $hm ) ) {
		$db_host = $hm[1];
	}
}
$db_ready = false;
if ( $db_host !== '' && strpos( $db_host, ':/' ) !== false ) {
	$parts = explode( ':', $db_host, 2 );
	$sock  = $parts[1] ?? '';
	$db_ready = ( $sock !== '' && file_exists( $sock ) );
} elseif ( $db_host !== '' ) {
	$db_ready = true; // TCP host — try WP load.
}

if ( $db_ready && file_exists( $wp_load ) ) {
	require $wp_load;
	if ( function_exists( 'cc_gt_stock_check' ) ) {
		$boot_mode = 'wordpress';
	}
}

if ( $boot_mode !== 'wordpress' ) {
	echo "(Local WP DB unavailable — using minimal bootstrap for stage HTTP check)\n";

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $root . '/app/public/' );
	}

	// Load only CC_GT_* defines from wp-config without executing the full file.
	if ( ! file_exists( $wp_config ) ) {
		fwrite( STDERR, "wp-config.php not found\n" );
		exit( 2 );
	}
	$cfg = file_get_contents( $wp_config );
	foreach ( array( 'CC_GT_STAGE_ENDPOINT', 'CC_GT_FROM_IDENTITY', 'CC_GT_TO_IDENTITY', 'CC_GT_SHARED_SECRET', 'CC_GT_STOCK_SENDER' ) as $const ) {
		if ( defined( $const ) ) {
			continue;
		}
		if ( preg_match( "/define\s*\(\s*['\"]" . preg_quote( $const, '/' ) . "['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/", $cfg, $m ) ) {
			define( $const, $m[1] );
		}
	}

	class CC_GT_WP_Error {
		private $message;
		public function __construct( $message ) {
			$this->message = (string) $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ) {
			return $thing instanceof CC_GT_WP_Error;
		}
	}
	if ( ! function_exists( 'wp_remote_post' ) ) {
		function wp_remote_post( $url, $args = array() ) {
			$ch = curl_init( $url );
			if ( $ch === false ) {
				return new CC_GT_WP_Error( 'curl_init failed' );
			}
			$body = isset( $args['body'] ) ? (string) $args['body'] : '';
			$timeout = isset( $args['timeout'] ) ? (int) $args['timeout'] : 60;
			$headers = array();
			foreach ( (array) ( $args['headers'] ?? array() ) as $k => $v ) {
				$headers[] = $k . ': ' . $v;
			}
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => $body,
					CURLOPT_HTTPHEADER     => $headers,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => $timeout,
					CURLOPT_FOLLOWLOCATION => true,
				)
			);
			$response_body = curl_exec( $ch );
			if ( $response_body === false ) {
				$err = curl_error( $ch );
				curl_close( $ch );
				return new CC_GT_WP_Error( $err !== '' ? $err : 'curl_exec failed' );
			}
			$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );
			return array(
				'response' => array( 'code' => $code ),
				'body'     => $response_body,
			);
		}
	}
	if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
		function wp_remote_retrieve_response_code( $response ) {
			return (int) ( $response['response']['code'] ?? 0 );
		}
	}
	if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
		function wp_remote_retrieve_body( $response ) {
			return (string) ( $response['body'] ?? '' );
		}
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action() {}
	}
	if ( ! function_exists( 'is_user_logged_in' ) ) {
		function is_user_logged_in() { return false; }
	}
	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can() { return false; }
	}
	if ( ! function_exists( 'wp_die' ) ) {
		function wp_die( $message = '' ) {
			fwrite( STDERR, (string) $message . "\n" );
			exit( 1 );
		}
	}

	require $theme_dir . '/grand-and-toy-cxml.php';
	require $theme_dir . '/cc-vendor-compare.php';
	$boot_mode = 'minimal';
}

if ( ! function_exists( 'cc_gt_stock_check' ) || ! function_exists( 'cc_gt_official_stock_sample' ) ) {
	fwrite( STDERR, "Grand & Toy cXML helpers not loaded.\n" );
	exit( 2 );
}

$creds = cc_gt_credentials();
$endpoint = cc_gt_stage_endpoint();
$host = parse_url( $endpoint, PHP_URL_HOST );

echo "=== Grand & Toy stage stock check (read-only) ===\n";
echo 'Bootstrap: ' . $boot_mode . "\n";
echo 'Endpoint host: ' . ( $host ? $host : '(invalid)' ) . "\n";
echo 'Endpoint is stage: ' . ( $host === 'stage.grandandtoy.com' ? 'yes' : 'NO — aborting' ) . "\n";

if ( $host !== 'stage.grandandtoy.com' ) {
	fwrite( STDERR, "Refusing to run: endpoint is not stage.grandandtoy.com\n" );
	exit( 3 );
}

if ( ! empty( $creds['errors'] ) ) {
	echo 'Credentials: MISSING (' . count( $creds['errors'] ) . " constant(s))\n";
	foreach ( $creds['errors'] as $err ) {
		echo '  - ' . $err . "\n";
	}
	echo "BLOCKER: add CC_GT_* stage constants in local wp-config.php (Basit / ops).\n";
	exit( 4 );
}

echo "Credentials: present (values not printed)\n";
echo 'From Identity length: ' . strlen( (string) $creds['from_identity'] ) . "\n";
echo 'To Identity length: ' . strlen( (string) $creds['to_identity'] ) . "\n";
echo 'Stock sender: ' . (string) $creds['stock_sender'] . "\n";

$sample = cc_gt_official_stock_sample();
$part = (string) ( $sample['items'][0]['supplier_part_id'] ?? '33111' );
echo "Requesting stock for approved sample part: $part (quantity 1)\n";
echo "PO submit: NOT called\n\n";

$result = cc_gt_stock_check( $sample['items'], $sample['facility_ids'] );

$http = (int) ( $result['http_code'] ?? 0 );
$ok   = ! empty( $result['ok'] );
$errors = array_filter( array_map( 'strval', (array) ( $result['errors'] ?? array() ) ) );

echo 'HTTP code: ' . $http . "\n";
echo 'ok: ' . ( $ok ? 'true' : 'false' ) . "\n";

$body = (string) ( $result['response_xml'] ?? $result['response_body'] ?? '' );
$looks_incapsula = ( stripos( $body, 'incapsula' ) !== false || stripos( $body, 'Request unsuccessful' ) !== false );
if ( $http === 403 || $looks_incapsula ) {
	$incident = '';
	if ( preg_match( '/Incapsula incident ID:\s*([0-9-]+)/i', $body, $matches ) ) {
		$incident = ' Incident ID: ' . $matches[1] . '.';
	}
	echo 'BLOCKER: HTTP 403 / Incapsula (IP allowlist or bot protection).' . $incident . " Ops must allowlist this machine's egress IP with Grand & Toy stage.\n";
}

if ( $errors ) {
	echo "Errors:\n";
	foreach ( $errors as $err ) {
		$safe = preg_replace( '/[A-Za-z0-9+\/=]{40,}/', '[redacted]', $err );
		echo '  - ' . $safe . "\n";
	}
}

if ( function_exists( 'cc_vendor_result_from_gt_stock' ) ) {
	$normalized = cc_vendor_result_from_gt_stock( $result, $part );
	echo "\nNormalized vendor row:\n";
	echo '  vendor: ' . $normalized['vendor'] . "\n";
	echo '  status: ' . $normalized['status'] . "\n";
	echo '  product_identifier: ' . $normalized['product_identifier'] . "\n";
	echo '  price: ' . ( $normalized['price'] === null ? 'null' : $normalized['price'] ) . "\n";
	echo '  currency: ' . ( $normalized['currency'] !== '' ? $normalized['currency'] : '(empty)' ) . "\n";
	echo '  availability_quantity: ' . ( $normalized['availability_quantity'] === null ? 'null' : $normalized['availability_quantity'] ) . "\n";
	echo '  error: ' . ( $normalized['error'] !== '' ? preg_replace( '/[A-Za-z0-9+\/=]{40,}/', '[redacted]', $normalized['error'] ) : '(none)' ) . "\n";
}

echo "\nDone. No purchase order was submitted.\n";
exit( $ok ? 0 : 5 );
