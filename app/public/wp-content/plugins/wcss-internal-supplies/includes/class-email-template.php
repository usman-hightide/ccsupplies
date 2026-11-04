<?php
/**
 * Branded HTML email layout shared by every automated and manual email.
 *
 * Table-based with inline styles so it renders in Outlook, Gmail and Apple Mail.
 * Change colours, header or footer here and every email follows.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_Email_Template {

    const INK    = '#16181D';
    const MUTED  = '#5B6270';
    const LINE   = '#E4E7EC';
    const BRAND  = '#C8372D';
    const HEADER = '#121417';

    /** Status pill colours by tone. */
    const TONES = [
        'ok'      => [ '#E7F5EC', '#1B6B3A' ],
        'warn'    => [ '#FFF4E0', '#8A5300' ],
        'bad'     => [ '#FDECEA', '#A8261C' ],
        'info'    => [ '#E8F0FE', '#1F4FA8' ],
        'neutral' => [ '#EEF0F3', '#4A515C' ],
    ];

    /**
     * @param array $a {
     *   eyebrow   string  Small caps label above the title, e.g. "Order approved".
     *   title     string  Main heading.
     *   status    array   [ 'label' => 'Approved', 'tone' => ok|warn|bad|info|neutral ].
     *   intro     string  Plain text paragraph.
     *   body_html string  Already-sanitised HTML (manual emails).
     *   details   array   [ [ label, value ], … ] plain text.
     *   items     array   [ [ 'name' =>, 'qty' =>, 'total' => ], … ].
     *   note      array   [ 'title' =>, 'lines' => [ … ], 'tone' => ].
     *   action    array   [ 'label' =>, 'url' => ].
     *   reason    string  Footer line saying why this person got the email.
     * }
     */
    public static function render( array $a ): string {
        $tone   = self::TONES[ $a['status']['tone'] ?? 'neutral' ] ?? self::TONES['neutral'];
        // Show the zone only when it has a real abbreviation (e.g. MST), not "GMT+0000".
        $abbr   = wp_date( 'T' );
        $sent   = wp_date( 'j M Y, g:i A' ) . ( preg_match( '/^[A-Z]{2,5}$/', $abbr ) ? ' ' . $abbr : '' );
        $font   = "font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;";
        $manage = home_url( '/manager/emails' );

        ob_start();
        ?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $a['title'] ?? '' ); ?></title></head>
