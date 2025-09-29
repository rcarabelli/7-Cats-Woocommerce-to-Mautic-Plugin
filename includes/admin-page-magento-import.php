<?php
if (!defined('ABSPATH')) exit;

/**
 * Página 2: Magento Import (pasos 1–6)
 * Renderiza, en orden, todos los bloques de importación disponibles.
 * (Cada bloque ya tiene su propio UI y admin-post handler).
 */
function o2w_render_page_magento_import() { ?>
    <div class="wrap">
        <h1>Magento — Import (pasos 1–6)</h1>
        <p class="description">Primero configura las credenciales en <strong>“7C O2W — Settings”</strong>. Luego usa estos módulos para traer datos a la DB local.</p>
        <hr>
        <?php
        // Llama a los bloques si existen (según tu instalación actual)
        if (function_exists('o2w_render_magento_settings_section'))        o2w_render_magento_settings_section();
        if (function_exists('o2w_render_magento_import_section'))          o2w_render_magento_import_section();
        if (function_exists('o2w_render_magento_import_details_section'))  o2w_render_magento_import_details_section();
        if (function_exists('o2w_customers_render_import_section'))        o2w_customers_render_import_section();
        if (function_exists('o2w_render_magento_import_items_section'))    o2w_render_magento_import_items_section();
        if (function_exists('o2w_render_magento_import_products_section')) o2w_render_magento_import_products_section();
        if (function_exists('o2w_render_magento_import_categories_section')) o2w_render_magento_import_categories_section();
        ?>
    </div>
<?php }
