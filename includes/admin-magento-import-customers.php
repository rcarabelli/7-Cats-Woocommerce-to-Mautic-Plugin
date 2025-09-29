<?php
// includes/admin-magento-import-customers.php
if (!defined('ABSPATH')) exit;

/** Reuse global constants if already defined */
if (!defined('O2W_OPT_MAGENTO_URL'))  define('O2W_OPT_MAGENTO_URL',  'o2w_magento_url');
if (!defined('O2W_OPT_MAGENTO_USER')) define('O2W_OPT_MAGENTO_USER', 'o2w_magento_user');
if (!defined('O2W_OPT_MAGENTO_PASS')) define('O2W_OPT_MAGENTO_PASS', 'o2w_magento_pass');
if (!defined('O2W_TR_MAGENTO_TOKEN'))  define('O2W_TR_MAGENTO_TOKEN', 'o2w_magento_token');

/** Customers cursor */
if (!defined('O2W_OPT_MAG_CUSTOMER_CURSOR')) define('O2W_OPT_MAG_CUSTOMER_CURSOR', 'o2w_magento_customer_cursor'); // ['page'=>int,'psize'=>int]

/** Capability helper (keeps menu + handlers in sync) */
if (!function_exists('o2w_required_cap')) {
    function o2w_required_cap() {
        return defined('S2M_REQUIRED_CAP') ? S2M_REQUIRED_CAP : 'manage_options';
    }
}

/* ============================================================
 * Bearer Token — customers flavor (no collision with orders)
 * ============================================================ */
if (!function_exists('o2w_customers_get_bearer_token')) {
    function o2w_customers_get_bearer_token($force_refresh = false) {
        // Prefer global helper if present
        if (function_exists('o2w_magento_get_bearer_token')) {
            return o2w_magento_get_bearer_token($force_refresh);
        }
        // Local implementation
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

/* ============================================================
 * Cursor helpers (customers)
 * ============================================================ */
if (!function_exists('o2w_customers_get_cursor')) {
    function o2w_customers_get_cursor() {
        $c = get_option(O2W_OPT_MAG_CUSTOMER_CURSOR, []);
        $page  = isset($c['page'])  ? max(1, (int)$c['page'])  : 1;
        $psize = isset($c['psize']) ? max(50,(int)$c['psize']) : 200;
        return ['page'=>$page,'psize'=>$psize];
    }
}
if (!function_exists('o2w_customers_set_cursor')) {
    function o2w_customers_set_cursor($page, $psize = 200) {
        update_option(O2W_OPT_MAG_CUSTOMER_CURSOR, ['page'=>(int)$page,'psize'=>(int)$psize], false);
    }
}

/* ============================================================
 * Local metrics (customers table)
 * ============================================================ */
if (!function_exists('o2w_customers_local_metrics')) {
    function o2w_customers_local_metrics() {
        global $wpdb;
        $t = $wpdb->prefix . 'o2w_magento_customers';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=%s AND table_name=%s",
            DB_NAME, $t
        ));
        if (!(int)$exists) return ['total'=>0];
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
        return compact('total');
    }
}

/* ============================================================
 * Magento Customers — fetch page (searchCriteria)
 * ============================================================ */
if (!function_exists('o2w_customers_fetch_page')) {
    function o2w_customers_fetch_page($page, $psize, $base, $token) {
        // Minimal fields + phones from addresses (by default billing/shipping IDs)
        $fields = 'items['
                .'id,email,firstname,lastname,created_at,updated_at,'
                .'default_billing,default_shipping,'
                .'addresses[id,telephone]'
            .'],total_count';

        $url = add_query_arg([
            'searchCriteria[currentPage]' => (int)$page,
            'searchCriteria[pageSize]'    => (int)$psize,
            'fields'                      => $fields,
        ], rtrim($base, '/') . '/V1/customers/search');

        $resp = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 25,
        ]);
        if (is_wp_error($resp)) return $resp;

        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            return new WP_Error('o2w_magento_customers_http', 'HTTP ' . $code);
        }
        return $body; // ['items'=>[], 'total_count'=>N]
    }
}

/* ============================================================
 * Util: pick phone from default addresses
 * ============================================================ */
