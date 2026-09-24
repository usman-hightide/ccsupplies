<?php 
/**  
 * Template Name: Amazon Checkout  
*/  


defined( 'ABSPATH' ) || exit;

/*                                                                       
--------------------------------------------------------------------------
Amazon Configuration                                                      
--------------------------------------------------------------------------
*/                                                                       

if ( ! defined( 'AMAZON_CART_ID' ) ) {
	define( 'AMAZON_CART_ID', 'cart-wxXmutF5kiXGRb9J' );
}

if ( ! defined( 'AMAZON_REGION' ) ) {
	define( 'AMAZON_REGION', 'CA' );
}

if ( ! defined( 'AMAZON_USER_EMAIL' ) ) {
	define( 'AMAZON_USER_EMAIL', 'ecom@hightideinc.com' );
}

if ( ! defined( 'AMAZON_GROUP_ID' ) ) {
	define( 'AMAZON_GROUP_ID', 'OrderingAPI5259590399' );
}

/*                                                                       
--------------------------------------------------------------------------
Amazon Access Token                                                       
--------------------------------------------------------------------------
*/                                                                       

if ( ! function_exists( 'wp_amazon_get_access_token' ) ) {

function wp_amazon_get_access_token() {

	$response = wp_remote_post(
		'https://api.amazon.com/auth/o2/token',
		array(
			'timeout' => 30,

			'headers' => array(
				'Content-Type' =>
					'application/x-www-form-urlencoded;charset=UTF-8',
			),

			'body' => array(
				'grant_type' =>
					'refresh_token',

				'refresh_token' =>
					AMAZON_REFRESH_TOKEN,

				'client_id' =>
					AMAZON_CLIENT_ID,

				'client_secret' =>
					AMAZON_CLIENT_SECRET,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status_code = wp_remote_retrieve_response_code(
		$response
	);

	$body = json_decode(
		wp_remote_retrieve_body( $response ),
		true
	);

	if (
		$status_code !== 200
		|| empty( $body['access_token'] )
	) {

		return new WP_Error(
			'amazon_auth_error',
			'Unable to get Amazon access token.',
			array(
				'status'   => $status_code,
				'response' => $body,
			)
		);
	}

	return $body['access_token'];
}

}

/*                                                                       
--------------------------------------------------------------------------
Amazon API Request Helper                                                 
--------------------------------------------------------------------------
*/                                                                       

function wp_amazon_ordering_request(

$method,

$url,

$body = null

) {

$access_token = wp_amazon_get_access_token();

if ( is_wp_error( $access_token ) ) {

	return array(
		'success' => false,
		'message' => $access_token->get_error_message(),
	);
}

if ( empty( $access_token ) ) {

	return array(
		'success' => false,
		'message' =>
			'Amazon access token is missing.',
	);
}


$args = array(
	'timeout'    => 60,
	'httpversion' => '1.1',

	'headers' => array(
		'x-amz-access-token' =>
			$access_token,

		'x-amz-user-email' =>
			AMAZON_USER_EMAIL,

		'Accept' =>
			'application/json',

		'Content-Type' =>
			'application/json',
	),
);


if ( null !== $body ) {

	$args['body'] =
		wp_json_encode( $body );
}


$response = wp_remote_request(
	$url,
	array_merge(
		$args,
		array(
			'method' =>
				strtoupper( $method ),
		)
	)
);


if ( is_wp_error( $response ) ) {

	return array(
		'success' => false,
		'message' =>
			$response->get_error_message(),
	);
}


$status_code =
	wp_remote_retrieve_response_code(
		$response
	);

$response_body =
	wp_remote_retrieve_body(
		$response
	);

$response_headers =
	wp_remote_retrieve_headers(
		$response
	);

$data = json_decode(
	$response_body,
	true
);


$request_id = '';


if (
	isset(
		$response_headers['x-amzn-requestid']
	)
) {

	$request_id =
		$response_headers['x-amzn-requestid'];

} elseif (
	isset(
		$response_headers['x-amzn-request-id']
	)
) {

	$request_id =
		$response_headers['x-amzn-request-id'];
}


return array(
	'success' =>
		$status_code >= 200
		&& $status_code < 300,

	'status_code' =>
		$status_code,

	'data' =>
		$data,

	'raw_body' =>
		$response_body,

	'request_id' =>
		$request_id,

	'headers' =>
		$response_headers,
);

}

/*                                                                       
--------------------------------------------------------------------------
Get Amazon Cart Items                                                     
--------------------------------------------------------------------------
*/                                                                       

function wp_amazon_checkout_get_cart_items() {

$url = sprintf(
	'https://na.business-api.amazon.com/cart/2025-04-30/carts/%s/items?region=%s',
	rawurlencode( AMAZON_CART_ID ),
	rawurlencode( AMAZON_REGION )
);


$result = wp_amazon_ordering_request(
	'GET',
	$url
);


if ( ! $result['success'] ) {

	return $result;
}


$items = array();


if (
	isset( $result['data']['items'] )
	&& is_array(
		$result['data']['items']
	)
) {

	$items =
		$result['data']['items'];
}


$result['items'] =
	$items;


return $result;

}

/*                                                                       
--------------------------------------------------------------------------
Generate Unique Order External ID                                         
--------------------------------------------------------------------------
*/                                                                       

function wp_amazon_checkout_generate_external_id() {

return 'DirectOrderTest-'
	. gmdate( 'YmdHis' )
	. '-'
	. wp_rand( 1000, 9999 );

}

/*                                                                       
--------------------------------------------------------------------------
Build Ordering API Payload                                                
--------------------------------------------------------------------------
*/                                                                       

function wp_amazon_checkout_build_order_payload(

$cart_items,

$form_data

) {

$line_items = array();


foreach (
	$cart_items as $index => $cart_item
) {


	/*
	 * ------------------------------------------------------
	 * Product / ASIN
	 * ------------------------------------------------------
	 */

	$asin = '';


	if (
		isset(
			$cart_item['productIdentifier']
		)
		&& ! empty(
			$cart_item['productIdentifier']
		)
	) {

		$asin =
			$cart_item['productIdentifier'];

	} elseif (
		isset(
			$cart_item['asin']
		)
		&& ! empty(
			$cart_item['asin']
		)
	) {

		$asin =
			$cart_item['asin'];
	}


	if ( empty( $asin ) ) {
		continue;
	}


	/*
	 * ------------------------------------------------------
	 * Quantity
	 * ------------------------------------------------------
	 */

	$quantity = 1;


	if (
		isset(
			$cart_item['quantity']
		)
	) {

		$quantity =
			max(
				1,
				(int) $cart_item['quantity']
			);
	}


	/*
	 * ------------------------------------------------------
	 * Buying Option
	 * ------------------------------------------------------
	 */

	$buying_option_id = '';


	if (
		isset(
			$cart_item['buyingOptionIdentifier']
		)
		&& ! empty(
			$cart_item['buyingOptionIdentifier']
		)
	) {

		$buying_option_id =
			$cart_item['buyingOptionIdentifier'];
	}


	/*
	 * ------------------------------------------------------
	 * Line Item External ID
	 * ------------------------------------------------------
	 */

	$line_external_id =
		(string) ( $index + 1 );


	/*
	 * ------------------------------------------------------
	 * Line Attributes
	 * ------------------------------------------------------
	 */

	$line_attributes = array(

		array(
			'attributeType' =>
				'SelectedProductReference',

			'productReference' => array(

				'id' =>
					$asin,

				'productReferenceType' =>
					'ProductIdentifier',
			),
		),

	);


	if ( ! empty( $buying_option_id ) ) {

		$line_attributes[] = array(

			'attributeType' =>
				'SelectedBuyingOptionReference',

			'buyingOptionReference' => array(

				'id' =>
					$buying_option_id,

				'buyingOptionReferenceType' =>
					'BuyingOptionIdentifier',
			),
		);
	}


	/*
	 * ------------------------------------------------------
	 * Line Expectations
	 * ------------------------------------------------------
	 */

	$line_expectations = array();


	$price_amount = '';
	$currency     = '';


	if (
		isset(
			$cart_item['price']['amount']
		)
	) {

		$price_amount =
			$cart_item['price']['amount'];
	}


	if (
		isset(
			$cart_item['price']['currencyCode']
		)
	) {

		$currency =
			$cart_item['price']['currencyCode'];
	}


	if (
		$price_amount !== ''
		&& $currency !== ''
	) {

		$line_expectations[] = array(

			'expectationType' =>
				'ExpectedUnitPrice',

			'amount' => array(

				'currencyCode' =>
					$currency,

				'amount' =>
					(float) $price_amount,
			),
		);
	}


	/*
	 * ------------------------------------------------------
	 * Build Line Item
	 * ------------------------------------------------------
	 */

	$line_items[] = array(

		'externalId' =>
			$line_external_id,

		'quantity' =>
			$quantity,

		'attributes' =>
			$line_attributes,

		'expectations' =>
			$line_expectations,
	);
}


/*
--------------------------------------------------------------------------
 Order Attributes
--------------------------------------------------------------------------
*/

$order_attributes = array();


/*
 * Purchase Order Number
 */

$purchase_order_number =
	sanitize_text_field(
		$form_data['purchase_order_number']
	);


if ( empty( $purchase_order_number ) ) {

	$purchase_order_number =
		'CC-' . gmdate( 'YmdHis' );
}


$order_attributes[] = array(

	'attributeType' =>
		'PurchaseOrderNumber',

	'purchaseOrderNumber' =>
		$purchase_order_number,
);


/*
 * Buyer Reference
 */

$order_attributes[] = array(

	'attributeType' =>
		'BuyerReference',

	'userReference' => array(

		'userReferenceType' =>
			'UserEmail',

		'emailAddress' =>
			sanitize_email(
				$form_data['email']
			),
	),
);


/*
 * Buying Group
 */

$order_attributes[] = array(

	'attributeType' =>
		'BuyingGroupReference',

	'groupReference' => array(

		'groupReferenceType' =>
			'GroupIdentity',

		'identifier' =>
			sanitize_text_field(
				$form_data['group_identifier']
			),
	),
);


/*
 * Region
 */

$order_attributes[] = array(

	'attributeType' =>
		'Region',

	'region' =>
		AMAZON_REGION,
);


/*
 * Payment Method
 */

$order_attributes[] = array(

	'attributeType' =>
		'SelectedPaymentMethodReference',

	'paymentMethodReference' => array(

		'paymentMethodReferenceType' =>
			'StoredPaymentMethod',
	),
);


/*
 * Shipping Address
 */

$order_attributes[] = array(

	'attributeType' =>
		'ShippingAddress',

	'address' => array(

		'addressType' =>
			'PhysicalAddress',

		'fullName' =>
			sanitize_text_field(
				$form_data['full_name']
			),

		'phoneNumber' =>
			sanitize_text_field(
				$form_data['phone']
			),

		'companyName' =>
			sanitize_text_field(
				$form_data['company_name']
			),

		'addressLine1' =>
			sanitize_text_field(
				$form_data['address_line1']
			),

		'addressLine2' =>
			sanitize_text_field(
				$form_data['address_line2']
			),

		'city' =>
			sanitize_text_field(
				$form_data['city']
			),

		'stateOrRegion' =>
			sanitize_text_field(
				$form_data['state']
			),

		'postalCode' =>
			sanitize_text_field(
				$form_data['postal_code']
			),

		'countryCode' =>
			AMAZON_REGION,
	),
);


/*
 * Trial Mode
 */

if ( ! empty( $form_data['trial_mode'] ) ) {

	$order_attributes[] = array(

		'attributeType' =>
			'TrialMode',
	);
}


/*
--------------------------------------------------------------------------
 Final Payload
--------------------------------------------------------------------------
*/

return array(

	'externalId' =>
		wp_amazon_checkout_generate_external_id(),

	'lineItems' =>
		$line_items,

	'attributes' =>
		$order_attributes,

	'expectations' =>
		array(),
);

}

/*                                                                       
--------------------------------------------------------------------------
Handle Checkout POST                                                      
--------------------------------------------------------------------------
*/                                                                       

$order_result  = null;
$wc_order_id   = function_exists( 'wcss_amazon_get_request_order_id' ) ? wcss_amazon_get_request_order_id() : 0;

$order_payload = null;

if (

isset( $_POST['amazon_checkout_action'] )

&& 'place_order' ===

sanitize_key(

wp_unslash(

$_POST['amazon_checkout_action']

)

)

) {

/*
 * Verify Nonce
 */

if (
	! isset(
		$_POST['amazon_checkout_nonce']
	)
	|| ! wp_verify_nonce(
		sanitize_text_field(
			wp_unslash(
				$_POST['amazon_checkout_nonce']
			)
		),
		'amazon_place_order'
	)
) {

	$order_result = array(

		'success' => false,

		'message' =>
			'Security check failed. Please refresh the page and try again.',
	);

} else {


	/*
	 * Collect Form Data
	 */

	$form_data = array(

		'full_name' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['full_name'] ?? ''
				)
			),

		'email' =>
			sanitize_email(
				wp_unslash(
					$_POST['email'] ?? ''
				)
			),

		'phone' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['phone'] ?? ''
				)
			),

		'company_name' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['company_name'] ?? ''
				)
			),

		'address_line1' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['address_line1'] ?? ''
				)
			),

		'address_line2' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['address_line2'] ?? ''
				)
			),

		'city' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['city'] ?? ''
				)
			),

		'state' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['state'] ?? ''
				)
			),

		'postal_code' =>
			strtoupper(
				sanitize_text_field(
					wp_unslash(
						$_POST['postal_code'] ?? ''
					)
				)
			),

		'purchase_order_number' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['purchase_order_number'] ?? ''
				)
			),

		'group_identifier' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['group_identifier']
					?? AMAZON_GROUP_ID
				)
			),

		'trial_mode' =>
			! empty(
				$_POST['trial_mode']
			),
	);


	/*
	 * Required Fields
	 */

	$required_fields = array(

		'full_name' =>
			'Full name',

		'email' =>
			'Email',

		'phone' =>
			'Phone',

		'address_line1' =>
			'Address',

		'city' =>
			'City',

		'state' =>
			'Province',

		'postal_code' =>
			'Postal code',

		'group_identifier' =>
			'Amazon group identifier',
	);


	$validation_errors = array();


	foreach (
		$required_fields as $field => $label
	) {

		if (
			empty(
				$form_data[ $field ]
			)
		) {

			$validation_errors[] =
				$label . ' is required.';
		}
	}


	/*
	 * Email Validation
	 */

	if (
		! empty( $form_data['email'] )
		&& ! is_email(
			$form_data['email']
		)
	) {

		$validation_errors[] =
			'Please enter a valid email address.';
	}


	/*
	 * Canadian Province Validation
	 */

	$canadian_provinces = array(

		'AB',
		'BC',
		'MB',
		'NB',
		'NL',
		'NS',
		'NT',
		'NU',
		'ON',
		'PE',
		'QC',
		'SK',
		'YT',
	);


	if (
		! empty( $form_data['state'] )
		&& ! in_array(
			strtoupper(
				$form_data['state']
			),
			$canadian_provinces,
			true
		)
	) {

		$validation_errors[] =
			'Please select a valid Canadian province.';
	}


	/*
	 * Canadian Postal Code Validation
	 */

	if (
		! empty(
			$form_data['postal_code']
		)
		&& ! preg_match(
			'/^[A-Z]\d[A-Z][ -]?\d[A-Z]\d$/i',
			$form_data['postal_code']
		)
	) {

		$validation_errors[] =
			'Please enter a valid Canadian postal code, for example M5V 2T6.';
	}


	/*
	 * Validation Result
	 */

	if ( ! empty( $validation_errors ) ) {

		$order_result = array(

			'success' => false,

			'message' =>
				implode(
					' ',
					$validation_errors
				),
		);

	} else {


		/*
		 * Get Current Amazon Cart
		 */

		$cart_result =
			wp_amazon_checkout_get_cart_items();


		if ( ! $cart_result['success'] ) {

			$order_result = array(

				'success' => false,

				'message' =>
					'Unable to retrieve Amazon cart: '
					. (
						$cart_result['message']
						?? 'Unknown error.'
					),
			);

		} elseif (
			empty(
				$cart_result['items']
			)
		) {

			$order_result = array(

				'success' => false,

				'message' =>
					'Your Amazon cart is empty.',
			);

		} else {


			/*
			 * Build Order Payload
			 */

			$order_payload =
				wp_amazon_checkout_build_order_payload(
					$cart_result['items'],
					$form_data
				);


			if (
				empty(
					$order_payload['lineItems']
				)
			) {

				$order_result = array(

					'success' => false,

					'message' =>
						'No valid Amazon products were found in the cart.',
				);

			} else {


				/*
				 * Place Order
				 */

				$order_result =
					wp_amazon_ordering_request(

						'POST',

						'https://na.business-api.amazon.com/ordering/2022-10-30/orders',

						$order_payload
					);

				// Save Amazon checkout attempt against the WooCommerce order.
				$wc_order_id_for_save = function_exists( 'wcss_amazon_get_request_order_id' )
					? wcss_amazon_get_request_order_id()
					: 0;

				if (
					$wc_order_id_for_save
					&& function_exists( 'wcss_amazon_save_order_snapshot' )
					&& function_exists( 'wcss_amazon_normalize_cart_items' )
				) {
					$normalized_items = wcss_amazon_normalize_cart_items( $cart_result['items'] ?? array() );
					$items_subtotal   = 0.0;
					foreach ( $normalized_items as $ni ) {
						$items_subtotal += ( (float) ( $ni['price'] ?? 0 ) ) * ( (int) ( $ni['quantity'] ?? 0 ) );
					}

					wcss_amazon_save_order_snapshot(
						$wc_order_id_for_save,
						array(
							'status'            => ! empty( $order_result['success'] ) ? 'placed' : 'place_failed',
							'source'            => 'amazon_checkout_place_order',
							'cart_id'           => defined( 'AMAZON_CART_ID' ) ? AMAZON_CART_ID : '',
							'region'            => defined( 'AMAZON_REGION' ) ? AMAZON_REGION : 'CA',
							'user_email'        => defined( 'AMAZON_USER_EMAIL' ) ? AMAZON_USER_EMAIL : '',
							'shipping'          => array_merge(
								$form_data,
								array(
									'country' => 'CA',
								)
							),
							'cart_items'        => $normalized_items,
							'cart_subtotal'     => round( $items_subtotal, 2 ),
							'currency'          => 'CAD',
							'order_payload'     => $order_payload,
							'amazon_response'   => $order_result,
							'amazon_request_id' => $order_result['request_id'] ?? '',
							'placed_at'         => current_time( 'mysql' ),
						)
					);
				}
			}
		}
	}
}

}


