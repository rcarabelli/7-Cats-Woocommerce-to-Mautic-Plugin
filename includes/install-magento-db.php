<?php
// includes/install-magento-db.php
if (!defined('ABSPATH')) exit;

/**
 * Aumenta la versión al cambiar el esquema
 * (subimos a 1.4.0 para forzar dbDelta con la nueva tabla de categorías)
 */
define('O2W_DB_VERSION', '1.4.0');

/** Helpers de nombres de tabla (con prefijo WP) */
function o2w_magento_orders_table()     { global $wpdb; return $wpdb->prefix . 'o2w_magento_orders'; }
function o2w_magento_customers_table()  { global $wpdb; return $wpdb->prefix . 'o2w_magento_customers'; }
function o2w_magento_order_items_table(){ global $wpdb; return $wpdb->prefix . 'o2w_magento_order_items'; }
function o2w_magento_products_table()   { global $wpdb; return $wpdb->prefix . 'o2w_magento_products'; }
function o2w_magento_categories_table() { global $wpdb; return $wpdb->prefix . 'o2w_magento_categories'; } // <-- NUEVA

/**
 * Crea/actualiza el esquema:
 * - orders: snapshot básico + control de cola
 * - customers: maestro por email
 * - order_items: líneas vendidas (snapshot)
 * - products: maestro de productos (coincide con columnas que inserta el importador)
 * - categories: maestro de categorías (árbol)
 */
function o2w_magento_install_db() {
    global $wpdb;
    $orders = o2w_magento_orders_table();
    $custs  = o2w_magento_customers_table();
    $items  = o2w_magento_order_items_table();
    $prods  = o2w_magento_products_table();
    $cats   = o2w_magento_categories_table(); // <-- NUEVA
    $collate = $wpdb->get_charset_collate();

    // --- Tabla de pedidos (esquema reducido) ---
    $sql_orders = "CREATE TABLE $orders (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

        entity_id BIGINT(20) UNSIGNED NOT NULL,
        increment_id VARCHAR(64) NULL,

        store_id INT(11) NULL,
        customer_id INT(11) NULL,
        customer_email VARCHAR(255) NULL,

        status VARCHAR(32) NULL,
        grand_total DECIMAL(18,4) NULL,
        currency_code VARCHAR(8) NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,

        queue_state VARCHAR(20) NOT NULL DEFAULT 'pending',
        last_error TEXT NULL,
        last_seen_at DATETIME NULL,

        PRIMARY KEY  (id),
        UNIQUE KEY uq_entity_id (entity_id),
        KEY idx_increment_id (increment_id),
        KEY idx_created_at (created_at),
        KEY idx_status (status),
        KEY idx_queue_state (queue_state),
        KEY idx_customer_email (customer_email)
    ) $collate;";

    // --- Maestro de clientes (clave natural: email) ---
    $sql_customers = "CREATE TABLE $custs (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

        customer_id INT(11) NULL,
        email VARCHAR(255) NOT NULL,
        first_name VARCHAR(100) NULL,
        last_name VARCHAR(100) NULL,
        full_name VARCHAR(255) NULL,
        phone VARCHAR(64) NULL,

        first_seen_at DATETIME NULL,
        last_order_at DATETIME NULL,
        orders_count INT(11) NOT NULL DEFAULT 0,

        PRIMARY KEY (id),
        UNIQUE KEY uq_email (email),
        KEY idx_customer_id (customer_id)
    ) $collate;";

    // --- Líneas de pedido (snapshot de venta) ---
    $sql_items = "CREATE TABLE $items (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

        order_entity_id BIGINT(20) UNSIGNED NOT NULL,
        item_id BIGINT(20) UNSIGNED NOT NULL,
        sku VARCHAR(128) NULL,
        product_id INT(11) NULL,
        product_name VARCHAR(255) NULL,

        qty_ordered DECIMAL(18,4) NULL,
        price DECIMAL(18,4) NULL,
        row_total DECIMAL(18,4) NULL,
        price_incl_tax DECIMAL(18,4) NULL,
        row_total_incl_tax DECIMAL(18,4) NULL,

        created_at DATETIME NULL,

        PRIMARY KEY (id),
        UNIQUE KEY uq_order_item (order_entity_id, item_id),
        KEY idx_order (order_entity_id),
        KEY idx_sku (sku)
    ) $collate;";

    // --- Maestro de productos (alineado con el importador) ---
    $sql_products = "CREATE TABLE $prods (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

        product_id INT(11) NOT NULL,
        sku VARCHAR(128) NOT NULL,
        name VARCHAR(255) NULL,
        status INT(11) NULL,
        visibility INT(11) NULL,
        type_id VARCHAR(32) NULL,
        attribute_set_id INT(11) NULL,

        price DECIMAL(18,4) NULL,
        special_price DECIMAL(18,4) NULL,
        cost DECIMAL(18,4) NULL,
        weight DECIMAL(12,3) NULL,

        created_at DATETIME NULL,
        updated_at DATETIME NULL,

        url_key VARCHAR(255) NULL,
        image VARCHAR(512) NULL,
        small_image VARCHAR(512) NULL,
        thumbnail VARCHAR(512) NULL,

        media_gallery_json LONGTEXT NULL,
        categories_json LONGTEXT NULL,

        stock_qty DECIMAL(18,4) NULL,
        is_in_stock TINYINT(1) NULL,

        brand VARCHAR(191) NULL,
        color VARCHAR(191) NULL,
        size VARCHAR(191) NULL,

        -- legacy
        image_url VARCHAR(512) NULL,
        category_ids_json LONGTEXT NULL,

        PRIMARY KEY (id),
        UNIQUE KEY uq_product_id (product_id),
        UNIQUE KEY uq_sku (sku),
        KEY idx_name (name),
        KEY idx_status (status),
        KEY idx_visibility (visibility)
    ) $collate;";

    // --- Maestro de categorías (alineado al REST de Magento) ---
    $sql_categories = "CREATE TABLE $cats (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

        category_id INT(11) NOT NULL,          -- catalog_category_entity.entity_id
        parent_id INT(11) NULL,
        path VARCHAR(255) NULL,                 -- ej. 1/2/3
        level INT(11) NULL,
        position INT(11) NULL,
        is_active TINYINT(1) NULL,

        name VARCHAR(255) NULL,
        url_key VARCHAR(255) NULL,
        url_path VARCHAR(255) NULL,
        image VARCHAR(512) NULL,
        include_in_menu TINYINT(1) NULL,
        children_count INT(11) NULL,

        created_at DATETIME NULL,
        updated_at DATETIME NULL,

        meta_title VARCHAR(255) NULL,
        meta_keywords TEXT NULL,
        meta_description TEXT NULL,

        custom_attributes_json LONGTEXT NULL,   -- por si llegan otros atributos

        PRIMARY KEY (id),
        UNIQUE KEY uq_category_id (category_id),
        KEY idx_parent_id (parent_id),
        KEY idx_level (level),
        KEY idx_is_active (is_active),
        KEY idx_name (name)
    ) $collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_orders);
    dbDelta($sql_customers);
    dbDelta($sql_items);
    dbDelta($sql_products);
    dbDelta($sql_categories); // <-- NUEVA

    update_option('o2w_db_version', O2W_DB_VERSION, false);
}

/** Ejecuta upgrade si la versión cambia (idempotente) */
function o2w_magento_maybe_upgrade_db() {
    $cur = get_option('o2w_db_version');
    if ($cur !== O2W_DB_VERSION) {
        o2w_magento_install_db();
    }
}
add_action('plugins_loaded', 'o2w_magento_maybe_upgrade_db');