if (!function_exists('o2w_customers_pick_phone')) {
    function o2w_customers_pick_phone(array $cust) {
        $addresses = isset($cust['addresses']) && is_array($cust['addresses']) ? $cust['addresses'] : [];
        if (!$addresses) return null;

        $by_id = [];
        foreach ($addresses as $a) {
            if (isset($a['id'])) $by_id[(string)$a['id']] = $a;
        }

        $def_b = isset($cust['default_billing']) ? (string)$cust['default_billing'] : null;
        $def_s = isset($cust['default_shipping']) ? (string)$cust['default_shipping'] : null;

        // Prefer billing, then shipping, then any address with a phone
        if ($def_b && !empty($by_id[$def_b]['telephone'])) {
            return (string)$by_id[$def_b]['telephone'];
        }
        if ($def_s && !empty($by_id[$def_s]['telephone'])) {
            return (string)$by_id[$def_s]['telephone'];
        }
        foreach ($addresses as $a) {
            if (!empty($a['telephone'])) return (string)$a['telephone'];
        }
        return null;
    }
}

/* ============================================================
 * Upsert batch into wp_*_o2w_magento_customers (by email)
 * ============================================================ */
if (!function_exists('o2w_customers_upsert_batch')) {
    function o2w_customers_upsert_batch(array $items) {
        if (!$items) return 0;
        global $wpdb;
        $table = $wpdb->prefix . 'o2w_magento_customers';

        $cols = ['customer_id','email','first_name','last_name','full_name','phone','first_seen_at'];
        $place = [];
        $params = [];

        foreach ($items as $c) {
            $place[] = "(%d,%s,%s,%s,%s,%s,%s)";
            $params[] = isset($c['customer_id']) ? (int)$c['customer_id'] : null;
            $params[] = (string)$c['email'];
            $params[] = isset($c['first_name']) ? (string)$c['first_name'] : null;
            $params[] = isset($c['last_name']) ? (string)$c['last_name'] : null;
            $params[] = isset($c['full_name']) ? (string)$c['full_name'] : null;
            $params[] = isset($c['phone']) ? (string)$c['phone'] : null;
            $params[] = isset($c['first_seen_at']) ? (string)$c['first_seen_at'] : null;
        }

        $sql = "INSERT INTO $table (".implode(',', $cols).") VALUES " . implode(',', $place) . "
            ON DUPLICATE KEY UPDATE
              customer_id = IFNULL(VALUES(customer_id), customer_id),
              first_name  = VALUES(first_name),
              last_name   = VALUES(last_name),
              full_name   = VALUES(full_name),
              phone       = COALESCE(VALUES(phone), phone),
              first_seen_at = CASE
                  WHEN first_seen_at IS NULL THEN VALUES(first_seen_at)
                  WHEN VALUES(first_seen_at) IS NULL THEN first_seen_at
                  ELSE LEAST(first_seen_at, VALUES(first_seen_at))
              END";

        $prepared = $wpdb->prepare($sql, $params);
        $wpdb->query($prepared);
        return (int)$wpdb->rows_affected; // inserted + updated rows
    }
}

/* ============================================================
 * Handler: import customers in batches
 * ============================================================ */