/*
|--------------------------------------------------------------------------
| AJAX Shipping / Total Cost Calculation
|--------------------------------------------------------------------------
|
| This is handled by the checkout page itself instead of admin-ajax.php.
| That keeps this template self-contained: the page receives the AJAX POST,
| returns JSON, and exits before rendering the normal checkout HTML.
|
*/

if (
	isset( $_POST['amazon_shipping_calculation'] )
	&& '1' === sanitize_text_field(
		wp_unslash( $_POST['amazon_shipping_calculation'] )
	)
) {

	/*
	 * Verify calculation nonce.
	 */
	if (
		! isset( $_POST['amazon_shipping_nonce'] )
		|| ! wp_verify_nonce(
			sanitize_text_field(
				wp_unslash( $_POST['amazon_shipping_nonce'] )
			),
			'amazon_shipping_calculation'
		)
	) {
		wp_send_json(
			array(
				'success' => false,
				'message' => 'Security check failed. Please refresh the page and try again.',
			),
			403
		);
	}

	/*
	 * Collect the SAME fields used by the existing checkout form.
	 */
	$form_data = array(
		'full_name' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['full_name'] ?? ''
				)
			),

		'email' =>
			sanitize_email(
				wp_unslash(
					$_POST['email'] ?? ''
				)
			),

		'phone' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['phone'] ?? ''
				)
			),

		'company_name' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['company_name'] ?? ''
				)
			),

		'address_line1' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['address_line1'] ?? ''
				)
			),

		'address_line2' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['address_line2'] ?? ''
				)
			),

		'city' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['city'] ?? ''
				)
			),

		'state' =>
			sanitize_text_field(
				wp_unslash(
					$_POST['state'] ?? ''
				)
			),

		'postal_code' =>
			strtoupper(
				sanitize_text_field(
					wp_unslash(
						$_POST['postal_code'] ?? ''
					)
				)
			),
	);

	/*
	 * Validate required fields.
	 */
	$required_fields = array(
		'full_name'     => 'Full name',
		'email'         => 'Email',
		'phone'         => 'Phone',
		'address_line1' => 'Address',
		'city'          => 'City',
		'state'         => 'Province',
		'postal_code'   => 'Postal code',
	);

	$validation_errors = array();

	foreach ( $required_fields as $field => $label ) {
		if ( empty( $form_data[ $field ] ) ) {
			$validation_errors[] = $label . ' is required.';
		}
	}

	if (
		! empty( $form_data['email'] )
		&& ! is_email( $form_data['email'] )
	) {
		$validation_errors[] = 'Please enter a valid email address.';
	}

	$canadian_provinces = array(
		'AB',
		'BC',
		'MB',
		'NB',
		'NL',
		'NS',
		'NT',
		'NU',
		'ON',
		'PE',
		'QC',
		'SK',
		'YT',
	);

	if (
		! empty( $form_data['state'] )
		&& ! in_array(
			strtoupper( $form_data['state'] ),
			$canadian_provinces,
			true
		)
	) {
		$validation_errors[] =
			'Please select a valid Canadian province.';
	}

	if (
		! empty( $form_data['postal_code'] )
		&& ! preg_match(
			'/^[A-Z]\d[A-Z][ -]?\d[A-Z]\d$/i',
			$form_data['postal_code']
		)
	) {
		$validation_errors[] =
			'Please enter a valid Canadian postal code, for example M5V 2T6.';
	}

	if ( ! empty( $validation_errors ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => implode( ' ', $validation_errors ),
			),
			422
		);
	}

	/*
	 * Get the current Amazon cart first.
	 */
	$cart_result = wp_amazon_checkout_get_cart_items();

	if ( ! $cart_result['success'] ) {
		wp_send_json(
			array(
				'success' => false,
				'message' =>
					'Unable to retrieve Amazon cart: '
					. (
						$cart_result['message']
						?? 'Unknown error.'
					),
				'status_code' =>
					$cart_result['status_code'] ?? 0,
				'request_id' =>
					$cart_result['request_id'] ?? '',
				'raw_body' =>
					$cart_result['raw_body'] ?? '',
			),
			500
		);
	}

	if ( empty( $cart_result['items'] ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => 'Your Amazon cart is empty.',
			),
			422
		);
	}

	/*
	 * Amazon Business Cart API:
	 * POST /cart/2025-04-30/carts/{cartId}/totalPurchaseCostEstimations
	 *
	 * The API calculates the estimated cart cost against the complete
	 * delivery address.
	 */
	$url = sprintf(
		'https://na.business-api.amazon.com/cart/2025-04-30/carts/%s/totalPurchaseCostEstimations?region=%s',
		rawurlencode( AMAZON_CART_ID ),
		rawurlencode( AMAZON_REGION )
	);

	/*
	 * IMPORTANT: Amazon's Total Purchase Cost Estimation API expects
	 * the address at the TOP LEVEL as "address".
	 * Keep this payload aligned with the working cURL request.
	 */
	$estimate_payload = array(
		'address' => array(
			'addressType'   => 'PhysicalAddress',
			'fullName'      => $form_data['full_name'],
			'addressLine1'  => $form_data['address_line1'],
			'city'          => $form_data['city'],
			'stateOrRegion' => strtoupper( $form_data['state'] ),
			'postalCode'    => $form_data['postal_code'],
			'countryCode'   => 'CA',
		),
	);

	$estimate_result = wp_amazon_ordering_request(
		'POST',
		$url,
		$estimate_payload
	);

	/*
	 * Amazon API failure.
	 */
	if ( ! $estimate_result['success'] ) {

		$amazon_message = '';

		if (
			isset( $estimate_result['data']['errors'] )
			&& is_array( $estimate_result['data']['errors'] )
		) {
			$error_messages = array();

			foreach (
				$estimate_result['data']['errors']
				as $amazon_error
			) {
				if ( ! empty( $amazon_error['message'] ) ) {
					$error_messages[] =
						$amazon_error['message'];
				}
			}

			if ( ! empty( $error_messages ) ) {
				$amazon_message =
					implode( ' ', $error_messages );
			}
		}

		wp_send_json(
			array(
				'success' => false,
				'message' =>
					$amazon_message
					?: (
						$estimate_result['message']
						?? 'Amazon returned an error while calculating shipping.'
					),
				'status_code' =>
					$estimate_result['status_code'] ?? 0,
				'request_id' =>
					$estimate_result['request_id'] ?? '',
				'raw_body' =>
					$estimate_result['raw_body'] ?? '',
				'amazon_response' =>
					$estimate_result['data'] ?? null,
			),
			500
		);
	}

	$data = $estimate_result['data'];

	/*
	 * Amazon returns either:
	 *
	 *   charges[]
	 *
	 * or:
	 *
	 *   rejectionArtifacts[]
	 *
	 * A successful estimate should contain charges.
	 */
	if (
		isset( $data['rejectionArtifacts'] )
		&& is_array( $data['rejectionArtifacts'] )
		&& ! empty( $data['rejectionArtifacts'] )
	) {

		$rejection_messages = array();

		foreach (
			$data['rejectionArtifacts']
			as $artifact
		) {
			if ( ! empty( $artifact['message'] ) ) {
				$rejection_messages[] =
					$artifact['message'];
			}
		}

		wp_send_json(
			array(
				'success' => false,
				'message' =>
					! empty( $rejection_messages )
					? implode( ' ', $rejection_messages )
					: 'Amazon could not calculate the total cost for this cart and address.',
				'status_code' =>
					$estimate_result['status_code'] ?? 200,
				'request_id' =>
					$estimate_result['request_id'] ?? '',
				'amazon_response' =>
					$data,
			),
			422
		);
	}

	$charges = array();

	if (
		isset( $data['charges'] )
		&& is_array( $data['charges'] )
	) {
		$charges = $data['charges'];
	}

	if ( empty( $charges ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => 'Amazon returned no cost charges for this cart.',
				'status_code' =>
					$estimate_result['status_code'] ?? 200,
				'request_id' =>
					$estimate_result['request_id'] ?? '',
				'amazon_response' =>
					$data,
			),
			422
		);
	}

	/*
	 * Build the display totals from Amazon's charge breakdown.
	 *
	 * PRINCIPAL + SUBTOTAL = subtotal
	 * PRINCIPAL + SHIPPING = shipping
	 * TAX charges           = tax
	 *
	 * Total is the sum of all returned charges, so additional Amazon
	 * charge categories are also included.
	 */
	$subtotal = 0.0;
	$shipping = 0.0;
	$tax      = 0.0;
	$total    = 0.0;
	$currency = 'CAD';

	foreach ( $charges as $charge ) {

		$amount = 0.0;

		if (
			isset(
				$charge['amount']['amount']
			)
		) {
			$amount =
				(float) $charge['amount']['amount'];
		}

		if (
			! empty(
				$charge['amount']['currencyCode']
			)
		) {
			$currency =
				$charge['amount']['currencyCode'];
		}

		$category =
			strtoupper(
				(string) (
					$charge['category']
					?? ''
				)
			);

		$type =
			strtoupper(
				(string) (
					$charge['type']
					?? ''
				)
			);

		$total += $amount;

		if (
			'PRINCIPAL' === $type
			&& 'SUBTOTAL' === $category
		) {
			$subtotal += $amount;
		}

		if (
			'PRINCIPAL' === $type
			&& 'SHIPPING' === $category
		) {
			$shipping += $amount;
		}

		if ( 'TAX' === $type ) {
			$tax += $amount;
		}
	}

	wp_send_json(
		array(
			'success' => true,
			'message' => 'Shipping and total calculated successfully.',
			'request_id' =>
				$estimate_result['request_id'] ?? '',
			'totals' => array(
				'subtotal' => number_format(
					$subtotal,
					2,
					'.',
					''
				),
				'shipping' => number_format(
					$shipping,
					2,
					'.',
					''
				),
				'tax' => number_format(
					$tax,
					2,
					'.',
					''
				),
				'total' => number_format(
					$total,
					2,
					'.',
					''
				),
				'currency' => $currency,
			),
			'amazon_response' => $data,
		),
		200
	);
}