<body style="margin:0;padding:0;background:#F4F5F7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F5F7;<?php echo $font; ?>">
<tr><td align="center" style="padding:28px 12px;">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid <?php echo self::LINE; ?>;border-radius:12px;overflow:hidden;">
    <tr><td style="height:4px;background:<?php echo self::BRAND; ?>;font-size:0;line-height:0;">&nbsp;</td></tr>
    <tr><td style="background:<?php echo self::HEADER; ?>;padding:18px 28px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
        <td style="<?php echo $font; ?>color:#ffffff;font-size:16px;letter-spacing:.14em;font-weight:700;">CANNA <span style="font-style:italic;font-weight:500;letter-spacing:.02em;">Cabana</span></td>
        <td align="right" style="<?php echo $font; ?>color:#9AA1AC;font-size:12px;">Supply Manager</td>
      </tr></table>
    </td></tr>

    <tr><td style="padding:28px 28px 8px;<?php echo $font; ?>">
      <?php if ( ! empty( $a['eyebrow'] ) ) : ?>
        <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:<?php echo self::MUTED; ?>;margin:0 0 8px;"><?php echo esc_html( $a['eyebrow'] ); ?></div>
      <?php endif; ?>
      <h1 style="margin:0;font-size:22px;line-height:1.3;color:<?php echo self::INK; ?>;font-weight:700;"><?php echo esc_html( $a['title'] ?? '' ); ?></h1>
      <?php if ( ! empty( $a['status']['label'] ) ) : ?>
        <div style="margin-top:12px;"><span style="display:inline-block;background:<?php echo $tone[0]; ?>;color:<?php echo $tone[1]; ?>;font-size:12px;font-weight:700;border-radius:999px;padding:4px 12px;"><?php echo esc_html( $a['status']['label'] ); ?></span></div>
      <?php endif; ?>
      <?php if ( ! empty( $a['intro'] ) ) : ?>
        <p style="margin:16px 0 0;font-size:15px;line-height:1.55;color:#2A2D34;"><?php echo esc_html( $a['intro'] ); ?></p>
      <?php endif; ?>
      <?php if ( ! empty( $a['body_html'] ) ) : ?>
        <div style="margin:16px 0 0;font-size:15px;line-height:1.6;color:#2A2D34;"><?php echo wp_kses_post( $a['body_html'] ); ?></div>
      <?php endif; ?>
    </td></tr>

    <?php if ( ! empty( $a['note']['lines'] ) ) :
        $nt = self::TONES[ $a['note']['tone'] ?? 'warn' ] ?? self::TONES['warn']; ?>
    <tr><td style="padding:16px 28px 0;<?php echo $font; ?>">
      <div style="background:<?php echo $nt[0]; ?>;border-radius:10px;padding:14px 16px;color:<?php echo $nt[1]; ?>;font-size:14px;line-height:1.5;">
        <?php if ( ! empty( $a['note']['title'] ) ) : ?><strong style="display:block;margin-bottom:4px;"><?php echo esc_html( $a['note']['title'] ); ?></strong><?php endif; ?>
        <?php foreach ( (array) $a['note']['lines'] as $line ) : ?><div>• <?php echo esc_html( $line ); ?></div><?php endforeach; ?>
      </div>
    </td></tr>
    <?php endif; ?>

    <?php if ( ! empty( $a['details'] ) ) : ?>
    <tr><td style="padding:20px 28px 0;<?php echo $font; ?>">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid <?php echo self::LINE; ?>;border-radius:10px;border-collapse:separate;">
        <?php foreach ( $a['details'] as $i => $row ) : ?>
        <tr>
          <td style="<?php echo $font; ?>padding:10px 14px;font-size:13px;color:<?php echo self::MUTED; ?>;font-weight:600;width:38%;<?php echo $i ? 'border-top:1px solid ' . self::LINE . ';' : ''; ?>"><?php echo esc_html( $row[0] ); ?></td>
          <td align="right" style="<?php echo $font; ?>padding:10px 14px;font-size:14px;color:<?php echo self::INK; ?>;<?php echo $i ? 'border-top:1px solid ' . self::LINE . ';' : ''; ?>"><?php echo esc_html( (string) $row[1] ); ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </td></tr>
    <?php endif; ?>

    <?php if ( ! empty( $a['items'] ) ) : ?>
    <tr><td style="padding:20px 28px 0;<?php echo $font; ?>">
      <div style="font-size:13px;font-weight:700;color:<?php echo self::INK; ?>;margin-bottom:8px;">Items</div>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
        <tr>
          <th align="left" style="<?php echo $font; ?>font-size:12px;color:<?php echo self::MUTED; ?>;padding:8px 0;border-bottom:2px solid <?php echo self::INK; ?>;">Product</th>
          <th align="right" style="<?php echo $font; ?>font-size:12px;color:<?php echo self::MUTED; ?>;padding:8px 0;border-bottom:2px solid <?php echo self::INK; ?>;width:50px;">Qty</th>
          <?php if ( isset( $a['items'][0]['total'] ) ) : ?>
          <th align="right" style="<?php echo $font; ?>font-size:12px;color:<?php echo self::MUTED; ?>;padding:8px 0;border-bottom:2px solid <?php echo self::INK; ?>;width:100px;">Total</th>
          <?php endif; ?>
        </tr>
        <?php foreach ( $a['items'] as $it ) : ?>
        <tr>
          <td style="<?php echo $font; ?>font-size:14px;color:<?php echo self::INK; ?>;padding:10px 8px 10px 0;border-bottom:1px solid <?php echo self::LINE; ?>;"><?php echo esc_html( $it['name'] ); ?><?php if ( ! empty( $it['sku'] ) ) : ?><div style="font-size:12px;color:<?php echo self::MUTED; ?>;">SKU <?php echo esc_html( $it['sku'] ); ?></div><?php endif; ?></td>
          <td align="right" style="<?php echo $font; ?>font-size:14px;color:<?php echo self::INK; ?>;padding:10px 0;border-bottom:1px solid <?php echo self::LINE; ?>;"><?php echo (int) $it['qty']; ?></td>
          <?php if ( isset( $it['total'] ) ) : ?>
          <td align="right" style="<?php echo $font; ?>font-size:14px;color:<?php echo self::INK; ?>;padding:10px 0;border-bottom:1px solid <?php echo self::LINE; ?>;"><?php echo esc_html( $it['total'] ); ?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </table>
    </td></tr>
    <?php endif; ?>

    <?php if ( ! empty( $a['action']['url'] ) ) : ?>
    <tr><td style="padding:24px 28px 0;<?php echo $font; ?>">
      <a href="<?php echo esc_url( $a['action']['url'] ); ?>" style="display:inline-block;background:<?php echo self::INK; ?>;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;border-radius:8px;padding:12px 20px;"><?php echo esc_html( $a['action']['label'] ?? 'Open' ); ?> &rarr;</a>
    </td></tr>
    <?php endif; ?>

    <tr><td style="padding:24px 28px 28px;<?php echo $font; ?>">
      <div style="font-size:12px;color:<?php echo self::MUTED; ?>;">Sent <?php echo esc_html( $sent ); ?></div>
    </td></tr>

    <tr><td style="background:#F9FAFB;border-top:1px solid <?php echo self::LINE; ?>;padding:16px 28px;<?php echo $font; ?>font-size:12px;line-height:1.5;color:<?php echo self::MUTED; ?>;">
      <?php if ( ! empty( $a['reason'] ) ) : ?><?php echo esc_html( $a['reason'] ); ?><br><?php endif; ?>
      Canna Cabana Supply Operations<?php if ( ! empty( $a['manage_link'] ) ) : ?> · <a href="<?php echo esc_url( $manage ); ?>" style="color:<?php echo self::MUTED; ?>;">Email settings</a><?php endif; ?>
    </td></tr>
  </table>
</td></tr>
</table>
</body></html>
        <?php
        return (string) ob_get_clean();
    }

    /** Order lines in the shape render() expects. */
    public static function order_items( WC_Order $order, ?array $only = null, bool $with_total = true ): array {
        $rows = [];
        foreach ( $order->get_items( 'line_item' ) as $id => $item ) {
            if ( $only !== null && ! isset( $only[ $id ] ) ) continue;
            $product = $item->get_product();
            $row     = [
                'name' => html_entity_decode( $item->get_name(), ENT_QUOTES ),
                'qty'  => (int) $item->get_quantity(),
                'sku'  => $product ? (string) $product->get_sku() : '',
            ];
            if ( $with_total ) {
                $row['total'] = html_entity_decode( wp_strip_all_tags( wc_price( (float) $item->get_total(), [ 'currency' => $order->get_currency() ] ) ), ENT_QUOTES );
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** Plain-text money for detail rows. */
    public static function money( $amount, string $currency = '' ): string {
        return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount, $currency ? [ 'currency' => $currency ] : [] ) ), ENT_QUOTES );
    }
}
