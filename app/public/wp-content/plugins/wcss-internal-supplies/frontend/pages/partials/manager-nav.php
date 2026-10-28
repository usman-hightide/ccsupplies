<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$current_user = wp_get_current_user();
$view = function_exists( 'get_query_var' ) ? (string) get_query_var( 'wcss' ) : '';
$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

$nav_items = array(
	array(
		'key'   => 'dashboard',
		'label' => 'Dashboard',
		'url'   => home_url( '/manager/' ),
		'active'=> ( $view === 'dashboard' || $view === '' || preg_match( '#/manager/?$#', $path ) ),
		'icon'  => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>',
	),
	array(
		'key'   => 'orders',
		'label' => 'Orders',
		'url'   => home_url( '/manager/orders' ),
		'active'=> ( $view === 'orders' ),
		'icon'  => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5h11M9 12h11M9 19h11M4 5h.01M4 12h.01M4 19h.01"/></svg>',
	),
	array(
		'key'   => 'products',
		'label' => 'Products',
		'url'   => home_url( '/manager/products' ),
		'active'=> ( strpos( $view, 'products' ) === 0 ),
		'icon'  => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7L12 12l8.7-5M12 22V12"/></svg>',
	),
	array(
		'key'   => 'vendors',
		'label' => 'Vendors',
		'url'   => home_url( '/manager/vendorslist' ),
		'active'=> ( strpos( $view, 'vendor' ) === 0 ),
		'icon'  => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="3"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a3 3 0 0 1 0 5.75"/></svg>',
	),
	array(
		'key'   => 'stores',
		'label' => 'Stores',
		'url'   => home_url( '/manager/stores' ),
		'active'=> ( strpos( $view, 'stores' ) === 0 ),
		'icon'  => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V10l7-5 7 5v11M9 21v-6h6v6"/></svg>',
	),
	array(
		'key'   => 'users',
		'label' => 'User',
		'url'   => home_url( '/manager/users' ),
		'active'=> ( $view === 'users' ),
		'icon'  => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
	),
	array(
		'key'   => 'amazon-catalog',
		'label' => 'Amazon catalog',
		'url'   => home_url( '/amazon-catalog/' ),
		'active'=> ( strpos( $path, '/amazon-catalog' ) !== false ),
		'icon'  => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>',
	),
	array(
		'key'   => 'gt-catalog',
		'label' => 'Grand & Toy',
		'url'   => home_url( '/gt-catalog/' ),
		'active'=> ( strpos( $path, '/gt-catalog' ) !== false || strpos( $path, '/gt-cart' ) !== false || strpos( $path, '/gt-checkout' ) !== false ),
		'icon'  => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7h18l-1.5 12h-15L3 7z"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/></svg>',
	),
);
?>

<nav class="wcss-manager-nav">
  <div class="nav-left">
    <a href="<?php echo esc_url( home_url('/manager') ); ?>" class="brand">
        <?php
    if ( function_exists( 'the_custom_logo' ) && has_custom_logo() ) {
        $logo_id = get_theme_mod( 'custom_logo' );
        $logo    = wp_get_attachment_image_src( $logo_id , 'full' );
        if ( $logo ) {
            echo '<img src="' . esc_url( $logo[0] ) . '" alt="' . esc_attr( get_bloginfo('name') ) . '" class="dashboard-logo" />';
        }
    } else {
        echo '<span class="logo-text">' . esc_html( get_bloginfo('name') ) . '</span>';
    }
    ?>
      <span>Manager Dashboard</span>
    </a>
    <ul class="nav-links">
      <?php foreach ( $nav_items as $item ) : ?>
        <?php
        $has_children = ! empty( $item['children'] ) && is_array( $item['children'] );
        $li_class     = $has_children ? 'has-dropdown' : '';
        if ( ! empty( $item['active'] ) ) {
          $li_class .= ( $li_class !== '' ? ' ' : '' ) . 'is-active';
        }
        ?>
        <li class="<?php echo esc_attr( $li_class ); ?>">
          <a
            href="<?php echo esc_url( $item['url'] ); ?>"
            class="<?php echo ! empty( $item['active'] ) ? 'is-active' : ''; ?>"
            <?php echo $has_children ? 'aria-haspopup="true"' : ''; ?>
          >
            <span class="nav-ico" aria-hidden="true"><?php echo $item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            <span><?php echo esc_html( $item['label'] ); ?></span>
            <?php if ( $has_children ) : ?>
              <span class="nav-caret" aria-hidden="true">▾</span>
            <?php endif; ?>
          </a>
          <?php if ( $has_children ) : ?>
            <ul class="nav-dropdown" role="menu">
              <?php foreach ( $item['children'] as $child ) : ?>
                <li role="none">
                  <a
                    role="menuitem"
                    href="<?php echo esc_url( $child['url'] ); ?>"
                    class="<?php echo ! empty( $child['active'] ) ? 'is-active' : ''; ?>"
                  ><?php echo esc_html( $child['label'] ); ?></a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="nav-right">
    <span class="welcome">Hi, <?php echo esc_html( $current_user->display_name ?: $current_user->user_login ); ?></span>
        <?php
    $logout_url = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url( 'customer-logout' )
        : wp_logout_url( home_url('/') );
    ?>
    <a class="btn btn-light logout" href="<?php echo esc_url( $logout_url ); ?>">
      <span class="nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
      </span>
      Logout
    </a>
  </div>
</nav>
