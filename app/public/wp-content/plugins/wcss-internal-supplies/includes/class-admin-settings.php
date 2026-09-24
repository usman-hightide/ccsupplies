<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Admin_Settings {

    private $option_key = 'wcss_settings';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Internal Supplies (WCSS)', 'wcss' ),
            __( 'Internal Supplies', 'wcss' ),
            'manage_woocommerce',
            'wcss-settings',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'wcss_settings_group', $this->option_key, [ $this, 'sanitize' ] );

        add_settings_section(
            'wcss_main',
            __( 'Core Settings', 'wcss' ),
            function() {
                echo '<p>' . esc_html__( 'Configure visibility, monthly order quota, budgets, and payment bypass mode.', 'wcss' ) . '</p>';
            },
            'wcss-settings'
        );

        add_settings_field( 'visibility_mode', __( 'Visibility Mode', 'wcss' ), [ $this, 'field_visibility' ], 'wcss-settings', 'wcss_main' );
        add_settings_field( 'default_monthly_quota', __( 'Default Monthly Order Quota (per store)', 'wcss' ), [ $this, 'field_quota' ], 'wcss-settings', 'wcss_main' );
        add_settings_field( 'budget_enforcement', __( 'Enforce Budgets', 'wcss' ), [ $this, 'field_budget' ], 'wcss-settings', 'wcss_main' );
        add_settings_field( 'exempt_product_ids', __( 'Exempt Product IDs (comma-separated)', 'wcss' ), [ $this, 'field_exempt' ], 'wcss-settings', 'wcss_main' );
        add_settings_field( 'bypass_method', __( 'Bypass Method', 'wcss' ), [ $this, 'field_bypass' ], 'wcss-settings', 'wcss_main' );
        add_settings_field( 'count_statuses', __( 'Statuses Counted Toward Monthly Limit', 'wcss' ), [ $this, 'field_statuses' ], 'wcss-settings', 'wcss_main' );
    }

    public function sanitize( $input ) {
        $out = get_option( $this->option_key, [] );

        $out['visibility_mode'] = in_array( $input['visibility_mode'] ?? 'public_catalog', ['public_catalog','fully_private'], true )
            ? $input['visibility_mode'] : 'public_catalog';

        $out['default_monthly_quota'] = max( 0, intval( $input['default_monthly_quota'] ?? 1 ) );
        $out['budget_enforcement'] = isset( $input['budget_enforcement'] ) ? 1 : 0;

        $exempt = isset( $input['exempt_product_ids'] ) ? sanitize_text_field( $input['exempt_product_ids'] ) : '';
        // normalize: digits + commas only
        $exempt = preg_replace( '/[^0-9,]/', '', $exempt );
        $out['exempt_product_ids'] = $exempt;

        $out['bypass_method'] = in_array( $input['bypass_method'] ?? 'internal_requisition', ['internal_requisition','cod','coupon'], true )
            ? $input['bypass_method'] : 'internal_requisition';

        $allowed_statuses = ['wc-awaiting-approval','wc-approved','wc-processing','wc-completed','wc-on-hold'];
        $sel = isset( $input['count_statuses'] ) && is_array( $input['count_statuses'] ) ? array_values( array_intersect( $allowed_statuses, $input['count_statuses'] ) ) : ['wc-awaiting-approval','wc-approved'];
        $out['count_statuses'] = $sel;

        return $out;
    }

    private function get_opts() {
        $defaults = [
            'visibility_mode' => 'public_catalog',
            'default_monthly_quota' => 1,
            'budget_enforcement' => 1,
            'exempt_product_ids' => '',
            'bypass_method' => 'internal_requisition',
            'count_statuses' => ['wc-awaiting-approval','wc-approved'],
        ];
        return wp_parse_args( get_option( $this->option_key, [] ), $defaults );
    }

    public function render_page() {
        $opts = $this->get_opts();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Internal Supplies (WCSS)', 'wcss' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'wcss_settings_group' ); ?>
                <?php do_settings_sections( 'wcss-settings' ); ?>
                <?php submit_button(); ?>
            </form>
            <hr />
            <p><em><?php esc_html_e( 'Next steps: add Store Location CPT, user→store mapping, and approval statuses.', 'wcss' ); ?></em></p>
        </div>
        <?php
    }

    public function field_visibility() {
        $opts = $this->get_opts();
        ?>
        <select name="wcss_settings[visibility_mode]">
            <option value="public_catalog" <?php selected( $opts['visibility_mode'], 'public_catalog' ); ?>><?php esc_html_e( 'Public Catalog (ordering requires login)', 'wcss' ); ?></option>
            <option value="fully_private" <?php selected( $opts['visibility_mode'], 'fully_private' ); ?>><?php esc_html_e( 'Fully Private (site requires login)', 'wcss' ); ?></option>
        </select>
        <?php
    }

    public function field_quota() {
        $opts = $this->get_opts();
        ?>
        <input type="number" min="0" step="1" name="wcss_settings[default_monthly_quota]" value="<?php echo esc_attr( $opts['default_monthly_quota'] ); ?>" />
        <?php
    }

    public function field_budget() {
        $opts = $this->get_opts();
        ?>
        <label>
            <input type="checkbox" name="wcss_settings[budget_enforcement]" value="1" <?php checked( $opts['budget_enforcement'], 1 ); ?> />
            <?php esc_html_e( 'Enable per-store monthly budget checks.', 'wcss' ); ?>
        </label>
        <?php
    }

    public function field_exempt() {
        $opts = $this->get_opts();
        ?>
        <input type="text" name="wcss_settings[exempt_product_ids]" value="<?php echo esc_attr( $opts['exempt_product_ids'] ); ?>" placeholder="e.g. 12,34,56" size="40" />
        <p class="description"><?php esc_html_e( 'These product IDs are excluded from budget calculations (e.g., printer ink).', 'wcss' ); ?></p>
        <?php
    }

    public function field_bypass() {
        $opts = $this->get_opts();
        ?>
        <select name="wcss_settings[bypass_method]">
            <option value="internal_requisition" <?php selected( $opts['bypass_method'], 'internal_requisition' ); ?>><?php esc_html_e( 'Internal Requisition (no payment capture)', 'wcss' ); ?></option>
            <option value="cod" <?php selected( $opts['bypass_method'], 'cod' ); ?>><?php esc_html_e( 'Cash on Delivery (use WooCommerce COD)', 'wcss' ); ?></option>
            <option value="coupon" <?php selected( $opts['bypass_method'], 'coupon' ); ?>><?php esc_html_e( '100% Coupon (not recommended)', 'wcss' ); ?></option>
        </select>
        <?php
    }

    public function field_statuses() {
        $opts = $this->get_opts();
        $allowed = [
            'wc-awaiting-approval' => __( 'Awaiting Approval (custom)', 'wcss' ),
            'wc-approved'          => __( 'Approved (custom)', 'wcss' ),
            'wc-processing'        => __( 'Processing', 'wcss' ),
            'wc-completed'         => __( 'Completed', 'wcss' ),
            'wc-on-hold'           => __( 'On hold', 'wcss' ),
        ];
        foreach ( $allowed as $status => $label ) {
            printf(
                '<label style="display:block;margin:2px 0;"><input type="checkbox" name="wcss_settings[count_statuses][]" value="%1$s" %2$s> %3$s</label>',
                esc_attr( $status ),
                checked( in_array( $status, $opts['count_statuses'], true ), true, false ),
                esc_html( $label )
            );
        }
        echo '<p class="description">' . esc_html__( 'Statuses that are counted toward the monthly one-order limit. Custom statuses will be added in a later phase.', 'wcss' ) . '</p>';
    }
}
