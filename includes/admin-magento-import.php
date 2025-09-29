<?php
// includes/admin-magento-import.php
if (!defined('ABSPATH')) exit;

/** === Shared constants with admin-magento-settings === */
if (!defined('O2W_OPT_MAGENTO_URL'))  define('O2W_OPT_MAGENTO_URL',  'o2w_magento_url');
if (!defined('O2W_OPT_MAGENTO_USER')) define('O2W_OPT_MAGENTO_USER', 'o2w_magento_user');
if (!defined('O2W_OPT_MAGENTO_PASS')) define('O2W_OPT_MAGENTO_PASS', 'o2w_magento_pass');

define('O2W_TR_MAGENTO_TOTAL_ORDERS', 'o2w_magento_total_orders'); // transient 15 min
define('O2W_TR_MAGENTO_TOKEN',        'o2w_magento_token');        // token cache (~50 min)

// Pagination cursor (Magento)
define('O2W_OPT_MAGENTO_CURSOR',      'o2w_magento_cursor');       // ['page'=>int,'psize'=>int]

/** Capability helper (keeps handlers/UI consistent with menus) */
if (!function_exists('o2w_required_cap')) {
    function o2w_required_cap() {
        return defined('S2M_REQUIRED_CAP') ? S2M_REQUIRED_CAP : 'manage_options';
    }
}

/** === Minimal helpers for Magento API === */

/** Bearer token (~50 min cache). */
if (!function_exists('o2w_magento_get_bearer_token')) {
    function o2w_magento_get_bearer_token($force_refresh = false) {
        if (!$force_refresh) {
            $tok = get_transient(O2W_TR_MAGENTO_TOKEN);
            if ($tok) return $tok;
        }

        $base = rtrim((string) get_option(O2W_OPT_MAGENTO_URL, ''), '/');
        $user = (string) get_option(O2W_OPT_MAGENTO_USER, '');
        $pass = (string) get_option(O2W_OPT_MAGENTO_PASS, '');

        if ($base === '' || $user === '' || $pass === '') {
            return new WP_Error('o2w_magento_creds', 'Missing Magento URL / user / password.');
        }

        $endpoint = $base . '/V1/integration/admin/token';
        $resp = wp_remote_post($endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['username' => $user, 'password' => $pass]),
            'timeout' => 20,
        ]);
        if (is_wp_error($resp)) return $resp;

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('o2w_magento_token_http', 'HTTP ' . $code . ': ' . substr($body, 0, 200));
        }

        // Magento returns a JSON string token: "xxxx"
        $token = is_string($body) ? trim($body, " \t\n\r\0\x0B\"") : '';
        if ($token === '') return new WP_Error('o2w_magento_token_empty', 'Magento did not return a token.');

        set_transient(O2W_TR_MAGENTO_TOKEN, $token, 50 * MINUTE_IN_SECONDS);
        return $token;
    }
}

/** Call /V1/orders just to get total_count. Cached for 15 min. */
function o2w_magento_fetch_total_orders($force_refresh = false) {
    if (!$force_refresh) {
        $cached = get_transient(O2W_TR_MAGENTO_TOTAL_ORDERS);
        if (is_array($cached) && isset($cached['count'], $cached['ts'])) {
            return $cached; // ['count'=>int,'ts'=>timestamp]
        }
    }

    $base = rtrim((string) get_option(O2W_OPT_MAGENTO_URL, ''), '/');
    if ($base === '') return new WP_Error('o2w_magento_base', 'Magento REST URL is missing.');

    $token = o2w_magento_get_bearer_token();
    if (is_wp_error($token)) return $token;

    $url = add_query_arg([
        'searchCriteria[currentPage]' => 1,
        'searchCriteria[pageSize]'    => 1, // enough to read total_count
    ], $base . '/V1/orders');

    $resp = wp_remote_get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 20,
    ]);
    if (is_wp_error($resp)) return $resp;

    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300 || !is_array($body)) {
        return new WP_Error('o2w_magento_total_http', 'Could not read total_count. HTTP ' . $code);
    }

    $total = isset($body['total_count']) ? (int)$body['total_count'] : 0;
    $payload = ['count' => $total, 'ts' => time()];
    set_transient(O2W_TR_MAGENTO_TOTAL_ORDERS, $payload, 15 * MINUTE_IN_SECONDS);
    return $payload;
}

