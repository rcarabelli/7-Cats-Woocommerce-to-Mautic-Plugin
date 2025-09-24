<?php
/*
Plugin Name: 7C WooCommerce Orders to WhatsApp
Description: Captura eventos de pedidos WooCommerce y los envía a canales configurables (WhatsApp vía Zender, archivo .txt y JSON para Mautic).
Version: 1.1
Author: Renato Carabelli - 7 Cats Studio Corp
*/

// Evitar acceso directo.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ruta base del plugin para resolver includes de forma robusta.
 * Ej: WC_ORDERS2WHATSAPP_PATH . 'includes/...'
 */
define('WC_ORDERS2WHATSAPP_PATH', plugin_dir_path(__FILE__));

/**
 * === INCLUDES ===
 * - admin-settings.php: Ajustes del plugin (Zender, Mautic, políticas).
 * - helpers.php: utilidades (normalizar teléfono, formateo de precio).
 * - Fábrica de payloads y canales de salida (Zender, archivo, Mautic).
 * - Order_Events: router que escucha hooks de WooCommerce y orquesta canales.
 */
require_once WC_ORDERS2WHATSAPP_PATH . 'includes/admin-settings.php';
require_once WC_ORDERS2WHATSAPP_PATH . 'includes/admin-mautic-fields.php';

require_once WC_ORDERS2WHATSAPP_PATH . 'includes/helpers.php';
require_once WC_ORDERS2WHATSAPP_PATH . 'includes/class-payload-factory.php';
require_once WC_ORDERS2WHATSAPP_PATH . 'includes/channels/class-channel-interface.php';
require_once WC_ORDERS2WHATSAPP_PATH . 'includes/channels/class-channel-zender.php';
require_once WC_ORDERS2WHATSAPP_PATH . 'includes/channels/class-channel-file.php';
require_once WC_ORDERS2WHATSAPP_PATH . 'includes/channels/class-channel-mautic.php';
require_once WC_ORDERS2WHATSAPP_PATH . 'includes/class-order-events.php';
require_once WC_ORDERS2WHATSAPP_PATH . 'includes/class-backfill-latest-per-customer.php';

/**
 * Bootstrap cuando WP terminó de cargar plugins:
 * - Verifica WooCommerce
 * - Inicializa el orquestador con la fábrica de payloads
 */
function orders2whatsapp_boot() {
    if (!class_exists('WooCommerce')) {
        error_log('[o2w] boot: WooCommerce no está activo');
        return;
    }
    error_log('[o2w] boot: OK, cargando Order_Events');

    $events = new Orders2WhatsApp_Order_Events(
        new Orders2WhatsApp_Payload_Factory()
    );
    $events->init();
}

add_action('plugins_loaded', 'orders2whatsapp_boot');

/**
 * === ACTIVACIÓN ===
 * Crea carpeta segura en /uploads/7c-wc-orders2whatsapp y protege con .htaccess (Apache).
 * En Nginx .htaccess no aplica, por eso además creamos index.html vacío.
 */
function orders2whatsapp_activate() {
    $upload_dir      = wp_upload_dir();
    $order_files_dir = trailingslashit($upload_dir['basedir']) . '7c-wc-orders2whatsapp';

    if (!file_exists($order_files_dir)) {
        if (!wp_mkdir_p($order_files_dir)) {
            error_log('[orders2whatsapp] No se pudo crear: ' . $order_files_dir);
            return;
        }
    }

    // Bloqueo para Apache
    $htaccess_file = trailingslashit($order_files_dir) . '.htaccess';
    if (!file_exists($htaccess_file)) {
        $htaccess_content = "Deny from all\n";
        if (false === file_put_contents($htaccess_file, $htaccess_content)) {
            error_log('[orders2whatsapp] No se pudo escribir .htaccess en: ' . $order_files_dir);
        }
    }

    // Evita listados accidentales
    $index_file = trailingslashit($order_files_dir) . 'index.html';
    if (!file_exists($index_file)) {
        @file_put_contents($index_file, '');
    }
}
register_activation_hook(__FILE__, 'orders2whatsapp_activate');

/**
 * Eliminación recursiva de carpeta/archivos.
 * Nota: si prefieres conservar histórico al desactivar, mueve esto a uninstall.php.
 */
function orders2whatsapp_rrmdir($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return @unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (!orders2whatsapp_rrmdir($path)) return false;
    }
    return @rmdir($dir);
}

/**
 * === DESACTIVACIÓN ===
 * Borra /uploads/7c-wc-orders2whatsapp (histórico). Comenta si deseas conservar.
 */
function orders2whatsapp_deactivate() {
    $upload_dir      = wp_upload_dir();
    $order_files_dir = trailingslashit($upload_dir['basedir']) . '7c-wc-orders2whatsapp';

    error_log('[orders2whatsapp] Intentando borrar: ' . $order_files_dir);

    if (file_exists($order_files_dir) && is_dir($order_files_dir)) {
        if (orders2whatsapp_rrmdir($order_files_dir)) {
            error_log('[orders2whatsapp] Carpeta eliminada: ' . $order_files_dir);
        } else {
            error_log('[orders2whatsapp] Falló al eliminar: ' . $order_files_dir);
        }
    } else {
        error_log('[orders2whatsapp] Carpeta no existe o no es directorio: ' . $order_files_dir);
    }
}
register_deactivation_hook(__FILE__, 'orders2whatsapp_deactivate');