add_action('admin_post_o2w_magento_fetch_customers', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_fetch_customers');

    $n = isset($_GET['n']) ? max(1,(int)$_GET['n']) : 1000; // approx. how many records to fetch
    $cursor = o2w_customers_get_cursor();
    $page   = $cursor['page'];
    $psize  = $cursor['psize'];

    $base = rtrim((string) get_option(O2W_OPT_MAGENTO_URL, ''), '/');
    if ($base === '') {
        wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode('Magento REST URL is missing.')], admin_url('admin.php?page=s2m_magento')));
        exit;
    }

    $token = function_exists('o2w_magento_get_bearer_token')
        ? o2w_magento_get_bearer_token()
        : o2w_customers_get_bearer_token();

    if (is_wp_error($token)) {
        wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode('Token error: '.$token->get_error_message())], admin_url('admin.php?page=s2m_magento')));
        exit;
    }

    $pages_to_fetch = (int) ceil($n / $psize);
    $seen = 0; $upserted = 0; $err = '';

    $sleepMicros = 150000; // 150 ms between pages to avoid overloading Magento

    for ($i=0; $i < $pages_to_fetch; $i++) {
        $res = o2w_customers_fetch_page($page, $psize, $base, $token);
        if (is_wp_error($res)) { $err = $res->get_error_message(); break; }

        $items = isset($res['items']) && is_array($res['items']) ? $res['items'] : [];
        if (!$items) { // end
            $page = 1;
            break;
        }

        // Map to local columns
        $batch = [];
        foreach ($items as $cust) {
            if (empty($cust['email'])) continue; // unique key guarantee
            $first = isset($cust['firstname']) ? trim((string)$cust['firstname']) : '';
            $last  = isset($cust['lastname'])  ? trim((string)$cust['lastname'])  : '';
            $full  = trim(trim($first.' '.$last));
            $phone = o2w_customers_pick_phone($cust);

            $batch[] = [
                'customer_id'   => isset($cust['id']) ? (int)$cust['id'] : null,
                'email'         => strtolower((string)$cust['email']),
                'first_name'    => $first ?: null,
                'last_name'     => $last ?: null,
                'full_name'     => $full ?: null,
                'phone'         => $phone ?: null,
                'first_seen_at' => isset($cust['created_at']) ? (string)$cust['created_at'] : null,
            ];
        }

        if ($batch) {
            $upserted += o2w_customers_upsert_batch($batch);
        }

        $seen += count($items);
        $page++;
        usleep($sleepMicros);
    }

    // Save cursor
    o2w_customers_set_cursor($page, $psize);

    $msg = $err
        ? ('Customers: import error: '.$err)
        : sprintf('Customers imported: seen %d / upserts %d. Cursor now at page %d (pageSize=%d).',
                  (int)$seen, (int)$upserted, (int)$page, (int)$psize);

    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode($msg)], admin_url('admin.php?page=s2m_magento')));
    exit;
});

/* ============================================================
 * Reset customers cursor
 * ============================================================ */
add_action('admin_post_o2w_magento_reset_customers_cursor', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_reset_customers_cursor');
    o2w_customers_set_cursor(1, 200);
    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode('Customers cursor reset to page 1 (pageSize=200).')], admin_url('admin.php?page=s2m_magento')));
    exit;
});

/* ============================================================
 * Render UI — customers section (unique name)
 * ============================================================ */
if (!function_exists('o2w_customers_render_import_section')) {
    function o2w_customers_render_import_section() {
        if (!current_user_can(o2w_required_cap())) return;

        $m   = o2w_customers_local_metrics();
        $m  += ['total'=>0];
        $cur = o2w_customers_get_cursor();

        // Action URLs
        $fetch1000_url = wp_nonce_url(
            admin_url('admin-post.php?action=o2w_magento_fetch_customers&n=1000'),
            'o2w_magento_fetch_customers'
        );
        $fetch200_url  = wp_nonce_url(
            admin_url('admin-post.php?action=o2w_magento_fetch_customers&n=200'),
            'o2w_magento_fetch_customers'
        );
        $reset_url     = wp_nonce_url(
            admin_url('admin-post.php?action=o2w_magento_reset_customers_cursor'),
            'o2w_magento_reset_customers_cursor'
        );

        echo '<hr>';
        ?>
        <h2 class="title">Import from Magento — Registered Customers (Step 3)</h2>
        <p class="description" style="max-width:800px;">
            Fetches all <strong>registered customers</strong> from Magento (whether they have orders or not)
            and stores them in the local table <code>o2w_magento_customers</code>.
        </p>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">Summary</th>
                    <td>
                        <div><strong>Total in local table:</strong> <?php echo (int)$m['total']; ?></div>
                        <div class="description" style="margin-top:6px;">
                            Current cursor: <strong>page <?php echo (int)$cur['page']; ?></strong> (pageSize=<?php echo (int)$cur['psize']; ?>)
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Actions</th>
                    <td>
                        <a href="<?php echo esc_url($fetch1000_url); ?>" class="button button-primary">Fetch customers (1000)</a>
                        <a href="<?php echo esc_url($fetch200_url); ?>" class="button">Fetch customers (200)</a>
                        <a href="<?php echo esc_url($reset_url); ?>" class="button button-secondary">Reset cursor</a>
                        <p class="description" style="margin-top:6px;">
                            Requests run in batches of <code><?php echo (int)$cur['psize']; ?></code> per page with a short pause between pages to avoid overloading Magento.
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }
}
