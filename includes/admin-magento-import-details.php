<?php
// includes/admin-magento-import-details.php
if (!defined('ABSPATH')) exit;

/** Reuse constants/functions if they already exist elsewhere */
if (!defined('O2W_OPT_MAGENTO_URL'))  define('O2W_OPT_MAGENTO_URL',  'o2w_magento_url');
if (!defined('O2W_OPT_MAGENTO_USER')) define('O2W_OPT_MAGENTO_USER', 'o2w_magento_user');
if (!defined('O2W_OPT_MAGENTO_PASS')) define('O2W_OPT_MAGENTO_PASS', 'o2w_magento_pass');
if (!defined('O2W_TR_MAGENTO_TOKEN'))  define('O2W_TR_MAGENTO_TOKEN', 'o2w_magento_token');

/** Capability helper (keeps menu + handlers in sync) */
if (!function_exists('o2w_required_cap')) {
    function o2w_required_cap() {
        return defined('S2M_REQUIRED_CAP') ? S2M_REQUIRED_CAP : 'manage_options';
    }
}

/**
 * Bearer token (cached ~50 min). If already defined in another include, it won't be redefined.
 */
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
            return new WP_Error('o2w_magento_token_http', 'HTTP ' . $code . ': ' . $body);
        }
        $token = is_string($body) ? trim($body, " \t\n\r\0\x0B\"") : '';
        if ($token === '') return new WP_Error('o2w_magento_token_empty', 'Magento did not return a token.');
        set_transient(O2W_TR_MAGENTO_TOKEN, $token, 50 * MINUTE_IN_SECONDS);
        return $token;
    }
}

/** Optional helper in case this file loads before Phase 1 */
if (!function_exists('o2w_magento_local_metrics')) {
    function o2w_magento_local_metrics() {
        global $wpdb;
        $t = $wpdb->prefix . 'o2w_magento_orders';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=%s AND table_name=%s",
            DB_NAME, $t
        ));
        if (!(int)$exists) return ['total'=>0, 'pending'=>0, 'fetching'=>0, 'done'=>0, 'error'=>0];
        $total    = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
        $pending  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE queue_state=%s", 'pending'));
        $fetching = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE queue_state=%s", 'fetching'));
        $done     = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE queue_state=%s", 'done'));
        $error    = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE queue_state=%s", 'error'));
        return compact('total','pending','fetching','done','error');
    }
}

/** === Phase 2 helpers: order details === */

/** Single-call fallback */
function o2w_magento_get_order_detail($entity_id, $base, $token) {
    $url = rtrim($base, '/') . '/V1/orders/' . urlencode((string)$entity_id);
    $resp = wp_remote_get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 25,
    ]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300 || !is_array($body)) {
        return new WP_Error('o2w_magento_order_http', 'HTTP ' . $code . ' (entity_id ' . $entity_id . ')');
    }
    return $body;
}

/** Bulk call: /V1/orders?entity_id IN (...) with fields trimmed down */
function o2w_magento_get_orders_bulk(array $entity_ids, $base, $token) {
    $entity_ids = array_values(array_unique(array_map('intval', $entity_ids)));
    if (!$entity_ids) return [];

    $ids_csv = implode(',', $entity_ids);

    // Only the fields required to populate wp_*_o2w_magento_orders
    $fields = 'items['
        .'entity_id,increment_id,store_id,customer_id,customer_email,'
        .'status,grand_total,order_currency_code,base_currency_code,global_currency_code,'
        .'created_at,updated_at,'
        // billing email as fallback (we do not fetch more personal data)
        .'billing_address[email]'
        .']';

    $url = add_query_arg([
        'searchCriteria[filter_groups][0][filters][0][field]'          => 'entity_id',
        'searchCriteria[filter_groups][0][filters][0][condition_type]' => 'in',
        'searchCriteria[filter_groups][0][filters][0][value]'          => $ids_csv,
        'searchCriteria[currentPage]'                                  => 1,
        'searchCriteria[pageSize]'                                     => count($entity_ids),
        'fields'                                                       => $fields,
    ], rtrim($base, '/') . '/V1/orders');

    $resp = wp_remote_get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 25,
    ]);
    if (is_wp_error($resp)) return $resp;

    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300 || !is_array($body)) {
        return new WP_Error('o2w_magento_bulk_http', 'HTTP ' . $code);
    }

    $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : [];
    $by_id = [];
    foreach ($items as $it) {
        if (isset($it['entity_id'])) {
            $by_id[(int)$it['entity_id']] = $it;
        }
    }
    return $by_id;
}

