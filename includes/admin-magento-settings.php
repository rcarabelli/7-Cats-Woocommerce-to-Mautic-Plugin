<?php
// includes/admin-magento-settings.php
if (!defined('ABSPATH')) exit;

define('O2W_OPT_MAGENTO_URL',  'o2w_magento_url');
define('O2W_OPT_MAGENTO_USER', 'o2w_magento_user');
define('O2W_OPT_MAGENTO_PASS', 'o2w_magento_pass');

/**
 * Return the capability required to access admin actions/pages.
 * Uses S2M_REQUIRED_CAP if defined in the main plugin; otherwise defaults to 'manage_options'.
 */
function o2w_required_cap() {
    return defined('S2M_REQUIRED_CAP') ? S2M_REQUIRED_CAP : 'manage_options';
}

/**
 * Update option with autoload=no if it does not exist yet, otherwise update normally.
 */
function o2w_update_option_no_autoload($key, $value) {
    if (get_option($key, null) === null) {
        add_option($key, $value, '', 'no'); // autoload = no
    } else {
        update_option($key, $value, false);
    }
}

/**
 * Normalize URL: store only the REST base (…/rest) without /V1.
 */
function o2w_magento_normalize_url($raw) {
    $u = trim((string)$raw);
    if ($u === '') return '';

    // Remove whitespace/backslashes
    $u = preg_replace('#\s+#', '', $u);
    $u = str_replace('\\', '/', $u);

    // Remove /V1 (with or without trailing slash)
    $u = preg_replace('#/V1/?$#i', '', rtrim($u, '/'));

    // Ensure it ends with /rest
    if (!preg_match('#/rest$#i', $u)) {
        $u .= '/rest';
    }
    // Clean double slashes
    $u = preg_replace('#(?<!:)//+#', '/', $u);
    return $u;
}

/** URL of the main plugin page. */
function o2w_admin_page_url() {
    return admin_url('admin.php?page=orders2whatsapp');
}

/**
 * Save handler: processes only the 3 Magento fields.
 */
function o2w_handle_save_magento() {
    if (!is_admin()) return;
    if (empty($_POST['o2w_magento_save'])) return;
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_save_nonce');

    // URL
    $url = isset($_POST[O2W_OPT_MAGENTO_URL]) ? sanitize_text_field($_POST[O2W_OPT_MAGENTO_URL]) : '';
    $url = o2w_magento_normalize_url($url);

    // Username
    $user = isset($_POST[O2W_OPT_MAGENTO_USER]) ? sanitize_text_field($_POST[O2W_OPT_MAGENTO_USER]) : '';

    // Password (update only if a new one was provided)
    $pass_in = isset($_POST[O2W_OPT_MAGENTO_PASS]) ? (string)$_POST[O2W_OPT_MAGENTO_PASS] : '';
    $update_pass = $pass_in !== '';

    // Save options
    o2w_update_option_no_autoload(O2W_OPT_MAGENTO_URL, $url);
    o2w_update_option_no_autoload(O2W_OPT_MAGENTO_USER, $user);
    if ($update_pass) {
        o2w_update_option_no_autoload(O2W_OPT_MAGENTO_PASS, $pass_in);
    }

    // Visual feedback
    $args = ['o2w_notice' => urlencode('Magento settings saved.')];
    if (!$update_pass && get_option(O2W_OPT_MAGENTO_PASS, '') !== '') {
        $args['o2w_hint'] = urlencode('Existing password kept (field left empty).');
    }

    $ref    = wp_get_referer();
    $target = ($ref && strpos($ref, 'page=orders2whatsapp') !== false) ? $ref : o2w_admin_page_url();

    wp_safe_redirect(add_query_arg($args, $target));
    exit;
}
add_action('admin_init', 'o2w_handle_save_magento');

/**
 * Test connection to Magento by requesting an admin token.
 * Does not save anything, only reports back.
 */
