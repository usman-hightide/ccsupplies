<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Store_Admin_Highlight {

    public function __construct() {
        // Only load on the Stores list screen
        add_action( 'load-edit.php', [ $this, 'maybe_hook_assets' ] );
    }

    public function maybe_hook_assets() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'edit-' . WCSS_Store_CPT::POST_TYPE ) return;

        add_action( 'admin_print_styles', [ $this, 'print_css' ] );
        add_action( 'admin_print_footer_scripts', [ $this, 'print_js' ] );
    }

    public function print_css() {
        ?>
        <style>
            /* Soft highlight colors for readability */
            .wp-list-table tr.wcss-row-warn  { background: #fff9e6 !important; }  /* light amber */
            .wp-list-table tr.wcss-row-danger{ background: #ffecec !important; }  /* light red   */
            .wp-list-table tr.wcss-row-warn  td, 
            .wp-list-table tr.wcss-row-danger td { box-shadow: inset 0 0 0 1px rgba(0,0,0,0.03); }
        </style>
        <?php
    }

    public function print_js() {
        // thresholds (you can make these options later)
        $warn = 0.80; // 80%
        $danger = 1.00; // 100%
        ?>
        <script>
        (function(){
          const warn = <?php echo json_encode( $warn ); ?>;
          const danger = <?php echo json_encode( $danger ); ?>;

          const rows = document.querySelectorAll('#the-list tr');
          rows.forEach(tr => {
            const meta = tr.querySelector('.wcss-usage-meta');
            if (!meta) return;

            const usedCount  = parseFloat(meta.dataset.usedCount || '0');
            const quota      = parseFloat(meta.dataset.quota || '0');
            const usedAmount = parseFloat(meta.dataset.usedAmount || '0');
            const budget     = parseFloat(meta.dataset.budget || '0');

            // Compute ratios (ignore unlimited / no-budget)
            const countRatio  = quota  > 0 ? (usedCount  / quota)  : null;
            const amountRatio = budget > 0 ? (usedAmount / budget) : null;

            // Determine the worst ratio present
            let ratio = -1;
            if (countRatio  !== null) ratio = Math.max(ratio, countRatio);
            if (amountRatio !== null) ratio = Math.max(ratio, amountRatio);
            if (ratio < 0) return; // nothing to highlight

            if (ratio >= danger) {
              tr.classList.add('wcss-row-danger');
            } else if (ratio >= warn) {
              tr.classList.add('wcss-row-warn');
            }
          });
        })();
        </script>
        <?php
    }
}