<?php
// includes/admin-settings.php
if (!defined('ABSPATH')) exit;

/**
 * UI ONLY for the Settings page.
 * Logic lives in:
 *  - includes/admin-settings-core.php    (register_setting, sanitizers)
 *  - includes/admin-settings-notices.php (admin_notices)
 */

function orders2whatsapp_settings_page() {
    // Woo statuses for notification policy checkboxes
    $wc_statuses  = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
    $status_slugs = [];
    foreach ($wc_statuses as $key => $label) { $status_slugs[ substr($key, 3) ] = $label; }

    // ===== Current option values =====
    // Mautic
    $mautic_url          = esc_attr(get_option('orders2whatsapp_mautic_url'));
    $mautic_token        = esc_attr(get_option('orders2whatsapp_mautic_token'));
    $oauth_client_id     = esc_attr(get_option('orders2whatsapp_mautic_oauth_client_id'));
    $oauth_client_secret = esc_attr(get_option('orders2whatsapp_mautic_oauth_client_secret'));
    $oauth_username      = esc_attr(get_option('orders2whatsapp_mautic_oauth_username'));
    $oauth_password      = esc_attr(get_option('orders2whatsapp_mautic_oauth_password'));
    $tag_woo             = esc_attr(get_option('orders2whatsapp_mautic_tag_woo', 'woocommerce'));
    $tag_magento         = esc_attr(get_option('orders2whatsapp_mautic_tag_magento', 'magento'));

    // Mautic Logs
    $log_enabled       = (int) get_option('orders2whatsapp_mautic_log_enabled', 0);
    $log_retentionDays = (int) get_option('orders2whatsapp_mautic_log_retention_days', 14);

    // Policies
    $notify_sel = (array) get_option('orders2whatsapp_notify_statuses', ['processing','completed','cancelled','refunded']);
    $default_cc = esc_attr(get_option('orders2whatsapp_default_country_code', '51'));

    // Zender
    $api_url    = esc_attr(get_option('orders2whatsapp_api_url'));
    $api_secret = esc_attr(get_option('orders2whatsapp_api_secret'));
    $device_id  = esc_attr(get_option('orders2whatsapp_device_id'));
    $admin_nums = esc_attr(get_option('orders2whatsapp_admin_numbers'));

    // Safe action to create custom fields in Mautic
    $create_fields_url = wp_nonce_url(
        admin_url('admin-post.php?action=orders2whatsapp_create_mautic_fields'),
        'o2w_create_mautic_fields'
    );
    ?>
    <div class="wrap">
        <h1>7C Shopping2Mautic — Plugin Settings</h1>
        <p class="description" style="max-width:820px">
            Configure the connection to <strong>Mautic</strong>, WhatsApp via <strong>Zender</strong>, and basic notification policies.
            Magento credentials are included at the end of this page.
        </p>

        <!-- ================= Mautic ================= -->
        <form method="post" action="options.php">
            <?php settings_fields('orders2whatsapp_settings_group'); ?>
            <?php do_settings_sections('orders2whatsapp_settings_group'); ?>

            <h2 class="title">Mautic</h2>
            <p>Configure how to authenticate with your Mautic instance.</p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_url">Mautic Base URL</label></th>
                    <td>
                        <input id="orders2whatsapp_mautic_url" type="url" name="orders2whatsapp_mautic_url" value="<?php echo $mautic_url; ?>" class="regular-text" style="width:100%" placeholder="https://your-mautic.tld">
                        <p class="description">Do not include <code>/api</code> or endpoints; the plugin builds them.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_token">Token / Authentication mode</label></th>
                    <td>
                        <input id="orders2whatsapp_mautic_token" type="text" name="orders2whatsapp_mautic_token" value="<?php echo $mautic_token; ?>" class="regular-text" style="width:100%" placeholder="oauth2  |  key:MY_API_KEY  |  &lt;token&gt;">
                        <p class="description">
                            Enter <code>oauth2</code> to use OAuth2 (password grant) with the fields below.<br>
                            Enter <code>key:MY_API_KEY</code> to use <code>X-Api-Key</code>.<br>
                            Or paste a long token to use <code>Authorization: Bearer &lt;token&gt;</code> (PAT or other).
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_tag_woo">Default tag for WooCommerce</label></th>
                    <td><input id="orders2whatsapp_mautic_tag_woo" type="text" name="orders2whatsapp_mautic_tag_woo" value="<?php echo $tag_woo; ?>" class="regular-text" placeholder="woocommerce"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_tag_magento">Default tag for Magento</label></th>
                    <td><input id="orders2whatsapp_mautic_tag_magento" type="text" name="orders2whatsapp_mautic_tag_magento" value="<?php echo $tag_magento; ?>" class="regular-text" placeholder="magento"></td>
                </tr>
            </table>

            <h3 class="title">OAuth2 (Password Grant)</h3>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><label for="orders2whatsapp_mautic_oauth_client_id">Client ID</label></th>
                    <td><input id="orders2whatsapp_mautic_oauth_client_id" type="text" name="orders2whatsapp_mautic_oauth_client_id" value="<?php echo $oauth_client_id; ?>" class="regular-text" style="width:100%"></td></tr>
                <tr><th scope="row"><label for="orders2whatsapp_mautic_oauth_client_secret">Client Secret</label></th>
                    <td><input id="orders2whatsapp_mautic_oauth_client_secret" type="text" name="orders2whatsapp_mautic_oauth_client_secret" value="<?php echo $oauth_client_secret; ?>" class="regular-text" style="width:100%"></td></tr>
                <tr><th scope="row"><label for="orders2whatsapp_mautic_oauth_username">Username</label></th>
                    <td><input id="orders2whatsapp_mautic_oauth_username" type="text" name="orders2whatsapp_mautic_oauth_username" value="<?php echo $oauth_username; ?>" class="regular-text" style="width:100%"></td></tr>
                <tr><th scope="row"><label for="orders2whatsapp_mautic_oauth_password">Password</label></th>
                    <td><input id="orders2whatsapp_mautic_oauth_password" type="password" name="orders2whatsapp_mautic_oauth_password" value="<?php echo $oauth_password; ?>" class="regular-text" style="width:100%"></td></tr>
            </table>

            <p>
                <a href="<?php echo esc_url($create_fields_url); ?>" class="button button-secondary">Create custom fields</a>
                <span class="description" style="margin-left:8px;">Creates/updates the custom fields in Mautic used by this plugin.</span>
            </p>

            <h3 class="title">Mautic Logs</h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_log_enabled">Enable request/response logs</label></th>
                    <td>
                        <label>
                            <input id="orders2whatsapp_mautic_log_enabled" type="checkbox" name="orders2whatsapp_mautic_log_enabled" value="1" <?php checked(1, $log_enabled); ?> />
                            Save one .txt per Mautic call with the payload and the raw API response.
                        </label>
                        <p class="description">Files saved in <code>/wp-content/uploads/7c-shop2mautic/</code> with prefix <code>mautic-</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_log_retention_days">Log retention (days)</label></th>
                    <td><input id="orders2whatsapp_mautic_log_retention_days" type="number" min="0" step="1" name="orders2whatsapp_mautic_log_retention_days" value="<?php echo $log_retentionDays; ?>" class="small-text" />
                        <p class="description">0 = no automatic deletion (not recommended).</p></td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <!-- separator -->
        <hr>

        <!-- ================= Zender (WhatsApp) — now above Policies ================= -->
        <form method="post" action="options.php">
            <?php settings_fields('orders2whatsapp_settings_group'); ?>
            <?php do_settings_sections('orders2whatsapp_settings_group'); ?>

            <h2 class="title">WhatsApp (Zender)</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="orders2whatsapp_api_url">Zender API URL</label></th>
                    <td><input id="orders2whatsapp_api_url" type="url" name="orders2whatsapp_api_url" value="<?php echo $api_url; ?>" class="regular-text" style="width:100%"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="orders2whatsapp_api_secret">API Secret</label></th>
                    <td><input id="orders2whatsapp_api_secret" type="text" name="orders2whatsapp_api_secret" value="<?php echo $api_secret; ?>" class="regular-text" style="width:100%"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="orders2whatsapp_device_id">Device ID (account)</label></th>
                    <td><input id="orders2whatsapp_device_id" type="text" name="orders2whatsapp_device_id" value="<?php echo $device_id; ?>" class="regular-text" style="width:100%"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="orders2whatsapp_admin_numbers">Admin numbers (comma-separated)</label></th>
                    <td><input id="orders2whatsapp_admin_numbers" type="text" name="orders2whatsapp_admin_numbers" value="<?php echo $admin_nums; ?>" class="regular-text" style="width:100%"></td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <!-- separator -->
        <hr>

        <!-- ================= Notification Policies (WhatsApp) ================= -->
        <form method="post" action="options.php">
            <?php settings_fields('orders2whatsapp_settings_group'); ?>
            <?php do_settings_sections('orders2whatsapp_settings_group'); ?>

            <h2 class="title">Notification policies (WhatsApp)</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Statuses to notify</th>
                    <td>
                        <?php if (!empty($status_slugs)) : ?>
                            <?php foreach ($status_slugs as $slug => $label) : ?>
                                <?php $checked = in_array($slug, $notify_sel, true) ? 'checked' : ''; $id = 'orders2whatsapp_status_' . esc_attr($slug); ?>
                                <label for="<?php echo $id; ?>" style="display:inline-block; margin:0 16px 8px 0;">
                                    <input id="<?php echo $id; ?>" type="checkbox" name="orders2whatsapp_notify_statuses[]"
                                           value="<?php echo esc_attr($slug); ?>" <?php echo $checked; ?> />
                                    <?php echo esc_html($label); ?> <code>(<?php echo esc_html($slug); ?>)</code>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">If you also want to notify when the order is created, you can add the pseudo-status <code>created</code>.</p>
                        <?php else : ?>
                            <em>No WooCommerce data detected. Is WooCommerce active?</em>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="orders2whatsapp_default_country_code">Default country code</label></th>
                    <td>
                        <input id="orders2whatsapp_default_country_code" type="text" name="orders2whatsapp_default_country_code"
                               value="<?php echo $default_cc; ?>" class="small-text">
                        <p class="description">Prepended to phone numbers without country code. Default: <code>51</code> (Peru).</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <!-- separator -->
        <hr>

        <!-- ================= Magento (credentials only) ================= -->
        <div>
            <h2 class="title">Magento</h2>
            <?php
            // Render ONLY the credentials/settings block (no imports, no progress here).
            if (function_exists('o2w_render_magento_settings_section')) {
                o2w_render_magento_settings_section();
            } else {
                echo '<p><em>Magento settings section not found.</em></p>';
            }
            ?>
        </div>

    </div>
    <?php
}
