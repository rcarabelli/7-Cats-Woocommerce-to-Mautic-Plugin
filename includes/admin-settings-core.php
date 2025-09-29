<?php
// includes/admin-settings-core.php
if (!defined('ABSPATH')) exit;

/**
 * SETTINGS CORE (no UI):
 * - register_setting (admin_init)
 * - sanitizers
 * - filters
 *
 * Cada bloque de la UI usa su propio "settings group" para evitar que
 * guardar una sección borre valores de las demás.
 *
 * NOTA: La UI está en includes/admin-settings.php
 */

// ===== Definición de grupos por sección (cada <form> usa uno) =====
if (!defined('S2M_GROUP_MAUTIC'))  define('S2M_GROUP_MAUTIC',  'orders2whatsapp_mautic');
if (!defined('S2M_GROUP_ZENDER'))  define('S2M_GROUP_ZENDER',  'orders2whatsapp_zender');
if (!defined('S2M_GROUP_POLICY'))  define('S2M_GROUP_POLICY',  'orders2whatsapp_policy');

// ===== Hook principal =====
add_action('admin_init', 'orders2whatsapp_register_settings');

/**
 * Wrapper que preserva el valor actual si la opción no vino en $_POST.
 * Útil cuando el <form> de otra sección no envía este campo.
 *
 * OJO: Para checkboxes/grupos que admiten "vaciar todo" se recomienda
 * un sanitizador ad-hoc que considere un marcador *_present en el POST.
 */
function s2m_preserve_if_missing($option_name, $sanitizer) {
    return function($val) use ($option_name, $sanitizer) {
        if (!array_key_exists($option_name, $_POST)) {
            return get_option($option_name); // conserva valor previo
        }
        return is_callable($sanitizer) ? call_user_func($sanitizer, $val) : $val;
    };
}

