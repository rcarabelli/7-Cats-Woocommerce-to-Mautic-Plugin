<?php
/*
Plugin Name: 7C Shopping2Mautic
Description: Orquesta la captura de pedidos (Woo/Magento) y el envío a Mautic/WhatsApp (vía Zender). Archivo principal del plugin, delgado y sólo coordinador.
Version: 1.0.0
Author: 7 Cats Studio Corp
*/

if (!defined('ABSPATH')) exit;

/**
 * Ruta base del plugin (no hardcodear carpetas/rutas en el código).
 * Usar siempre S2M_PATH para require_once de includes.
 */
define('S2M_PATH', plugin_dir_path(__FILE__));

// Unified capability used for all admin pages and actions.
// Change here if you want a different requirement across the plugin.
if (!defined('S2M_REQUIRED_CAP')) {
    define('S2M_REQUIRED_CAP', 'manage_woocommerce'); // or 'manage_options' if you prefer
}


/* =========================================================
 *  RUNTIME (siempre cargado) — solo clases/funciones, sin UI
 * ========================================================= */
require_once S2M_PATH . 'includes/install-magento-db.php';
require_once S2M_PATH . 'includes/helpers.php';
require_once S2M_PATH . 'includes/class-payload-factory.php';
require_once S2M_PATH . 'includes/channels/class-channel-interface.php';
require_once S2M_PATH . 'includes/channels/class-channel-zender.php';
require_once S2M_PATH . 'includes/channels/class-channel-file.php';
require_once S2M_PATH . 'includes/channels/class-channel-mautic.php';
require_once S2M_PATH . 'includes/mautic-builder-magento.php';
require_once S2M_PATH . 'includes/class-order-events.php';
require_once S2M_PATH . 'includes/class-backfill-latest-per-customer.php';

/* =========================================================
 *  ADMIN (solo UI, notices y acciones admin-post)
 *  Mantener el principal delgado: las páginas y el menú
 *  están modularizados en includes/admin-pages.php y
 *  includes/admin-menus.php respectivamente.
 * ========================================================= */
if (is_admin()) {
    // Core de ajustes (registro de options, sanitizadores, notices)
    require_once S2M_PATH . 'includes/admin-settings-core.php';
    require_once S2M_PATH . 'includes/admin-settings-notices.php';

    // UI de Settings (credenciales + políticas + logs)
    require_once S2M_PATH . 'includes/admin-settings.php';

    // Acciones/instalador de campos Mautic (botón "Create custom fields")
    require_once S2M_PATH . 'includes/admin-mautic-fields.php';
    
    // Woo → Mautic backfill UI (NEW)
    require_once S2M_PATH . 'includes/admin-woo-backfill.php';

    // Bloques/acciones Magento (pasos 1–6 + paso 7: enviar a Mautic)
    require_once S2M_PATH . 'includes/admin-magento-settings.php';
    require_once S2M_PATH . 'includes/admin-magento-import.php';
    require_once S2M_PATH . 'includes/admin-magento-import-details.php';
    require_once S2M_PATH . 'includes/admin-magento-import-customers.php';
    require_once S2M_PATH . 'includes/admin-magento-import-items.php';
    require_once S2M_PATH . 'includes/admin-magento-import-products.php';
    require_once S2M_PATH . 'includes/admin-magento-import-categories.php';
    require_once S2M_PATH . 'includes/admin-magento-send-to-mautic.php';

    // Wrappers de render de páginas de admin (sin lógica)
    require_once S2M_PATH . 'includes/admin-pages.php';

    // Registro del menú (top-level + submenús)
    require_once S2M_PATH . 'includes/admin-menus.php';
    
    //Otros
    require_once S2M_PATH . 'includes/admin-page-about.php';
}

/* =========================
 *  BOOT (Router Woo)
 *  - Enchufa el listener de eventos de pedido si WooCommerce está activo.
 * ========================= */
function s2m_boot() {
    if (!class_exists('WooCommerce')) {
        error_log('[s2m] WooCommerce no está activo; el router de pedidos no se inicia.');
        return;
    }
    $events = new Orders2WhatsApp_Order_Events(new Orders2WhatsApp_Payload_Factory());
    $events->init();
}
add_action('plugins_loaded', 's2m_boot');

/* =========================
 *  ACTIVACIÓN / DESACTIVACIÓN
 *  - Crear carpeta segura de logs/exports en uploads/7c-shop2mautic
 *  - (Opcional) Borrar carpeta al desactivar
 * ========================= */
function s2m_activate() {
    // DB Magento (idempotente)
    if (function_exists('o2w_magento_install_db')) {
        try { o2w_magento_install_db(); } catch (Throwable $e) {
            error_log('[s2m] Error instalando DB Magento: ' . $e->getMessage());
        }
    }

    // Carpeta segura de logs/exports
    $upload = wp_upload_dir();
    $dir    = trailingslashit($upload['basedir']) . '7c-shop2mautic';
    if (!file_exists($dir)) {
        @wp_mkdir_p($dir);
    }
    if (is_dir($dir)) {
        $ht = trailingslashit($dir) . '.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "Deny from all\n");
        }
        $ix = trailingslashit($dir) . 'index.html';
        if (!file_exists($ix)) {
            @file_put_contents($ix, '');
        }
    }
}
register_activation_hook(__FILE__, 's2m_activate');

/**
 * Borrado recursivo simple (con cuidado).
 * No seguir symlinks, sólo contenido normal.
 */
function s2m_rrmdir($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return @unlink($dir);
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        if (!s2m_rrmdir($dir . DIRECTORY_SEPARATOR . $f)) return false;
    }
    return @rmdir($dir);
}

function s2m_deactivate() {
    // Si quieres conservar histórico, comenta este bloque.
    $upload = wp_upload_dir();
    $dir    = trailingslashit($upload['basedir']) . '7c-shop2mautic';
    if (file_exists($dir) && is_dir($dir)) {
        s2m_rrmdir($dir);
    }
}
register_deactivation_hook(__FILE__, 's2m_deactivate');
