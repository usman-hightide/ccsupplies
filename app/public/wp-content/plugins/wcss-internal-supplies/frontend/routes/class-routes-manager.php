<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_Route_Manager {

    public function __construct() {
        add_action( 'init',                [ $this, 'register_rewrite' ] );
        // add_filter( 'query_vars',          [ $this, 'add_qv' ] );
        add_action( 'template_redirect',   [ $this, 'maybe_render' ] );
        add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        add_filter('query_vars', function($vars){
            $vars[] = 'wcss';
            $vars[] = 'wcss_action';
            $vars[] = 'wcss_id';
            return $vars;
        });

    }

    /**
     * Pretty URLs:
     *  /manager                       -> wcssm=dashboard
     *  /manager/{view}                -> wcssm={view}
     *  /manager/{view}/{action}       -> wcssm={view}&wcssm_action={action}
     *  /manager/{view}/{action}/{id}  -> wcssm={view}&wcssm_action={action}&wcssm_id={id}
     */


    public function register_rewrite() {
        // Tell WP these query vars exist
        add_rewrite_tag( '%wcss%',        '([a-z0-9_-]+)' );
        add_rewrite_tag( '%wcss_action%', '([a-z0-9_-]+)' );
        add_rewrite_tag( '%wcss_id%',     '([0-9]+)' );

        // Allowed sections under /manager
        $sections = '(products|stores|orders|reports|dashboard)';
        
        add_rewrite_rule(
            '^manager/vendorslist/?$',
            'index.php?wcss=vendorslist',
            'top'
        );

        // /manager  → dashboard
        add_rewrite_rule(
            '^manager/?$',
            'index.php?wcss=dashboard',
            'top'
        );

        // /manager/{section}
        add_rewrite_rule(
            '^manager/' . $sections . '/?$',
            'index.php?wcss=$matches[1]',
            'top'
        );

        // /manager/{section}/{action}   (e.g., create|edit|view|list)
        add_rewrite_rule(
            '^manager/' . $sections . '/([a-z0-9_-]+)/?$',
            'index.php?wcss=$matches[1]&wcss_action=$matches[2]',
            'top'
        );

        // /manager/{section}/{action}/{id}  (numeric id)
        add_rewrite_rule(
            '^manager/' . $sections . '/([a-z0-9_-]+)/([0-9]+)/?$',
            'index.php?wcss=$matches[1]&wcss_action=$matches[2]&wcss_id=$matches[3]',
            'top'
        );

        add_rewrite_rule(
            '^manager/users/?$',
            'index.php?wcss=users',
            'top'
        );

        // add_rewrite_rule(
        //     '^manager/vendors/?$',
        //     'index.php?wcss=vendors',
        //     'top'
        // );
        
    }

    public function add_qv( $vars ) {
        $vars[] = 'wcss'; $vars[] = 'wcss_action'; $vars[] = 'wcss_id'; return $vars;
    }

    public function enqueue_assets() {


        if ( ! get_query_var( 'wcss' ) ) {
            error_log('WCSS enqueue skipped (no query var)');
            return;
        }
        error_log('WCSS enqueue firing for view: ' . get_query_var('wcss'));
    
        wp_enqueue_style(
            'wcss-manager',
            plugins_url( 'frontend/assets/css/manager.css', WCSS_FILE ),
            [],
            '1.0.0'
        );
    
        wp_enqueue_script(
            'wcss-manager',
            plugins_url( 'frontend/assets/js/manager.js', WCSS_FILE ),
            [ 'jquery' ],   // <— important
            rand(1, 1000),
            true
        );
    
        if ( in_array( get_query_var('wcss'), ['products','products-create','products-edit'], true ) ) {
            wp_enqueue_media();
        }
    
        wp_localize_script( 'wcss-manager', 'WCSSM', [

            'rest'         => esc_url_raw( rest_url( 'wcss/v1/' ) ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'view'         => (string) get_query_var( 'wcss' ),
            'action'       => (string) get_query_var( 'wcss_action' ),
            'id'           => absint( get_query_var( 'wcss_id' ) ),
            // convenience for building links from JS
            'manager_base' => trailingslashit( home_url( '/manager' ) ),
            'home'         => trailingslashit( home_url( '/' ) ),

        ]);
        

    }

    public function maybe_render_old() {
        $view   = get_query_var('wcss');
        $action = get_query_var('wcss_action');
        $id     = absint( get_query_var('wcss_id') );
    
        if ( ! $view ) return;
    
        // Auth
        if ( ! is_user_logged_in() || ( ! current_user_can('wcss_manage_portal') && ! current_user_can('manage_options') ) ) {
            auth_redirect(); exit;
        }
    
        // Enqueue media when needed
        if ( $view === 'products' && in_array( $action, [ 'create', 'edit' ], true ) ) {
            wp_enqueue_media();
        }
    
        // status_header(200);
        // nocache_headers();
        // show_admin_bar(false);
    
        $base = WCSS_DIR . 'frontend/pages/';
        $file = $this->resolve_view( $base, $view, $action );
    
        // Expose page context to JS
        $inline = 'window.WCSSM = Object.assign(window.WCSSM||{}, ' . wp_json_encode( [
            'view'   => $view,
            'action' => $action,
            'id'     => $id,
            'home'   => home_url( '/manager/' ),
            'manager_base' => home_url( '/manager/' ),
        ] ) . ');';
        wp_add_inline_script( 'wcss-manager', $inline, 'after' );
    
        // Render found page
        if ( $file && file_exists( $file ) ) {

            status_header( 200 );
            nocache_headers();
            show_admin_bar( false );
        
            ob_start();
            include $file;
            $content = ob_get_clean();
            ?>
            <!doctype html>
            <html <?php language_attributes(); ?>>
            <head>
                <meta charset="<?php bloginfo('charset'); ?>">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <?php wp_head(); ?>
            </head>
            <body <?php body_class('wcss-manager-body'); ?>>
                <?php echo $content; ?>
                <?php wp_footer(); ?>
            </body>
            </html>
            <?php
            exit;
        }
    
        // Manager 404 fallback (styled, inside the manager shell)
        status_header(404);
        nocache_headers();
        show_admin_bar( false );


        $not_found = $base . '404.php';


        ob_start();
        if ( file_exists( $not_found ) ) {
            include $not_found;
        } else {
            echo '<div class="wcssm-wrap"><div class="panel"><h1>Not found</h1><p>This page doesn’t exist in the manager portal.</p><p><a class="btn" href="' . esc_url( home_url('/manager') ) . '">← Back to Dashboard</a></p></div></div>';
        }
        $content = ob_get_clean();
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <?php wp_head(); ?>
        </head>
        <body <?php body_class('wcss-manager-body'); ?>>
            <?php echo $content; ?>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
    }

    public function maybe_render() {
        flush_rewrite_rules();
        
        $view   = get_query_var('wcss');
        $action = get_query_var('wcss_action');
        $id     = absint( get_query_var('wcss_id') );

        // if( isset( $_GET['testing'] ) ) {
        //     echo $view . "<br>";
        //     echo $action . "<br>";
        //     die("Testing view coming today!");
        // }
    
        // Is this a /manager request at all?
        $req_uri  = esc_url_raw( $_SERVER['REQUEST_URI'] ?? '' );
        $req_path = parse_url( $req_uri, PHP_URL_PATH ) ?? '';
        $is_manager_path = (bool) preg_match('#^/manager(?:/|$)#', $req_path);
    
        // If it's not a manager path and no manager view is set, bail early to let WP handle normally.
        if ( ! $is_manager_path && ! $view ) {
            return;
        }
    
        // Manager area requires auth/cap
        if ( ! is_user_logged_in() || ( ! current_user_can('wcss_manage_portal') && ! current_user_can('manage_options') ) ) {
            auth_redirect();
            exit;
        }
    
        // Media only on product create/edit
        if ( $view === 'products' && in_array( $action, ['create','edit'], true ) ) {
            wp_enqueue_media();
        }
    
        // Resolve the page fragment file (or 404)
        $base = WCSS_DIR . 'frontend/pages/';

        $file = $view ? $this->resolve_view( $base, $view, $action ) : false;

        $file_404 = $base . '404.php';
    
        // Pass context to JS
        $inline = 'window.WCSSM = Object.assign(window.WCSSM||{}, ' . wp_json_encode( [
            'view'         => $view,
            'action'       => $action,
            'id'           => $id,
            'home'         => home_url( '/manager/' ),
            'manager_base' => home_url( '/manager/' ),
        ] ) . ');';
        wp_add_inline_script( 'wcss-manager', $inline, 'after' );
    
        // Choose content: valid file or 404
        $is_valid = ( $file && file_exists( $file ) );
        $http_status = $is_valid ? 200 : 404;
    
        status_header( $http_status );
        nocache_headers();
        show_admin_bar( false );
    
        ob_start();
        if ( $is_valid ) {
            include $file;
        } else {
            if ( file_exists( $file_404 ) ) {
                include $file_404;
            } else {
                echo '<div class="wcssm-wrap"><h1>Manager</h1><p>Page not found.</p></div>';
            }
        }
        $content = ob_get_clean();
    
        // Shared shell so wp_head/wp_footer (and your enqueued manager assets) always print
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <?php wp_head(); ?>
        </head>
        <body <?php body_class('wcss-manager-body'); ?>>
            <?php echo $content; ?>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
    }

    

    private function resolve_view( string $base, string $view, ?string $action ) {
        
        // if(  isset( $_GET['testing'] ) ) {
        //     die("Testing view coming soon!");
        // }
        switch ( $view ) {
            case 'dashboard':
                return $base . 'dashboard.php';

            case 'products':
                if ( $action === 'create' ) return $base . 'products-create.php';
                if ( $action === 'edit'   ) return $base . 'products-edit.php';
                if ( $action === null || $action === '' ) return $base . 'products.php';
                return false; // unknown action → 404

            case 'stores':
                if ( $action === 'create' ) return $base . 'stores-create.php';
                if ( $action === 'edit'   ) return $base . 'stores-edit.php';
                if ( $action === null || $action === '' ) return $base . 'stores.php';
                return false;

            case 'orders':
                if ( $action === 'view'   ) return $base . 'orders-view.php';
                if ( $action === 'amazon-view' ) return $base . 'orders-amazon-view.php';
                if ( $action === null || $action === '' ) return $base . 'orders.php';
                return false;

            case 'reports':
                // if you have a reports index
                if ( $action === null || $action === '' ) return $base . 'reports.php';
                return false;

            case 'users':
                if ( $action === null || $action === '' ) return $base . 'users.php';
                return $base . 'users.php';

            case 'vendorslist':
                // die("vendors view coming soon!");
                if ( $action === null || $action === '' ) return $base . 'vendor/view.php';
                return $base . 'vendor/view.php';

            default:
                // unknown section → 404
                return false;
        }
    }


}