// ===== Registro de settings (sin HTML) =====
function orders2whatsapp_register_settings() {

    // ====== MAUTIC ======
    register_setting(S2M_GROUP_MAUTIC, 'orders2whatsapp_mautic_url', [
        'type' => 'string',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_mautic_url', 'esc_url_raw'),
    ]);

    register_setting(S2M_GROUP_MAUTIC, 'orders2whatsapp_mautic_token', [
        'type' => 'string',
        // valores posibles: "oauth2" | "key:APIKEY" | "<token>"
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_mautic_token', 'sanitize_text_field'),
    ]);

    // OAuth2 password grant
    register_setting(S2M_GROUP_MAUTIC, 'orders2whatsapp_mautic_oauth_client_id', [
        'type' => 'string',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_mautic_oauth_client_id', 'sanitize_text_field'),
    ]);
    register_setting(S2M_GROUP_MAUTIC, 'orders2whatsapp_mautic_oauth_client_secret', [
        'type' => 'string',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_mautic_oauth_client_secret', 'sanitize_text_field'),
    ]);
    register_setting(S2M_GROUP_MAUTIC, 'orders2whatsapp_mautic_oauth_username', [
        'type' => 'string',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_mautic_oauth_username', 'sanitize_text_field'),
    ]);
    register_setting(S2M_GROUP_MAUTIC, 'orders2whatsapp_mautic_oauth_password', [
        'type' => 'string',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_mautic_oauth_password', 'orders2whatsapp_sanitize_password'),
    ]);

    // Tags por defecto
    register_setting(S2M_GROUP_MAUTIC, 'orders2whatsapp_mautic_tag_woo', [
        'type' => 'string',
        'default' => 'woocommerce',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_mautic_tag_woo', 'sanitize_text_field'),
    ]);
    register_setting(S2M_GROUP_MAUTIC, 'orders2whatsapp_mautic_tag_magento', [
        'type' => 'string',
        'default' => 'magento',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_mautic_tag_magento', 'sanitize_text_field'),
    ]);

    // Logs Mautic (checkbox + number)
    // Importante: permitimos apagar el checkbox aunque no venga en POST
    register_setting(S2M_GROUP_MAUTIC, 'orders2whatsapp_mautic_log_enabled', [
        'type' => 'boolean',
        'default' => 0,
        'sanitize_callback' => function($val) {
            // Si el form de MAUTIC fue enviado, habrá un flag oculto:
            if (isset($_POST['orders2whatsapp_mautic_log_enabled_present'])) {
                // Si el checkbox no vino, interpretamos 0 (apagado)
                if (!array_key_exists('orders2whatsapp_mautic_log_enabled', $_POST)) {
                    return 0;
                }
                return orders2whatsapp_sanitize_bool($val);
            }
            // Si no es el formulario de esta sección, preservamos
            if (!array_key_exists('orders2whatsapp_mautic_log_enabled', $_POST)) {
                return get_option('orders2whatsapp_mautic_log_enabled');
            }
            return orders2whatsapp_sanitize_bool($val);
        },
    ]);

    register_setting(S2M_GROUP_MAUTIC, 'orders2whatsapp_mautic_log_retention_days', [
        'type' => 'integer',
        'default' => 14,
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_mautic_log_retention_days', 'orders2whatsapp_sanitize_positive_int'),
    ]);

    // Tokens temporales (sin UI, mismo grupo Mautic)
    register_setting(S2M_GROUP_MAUTIC, 'orders2whatsapp_mautic_access_token', [
        'type' => 'string',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_mautic_access_token', 'sanitize_text_field'),
    ]);
    register_setting(S2M_GROUP_MAUTIC, 'orders2whatsapp_mautic_refresh_token', [
        'type' => 'string',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_mautic_refresh_token', 'sanitize_text_field'),
    ]);
    register_setting(S2M_GROUP_MAUTIC, 'orders2whatsapp_mautic_access_expires', [
        'type' => 'integer',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_mautic_access_expires', 'orders2whatsapp_sanitize_positive_int'),
    ]);

    // ====== ZENDER ======
    register_setting(S2M_GROUP_ZENDER, 'orders2whatsapp_api_url', [
        'type' => 'string',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_api_url', 'esc_url_raw'),
    ]);
    register_setting(S2M_GROUP_ZENDER, 'orders2whatsapp_api_secret', [
        'type' => 'string',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_api_secret', 'sanitize_text_field'),
    ]);
    register_setting(S2M_GROUP_ZENDER, 'orders2whatsapp_device_id', [
        'type' => 'string',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_device_id', 'sanitize_text_field'),
    ]);
    register_setting(S2M_GROUP_ZENDER, 'orders2whatsapp_admin_numbers', [
        'type' => 'string',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_admin_numbers', 'orders2whatsapp_sanitize_admin_numbers'),
    ]);

    // ====== POLICIES (WhatsApp) ======
    // Grupo de checkboxes: permitimos "vaciar todo" si el form de POLICIES fue enviado
    register_setting(S2M_GROUP_POLICY, 'orders2whatsapp_notify_statuses', [
        'type' => 'array',
        'default' => ['processing','completed','cancelled','refunded'],
        'sanitize_callback' => function($val) {
            if (isset($_POST['orders2whatsapp_notify_statuses_present'])) {
                // Si el campo vino, sanitizamos; si NO vino, usuario destildó todo → []
                if (array_key_exists('orders2whatsapp_notify_statuses', $_POST)) {
                    return orders2whatsapp_sanitize_statuses($val);
                }
                return []; // vacío a propósito
            }
            // Si no corresponde a este form, preservamos
            if (!array_key_exists('orders2whatsapp_notify_statuses', $_POST)) {
                return get_option('orders2whatsapp_notify_statuses');
            }
            return orders2whatsapp_sanitize_statuses($val);
        },
    ]);

    register_setting(S2M_GROUP_POLICY, 'orders2whatsapp_default_country_code', [
        'type' => 'string',
        'default' => '51',
        'sanitize_callback' => s2m_preserve_if_missing('orders2whatsapp_default_country_code', 'sanitize_text_field'),
    ]);

    // ===== Filtro global de políticas (igual que antes) =====
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
