<?php
if (!defined('ABSPATH')) exit;

/**
 * Main landing page (About / Dashboard) for the plugin.
 * This replaces the default behavior where the root menu
 * redirected directly to Settings.
 */
function s2m_admin_page_about() {
    ?>
    <div class="wrap">
        <h1>7C Shopping2Mautic</h1>
        <p class="description">
            Marketing automation bridge for WooCommerce and Magento — connected to Mautic and WhatsApp via Zender.
        </p>

        <hr>

        <h2>What does this plugin do?</h2>
        <p>
            <strong>7C Shopping2Mautic</strong> orchestrates order capture and synchronization between 
            <strong>WooCommerce</strong>, <strong>Magento</strong>, and <strong>Mautic</strong>. 
            It enables automated campaigns for recovery, thank-you messages, and cross-selling opportunities.
        </p>

        <p>
            It also integrates with <strong>Zender</strong> so you can send real-time WhatsApp messages 
            (purchase confirmations, order status updates) in addition to WooCommerce’s default email notifications.
        </p>

        <h2>Main Features</h2>
        <ul style="list-style:disc; margin-left:20px;">
            <li>Capture WooCommerce orders and status changes → sync to Mautic.</li>
            <li>Full Magento import (customers, products, orders).</li>
            <li>Periodic cron-based sync for new Magento orders → Mautic.</li>
            <li>Ready for recovery, thank-you, and cross-sell campaigns.</li>
            <li>WhatsApp messaging through Zender (purchase & status updates).</li>
        </ul>

        <h2>Plugin Information</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Company</th>
                <td>7 Cats Studio Corp</td>
            </tr>
            <tr>
                <th scope="row">Author</th>
                <td>Renato Carabelli</td>
            </tr>
            <tr>
                <th scope="row">Contact</th>
                <td><a href="mailto:info@7catstudio.com">info@7catstudio.com</a></td>
            </tr>
            <tr>
                <th scope="row">Website</th>
                <td><a href="https://www.7catstudio.com" target="_blank">www.7catstudio.com</a></td>
            </tr>
            <tr>
                <th scope="row">License</th>
                <td>MIT</td>
            </tr>
            <tr>
                <th scope="row">Release Date</th>
                <td>September 2025</td>
            </tr>
        </table>

        <hr>
        <p style="font-size:12px; color:#666;">
            © 2025 7 Cats Studio Corp. Distributed under the MIT License.
        </p>
    </div>
    <?php
}
