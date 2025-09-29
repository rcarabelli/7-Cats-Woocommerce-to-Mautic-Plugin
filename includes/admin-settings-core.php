<?php
// includes/admin-settings-core.php
if (!defined('ABSPATH')) exit;

/**
 * SETTINGS CORE (no UI):
 * - register_setting (admin_init)
 * - sanitizers
 * - filters
 *
 * NOTE: The legacy top-level admin menu "7C Shopping2Mautic" has been removed.
 * The Settings UI is rendered elsewhere (see includes/admin-settings.php) and
 * should be attached under the new "7C Shopping2Mautic" menu (admin-menus.php).
 */

// ===== Hooks (NO admin_menu here; we don't create the legacy top-level menu) =====
add_action('admin_init', 'orders2whatsapp_register_settings');

// ===== Settings registration (no HTML here) =====
function orders2whatsapp_register_settings() {
    // === ZENDER ===
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_api_url', [
        'type' => 'string', 'sanitize_callback' => 'esc_url_raw'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_api_secret', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_device_id', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_admin_numbers', [
        'type' => 'string', 'sanitize_callback' => 'orders2whatsapp_sanitize_admin_numbers'
    ]);

    // === MAUTIC (basic) ===
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_url', [
        'type' => 'string', 'sanitize_callback' => 'esc_url_raw'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_token', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'
        // values:
        // - "oauth2"
        // - "key:APIKEY"
        // - "<token>"
    ]);

    // === MAUTIC (OAuth2 password grant) ===
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_oauth_client_id', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_oauth_client_secret', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_oauth_username', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_oauth_password', [
        'type' => 'string', 'sanitize_callback' => 'orders2whatsapp_sanitize_password'
    ]);

    // === MAUTIC (Default tags for Woo/Magento) ===
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_tag_woo', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'woocommerce'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_tag_magento', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'magento'
    ]);

    // === MAUTIC LOGS ===
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_log_enabled', [
        'type' => 'boolean', 'sanitize_callback' => 'orders2whatsapp_sanitize_bool', 'default' => 0
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_log_retention_days', [
        'type' => 'integer', 'sanitize_callback' => 'orders2whatsapp_sanitize_positive_int', 'default' => 14
    ]);

    // === POLICIES (WhatsApp) ===
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_notify_statuses', [
        'type' => 'array',
        'sanitize_callback' => 'orders2whatsapp_sanitize_statuses',
        'default' => ['processing', 'completed', 'cancelled', 'refunded'],
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_default_country_code', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => '51',
    ]);

    // === Temporary tokens (saved by the OAuth2 channel; no UI) ===
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_access_token', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_refresh_token', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_access_expires', [
        'type' => 'integer', 'sanitize_callback' => 'orders2whatsapp_sanitize_positive_int'
    ]);

    // === Global filter for policies (logic lives here; UI lives elsewhere) ===
    add_filter('orders2whatsapp_customer_notify_statuses', function ($defaults) {
        $configured = get_option('orders2whatsapp_notify_statuses');
        if (is_array($configured) && !empty($configured)) {
            return $configured;
        }
        return $defaults;
    });
}

// ===== Sanitizers (no HTML) =====
function orders2whatsapp_sanitize_bool($val) {
    return (int) (bool) $val;
}

function orders2whatsapp_sanitize_positive_int($val) {
    $val = is_numeric($val) ? (int) $val : 0;
    return max(0, $val);
}

function orders2whatsapp_sanitize_password($val) {
    $val = (string) $val;
    return wp_kses($val, []); // no tags
}

function orders2whatsapp_sanitize_admin_numbers($input) {
    if (!is_string($input)) return '';
    $parts = array_filter(array_map('trim', explode(',', $input)));
    return implode(', ', $parts);
}

function orders2whatsapp_sanitize_statuses($input) {
    if (!is_array($input)) return [];
    $clean = [];
    foreach ($input as $slug) {
        $slug = sanitize_key($slug);
        if (!empty($slug)) $clean[] = $slug;
    }
    return array_values(array_unique($clean));
}
