<?php
// includes/admin-magento-send-to-mautic.php
if (!defined('ABSPATH')) exit;

/**
 * Step 7: Send Magento orders to Mautic
 * - Reads batches from wp_{prefix}o2w_magento_orders by queue state (pending/retry).
 * - Triggers the 'mautic' channel with source=magento for each entity_id.
 * - Marks result: done / retry, and stores last_error if needed.
 *
 * Requirements:
 * - includes/channels/class-channel-mautic.php (supports source=magento)
 * - includes/mautic-builder-magento.php loaded by the plugin
 * - Magento orders table populated by steps 1..6
 */

/** Capability helper */
if (!function_exists('o2w_required_cap')) {
    function o2w_required_cap() {
        return defined('S2M_REQUIRED_CAP') ? S2M_REQUIRED_CAP : 'manage_options';
    }
}

/** Magento orders table helper */
function o2w_mag_orders_table() {
    if (function_exists('o2w_magento_orders_table')) {
        return o2w_magento_orders_table();
    }
    global $wpdb; return $wpdb->prefix . 'o2w_magento_orders';
}

/** Queue metrics for UI */
function o2w_magento_mautic_queue_stats() {
    global $wpdb;
    $t = o2w_mag_orders_table();
    $rows = $wpdb->get_results("SELECT queue_state, COUNT(*) c FROM $t GROUP BY queue_state", ARRAY_A);
    $out = ['pending'=>0,'processing'=>0,'done'=>0,'retry'=>0,'error'=>0,'other'=>0];
    foreach ((array)$rows as $r) {
        $k = (string)$r['queue_state'];
        $c = (int)$r['c'];
        if (isset($out[$k])) $out[$k] = $c; else $out['other'] += $c;
    }
    $out['total'] = array_sum($out);
    return $out;
}

/** Send one order (entity_id) to Mautic through the channel */
function o2w_magento_send_one_to_mautic($entity_id) {
    do_action('orders2whatsapp_send_channel', 'mautic', [], [
        'vars' => ['source' => 'magento', 'magento_entity_id' => (int)$entity_id],
    ]);
}

/** Handler: send batch to Mautic */
add_action('admin_post_o2w_magento_send_orders_to_mautic', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_send_orders_to_mautic');

    global $wpdb;
    $table = o2w_mag_orders_table();

    $n         = isset($_GET['n']) ? max(1, (int)$_GET['n']) : 200;
    $statesRaw = isset($_GET['states']) ? (string)$_GET['states'] : 'pending,retry';
    $states    = array_values(array_filter(array_map('trim', explode(',', $statesRaw))));
    if (!$states) $states = ['pending','retry'];

    // Mautic base URL must exist
    $base = rtrim((string)get_option('orders2whatsapp_mautic_url'), '/');
    if ($base === '') {
        wp_safe_redirect(add_query_arg(
            ['o2w_notice'=>rawurlencode('Mautic Base URL is not configured.')],
            admin_url('admin.php?page=s2m_sync')
        ));
        exit;
    }

    // Candidates (oldest first by created_at when present; fallback to id)
    $orderBy = $wpdb->get_var($wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='created_at'",
        DB_NAME, $table
    )) ? 'created_at' : 'id';

    $ph  = implode(',', array_fill(0, count($states), '%s'));
    $sql = $wpdb->prepare(
        "SELECT entity_id FROM {$table}
         WHERE queue_state IN ($ph)
         ORDER BY {$orderBy} ASC
         LIMIT %d",
        array_merge($states, [$n])
    );
    $ids = $wpdb->get_col($sql);

    $seen=0; $ok=0; $fail=0;
    if ($ids) {
        foreach ($ids as $eid) {
            $eid = (int)$eid;
            $seen++;

            // Mark as processing (race-friendly)
            $wpdb->update(
                $table,
                ['queue_state'=>'processing', 'last_seen_at'=>current_time('mysql')],
                ['entity_id'=>$eid],
                ['%s','%s'],
                ['%d']
            );

            try {
                o2w_magento_send_one_to_mautic($eid);

                $wpdb->update(
                    $table,
                    ['queue_state'=>'done', 'last_error'=>null, 'last_seen_at'=>current_time('mysql')],
                    ['entity_id'=>$eid],
                    ['%s','%s','%s'],
                    ['%d']
                );
                $ok++;
            } catch (\Throwable $e) {
                $wpdb->update(
                    $table,
                    [
                        'queue_state'=>'retry',
                        'last_error'=>substr($e->getMessage(),0,1000),
                        'last_seen_at'=>current_time('mysql')
                    ],
                    ['entity_id'=>$eid],
                    ['%s','%s','%s'],
                    ['%d']
                );
                $fail++;
            }

            // Short courtesy pause (tunable)
            usleep(apply_filters('o2w_magento_mautic_sleep_micros', 150000)); // 150 ms
        }
    }

    $msg = sprintf('Mautic (Magento): seen %d / OK %d / errors %d.', (int)$seen, (int)$ok, (int)$fail);
    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode($msg)], admin_url('admin.php?page=s2m_sync')));
    exit;
});

/** Handler: move retry→pending (quick re-try) */
add_action('admin_post_o2w_magento_reset_retry_to_pending', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_reset_retry_to_pending');

    global $wpdb;
    $table = o2w_mag_orders_table();
    $cnt = $wpdb->query("UPDATE {$table} SET queue_state='pending', last_seen_at=NOW() WHERE queue_state='retry'");
    $msg = sprintf('Moved %d orders from retry → pending.', (int)$cnt);
    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode($msg)], admin_url('admin.php?page=s2m_sync')));
    exit;
});

