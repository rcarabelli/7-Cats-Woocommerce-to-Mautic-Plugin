<?php
// includes/admin-pages.php
if (!defined('ABSPATH')) exit;

/** Subpage 1: Settings (uses the already existing UI) */
function s2m_admin_page_settings() {
    if (function_exists('orders2whatsapp_settings_page')) {
        orders2whatsapp_settings_page();
    } else {
        echo '<div class="wrap"><h1>Settings</h1><p><code>orders2whatsapp_settings_page()</code> not found.</p></div>';
    }
}

/** Subpage 2: Magento (shows ONLY blocks 1–6) */
function s2m_admin_page_magento() {
    echo '<div class="wrap"><h1>Magento — Import (steps 1–6)</h1>';

    // Page intro (appears under the title)
    echo '<p class="description" style="max-width:800px; margin-bottom:20px;">'
        . 'This page lets you connect to your Magento store and import its data into WordPress. '
        . 'The process is divided into six sequential steps:<br><br>'
        . '<strong>Step 1 — IDs:</strong> Seeds order IDs locally to prepare for batch processing.<br>'
        . '<strong>Step 2 — Details:</strong> Imports full order information including totals, status, and dates.<br>'
        . '<strong>Step 3 — Customers:</strong> Fetches all registered Magento customers.<br>'
        . '<strong>Step 4 — Items:</strong> Loads order items and maps them to products.<br>'
        . '<strong>Step 5 — Products:</strong> Retrieves the Magento product catalog.<br>'
        . '<strong>Step 6 — Categories:</strong> Brings in the Magento category tree.<br><br>'
        . 'Each block shows the current queue or progress state, and you can run imports in batches (100, 200, 1000) '
        . 'to avoid overloading your Magento API. You may also reset cursors or queues if needed.'
        . '</p>';
    if (function_exists('o2w_render_magento_settings_section'))        o2w_render_magento_settings_section();
    if (function_exists('o2w_render_magento_import_section'))          o2w_render_magento_import_section();
    if (function_exists('o2w_render_magento_import_details_section'))  o2w_render_magento_import_details_section();
    if (function_exists('o2w_customers_render_import_section'))        o2w_customers_render_import_section();
    if (function_exists('o2w_render_magento_import_items_section'))    o2w_render_magento_import_items_section();
    if (function_exists('o2w_render_magento_import_products_section')) o2w_render_magento_import_products_section();
    if (function_exists('o2w_render_magento_import_categories_section')) o2w_render_magento_import_categories_section();
    echo '</div>';
}

/** Subpage 3: Sync to Mautic (Woo Backfill + Magento Step 7) */
function s2m_admin_page_sync() {
    echo '<div class="wrap"><h1>Sync to Mautic</h1>';

    // Page intro (appears under the title)
    echo '<p class="description" style="max-width:800px; margin-bottom:20px;">'
        . 'This page lets you manage how order data is synchronized into Mautic.<br><br>'
        . '<strong>Backfill Woo → Mautic:</strong> Ensures each WooCommerce customer has their latest order recorded in Mautic. '
        . 'You can run records one by one, in batches, or start a background process until all are complete.<br><br>'
        . '<strong>Magento → Mautic (Step 7):</strong> Sends Magento orders (imported in steps 1–6) into Mautic. '
        . 'The queue shows how many are pending, retried, processing, or done. You can send them in batches, retry failed ones, '
        . 'or re-queue recently completed ones.<br><br>'
        . 'In short: the top section keeps WooCommerce customers up to date in Mautic, and the bottom section pushes Magento order '
        . 'data to Mautic with full control over queue states.'
        . '</p>';

    // Block A: Woo Backfill (UI renderer moved out)
    if (function_exists('s2m_render_woo_backfill_section')) {
        s2m_render_woo_backfill_section();
    } else {
        // Fallback: class present but no renderer, or neither present
        if (class_exists('Orders2WhatsApp_Backfill')) {
            echo '<p>Woo → Mautic Backfill UI not available (renderer missing).</p><hr>';
        } else {
            echo '<p>Backfill class not found.</p><hr>';
        }
    }

    // Block B: Magento Step 7
    if (function_exists('o2w_render_magento_send_to_mautic_section')) {
        o2w_render_magento_send_to_mautic_section();
    } else {
        echo '<p><code>o2w_render_magento_send_to_mautic_section()</code> not found.</p>';
    }

    echo '</div>';
}
