<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Prefills checkout billing from the assigned store, locks address fields
 * (Aman 5 Nov 2026 — store cannot change ship-to), and stamps store meta on the order.
 */
class WCSS_Checkout_Store_Lock {

	/** Billing keys that always come from the store record. */
	const LOCKED_KEYS = [
		'billing_company',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_country',
		'billing_phone',
	];

	public function __construct() {
		add_filter( 'woocommerce_checkout_fields', [ $this, 'prefill_checkout_fields' ], 20 );
		add_filter( 'woocommerce_checkout_get_value', [ $this, 'prefill_checkout_value' ], 20, 2 );
		add_filter( 'woocommerce_checkout_posted_data', [ $this, 'force_posted_store_address' ], 20 );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_store_address' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'attach_store_meta_to_order' ], 5, 2 );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'apply_store_address_to_order' ], 6, 2 );
		add_action( 'woocommerce_before_checkout_form', [ $this, 'render_address_notice' ], 8 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ], 30 );

		add_filter( 'woocommerce_cart_needs_shipping', '__return_false', 99 );
		add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false', 99 );
		add_filter( 'woocommerce_coupons_enabled', '__return_false', 99 );
	}

	/**
	 * Active store for the current user + billing defaults + snapshot.
	 *
	 * @return array{0:int,1:array<string,string>,2:array<string,string>}
	 */
	private function get_user_store() {
		$store_id = class_exists( 'WCSS_User_Store_Map' ) ? (int) WCSS_User_Store_Map::get_user_store_id() : 0;
		if ( ! $store_id ) {
			return [ 0, [], [] ];
		}

		$title    = (string) get_the_title( $store_id );
		$code     = (string) get_post_meta( $store_id, WCSS_Store_CPT::META_CODE, true );
		$addr1    = (string) get_post_meta( $store_id, WCSS_Store_CPT::META_ADDR, true );
		$city     = (string) get_post_meta( $store_id, WCSS_Store_CPT::META_CITY, true );
		$state    = $this->normalize_province( (string) get_post_meta( $store_id, WCSS_Store_CPT::META_STATE, true ) );
		$postcode = (string) get_post_meta( $store_id, WCSS_Store_CPT::META_POSTCODE, true );
		$country  = strtoupper( (string) get_post_meta( $store_id, WCSS_Store_CPT::META_COUNTRY, true ) ) ?: 'CA';
		$phone    = (string) get_post_meta( $store_id, WCSS_Store_CPT::META_PHONE, true );

		$user  = wp_get_current_user();
		$email = ( $user && $user->user_email ) ? $user->user_email : '';
		$first = ( $user && $user->first_name ) ? $user->first_name : '';
		$last  = ( $user && $user->last_name ) ? $user->last_name : '';

		$vals = [
			'billing_company'    => $title,
			'billing_first_name' => $first,
			'billing_last_name'  => $last,
			'billing_email'      => $email,
			'billing_phone'      => $phone,
			'billing_address_1'  => $addr1,
			'billing_address_2'  => '',
			'billing_city'       => $city,
			'billing_postcode'   => $postcode,
			'billing_state'      => $state,
			'billing_country'    => $country,
		];

		$snapshot = [
			'store_name'     => $title,
			'store_code'     => $code,
			'store_address'  => $addr1,
			'store_city'     => $city,
			'store_state'    => $state,
			'store_postcode' => $postcode,
			'store_country'  => $country,
			'store_phone'    => $phone,
		];

		return [ $store_id, $vals, $snapshot ];
	}

	/**
	 * Map full province names to WooCommerce CA codes.
	 */
	private function normalize_province( string $state ): string {
		if ( function_exists( 'wcss_amazon_checkout_normalize_province' ) ) {
			$n = wcss_amazon_checkout_normalize_province( $state );
			if ( $n !== '' ) {
				return $n;
			}
		}
		$raw = strtoupper( trim( $state ) );
		if ( $raw === '' ) {
			return '';
		}
		if ( strlen( $raw ) === 2 ) {
			return $raw;
		}
		$map = [
			'ALBERTA'                   => 'AB',
			'BRITISH COLUMBIA'          => 'BC',
			'MANITOBA'                  => 'MB',
			'NEW BRUNSWICK'             => 'NB',
			'NEWFOUNDLAND AND LABRADOR' => 'NL',
			'NEWFOUNDLAND'              => 'NL',
			'NOVA SCOTIA'               => 'NS',
			'NORTHWEST TERRITORIES'     => 'NT',
			'NUNAVUT'                   => 'NU',
			'ONTARIO'                   => 'ON',
			'PRINCE EDWARD ISLAND'      => 'PE',
			'QUEBEC'                    => 'QC',
			'SASKATCHEWAN'              => 'SK',
			'YUKON'                     => 'YT',
			'YUKON TERRITORY'           => 'YT',
		];
		return $map[ $raw ] ?? $state;
	}

	/** True when the store has every field checkout needs to ship. */
	public static function store_address_complete( int $store_id ): bool {
		if ( ! $store_id ) {
			return false;
		}
		$need = [
			WCSS_Store_CPT::META_ADDR,
			WCSS_Store_CPT::META_CITY,
			WCSS_Store_CPT::META_STATE,
			WCSS_Store_CPT::META_POSTCODE,
			WCSS_Store_CPT::META_PHONE,
		];
		foreach ( $need as $key ) {
			if ( trim( (string) get_post_meta( $store_id, $key, true ) ) === '' ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Prefill + lock store address fields. Name / email stay editable.
	 */
	public function prefill_checkout_fields( $fields ) {
		if ( ! is_user_logged_in() ) {
			return $fields;
		}

		unset( $fields['shipping'] );

		if ( isset( $fields['order']['order_comments'] ) ) {
			$fields['order']['order_comments']['label']       = __( 'Order notes', 'wcss' );
			$fields['order']['order_comments']['placeholder'] = __( 'Add any special instructions for admin', 'wcss' );
			$fields['order']['order_comments']['required']    = false;
		}

		list( $store_id, $vals ) = $this->get_user_store();
		if ( ! $store_id || empty( $fields['billing'] ) ) {
			return $fields;
		}

		// Theme/Woo may hide company — restore it so store name is visible and locked.
		if ( empty( $fields['billing']['billing_company'] ) ) {
			$fields['billing']['billing_company'] = [
				'label'    => __( 'Store / company', 'wcss' ),
				'required' => false,
				'class'    => [ 'form-row-wide' ],
				'priority' => 30,
				'type'     => 'text',
			];
		}

		foreach ( $vals as $key => $val ) {
			if ( ! isset( $fields['billing'][ $key ] ) ) {
				continue;
			}
			// Always use the store for locked keys; name/email only seed when empty.
			$current_default = $fields['billing'][ $key ]['default'] ?? null;
			if ( in_array( $key, self::LOCKED_KEYS, true ) || $current_default === '' || $current_default === null ) {
				$fields['billing'][ $key ]['default'] = $val;
			}
		}

		if ( isset( $fields['billing']['billing_company'] ) ) {
			$fields['billing']['billing_company']['label']    = __( 'Store / company', 'wcss' );
			// Mark required so Woo does not append "(optional)"; value is always forced from the store.
			$fields['billing']['billing_company']['required'] = true;
		}

		if ( isset( $fields['billing']['billing_phone'] ) ) {
			$fields['billing']['billing_phone']['label']    = __( 'Phone', 'wcss' );
			$fields['billing']['billing_phone']['required'] = true;
		}

		foreach ( self::LOCKED_KEYS as $key ) {
			if ( ! isset( $fields['billing'][ $key ] ) ) {
				continue;
			}
			$fields['billing'][ $key ]['custom_attributes'] = array_merge(
				(array) ( $fields['billing'][ $key ]['custom_attributes'] ?? [] ),
				[
					'readonly' => 'readonly',
					'aria-readonly' => 'true',
				]
			);
			$fields['billing'][ $key ]['class'] = array_values( array_unique( array_merge(
				(array) ( $fields['billing'][ $key ]['class'] ?? [] ),
				[ 'wcss-store-locked' ]
			) ) );
			// Country/state are <select> — readonly is ignored; mark for JS + CSS.
			if ( in_array( $key, [ 'billing_country', 'billing_state' ], true ) ) {
				$fields['billing'][ $key ]['custom_attributes']['data-wcss-lock'] = '1';
			}
		}

		// Address line 2 is unused for store ship-to — hide for a cleaner form.
		if ( isset( $fields['billing']['billing_address_2'] ) ) {
			unset( $fields['billing']['billing_address_2'] );
		}

		return $fields;
	}

	/**
	 * Always return store values for locked keys (ignore customer profile / POST drafts).
	 */
	public function prefill_checkout_value( $value, $input ) {
		if ( ! is_user_logged_in() ) {
			return $value;
		}
		list( $store_id, $vals ) = $this->get_user_store();
		if ( ! $store_id || ! isset( $vals[ $input ] ) ) {
			return $value;
		}
		if ( in_array( $input, self::LOCKED_KEYS, true ) ) {
			return $vals[ $input ];
		}
		if ( $value !== null && $value !== '' ) {
			return $value;
		}
		return $vals[ $input ];
	}

	/**
	 * Overwrite posted billing with the store address so edits cannot stick.
	 */
	public function force_posted_store_address( $data ) {
		if ( ! is_user_logged_in() || ! is_array( $data ) ) {
			return $data;
		}
		list( $store_id, $vals ) = $this->get_user_store();
		if ( ! $store_id ) {
			return $data;
		}
		foreach ( self::LOCKED_KEYS as $key ) {
			if ( isset( $vals[ $key ] ) ) {
				$data[ $key ] = $vals[ $key ];
			}
		}
		return $data;
	}

	/**
	 * Block checkout if the store record is missing ship-to fields.
	 */
	public function validate_store_address( $data, $errors ) {
		if ( ! is_user_logged_in() || ! $errors instanceof WP_Error ) {
			return;
		}
		list( $store_id ) = $this->get_user_store();
		if ( ! $store_id ) {
			$errors->add( 'wcss_no_store', __( 'Your account is not assigned to a store. Contact Supply Ops before ordering.', 'wcss' ) );
			return;
		}
		if ( ! self::store_address_complete( $store_id ) ) {
			$errors->add(
				'wcss_store_address',
				__( 'Your store address is incomplete (street, city, province, postal code and phone are required). Contact Supply Ops to update the store record — you cannot change the address at checkout.', 'wcss' )
			);
		}
	}

	/**
	 * Write store address onto the order billing fields.
	 */
	public function apply_store_address_to_order( $order, $data ) {
		if ( ! is_user_logged_in() || ! $order instanceof WC_Order ) {
			return;
		}
		list( $store_id, $vals ) = $this->get_user_store();
		if ( ! $store_id ) {
			return;
		}
		$map = [
			'billing_company'   => 'set_billing_company',
			'billing_address_1' => 'set_billing_address_1',
			'billing_address_2' => 'set_billing_address_2',
			'billing_city'      => 'set_billing_city',
			'billing_state'     => 'set_billing_state',
			'billing_postcode'  => 'set_billing_postcode',
			'billing_country'   => 'set_billing_country',
			'billing_phone'     => 'set_billing_phone',
		];
		foreach ( $map as $key => $setter ) {
			if ( method_exists( $order, $setter ) ) {
				$order->{$setter}( (string) ( $vals[ $key ] ?? '' ) );
			}
		}
		// Mirror to shipping for vendor payloads that read shipping.
		$order->set_shipping_company( (string) ( $vals['billing_company'] ?? '' ) );
		$order->set_shipping_address_1( (string) ( $vals['billing_address_1'] ?? '' ) );
		$order->set_shipping_address_2( '' );
		$order->set_shipping_city( (string) ( $vals['billing_city'] ?? '' ) );
		$order->set_shipping_state( (string) ( $vals['billing_state'] ?? '' ) );
		$order->set_shipping_postcode( (string) ( $vals['billing_postcode'] ?? '' ) );
		$order->set_shipping_country( (string) ( $vals['billing_country'] ?? 'CA' ) );
		$fn = is_array( $data ) ? (string) ( $data['billing_first_name'] ?? '' ) : '';
		$ln = is_array( $data ) ? (string) ( $data['billing_last_name'] ?? '' ) : '';
		if ( $fn !== '' ) {
			$order->set_shipping_first_name( $fn );
		}
		if ( $ln !== '' ) {
			$order->set_shipping_last_name( $ln );
		}
	}

	/**
	 * Attach store snapshot to order meta for auditing / policy.
	 */
	public function attach_store_meta_to_order( $order, $data ) {
		if ( ! is_user_logged_in() || ! $order instanceof WC_Order ) {
			return;
		}
		list( $store_id, $vals, $snapshot ) = $this->get_user_store();
		if ( ! $store_id ) {
			return;
		}

		$order->update_meta_data( '_wcss_store_id', $store_id );
		$order->update_meta_data( '_wcss_store_name', $snapshot['store_name'] );
		$order->update_meta_data( '_wcss_store_code', $snapshot['store_code'] );
		$order->update_meta_data( '_wcss_store_address', $snapshot['store_address'] );
		$order->update_meta_data( '_wcss_store_city', $snapshot['store_city'] );
		$order->update_meta_data( '_wcss_store_state', $snapshot['store_state'] );
		$order->update_meta_data( '_wcss_store_postcode', $snapshot['store_postcode'] );
		$order->update_meta_data( '_wcss_store_country', $snapshot['store_country'] );
		$order->update_meta_data( '_wcss_store_phone', $snapshot['store_phone'] );
	}

	/** Short note above the checkout form. */
	public function render_address_notice() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		list( $store_id ) = $this->get_user_store();
		if ( ! $store_id ) {
			return;
		}
		echo '<div class="woocommerce-info wcss-store-address-note" role="status">';
		echo esc_html__( 'Ship-to address comes from your store record and cannot be changed here. Contact Supply Ops if it needs an update.', 'wcss' );
		echo '</div>';
	}

	/** Styles + lock selects that ignore HTML readonly. */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		$css = '
.woocommerce-checkout .wcss-store-locked input[readonly],
.woocommerce-checkout .wcss-store-locked textarea[readonly],
.woocommerce-checkout .form-row.wcss-store-locked input[readonly],
.woocommerce-checkout .form-row.wcss-store-locked textarea[readonly]{
  background:#f3f4f6!important;color:#374151!important;cursor:default;box-shadow:none;
}
.woocommerce-checkout .form-row.wcss-store-locked select[data-wcss-lock],
.woocommerce-checkout .form-row.wcss-store-locked .select2-container{
  pointer-events:none;opacity:.92;
}
.woocommerce-checkout .woocommerce-info.wcss-store-address-note,
.woocommerce .woocommerce-info.wcss-store-address-note,
.wcss-store-address-note{
  margin:0 0 1.25rem;
  padding:14px 18px;
  background:#121417!important;
  color:#fff!important;
  border:2px solid #C8372D!important;
  border-radius:10px;
  box-shadow:none!important;
  font-size:14.5px;
  font-weight:600;
  line-height:1.45;
}
.woocommerce-checkout .woocommerce-info.wcss-store-address-note::before,
.woocommerce .woocommerce-info.wcss-store-address-note::before,
.wcss-store-address-note::before{
  display:none!important;
  content:none!important;
}
';
		wp_register_style( 'wcss-checkout-store-lock', false, [], '1.1' );
		wp_enqueue_style( 'wcss-checkout-store-lock' );
		wp_add_inline_style( 'wcss-checkout-store-lock', $css );

		$js = <<<'JS'
(function($){
  function lock(){
    $('.woocommerce-checkout .wcss-store-locked [data-wcss-lock="1"], .woocommerce-checkout select[data-wcss-lock="1"]').each(function(){
      var $el=$(this);
      $el.prop('disabled', false); // keep value in POST
      $el.attr('tabindex','-1');
      $el.on('mousedown.wcsslock keydown.wcsslock change.wcsslock', function(e){ e.preventDefault(); e.stopImmediatePropagation(); return false; });
      if ($el.data('select2')) {
        $el.next('.select2').css({'pointer-events':'none','opacity':'0.92'});
      }
    });
  }
  $(lock);
  $(document.body).on('updated_checkout country_to_state_changed', lock);
})(jQuery);
JS;
		wp_register_script( 'wcss-checkout-store-lock', false, [ 'jquery' ], '1.0', true );
		wp_enqueue_script( 'wcss-checkout-store-lock' );
		wp_add_inline_script( 'wcss-checkout-store-lock', $js );
	}
}
