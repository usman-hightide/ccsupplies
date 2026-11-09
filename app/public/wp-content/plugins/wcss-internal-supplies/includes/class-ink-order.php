<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parallel Ink order flow (Plan B, 5 Nov 2026).
 *
 * The shop shows everything; a cart's contents decide the order type.
 * Stationery = normal policy and auto-approve.
 * Ink = ink categories, always Alyshia approval, then vendor submit. Ordered in its own cart.
 */
class WCSS_Ink_Order {

	const META         = '_wcss_order_type';
	const TYPE_INK     = 'ink';
	const TYPE_STATION = 'stationery';
	const OPT_CATS     = 'ink_category_ids';

	/** Set when adding a product replaced a cart of the other type. */
	private static $cleared_note = '';

	public function __construct() {
		// Classic form / AJAX / Store API / session restore still fire this.
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 20, 3 );
		// WC 10+ no longer runs that filter inside WC_Cart::add_to_cart — block there too.
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'clear_other_type_on_add' ], 5, 3 );
		add_filter( 'wc_add_to_cart_message_html', [ $this, 'add_cleared_note' ], 30 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', [ $this, 'store_api_validate' ], 10, 2 );
		add_action( 'woocommerce_check_cart_items', [ $this, 'validate_cart_contents' ], 20 );

		add_action( 'woocommerce_checkout_create_order', [ $this, 'stamp_order_type' ], 4, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', [ $this, 'stamp_order_type_api' ], 4 );

		add_filter( 'cc_auto_vendor_submit_policy', [ $this, 'auto_submit_gate' ], 5, 2 );
		add_action( 'wcss_order_policy_recorded', [ $this, 'after_policy_recorded' ], 5, 2 );
		add_action( 'woocommerce_before_checkout_form', [ $this, 'checkout_ink_notice' ], 6 );
		add_action( 'wp_footer', [ $this, 'render_styles' ], 5 );

	}

	/* ---------------- what's in the cart ---------------- */

	/** @return int[] Product IDs in the cart. The cart isn't loaded before wp_loaded. */
	private static function cart_product_ids(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! did_action( 'wp_loaded' ) ) {
			return [];
		}
		return array_values( array_filter( array_map( 'intval', wp_list_pluck( WC()->cart->get_cart(), 'product_id' ) ) ) );
	}

	/** True when the cart holds ink, which makes this an ink order. */
	public static function cart_is_ink(): bool {
		foreach ( self::cart_product_ids() as $pid ) {
			if ( self::product_is_ink( $pid ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------- categories ---------------- */

	/**
	 * Categories with "ink" as a word in the name or slug, so a new "Canon Ink" works from the
	 * product screen. Whole words only: "Drinks" and "Pink" aren't ink.
	 *
	 * @return int[]
	 */
	public static function discover_ink_category_ids(): array {
		static $ids = null;
		if ( $ids !== null ) {
			return $ids;
		}
		$ids = []; // get_terms() below runs filter_product_cat_terms(), which comes back here.
		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			]
		);
		if ( is_wp_error( $terms ) ) {
			return $ids;
		}
		$found = [];
		foreach ( $terms as $t ) {
			if ( preg_match( '/\bink\b/i', $t->name ) || preg_match( '/(^|-)ink(-|$)/i', $t->slug ) ) {
				$found[] = (int) $t->term_id;
			}
		}
		$ids = array_values( array_unique( $found ) );
		return $ids;
	}

	/** @return int[] Categories saved earlier, plus any category named as ink. */
	public static function category_ids(): array {
		$saved = wcss_get_option( self::OPT_CATS, [] );
		if ( is_string( $saved ) ) {
			$saved = preg_split( '/[\s,]+/', $saved ) ?: [];
		}
		$saved = is_array( $saved ) ? array_map( 'absint', $saved ) : [];
		return array_values( array_unique( array_filter( array_merge( $saved, self::discover_ink_category_ids() ) ) ) );
	}

	public static function product_is_ink( int $product_id ): bool {
		$cats = self::category_ids();
		if ( ! $cats || $product_id <= 0 ) {
			return false;
		}
		$ids = wc_get_product_term_ids( $product_id, 'product_cat' );
		return (bool) array_intersect( $cats, array_map( 'intval', $ids ) );
	}

	public static function order_type_from_order( $order ): string {
		if ( ! $order instanceof WC_Order ) {
			return self::TYPE_STATION;
		}
		$t = (string) $order->get_meta( self::META );
		return $t === self::TYPE_INK ? self::TYPE_INK : self::TYPE_STATION;
	}

	public static function order_is_ink( $order ): bool {
		return self::order_type_from_order( $order ) === self::TYPE_INK;
	}

	/* ---------------- cart validation ---------------- */

	/**
	 * Ink and stationery are separate orders, so adding one type replaces a cart of the other.
	 * Skipped for a product that can't be added, so a store never loses its cart for nothing.
	 */
	private static function clear_other_type( int $product_id ): void {
		if ( ! self::cart_product_ids() ) {
			return;
		}
		$is_ink  = self::product_is_ink( $product_id );
		$product = wc_get_product( $product_id );
		if ( $is_ink === self::cart_is_ink() || ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}
		WC()->cart->empty_cart();
		self::$cleared_note = $is_ink
			? __( 'Your stationery items were removed from the cart because ink is ordered separately.', 'wcss' )
			: __( 'Your ink items were removed from the cart because stationery is ordered separately.', 'wcss' );
		if ( wp_doing_ajax() ) {
			wc_add_notice( self::$cleared_note, 'notice' ); // Shop cards add without a popup; this shows on the next page.
		}
	}

	public function validate_add_to_cart( $ok, $product_id, $quantity ) {
		if ( $ok ) {
			self::clear_other_type( (int) $product_id );
		}
		return $ok;
	}

	/**
	 * Same rule for code that adds to the cart without running the validation filter.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 */
	public function clear_other_type_on_add( $cart_item_data, $product_id, $variation_id = 0 ) {
		self::clear_other_type( (int) $product_id );
		return $cart_item_data;
	}

	/**
	 * @param WC_Product $product Product being added via Store API.
	 * @param array      $request Request payload.
	 */
	public function store_api_validate( $product, $request ) {
		if ( $product instanceof WC_Product ) {
			self::clear_other_type( (int) ( $product->get_parent_id() ?: $product->get_id() ) );
		}
	}

	/** Explain the cleared cart inside the "Added to your cart" popup. */
	public function add_cleared_note( $message ) {
		if ( self::$cleared_note === '' ) {
			return $message;
		}
		return str_replace( '<div class="cc-atc__actions">', '<p class="cc-atc__note">' . esc_html( self::$cleared_note ) . '</p><div class="cc-atc__actions">', (string) $message );
	}

	/** Backstop for a cart that became mixed some other way: checkout stops until it's sorted out. */
	public function validate_cart_contents() {
		$ids = self::cart_product_ids();
		$ink = array_filter( $ids, [ __CLASS__, 'product_is_ink' ] );
		if ( $ink && count( $ink ) < count( $ids ) ) {
			wc_add_notice( __( 'Ink and stationery are ordered separately. Please remove one type from your cart.', 'wcss' ), 'error' );
		}
	}

	/* ---------------- order stamp + policy ---------------- */

	public function stamp_order_type( $order, $data ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$type = self::cart_is_ink() ? self::TYPE_INK : self::TYPE_STATION;
		$order->update_meta_data( self::META, $type );
	}

	public function stamp_order_type_api( $order ) {
		$this->stamp_order_type( $order, [] );
	}

	/**
	 * Force ink orders to need Supply Ops approval (never auto-approve / auto-submit).
	 */
	public function auto_submit_gate( $gate, $order ) {
		if ( ! $order instanceof WC_Order || ! self::order_is_ink( $order ) ) {
			return $gate;
		}
		if ( $order->get_meta( '_wcss_policy_override' ) ) {
			return [ 'allowed' => true, 'reasons' => [], 'messages' => [ 'Ink order approved by Supply Ops.' ] ];
		}
		return [
			'allowed'  => false,
			'reasons'  => [ 'ink_review' ],
			'messages' => [ __( 'Ink order — always reviewed by Supply Ops before sending to a supplier.', 'wcss' ) ],
		];
	}

	/**
	 * After policy is recorded, rewrite ink orders so UI/emails show needs-approval.
	 */
	public function after_policy_recorded( $order, $res ) {
		if ( ! $order instanceof WC_Order || ! self::order_is_ink( $order ) ) {
			return;
		}
		if ( $order->get_meta( '_wcss_policy_override' ) ) {
			return;
		}
		$res = is_array( $res ) ? $res : [];
		$res['in_policy'] = false;
		$reasons          = (array) ( $res['reasons'] ?? [] );
		$messages         = (array) ( $res['messages'] ?? [] );
		if ( ! in_array( 'ink_review', $reasons, true ) ) {
			$reasons[]  = 'ink_review';
			$messages[] = __( 'Ink order — always reviewed by Supply Ops before sending to a supplier.', 'wcss' );
		}
		$res['reasons']  = $reasons;
		$res['messages'] = $messages;
		$order->update_meta_data( WCSS_Order_Policy::META, $res );
		$order->save();
	}

	public function checkout_ink_notice() {
		if ( ! self::cart_is_ink() ) {
			return;
		}
		?>
		<section class="cc-approval-alert cc-ink-alert" role="status" aria-labelledby="cc-ink-title">
			<span class="cc-approval-alert__icon" aria-hidden="true">
				<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.01"/></svg>
			</span>
			<div class="cc-approval-alert__body">
				<p class="cc-approval-alert__eyebrow"><?php esc_html_e( 'Ink order', 'wcss' ); ?></p>
				<h2 class="cc-approval-alert__title" id="cc-ink-title"><?php esc_html_e( 'This ink order always needs Supply Ops approval', 'wcss' ); ?></h2>
				<p class="cc-approval-alert__text"><?php esc_html_e( 'Ink is separate from your monthly stationery order. Nothing is sent to a supplier until Supply Ops reviews and approves it.', 'wcss' ); ?></p>
			</div>
			<a class="cc-approval-alert__link" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'Back to cart', 'wcss' ); ?></a>
		</section>
		<?php
	}

	public function render_styles() {
		if ( is_admin() ) {
			return;
		}
		?>
		<style id="wcss-ink-order-css">
		.cc-ink-alert{margin-bottom:1rem}
		.ccm-pill.p-ink{background:#1F2937;color:#F9FAFB;border:1px solid #C8372D}
		</style>
		<?php
	}
}