/*                                                                       
--------------------------------------------------------------------------
Get Cart For Display                                                      
--------------------------------------------------------------------------
*/                                                                       

$display_cart_result =

wp_amazon_checkout_get_cart_items();

$display_cart_items = array();

if (

$display_cart_result['success']

&& ! empty(

$display_cart_result['items']

)

) {

$display_cart_items =
	$display_cart_result['items'];

}

// Keep a checkout-page cart snapshot linked to the WC order.
if (
	! empty( $wc_order_id )
	&& ! empty( $display_cart_items )
	&& function_exists( 'wcss_amazon_save_order_snapshot' )
	&& function_exists( 'wcss_amazon_normalize_cart_items' )
) {
	wcss_amazon_save_order_snapshot(
		(int) $wc_order_id,
		array(
			'status'     => 'checkout_ready',
			'source'     => 'amazon_checkout_page',
			'cart_items' => wcss_amazon_normalize_cart_items( $display_cart_items ),
		)
	);
}

get_header();

?>

 <div class="amazon-checkout-page"> 

<div class="amazon-checkout-container">


	<!-- HEADER -->

	<div class="amazon-checkout-header">

		<h1>
			Amazon Checkout
		</h1>

		<p>
			Review your Amazon Business order and enter
			your Canadian shipping information.
		</p>

	</div>


	<?php if ( $order_result ) : ?>

		<!-- ORDER RESULT -->

		<div
			class="amazon-order-result <?php echo $order_result['success'] ? 'success' : 'error'; ?>"
		>

			<?php if ( $order_result['success'] ) : ?>

				<div class="amazon-result-icon">
					✓
				</div>

				<h2>
					Order Request Submitted
				</h2>

				<p>
					Amazon has processed the order request.
				</p>


				<?php if ( ! empty( $order_result['request_id'] ) ) : ?>

					<div class="amazon-result-detail">

						<strong>
							Amazon Request ID
						</strong>

						<code>
							<?php
							echo esc_html(
								$order_result['request_id']
							);
							?>
						</code>

					</div>

				<?php endif; ?>


				<details class="amazon-response-details">

					<summary>
						View Amazon Response
					</summary>

					<pre><?php
					echo esc_html(
						wp_json_encode(
							$order_result['data'],
							JSON_PRETTY_PRINT
						)
					);
					?></pre>

				</details>


			<?php else : ?>

				<div class="amazon-result-icon">
					!
				</div>

				<h2>
					Order Could Not Be Placed
				</h2>

				<p>
					<?php
					echo esc_html(
						$order_result['message']
						?? 'Amazon returned an error.'
					);
					?>
				</p>


				<?php if ( ! empty( $order_result['status_code'] ) ) : ?>

					<div class="amazon-result-detail">

						<strong>
							HTTP Status
						</strong>

						<?php
						echo esc_html(
							$order_result['status_code']
						);
						?>

					</div>

				<?php endif; ?>


				<?php if ( ! empty( $order_result['request_id'] ) ) : ?>

					<div class="amazon-result-detail">

						<strong>
							Amazon Request ID
						</strong>

						<code>
							<?php
							echo esc_html(
								$order_result['request_id']
							);
							?>
						</code>

					</div>

				<?php endif; ?>


				<details class="amazon-response-details">

					<summary>
						View Amazon Response
					</summary>

					<pre><?php
					echo esc_html(
						$order_result['raw_body']
						?? ''
					);
					?></pre>

				</details>


			<?php endif; ?>

		</div>

	<?php endif; ?>


	<?php if ( empty( $display_cart_items ) ) : ?>

		<div class="amazon-checkout-empty">

			<h2>
				Your Amazon cart is empty
			</h2>

			<p>
				Please add products to your Amazon cart
				before checking out.
			</p>

		</div>


	<?php else : ?>


		<div class="amazon-checkout-grid">


			<!--
			==================================================
			LEFT SIDE
			SHIPPING + ORDER INFORMATION
			==================================================
			-->

			<div class="amazon-checkout-right">


				<!-- SHIPPING INFORMATION -->

				<div class="amazon-checkout-card">

					<h2>
						Shipping Information
					</h2>


					<form
						id="amazon-order-form"
						method="post"
					>


						<input
							type="hidden"
							name="amazon_checkout_action"
							value="place_order"
						>

						<input
							type="hidden"
							name="wc_order_id"
							value="<?php echo esc_attr( (string) $wc_order_id ); ?>"
						>


						<?php
						wp_nonce_field(
							'amazon_place_order',
							'amazon_checkout_nonce'
						);
						?>

						<input
							type="hidden"
							name="amazon_shipping_nonce"
							id="amazon_shipping_nonce"
							value="<?php echo esc_attr( wp_create_nonce( 'amazon_shipping_calculation' ) ); ?>"
						>

						<input
							type="hidden"
							name="shipping_calculated"
							id="amazon_shipping_calculated"
							value="0"
						>


						<div class="amazon-form-field">

							<label for="full_name">
								Full Name *
							</label>

							<input
								type="text"
								id="full_name"
								name="full_name"
								placeholder="John Smith"
								required
							>

						</div>


						<div class="amazon-form-field">

							<label for="email">
								Email *
							</label>

							<input
								type="email"
								id="email"
								name="email"
								value="<?php echo esc_attr( AMAZON_USER_EMAIL ); ?>"
								required
							>

						</div>


						<div class="amazon-form-field">

							<label for="phone">
								Phone *
							</label>

							<input
								type="text"
								id="phone"
								name="phone"
								placeholder="416-555-0123"
								required
							>

						</div>


						<div class="amazon-form-field">

							<label for="company_name">
								Company Name
							</label>

							<input
								type="text"
								id="company_name"
								name="company_name"
								placeholder="ABC Supplies Inc."
							>

						</div>


						<div class="amazon-form-field">

							<label for="address_line1">
								Address *
							</label>

							<input
								type="text"
								id="address_line1"
								name="address_line1"
								placeholder="100 Queen Street West"
								required
							>

						</div>


						<div class="amazon-form-field">

							<label for="address_line2">
								Address Line 2
							</label>

							<input
								type="text"
								id="address_line2"
								name="address_line2"
								placeholder="Suite 100"
							>

						</div>


						<div class="amazon-form-row">

							<div class="amazon-form-field">

								<label for="city">
									City *
								</label>

								<input
									type="text"
									id="city"
									name="city"
									placeholder="Toronto"
									required
								>

							</div>


							<div class="amazon-form-field">

								<label for="state">
									Province *
								</label>

								<select
									id="state"
									name="state"
									required
								>

									<option value="">
										Select Province (e.g. Ontario / ON)
									</option>

									<option value="AB">
										Alberta (AB)
									</option>

									<option value="BC">
										British Columbia (BC)
									</option>

									<option value="MB">
										Manitoba (MB)
									</option>

									<option value="NB">
										New Brunswick (NB)
									</option>

									<option value="NL">
										Newfoundland and Labrador (NL)
									</option>

									<option value="NS">
										Nova Scotia (NS)
									</option>

									<option value="NT">
										Northwest Territories (NT)
									</option>

									<option value="NU">
										Nunavut (NU)
									</option>

									<option value="ON">
										Ontario (ON)
									</option>

									<option value="PE">
										Prince Edward Island (PE)
									</option>

									<option value="QC">
										Quebec (QC)
									</option>

									<option value="SK">
										Saskatchewan (SK)
									</option>

									<option value="YT">
										Yukon (YT)
									</option>

								</select>

							</div>

						</div>


						<div class="amazon-form-row">

							<div class="amazon-form-field">

								<label for="postal_code">
									Postal Code *
								</label>

								<input
									type="text"
									id="postal_code"
									name="postal_code"
									placeholder="M5H 2N2"
									autocomplete="postal-code"
									required
								>

							</div>


							<div class="amazon-form-field">

								<label for="country">
									Country
								</label>

								<input
									type="text"
									id="country"
									value="Canada (CA)"
									disabled
								>

							</div>

						</div>


						<!-- TRIAL MODE -->

						<div
							class="amazon-trial-mode"
							style="display: none;"
						>

							<label>

								<input
									type="checkbox"
									name="trial_mode"
									value="1"
									checked
								>

								<span>

									<strong>
										Trial Mode
									</strong>

									<small>
										Test the order without creating
										an actual Amazon order.
									</small>

								</span>

							</label>

						</div><!-- .amazon-trial-mode -->


						<!-- CALCULATE SHIPPING -->

						<div class="amazon-shipping-calculation">

							<button
								type="button"
								id="amazon-calculate-shipping"
								class="amazon-calculate-shipping-button"
							>
								Calculate Shipping
							</button>

							<div
								id="amazon-shipping-calculation-status"
								class="amazon-shipping-calculation-status"
								aria-live="polite"
								style="display:none;"
							></div>

							<div
								id="amazon-shipping-request-id"
								class="amazon-shipping-request-id"
								style="display:none;"
							></div>

						</div>



						<!--
						 * Place Order button intentionally removed
						 * from here.
						 *
						 * It is now displayed in the Order Summary
						 * on the right, while still submitting this
						 * same form using form="amazon-order-form".
						 -->


					</form>

				</div>


				<!--
				==================================================
				ORDER INFORMATION
				==================================================
				-->

				<div class="amazon-checkout-card" style="display:none;">

					<h2>
						Order Information
					</h2>


					<div class="amazon-form-row">


						<div class="amazon-form-field">

							<label for="purchase_order_number">
								Purchase Order Number
							</label>

							<input
								type="text"
								id="purchase_order_number"
								name="purchase_order_number"
								form="amazon-order-form"
								placeholder="Direct Order 001"
							>

						</div>


						<div class="amazon-form-field">

							<label for="group_identifier">
								Amazon Group Identifier
							</label>

							<input
								type="text"
								id="group_identifier"
								name="group_identifier"
								form="amazon-order-form"
								value="<?php echo esc_attr( AMAZON_GROUP_ID ); ?>"
								required
							>

						</div>


					</div>

				</div>


			</div>


			<!--
			==================================================
			RIGHT SIDE
			ORDER SUMMARY / CART
			==================================================
			-->

			<div class="amazon-checkout-left">


				<div class="amazon-checkout-card">

					<h2>
						Order Summary
					</h2>


					<div class="amazon-checkout-items">


						<?php

						$cart_total = 0;
						$currency   = 'CAD';

						foreach (
							$display_cart_items
							as $index => $item
						) :

							$asin = '';


							if (
								isset(
									$item['productIdentifier']
								)
							) {

								$asin =
									$item['productIdentifier'];

							} elseif (
								isset(
									$item['asin']
								)
							) {

								$asin =
									$item['asin'];
							}


							$asin =
								$item['productIdentifier']
								?? $asin;


							$woo_product_ids = get_posts(
								array(
									'post_type' =>
										'product',

									'post_status' =>
										'any',

									'meta_key' =>
										'amazon_asin',

									'meta_value' =>
										$asin,

									'posts_per_page' =>
										1,

									'fields' =>
										'ids',
								)
							);


							$woo_product_id =
								$woo_product_ids[0] ?? 0;


							$product_title =
								get_the_title(
									$woo_product_id
								);


							$quantity = 1;


							if (
								isset(
									$item['quantity']
								)
							) {

								$quantity =
									(int) $item['quantity'];
							}


							$title =
								$product_title;


							if (
								isset(
									$item['title']
								)
								&& is_string(
									$item['title']
								)
							) {

								$title =
									$item['title'];
							}


							$price    = '';
							$item_currency = '';


							if (
								isset(
									$item['price']['amount']
								)
							) {

								$price =
									$item['price']['amount'];
							}


							if (
								isset(
									$item['price']['currencyCode']
								)
							) {

								$item_currency =
									$item['price']['currencyCode'];

								$currency =
									$item_currency;
							}


							$cart_total += (float) $price * $quantity;


							$buying_option_id = '';


							if (
								isset(
									$item['buyingOptionIdentifier']
								)
							) {

								$buying_option_id =
									$item['buyingOptionIdentifier'];
							}

							?>


							<div class="amazon-checkout-item">


								<div class="amazon-checkout-item-number">

									<?php
									echo esc_html(
										$index + 1
									);
									?>

								</div>


								<div class="amazon-checkout-item-content">

									<h3>

										<?php
										echo esc_html(
											$title
										);
										?>

									</h3>


									<?php if ( $asin ) : ?>

										<div class="amazon-checkout-item-asin">

											ASIN:

											<strong>

												<?php
												echo esc_html(
													$asin
												);
												?>

											</strong>

										</div>

									<?php endif; ?>


									<div class="amazon-checkout-item-meta">

										<span>

											Qty:

											<strong>

												<?php
												echo esc_html(
													$quantity
												);
												?>

											</strong>

										</span>


										<?php if ( $price !== '' ) : ?>

											<span>

												Price:

												<strong>

													<?php
													echo esc_html(
														trim(
															$item_currency
															. ' '
															. $price
														)
													);
													?>

												</strong>

											</span>

										<?php endif; ?>

									</div>


									<?php if ( $buying_option_id ) : ?>

										<div
											class="amazon-buying-option"
										>

											Buying Option:

											<code>

												<?php
												echo esc_html(
													$buying_option_id
												);
												?>

											</code>

										</div>

									<?php endif; ?>


								</div>

							</div>


						<?php endforeach; ?>


					</div>


					<!--
					==================================================
					CART TOTAL
					==================================================
					-->

					<div class="amazon-checkout-total">


						<div class="amazon-checkout-total-row">

							<span>
								Subtotal
							</span>

							<strong id="amazon-checkout-subtotal">

								<?php
								echo esc_html(
									trim(
										$currency
										. ' '
										. number_format(
											(float) $cart_total,
											2
										)
									)
								);
								?>

							</strong>

						</div>


						<div class="amazon-checkout-total-row">

							<span>
								Shipping
							</span>

							<strong id="amazon-checkout-shipping">
								—
							</strong>

						</div>


						<div class="amazon-checkout-total-row">

							<span>
								Tax
							</span>

							<strong id="amazon-checkout-tax">
								—
							</strong>

						</div>


						<div class="amazon-checkout-total-row amazon-checkout-total-final">

							<span>
								Total
							</span>

							<strong id="amazon-checkout-total">

								<?php
								echo esc_html(
									trim(
										$currency
										. ' '
										. number_format(
											(float) $cart_total,
											2
										)
									)
								);
								?>

							</strong>

						</div>


					</div>


					<!--
					==================================================
					PLACE ORDER
					==================================================

					The button is outside the form visually,
					but "form" connects it to the existing
					amazon-order-form.

					No POST/API logic is changed.
					-->

					<div class="amazon-checkout-place-order">

						<button
							type="submit"
							form="amazon-order-form"
							class="amazon-place-order-button"
							disabled
						>

							Place Amazon Order

						</button>


						<p class="amazon-checkout-note">

							Your order will be submitted through the
							Amazon Business Ordering API using the stored
							Amazon Business payment method.

						</p>

					</div>


				</div>


			</div>


		</div>


	<?php endif; ?>