/** (Optional) Handler: requeue recent done→pending (by days) */
add_action('admin_post_o2w_magento_requeue_done_recent', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_requeue_done_recent');

    global $wpdb;
    $table = o2w_mag_orders_table();
    $days  = isset($_GET['days']) ? max(1, (int)$_GET['days']) : 7;
    $sql   = $wpdb->prepare(
        "UPDATE {$table}
         SET queue_state='pending', last_seen_at=NOW()
         WHERE queue_state='done' AND updated_at >= (NOW() - INTERVAL %d DAY)",
        $days
    );
    $cnt = $wpdb->query($sql);
    $msg = sprintf('Re-queued %d orders from done → pending (last %d days).', (int)$cnt, (int)$days);
    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode($msg)], admin_url('admin.php?page=s2m_sync')));
    exit;
});

/**
 * UI: Step 7 — Send Magento orders to Mautic
 * You can control separators and title to fit container pages.
 *
 * @param array $args {
 *   @type bool $show_title            Render the <h2> section title. Default true.
 *   @type bool $show_separator_top    Render a leading <hr>. Default true.
 *   @type bool $show_separator_bottom Render a trailing <hr>. Default true.
 * }
 */
function o2w_render_magento_send_to_mautic_section_ui($args = []) {
    if (!current_user_can(o2w_required_cap())) return;

    $args = wp_parse_args($args, [
        'show_title'            => true,
        'show_separator_top'    => true,
        'show_separator_bottom' => true,
    ]);

    $s = o2w_magento_mautic_queue_stats();

    $send50_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_send_orders_to_mautic&n=50&states=pending,retry'),
        'o2w_magento_send_orders_to_mautic'
    );
    $send200_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_send_orders_to_mautic&n=200&states=pending,retry'),
        'o2w_magento_send_orders_to_mautic'
    );
    $send1000_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_send_orders_to_mautic&n=1000&states=pending,retry'),
        'o2w_magento_send_orders_to_mautic'
    );

    $reset_retry_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_reset_retry_to_pending'),
        'o2w_magento_reset_retry_to_pending'
    );

    $requeue_done7_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_requeue_done_recent&days=7'),
        'o2w_magento_requeue_done_recent'
    );

    if (!empty($args['show_separator_top'])) {
        echo '<hr>';
    }

    if (!empty($args['show_title'])) {
        echo '<h2 class="title">Magento — Step 7: Send Orders to Mautic (latest order per customer)</h2>';
    }

    ?>
    <p class="description" style="max-width:800px;">
        Reads orders from
        <code class="s2m-monospace"><?php echo esc_html(o2w_mag_orders_table()); ?></code>
        in queue (<em>pending/retry</em>) and triggers the <strong>Mautic</strong> channel using the Magento builder.
        Use the actions below to process batches or to re-queue records.
    </p>

    <table class="form-table" role="presentation">
        <tbody>
        <tr>
            <th scope="row">Queue</th>
            <td>
                <ul style="margin-top:6px;">
                    <li><strong>pending:</strong> <?php echo (int)$s['pending']; ?></li>
                    <li><strong>retry:</strong> <?php echo (int)$s['retry']; ?></li>
                    <li><strong>processing:</strong> <?php echo (int)$s['processing']; ?></li>
                    <li><strong>done:</strong> <?php echo (int)$s['done']; ?></li>
                    <?php if (!empty($s['other'])): ?>
                        <li><strong>other:</strong> <?php echo (int)$s['other']; ?></li>
                    <?php endif; ?>
                    <li style="margin-top:6px;"><strong>Total:</strong> <?php echo (int)$s['total']; ?></li>
                </ul>
                <p class="description">Tip: keep <em>pending</em> as the input state; <em>retry</em> is used when something failed and you want to try again.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Actions</th>
            <td>
                <a href="<?php echo esc_url($send50_url); ?>" class="button">Send 50</a>
                <a href="<?php echo esc_url($send200_url); ?>" class="button button-primary">Send 200</a>
                <a href="<?php echo esc_url($send1000_url); ?>" class="button">Send 1000</a>
                <br><br>
                <a href="<?php echo esc_url($reset_retry_url); ?>" class="button button-secondary">Move RETRY → PENDING</a>
                <a href="<?php echo esc_url($requeue_done7_url); ?>" class="button button-secondary">Re-queue DONE (7 days)</a>
                <p class="description" style="margin-top:6px;">
                    Each batch sleeps briefly between orders to avoid overloading Mautic.
                    You can adjust the delay with the <code>o2w_magento_mautic_sleep_micros</code> filter.
                </p>
            </td>
        </tr>
        </tbody>
    </table>
    <?php

    if (!empty($args['show_separator_bottom'])) {
        echo '<hr>';
    }
}

/** Backward-compatible alias */
if (!function_exists('o2w_render_magento_send_to_mautic_section')) {
    function o2w_render_magento_send_to_mautic_section() {
        // Render with title and both separators by default
        o2w_render_magento_send_to_mautic_section_ui([
            'show_title'            => true,
            'show_separator_top'    => true,
            'show_separator_bottom' => true,
        ]);
    }
}
