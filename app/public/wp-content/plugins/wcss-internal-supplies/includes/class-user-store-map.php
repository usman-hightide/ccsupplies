<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_User_Store_Map {

    const USER_META_STORE_ID = '_wcss_store_id';

    public function __construct() {
        add_action( 'show_user_profile', [ $this, 'render_field' ] );
        add_action( 'edit_user_profile', [ $this, 'render_field' ] );
        add_action( 'personal_options_update', [ $this, 'save_field' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_field' ] );
        add_action( 'woocommerce_checkout_process', [ $this, 'enforce_store_mapping_on_checkout' ] );
    }

    public function render_field( $user ) {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'list_users' ) ) {
            return; // limit who can set mapping
        }

        $current = (int) get_user_meta( $user->ID, self::USER_META_STORE_ID, true );

        // Fetch active stores
        $stores = get_posts( [
            'post_type'      => WCSS_Store_CPT::POST_TYPE,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => [
                [
                    'key'   => WCSS_Store_CPT::META_ACTIVE,
                    'value' => '1',
                ],
            ],
        ] );
        ?>
        <h2><?php esc_html_e( 'Internal Supplies', 'wcss' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="wcss_store_id"><?php esc_html_e( 'Assigned Store Location', 'wcss' ); ?></label></th>
                <td>
                    <select name="wcss_store_id" id="wcss_store_id">
                        <option value="0"><?php esc_html_e( '— None —', 'wcss' ); ?></option>
                        <?php foreach ( $stores as $store ) : ?>
                            <option value="<?php echo esc_attr( $store->ID ); ?>" <?php selected( $current, $store->ID ); ?>>
                                <?php echo esc_html( $store->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Link this user to a single store location. Used for budgets & one-order-per-month rules.', 'wcss' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_field( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) { return; }
        if ( isset( $_POST['wcss_store_id'] ) ) {
            $store_id = max( 0, (int) $_POST['wcss_store_id'] );
            update_user_meta( $user_id, self::USER_META_STORE_ID, $store_id );
        }
    }

    /**
     * Helper to get store ID for a user (static for easy reuse).
     */
    public static function get_user_store_id( $user_id = 0 ) {
        $user_id = $user_id ?: get_current_user_id();
        return (int) get_user_meta( $user_id, self::USER_META_STORE_ID, true );
    }

    public function enforce_store_mapping_on_checkout() {
        if ( ! is_checkout() ) {
            return;
        }

        $store_id = self::get_user_store_id();
    }
}