/** Cursor helpers */
function o2w_magento_get_cursor() {
    $c = get_option(O2W_OPT_MAGENTO_CURSOR, []);
    $page  = isset($c['page'])  ? max(1, (int)$c['page'])  : 1;
    $psize = isset($c['psize']) ? max(50,(int)$c['psize']) : 200;
    return ['page'=>$page,'psize'=>$psize];
}
function o2w_magento_set_cursor($page, $psize = 200) {
    update_option(O2W_OPT_MAGENTO_CURSOR, ['page'=>(int)$page,'psize'=>(int)$psize], false);
}

/** Local metrics from wp_*_o2w_magento_orders (queue_state). */
function o2w_magento_local_metrics() {
    global $wpdb;
    $t = $wpdb->prefix . 'o2w_magento_orders';

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=%s AND table_name=%s",
        DB_NAME, $t
    ));
    if (!(int)$exists) {
        return ['total'=>0, 'pending'=>0, 'done'=>0, 'error'=>0];
    }

    $total   = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
    $pending = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE queue_state=%s", 'pending'));
    $done    = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE queue_state=%s", 'done'));
    $error   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE queue_state=%s", 'error'));

    return compact('total','pending','done','error');
}

/** === Handler: Refresh summary === */
add_action('admin_post_o2w_magento_refresh_summary', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_refresh_summary');

    $force  = !empty($_GET['force']) || !empty($_POST['force']);
    $result = o2w_magento_fetch_total_orders($force);

    if (is_wp_error($result)) {
        $msg = 'Could not refresh summary: ' . $result->get_error_message();
    } else {
        $msg = sprintf('Summary updated. Total in Magento: %d.', (int)$result['count']);
    }

    wp_safe_redirect(add_query_arg(['o2w_notice' => rawurlencode($msg)], admin_url('admin.php?page=s2m_magento')));
    exit;
});

/** === Handler: Seed IDs (INSERT IGNORE + cursor) === */
add_action('admin_post_o2w_magento_seed_ids', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_seed_ids');

    $n = isset($_GET['n']) ? (int)$_GET['n'] : 200; // how many IDs to try to fetch in total
    if ($n <= 0) $n = 200;

    $cursor = o2w_magento_get_cursor();
    $page   = $cursor['page'];
    $psize  = $cursor['psize'];

    $base = rtrim((string) get_option(O2W_OPT_MAGENTO_URL, ''), '/');
    if ($base === '') {
        wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode('Magento REST URL is missing.')], admin_url('admin.php?page=s2m_magento')));
        exit;
    }

    $token = o2w_magento_get_bearer_token();
    if (is_wp_error($token)) {
        wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode('Token error: '.$token->get_error_message())], admin_url('admin.php?page=s2m_magento')));
        exit;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'o2w_magento_orders';

    $pages_to_fetch = (int) ceil($n / $psize);
    $seen = 0; $inserted = 0; $err = '';

    for ($i = 0; $i < $pages_to_fetch; $i++) {
        $url = add_query_arg([
            'searchCriteria[currentPage]' => $page,
            'searchCriteria[pageSize]'    => $psize,
            'fields' => 'items[entity_id],total_count'
        ], $base . '/V1/orders');

        $resp = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 25,
        ]);
        if (is_wp_error($resp)) { $err = $resp->get_error_message(); break; }

        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) { $err = 'HTTP '.$code; break; }

        $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : [];
        if (!$items) { // end of pages
            $page = 1; // reset to 1 for a new cycle
            break;
        }

        // Mass INSERT IGNORE into (entity_id, queue_state, last_seen_at)
        $placeholders = [];
        $params = [];
        $now = current_time('mysql'); // when we saw the ID (not Magento's created_at)

        foreach ($items as $it) {
            if (!isset($it['entity_id'])) continue;
            $placeholders[] = '(%d,%s,%s)';
            $params[] = (int)$it['entity_id'];
            $params[] = 'pending';
            $params[] = $now;
        }

        if ($placeholders) {
            $sql = "INSERT IGNORE INTO $table (entity_id, queue_state, last_seen_at) VALUES " . implode(',', $placeholders);
            $prepared = $wpdb->prepare($sql, $params);
            $wpdb->query($prepared);
            $inserted += (int)$wpdb->rows_affected;
        }

        $seen += count($items);
        $page++;
    }

    // Save updated cursor
    o2w_magento_set_cursor($page, $psize);

    $msg = $err
        ? ("Error while seeding IDs: " . $err)
        : sprintf('Seed: seen %d / inserted %d. Cursor now at page %d (pageSize=%d).',
                  $seen, $inserted, $page, $psize);

    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode($msg)], admin_url('admin.php?page=s2m_magento')));
    exit;
});

