<?php
// includes/admin-woo-backfill.php
if (!defined('ABSPATH')) exit;

/**
 * Renders the Woo → Mautic Backfill section (UI only).
 * Mirrors the Magento section layout (separator, title, form-table).
 */
function s2m_render_woo_backfill_section($args = []) {
    // Use the unified capability defined in the plugin main file.
    if (!current_user_can(S2M_REQUIRED_CAP)) return;

    // Options for consistency with other sections
    $args = wp_parse_args($args, [
        'show_title'     => true,
        'show_separator' => true,
    ]);

    // Inline CSS once (progress bar + small spacing)
    static $s2m_backfill_css_printed = false;
    if (!$s2m_backfill_css_printed) {
        echo '<style id="s2m-backfill-css">
            .s2m-progress { width: 420px; max-width: 100%; background:#eee; height:10px; border-radius:6px; overflow:hidden; margin-top:6px; }
            .s2m-progress .s2m-bar { height:10px; background:#46b450; }
            .s2m-monospace { font-family: Menlo,Consolas,Monaco,monospace; }
            .s2m-actions .button { margin-right:6px; margin-bottom:6px; }
            .s2m-hint { margin-top:8px; color:#666; }
        </style>';
        $s2m_backfill_css_printed = true;
    }

    if ($args['show_separator']) echo '<hr>';
    if ($args['show_title']) echo '<h2 class="title">Backfill Woo → Mautic (Client last order)</h2>';

    if (!class_exists('Orders2WhatsApp_Backfill')) {
        echo '<p>Backfill class not found.</p>';
        return;
    }

    // Progress + state
    $prog         = Orders2WhatsApp_Backfill::get_progress();
    $cron_running = Orders2WhatsApp_Backfill::cron_is_running();

    // Action URLs (with nonces)
    $run_one_url = wp_nonce_url(
        admin_url('admin-post.php?action=orders2whatsapp_backfill_run_one'),
        'o2w_backfill_run_one'
    );
    $run_25_url = wp_nonce_url(
        admin_url('admin-post.php?action=orders2whatsapp_backfill_run_batch&n=25'),
        'o2w_backfill_run_batch'
    );
    $cron_start = wp_nonce_url(
        admin_url('admin-post.php?action=orders2whatsapp_backfill_cron_start&n=25'),
        'o2w_backfill_cron_start'
    );
    $cron_stop = wp_nonce_url(
        admin_url('admin-post.php?action=orders2whatsapp_backfill_cron_stop'),
        'o2w_backfill_cron_stop'
    );
    $reset_url = wp_nonce_url(
        admin_url('admin-post.php?action=orders2whatsapp_backfill_reset'),
        'o2w_backfill_reset'
    );
    // NEW: Build queue explicitly
    $build_url = wp_nonce_url(
        admin_url('admin-post.php?action=orders2whatsapp_backfill_build'),
        'o2w_backfill_build'
    );

    $done  = (int) ($prog['done'] ?? 0);
    $total = (int) ($prog['total'] ?? 0);
    $rem   = (int) ($prog['remaining'] ?? max(0, $total - $done));
    $pct   = ($total > 0) ? min(100, round(($done / $total) * 100)) : 0;

    ?>
    <table class="form-table" role="presentation">
        <tbody>
        <tr>
            <th scope="row">Status</th>
            <td>
                <p><strong>Done:</strong> <?php echo (int)$done; ?> /
                   <strong>Total:</strong> <?php echo (int)$total; ?> —
                   <strong>Remaining:</strong> <?php echo (int)$rem; ?></p>

                <div class="s2m-progress" aria-label="Backfill progress">
                    <div class="s2m-bar" style="width: <?php echo (int)$pct; ?>%;"></div>
                </div>

                <p class="description" style="margin-top:6px;">
                    Progress: <?php echo (int)$pct; ?>% — Background:
                    <strong><?php echo $cron_running ? 'Running' : 'Stopped'; ?></strong>
                </p>

                <?php if ($total === 0): ?>
                    <p class="s2m-hint">
                        Queue is empty. Click <em>Build queue</em> to collect the latest WooCommerce orders (latest per customer),
                        then you can <em>Run</em> or <em>Start background</em>.
                    </p>
                <?php endif; ?>
            </td>
        </tr>

        <tr>
            <th scope="row">Actions</th>
            <td class="s2m-actions">
                <a class="button" href="<?php echo esc_url($run_one_url); ?>">Run 1</a>
                <a class="button" href="<?php echo esc_url($run_25_url); ?>">Run 25</a>
                <?php if ($cron_running): ?>
                    <a class="button button-secondary" href="<?php echo esc_url($cron_stop); ?>">Stop background</a>
                <?php else: ?>
                    <a class="button button-primary" href="<?php echo esc_url($cron_start); ?>">Start background</a>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url($reset_url); ?>">Reset queue</a>
                <a class="button" href="<?php echo esc_url($build_url); ?>">Build queue</a>
            </td>
        </tr>
        </tbody>
    </table>
    <?php
}