// Replace existing handler with this improved version
add_action('admin_post_o2w_magento_test_token', 'o2w_magento_test_token');
function o2w_magento_test_token() {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_test_token');

    $base = o2w_magento_normalize_url(get_option(O2W_OPT_MAGENTO_URL, ''));
    $user = (string) get_option(O2W_OPT_MAGENTO_USER, '');
    $pass = (string) get_option(O2W_OPT_MAGENTO_PASS, '');

    $args = [];

    // Helper: short, safe snippet (no HTML noise)
    $short = function($text, $len = 240) {
        $text = wp_strip_all_tags((string)$text);
        $text = preg_replace('/\s+/', ' ', $text);
        return mb_substr($text, 0, $len);
    };

    // Helper: readable label per status
    $label = function($code) {
        $map = [
            200=>'OK', 301=>'Moved Permanently', 302=>'Found',
            400=>'Bad Request', 401=>'Unauthorized', 403=>'Forbidden',
            404=>'Not Found', 408=>'Request Timeout', 429=>'Too Many Requests',
            500=>'Internal Server Error', 502=>'Bad Gateway',
            503=>'Service Unavailable', 504=>'Gateway Timeout'
        ];
        return isset($map[$code]) ? $map[$code] : 'HTTP '.$code;
    };

    if (!$base || !$user || !$pass) {
        $args['o2w_error'] = urlencode('Missing data: URL, user or password.');
        wp_safe_redirect(add_query_arg($args, o2w_admin_page_url()));
        exit;
    }

    // Token endpoint built from REST base
    $endpoint = rtrim($base, '/') . '/V1/integration/admin/token';

    // Make the request (longer timeout, no auto redirect, explicit UA)
    $resp = wp_remote_post($endpoint, [
        'timeout'     => 20,
        'redirection' => 0,
        'headers'     => [
            'Content-Type' => 'application/json',
            'User-Agent'   => '7c-shop2mautic/1.0 (+WP HTTP API)',
        ],
        'body'        => wp_json_encode(['username' => $user, 'password' => $pass]),
    ]);

    if (is_wp_error($resp)) {
        // Common WP transport errors with hints
        $emsg = $resp->get_error_message();
        $hint = '';
        if (stripos($emsg, 'Could not resolve host') !== false) {
            $hint = 'Hint: DNS/host not resolving. Check the domain.';
        } elseif (stripos($emsg, 'timed out') !== false) {
            $hint = 'Hint: Server timed out. The store might be slow or down.';
        } elseif (stripos($emsg, 'SSL') !== false) {
            $hint = 'Hint: SSL issue. Check the certificate and HTTPS configuration.';
        }
        $args['o2w_error'] = urlencode('Connection error: ' . $emsg . ($hint ? ' — ' . $hint : ''));
        wp_safe_redirect(add_query_arg($args, o2w_admin_page_url()));
        exit;
    }

    $code     = (int) wp_remote_retrieve_response_code($resp);
    $headers  = (array) wp_remote_retrieve_headers($resp);
    $body_raw = wp_remote_retrieve_body($resp);
    $body     = $short($body_raw, 400);

    if ($code === 200) {
        $token = json_decode($body_raw, true);
        if (is_string($token) && $token !== '') {
            $mask = substr($token, 0, 6) . '…' . substr($token, -4);
            $args['o2w_notice'] = urlencode('Connection OK. Token received (' . strlen($token) . ' chars): ' . $mask);
        } else {
            $args['o2w_error'] = urlencode('HTTP 200 but unexpected response: ' . $body);
        }
        wp_safe_redirect(add_query_arg($args, o2w_admin_page_url()));
        exit;
    }

    // Handle redirects explicitly (often misconfigured base URL)
    if ($code === 301 || $code === 302) {
        $loc = '';
        if (!empty($headers['location'])) {
            $loc = is_array($headers['location']) ? reset($headers['location']) : $headers['location'];
        }
        $args['o2w_error'] = urlencode(
            $label($code) . ': the endpoint redirected' .
            ($loc ? ' to ' . $loc : '') .
            '. Hint: REST base URL should be the /rest root (no /V1).'
        );
        wp_safe_redirect(add_query_arg($args, o2w_admin_page_url()));
        exit;
    }

    // Tailored hints for common failures
    $hint = '';
    switch ($code) {
        case 401: $hint = 'Hint: Wrong username or password, or user lacks API permission.'; break;
        case 403: $hint = 'Hint: IP restrictions, ACLs, or Magento permissions blocking the request.'; break;
        case 404: $hint = 'Hint: Check the REST base URL. It must be like https://yourstore.tld/rest'; break;
        case 429: $hint = 'Hint: Rate limited. Try again later or lower request frequency.'; break;
        case 500: $hint = 'Hint: Magento error. Check server logs (exception.log/system.log).'; break;
        case 502: $hint = 'Hint: Bad gateway (upstream). Hosting/proxy issue.'; break;
        case 503: $hint = 'Hint: Service unavailable. The store may be down or in maintenance mode.'; break;
        case 504: $hint = 'Hint: Gateway timeout. Server too slow or blocked by firewall.'; break;
        default:  $hint = '';
    }

    $args['o2w_error'] = urlencode($label($code) . ' — ' . $body . ($hint ? ' — ' . $hint : ''));
    wp_safe_redirect(add_query_arg($args, o2w_admin_page_url()));
    exit;
}