/** === Handler: Reset queue === */
add_action('admin_post_o2w_magento_reset_queue', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_reset_queue');

    global $wpdb;
    $table = $wpdb->prefix . 'o2w_magento_orders';
    $wpdb->query("TRUNCATE TABLE $table");
    o2w_magento_set_cursor(1, 200);
    delete_transient(O2W_TR_MAGENTO_TOTAL_ORDERS);

    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode('Queue reset. Cursor = page 1 (pageSize=200).')], admin_url('admin.php?page=s2m_magento')));
    exit;
});

/** === Render: Import block (IDs, Step 1) === */
function o2w_render_magento_import_section() {
    if (!current_user_can(o2w_required_cap())) return;

    // Remote summary (if cached)
    $remote = get_transient(O2W_TR_MAGENTO_TOTAL_ORDERS);
    $remote_count = is_array($remote) ? (int)$remote['count'] : null;
    $remote_ts    = is_array($remote) ? (int)$remote['ts'] : null;

    // Local metrics
    $m = o2w_magento_local_metrics();

    // Progress if we know remote total
    $pct = (is_int($remote_count) && $remote_count > 0)
        ? min(100, round(($m['total'] / $remote_count) * 100))
        : null;

    // Cursor
    $cur = o2w_magento_get_cursor();

    // Action URLs
    $refresh_url  = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_refresh_summary'),
        'o2w_magento_refresh_summary'
    );
    $seed1000_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_seed_ids&n=1000'),
        'o2w_magento_seed_ids'
    );
    $seed200_url  = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_seed_ids&n=200'),
        'o2w_magento_seed_ids'
    );
    $reset_url    = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_reset_queue'),
        'o2w_magento_reset_queue'
    );

    echo '<hr>';
    ?>
    <h2 class="title">Import from Magento — Order IDs (Step 1)</h2>
    <p class="description" style="max-width:800px;">
        This step only “seeds” every Magento <strong>Order ID</strong> into the local table so they can be processed in later steps.
        You can run it multiple times; the cursor advances and duplicates are avoided with <code>INSERT IGNORE</code>.
    </p>

    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row">Summary</th>
                <td>
                    <?php if (is_int($remote_count)) : ?>
                        <div><strong>Total in Magento:</strong> <?php echo esc_html(number_format_i18n($remote_count)); ?>
                            <span class="description">
                                (cached <?php
                                    if ($remote_ts) {
                                        echo esc_html(human_time_diff($remote_ts, time())) . ' ago';
                                    } else {
                                        echo 'recently';
                                    }
                                ?>)
                            </span>
                        </div>
                    <?php else : ?>
                        <div class="description">Magento order total has not been fetched yet.</div>
                    <?php endif; ?>

                    <div><strong>Total in local table:</strong> <?php echo (int)$m['total']; ?> —
                        <strong>pending:</strong> <?php echo (int)$m['pending']; ?> /
                        <strong>done:</strong> <?php echo (int)$m['done']; ?> /
                        <strong>error:</strong> <?php echo (int)$m['error']; ?>
                    </div>

                    <div class="description" style="margin-top:6px;">
                        Current cursor: <strong>page <?php echo (int)$cur['page']; ?></strong> (Magento, pageSize=<?php echo (int)$cur['psize']; ?>)
                    </div>

                    <?php if ($pct !== null) : ?>
                        <div style="margin-top:6px; width:320px; background:#eee; height:10px; border-radius:6px; overflow:hidden;">
                            <div style="width:<?php echo (int)$pct; ?>%; height:10px; background:#2271b1;"></div>
                        </div>
                        <p class="description" style="margin-top:6px;"><?php echo (int)$pct; ?>% seeded</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">Actions</th>
                <td>
                    <a href="<?php echo esc_url($refresh_url); ?>" class="button">Refresh summary</a>
                    <a href="<?php echo esc_url($seed1000_url); ?>" class="button button-primary">Seed IDs (1000)</a>
                    <a href="<?php echo esc_url($seed200_url); ?>" class="button">Seed IDs (200)</a>
                    <a href="<?php echo esc_url($reset_url); ?>" class="button button-secondary">Reset queue</a>
                    <p class="description" style="margin-top:6px;">
                        Uses pages of <?php echo (int)$cur['psize']; ?> per request; the cursor moves forward and wraps to page 1 when finished.
                    </p>
                </td>
            </tr>
        </tbody>
    </table>
    <?php
}
