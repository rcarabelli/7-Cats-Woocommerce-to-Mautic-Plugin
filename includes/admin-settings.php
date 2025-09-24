<?php
/**
 * Admin settings for 7C Orders2WhatsApp
 * - Zender (WhatsApp)
 * - Mautic (base URL / token / optional OAuth2)
 * - Mautic logs (enable / retention days)
 * - Policies: which WooCommerce statuses to notify (customer & admins), default country code
 * - Backfill UI: batches + background (WP-Cron) for "latest order per customer" to Mautic
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'orders2whatsapp_admin_menu');
add_action('admin_init', 'orders2whatsapp_register_settings');

/** Adds main menu to WP Admin */
function orders2whatsapp_admin_menu() {
    add_menu_page(
        '7C Orders2WhatsApp - Settings', // Page title
        '7C Orders2WhatsApp',            // Menu title
        'manage_options',                // Capability
        'orders2whatsapp',               // Slug
        'orders2whatsapp_settings_page', // Render callback
        'dashicons-whatsapp',            // Icon
        56                               // Approx position
    );
}

/** Registers options with sanitization */
function orders2whatsapp_register_settings() {
    // === ZENDER ===
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_api_url', [
        'type' => 'string', 'sanitize_callback' => 'esc_url_raw'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_api_secret', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_device_id', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_admin_numbers', [
        'type' => 'string', 'sanitize_callback' => 'orders2whatsapp_sanitize_admin_numbers'
    ]);

    // === MAUTIC (basic) ===
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_url', [
        'type' => 'string', 'sanitize_callback' => 'esc_url_raw'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_token', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'
        // Accepted values:
        // - "oauth2"   → enables OAuth2 password grant using the fields below
        // - "key:XXXX" → uses header X-Api-Key: XXXX
        // - "<token>"  → uses header Authorization: Bearer <token> (PAT or other)
    ]);

    // === MAUTIC (OAuth2 Password Grant) ===
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_oauth_client_id', [
        'type'=>'string','sanitize_callback'=>'sanitize_text_field'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_oauth_client_secret', [
        'type'=>'string','sanitize_callback'=>'sanitize_text_field'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_oauth_username', [
        'type'=>'string','sanitize_callback'=>'sanitize_text_field'
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_oauth_password', [
        'type'=>'string','sanitize_callback'=>'orders2whatsapp_sanitize_password'
    ]);

    // === MAUTIC LOGS ===
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_log_enabled', [
        'type' => 'boolean', 'sanitize_callback' => 'orders2whatsapp_sanitize_bool', 'default' => 0
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_log_retention_days', [
        'type' => 'integer', 'sanitize_callback' => 'orders2whatsapp_sanitize_positive_int', 'default' => 14
    ]);

    // === POLICIES (apply to customer and admin WhatsApp notifications) ===
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_notify_statuses', [
        'type' => 'array',
        'sanitize_callback' => 'orders2whatsapp_sanitize_statuses',
        'default' => ['processing','completed','cancelled','refunded'],
    ]);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_default_country_code', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => '51',
    ]);

    // Temporary tokens (saved by the OAuth2 channel). No UI.
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_access_token', ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_refresh_token', ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
    register_setting('orders2whatsapp_settings_group', 'orders2whatsapp_mautic_access_expires', ['type'=>'integer','sanitize_callback'=>'orders2whatsapp_sanitize_positive_int']);
}

/** Sanitizes boolean (checkbox) */
function orders2whatsapp_sanitize_bool($val) {
    return (int) (bool) $val;
}

/** Sanitizes positive integers (0 allowed) */
function orders2whatsapp_sanitize_positive_int($val) {
    $val = is_numeric($val) ? (int) $val : 0;
    return max(0, $val);
}

/** Password: keep spaces intact on purpose, but strip tags */
function orders2whatsapp_sanitize_password($val) {
    $val = (string)$val;
    // Strip tags/JS; do not use esc_html here because we store the raw value.
    return wp_kses($val, []);
}

