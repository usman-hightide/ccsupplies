<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$current_user = wp_get_current_user();
$view = function_exists( 'get_query_var' ) ? (string) get_query_var( 'wcss' ) : '';
$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

$display_name = $current_user->display_name ?: $current_user->user_login;
$name_words   = preg_split( '/\s+/', trim( preg_replace( '/[^A-Za-z0-9 ]/', ' ', $display_name ) ) );
$initials     = strtoupper( substr( $name_words[0] ?? '?', 0, 1 ) . substr( $name_words[1] ?? '', 0, 1 ) );

$pending_count = class_exists( 'WCSS_REST_Reports' ) ? WCSS_REST_Reports::pending_summary()['count'] : 0;
$store_count   = (int) wp_count_posts( 'store_location' )->publish;
$vendor_count  = (int) wp_count_terms( [ 'taxonomy' => 'wcss_vendor', 'hide_empty' => false ] );

$logout_url = function_exists( 'wc_get_account_endpoint_url' )
	? wc_get_account_endpoint_url( 'customer-logout' )
	: wp_logout_url( home_url( '/' ) );

$svg = function ( $inner ) {
	return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
};

$nav_groups = array(
	'Overview' => array(
		array(
			'label'  => 'Dashboard',
			'url'    => home_url( '/manager/' ),
			'active' => ( $view === 'dashboard' || $view === '' || preg_match( '#/manager/?$#', $path ) ),
			'icon'   => $svg( '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>' ),
		),
		array(
			'label'  => 'Reports',
			'url'    => home_url( '/manager/reports' ),
			'active' => ( $view === 'reports' ),
			'icon'   => $svg( '<path d="M3 3v18h18"/><path d="M7 15v3M12 10v8M17 6v12"/>' ),
		),
	),
	'Operations' => array(
		array(
			'label'  => 'Orders',
			'url'    => home_url( '/manager/orders' ),
			'active' => ( $view === 'orders' ),
			'icon'   => $svg( '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>' ),
			'badge'  => $pending_count,
		),
		array(
			'label'  => 'Stores',
			'url'    => home_url( '/manager/stores' ),
			'active' => ( strpos( $view, 'stores' ) === 0 ),
			'icon'   => $svg( '<path d="M3 9 4.5 4h15L21 9"/><path d="M4 9v11h16V9"/><path d="M3 9h18"/><path d="M10 20v-6h4v6"/>' ),
		),
		array(
			'label'  => 'Users',
			'url'    => home_url( '/manager/users' ),
			'active' => ( $view === 'users' ),
			'icon'   => $svg( '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M17 4a4 4 0 0 1 0 8"/><path d="M22 21a7 7 0 0 0-4-6.3"/>' ),
		),
		array(
			'label'  => 'Emails',
			'url'    => home_url( '/manager/emails' ),
			'active' => ( $view === 'emails' ),
			'icon'   => $svg( '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>' ),
		),
	),
	'Catalog' => array(
		array(
			'label'  => 'Products',
			'url'    => home_url( '/manager/products' ),
			'active' => ( strpos( $view, 'products' ) === 0 ),
			'icon'   => $svg( '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/>' ),
		),
		array(
			'label'  => 'Vendors',
			'url'    => home_url( '/manager/vendorslist' ),
			'active' => ( strpos( $view, 'vendor' ) === 0 ),
			'icon'   => $svg( '<path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>' ),
		),
	),
	'Supplier catalogs' => array(
		array(
			'label'  => 'Amazon catalog',
			'url'    => home_url( '/manager/amazon' ),
			'active' => ( $view === 'amazon' || strpos( $path, '/amazon-catalog' ) !== false ),
			'icon'   => $svg( '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>' ),
		),
		array(
			'label'  => 'Grand & Toy',
			'url'    => home_url( '/manager/gt' ),
			'active' => ( $view === 'gt' || strpos( $path, '/gt-catalog' ) !== false || strpos( $path, '/gt-cart' ) !== false || strpos( $path, '/gt-checkout' ) !== false ),
			'icon'   => $svg( '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/>' ),
		),
	),
);
?>

<nav class="ccm-side" aria-label="Main navigation">
  <a class="ccm-brand" href="<?php echo esc_url( home_url( '/manager' ) ); ?>">
    <?php
    // Same logo as the storefront header (Appearance → Customize → Site identity); text if none is set.
    $ccm_logo_id  = (int) get_theme_mod( 'custom_logo' );
    $ccm_logo_url = $ccm_logo_id ? wp_get_attachment_image_url( $ccm_logo_id, 'full' ) : '';
    if ( $ccm_logo_url ) : ?>
      <img class="ccm-brand-logo" src="<?php echo esc_url( $ccm_logo_url ); ?>" alt="<?php esc_attr_e( 'Canna Cabana', 'wcss' ); ?>" width="133" height="47">
    <?php else : ?>
      <span class="ccm-brand-mark">CANNA</span><span class="ccm-brand-script">Cabana</span>
    <?php endif; ?>
  </a>
  <div class="ccm-brand-sub">Supply Manager</div>

  <?php foreach ( $nav_groups as $group_label => $items ) : ?>
    <div class="ccm-navlabel"><?php echo esc_html( $group_label ); ?></div>
    <?php foreach ( $items as $item ) : ?>
      <a class="ccm-navbtn<?php echo ! empty( $item['active'] ) ? ' on' : ''; ?>" href="<?php echo esc_url( $item['url'] ); ?>"<?php echo ! empty( $item['active'] ) ? ' aria-current="page"' : ''; ?>>
        <?php echo $item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php echo esc_html( $item['label'] ); ?>
        <?php if ( ! empty( $item['badge'] ) ) : ?>
          <span class="ccm-nbadge" aria-label="<?php echo esc_attr( $item['badge'] . ' orders awaiting approval' ); ?>"><?php echo (int) $item['badge']; ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <div class="ccm-side-foot">Data as of today, <span class="ccm-updated"><?php echo esc_html( wp_date( 'H:i' ) ); ?></span><br><?php echo (int) $store_count; ?> stores · <?php echo (int) $vendor_count; ?> vendors</div>
</nav>

<header class="ccm-topbar">
  <div class="ccm-gsearch">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
    <label for="ccm-gq" class="ccm-sr">Search orders, products and stores</label>
    <input id="ccm-gq" type="search" placeholder="Search orders, products, stores…" autocomplete="off">
    <div class="ccm-gresults" id="ccm-gresults" hidden></div>
  </div>
  <span class="ccm-topbar-gap" aria-hidden="true"></span>
  <?php
  // Refresh reloads dashboard KPIs/cards in place — only meaningful on Dashboard.
  // Same match as the Dashboard nav "active" state (path without query string).
  $ccm_path_only     = (string) ( wp_parse_url( $path, PHP_URL_PATH ) ?: $path );
  $ccm_is_dashboard  = ( $view === 'dashboard' || $view === '' || (bool) preg_match( '#/manager/?$#', $ccm_path_only ) );
  if ( $ccm_is_dashboard ) :
  ?>
  <button class="ccm-btn sm" type="button" id="ccm-refresh" title="Reload dashboard numbers">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
    Refresh
  </button>
  <?php endif; ?>
  <div class="ccm-me">
    <div class="ccm-avatar" aria-hidden="true"><?php echo esc_html( $initials ); ?></div>
    <div><div class="ccm-strong"><?php echo esc_html( $display_name ); ?></div><div class="ccm-muted ccm-small">Supply Manager</div></div>
  </div>
  <a class="ccm-btn sm danger" href="<?php echo esc_url( $logout_url ); ?>">Log out</a>
</header>
