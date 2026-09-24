<?php
/**
 * Template Name: Amazon Cart
 */

defined( 'ABSPATH' ) || exit;


/*
|--------------------------------------------------------------------------
| Amazon Settings
|--------------------------------------------------------------------------
*/

define( 'AMAZON_CART_ID', 'cart-wxXmutF5kiXGRb9J' );
define( 'AMAZON_CART_REGION', 'CA' );
define( 'AMAZON_USER_EMAIL', 'ecom@hightideinc.com' );


/*
|--------------------------------------------------------------------------
| Get Amazon Access Token
|--------------------------------------------------------------------------
*/

if ( ! function_exists( 'wp_amazon_get_access_token' ) ) {

	function wp_amazon_get_access_token() {

		$response = wp_remote_post(
			'https://api.amazon.com/auth/o2/token',
			array(
				'timeout' => 30,

				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
				),

				'body' => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => AMAZON_REFRESH_TOKEN,
					'client_id'     => AMAZON_CLIENT_ID,
					'client_secret' => AMAZON_CLIENT_SECRET,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		$body = json_decode(
			wp_remote_retrieve_body( $response ),
			true
		);

		if ( $status_code !== 200 || empty( $body['access_token'] ) ) {

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
|--------------------------------------------------------------------------
| Get Amazon Cart Items
|--------------------------------------------------------------------------
*/

function wp_amazon_get_cart_items() {

	$access_token = wp_amazon_get_access_token();

	if ( is_wp_error( $access_token ) || empty( $access_token ) ) {

		return array(
			'success' => false,
			'message' => is_wp_error( $access_token )
				? $access_token->get_error_message()
				: 'Amazon access token is missing.',
		);
	}

	$url = sprintf(
		'https://na.business-api.amazon.com/cart/2025-04-30/carts/%s/items?region=%s',
		rawurlencode( AMAZON_CART_ID ),
		AMAZON_CART_REGION
	);

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 30,

			'headers' => array(
				'x-amz-access-token' => $access_token,
				'x-amz-user-email'   => AMAZON_USER_EMAIL,
				'Accept'             => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {

		return array(
			'success' => false,
			'message' => $response->get_error_message(),
		);
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = wp_remote_retrieve_body( $response );
	$data        = json_decode( $body, true );

	$request_id = wp_remote_retrieve_header(
		$response,
		'x-amzn-requestid'
	);

	if ( empty( $request_id ) ) {
		$request_id = wp_remote_retrieve_header(
			$response,
			'x-amzn-request-id'
		);
	}

	if ( $status_code < 200 || $status_code >= 300 ) {

		return array(
			'success'     => false,
			'status_code' => $status_code,
			'request_id'  => $request_id,
			'message'     => isset( $data['errors'] )
				? wp_json_encode( $data['errors'] )
				: $body,
		);
	}

	return array(
		'success'     => true,
		'status_code' => $status_code,
		'request_id'  => $request_id,
		'data'        => $data,
	);
}


/*
|--------------------------------------------------------------------------
| Update / Remove Amazon Cart Item
|--------------------------------------------------------------------------
*/

function wp_amazon_modify_cart_item( $item_id, $quantity ) {

	$item_id  = sanitize_text_field( $item_id );
	$quantity = (int) $quantity;

	if ( empty( $item_id ) ) {

		return array(
			'success' => false,
			'message' => 'Amazon cart item ID is missing.',
		);
	}

	if ( $quantity < 0 ) {
		$quantity = 0;
	}

	$access_token = wp_amazon_get_access_token();

	if ( is_wp_error( $access_token ) || empty( $access_token ) ) {

		return array(
			'success' => false,
			'message' => is_wp_error( $access_token )
				? $access_token->get_error_message()
				: 'Amazon access token is missing.',
		);
	}

	$url = sprintf(
		'https://na.business-api.amazon.com/cart/2025-04-30/carts/%s/items?region=%s',
		rawurlencode( AMAZON_CART_ID ),
		AMAZON_CART_REGION
	);

	$payload = array(
		'items' => array(
			array(
				'itemId'   => $item_id,
				'quantity' => $quantity,
			),
		),
	);

	$response = wp_remote_request(
		$url,
		array(
			'method'  => 'PATCH',
			'timeout' => 30,

			'headers' => array(
				'x-amz-access-token' => $access_token,
				'x-amz-user-email'   => AMAZON_USER_EMAIL,
				'Accept'             => 'application/json',
				'Content-Type'       => 'application/json',
			),

			'body' => wp_json_encode( $payload ),
		)
	);

	if ( is_wp_error( $response ) ) {

		return array(
			'success' => false,
			'message' => $response->get_error_message(),
		);
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = wp_remote_retrieve_body( $response );
	$data        = json_decode( $body, true );

	$request_id = wp_remote_retrieve_header(
		$response,
		'x-amzn-requestid'
	);

	if ( empty( $request_id ) ) {
		$request_id = wp_remote_retrieve_header(
			$response,
			'x-amzn-request-id'
		);
	}

	if ( $status_code < 200 || $status_code >= 300 ) {

		$message = 'Amazon cart update failed.';

		if ( ! empty( $data['errors'] ) ) {
			$message = wp_json_encode( $data['errors'] );
		} elseif ( ! empty( $body ) ) {
			$message = $body;
		}

		return array(
			'success'     => false,
			'status_code' => $status_code,
			'request_id'  => $request_id,
			'message'     => $message,
			'response'    => $data,
		);
	}

	return array(
		'success'     => true,
		'status_code' => $status_code,
		'request_id'  => $request_id,
		'message'     => $quantity === 0
			? 'Item removed from Amazon cart.'
			: 'Cart quantity updated successfully.',
		'data'        => $data,
	);
}


/*
|--------------------------------------------------------------------------
| Handle Update / Remove
|--------------------------------------------------------------------------
*/

$cart_action_result = null;

if (
	isset( $_POST['amazon_cart_action'] )
	&& in_array(
		$_POST['amazon_cart_action'],
		array( 'update_quantity', 'remove_item' ),
		true
	)
) {

	if (
		! isset( $_POST['amazon_cart_nonce'] )
		|| ! wp_verify_nonce(
			sanitize_text_field(
				wp_unslash( $_POST['amazon_cart_nonce'] )
			),
			'amazon_cart_modify'
		)
	) {

		$cart_action_result = array(
			'success' => false,
			'message' => 'Security verification failed. Please refresh the page and try again.',
		);

	} else {

		$item_id = isset( $_POST['item_id'] )
			? sanitize_text_field(
				wp_unslash( $_POST['item_id'] )
			)
			: '';

		if ( $_POST['amazon_cart_action'] === 'remove_item' ) {

			$quantity = 0;

		} else {

			$quantity = isset( $_POST['quantity'] )
				? absint( $_POST['quantity'] )
				: 1;

			if ( $quantity < 1 ) {
				$quantity = 1;
			}
		}

		$cart_action_result = wp_amazon_modify_cart_item(
			$item_id,
			$quantity
		);
	}
}


/*
|--------------------------------------------------------------------------
| Get Latest Cart
|--------------------------------------------------------------------------
*/

$cart_response = wp_amazon_get_cart_items();
$wc_order_id   = function_exists( 'wcss_amazon_get_request_order_id' ) ? wcss_amazon_get_request_order_id() : 0;

// Persist current Amazon cart against the related WooCommerce order (if any).
if (
	$wc_order_id
	&& is_array( $cart_response )
	&& ! empty( $cart_response['success'] )
	&& function_exists( 'wcss_amazon_save_order_snapshot' )
	&& function_exists( 'wcss_amazon_normalize_cart_items' )
) {
	$items = array();
	if ( ! empty( $cart_response['data']['items'] ) && is_array( $cart_response['data']['items'] ) ) {
		$items = $cart_response['data']['items'];
	} elseif ( ! empty( $cart_response['items'] ) && is_array( $cart_response['items'] ) ) {
		$items = $cart_response['items'];
	}

	wcss_amazon_save_order_snapshot(
		$wc_order_id,
		array(
			'status'     => 'cart_saved',
			'source'     => 'amazon_cart_page',
			'cart_id'    => defined( 'AMAZON_CART_ID' ) ? AMAZON_CART_ID : '',
			'cart_items' => wcss_amazon_normalize_cart_items( $items ),
			'cart_raw'   => $cart_response['data'] ?? null,
		)
	);
}

$cart_items = array();

if ( ! empty( $cart_response['success'] ) ) {

	$cart_data = $cart_response['data'];

	if (
		isset( $cart_data['items'] )
		&& is_array( $cart_data['items'] )
	) {

		$cart_items = $cart_data['items'];
	}
}


get_header();

?>

<div class="amazon-cart-page">

	<div class="amazon-cart-container">


		<div class="amazon-cart-header">

			<div>

				<h1>
					Amazon Cart
				</h1>

				<p>
					Items currently added to your Amazon Business cart.
				</p>

			</div>

			<div class="amazon-cart-id" style="display: none;">

				<strong>
					Cart ID:
				</strong>

				<?php echo esc_html( AMAZON_CART_ID ); ?>

			</div>

		</div>


		<?php if ( ! empty( $cart_action_result ) ) : ?>

			<div
				class="amazon-cart-message <?php echo ! empty( $cart_action_result['success'] ) ? 'success' : 'error'; ?>"
			>

				<strong>
					<?php
					echo ! empty( $cart_action_result['success'] )
						? 'Success'
						: 'Error';
					?>
				</strong>

				<p>
					<?php
					echo esc_html(
						isset( $cart_action_result['message'] )
							? $cart_action_result['message']
							: 'Unknown response.'
					);
					?>
				</p>

				<?php if ( ! empty( $cart_action_result['request_id'] ) ) : ?>

					<div class="amazon-cart-message-meta">

						Amazon Request ID:

						<code>
							<?php
							echo esc_html(
								$cart_action_result['request_id']
							);
							?>
						</code>

					</div>

				<?php endif; ?>

			</div>

		<?php endif; ?>


		<?php if ( ! empty( $cart_response['success'] ) ) : ?>


			<?php if ( ! empty( $cart_items ) ) : ?>


				<div class="amazon-cart-summary">

					<div class="amazon-cart-count">

						<strong>
							<?php echo esc_html( count( $cart_items ) ); ?>
						</strong>

						<span>
							<?php
							echo count( $cart_items ) === 1
								? 'Item'
								: 'Items';
							?>
						</span>

					</div>

				</div>


				<div class="amazon-cart-items">

					<?php 
                    $cart_total = 0;
                    foreach ( $cart_items as $index => $item ) : ?>

						<?php

						$asin = '';

						if ( isset( $item['asin'] ) ) {

							$asin = $item['asin'];
                            $woo_product_ids = get_posts([
                                'post_type'      => 'product',
                                'post_status'    => 'any',
                                'meta_key'       => 'amazon_asin',
                                'meta_value'     => $asin,
                                'posts_per_page' => 1,
                                'fields'         => 'ids',
                            ]);

                            $woo_product_id = $woo_product_ids[0] ?? 0;

						} elseif ( isset( $item['productIdentifier'] ) ) {

							$asin = $item['productIdentifier'];
                            $woo_product_ids = get_posts([
                                'post_type'      => 'product',
                                'post_status'    => 'any',
                                'meta_key'       => 'amazon_asin',
                                'meta_value'     => $asin,
                                'posts_per_page' => 1,
                                'fields'         => 'ids',
                            ]);

                            $woo_product_id = $woo_product_ids[0] ?? 0;
						}
                        $product_title = get_the_title( $woo_product_id );


						$item_id = isset( $item['itemId'] )
							? $item['itemId']
							: '';


						$buying_option_id = isset(
							$item['buyingOptionIdentifier']
						)
							? $item['buyingOptionIdentifier']
							: '';


						$quantity = isset( $item['quantity'] )
							? (int) $item['quantity']
							: 1;


						if ( $quantity < 1 ) {
							$quantity = 1;
						}


						$is_available = isset(
							$item['isItemAvailable']
						)
							? (bool) $item['isItemAvailable']
							: null;


						$price_amount = '';

						$currency = '';


						if ( isset( $item['price']['amount'] ) ) {
							$price_amount = $item['price']['amount'];
                            $cart_total = $cart_total + ( $price_amount * $quantity );
						}


						if ( isset( $item['price']['currencyCode'] ) ) {
							$currency = $item['price']['currencyCode'];
						}


						$added_date = isset(
							$item['addedToCartDate']
						)
							? $item['addedToCartDate']
							: '';


						$modified_date = isset(
							$item['modifiedDate']
						)
							? $item['modifiedDate']
							: '';

						?>


						<div class="amazon-cart-item">


							<div class="amazon-cart-item-number">

								<?php
								echo esc_html( $index + 1 );
								?>

							</div>


							<div class="amazon-cart-item-main">


								<div class="amazon-cart-item-title">

									<h2>
										<?php echo $product_title; ?>
									</h2>

									<?php if ( $asin ) : ?>

										<div class="amazon-cart-asin">

											ASIN:

											<strong>
												<?php
												echo esc_html( $asin );
												?>
											</strong>

										</div>

									<?php endif; ?>

								</div>


								<div class="amazon-cart-details">


									<?php if ( $price_amount !== '' ) : ?>

										<div class="amazon-cart-detail">

											<span class="label">
												Price
											</span>

											<strong>

												<?php
												echo esc_html(
													$currency
													. ' '
													. $price_amount
												);
												?>

											</strong>

										</div>

									<?php endif; ?>


									<?php if ( $is_available !== null ) : ?>

										<div class="amazon-cart-detail">

											<span class="label">
												Availability
											</span>

											<strong
												class="<?php echo $is_available ? 'available' : 'unavailable'; ?>"
											>

												<?php
												echo $is_available
													? 'Available'
													: 'Unavailable';
												?>

											</strong>

										</div>

									<?php endif; ?>


									<div class="amazon-cart-detail" style="display: none;">

										<span class="label">
											Item ID
										</span>

										<strong class="amazon-item-id">

											<?php
											echo esc_html( $item_id );
											?>

										</strong>

									</div>


								</div>


								<div class="amazon-cart-actions">


									<form
										method="post"
										class="amazon-update-form"
									>

										<?php wp_nonce_field(
											'amazon_cart_modify',
											'amazon_cart_nonce'
										); ?>


										<input
											type="hidden"
											name="amazon_cart_action"
											value="update_quantity"
										/>


										<input
											type="hidden"
											name="item_id"
											value="<?php echo esc_attr( $item_id ); ?>"
										/>


										<div class="amazon-quantity-wrapper">

											<span class="amazon-action-label">
												Quantity
											</span>


											<div class="amazon-quantity-control">

												<button
													type="button"
													class="amazon-qty-button amazon-qty-minus"
													aria-label="Decrease quantity"
												>
													−
												</button>


												<input
													type="number"
													name="quantity"
													class="amazon-quantity-input"
													value="<?php echo esc_attr( $quantity ); ?>"
													min="1"
													step="1"
												/>


												<button
													type="button"
													class="amazon-qty-button amazon-qty-plus"
													aria-label="Increase quantity"
												>
													+
												</button>

											</div>


											<button
												type="submit"
												class="amazon-update-button"
											>
												Update
											</button>

										</div>

									</form>


									<form
										method="post"
										class="amazon-remove-form"
										onsubmit="return confirm('Are you sure you want to remove this item from the Amazon cart?');"
									>

										<?php wp_nonce_field(
											'amazon_cart_modify',
											'amazon_cart_nonce'
										); ?>


										<input
											type="hidden"
											name="amazon_cart_action"
											value="remove_item"
										/>


										<input
											type="hidden"
											name="item_id"
											value="<?php echo esc_attr( $item_id ); ?>"
										/>


										<button
											type="submit"
											class="amazon-remove-button"
										>
											Remove
										</button>

									</form>


								</div>


								<div class="amazon-cart-identifiers" style="display: none;">


									<?php if ( $item_id ) : ?>

										<div>

											<span>
												Item ID:
											</span>

											<code>
												<?php
												echo esc_html( $item_id );
												?>
											</code>

										</div>

									<?php endif; ?>


									<?php if ( $buying_option_id ) : ?>

										<div>

											<span>
												Buying Option:
											</span>

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


								<div class="amazon-cart-dates">


									<?php if ( $added_date ) : ?>

										<div>

											<strong>
												Added:
											</strong>

											<?php

											$timestamp = strtotime(
												$added_date
											);

											echo $timestamp
												? esc_html(
													wp_date(
														'F j, Y g:i A',
														$timestamp
													)
												)
												: esc_html( $added_date );

											?>

										</div>

									<?php endif; ?>


									<?php if ( $modified_date ) : ?>

										<div>

											<strong>
												Modified:
											</strong>

											<?php

											$timestamp = strtotime(
												$modified_date
											);

											echo $timestamp
												? esc_html(
													wp_date(
														'F j, Y g:i A',
														$timestamp
													)
												)
												: esc_html( $modified_date );

											?>

										</div>

									<?php endif; ?>


								</div>


							</div>

						</div>


					<?php endforeach; ?>

				</div>
                <div class="amazon-cart-item">
                    <h4> <strong>Cart Total: CAD</strong> <?php echo $cart_total; ?> </h4>
                </div>


				<div class="amazon-checkout-wrapper">

					<a
						href="<?php echo esc_url( $wc_order_id ? add_query_arg( 'order-id', $wc_order_id, home_url( '/amazon-checkout/' ) ) : home_url( '/amazon-checkout/' ) ); ?>"
						class="amazon-place-order-button"
					>
						Checkout
					</a>

				</div>


			<?php else : ?>


				<div class="amazon-cart-empty">

					<div class="amazon-cart-empty-icon">
						🛒
					</div>

					<h2>
						Your Amazon cart is empty
					</h2>

					<p>
						No items are currently in this Amazon Business cart.
					</p>

				</div>


			<?php endif; ?>


		<?php else : ?>


			<div class="amazon-cart-error">

				<h2>
					Unable to load Amazon cart
				</h2>

				<p>
					<?php
					echo esc_html(
						isset( $cart_response['message'] )
							? $cart_response['message']
							: 'Unknown Amazon API error.'
					);
					?>
				</p>


				<?php if ( ! empty( $cart_response['status_code'] ) ) : ?>

					<p>

						<strong>
							HTTP Status:
						</strong>

						<?php
						echo esc_html(
							$cart_response['status_code']
						);
						?>

					</p>

				<?php endif; ?>


				<?php if ( ! empty( $cart_response['request_id'] ) ) : ?>

					<p>

						<strong>
							Amazon Request ID:
						</strong>

						<code>
							<?php
							echo esc_html(
								$cart_response['request_id']
							);
							?>
						</code>

					</p>

				<?php endif; ?>

			</div>


		<?php endif; ?>


	</div>

</div>


<style>

/* Page */

.amazon-cart-page {
	width: 100%;
	background: #f5f6f7;
	padding: 50px 20px;
	box-sizing: border-box;
}

.amazon-cart-container {
	max-width: 1100px;
	margin: 0 auto;
}


/* Header */

.amazon-cart-header {
	background: #fff;
	border-radius: 12px;
	padding: 30px;
	margin-bottom: 25px;

	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 25px;

	box-shadow: 0 2px 10px rgba(0,0,0,.06);
}

.amazon-cart-header h1 {
	margin: 0 0 8px;
	font-size: 32px;
	line-height: 1.2;
}

.amazon-cart-header p {
	margin: 0;
	color: #666;
}

.amazon-cart-id {
	font-size: 13px;
	color: #666;
	word-break: break-all;
	text-align: right;
}

.amazon-cart-id strong {
	color: #222;
}


/* Messages */

.amazon-cart-message {
	background: #fff;
	border-radius: 10px;
	padding: 18px 20px;
	margin-bottom: 20px;
	border-left: 5px solid #333;
	box-shadow: 0 2px 10px rgba(0,0,0,.05);
}

.amazon-cart-message.success {
	border-left-color: #16803c;
}

.amazon-cart-message.error {
	border-left-color: #c62828;
}

.amazon-cart-message strong {
	font-size: 17px;
}

.amazon-cart-message p {
	margin: 5px 0 8px;
}

.amazon-cart-message-meta {
	margin-top: 5px;
	font-size: 13px;
	color: #666;
}


/* Summary */

.amazon-cart-summary {
	margin-bottom: 15px;
}

.amazon-cart-count {
	display: inline-flex;
	align-items: center;
	gap: 6px;

	background: #fff;
	padding: 10px 16px;
	border-radius: 8px;

	box-shadow: 0 2px 8px rgba(0,0,0,.05);
}

.amazon-cart-count strong {
	font-size: 20px;
}


/* Items */

.amazon-cart-items {
	display: flex;
	flex-direction: column;
	gap: 15px;
}

.amazon-cart-item {
	background: #fff;
	border-radius: 12px;
	padding: 25px;

	display: flex;
	gap: 20px;

	box-shadow: 0 2px 10px rgba(0,0,0,.06);
}

.amazon-cart-item-number {
	width: 42px;
	height: 42px;
	border-radius: 50%;

	background: #f0f0f0;

	display: flex;
	align-items: center;
	justify-content: center;

	font-weight: 700;
	flex: 0 0 42px;
}

.amazon-cart-item-main {
	flex: 1;
	min-width: 0;
}


/* Product */

.amazon-cart-item-title {
	margin-bottom: 20px;
}

.amazon-cart-item-title h2 {
	margin: 0 0 7px;
	font-size: 21px;
}

.amazon-cart-asin {
	font-size: 14px;
	color: #666;
}


/* Details */

.amazon-cart-details {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 15px;
	margin-bottom: 20px;
}

.amazon-cart-detail {
	border: 1px solid #e5e5e5;
	border-radius: 8px;
	padding: 14px;
	min-width: 0;
}

.amazon-cart-detail .label {
	display: block;
	font-size: 12px;
	color: #777;
	margin-bottom: 5px;
	text-transform: uppercase;
	letter-spacing: .3px;
}

.amazon-cart-detail strong {
	font-size: 16px;
	word-break: break-word;
}

.amazon-cart-detail .available {
	color: #16803c;
}

.amazon-cart-detail .unavailable {
	color: #c62828;
}

.amazon-item-id {
	font-family: monospace;
	font-size: 12px !important;
}


/* Actions */

.amazon-cart-actions {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 15px;

	margin-bottom: 20px;
	padding: 16px;

	background: #f8f8f8;
	border-radius: 10px;
}

.amazon-update-form {
	margin: 0;
}

.amazon-quantity-wrapper {
	display: flex;
	align-items: center;
	gap: 12px;
}

.amazon-action-label {
	font-size: 13px;
	font-weight: 700;
	color: #444;
}

.amazon-quantity-control {
	display: inline-flex;
	align-items: center;

	border: 1px solid #d7d7d7;
	border-radius: 7px;

	background: #fff;
	overflow: hidden;
}

.amazon-qty-button {
	width: 38px;
	height: 38px;

	border: 0;
	background: #fff;

	font-size: 20px;
	font-weight: 600;

	cursor: pointer;
	padding: unset !important;
}

.amazon-qty-button:hover {
	background: #eee;
}

.amazon-quantity-input {
	width: 55px;
	height: 38px;

	border: 0 !important;
	border-left: 1px solid #ddd !important;
	border-right: 1px solid #ddd !important;

	text-align: center;

	font-size: 15px;

	padding: 0 !important;
	margin: 0 !important;

	background: #fff !important;
}

.amazon-quantity-input::-webkit-outer-spin-button,
.amazon-quantity-input::-webkit-inner-spin-button {
	-webkit-appearance: none;
	margin: 0;
}

.amazon-quantity-input[type=number] {
	-moz-appearance: textfield;
}

.amazon-update-button {
	border: 0;
	border-radius: 7px;

	padding: 10px 16px;

	background: #111;
	color: #fff;

	font-size: 14px;
	font-weight: 700;

	cursor: pointer;
}

.amazon-update-button:hover {
	background: #333;
}

.amazon-remove-form {
	margin: 0;
}

.amazon-remove-button {
	border: 1px solid #c62828;
	border-radius: 7px;

	padding: 10px 16px;

	background: #fff;
	color: #c62828;

	font-size: 14px;
	font-weight: 700;

	cursor: pointer;
}

.amazon-remove-button:hover {
	background: #c62828;
	color: #fff;
}


/* Identifiers */

.amazon-cart-identifiers {
	background: #f7f7f7;
	border-radius: 8px;
	padding: 14px;

	display: flex;
	flex-direction: column;
	gap: 8px;

	margin-bottom: 15px;
}

.amazon-cart-identifiers div {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	font-size: 13px;
}

.amazon-cart-identifiers span {
	font-weight: 600;
	min-width: 110px;
}

.amazon-cart-identifiers code {
	word-break: break-all;
	font-family: monospace;
	font-size: 12px;
}


/* Dates */

.amazon-cart-dates {
	display: flex;
	gap: 25px;
	flex-wrap: wrap;

	font-size: 13px;
	color: #666;
}


/* Checkout */

.amazon-checkout-wrapper {
	margin-top: 20px;
}

.amazon-place-order-button {
	display: block;
	width: 100%;
	box-sizing: border-box;

	padding: 15px 20px;

	border: 0;
	border-radius: 8px;

	background: #111;
	color: #fff !important;

	font-size: 16px;
	font-weight: 700;

	text-align: center;
	text-decoration: none !important;
	cursor: pointer;
}

.amazon-place-order-button:hover {
	background: #333;
	color: #fff !important;
}


/* Empty / Error */

.amazon-cart-empty,
.amazon-cart-error {
	background: #fff;
	border-radius: 12px;

	padding: 60px 30px;

	text-align: center;

	box-shadow: 0 2px 10px rgba(0,0,0,.06);
}

.amazon-cart-empty-icon {
	font-size: 45px;
	margin-bottom: 15px;
}

.amazon-cart-empty h2,
.amazon-cart-error h2 {
	margin: 0 0 10px;
}

.amazon-cart-empty p,
.amazon-cart-error p {
	color: #666;
}

.amazon-cart-error {
	border-left: 5px solid #c62828;
}


/* Mobile */

@media (max-width: 700px) {

	.amazon-cart-page {
		padding: 25px 12px;
	}

	.amazon-cart-header {
		flex-direction: column;
		align-items: flex-start;
		padding: 22px;
	}

	.amazon-cart-id {
		text-align: left;
	}

	.amazon-cart-item {
		padding: 18px;
	}

	.amazon-cart-details {
		grid-template-columns: 1fr;
	}

	.amazon-cart-item-number {
		width: 34px;
		height: 34px;
		flex-basis: 34px;
	}

	.amazon-cart-actions {
		align-items: stretch;
		flex-direction: column;
	}

	.amazon-quantity-wrapper {
		flex-wrap: wrap;
	}

	.amazon-remove-form {
		width: 100%;
	}

	.amazon-remove-button {
		width: 100%;
	}

}
</style>


<script>
document.addEventListener('DOMContentLoaded', function () {

	/*
	|--------------------------------------------------------------------------
	| Quantity +/- buttons
	|--------------------------------------------------------------------------
	*/

	document.querySelectorAll('.amazon-cart-item').forEach(function (item) {

		const input = item.querySelector('.amazon-quantity-input');
		const minus = item.querySelector('.amazon-qty-minus');
		const plus = item.querySelector('.amazon-qty-plus');

		if (!input) {
			return;
		}

		if (minus) {

			minus.addEventListener('click', function () {

				let quantity = parseInt(input.value, 10) || 1;

				quantity--;

				if (quantity < 1) {
					quantity = 1;
				}

				input.value = quantity;

			});

		}


		if (plus) {

			plus.addEventListener('click', function () {

				let quantity = parseInt(input.value, 10) || 1;

				quantity++;

				input.value = quantity;

			});

		}


		input.addEventListener('change', function () {

			let quantity = parseInt(input.value, 10) || 1;

			if (quantity < 1) {
				quantity = 1;
			}

			input.value = quantity;

		});

	});


	/*
	|--------------------------------------------------------------------------
	| Disable button during request
	|--------------------------------------------------------------------------
	*/

	document.querySelectorAll(
		'.amazon-update-form, .amazon-remove-form'
	).forEach(function (form) {

		form.addEventListener('submit', function () {

			const submitButton = form.querySelector(
				'button[type="submit"]'
			);

			if (!submitButton) {
				return;
			}

			submitButton.disabled = true;

			if (
				form.classList.contains('amazon-remove-form')
			) {

				submitButton.textContent = 'Removing...';

			} else {

				submitButton.textContent = 'Updating...';

			}

		});

	});

});
</script>


<?php get_footer(); ?>