</div>

 </div>
<script>
document.addEventListener('DOMContentLoaded', function () {

	const form = document.getElementById('amazon-order-form');
	const calculateButton = document.getElementById('amazon-calculate-shipping');
	const statusBox = document.getElementById('amazon-shipping-calculation-status');
	const requestIdBox = document.getElementById('amazon-shipping-request-id');

	const shippingCalculated = document.getElementById(
		'amazon_shipping_calculated'
	);

	const placeOrderButton = document.querySelector(
		'.amazon-place-order-button'
	);

	const subtotalElement = document.getElementById(
		'amazon-checkout-subtotal'
	);

	const shippingElement = document.getElementById(
		'amazon-checkout-shipping'
	);

	const taxElement = document.getElementById(
		'amazon-checkout-tax'
	);

	const totalElement = document.getElementById(
		'amazon-checkout-total'
	);

	if (
		! form ||
		! calculateButton ||
		! shippingCalculated ||
		! placeOrderButton
	) {
		return;
	}

	/*
	 * Fields that affect Amazon's shipping/tax calculation.
	 * These are the ORIGINAL field names from this checkout template.
	 */
	const addressFields = [
		'full_name',
		'email',
		'phone',
		'company_name',
		'address_line1',
		'address_line2',
		'city',
		'state',
		'postal_code'
	];

	function setStatus(message, type) {

		if (!statusBox) {
			return;
		}

		statusBox.textContent = message;
		statusBox.className =
			'amazon-shipping-calculation-status amazon-status-' +
			(type || 'info');

		statusBox.style.display = message ? 'block' : 'none';
	}

	function setRequestId(requestId) {

		if (!requestIdBox) {
			return;
		}

		if (requestId) {
			requestIdBox.textContent =
				'Amazon Request ID: ' + requestId;

			requestIdBox.style.display = 'block';
		} else {
			requestIdBox.textContent = '';
			requestIdBox.style.display = 'none';
		}
	}

	function formatMoney(amount, currency) {

		const numericAmount = Number(amount || 0);

		return (
			currency +
			' ' +
			numericAmount.toFixed(2)
		);
	}

	function invalidateCalculation(message) {

		shippingCalculated.value = '0';
		placeOrderButton.disabled = true;

		if (shippingElement) {
			shippingElement.textContent = '—';
		}

		if (taxElement) {
			taxElement.textContent = '—';
		}

		if (totalElement) {
			totalElement.textContent = '—';
		}

		setRequestId('');

		if (message) {
			setStatus(message, 'info');
		} else {
			setStatus('', 'info');
		}
	}

	/*
	 * Any address change invalidates the previous Amazon calculation.
	 */
	addressFields.forEach(function (fieldName) {

		const field = form.elements[fieldName];

		if (!field) {
			return;
		}

		field.addEventListener('input', function () {
			invalidateCalculation(
				'Shipping and tax need to be recalculated after an address change.'
			);
		});

		field.addEventListener('change', function () {
			invalidateCalculation(
				'Shipping and tax need to be recalculated after an address change.'
			);
		});
	});

	/*
	 * Calculate Shipping / Total using AJAX.
	 *
	 * IMPORTANT:
	 * This intentionally posts back to the checkout page itself rather
	 * than admin-ajax.php. The page template handles the POST and returns
	 * JSON before rendering HTML. This keeps the AJAX handler inside this
	 * single template and avoids the 400 caused by an unregistered
	 * admin-ajax action.
	 */
	calculateButton.addEventListener('click', async function () {

		if ( ! form.checkValidity() ) {
			form.reportValidity();
			return;
		}

		const nonceField = document.getElementById(
			'amazon_shipping_nonce'
		);

		if (!nonceField || !nonceField.value) {
			setStatus(
				'Shipping calculation security token is missing. Please refresh the page.',
				'error'
			);
			return;
		}

		calculateButton.disabled = true;
		calculateButton.classList.add('is-loading');

		const originalButtonText =
			calculateButton.getAttribute('data-original-text')
			|| calculateButton.textContent.trim();

		calculateButton.setAttribute(
			'data-original-text',
			originalButtonText
		);

		calculateButton.textContent =
			'Calculating…';

		placeOrderButton.disabled = true;
		shippingCalculated.value = '0';

		setRequestId('');

		setStatus(
			'Calculating Amazon shipping, tax, and total…',
			'loading'
		);

		const formData = new FormData(form);

		formData.append(
			'amazon_shipping_calculation',
			'1'
		);

		formData.set(
			'amazon_shipping_nonce',
			nonceField.value
		);

		try {

			const response = await fetch(
				window.location.href.split('#')[0],
				{
					method: 'POST',
					body: formData,
					credentials: 'same-origin',
					headers: {
						'X-Requested-With': 'XMLHttpRequest'
					}
				}
			);

			const responseText =
				await response.text();

			let result = null;

			try {
				result = JSON.parse(responseText);
			} catch (jsonError) {

				console.error(
					'Amazon shipping calculation returned non-JSON:',
					responseText
				);

				throw new Error(
					'The server returned an unexpected response. HTTP ' +
					response.status +
					'. Check the browser Network response for details.'
				);
			}

			console.log(
				'Amazon shipping calculation response:',
				result
			);

			if (
				! response.ok
				|| ! result
				|| ! result.success
			) {

				const message =
					result && result.message
					? result.message
					: (
						'Unable to calculate shipping. HTTP ' +
						response.status +
						'.'
					);

				if (result && result.request_id) {
					setRequestId(
						result.request_id
					);
				}

				if (
					result
					&& result.raw_body
				) {
					console.error(
						'Amazon raw response:',
						result.raw_body
					);
				}

				throw new Error(message);
			}

			if (
				! result.totals
				|| typeof result.totals.total === 'undefined'
			) {
				throw new Error(
					'Amazon returned a successful response, but no total was found.'
				);
			}

			const totals = result.totals;

			const currency =
				totals.currency || 'CAD';

			if (subtotalElement) {
				subtotalElement.textContent =
					formatMoney(
						totals.subtotal,
						currency
					);
			}

			if (shippingElement) {
				shippingElement.textContent =
					formatMoney(
						totals.shipping,
						currency
					);
			}

			if (taxElement) {
				taxElement.textContent =
					formatMoney(
						totals.tax,
						currency
					);
			}

			if (totalElement) {
				totalElement.textContent =
					formatMoney(
						totals.total,
						currency
					);
			}

			shippingCalculated.value = '1';
			placeOrderButton.disabled = false;

			setRequestId(
				result.request_id || ''
			);

			setStatus(
				'Shipping, tax, and total calculated successfully. You can now place the order.',
				'success'
			);

		} catch (error) {

			console.error(
				'Amazon shipping calculation error:',
				error
			);

			invalidateCalculation(
				error.message ||
				'Unable to calculate shipping. Please try again.'
			);

		} finally {

			calculateButton.disabled = false;
			calculateButton.classList.remove('is-loading');

			calculateButton.textContent =
				calculateButton.getAttribute(
					'data-original-text'
				) || 'Calculate Shipping';
		}
	});

	/*
	 * Do not allow Place Order until Amazon has successfully calculated
	 * the current shipping address.
	 */
	form.addEventListener('submit', function (event) {

		if (shippingCalculated.value !== '1') {

			event.preventDefault();

			setStatus(
				'Please click "Calculate Shipping" and wait for the Amazon calculation to complete before placing the order.',
				'error'
			);

			calculateButton.scrollIntoView({
				behavior: 'smooth',
				block: 'center'
			});
		}
	});

});
</script>

