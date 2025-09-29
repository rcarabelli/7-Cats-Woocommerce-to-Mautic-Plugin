<?php
if (!defined('ABSPATH')) exit;

/**
 * Página 1: Settings generales
 * - Credenciales Zender
 * - Credenciales Mautic (+ OAuth2) y Logs
 * - Credenciales Magento
 * (Sólo DISPLAY/UI. El register_setting/sanitizadores ya viven en admin-settings-core.php)
 */
function o2w_render_page_settings() {
    // Valores actuales
    $api_url    = esc_attr(get_option('orders2whatsapp_api_url'));
    $api_secret = esc_attr(get_option('orders2whatsapp_api_secret'));
    $device_id  = esc_attr(get_option('orders2whatsapp_device_id'));
    $admin_nums = esc_attr(get_option('orders2whatsapp_admin_numbers'));

    $mautic_url   = esc_attr(get_option('orders2whatsapp_mautic_url'));
    $mautic_token = esc_attr(get_option('orders2whatsapp_mautic_token'));

    $oauth_client_id     = esc_attr(get_option('orders2whatsapp_mautic_oauth_client_id'));
    $oauth_client_secret = esc_attr(get_option('orders2whatsapp_mautic_oauth_client_secret'));
    $oauth_username      = esc_attr(get_option('orders2whatsapp_mautic_oauth_username'));
    $oauth_password      = esc_attr(get_option('orders2whatsapp_mautic_oauth_password'));

    $log_enabled       = (int) get_option('orders2whatsapp_mautic_log_enabled', 0);
    $log_retentionDays = (int) get_option('orders2whatsapp_mautic_log_retention_days', 14);

    // Credenciales Magento
    if (!defined('O2W_OPT_MAGENTO_URL'))  define('O2W_OPT_MAGENTO_URL',  'o2w_magento_url');
    if (!defined('O2W_OPT_MAGENTO_USER')) define('O2W_OPT_MAGENTO_USER', 'o2w_magento_user');
    if (!defined('O2W_OPT_MAGENTO_PASS')) define('O2W_OPT_MAGENTO_PASS', 'o2w_magento_pass');

    $magento_url  = esc_attr(get_option(O2W_OPT_MAGENTO_URL, ''));
    $magento_user = esc_attr(get_option(O2W_OPT_MAGENTO_USER, ''));
    $magento_pass = esc_attr(get_option(O2W_OPT_MAGENTO_PASS, ''));

    // Link “Create custom fields” (si ya tienes el handler)
    $create_fields_url = wp_nonce_url(
        admin_url('admin-post.php?action=orders2whatsapp_create_mautic_fields'),
        'o2w_create_mautic_fields'
    );
    ?>
    <div class="wrap">
        <h1>Settings — 7C Shopping2Mautic</h1>

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

            <hr>

            <h2 class="title">Mautic</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_url">Mautic Base URL</label></th>
                    <td>
                        <input id="orders2whatsapp_mautic_url" type="url" name="orders2whatsapp_mautic_url" value="<?php echo $mautic_url; ?>" class="regular-text" style="width:100%" placeholder="https://your-mautic.tld">
                        <p class="description">No pongas <code>/api</code>; el canal arma los endpoints.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_token">Token / Authentication mode</label></th>
                    <td>
                        <input id="orders2whatsapp_mautic_token" type="text" name="orders2whatsapp_mautic_token" value="<?php echo $mautic_token; ?>" class="regular-text" style="width:100%" placeholder="oauth2  |  key:MY_API_KEY  |  &lt;token&gt;">
                        <p class="description">
                            Usa <code>oauth2</code> para OAuth2 (password grant) con los campos de abajo.<br>
                            Usa <code>key:MI_API_KEY</code> para header <code>X-Api-Key</code>.<br>
                            O pega un token para <code>Authorization: Bearer &lt;token&gt;</code>.
                        </p>
                    </td>
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

            <p style="margin:8px 0 16px;">
                <a href="<?php echo esc_url($create_fields_url); ?>" class="button button-secondary">Create custom fields</a>
                <span class="description" style="margin-left:8px;">Crea/actualiza los campos custom en Mautic usados por el plugin.</span>
            </p>

            <h3 class="title">Mautic Logs</h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_log_enabled">Enable request/response logs</label></th>
                    <td>
                        <label>
                            <input id="orders2whatsapp_mautic_log_enabled" type="checkbox" name="orders2whatsapp_mautic_log_enabled" value="1" <?php checked(1, $log_enabled); ?> />
                            Guardar un .txt por llamada (payload + respuesta del API).
                        </label>
                        <p class="description">Se guardan en <code>/wp-content/uploads/7c-shop2mautic/</code> (prefijo <code>mautic-</code>).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_log_retention_days">Log retention (days)</label></th>
                    <td>
                        <input id="orders2whatsapp_mautic_log_retention_days" type="number" min="0" step="1" name="orders2whatsapp_mautic_log_retention_days" value="<?php echo $log_retentionDays; ?>" class="small-text" />
                        <p class="description">0 = no borrar automático (no recomendado).</p>
                    </td>
                </tr>
            </table>

            <hr>

            <h2 class="title">Magento</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="o2w_magento_url">Magento REST Base URL</label></th>
                    <td><input id="o2w_magento_url" type="url" name="<?php echo esc_attr(O2W_OPT_MAGENTO_URL); ?>" value="<?php echo $magento_url; ?>" class="regular-text" style="width:100%" placeholder="https://magento.tld/rest"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="o2w_magento_user">Admin user</label></th>
                    <td><input id="o2w_magento_user" type="text" name="<?php echo esc_attr(O2W_OPT_MAGENTO_USER); ?>" value="<?php echo $magento_user; ?>" class="regular-text" style="width:100%"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="o2w_magento_pass">Admin password</label></th>
                    <td><input id="o2w_magento_pass" type="password" name="<?php echo esc_attr(O2W_OPT_MAGENTO_PASS); ?>" value="<?php echo $magento_pass; ?>" class="regular-text" style="width:100%"></td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