/** Extract ONLY columns that exist in wp_*_o2w_magento_orders */
function o2w_magento_extract_fields_from_order($order) {
    // Email (customer_email with fallback to billing.email)
    $email = '';
    if (!empty($order['customer_email'])) {
        $email = (string)$order['customer_email'];
    } elseif (!empty($order['billing_address']['email'])) {
        $email = (string)$order['billing_address']['email'];
    }

    // Currency preference (order > base > global)
    $currency = (string)($order['order_currency_code']
        ?? $order['base_currency_code']
        ?? ($order['global_currency_code'] ?? ''));

    // Total
    $grand_total = null;
    if (isset($order['grand_total'])) {
        $grand_total = (float)$order['grand_total'];
    }

    return [
        'increment_id'   => (string)($order['increment_id'] ?? ''),
        'status'         => (string)($order['status'] ?? ''),
        'currency_code'  => $currency ?: null,
        'grand_total'    => $grand_total,
        'store_id'       => isset($order['store_id']) ? (int)$order['store_id'] : null,
        'customer_id'    => isset($order['customer_id']) ? (int)$order['customer_id'] : null,
        'customer_email' => $email ?: null,
        'created_at'     => isset($order['created_at']) ? (string)$order['created_at'] : null,
        'updated_at'     => isset($order['updated_at']) ? (string)$order['updated_at'] : null,
    ];
}

/** === Handler: Phase 2 manual — fetch details in batches === */
add_action('admin_post_o2w_magento_fetch_details', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_fetch_details');

    $n = isset($_GET['n']) ? max(1, (int)$_GET['n']) : 100;
    $retryErrors = !empty($_GET['retry']); // if true, take 'error' instead of 'pending'

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

    // 1) Select entities to process
    $state = $retryErrors ? 'error' : 'pending';
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT entity_id FROM $table WHERE queue_state=%s ORDER BY id ASC LIMIT %d",
        $state, $n
    ));
    if (empty($ids)) {
        $msg = $retryErrors ? 'No orders in error state to retry.' : 'No pending orders.';
        wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode($msg)], admin_url('admin.php?page=s2m_magento')));
        exit;
    }

    // 2) Mark as fetching
    $ph = implode(',', array_fill(0, count($ids), '%d'));
    $sqlFetching = $wpdb->prepare("UPDATE $table SET queue_state='fetching' WHERE entity_id IN ($ph)", $ids);
    $wpdb->query($sqlFetching);

    $ok = 0; $err = 0; $now = current_time('mysql');

    // 3) Process in chunks to reduce requests
    $chunkSize   = 20;       // ≈ 1 request per 20 orders
    $sleepMicros = 150000;   // 150 ms between requests to avoid overload

    foreach (array_chunk($ids, $chunkSize) as $chunk) {
        $batch = o2w_magento_get_orders_bulk($chunk, $base, $token);

        if (is_wp_error($batch)) {
            // If the bulk call fails, mark this chunk as error
            foreach ($chunk as $entity_id) {
                $wpdb->update(
                    $table,
                    ['queue_state'=>'error', 'last_error'=>$batch->get_error_message(), 'last_seen_at'=>$now],
                    ['entity_id'=>$entity_id]
                );
                $err++;
            }
            usleep($sleepMicros);
            continue;
        }

        // Update those returned
        $returned_ids = [];
        foreach ($batch as $entity_id => $order) {
            $returned_ids[] = (int)$entity_id;

            $fields = o2w_magento_extract_fields_from_order($order);
            $data = array_merge($fields, [
                'queue_state'  => 'done',
                'last_error'   => null,
                'last_seen_at' => $now,
            ]);

            // Whitelist of valid columns (avoid issues if keys change)
            $allowed = [
                'increment_id','status','currency_code','grand_total',
                'store_id','customer_id','customer_email','created_at','updated_at',
                'queue_state','last_error','last_seen_at'
            ];
            $data = array_intersect_key($data, array_flip($allowed));

            $wpdb->update($table, $data, ['entity_id'=>$entity_id]);
            if ($wpdb->last_error) {
                $wpdb->update(
                    $table,
                    ['queue_state'=>'error', 'last_error'=>$wpdb->last_error, 'last_seen_at'=>$now],
                    ['entity_id'=>$entity_id]
                );
                $err++;
            } else {
                $ok++;
            }
        }

        // Mark as error those not returned by the API
        $missing = array_diff($chunk, $returned_ids);
        if (!empty($missing)) {
            $ph2 = implode(',', array_fill(0, count($missing), '%d'));
            $sqlErr = $wpdb->prepare(
                "UPDATE $table SET queue_state='error', last_error=%s, last_seen_at=%s WHERE entity_id IN ($ph2)",
                array_merge(['Not returned by API (bulk).', $now], array_values($missing))
            );
            $wpdb->query($sqlErr);
            $err += count($missing);
        }

        usleep($sleepMicros);
    }

    $msg = sprintf('Details: processed %d (OK=%d, errors=%d).', (int)count($ids), (int)$ok, (int)$err);
    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode($msg)], admin_url('admin.php?page=s2m_magento')));
    exit;
});

