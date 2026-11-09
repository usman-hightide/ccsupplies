<?php
/**
 * WP Admin → WooCommerce → Vendor connections (SOW 2.9 / 2.10). Administrators only.
 *
 * One place to switch each vendor between its test and real system, and to enter credentials:
 *   Amazon Business: Trial Mode (orders are checked by Amazon but never bought) or Live (real purchases).
 *   Grand & Toy:     Stage (stage.grandandtoy.com, test POs) or Production (www.grandandtoy.com, real POs).
 *
 * Each field shows the value in use, ready to edit. A changed value is saved here and wins over
 * wp-config.php; an unchanged one keeps coming from wp-config.php. Secrets are masked until Show is clicked.
 * Both vendors start in their test mode, and going live needs a ticked confirmation.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_Vendor_Connections {
    const OPTION   = 'wcss_vendor_connections';
    const SLUG     = 'wcss-vendor-connections';
    const GT_STAGE = 'https://stage.grandandtoy.com/B2BIntegration/SubmitPO/SubmitPO_wholesale.aspx';
    const GT_PROD  = 'https://www.grandandtoy.com/B2BIntegration/SubmitPO/SubmitPO_wholesale.aspx';

    /** Field => [ label, wp-config constant it falls back to, secret ]. */
    const AMAZON_FIELDS = [
        'client_id'     => [ 'Client ID', 'AMAZON_CLIENT_ID', false ],
        'client_secret' => [ 'Client secret', 'AMAZON_CLIENT_SECRET', true ],
        'refresh_token' => [ 'Refresh token', 'AMAZON_REFRESH_TOKEN', true ],
        'user_email'    => [ 'Buyer email', 'AMAZON_USER_EMAIL', false ],
        'group_id'      => [ 'Buying group ID', 'AMAZON_GROUP_ID', false ],
    ];
    /** The theme's own defaults for these (cc_auto_vendor_submit_bootstrap_amazon), used when nothing else is set. */
    const AMAZON_DEFAULTS = [ 'user_email' => 'ecom@hightideinc.com', 'group_id' => 'OrderingAPI5259590399' ];
    const GT_FIELDS = [
        'from_identity' => [ 'From identity', 'CC_GT_FROM_IDENTITY', false ],
        'to_identity'   => [ 'To identity', 'CC_GT_TO_IDENTITY', false ],
        'shared_secret' => [ 'Shared secret', 'CC_GT_SHARED_SECRET', true ],
        'sender'        => [ 'Account number', 'CC_GT_STOCK_SENDER', false ],
    ];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'menu' ], 60 );
        add_action( 'admin_post_wcss_vendor_connections', [ $this, 'save' ] );
        add_action( 'admin_post_wcss_vendor_connections_check', [ $this, 'check' ] );
        // Other plugins' banners (licences, updates, upsells) push this page down; keep it clean.
        add_action( 'in_admin_header', function () {
            if ( ( $_GET['page'] ?? '' ) === self::SLUG ) {
                remove_all_actions( 'admin_notices' );
                remove_all_actions( 'all_admin_notices' );
            }
        }, 1000 );
    }

    /* ===================== Reading the settings ===================== */

    public static function all(): array {
        $o = get_option( self::OPTION, [] );
        return wp_parse_args( is_array( $o ) ? $o : [], [
            'amazon_mode' => 'trial', 'gt_mode' => 'stage',
            'amazon' => [], 'amazon_live' => [], 'gt_stage' => [], 'gt_prod' => [], 'log' => [],
        ] );
    }

    public static function amazon_live(): bool {
        return self::all()['amazon_mode'] === 'live';
    }

    public static function gt_production(): bool {
        return self::all()['gt_mode'] === 'production';
    }

    /**
     * Amazon credential for a mode (default: the current one).
     * Trial Mode: typed here, else wp-config.php, else the built-in default.
     * Live: its own value typed here, else the same value as Trial Mode (Amazon normally issues one set).
     */
    public static function amazon( string $key, string $mode = '' ): string {
        $mode = $mode !== '' ? $mode : ( self::amazon_live() ? 'live' : 'trial' );
        if ( $mode === 'live' ) {
            $v = (string) ( self::all()['amazon_live'][ $key ] ?? '' );
            if ( $v !== '' ) return $v;
        }
        $v = (string) ( self::all()['amazon'][ $key ] ?? '' );
        if ( $v !== '' ) return $v;
        $c = self::AMAZON_FIELDS[ $key ][1] ?? '';
        if ( $c && defined( $c ) ) return (string) constant( $c );
        return self::AMAZON_DEFAULTS[ $key ] ?? '';
    }

    /** Where Grand & Toy POs and stock checks go right now. */
    public static function gt_endpoint(): string {
        if ( self::gt_production() ) {
            $e = (string) ( self::all()['gt_prod']['endpoint'] ?? '' );
            return $e !== '' ? $e : self::GT_PROD;
        }
        $e = (string) ( self::all()['gt_stage']['endpoint'] ?? '' );
        if ( $e === '' && defined( 'CC_GT_STAGE_ENDPOINT' ) ) $e = (string) CC_GT_STAGE_ENDPOINT;
        return ( $e !== '' && wp_parse_url( $e, PHP_URL_HOST ) === 'stage.grandandtoy.com' ) ? $e : self::GT_STAGE;
    }

    /** Hosts a PO may be posted to in the current mode. */
    public static function gt_allowed_host( string $host ): bool {
        if ( self::gt_production() ) return in_array( $host, [ 'www.grandandtoy.com', 'grandandtoy.com' ], true );
        return $host === 'stage.grandandtoy.com';
    }

    /**
     * Grand & Toy credential for the current mode. Production never falls back to the stage
     * values in wp-config.php: Grand & Toy issues separate production credentials.
     */
    public static function gt( string $key ): string {
        if ( self::gt_production() ) {
            return (string) ( self::all()['gt_prod'][ $key ] ?? '' );
        }
        $v = (string) ( self::all()['gt_stage'][ $key ] ?? '' );
        if ( $v !== '' ) return $v;
        $c = self::GT_FIELDS[ $key ][1] ?? '';
        return $c && defined( $c ) ? (string) constant( $c ) : '';
    }

    /** What is missing before a vendor can run in the given mode. */
    public static function missing( string $vendor, string $mode = '' ): array {
        $out = [];
        if ( $vendor === 'amazon' ) {
            foreach ( self::AMAZON_FIELDS as $k => $f ) if ( self::amazon( $k, $mode ) === '' ) $out[] = $f[0];
            return $out;
        }
        $prod = $mode === 'production' || ( $mode === '' && self::gt_production() );
        foreach ( self::GT_FIELDS as $k => $f ) {
            if ( $k === 'sender' ) continue;
            $v = $prod ? (string) ( self::all()['gt_prod'][ $k ] ?? '' ) : self::gt( $k );
            if ( $v === '' ) $out[] = $f[0];
        }
        return $out;
    }

    /** Short label for screens and logs. */
    public static function label( string $vendor ): string {
        return $vendor === 'amazon' ? ( self::amazon_live() ? 'Live' : 'Trial Mode' ) : ( self::gt_production() ? 'Production' : 'Stage' );
    }

    /* ===================== Admin page ===================== */

    public function menu() {
        add_submenu_page( 'woocommerce', 'Vendor connections', 'Vendor connections', 'manage_options', self::SLUG, [ $this, 'page' ] );
    }

    /** Help under each field, in plain words. */
    const HELP = [
        'amazon'   => [
            'client_id'     => 'From the Amazon Business developer app. Starts with amzn1.application-oa2-client.',
            'client_secret' => 'From the same app. Shown as dots; click Show to see it.',
            'refresh_token' => 'Created when the Amazon Business account authorised the app. Starts with Atzr|. Shown as dots; click Show to see it.',
            'user_email'    => 'The Amazon Business user that orders are placed as.',
            'group_id'      => 'The Amazon Business buying group that pays for the orders.',
        ],
        'gt' => [
            'endpoint'      => 'The address Grand & Toy gave for purchase orders.',
            'from_identity' => 'Our ID with Grand & Toy.',
            'to_identity'   => 'Grand & Toy’s ID.',
            'shared_secret' => 'The password for this connection. Shown as dots; click Show to see it.',
            'sender'        => 'Our account number on orders and stock checks.',
        ],
    ];

    /** [ badge text, tone ] for where a field's value comes from. */
    private static function source( string $group, string $key, string $const ): array {
        $saved = (string) ( self::all()[ $group ][ $key ] ?? '' );
        if ( $saved !== '' ) return [ 'Saved on this page', 'saved' ];
        if ( $group === 'amazon_live' ) return [ 'Same as Trial Mode', 'default' ];
        if ( $group === 'gt_prod' ) {
            return $key === 'endpoint' ? [ 'Standard Grand & Toy address', 'server' ] : [ $key === 'sender' ? 'Optional' : 'Missing', $key === 'sender' ? 'default' : 'missing' ];
        }
        if ( $const && defined( $const ) && (string) constant( $const ) !== '' ) return [ 'Server setting (wp-config.php)', 'server' ];
        if ( $group === 'amazon' && isset( self::AMAZON_DEFAULTS[ $key ] ) ) return [ 'Built-in default', 'default' ];
        if ( $group === 'gt_stage' && $key === 'endpoint' ) return [ 'Standard stage address', 'server' ];
        if ( $key === 'sender' ) return [ 'Optional', 'default' ];
        return [ 'Missing', 'missing' ];
    }

    private static function mask( string $v ): string {
        return $v === '' ? '' : '••••' . substr( $v, -4 );
    }

    private static function const_for( string $group, string $key ): string {
        if ( $key === 'endpoint' ) return $group === 'gt_stage' ? 'CC_GT_STAGE_ENDPOINT' : '';
        $f = strpos( $group, 'amazon' ) === 0 ? self::AMAZON_FIELDS : self::GT_FIELDS;
        return (string) ( $f[ $key ][1] ?? '' );
    }

    /** The value now in use, shown under a non-secret field so the admin can see it without typing. */
    private static function in_use( string $group, string $key, string $const ): string {
        if ( $group === 'amazon' ) return self::amazon( $key, 'trial' );
        if ( $group === 'amazon_live' ) return self::amazon( $key, 'live' );
        if ( $group === 'gt_prod' ) return $key === 'endpoint' ? ( (string) ( self::all()['gt_prod']['endpoint'] ?? '' ) ?: self::GT_PROD ) : (string) ( self::all()['gt_prod'][ $key ] ?? '' );
        $v = (string) ( self::all()['gt_stage'][ $key ] ?? '' );
        if ( $v === '' && $const && defined( $const ) ) $v = (string) constant( $const );
        if ( $v === '' && $key === 'endpoint' ) $v = self::GT_STAGE;
        return $v;
    }

    private function field( string $group, string $key, array $f ) {
        [ $label, $const, $secret ] = $f;
        $saved = (string) ( self::all()[ $group ][ $key ] ?? '' );
        [ $src, $tone ] = self::source( $group, $key, $const );
        $id    = 'vc-' . $group . '-' . $key;
        $help  = self::HELP[ strpos( $group, 'amazon' ) === 0 ? 'amazon' : 'gt' ][ $key ] ?? '';
        // The value in use right now, ready to edit (saved here, else the server setting or default).
        $value = $saved !== '' ? $saved : self::in_use( $group, $key, $const );
        $ph    = $group === 'gt_prod' && $key !== 'sender' ? 'From Grand & Toy' : '';
        ?>
        <div class="vc-field">
          <div class="vc-field-l">
            <label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
            <span class="vc-badge is-<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $src ); ?></span>
          </div>
          <div class="vc-field-c">
            <div class="vc-inrow">
              <input type="<?php echo $secret ? 'password' : 'text'; ?>" class="vc-input" id="<?php echo esc_attr( $id ); ?>"
                name="<?php echo esc_attr( 'wcss_vc[' . $group . '][' . $key . ']' ); ?>"
                value="<?php echo esc_attr( $value ); ?>"
                autocomplete="<?php echo $secret ? 'new-password' : 'off'; ?>" spellcheck="false"
                placeholder="<?php echo esc_attr( $ph ); ?>"<?php echo $help ? ' aria-describedby="' . esc_attr( $id ) . '-h"' : ''; ?>>
              <?php if ( $secret ) : ?>
                <button type="button" class="button vc-eye" data-vc-eye="<?php echo esc_attr( $id ); ?>" aria-controls="<?php echo esc_attr( $id ); ?>" aria-pressed="false">Show</button>
              <?php endif; ?>
            </div>
            <?php if ( $help ) : ?><p class="vc-help" id="<?php echo esc_attr( $id ); ?>-h"><?php echo esc_html( $help ); ?></p><?php endif; ?>
            <?php if ( $saved !== '' ) : ?>
              <label class="vc-remove"><input type="checkbox" name="wcss_vc_clear[<?php echo esc_attr( $group ); ?>][]" value="<?php echo esc_attr( $key ); ?>"> Remove the value saved here<?php echo $group === 'gt_prod' ? '' : ( $group === 'amazon_live' ? ' and use the Trial Mode value' : ' and use the server setting' ); ?></label>
            <?php endif; ?>
          </div>
        </div>
        <?php
    }

    /** A mode choice drawn as a selectable tile. */
    private static function tile( string $name, string $value, bool $on, string $title, string $text, bool $danger = false ) {
        ?>
        <label class="vc-tile<?php echo $danger ? ' is-danger' : ''; ?>">
          <input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $on ); ?>>
          <span class="vc-tile-b"><span class="vc-tile-t"><?php echo esc_html( $title ); ?></span><span class="vc-tile-d"><?php echo esc_html( $text ); ?></span></span>
        </label>
        <?php
    }

    public function page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrators only.' );
        $s    = self::all();
        $note = get_transient( 'wcss_vc_notice_' . get_current_user_id() );
        if ( $note ) delete_transient( 'wcss_vc_notice_' . get_current_user_id() );
        $live = self::amazon_live(); $prod = self::gt_production();
        $am_miss = self::missing( 'amazon', 'live' ); $gt_miss = self::missing( 'gt', 'production' );
        $post = esc_url( admin_url( 'admin-post.php' ) );
        ?>
        <div class="wrap wcss-vc">
          <style>
            .wcss-vc{max-width:980px;--ink:#16181D;--muted:#5B6270;--line:#E2E5EA;--soft:#F6F7F9;--ok:#1B6B3A;--okbg:#E7F5EC;--bad:#A8261C;--badbg:#FDECEA;--info:#1F4FA8;--infobg:#E8F0FE;--warnbg:#FFF8EC}
            .wcss-vc h1{font-size:23px;font-weight:600;margin:8px 0 4px}
            .vc-lead{margin:0 0 18px;color:var(--muted);font-size:14px;max-width:720px}
            .vc-status{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:0 0 20px}
            .vc-stat{display:flex;gap:12px;align-items:flex-start;padding:14px 16px;border:1px solid var(--line);border-radius:10px;background:#fff}
            .vc-stat .dashicons{flex:none;width:22px;height:22px;font-size:22px;color:var(--info)}
            .vc-stat.is-live{border-color:#F3C4BF;background:#FFF7F6}.vc-stat.is-live .dashicons{color:var(--bad)}
            .vc-stat b{display:block;font-size:14px;color:var(--ink)}.vc-stat span{display:block;color:var(--muted);font-size:13px;margin-top:2px}
            .vc-card{background:#fff;border:1px solid var(--line);border-radius:12px;margin:0 0 20px;overflow:hidden}
            .vc-card-h{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:16px 20px;border-bottom:1px solid var(--line)}
            .vc-card-h h2{margin:0;font-size:17px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
            .vc-card-b{padding:18px 20px}
            .vc-pill{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;background:var(--infobg);color:var(--info)}
            .vc-pill::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
            .vc-pill.is-live{background:var(--badbg);color:var(--bad)}
            .vc-sec{margin:22px 0 10px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
            .vc-sec:first-child{margin-top:0}
            .vc-tiles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
            .vc-tile{display:flex;gap:10px;align-items:flex-start;padding:14px;border:1.5px solid var(--line);border-radius:10px;cursor:pointer;background:#fff}
            .vc-tile input{flex:0 0 auto;margin:2px 0 0!important}
            .vc-tile:has(input:checked){border-color:var(--info);background:#F5F8FF}
            .vc-tile.is-danger:has(input:checked){border-color:var(--bad);background:#FFF7F6}
            .vc-tile:focus-within{box-shadow:0 0 0 2px #fff,0 0 0 4px #2271b1}
            .vc-tile-t{display:block;font-weight:600;color:var(--ink);font-size:14px}
            .vc-tile-d{display:block;color:var(--muted);font-size:13px;margin-top:3px;line-height:1.45}
            .vc-confirm{margin:12px 0 0;padding:12px 14px;border:1px solid #F3C4BF;border-left:4px solid var(--bad);border-radius:8px;background:#FFF7F6;color:#5A1712;font-size:13px}
            .vc-confirm label{display:flex;gap:8px;align-items:flex-start}.vc-confirm input{margin-top:1px!important}
            .vc-confirm[hidden]{display:none}
            .vc-note{margin:12px 0 0;padding:10px 12px;border-radius:8px;background:var(--soft);color:var(--muted);font-size:13px}
            .vc-note.is-missing{background:var(--warnbg);color:#6B4A00}
            .vc-field{display:grid;grid-template-columns:230px minmax(0,1fr);gap:8px 20px;padding:12px 0;border-top:1px solid #EEF0F3}
            .vc-fields .vc-field:first-child{border-top:0;padding-top:4px}
            .vc-field-l label{display:block;font-weight:600;color:var(--ink);font-size:13.5px;margin:7px 0 6px}
            .vc-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11.5px;font-weight:500;background:var(--soft);color:var(--muted)}
            .vc-badge.is-saved{background:var(--infobg);color:var(--info)}.vc-badge.is-server{background:var(--okbg);color:var(--ok)}.vc-badge.is-missing{background:var(--badbg);color:var(--bad)}
            .vc-input{width:100%;max-width:520px;min-height:36px;padding:0 10px!important;border:1px solid #C9CED6!important;border-radius:6px!important;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px!important}
            .vc-input::placeholder{color:#8A9099;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
            .vc-help{margin:6px 0 0;color:var(--muted);font-size:12.5px}
            .vc-inrow{display:flex;gap:8px;align-items:center;max-width:600px}
            .vc-inrow .vc-input{flex:1 1 auto;min-width:0}
            .vc-eye{flex:none;min-width:64px}
            .vc-remove{display:inline-flex;gap:6px;align-items:center;margin-top:6px;font-size:12.5px;color:var(--bad)}
            .vc-check{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
            .vc-check .vc-input{width:200px;font-family:inherit}
            .vc-foot{position:sticky;bottom:0;z-index:5;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0 0 20px;padding:12px 20px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 -4px 14px rgba(16,24,40,.06)}
            .vc-foot p{margin:0;color:var(--muted);font-size:13px}
            .vc-log{width:100%;border-collapse:collapse;font-size:13px}
            .vc-log th{text-align:left;color:var(--muted);font-weight:600;padding:8px 10px;border-bottom:1px solid var(--line)}
            .vc-log td{padding:9px 10px;border-bottom:1px solid #EEF0F3;vertical-align:top}
            .vc-empty{margin:0;color:var(--muted)}
            @media (max-width:782px){
              .vc-status,.vc-tiles{grid-template-columns:1fr}
              .vc-field{grid-template-columns:1fr;gap:4px}
              .vc-field-l label{margin:0 6px 4px 0;display:inline-block}
              .vc-card-h,.vc-card-b{padding:14px}
              .vc-input{max-width:none;min-height:40px;font-size:16px!important}
              .vc-check .vc-input{width:100%}
              .vc-foot{padding:10px 14px}.vc-foot p{display:none}.vc-foot .button{width:100%;text-align:center}
              .vc-card-h .vc-check{width:100%}.vc-card-h .vc-check .button{flex:1 1 auto;text-align:center}
              .vc-log thead{display:none}.vc-log tr{display:block;padding:8px 0;border-bottom:1px solid #EEF0F3}.vc-log td{display:block;border:0;padding:2px 0}
            }
          </style>

          <h1>Vendor connections</h1>
          <p class="vc-lead">Choose whether approved orders go to each vendor’s test system or are real purchases, and keep the credentials each vendor issued. Only administrators can see this page.</p>

          <?php if ( $note ) : ?>
            <div class="notice notice-<?php echo esc_attr( $note['type'] ); ?> is-dismissible"><p><?php echo esc_html( $note['text'] ); ?></p></div>
          <?php endif; ?>

          <div class="vc-status" role="status">
            <div class="vc-stat<?php echo $live ? ' is-live' : ''; ?>">
              <span class="dashicons <?php echo $live ? 'dashicons-warning' : 'dashicons-shield'; ?>" aria-hidden="true"></span>
              <div><b>Amazon Business: <?php echo $live ? 'Live' : 'Trial Mode'; ?></b><span><?php echo $live ? 'Approved orders are bought and charged to the Amazon Business account.' : 'Amazon checks each order and replies. Nothing is bought.'; ?></span></div>
            </div>
            <div class="vc-stat<?php echo $prod ? ' is-live' : ''; ?>">
              <span class="dashicons <?php echo $prod ? 'dashicons-warning' : 'dashicons-shield'; ?>" aria-hidden="true"></span>
              <div><b>Grand &amp; Toy: <?php echo $prod ? 'Production' : 'Stage'; ?></b><span><?php echo $prod ? 'Approved orders are real purchase orders.' : 'Purchase orders go to Grand & Toy’s test system. Nothing ships.'; ?></span></div>
            </div>
          </div>

          <form method="post" action="<?php echo $post; ?>" id="vc-form">
            <input type="hidden" name="action" value="wcss_vendor_connections">
            <?php wp_nonce_field( 'wcss_vendor_connections' ); ?>

            <section class="vc-card" aria-labelledby="vc-amazon-h">
              <div class="vc-card-h">
                <h2 id="vc-amazon-h">Amazon Business <span class="vc-pill<?php echo $live ? ' is-live' : ''; ?>"><?php echo $live ? 'Live' : 'Trial Mode'; ?></span></h2>
                <div class="vc-check">
                  <button type="submit" class="button" form="vc-check-amazon">Check Amazon sign-in</button>
                </div>
              </div>
              <div class="vc-card-b">
                <h3 class="vc-sec">Ordering mode</h3>
                <div class="vc-tiles" role="radiogroup" aria-label="Amazon ordering mode">
                  <?php self::tile( 'wcss_vc[amazon_mode]', 'trial', ! $live, 'Trial Mode', 'For testing. Amazon checks each order and returns prices and delivery dates. Nothing is bought or charged.' ); ?>
                  <?php self::tile( 'wcss_vc[amazon_mode]', 'live', $live, 'Live', 'Real ordering. Every approved order is bought and charged to the Amazon Business account.', true ); ?>
                </div>
                <?php if ( ! $live ) : ?>
                  <div class="vc-confirm" data-vc-confirm="amazon_mode" data-vc-when="live" hidden>
                    <label><input type="checkbox" name="wcss_vc_confirm_amazon" value="1"> I understand that every approved Amazon order will be bought and charged to the Amazon Business account.</label>
                  </div>
                <?php endif; ?>
                <p class="vc-note<?php echo $am_miss ? ' is-missing' : ''; ?>"><?php echo $am_miss ? esc_html( 'Before Live can be turned on, add: ' . implode( ', ', $am_miss ) . '.' ) : 'The credentials below follow the mode you pick: Trial Mode shows the trial set, Live shows the live set. Each set is saved separately.'; ?></p>

                <div data-vc-set="amazon_mode" data-vc-when="trial">
                  <h3 class="vc-sec">Trial Mode credentials</h3>
                  <div class="vc-fields">
                    <?php foreach ( self::AMAZON_FIELDS as $k => $f ) $this->field( 'amazon', $k, $f ); ?>
                  </div>
                </div>
                <div data-vc-set="amazon_mode" data-vc-when="live">
                  <h3 class="vc-sec">Live credentials</h3>
                  <p class="vc-help" style="margin:-4px 0 6px">Amazon normally uses the same credentials for Trial Mode and Live, so these start as a copy. Change a field only if Amazon gave a different value for Live; it is then saved separately.</p>
                  <div class="vc-fields">
                    <?php foreach ( self::AMAZON_FIELDS as $k => $f ) $this->field( 'amazon_live', $k, $f ); ?>
                  </div>
                </div>
              </div>
            </section>

            <section class="vc-card" aria-labelledby="vc-gt-h">
              <div class="vc-card-h">
                <h2 id="vc-gt-h">Grand &amp; Toy <span class="vc-pill<?php echo $prod ? ' is-live' : ''; ?>"><?php echo $prod ? 'Production' : 'Stage'; ?></span></h2>
                <div class="vc-check">
                  <label class="screen-reader-text" for="vc-part">Grand &amp; Toy part number</label>
                  <input type="text" class="vc-input" id="vc-part" name="part" form="vc-check-gt" placeholder="Grand &amp; Toy part number" autocomplete="off">
                  <button type="submit" class="button" form="vc-check-gt">Check stock</button>
                </div>
              </div>
              <div class="vc-card-b">
                <h3 class="vc-sec">Ordering mode</h3>
                <div class="vc-tiles" role="radiogroup" aria-label="Grand &amp; Toy ordering mode">
                  <?php self::tile( 'wcss_vc[gt_mode]', 'stage', ! $prod, 'Stage', 'For testing. Purchase orders go to stage.grandandtoy.com. Nothing ships.' ); ?>
                  <?php self::tile( 'wcss_vc[gt_mode]', 'production', $prod, 'Production', 'Real ordering. Purchase orders go to www.grandandtoy.com and are filled.', true ); ?>
                </div>
                <?php if ( ! $prod ) : ?>
                  <div class="vc-confirm" data-vc-confirm="gt_mode" data-vc-when="production" hidden>
                    <label><input type="checkbox" name="wcss_vc_confirm_gt" value="1"> I understand that every approved Grand &amp; Toy order will be a real purchase order.</label>
                  </div>
                <?php endif; ?>
                <p class="vc-note<?php echo $gt_miss ? ' is-missing' : ''; ?>"><?php echo $gt_miss ? esc_html( 'Before Production can be turned on, add the production ' . implode( ', ', $gt_miss ) . ' from Grand & Toy.' ) : 'The credentials below follow the mode you pick: Stage shows the stage set, Production shows the production set. Each set is saved separately.'; ?></p>

                <div data-vc-set="gt_mode" data-vc-when="stage">
                <h3 class="vc-sec">Stage credentials</h3>
                <div class="vc-fields">
                  <?php $this->field( 'gt_stage', 'endpoint', [ 'Stage address', 'CC_GT_STAGE_ENDPOINT', false ] ); ?>
                  <?php foreach ( self::GT_FIELDS as $k => $f ) $this->field( 'gt_stage', $k, $f ); ?>
                </div>

                </div>
                <div data-vc-set="gt_mode" data-vc-when="production">
                <h3 class="vc-sec">Production credentials</h3>
                <p class="vc-help" style="margin:-4px 0 6px">Grand &amp; Toy issues these separately. Production never uses the stage values.</p>
                <div class="vc-fields">
                  <?php $this->field( 'gt_prod', 'endpoint', [ 'Production address', '', false ] ); ?>
                  <?php foreach ( self::GT_FIELDS as $k => $f ) $this->field( 'gt_prod', $k, $f ); ?>
                </div>
                </div>
              </div>
            </section>

            <div class="vc-foot">
              <p>Saving applies to the next order sent to a vendor. Orders already sent don’t change.</p>
              <button type="submit" class="button button-primary button-large">Save changes</button>
            </div>
          </form>

          <form method="post" action="<?php echo $post; ?>" id="vc-check-amazon">
            <input type="hidden" name="action" value="wcss_vendor_connections_check"><input type="hidden" name="vendor" value="amazon">
            <input type="hidden" name="_vcnonce" value="<?php echo esc_attr( wp_create_nonce( 'wcss_vendor_connections_check' ) ); ?>">
          </form>
          <form method="post" action="<?php echo $post; ?>" id="vc-check-gt">
            <input type="hidden" name="action" value="wcss_vendor_connections_check"><input type="hidden" name="vendor" value="gt">
            <input type="hidden" name="_vcnonce" value="<?php echo esc_attr( wp_create_nonce( 'wcss_vendor_connections_check' ) ); ?>">
          </form>

          <section class="vc-card" aria-labelledby="vc-log-h">
            <div class="vc-card-h"><h2 id="vc-log-h">Change history</h2></div>
            <div class="vc-card-b">
              <?php if ( $s['log'] ) : ?>
                <table class="vc-log"><thead><tr><th>When</th><th>Who</th><th>What changed</th></tr></thead><tbody>
                <?php foreach ( array_slice( array_reverse( $s['log'] ), 0, 20 ) as $l ) : ?>
                  <tr><td><?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $l['at'] ) ) ); ?></td><td><?php echo esc_html( $l['by'] ); ?></td><td><?php echo esc_html( $l['what'] ); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
              <?php else : ?>
                <p class="vc-empty">No changes yet. Every mode or credential change is recorded here with who made it.</p>
              <?php endif; ?>
            </div>
          </section>

          <script>
            (function () {
              var form = document.getElementById('vc-form');
              function sync() {
                document.querySelectorAll('[data-vc-confirm]').forEach(function (box) {
                  var on = form.querySelector('input[name="wcss_vc[' + box.dataset.vcConfirm + ']"]:checked');
                  box.hidden = !on || on.value !== box.dataset.vcWhen;
                });
              }
              function sets() {
                document.querySelectorAll('[data-vc-set]').forEach(function (box) {
                  var on = form.querySelector('input[name="wcss_vc[' + box.dataset.vcSet + ']"]:checked');
                  box.hidden = !on || on.value !== box.dataset.vcWhen;
                });
              }
              form.addEventListener('change', sets);
              sets();
              form.addEventListener('change', sync);
              sync();
              document.querySelectorAll('[data-vc-eye]').forEach(function (b) {
                b.addEventListener('click', function () {
                  var i = document.getElementById(b.dataset.vcEye), show = i.type === 'password';
                  i.type = show ? 'text' : 'password';
                  b.textContent = show ? 'Hide' : 'Show';
                  b.setAttribute('aria-pressed', show ? 'true' : 'false');
                });
              });
            })();
          </script>
        </div>
        <?php
    }

    private static function notice( string $type, string $text ) {
        set_transient( 'wcss_vc_notice_' . get_current_user_id(), [ 'type' => $type, 'text' => $text ], 120 );
    }

    private static function back() {
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
        exit;
    }

    /* ===================== Save ===================== */

    public function save() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrators only.', 403 );
        check_admin_referer( 'wcss_vendor_connections' );
        $res = self::apply( (array) wp_unslash( $_POST['wcss_vc'] ?? [] ), (array) wp_unslash( $_POST['wcss_vc_clear'] ?? [] ), [
            'amazon' => ! empty( $_POST['wcss_vc_confirm_amazon'] ),
            'gt'     => ! empty( $_POST['wcss_vc_confirm_gt'] ),
        ] );
        self::notice( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : $res );
        self::back();
    }

    /**
     * Validate and store. Kept apart from the request so tests can call it.
     *
     * @return string|WP_Error What changed, or why nothing was saved.
     */
    public static function apply( array $in, array $clear, array $confirm ) {
        $s   = self::all();
        $new = $s;

        foreach ( [ 'amazon' => self::AMAZON_FIELDS, 'amazon_live' => self::AMAZON_FIELDS, 'gt_stage' => self::GT_FIELDS + [ 'endpoint' => [] ], 'gt_prod' => self::GT_FIELDS + [ 'endpoint' => [] ] ] as $group => $fields ) {
            foreach ( $fields as $key => $f ) {
                $secret = ! empty( $f[2] );
                $val    = trim( (string) ( $in[ $group ][ $key ] ?? '' ) );
                if ( in_array( $key, (array) ( $clear[ $group ] ?? [] ), true ) ) { unset( $new[ $group ][ $key ] ); continue; }
                if ( $secret && $val === '' ) continue; // Blank secret = keep what is saved.
                if ( $val === '' ) { unset( $new[ $group ][ $key ] ); continue; }
                if ( $key === 'endpoint' ) {
                    $host = wp_parse_url( $val, PHP_URL_HOST );
                    $want = $group === 'gt_stage' ? [ 'stage.grandandtoy.com' ] : [ 'www.grandandtoy.com', 'grandandtoy.com' ];
                    if ( wp_parse_url( $val, PHP_URL_SCHEME ) !== 'https' || ! in_array( $host, $want, true ) ) {
                        return new WP_Error( 'bad_url', sprintf( 'The %s URL must be https://%s/…', $group === 'gt_stage' ? 'stage' : 'production', $want[0] ) );
                    }
                    $val = esc_url_raw( $val );
                } elseif ( $key === 'user_email' ) {
                    if ( ! is_email( $val ) ) return new WP_Error( 'bad_email', 'The Amazon buyer email is not a valid email address.' );
                } else {
                    $val = sanitize_text_field( $val );
                }
                // The form shows the value in use. If it wasn't changed, keep it where it comes from
                // (server setting or default) instead of copying it onto this page.
                if ( (string) ( $s[ $group ][ $key ] ?? '' ) === '' && $val === self::in_use( $group, $key, self::const_for( $group, $key ) ) ) {
                    continue;
                }
                $new[ $group ][ $key ] = $val;
            }
        }

        $am = ( $in['amazon_mode'] ?? $s['amazon_mode'] ) === 'live' ? 'live' : 'trial';
        $gm = ( $in['gt_mode'] ?? $s['gt_mode'] ) === 'production' ? 'production' : 'stage';
        if ( $am === 'live' && $s['amazon_mode'] !== 'live' && empty( $confirm['amazon'] ) ) {
            return new WP_Error( 'confirm', 'Nothing saved. To turn Amazon Live on, tick the box that confirms real orders will be bought.' );
        }
        if ( $gm === 'production' && $s['gt_mode'] !== 'production' && empty( $confirm['gt'] ) ) {
            return new WP_Error( 'confirm', 'Nothing saved. To switch Grand & Toy to Production, tick the box that confirms real purchase orders.' );
        }
        $new['amazon_mode'] = $am;
        $new['gt_mode']     = $gm;

        // Check the new values are complete before going live.
        update_option( self::OPTION, $new, false );
        if ( $am === 'live' && ( $miss = self::missing( 'amazon', 'live' ) ) ) {
            update_option( self::OPTION, $s, false );
            return new WP_Error( 'missing', 'Nothing saved. Amazon Live needs: ' . implode( ', ', $miss ) . '.' );
        }
        if ( $gm === 'production' && ( $miss = self::missing( 'gt', 'production' ) ) ) {
            update_option( self::OPTION, $s, false );
            return new WP_Error( 'missing', 'Nothing saved. Grand & Toy Production needs its production ' . implode( ', ', $miss ) . '.' );
        }

        $what = [];
        if ( $am !== $s['amazon_mode'] ) $what[] = 'Amazon → ' . ( $am === 'live' ? 'Live' : 'Trial Mode' );
        if ( $gm !== $s['gt_mode'] ) $what[] = 'Grand & Toy → ' . ( $gm === 'production' ? 'Production' : 'Stage' );
        foreach ( [ 'amazon', 'amazon_live', 'gt_stage', 'gt_prod' ] as $g ) {
            $changed = array_keys( array_diff_assoc( (array) ( $new[ $g ] ?? [] ), (array) ( $s[ $g ] ?? [] ) ) + array_diff_key( (array) ( $s[ $g ] ?? [] ), (array) ( $new[ $g ] ?? [] ) ) );
            if ( $changed ) $what[] = [ 'amazon' => 'Amazon Trial Mode', 'amazon_live' => 'Amazon Live', 'gt_stage' => 'G&T stage', 'gt_prod' => 'G&T production' ][ $g ] . ' credentials changed (' . implode( ', ', $changed ) . ')';
        }
        if ( ! $what ) return 'No changes.';
        $u = wp_get_current_user();
        $new['log'][] = [ 'at' => current_time( 'mysql' ), 'by' => $u && $u->exists() ? $u->display_name : 'System', 'what' => implode( '; ', $what ) ];
        $new['log']   = array_slice( $new['log'], -50 );
        update_option( self::OPTION, $new, false );
        do_action( 'wcss_vendor_connections_changed', $new, $s );
        return 'Saved: ' . implode( '; ', $what ) . '.';
    }

    /* ===================== Safe checks ===================== */

    public function check() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrators only.', 403 );
        check_admin_referer( 'wcss_vendor_connections_check', '_vcnonce' );
        $vendor = sanitize_key( $_POST['vendor'] ?? '' );
        if ( $vendor === 'amazon' ) {
            $r = self::check_amazon();
        } else {
            $r = self::check_gt( sanitize_text_field( wp_unslash( $_POST['part'] ?? '' ) ) );
        }
        self::notice( $r['ok'] ? 'success' : 'error', $r['text'] );
        self::back();
    }

    /** Ask Amazon for a sign-in token. No order, no cart change. */
    public static function check_amazon(): array {
        if ( $m = array_intersect( self::missing( 'amazon' ), [ 'Client ID', 'Client secret', 'Refresh token' ] ) ) {
            return [ 'ok' => false, 'text' => 'Amazon: missing ' . implode( ', ', $m ) . '.' ];
        }
        $res = wp_remote_post( 'https://api.amazon.com/auth/o2/token', [
            'timeout' => 20,
            'body'    => [ 'grant_type' => 'refresh_token', 'refresh_token' => self::amazon( 'refresh_token' ), 'client_id' => self::amazon( 'client_id' ), 'client_secret' => self::amazon( 'client_secret' ) ],
        ] );
        if ( is_wp_error( $res ) ) return [ 'ok' => false, 'text' => 'Amazon: could not connect — ' . $res->get_error_message() ];
        $code = (int) wp_remote_retrieve_response_code( $res );
        $body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        if ( $code === 200 && ! empty( $body['access_token'] ) ) {
            return [ 'ok' => true, 'text' => 'Amazon: signed in. Mode is ' . self::label( 'amazon' ) . '.' ];
        }
        return [ 'ok' => false, 'text' => 'Amazon: sign-in refused (HTTP ' . $code . ( ! empty( $body['error_description'] ) ? ', ' . $body['error_description'] : '' ) . ').' ];
    }

    /** Ask Grand & Toy for stock of one part. A stock check is not an order. */
    public static function check_gt( string $part ): array {
        if ( $part === '' ) return [ 'ok' => false, 'text' => 'Grand & Toy: enter a part number to check.' ];
        if ( $m = self::missing( 'gt' ) ) return [ 'ok' => false, 'text' => 'Grand & Toy ' . self::label( 'gt' ) . ': missing ' . implode( ', ', $m ) . '.' ];
        if ( ! function_exists( 'cc_gt_stock_check' ) ) return [ 'ok' => false, 'text' => 'Grand & Toy: the theme’s cXML client is not loaded.' ];
        $r = cc_gt_stock_check( [ [ 'supplier_part_id' => $part, 'quantity' => 1, 'uom' => 'EA' ] ] );
        if ( ! empty( $r['ok'] ) ) return [ 'ok' => true, 'text' => 'Grand & Toy ' . self::label( 'gt' ) . ': connected; stock reply received for ' . $part . '.' ];
        $err = implode( ' ', array_map( 'strval', (array) ( $r['errors'] ?? [] ) ) );
        return [ 'ok' => false, 'text' => 'Grand & Toy ' . self::label( 'gt' ) . ': ' . ( $err !== '' ? $err : 'no valid reply (HTTP ' . (int) ( $r['http_code'] ?? 0 ) . ').' ) ];
    }
}