/** Sanitizes comma-separated admin phone numbers */
function orders2whatsapp_sanitize_admin_numbers($input) {
    if (!is_string($input)) return '';
    $parts = array_filter(array_map('trim', explode(',', $input)));
    return implode(', ', $parts);
}

/** Sanitizes array of WooCommerce status slugs */
function orders2whatsapp_sanitize_statuses($input) {
    if (!is_array($input)) return [];
    $clean = [];
    foreach ($input as $slug) {
        $slug = sanitize_key($slug);
        if (!empty($slug)) $clean[] = $slug;
    }
    return array_values(array_unique($clean));
}

/** Renders the settings page */
function orders2whatsapp_settings_page() {
    // WooCommerce statuses: 'wc-processing' => 'Processing', etc.
    $wc_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
    // Convert to slugs without the 'wc-' prefix
    $status_slugs = [];
    foreach ($wc_statuses as $key => $label) {
        $status_slugs[ substr($key, 3) ] = $label;
    }

    // Current values
    $api_url    = esc_attr(get_option('orders2whatsapp_api_url'));
    $api_secret = esc_attr(get_option('orders2whatsapp_api_secret'));
    $device_id  = esc_attr(get_option('orders2whatsapp_device_id'));
    $admin_nums = esc_attr(get_option('orders2whatsapp_admin_numbers'));

    $mautic_url   = esc_attr(get_option('orders2whatsapp_mautic_url'));
    $mautic_token = esc_attr(get_option('orders2whatsapp_mautic_token')); // 'oauth2' | 'key:...' | '<token>'

    $oauth_client_id     = esc_attr(get_option('orders2whatsapp_mautic_oauth_client_id'));
    $oauth_client_secret = esc_attr(get_option('orders2whatsapp_mautic_oauth_client_secret'));
    $oauth_username      = esc_attr(get_option('orders2whatsapp_mautic_oauth_username'));
    $oauth_password      = esc_attr(get_option('orders2whatsapp_mautic_oauth_password'));

    $log_enabled       = (int) get_option('orders2whatsapp_mautic_log_enabled', 0);
    $log_retentionDays = (int) get_option('orders2whatsapp_mautic_log_retention_days', 14);

    $notify_sel = (array) get_option('orders2whatsapp_notify_statuses', ['processing','completed','cancelled','refunded']);
    $default_cc = esc_attr(get_option('orders2whatsapp_default_country_code', '51'));

    // Secure URL (nonce) to trigger "Create custom fields" installer
    $create_fields_url = wp_nonce_url(
        admin_url('admin-post.php?action=orders2whatsapp_create_mautic_fields'),
        'o2w_create_mautic_fields'
    );

    // Backfill progress + action URLs (class is in includes/class-backfill-latest-per-customer.php)
    $prog = class_exists('Orders2WhatsApp_Backfill')
        ? Orders2WhatsApp_Backfill::get_progress()
        : ['total'=>0,'done'=>0,'remaining'=>0];

    $cron_running = class_exists('Orders2WhatsApp_Backfill')
        ? Orders2WhatsApp_Backfill::cron_is_running()
        : false;

    $backfill_run_one_url = wp_nonce_url(
        admin_url('admin-post.php?action=orders2whatsapp_backfill_run_one'),
        'o2w_backfill_run_one'
    );
    $backfill_run_batch_url = wp_nonce_url(
        admin_url('admin-post.php?action=orders2whatsapp_backfill_run_batch&n=25'),
        'o2w_backfill_run_batch'
    );
    $backfill_cron_start_url = wp_nonce_url(
        admin_url('admin-post.php?action=orders2whatsapp_backfill_cron_start&n=25'),
        'o2w_backfill_cron_start'
    );
    $backfill_cron_stop_url = wp_nonce_url(
        admin_url('admin-post.php?action=orders2whatsapp_backfill_cron_stop'),
        'o2w_backfill_cron_stop'
    );
    $backfill_reset_url = wp_nonce_url(
        admin_url('admin-post.php?action=orders2whatsapp_backfill_reset'),
        'o2w_backfill_reset'
    );
    ?>
    <div class="wrap">
        <h1>Settings — 7C Orders2WhatsApp</h1>

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
            </table>

            <h3 class="title">OAuth2 (Password Grant)</h3>
            <p>Use these fields only if you set <code>oauth2</code> in “Token / Authentication mode”. The App's <em>Redirect URI</em> in Mautic can be any valid URL (not used in this grant), e.g. <code>https://www.donitalo.com/oauth/dummy</code>.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_oauth_client_id">Client ID (Public Key)</label></th>
                    <td><input id="orders2whatsapp_mautic_oauth_client_id" type="text" name="orders2whatsapp_mautic_oauth_client_id" value="<?php echo $oauth_client_id; ?>" class="regular-text" style="width:100%"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_oauth_client_secret">Client Secret</label></th>
                    <td><input id="orders2whatsapp_mautic_oauth_client_secret" type="text" name="orders2whatsapp_mautic_oauth_client_secret" value="<?php echo $oauth_client_secret; ?>" class="regular-text" style="width:100%"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_oauth_username">Username</label></th>
                    <td><input id="orders2whatsapp_mautic_oauth_username" type="text" name="orders2whatsapp_mautic_oauth_username" value="<?php echo $oauth_username; ?>" class="regular-text" style="width:100%"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_oauth_password">Password</label></th>
                    <td><input id="orders2whatsapp_mautic_oauth_password" type="password" name="orders2whatsapp_mautic_oauth_password" value="<?php echo $oauth_password; ?>" class="regular-text" style="width:100%"></td>
                </tr>
            </table>

            <!-- === Nonce link to create/update Mautic custom fields (no nested forms) === -->
            <p style="margin: 6px 0 18px;">
                <a href="<?php echo esc_url($create_fields_url); ?>" class="button button-secondary">
                    Create custom fields
                </a>
                <span class="description" style="margin-left:8px;">
                    Creates/updates the custom fields in Mautic used by this plugin.
                </span>
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
                        <p class="description">Files are stored at <code>/wp-content/uploads/7c-wc-orders2whatsapp/</code> with prefix <code>mautic-</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="orders2whatsapp_mautic_log_retention_days">Log retention (days)</label></th>
                    <td>
                        <input id="orders2whatsapp_mautic_log_retention_days" type="number" min="0" step="1"
                               name="orders2whatsapp_mautic_log_retention_days" value="<?php echo $log_retentionDays; ?>" class="small-text" />
                        <p class="description">0 = no automatic deletion (not recommended). The channel will use this value to purge old logs.</p>
                    </td>
                </tr>
            </table>

            <hr>

            <!-- === Backfill (latest order per customer) === -->
            <h2 class="title">Backfill to Mautic (latest order per customer)</h2>
            <p>Push the most recent order per customer to Mautic. First action builds the queue if needed. Orders without email are ignored.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Progress</th>
                    <td>
                        <strong>Done:</strong> <?php echo (int) $prog['done']; ?> /
                        <strong>Total:</strong> <?php echo (int) $prog['total']; ?> —
                        <strong>Remaining:</strong> <?php echo (int) $prog['remaining']; ?>
                        <?php
                        $pct = ($prog['total'] > 0) ? min(100, round(($prog['done'] / $prog['total']) * 100)) : 0;
                        ?>
                        <div style="margin-top:6px; width:320px; background:#eee; height:10px; border-radius:6px; overflow:hidden;">
                            <div style="width:<?php echo (int)$pct; ?>%; height:10px; background:#46b450;"></div>
                        </div>
                        <p class="description" style="margin-top:6px;"><?php echo (int)$pct; ?>% complete</p>
                        <p class="description" style="margin-top:6px;">
                            Background: <strong><?php echo $cron_running ? 'Running' : 'Stopped'; ?></strong>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Actions</th>
                    <td>
                        <a href="<?php echo esc_url($backfill_run_one_url); ?>" class="button">Run 1</a>
                        <a href="<?php echo esc_url($backfill_run_batch_url); ?>" class="button">Run 25</a>
                        <?php if ($cron_running): ?>
                            <a href="<?php echo esc_url($backfill_cron_stop_url); ?>" class="button button-secondary">Stop background</a>
                        <?php else: ?>
                            <a href="<?php echo esc_url($backfill_cron_start_url); ?>" class="button button-primary">Start background</a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url($backfill_reset_url); ?>" class="button">Reset queue</a>
                    
                        <p class="description" style="margin-top:6px;">No WhatsApp is sent; only the Mautic channel runs.</p>
                        <p class="description" style="margin-top:6px;">
                            Tip: WP-Cron runs on page hits. If your host disables it, run a cron to trigger your background process</code> every minute:
                        </p>
                        <pre style="background:#f6f7f7; padding:8px; border:1px solid #ccd0d4; display:inline-block; overflow:auto;">* * * * * cd /path/to/wp && wp cron event run o2w_backfill_cron --quiet</pre>
                    </td>
                </tr>
            </table>

            <hr>

            <h2 class="title">Notification policies (WhatsApp)</h2>
            <p>Select which order statuses will trigger WhatsApp to the <strong>customer and admins</strong>. (TXT and Mautic are <em>always</em> sent.)</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Statuses to notify</th>
                    <td>
                        <?php if (!empty($status_slugs)) : ?>
                            <?php foreach ($status_slugs as $slug => $label) : ?>
                                <?php
                                $checked = in_array($slug, $notify_sel, true) ? 'checked' : '';
                                $id = 'orders2whatsapp_status_' . esc_attr($slug);
                                ?>
                                <label for="<?php echo $id; ?>" style="display:inline-block; margin:0 16px 8px 0;">
                                    <input id="<?php echo $id; ?>" type="checkbox" name="orders2whatsapp_notify_statuses[]"
                                           value="<?php echo esc_attr($slug); ?>" <?php echo $checked; ?> />
                                    <?php echo esc_html($label); ?> <code>(<?php echo esc_html($slug); ?>)</code>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">If you also want to notify when the order is created, you can add the pseudo-status <code>created</code> (your router supports it).</p>
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
                        <p class="description">Prepended to phone numbers without country code. Default is <code>51</code> (Peru).</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Global filter so channels read notification statuses from Settings.
 * If there is no saved configuration, fall back to the defaults defined in code.
 */
add_filter('orders2whatsapp_customer_notify_statuses', function ($defaults) {
    $configured = get_option('orders2whatsapp_notify_statuses');
    if (is_array($configured) && !empty($configured)) {
        return $configured;
    }
    return $defaults;
});

/**
 * Admin notice to display the result of the custom fields installer (via admin-post).
 * The handler should set the transient 'o2w_fields_installer_msg'.
 */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (!isset($_GET['page']) || $_GET['page'] !== 'orders2whatsapp') return;

    $msg = get_transient('o2w_fields_installer_msg');
    if ($msg) {
        delete_transient('o2w_fields_installer_msg');
        echo '<div class="notice notice-info is-dismissible"><p><strong>7C Orders2WhatsApp:</strong> ' . esc_html($msg) . '</p></div>';
    }
});

/**
 * Admin notice to display Backfill results (via admin-post).
 * The backfill handlers set the transient 'o2w_backfill_msg'.
 */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (!isset($_GET['page']) || $_GET['page'] !== 'orders2whatsapp') return;

    $msg = get_transient('o2w_backfill_msg');
    if ($msg) {
        delete_transient('o2w_backfill_msg');
        echo '<div class="notice notice-info is-dismissible"><p><strong>7C Orders2WhatsApp (Backfill):</strong> ' . esc_html($msg) . '</p></div>';
    }
});
