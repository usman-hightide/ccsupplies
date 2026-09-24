<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_REST_Products {
    const NS = 'wcss/v1';
    public function __construct(){ add_action( 'rest_api_init', [ $this, 'routes' ] ); }
    
    public function can_manage( $req = null ): bool {
        return current_user_can( 'wcss_manage_portal' ) || current_user_can( 'manage_options' );
    }

    public function routes() {
        
        register_rest_route( self::NS, '/products', [
            'methods'  => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'list_products' ],
            'args'     => [
                'page' => [
                    'validate_callback' => function( $value ) {
                        return $value === null || $value === '' || is_numeric( $value );
                    },
                ],
                'per_page' => [
                    'validate_callback' => function( $value ) {
                        return $value === null || $value === '' || is_numeric( $value );
                    },
                ],
                'search' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    
    
        register_rest_route( self::NS, '/products/(?P<id>\d+)', [
            'methods'  => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'read' ],
        ]);
    
        register_rest_route( self::NS, '/products', [
            'methods'  => 'POST',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'create' ],
            'args'     => [
                'name'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field'],
                'sku'=>['required'=>false,'sanitize_callback'=>'sanitize_text_field'],
                'price'=>['required'=>true,'sanitize_callback'=>'wc_format_decimal'],
                'short_description'=>['required'=>false,'sanitize_callback'=>'wp_kses_post'],
                'category_ids'=>['required'=>false],      // array
                'brand'=>['required'=>false,'sanitize_callback'=>'sanitize_text_field'],
                'vendor'=>['required'=>false,'sanitize_callback'=>'sanitize_text_field'],
                'images'=>['required'=>false],            // array of attachment IDs
                'manage_stock'=>['required'=>false],
                'stock'=>['required'=>false],
                'stock_status'=>['required'=>false,'sanitize_callback'=>'sanitize_text_field'],
                'status'=>['required'=>false,'sanitize_callback'=>'sanitize_text_field'],
            ],
        ]);
    
        register_rest_route( self::NS, '/products/(?P<id>\d+)', [
            'methods'=>'PUT',
            'permission_callback'=>[ $this,'can_manage' ],
            'callback'=>[ $this,'update' ],
        ]);
    
        register_rest_route( self::NS, '/products/(?P<id>\d+)', [
            'methods'  => 'DELETE',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'delete' ],
        ]);
    
        register_rest_route( self::NS, '/products/meta', [
            'methods'=>'GET',
            'permission_callback'=>[ $this,'can_manage' ],
            'callback'=>[ $this,'meta' ],
        ]);

        // Create a product category
        register_rest_route( self::NS, '/categories', [
            'methods'  => 'POST',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'create_category' ],
            'args'     => [
                'name' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'parent' => [ 'required' => false, 'validate_callback' => 'is_numeric' ],
            ],
        ]);

        // Create a vendor term
        register_rest_route( self::NS, '/vendors', [
            'methods'  => 'POST',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'create_vendor' ],
            'args'     => [
                'name' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'vendor_ids' => ['required'=>false], // array<int>
            ],
        ]);

        register_rest_route( 'wcss/v1', '/products/export', [
            'methods'             => 'GET',
            'permission_callback' => [ $this, 'can_manage' ], // the same guard you use for products list
            'callback'            => [ $this, 'export_products_csv' ],
        ] );
  

    }

    public function list_products( WP_REST_Request $req ) {
        $page   = max( 1, (int) ($req->get_param('page') ?: 1) );
        $per    = min( 20, max( 100, (int) ($req->get_param('per_page') ?: 20) ) );
        $search = sanitize_text_field( (string) $req->get_param('search') );
    
        // Build a safe, boring WP_Query for products
        $q_args = [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'draft', 'pending' ],
            'orderby'        => 'date',
            'order'          => 'DESC',
            'posts_per_page' => $per,
            'paged'          => $page,
            's'              => $search,
            'no_found_rows'  => false,            // we want totals for pagination
            'fields'         => 'ids',            // fetch only IDs; faster
        ];
        // return 'testing the fucntion';
        try {
            $q = new WP_Query( $q_args );
    
            if ( is_wp_error( $q ) ) {
                wc_get_logger()->error( 'WCSS products WP_Query failed: ' . $q->get_error_message(), [ 'source' => 'wcss' ] );
                return new WP_Error( 'wcss_products_query_failed', 'Products query failed.', [ 'status' => 500 ] );
            }
    
            $ids   = (array) $q->posts;
            $items = [];
    
            foreach ( $ids as $pid ) {
                $p = wc_get_product( $pid );
                if ( ! $p instanceof WC_Product ) { continue; }
                $items[] = $this->dto( $p );
            }
    
            return rest_ensure_response([
                'items'       => $items,
                'total'       => (int) $q->found_posts,
                'total_pages' => (int) $q->max_num_pages,
                'page'        => $page,
                'per_page'    => $per,
            ]);
    
        } catch ( Throwable $e ) {
            wc_get_logger()->critical(
                'WCSS products list exception: ' . $e->getMessage(),
                [ 'source' => 'wcss', 'trace' => $e->getTraceAsString(), 'args' => $q_args ]
            );
            return new WP_Error( 'wcss_products_exception', 'Products query crashed.', [ 'status' => 500 ] );
        }
    }

    public function read( WP_REST_Request $req ) {
        $p = wc_get_product( (int) $req['id'] );
        if ( ! $p ) return new WP_Error( 'not_found', 'Product not found', [ 'status' => 404 ] );
        return rest_ensure_response( $this->dto( $p ) );
    }

    public function delete( WP_REST_Request $req ) {
        $p = wc_get_product( (int) $req['id'] );
        if ( ! $p ) return new WP_Error( 'not_found', 'Product not found', [ 'status' => 404 ] );
        try {
            $p->delete( true );
            return rest_ensure_response([ 'ok' => true ]);
        } catch ( Throwable $e ) {
            return new WP_Error( 'wcss_product_delete_failed', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    /* ---------- DTO ---------- */
    private function dto( WC_Product $p ): array {
        $brand_terms   = wp_get_post_terms( $p->get_id(), 'wcss_brand',  [ 'fields' => 'names' ] );
        if ( is_wp_error( $brand_terms ) )   $brand_terms = [];
    
        // $vendor_terms  = wp_get_post_terms( $p->get_id(), 'wcss_vendor', [ 'fields' => 'names' ] );
        // if ( is_wp_error( $vendor_terms ) )  $vendor_terms = [];
    
        $vendor_ids    = wp_get_post_terms( $p->get_id(), 'wcss_vendor', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $vendor_ids ) )    $vendor_ids = [];


        $vendor_terms = wp_get_post_terms( $p->get_id(), 'wcss_vendor', [ 'fields' => 'all' ] );
        if ( is_wp_error( $vendor_terms ) ) { $vendor_terms = []; }
        $vendor = null;
        if ( ! empty( $vendor_terms ) && $vendor_terms[0] instanceof WP_Term ) {
            $vt = $vendor_terms[0];
            $vendor = [
                'id'      => (int) $vt->term_id,
                'name'    => $vt->name,
                'phone'   => (string) get_term_meta( $vt->term_id, 'wcss_vendor_phone', true ),
                'email'   => (string) get_term_meta( $vt->term_id, 'wcss_vendor_email', true ),
                'address' => (string) get_term_meta( $vt->term_id, 'wcss_vendor_address', true ),
            ];
        }

    
        $cat_ids       = wp_get_post_terms( $p->get_id(), 'product_cat', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $cat_ids ) )       $cat_ids = [];
    
        $featured = (int) $p->get_image_id();
        $gallery  = array_map( 'intval', (array) $p->get_gallery_image_ids() );
        $images   = array_values( array_filter( array_unique( array_merge( $featured ? [ $featured ] : [], $gallery ) ) ) );
    
        return [
            'id'                => $p->get_id(),
            'name'              => $p->get_name(),
            'sku'               => $p->get_sku(),
            'price'             => (float) $p->get_price(),
            'price_html'        => wc_price( (float) $p->get_price() ),
            'short_description' => $p->get_short_description(),
            'manage_stock'      => (bool) $p->get_manage_stock(),
            'stock'             => $p->get_stock_quantity(),
            'stock_status'      => $p->get_stock_status(),
            'status'            => $p->get_status(),
            'category_ids'      => $cat_ids,
            'brand'             => $brand_terms  ? $brand_terms[0]  : '',
            'vendor'            => $vendor,
            'vendor_id'         =>$vendor_ids,
            'images'            => $images,
        ];
    }

    private function set_terms_by_name( int $post_id, string $taxonomy, ?string $name ): void {
        if ( $name === null ) return;
        $name = trim( (string) $name );
        if ( $name === '' ) {
            wp_set_object_terms( $post_id, [], $taxonomy, false );
        } else {
            wp_set_object_terms( $post_id, [ $name ], $taxonomy, false ); // auto creates if missing
        }
    }
    
    private function set_product_images( WC_Product $p, array $image_ids ): void {
        $image_ids = array_values( array_filter( array_map( 'intval', $image_ids ) ) );
        if ( empty( $image_ids ) ) {
            $p->set_image_id( 0 );
            $p->set_gallery_image_ids( [] );
            return;
        }
        $p->set_image_id( array_shift( $image_ids ) );     // featured
        $p->set_gallery_image_ids( $image_ids );           // gallery
    }

    public function create( WP_REST_Request $req ) {
        try {
            $p = new WC_Product_Simple();

            // Core fields
            $p->set_name( sanitize_text_field( $req['name'] ) );
            if ( $req['sku'] ) {
                $p->set_sku( sanitize_text_field( $req['sku'] ) );
            }
            $p->set_regular_price( wc_format_decimal( $req['price'] ) );

            if ( isset($req['short_description']) ) {
                $p->set_short_description( wp_kses_post( $req['short_description'] ) );
            }

            // Inventory
            $manage = isset($req['manage_stock']) ? (bool)$req['manage_stock'] : false;
            $p->set_manage_stock( $manage );
            if ( $manage && isset($req['stock']) ) {
                $p->set_stock_quantity( (int) $req['stock'] );
            }
            if ( isset($req['stock_status']) ) {
                $ss = sanitize_text_field( $req['stock_status'] );
                if ( in_array($ss, ['instock','outofstock','onbackorder'], true) ) {
                    $p->set_stock_status($ss);
                }
            }

            // Status
            $status = $req['status'] ? sanitize_text_field($req['status']) : 'publish';
            $p->set_status( in_array($status, ['publish','draft','pending'], true) ? $status : 'publish' );

            // Save first to get ID
            $p->save();

            // Categories (IDs)
            if ( ! empty($req['category_ids']) && is_array($req['category_ids']) ) {
                $cat_ids = array_map('intval', $req['category_ids']);
                wp_set_object_terms( $p->get_id(), $cat_ids, 'product_cat', false );
            } else {
                wp_set_object_terms( $p->get_id(), [], 'product_cat', false );
            }

            // Vendors (IDs preferred; fallback to legacy 'vendor' name)
            if ( taxonomy_exists('wcss_vendor') ) {
                if ( ! empty( $req['vendor_ids'] ) && is_array( $req['vendor_ids'] ) ) {
                    $vids = array_map('intval', (array) $req['vendor_ids']);
                    wp_set_object_terms( $p->get_id(), $vids, 'wcss_vendor', false );
                } elseif ( ! empty( $req['vendor'] ) ) {
                    $vn = sanitize_text_field( $req['vendor'] );
                    $t  = term_exists( $vn, 'wcss_vendor' );
                    if ( ! $t || is_wp_error($t) ) $t = wp_insert_term( $vn, 'wcss_vendor' );
                    if ( ! is_wp_error($t) ) wp_set_object_terms( $p->get_id(), [ (int) $t['term_id'] ], 'wcss_vendor', false );
                } else {
                    wp_set_object_terms( $p->get_id(), [], 'wcss_vendor', false );
                }
            }

            // Images (attachment IDs)
            if ( isset($req['images']) && is_array($req['images']) ) {
                $this->set_product_images( $p, $req['images'] );
                $p->save();
            }

            return rest_ensure_response( $this->dto( $p ) );
        } catch ( Throwable $e ) {
            return new WP_Error( 'wcss_product_create_failed', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    public function update( WP_REST_Request $req ) {
        
        $p = wc_get_product( (int) $req['id'] );
        if ( ! $p ) return new WP_Error( 'not_found', 'Product not found', [ 'status' => 404 ] );

        $body = $req->get_json_params() ?: [];
        try {
            if ( array_key_exists('name', $body) )  $p->set_name( sanitize_text_field($body['name']) );
            if ( array_key_exists('sku', $body) )   $p->set_sku( sanitize_text_field($body['sku']) );
            if ( array_key_exists('price', $body) ) $p->set_regular_price( wc_format_decimal($body['price']) );
            if ( array_key_exists('short_description', $body) ) $p->set_short_description( wp_kses_post($body['short_description']) );

            if ( array_key_exists('manage_stock', $body) ) $p->set_manage_stock( (bool)$body['manage_stock'] );
            if ( array_key_exists('stock', $body) )        $p->set_stock_quantity( (int)$body['stock'] );
            if ( array_key_exists('stock_status', $body) ) {
                $ss = sanitize_text_field( $body['stock_status'] );
                if ( in_array($ss, ['instock','outofstock','onbackorder'], true) ) $p->set_stock_status($ss);
            }

            if ( array_key_exists('status', $body) ) {
                $st = sanitize_text_field($body['status']);
                if ( in_array($st, ['publish','draft','pending'], true) ) $p->set_status($st);
            }

            $p->save();

            if ( array_key_exists('category_ids', $body) && is_array($body['category_ids']) ) {
                $cat_ids = array_map('intval', $body['category_ids']);
                wp_set_object_terms( $p->get_id(), $cat_ids, 'product_cat', false );
            }

            // Vendors
            if ( taxonomy_exists('wcss_vendor') ) {
                if ( array_key_exists('vendor_ids', $body) && is_array($body['vendor_ids']) ) {
                    wp_set_object_terms( $p->get_id(), array_map('intval',$body['vendor_ids']), 'wcss_vendor', false );
                } elseif ( array_key_exists('vendor', $body) ) {
                    $name = trim((string)$body['vendor']);
                    if ( $name === '' ) {
                        wp_set_object_terms( $p->get_id(), [], 'wcss_vendor', false );
                    } else {
                        $t = term_exists( $name, 'wcss_vendor' );
                        if ( ! $t || is_wp_error($t) ) $t = wp_insert_term( $name, 'wcss_vendor' );
                        if ( ! is_wp_error($t) ) {
                            wp_set_object_terms( $p->get_id(), [ (int) $t['term_id'] ], 'wcss_vendor', false );
                        }
                    }
                }
            }

            if ( array_key_exists('images', $body) && is_array($body['images']) ) {
                $this->set_product_images( $p, $body['images'] );
                $p->save();
            }

            return rest_ensure_response( $this->dto( $p ) );
        } catch ( Throwable $e ) {
            return new WP_Error( 'wcss_product_update_failed', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    public function meta( WP_REST_Request $req ) {
        $out = [ 'categories' => [], 'brands' => [], 'vendors' => [] ];
    
        // Categories
        $cats = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ] );
        if ( ! is_wp_error( $cats ) ) {
            foreach ( $cats as $t ) {
                $out['categories'][] = [ 'id' => (int) $t->term_id, 'name' => $t->name, 'parent' => (int) $t->parent ];
            }
        }
    
        // Brand
        $brands = taxonomy_exists('wcss_brand') ? get_terms( [ 'taxonomy' => 'wcss_brand', 'hide_empty' => false ] ) : [];
        if ( ! is_wp_error( $brands ) ) {
            foreach ( $brands as $t ) {
                $out['brands'][] = [ 'id' => (int) $t->term_id, 'name' => $t->name ];
            }
        }
    
        // Vendor
        $vendors = taxonomy_exists('wcss_vendor') ? get_terms( [ 'taxonomy' => 'wcss_vendor', 'hide_empty' => false ] ) : [];
        if ( ! is_wp_error( $vendors ) ) {
            foreach ( $vendors as $t ) {
                $out['vendors'][] = [ 'id' => (int) $t->term_id, 'name' => $t->name ];
            }
        }
    
        return rest_ensure_response( $out );
    }

    public function enqueue_assets() {
        // existing css/js enqueues…
        wp_enqueue_media(); 
    
        wp_localize_script( 'wcss-manager', 'WCSSM', [
            'rest'  => esc_url_raw( rest_url( 'wcss/v1/' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ]);
    }

    public function create_category( WP_REST_Request $req ) {
        $name   = trim( (string) $req->get_param('name') );
        $parent = (int) $req->get_param('parent');
    
        if ( $name === '' ) return new WP_Error('wcss_bad_name', 'Category name required', [ 'status'=>400 ]);
    
        // If exists, return it
        $existing = term_exists( $name, 'product_cat', $parent ?: 0 );
        if ( $existing && ! is_wp_error($existing) ) {
            $t = get_term( (int) $existing['term_id'] );
            return rest_ensure_response([ 'id'=>(int)$t->term_id, 'name'=>$t->name, 'parent'=>(int)$t->parent ]);
        }
    
        $res = wp_insert_term( $name, 'product_cat', [ 'parent' => $parent ?: 0 ] );
        if ( is_wp_error( $res ) ) return $res;
    
        $t = get_term( (int) $res['term_id'] );
        return rest_ensure_response([ 'id'=>(int)$t->term_id, 'name'=>$t->name, 'parent'=>(int)$t->parent ]);
    }

    public function create_vendor( WP_REST_Request $req ) {
        $name = trim( (string) $req->get_param('name') );
        if ( $name === '' ) return new WP_Error('wcss_bad_name', 'Vendor name required', [ 'status'=>400 ]);
    
        if ( ! taxonomy_exists('wcss_vendor') ) {
            return new WP_Error('wcss_vendor_missing', 'Vendor taxonomy not registered', [ 'status'=>500 ]);
        }
    
        // If exists, return it
        $existing = term_exists( $name, 'wcss_vendor' );
        if ( $existing && ! is_wp_error($existing) ) {
            $t = get_term( (int) $existing['term_id'] );
            return rest_ensure_response([ 'id'=>(int)$t->term_id, 'name'=>$t->name ]);
        }
    
        $res = wp_insert_term( $name, 'wcss_vendor' );
        if ( is_wp_error( $res ) ) return $res;
    
        $t = get_term( (int) $res['term_id'] );
        return rest_ensure_response([ 'id'=>(int)$t->term_id, 'name'=>$t->name ]);
    }

    // product export method
    public function export_products_csv_old( WP_REST_Request $req ) {
        if ( ! class_exists( 'WC_Product_CSV_Exporter' ) ) {
            return new WP_Error( 'wc_missing', 'WooCommerce exporter not available', [ 'status' => 500 ] );
        }
    
        // Optional: reuse your list filter (search, status, etc.)
        $search = sanitize_text_field( (string) $req->get_param( 'search' ) );
    
        // Load product IDs similarly to your list endpoint
        $args = [
            'limit'   => -1,
            'return'  => 'ids',
            'status'  => array_keys( wc_get_product_statuses() ),
            'orderby' => 'date',
            'order'   => 'DESC',
        ];
        if ( $search !== '' ) {
            $args['s'] = $search; // Woo’s product query supports 's' for keyword
        }
    
        $ids = wc_get_products( $args );
    
        // Build CSV using WooCommerce default exporter
        $exporter = new WC_Product_CSV_Exporter();
        $exporter->set_export_columns( array_keys( $exporter->get_default_column_names() ) );
        $exporter->set_product_ids( $ids );
        $exporter->generate();                         // generates into internal buffer
        $csv = $exporter->get_csv_data();              // fetch CSV string
    
        $filename = 'products-' . date( 'Ymd-His' ) . '.csv';
        $response = new WP_REST_Response( $csv, 200 );
        $response->header( 'Content-Type', 'text/csv; charset=utf-8' );
        $response->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
    
        return $response;
    }

    public function export_products_csv_1( WP_REST_Request $req ) {
        // WooCommerce + exporter available?
        if ( ! function_exists( 'WC' ) ) {
            return new WP_Error( 'wc_missing', 'WooCommerce not loaded', [ 'status' => 500 ] );
        }
        if ( ! class_exists( 'WC_Product_CSV_Exporter', false ) ) {
            $file = trailingslashit( WC()->plugin_path() ) . 'includes/export/class-wc-product-csv-exporter.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            } else {
                return new WP_Error( 'wc_missing', 'WooCommerce exporter not found', [ 'status' => 500 ] );
            }
        }
    
        // --- Collect product IDs (broad + version-safe) ---
        $search = sanitize_text_field( (string) $req->get_param( 'search' ) );
    
        // statuses to include (fallback if WC helper missing)
        $statuses = function_exists( 'wc_get_product_statuses' )
            ? array_keys( wc_get_product_statuses() )           // publish,draft,pending,private
            : array( 'publish', 'draft', 'pending', 'private' );
    
        $q_args = array(
            'post_type'        => 'product',
            'post_status'      => $statuses,
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
        );
    
        // Add simple title/content search
        if ( $search !== '' ) {
            $q_args['s'] = $search;
    
            // ALSO search by SKU (WP core doesn't search meta)
            $q_args['meta_query'] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_sku',
                    'value'   => $search,
                    'compare' => 'LIKE',
                ),
            );
        }
    
        $ids = get_posts( $q_args );
    
        // Optional fallback: if the catalog has only variations and no stand-alone products
        if ( empty( $ids ) ) {
            $var_ids = get_posts( array(
                'post_type'        => 'product_variation',
                'post_status'      => $statuses,
                'posts_per_page'   => -1,
                'fields'           => 'ids',
                'orderby'          => 'ID',
                'order'            => 'ASC',
                'suppress_filters' => true,
            ) );
            // Exporter accepts product IDs; including variations is okay when include_variations=true
            $ids = $var_ids;
        }
    
        if ( empty( $ids ) ) {
            return new WP_Error( 'wc_export_empty', 'No products match your filter.', [ 'status' => 200 ] );
        }
    
        // --- Configure exporter ---
        $exporter = new WC_Product_CSV_Exporter();
    
        if ( method_exists( $exporter, 'set_product_ids' ) ) {
            $exporter->set_product_ids( array_map( 'intval', $ids ) );
        }
        if ( method_exists( $exporter, 'set_include_variations' ) ) {
            $exporter->set_include_variations( true );
        }
        if ( method_exists( $exporter, 'set_export_columns' ) ) {
            $exporter->set_export_columns( array_keys( $exporter->get_default_column_names() ) );
        }
        if ( method_exists( $exporter, 'set_filename' ) ) {
            $exporter->set_filename( 'products-' . date( 'Ymd-His' ) . '.csv' );
        }
        if ( method_exists( $exporter, 'generate' ) ) {
            $exporter->generate();
        }
    
        // --- Obtain CSV content (version-safe) ---
        $csv = '';
        if ( method_exists( $exporter, 'get_csv' ) ) {
            $csv = (string) $exporter->get_csv();
        } else {
            ob_start();
            if ( method_exists( $exporter, 'export' ) ) {
                $exporter->export();       // writes to output buffer
            } elseif ( method_exists( $exporter, 'download' ) ) {
                // 'download()' sends headers in some WC versions; last resort
                $exporter->download();
            }
            $csv = (string) ob_get_clean();
        }
    
        if ( $csv === '' ) {
            return new WP_Error( 'wc_export_empty', 'Exporter returned no data.', [ 'status' => 500 ] );
        }
    
        // Return as downloadable CSV
        $filename = 'products-' . date( 'Ymd-His' ) . '.csv';
        $resp = new WP_REST_Response( $csv, 200 );
        $resp->header( 'Content-Type', 'text/csv; charset=utf-8' );
        $resp->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
        $resp->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        return $resp;
    }

    public function export_products_csv( WP_REST_Request $req ) {
        // WooCommerce + exporter available?
        if ( ! function_exists( 'WC' ) ) {
            return new WP_Error( 'wc_missing', 'WooCommerce not loaded', [ 'status' => 500 ] );
        }
        if ( ! class_exists( 'WC_Product_CSV_Exporter', false ) ) {
            $file = trailingslashit( WC()->plugin_path() ) . 'includes/export/class-wc-product-csv-exporter.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            } else {
                return new WP_Error( 'wc_missing', 'WooCommerce exporter not found', [ 'status' => 500 ] );
            }
        }
    
                // --- Collect product IDs (robust) ---
            $search = sanitize_text_field( (string) $req->get_param( 'search' ) );

            // Allowed statuses (fallback if helper missing)
            $statuses = function_exists( 'wc_get_product_statuses' )
                ? array_keys( wc_get_product_statuses() )                 // publish,draft,pending,private
                : array( 'publish', 'draft', 'pending', 'private' );

            // 1) Try WooCommerce layer first (works even when HPOS / custom data stores are enabled)
            $args = array(
                'limit'   => -1,
                'status'  => $statuses,
                'return'  => 'ids',
            );
            if ( $search !== '' ) {
                // wc_get_products supports a broad "search" (title/content) and exact SKU via "sku"
                // We'll try both: exact SKU first, then broad search, then LIKE fallback (below).
                $ids  = wc_get_products( array_merge( $args, array( 'sku' => $search ) ) );
                $ids2 = wc_get_products( array_merge( $args, array( 'search' => $search ) ) );
                $ids  = array_unique( array_merge( (array) $ids, (array) $ids2 ) );
            } else {
                $ids = wc_get_products( $args );
            }

            // 2) If still empty and we have a search term, do a direct SKU LIKE lookup (partial match)
            if ( empty( $ids ) && $search !== '' ) {
                global $wpdb;
                $like = '%' . $wpdb->esc_like( $search ) . '%';
                $ids_from_sku = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT DISTINCT p.ID
                        FROM {$wpdb->posts} p
                        INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                        WHERE pm.meta_key = '_sku'
                        AND pm.meta_value LIKE %s
                        AND p.post_type IN ('product','product_variation')
                        AND p.post_status IN (" . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')',
                        array_merge( array( $like ), $statuses )
                    )
                );
                if ( $ids_from_sku ) {
                    $ids = array_unique( array_merge( (array) $ids, array_map( 'intval', $ids_from_sku ) ) );
                }
            }


            if ( empty( $ids ) ) {
                $var_ids = wc_get_products( array(
                    'type'    => array( 'variation' ),
                    'limit'   => -1,
                    'status'  => $statuses,
                    'return'  => 'ids',
                ) );
                $ids = (array) $var_ids;
            }
            
            $ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
            if ( empty( $ids ) ) {
                return new WP_Error( 'wc_export_empty', 'No products match your filter.', array( 'status' => 200 ) );
            }

            

        // --- Configure exporter ---
        $exporter = new WC_Product_CSV_Exporter();
    
        if ( method_exists( $exporter, 'set_product_ids' ) ) {
            $exporter->set_product_ids( array_map( 'intval', $ids ) );
        }
        if ( method_exists( $exporter, 'set_include_variations' ) ) {
            $exporter->set_include_variations( true );
        }
        if ( method_exists( $exporter, 'set_export_columns' ) ) {
            $exporter->set_export_columns( array_keys( $exporter->get_default_column_names() ) );
        }
        if ( method_exists( $exporter, 'set_filename' ) ) {
            $exporter->set_filename( 'products-' . date( 'Ymd-His' ) . '.csv' );
        }
        if ( method_exists( $exporter, 'generate' ) ) {
            $exporter->generate();
        }
    
        // --- Obtain CSV content (version-safe) ---
        $csv = '';
        if ( method_exists( $exporter, 'get_csv' ) ) {
            $csv = (string) $exporter->get_csv();
        } else {
            ob_start();
            if ( method_exists( $exporter, 'export' ) ) {
                $exporter->export();       // writes to output buffer
            } elseif ( method_exists( $exporter, 'download' ) ) {
                // 'download()' sends headers in some WC versions; last resort
                $exporter->download();
            }
            $csv = (string) ob_get_clean();
        }
    
        if ( $csv === '' ) {
            return new WP_Error( 'wc_export_empty', 'Exporter returned no data.', [ 'status' => 500 ] );
        }
    
        // Return as downloadable CSV
        $filename = 'products-' . date( 'Ymd-His' ) . '.csv';
        $resp = new WP_REST_Response( $csv, 200 );
        $resp->header( 'Content-Type', 'text/csv; charset=utf-8' );
        $resp->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
        $resp->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        return $resp;
    }


}