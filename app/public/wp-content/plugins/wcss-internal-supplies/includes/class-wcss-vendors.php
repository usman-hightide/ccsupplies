<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_Vendors {
    public function __construct() {
        add_action( 'init', [ $this, 'register_taxonomy' ] );
    }
    public function register_taxonomy() {
        $labels = [
            'name'          => _x('Vendors', 'taxonomy general name', 'wcss'),
            'singular_name' => _x('Vendor', 'taxonomy singular name', 'wcss'),
            'search_items'  => __('Search Vendors','wcss'),
            'all_items'     => __('All Vendors','wcss'),
            'edit_item'     => __('Edit Vendor','wcss'),
            'update_item'   => __('Update Vendor','wcss'),
            'add_new_item'  => __('Add New Vendor','wcss'),
            'menu_name'     => __('Vendors','wcss'),
        ];
        register_taxonomy( 'wcss_vendor', [ 'product' ], [
            'hierarchical'      => false,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => [ 'slug' => 'vendor' ],
        ] );
    }

}

    // ---- Vendor term meta (phone, email, address)
    add_action( 'wcss_vendor_add_form_fields', function() {
        ?>
        <div class="form-field">
            <label for="wcss_vendor_phone"><?php esc_html_e('Phone', 'wcss'); ?></label>
            <input type="text" name="wcss_vendor_phone" id="wcss_vendor_phone" />
        </div>
        <div class="form-field">
            <label for="wcss_vendor_email"><?php esc_html_e('Email', 'wcss'); ?></label>
            <input type="email" name="wcss_vendor_email" id="wcss_vendor_email" />
        </div>
        <div class="form-field">
            <label for="wcss_vendor_address"><?php esc_html_e('Address', 'wcss'); ?></label>
            <textarea name="wcss_vendor_address" id="wcss_vendor_address" rows="3"></textarea>
        </div>
        <?php
    });

    add_action( 'wcss_vendor_edit_form_fields', function( $term ) {
        $phone   = get_term_meta( $term->term_id, 'wcss_vendor_phone', true );
        $email   = get_term_meta( $term->term_id, 'wcss_vendor_email', true );
        $address = get_term_meta( $term->term_id, 'wcss_vendor_address', true );
        ?>
        <tr class="form-field">
            <th scope="row"><label for="wcss_vendor_phone"><?php esc_html_e('Phone', 'wcss'); ?></label></th>
            <td><input type="text" name="wcss_vendor_phone" id="wcss_vendor_phone" value="<?php echo esc_attr($phone); ?>" /></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="wcss_vendor_email"><?php esc_html_e('Email', 'wcss'); ?></label></th>
            <td><input type="email" name="wcss_vendor_email" id="wcss_vendor_email" value="<?php echo esc_attr($email); ?>" /></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="wcss_vendor_address"><?php esc_html_e('Address', 'wcss'); ?></label></th>
            <td><textarea name="wcss_vendor_address" id="wcss_vendor_address" rows="3"><?php echo esc_textarea($address); ?></textarea></td>
        </tr>
        <?php
    }, 10, 1);

    // Save handlers
    add_action( 'created_wcss_vendor', function( $term_id ) {
        if ( isset($_POST['wcss_vendor_phone']) )   update_term_meta( $term_id, 'wcss_vendor_phone',   sanitize_text_field($_POST['wcss_vendor_phone']) );
        if ( isset($_POST['wcss_vendor_email']) )   update_term_meta( $term_id, 'wcss_vendor_email',   sanitize_email($_POST['wcss_vendor_email']) );
        if ( isset($_POST['wcss_vendor_address']) ) update_term_meta( $term_id, 'wcss_vendor_address', sanitize_textarea_field($_POST['wcss_vendor_address']) );
    });
    add_action( 'edited_wcss_vendor', function( $term_id ) {
        if ( isset($_POST['wcss_vendor_phone']) )   update_term_meta( $term_id, 'wcss_vendor_phone',   sanitize_text_field($_POST['wcss_vendor_phone']) );
        if ( isset($_POST['wcss_vendor_email']) )   update_term_meta( $term_id, 'wcss_vendor_email',   sanitize_email($_POST['wcss_vendor_email']) );
        if ( isset($_POST['wcss_vendor_address']) ) update_term_meta( $term_id, 'wcss_vendor_address', sanitize_textarea_field($_POST['wcss_vendor_address']) );
    });
    