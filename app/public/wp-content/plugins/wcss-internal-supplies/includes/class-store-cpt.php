<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Store_CPT {

    const POST_TYPE = 'store_location';
    const META_CODE   = '_wcss_store_code';
    const META_ADDR   = '_wcss_store_address';
    const META_BUDGET = '_wcss_monthly_budget';
    const META_QUOTA  = '_wcss_monthly_quota';
    const META_ACTIVE = '_wcss_is_active';
    const META_CITY     = '_wcss_store_city';
    const META_STATE    = '_wcss_store_state';
    const META_POSTCODE = '_wcss_store_postcode';
    const META_COUNTRY  = '_wcss_store_country';

    public function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
        add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_meta' ], 10, 2 );
        add_filter( 'manage_edit-' . self::POST_TYPE . '_columns', [ $this, 'columns' ] );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'column_content' ], 10, 2 );
        add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', [ $this, 'sortable_columns' ] );
    }

    public function register_cpt() {
        $labels = [
            'name'               => __( 'Store Locations', 'wcss' ),
            'singular_name'      => __( 'Store Location', 'wcss' ),
            'add_new_item'       => __( 'Add New Store Location', 'wcss' ),
            'edit_item'          => __( 'Edit Store Location', 'wcss' ),
            'new_item'           => __( 'New Store Location', 'wcss' ),
            'view_item'          => __( 'View Store Location', 'wcss' ),
            'search_items'       => __( 'Search Store Locations', 'wcss' ),
            'not_found'          => __( 'No store locations found', 'wcss' ),
            'menu_name'          => __( 'Store Locations', 'wcss' ),
        ];

        register_post_type( self::POST_TYPE, [
            'labels'        => $labels,
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => 'woocommerce',
            'capability_type' => 'post',
            'map_meta_cap'  => true,
            'supports'      => [ 'title' ], // keep lean; we handle fields via metabox
            'menu_position' => 56,
        ] );
    }

    public function add_metabox() {
        add_meta_box(
            'wcss_store_details',
            __( 'Store Details', 'wcss' ),
            [ $this, 'render_metabox' ],
            self::POST_TYPE,
            'normal',
            'default'
        );
    }

    public function render_metabox( $post ) {
        wp_nonce_field( 'wcss_store_save_' . $post->ID, 'wcss_store_nonce' );

        $code   = get_post_meta( $post->ID, self::META_CODE, true );
        $addr   = get_post_meta( $post->ID, self::META_ADDR, true );
        $budget = get_post_meta( $post->ID, self::META_BUDGET, true );
        $quota  = get_post_meta( $post->ID, self::META_QUOTA, true );
        $active = get_post_meta( $post->ID, self::META_ACTIVE, true );
        $city   = get_post_meta( $post->ID, self::META_CITY, true );
        $state  = get_post_meta( $post->ID, self::META_STATE, true );
        $postcode = get_post_meta( $post->ID, self::META_POSTCODE, true );
        $country  = get_post_meta( $post->ID, self::META_COUNTRY, true );
        if ( $quota === '' ) {
            $quota = intval( wcss_get_option( 'default_monthly_quota', 1 ) );
        }

        ?>
        <table class="form-table">
            <tr>
                <th><label for="wcss_store_code"><?php esc_html_e( 'Store Code', 'wcss' ); ?></label></th>
                <td><input type="text" id="wcss_store_code" name="wcss_store_code" class="regular-text" value="<?php echo esc_attr( $code ); ?>" /></td>
            </tr>
            <tr>
                <th><label for="wcss_store_address"><?php esc_html_e( 'Address', 'wcss' ); ?></label></th>
                <td><textarea id="wcss_store_address" name="wcss_store_address" class="large-text" rows="3"><?php echo esc_textarea( $addr ); ?></textarea></td>
            </tr>
            <tr>
                <th><label for="wcss_monthly_budget"><?php esc_html_e( 'Monthly Budget', 'wcss' ); ?></label></th>
                <td><input type="number" step="0.01" min="0" id="wcss_monthly_budget" name="wcss_monthly_budget" value="<?php echo esc_attr( $budget ); ?>" /></td>
            </tr>
            <tr>
                <th><label for="wcss_monthly_quota"><?php esc_html_e( 'Monthly Order Quota', 'wcss' ); ?></label></th>
                <td><input type="number" step="1" min="0" id="wcss_monthly_quota" name="wcss_monthly_quota" value="<?php echo esc_attr( $quota ); ?>" /></td>
            </tr>
            <tr>
                <th><label for="wcss_store_city"><?php esc_html_e( 'City', 'wcss' ); ?></label></th>
                <td><input type="text" id="wcss_store_city" name="wcss_store_city" class="regular-text" value="<?php echo esc_attr( $city ); ?>" /></td>
                </tr>
                <tr>
                <th><label for="wcss_store_state"><?php esc_html_e( 'State/Province', 'wcss' ); ?></label></th>
                <td><input type="text" id="wcss_store_state" name="wcss_store_state" class="regular-text" value="<?php echo esc_attr( $state ); ?>" /></td>
                </tr>
                <tr>
                <th><label for="wcss_store_postcode"><?php esc_html_e( 'Postcode / ZIP', 'wcss' ); ?></label></th>
                <td><input type="text" id="wcss_store_postcode" name="wcss_store_postcode" class="regular-text" value="<?php echo esc_attr( $postcode ); ?>" /></td>
                </tr>
                <tr>
                <th><label for="wcss_store_country"><?php esc_html_e( 'Country (ISO code e.g., PK/US/GB)', 'wcss' ); ?></label></th>
                <td><input type="text" id="wcss_store_country" name="wcss_store_country" class="regular-text" value="<?php echo esc_attr( $country ); ?>" /></td>
                </tr>
            <tr>
                <th><label for="wcss_is_active"><?php esc_html_e( 'Active', 'wcss' ); ?></label></th>
                <td>
                    <select id="wcss_is_active" name="wcss_is_active">
                        <option value="1" <?php selected( $active, '1' ); ?>><?php esc_html_e( 'Yes', 'wcss' ); ?></option>
                        <option value="0" <?php selected( $active, '0' ); ?>><?php esc_html_e( 'No', 'wcss' ); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_meta( $post_id, $post ) {
        if ( ! isset( $_POST['wcss_store_nonce'] ) || ! wp_verify_nonce( $_POST['wcss_store_nonce'], 'wcss_store_save_' . $post_id ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

        $code   = isset( $_POST['wcss_store_code'] ) ? sanitize_text_field( $_POST['wcss_store_code'] ) : '';
        $addr   = isset( $_POST['wcss_store_address'] ) ? wp_kses_post( $_POST['wcss_store_address'] ) : '';
        $budget = isset( $_POST['wcss_monthly_budget'] ) ? floatval( $_POST['wcss_monthly_budget'] ) : 0;
        $quota  = isset( $_POST['wcss_monthly_quota'] ) ? max( 0, intval( $_POST['wcss_monthly_quota'] ) ) : 0;
        $active = isset( $_POST['wcss_is_active'] ) ? ( $_POST['wcss_is_active'] === '1' ? '1' : '0' ) : '1';
        $city     = isset( $_POST['wcss_store_city'] ) ? sanitize_text_field( $_POST['wcss_store_city'] ) : '';
        $state    = isset( $_POST['wcss_store_state'] ) ? sanitize_text_field( $_POST['wcss_store_state'] ) : '';
        $postcode = isset( $_POST['wcss_store_postcode'] ) ? sanitize_text_field( $_POST['wcss_store_postcode'] ) : '';
        $country  = isset( $_POST['wcss_store_country'] ) ? strtoupper( sanitize_text_field( $_POST['wcss_store_country'] ) ) : '';

        update_post_meta( $post_id, self::META_CITY,     $city );
        update_post_meta( $post_id, self::META_STATE,    $state );
        update_post_meta( $post_id, self::META_POSTCODE, $postcode );
        update_post_meta( $post_id, self::META_COUNTRY,  $country );
        update_post_meta( $post_id, self::META_CODE,   $code );
        update_post_meta( $post_id, self::META_ADDR,   $addr );
        update_post_meta( $post_id, self::META_BUDGET, $budget );
        update_post_meta( $post_id, self::META_QUOTA,  $quota );
        update_post_meta( $post_id, self::META_ACTIVE, $active );
    }

    public function columns( $cols ) {
        $new = [];
        $new['cb']    = $cols['cb'];
        $new['title'] = __( 'Store Name', 'wcss' );
        $new['code']  = __( 'Code', 'wcss' );
        $new['budget'] = __( 'Monthly Budget', 'wcss' );
        $new['quota']  = __( 'Monthly Quota', 'wcss' );
        $new['active'] = __( 'Active', 'wcss' );
        $new['date']   = $cols['date'];
        return $new;
    }

    public function column_content( $col, $post_id ) {
        switch ( $col ) {
            case 'code':
                echo esc_html( get_post_meta( $post_id, self::META_CODE, true ) );
                break;
            case 'budget':
                $b = get_post_meta( $post_id, self::META_BUDGET, true );
                echo $b !== '' ? wp_kses_post( wc_price( (float) $b ) ) : '&mdash;';
                break;
            case 'quota':
                $q = get_post_meta( $post_id, self::META_QUOTA, true );
                echo $q !== '' ? intval( $q ) : '&mdash;';
                break;
            case 'active':
                $a = get_post_meta( $post_id, self::META_ACTIVE, true );
                echo $a === '1' ? '✅' : '⛔';
                break;
        }
    }

    public function sortable_columns( $cols ) {
        $cols['budget'] = 'budget';
        $cols['quota']  = 'quota';
        $cols['active'] = 'active';
        return $cols;
    }
}