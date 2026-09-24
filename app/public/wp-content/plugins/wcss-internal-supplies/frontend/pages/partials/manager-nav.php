<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$current_user = wp_get_current_user();
?>

<nav class="wcss-manager-nav">
  <div class="nav-left">
    <a href="<?php echo esc_url( home_url('/manager') ); ?>" class="brand">
      <!-- <img src="<?php echo esc_url( plugins_url('frontend/assets/img/logo.png', WCSS_FILE) ); ?>" alt="Logo" />
        -->
        <?php 
    if ( function_exists( 'the_custom_logo' ) && has_custom_logo() ) {
        $logo_id = get_theme_mod( 'custom_logo' );
        $logo    = wp_get_attachment_image_src( $logo_id , 'full' );
        if ( $logo ) {
            echo '<img src="' . esc_url( $logo[0] ) . '" alt="' . esc_attr( get_bloginfo('name') ) . '" class="dashboard-logo" />';
        }
    } else {
        // Fallback if no logo set in Site Identity
        echo '<span class="logo-text">' . esc_html( get_bloginfo('name') ) . '</span>';
    }
    ?>
    
      <span>Manager Dashboard</span>
    </a>
    <ul class="nav-links">
      <li><a href="<?php echo esc_url( home_url('/manager/') ); ?>">Dashboard</a></li>
      <li><a href="<?php echo esc_url( home_url('/manager/orders') ); ?>">Orders</a></li>
      <li><a href="<?php echo esc_url( home_url('/manager/products') ); ?>">Products</a></li>
      <li><a href="<?php echo esc_url( home_url('/manager/vendorslist') ); ?>">Vendors</a></li>
      <li><a href="<?php echo esc_url( home_url('/manager/stores') ); ?>">Stores</a></li>
      <li><a href="<?php echo esc_url( home_url('/manager/users') ); ?>">User</a></li>

    </ul>
  </div>

  <div class="nav-right">
    <span class="welcome">Hi, <?php echo esc_html( $current_user->display_name ?: $current_user->user_login ); ?></span>
        <?php
    $logout_url = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url( 'customer-logout' )
        : wp_logout_url( home_url('/') );
    ?>
    <a class="btn btn-light logout" href="<?php echo esc_url( $logout_url ); ?>">Logout</a>
  </div>
</nav>