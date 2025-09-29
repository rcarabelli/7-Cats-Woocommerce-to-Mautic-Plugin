<?php
if (!defined('ABSPATH')) exit;

/**
 * Página 3: Mautic Sync
 * - Backfill to Mautic (latest order per customer) — Woo
 * - Paso 7 — Enviar pedidos Magento a Mautic (render externo)
 */
function o2w_render_page_mautic_sync() {
    // Progreso backfill (si la clase está cargada)
    $prog = class_exists('Orders2WhatsApp_Backfill')
        ? Orders2WhatsApp_Backfill::get_progress()
        : ['total'=>0,'done'=>0,'remaining'=>0];

    $cron_running = class_exists('Orders2WhatsApp_Backfill')
        ? Orders2WhatsApp_Backfill::cron_is_running()
        : false;

    // Acciones backfill
    $run_one_url   = wp_nonce_url(admin_url('admin-post.php?action=orders2whatsapp_backfill_run_one'),        'o2w_backfill_run_one');
    $run_25_url    = wp_nonce_url(admin_url('admin-post.php?action=orders2whatsapp_backfill_run_batch&n=25'), 'o2w_backfill_run_batch');
    $cron_start_url= wp_nonce_url(admin_url('admin-post.php?action=orders2whatsapp_backfill_cron_start&n=25'),'o2w_backfill_cron_start');
    $cron_stop_url = wp_nonce_url(admin_url('admin-post.php?action=orders2whatsapp_backfill_cron_stop'),       'o2w_backfill_cron_stop');
    $reset_url     = wp_nonce_url(admin_url('admin-post.php?action=orders2whatsapp_backfill_reset'),           'o2w_backfill_reset');

    $pct = ($prog['total'] > 0) ? min(100, round(($prog['done'] / $prog['total']) * 100)) : 0;
    ?>
    <div class="wrap">
        <h1>Sync orders to Mautic</h1>
    
        <p class="description" style="max-width:800px; margin-bottom:20px;">
            This page lets you manage how order data is synchronized into Mautic.
            <br><br>
            <strong>Backfill Woo → Mautic:</strong> Ensures each WooCommerce customer has their latest order recorded in Mautic. You can run records one by one, in batches, or start a background process until all are complete.
            <br><br>
            <strong>Magento → Mautic (Step 7):</strong> Sends Magento orders (imported in steps 1–6) into Mautic. The queue shows how many are pending, retried, processing, or done. You can send them in batches, retry failed ones, or re-queue recently completed ones.
            <br><br>
            In short: the top section keeps WooCommerce customers up to date in Mautic, and the bottom section pushes Magento order data to Mautic with full control over queue states.
        </p>
    
        <!-- ===== Backfill Woo ===== -->
        <h2 class="title">Backfill Woo → Mautic (latest order per customer)</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Progress</th>
                <td>
                    <strong>Done:</strong> <?php echo (int) $prog['done']; ?> /
                    <strong>Total:</strong> <?php echo (int) $prog['total']; ?> —
                    <strong>Remaining:</strong> <?php echo (int) $prog['remaining']; ?>
                    <div style="margin-top:6px; width:420px; background:#eee; height:12px; border-radius:6px; overflow:hidden;">
                        <div style="width:<?php echo (int)$pct; ?>%; height:12px; background:#46b450;"></div>
                    </div>
                    <p class="description" style="margin-top:6px;">
                        Progress: <?php echo (int)$pct; ?>% — Background: <strong><?php echo $cron_running ? 'Running' : 'Stopped'; ?></strong>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">Actions</th>
                <td>
                    <a href="<?php echo esc_url($run_one_url); ?>" class="button">Run 1</a>
                    <a href="<?php echo esc_url($run_25_url); ?>" class="button">Run 25</a>
                    <?php if ($cron_running): ?>
                        <a href="<?php echo esc_url($cron_stop_url); ?>" class="button button-secondary">Stop background</a>
                    <?php else: ?>
                        <a href="<?php echo esc_url($cron_start_url); ?>" class="button button-primary">Start background</a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url($reset_url); ?>" class="button">Reset queue</a>
                    <p class="description" style="margin-top:6px;">No WhatsApp is sent; only the Mautic channel runs.</p>
                </td>
            </tr>
        </table>

        <!-- separador único entre bloques -->
        <hr>

        <!-- ===== Magento Paso 7 =====
             No ponemos <h2> aquí porque el renderer ya titula su bloque.
        -->
        <?php
        if (function_exists('o2w_render_magento_send_to_mautic_section')) {
            o2w_render_magento_send_to_mautic_section();
        } else {
            echo '<p class="description">El bloque "Paso 7" aún no está disponible. Verifica que el archivo <code>includes/admin-magento-send-to-mautic.php</code> esté cargado.</p>';
        }
        ?>
    </div>
    <?php
}
