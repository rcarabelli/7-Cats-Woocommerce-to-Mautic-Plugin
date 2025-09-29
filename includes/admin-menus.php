<?php
// includes/admin-menus.php
if (!defined('ABSPATH')) exit;

/**
 * Admin menu for 7C Shopping2Mautic
 *
 * Top-level: 7C Shopping2Mautic  (slug: orders2whatsapp) -> s2m_admin_page_settings()
 * Submenus:
 *  - Settings       (same slug as top-level)
 *  - Magento        (steps 1–6)  -> s2m_admin_page_magento()
 *  - Sync to Mautic              -> s2m_admin_page_sync()
 *  - About                       -> s2m_admin_page_about()
 *
 * NOTE: o2w_admin_page_url() and admin notices redirect back to ?page=orders2whatsapp
 *       so the top-level slug MUST remain 'orders2whatsapp'.
 */

// Single capability used across the plugin (fallback if the constant isn't defined)
$s2m_capability = defined('S2M_REQUIRED_CAP') ? S2M_REQUIRED_CAP : 'manage_options';

add_action('admin_menu', function () use ($s2m_capability) {

    // === TOP-LEVEL ===
    add_menu_page(
        '7C Shopping2Mautic',     // page title
        '7C Shopping2Mautic',     // menu title
        $s2m_capability,          // capability
        'orders2whatsapp',        // menu slug  (keep this; other code depends on it)
        's2m_admin_page_settings',// callback   (existing)
        'dashicons-cart',         // icon
        56                        // position
    );

    // === SUBMENUS ===

    // 1) Settings (same slug as top-level to show as default page)
    add_submenu_page(
        'orders2whatsapp',        // parent slug
        'Settings — 7C Shopping2Mautic', // page title
        'Settings',               // menu title
        $s2m_capability,          // capability
        'orders2whatsapp',        // menu slug (same as parent)
        's2m_admin_page_settings' // callback
    );

    // 2) Magento (steps 1–6)
    add_submenu_page(
        'orders2whatsapp',
        'Magento — Import (steps 1–6)',
        'Magento (steps 1–6)',
        $s2m_capability,
        's2m_magento',
        's2m_admin_page_magento'
    );

    // 3) Sync to Mautic
    add_submenu_page(
        'orders2whatsapp',
        'Sync to Mautic — Woo Backfill + Magento Step 7',
        'Sync to Mautic',
        $s2m_capability,
        's2m_sync',
        's2m_admin_page_sync'
    );

    // 4) About
    if (function_exists('s2m_admin_page_about')) {
        add_submenu_page(
            'orders2whatsapp',
            'About — 7C Shopping2Mautic',
            'About',
            $s2m_capability,
            's2m_about',
            's2m_admin_page_about'
        );
    }
});