/** === Render: UI block for Phase 2 (details) === */
function o2w_render_magento_import_details_section() {
    if (!current_user_can(o2w_required_cap())) return;

    $m = o2w_magento_local_metrics();

    // Phase 2 actions
    $fetch100_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_fetch_details&n=100'),
        'o2w_magento_fetch_details'
    );
    $fetch20_url  = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_fetch_details&n=20'),
        'o2w_magento_fetch_details'
    );
    $retry50_url  = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_fetch_details&n=50&retry=1'),
        'o2w_magento_fetch_details'
    );

    echo '<hr>';
    ?>
    <h2 class="title">Import from Magento — Order Details (Step 2)</h2>
    <p class="description" style="max-width:800px;">
        Completes pending orders with: order number, customer email, status, totals, currency and timestamps.
        This step reads the queue in <code><?php echo esc_html($GLOBALS['wpdb']->prefix . 'o2w_magento_orders'); ?></code>
        and updates each row as details are fetched.
    </p>

    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row">Current queue</th>
                <td>
                    <div><strong>Total in table:</strong> <?php echo (int)$m['total']; ?> —
                        <strong>pending:</strong> <?php echo (int)$m['pending']; ?> /
                        <strong>fetching:</strong> <?php echo (int)$m['fetching']; ?> /
                        <strong>done:</strong> <?php echo (int)$m['done']; ?> /
                        <strong>error:</strong> <?php echo (int)$m['error']; ?>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row">Actions</th>
                <td>
                    <a href="<?php echo esc_url($fetch100_url); ?>" class="button button-primary">Fetch details (100)</a>
                    <a href="<?php echo esc_url($fetch20_url); ?>" class="button">Fetch details (20)</a>
                    <a href="<?php echo esc_url($retry50_url); ?>" class="button button-secondary">Retry errors (50)</a>
                    <p class="description" style="margin-top:6px;">
                        Requests are performed in <em>batches</em> to reduce the number of API calls and server load.
                    </p>
                </td>
            </tr>
        </tbody>
    </table>
    <?php
}
