<?php
// includes/admin-settings-notices.php
if (!defined('ABSPATH')) exit;

/**
 * Avisos (admin_notices). Lógica mínima y aislada.
 */

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (!isset($_GET['page']) || !in_array($_GET['page'], ['orders2whatsapp','s2m_settings'], true)) return;

    $msg = get_transient('o2w_fields_installer_msg');
    if ($msg) {
        delete_transient('o2w_fields_installer_msg');
        echo '<div class="notice notice-info is-dismissible"><p><strong>7C Shopping2Mautic:</strong> ' . esc_html($msg) . '</p></div>';
    }
});

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (!isset($_GET['page']) || !in_array($_GET['page'], ['orders2whatsapp','s2m_settings'], true)) return;

    $msg = get_transient('o2w_backfill_msg');
    if ($msg) {
        delete_transient('o2w_backfill_msg');
        echo '<div class="notice notice-info is-dismissible"><p><strong>7C Shopping2Mautic (Backfill):</strong> ' . esc_html($msg) . '</p></div>';
    }
});