/**
 * Show notices after saving/testing.
 */
function o2w_admin_magento_notices() {
    if (!is_admin() || !current_user_can(o2w_required_cap())) return;
    if (!isset($_GET['page']) || $_GET['page'] !== 'orders2whatsapp') return;

    if (!empty($_GET['o2w_notice'])) {
        echo '<div class="notice notice-success is-dismissible"><p><strong>Magento:</strong> ' . esc_html($_GET['o2w_notice']) . '</p></div>';
    }
    if (!empty($_GET['o2w_hint'])) {
        echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($_GET['o2w_hint']) . '</p></div>';
    }
    if (!empty($_GET['o2w_error'])) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($_GET['o2w_error']) . '</p></div>';
    }
}
add_action('admin_notices', 'o2w_admin_magento_notices');

/**
 * Render the “Magento (pull)” section.
 * This is Block 1: connection settings.
 *
 * Context:
 * Configure the base connection to your Magento store:
 * - The REST base URL (must point to /rest, not /V1).
 * - A Magento user with read-only permissions (Orders; optionally Customers/Products).
 * - Its password (leave blank to keep the stored one).
 *
 * After saving, click “Test connection” to verify Magento accepts the credentials
 * and returns a valid token. This step is required before importing any data.
 */
function o2w_render_magento_settings_section() {
    if (!current_user_can(o2w_required_cap())) return;

    $url  = (string) get_option(O2W_OPT_MAGENTO_URL, '');
    $user = (string) get_option(O2W_OPT_MAGENTO_USER, '');
    ?>
    <hr>
    <h2 class="title">Magento — Connection (Step 0)</h2>
    <p class="description" style="max-width:800px;">
        Configure the connection to your Magento store. These settings are used by all subsequent import steps.
    </p>

    <form method="post" action="">
        <?php wp_nonce_field('o2w_magento_save_nonce'); ?>
        <?php wp_referer_field(); ?>
        <input type="hidden" name="o2w_magento_save" value="1" />

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="o2w_magento_url">Magento REST URL</label></th>
                    <td>
                        <input name="<?php echo esc_attr(O2W_OPT_MAGENTO_URL); ?>" id="o2w_magento_url" type="text" class="regular-text" value="<?php echo esc_attr($url); ?>" placeholder="https://your-store.com/rest" />
                        <p class="description">Use the <code>/rest</code> base (without <code>/V1</code>). Example: <code>https://your-store.com/rest</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="o2w_magento_user">Magento User</label></th>
                    <td>
                        <input name="<?php echo esc_attr(O2W_OPT_MAGENTO_USER); ?>" id="o2w_magento_user" type="text" class="regular-text" value="<?php echo esc_attr($user); ?>" placeholder="integration.user" />
                        <p class="description">A user with minimum read permissions (Orders; optional: Customers/Products).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="o2w_magento_pass">Password</label></th>
                    <td>
                        <input name="<?php echo esc_attr(O2W_OPT_MAGENTO_PASS); ?>" id="o2w_magento_pass" type="password" class="regular-text" value="" placeholder="••••••••" autocomplete="new-password" />
                        <p class="description">Leave blank to keep the stored password.</p>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button('Save Magento'); ?>
    </form>

    <?php
    $test_token_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_test_token'),
        'o2w_magento_test_token'
    );
    ?>
    <p style="margin-top:6px;">
        <a href="<?php echo esc_url($test_token_url); ?>" class="button">Test connection (token)</a>
        <span class="description" style="margin-left:8px;">Uses the saved values and checks if Magento returns a valid token.</span>
    </p>
    <?php
}