<style>  /* |-------------------------------------------------------------------------- | Page |-------------------------------------------------------------------------- */  .amazon-checkout-page { 	width: 100%; 	padding: 45px 20px; 	background: #f5f6f7; 	box-sizing: border-box; }

.amazon-checkout-container {

max-width: 1200px;

margin: 0 auto;

}

/*                                                                       
--------------------------------------------------------------------------
Header                                                                    
--------------------------------------------------------------------------
*/                                                                       

.amazon-checkout-header {

margin-bottom: 25px;

}

.amazon-checkout-header h1 {

margin: 0 0 8px;

font-size: 32px;

}

.amazon-checkout-header p {

margin: 0;

color: #666;

}

/*                                                                       
--------------------------------------------------------------------------
Grid                                                                      
--------------------------------------------------------------------------
                                                                          
LEFT:                                                                     
Shipping                                                                  
Order Information                                                         
                                                                          
RIGHT:                                                                    
Order Summary                                                             
                                                                          
*/                                                                       

.amazon-checkout-grid {

display: grid;

grid-template-columns:
	minmax(0, 1.15fr)
	minmax(360px, .85fr);

gap: 25px;

align-items: start;

}

/*                                                                       
--------------------------------------------------------------------------
LEFT SIDE                                                                 
--------------------------------------------------------------------------
*/                                                                       

.amazon-checkout-right {

grid-column: 1;

grid-row: 1;

}

/*                                                                       
--------------------------------------------------------------------------
RIGHT SIDE                                                                
--------------------------------------------------------------------------
*/                                                                       

.amazon-checkout-left {

grid-column: 2;

grid-row: 1;

}

/*                                                                       
--------------------------------------------------------------------------
Card                                                                      
--------------------------------------------------------------------------
*/                                                                       

.amazon-checkout-card {

padding: 25px;

margin-bottom: 20px;

background: #fff;

border-radius: 12px;

box-shadow:
	0 2px 10px rgba(0,0,0,.06);

}

.amazon-checkout-card h2 {

margin: 0 0 22px;

font-size: 21px;

}

/*                                                                       
--------------------------------------------------------------------------
Cart Items                                                                
--------------------------------------------------------------------------
*/                                                                       

.amazon-checkout-items {

display: flex;

flex-direction: column;

gap: 12px;

}

.amazon-checkout-item {

display: flex;

gap: 15px;

padding: 16px;

border: 1px solid #e5e5e5;

border-radius: 8px;

}

.amazon-checkout-item-number {

flex: 0 0 35px;

width: 35px;
height: 35px;

display: flex;

align-items: center;
justify-content: center;

background: #f1f1f1;

border-radius: 50%;

font-weight: 700;

}

.amazon-checkout-item-content {

min-width: 0;

flex: 1;

}

.amazon-checkout-item-content h3 {

margin: 0 0 5px;

font-size: 17px;

}

.amazon-checkout-item-asin {

margin-bottom: 8px;

font-size: 13px;

color: #666;

}

.amazon-checkout-item-meta {

display: flex;

gap: 20px;

flex-wrap: wrap;

font-size: 14px;

color: #555;

}

.amazon-buying-option {

margin-top: 8px;

font-size: 12px;

color: #777;

word-break: break-all;

}

.amazon-buying-option code {

font-family: monospace;

}

/*                                                                       
--------------------------------------------------------------------------
Cart Total                                                                
--------------------------------------------------------------------------
*/                                                                       

.amazon-checkout-total {

margin-top: 22px;

padding-top: 18px;

border-top: 1px solid #e5e5e5;

}

.amazon-checkout-total-row {

display: flex;

align-items: center;

justify-content: space-between;

gap: 20px;

padding: 7px 0;

font-size: 15px;

color: #555;

}

.amazon-checkout-total-row strong {

color: #222;

font-weight: 600;

}

.amazon-checkout-total-final {

margin-top: 8px;

padding-top: 15px;

border-top: 1px solid #e5e5e5;

font-size: 18px;

color: #111;

}

.amazon-checkout-total-final strong {

font-size: 20px;

font-weight: 700;

}

/*                                                                       
--------------------------------------------------------------------------
Place Order                                                               
--------------------------------------------------------------------------
*/                                                                       

.amazon-checkout-place-order {

margin-top: 20px;

padding-top: 20px;

border-top: 1px solid #e5e5e5;

}

.amazon-place-order-button {

display: block;

width: 100%;

padding: 15px 20px;

border: 0;

border-radius: 8px;

background: #111;

color: #fff;

font-size: 16px;

font-weight: 700;

cursor: pointer;

text-align: center;

transition: opacity .2s ease;

}

.amazon-place-order-button:hover {

opacity: .9;

}

.amazon-checkout-note {

margin: 12px 0 0;

color: #777;

font-size: 12px;

line-height: 1.5;

}

/*                                                                       
--------------------------------------------------------------------------
Form                                                                      
--------------------------------------------------------------------------
*/                                                                       

.amazon-form-field {

margin-bottom: 16px;

}

.amazon-form-field label {

display: block;

margin-bottom: 6px;

font-size: 13px;

font-weight: 600;

color: #333;

}

.amazon-form-field input,

.amazon-form-field select {

width: 100%;

padding: 12px 13px;

border: 1px solid #d7d7d7;

border-radius: 7px;

background: #fff;

box-sizing: border-box;

font-size: 14px;

}

.amazon-form-field input:focus,

.amazon-form-field select:focus {

outline: none;

border-color: #777;

}

.amazon-form-row {

display: grid;

grid-template-columns: 1fr 1fr;

gap: 15px;

}

/*                                                                       
--------------------------------------------------------------------------
Trial Mode                                                                
--------------------------------------------------------------------------
*/                                                                       

.amazon-trial-mode {

padding: 15px;

margin: 20px 0;

background: #fff8e1;

border: 1px solid #f0d77a;

border-radius: 8px;

}

.amazon-trial-mode label {

display: flex;

gap: 10px;

align-items: flex-start;

cursor: pointer;

}

.amazon-trial-mode input {

margin-top: 4px;

}

.amazon-trial-mode strong {

display: block;

margin-bottom: 3px;

}

.amazon-trial-mode small {

display: block;

color: #666;

}

/*                                                                       
--------------------------------------------------------------------------
Empty                                                                     
--------------------------------------------------------------------------
*/                                                                       

.amazon-checkout-empty {

padding: 60px 30px;

background: #fff;

border-radius: 12px;

text-align: center;

box-shadow:
	0 2px 10px rgba(0,0,0,.06);

}

.amazon-checkout-empty h2 {

margin: 0 0 10px;

}

.amazon-checkout-empty p {

color: #666;

}

/*                                                                       
--------------------------------------------------------------------------
Result                                                                    
--------------------------------------------------------------------------
*/                                                                       

.amazon-order-result {

padding: 25px;

margin-bottom: 25px;

border-radius: 12px;

background: #fff;

box-shadow:
	0 2px 10px rgba(0,0,0,.06);

}

.amazon-order-result.success {

border-left: 5px solid #16803c;

}

.amazon-order-result.error {

border-left: 5px solid #c62828;

}

.amazon-result-icon {

width: 42px;

height: 42px;

display: flex;

align-items: center;
justify-content: center;

margin-bottom: 12px;

border-radius: 50%;

background: #f0f0f0;

font-size: 22px;

font-weight: 700;

}

.amazon-order-result h2 {

margin: 0 0 8px;

}

.amazon-order-result p {

color: #666;

}

.amazon-result-detail {

margin-top: 12px;

padding: 12px;

background: #f7f7f7;

border-radius: 6px;

font-size: 13px;

}

.amazon-result-detail strong {

display: inline-block;

margin-right: 8px;

}

.amazon-result-detail code {

word-break: break-all;

font-family: monospace;

}

.amazon-response-details {

margin-top: 20px;

}

.amazon-response-details summary {

cursor: pointer;

font-weight: 600;

}

.amazon-response-details pre {

max-height: 500px;

overflow: auto;

padding: 15px;

margin-top: 10px;

background: #111;

color: #fff;

border-radius: 8px;

font-size: 12px;

line-height: 1.5;

white-space: pre-wrap;

word-break: break-word;

}


/* --------------------------------------------------------------------------
Calculate Shipping
-------------------------------------------------------------------------- */

.amazon-shipping-calculation {
	margin-top: 20px;
	padding-top: 20px;
	border-top: 1px solid #e5e5e5;
}

.amazon-calculate-shipping-button {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	padding: 13px 20px;
	border: 1px solid #111;
	border-radius: 8px;
	background: #fff;
	color: #111;
	font-size: 15px;
	font-weight: 700;
	cursor: pointer;
	transition: opacity .2s ease, background .2s ease;
}

.amazon-calculate-shipping-button:hover {
	background: #f5f5f5;
}

.amazon-calculate-shipping-button:disabled {
	opacity: .55;
	cursor: not-allowed;
}

.amazon-calculate-shipping-button.is-loading {
	cursor: wait;
}

.amazon-shipping-calculation-status {
	margin-top: 12px;
	padding: 11px 13px;
	border-radius: 7px;
	font-size: 13px;
	line-height: 1.5;
}

.amazon-status-info,
.amazon-status-loading {
	background: #f5f5f5;
	color: #555;
}

.amazon-status-success {
	background: #edf8f1;
	color: #166534;
	border: 1px solid #ccebd7;
}

.amazon-status-error {
	background: #fff1f1;
	color: #a51d1d;
	border: 1px solid #f0caca;
}

.amazon-shipping-request-id {
	margin-top: 8px;
	font-size: 11px;
	color: #777;
	word-break: break-all;
}

.amazon-place-order-button:disabled {
	opacity: .45;
	cursor: not-allowed;
}


/*                                                                       
--------------------------------------------------------------------------
Responsive                                                                
--------------------------------------------------------------------------
*/                                                                       

@media (max-width: 850px) {

.amazon-checkout-grid {
	grid-template-columns: 1fr;
}


.amazon-checkout-right {
	grid-column: 1;
	grid-row: 1;
}


.amazon-checkout-left {
	grid-column: 1;
	grid-row: 2;
}

}

@media (max-width: 550px) {

.amazon-checkout-page {
	padding: 25px 12px;
}


.amazon-checkout-card {
	padding: 18px;
}


.amazon-form-row {
	grid-template-columns: 1fr;
}


.amazon-checkout-header h1 {
	font-size: 27px;
}

}

 </style><?php get_footer(); ?>