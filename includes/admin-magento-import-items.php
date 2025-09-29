<?php
// includes/admin-magento-import-items.php
if (!defined('ABSPATH')) exit;

/** Reuse constants if they already exist */
if (!defined('O2W_OPT_MAGENTO_URL'))  define('O2W_OPT_MAGENTO_URL',  'o2w_magento_url');
if (!defined('O2W_OPT_MAGENTO_USER')) define('O2W_OPT_MAGENTO_USER', 'o2w_magento_user');
if (!defined('O2W_OPT_MAGENTO_PASS')) define('O2W_OPT_MAGENTO_PASS', 'o2w_magento_pass');
if (!defined('O2W_TR_MAGENTO_TOKEN'))  define('O2W_TR_MAGENTO_TOKEN', 'o2w_magento_token');

/** Capability helper (keeps handlers/UI in sync with menus) */
if (!function_exists('o2w_required_cap')) {
    function o2w_required_cap() {
        return defined('S2M_REQUIRED_CAP') ? S2M_REQUIRED_CAP : 'manage_options';
    }
}

/**
 * Bearer token (cached ~50 min). Guarded to avoid redeclaration.
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
            return new WP_Error('o2w_magento_token_http', 'HTTP ' . $code . ': ' . substr($body, 0, 200));
        }
        $token = is_string($body) ? trim($body, " \t\n\r\0\x0B\"") : '';
        if ($token === '') return new WP_Error('o2w_magento_token_empty', 'Magento did not return a token.');
        set_transient(O2W_TR_MAGENTO_TOKEN, $token, 50 * MINUTE_IN_SECONDS);
        return $token;
    }
}

/** === Step 4 Helpers: order items — unique names o2w_items_* === */

/** Bulk /V1/orders with minimal fields for items */
function o2w_items_get_orders_bulk(array $entity_ids, $base, $token) {
    $entity_ids = array_values(array_unique(array_map('intval', $entity_ids)));
    if (!$entity_ids) return [];

    $ids_csv = implode(',', $entity_ids);

    $fields = 'items['
        .'entity_id,created_at,'
        .'items[item_id,parent_item_id,sku,product_id,name,qty_ordered,price,row_total,price_incl_tax,row_total_incl_tax]'
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
        return new WP_Error('o2w_magento_items_bulk_http', 'HTTP ' . $code);
    }

    $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : [];
    $by_id = [];
    foreach ($items as $it) {
        if (isset($it['entity_id'])) {
            $by_id[(int)$it['entity_id']] = $it; // each $it is an order with its "items"
        }
    }
    return $by_id;
}

/** Simple metrics for UI */
function o2w_items_metrics() {
    global $wpdb;
    $orders  = $wpdb->prefix . 'o2w_magento_orders';
    $oi      = $wpdb->prefix . 'o2w_magento_order_items';

    $total_done = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $orders WHERE queue_state=%s", 'done'
    ));
    $orders_with_items = (int)$wpdb->get_var("SELECT COUNT(DISTINCT order_entity_id) FROM $oi");
    $items_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM $oi");
    $pending_orders = max(0, $total_done - $orders_with_items);

    return compact('total_done','orders_with_items','items_count','pending_orders');
}

/** === Handler: Step 4 — fetch order items in batches === */
add_action('admin_post_o2w_magento_fetch_items', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_fetch_items');

    $n = isset($_GET['n']) ? max(1, (int)$_GET['n']) : 100;

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
    $orders_table = $wpdb->prefix . 'o2w_magento_orders';
    $items_table  = $wpdb->prefix . 'o2w_magento_order_items';

    // 1) Only orders already "done" that still have no items
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT o.entity_id
         FROM $orders_table o
         LEFT JOIN $items_table i ON i.order_entity_id = o.entity_id
         WHERE o.queue_state=%s AND i.order_entity_id IS NULL
         ORDER BY o.id ASC
         LIMIT %d",
        'done', $n
    ));
    if (empty($ids)) {
        wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode('No orders pending items.')], admin_url('admin.php?page=s2m_magento')));
        exit;
    }

    $ok_orders = 0; $err_orders = 0; $ins_items = 0;
    $chunkSize   = 20;      // ≈1 request per 20 orders
    $sleepMicros = 150000;  // 150 ms between requests

    foreach (array_chunk($ids, $chunkSize) as $chunk) {
        $batch = o2w_items_get_orders_bulk($chunk, $base, $token);
        if (is_wp_error($batch)) {
            $err_orders += count($chunk);
            usleep($sleepMicros);
            continue;
        }

        foreach ($batch as $order_id => $order) {
            $order_created_at = isset($order['created_at']) ? (string)$order['created_at'] : null;
            $rows = [];
            $params = [];

            if (!empty($order['items']) && is_array($order['items'])) {
                foreach ($order['items'] as $it) {
                    // skip child items (configurable/bundle)
                    if (!empty($it['parent_item_id'])) continue;

                    $rows[] = '(%d,%d,%s,%d,%s,%f,%f,%f,%f,%f,%s)';
                    $params[] = (int)$order_id;                                         // order_entity_id
                    $params[] = isset($it['item_id']) ? (int)$it['item_id'] : 0;       // item_id
                    $params[] = isset($it['sku']) ? (string)$it['sku'] : null;         // sku
                    $params[] = isset($it['product_id']) ? (int)$it['product_id'] : 0; // product_id (0 if null)
                    $params[] = isset($it['name']) ? (string)$it['name'] : null;       // product_name
                    $params[] = isset($it['qty_ordered']) ? (float)$it['qty_ordered'] : 0.0;
                    $params[] = isset($it['price']) ? (float)$it['price'] : 0.0;
                    $params[] = isset($it['row_total']) ? (float)$it['row_total'] : 0.0;
                    $params[] = isset($it['price_incl_tax']) ? (float)$it['price_incl_tax'] : 0.0;
                    $params[] = isset($it['row_total_incl_tax']) ? (float)$it['row_total_incl_tax'] : 0.0;
                    $params[] = $order_created_at;                                      // created_at (from order)
                }
            }

            if ($rows) {
                $sql = "INSERT INTO $items_table
                    (order_entity_id,item_id,sku,product_id,product_name,qty_ordered,price,row_total,price_incl_tax,row_total_incl_tax,created_at)
                    VALUES " . implode(',', $rows) . "
                    ON DUPLICATE KEY UPDATE
                      sku=VALUES(sku),
                      product_id=VALUES(product_id),
                      product_name=VALUES(product_name),
                      qty_ordered=VALUES(qty_ordered),
                      price=VALUES(price),
                      row_total=VALUES(row_total),
                      price_incl_tax=VALUES(price_incl_tax),
                      row_total_incl_tax=VALUES(row_total_incl_tax),
                      created_at=VALUES(created_at)";

                $wpdb->query($wpdb->prepare($sql, $params));
                $ins_items += (int)$wpdb->rows_affected; // includes updates
            }
            $ok_orders++;
        }

        usleep($sleepMicros);
    }

    $msg = sprintf('Items: orders processed %d (errors=%d), rows affected=%d.',
                   (int)$ok_orders, (int)$err_orders, (int)$ins_items);
    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode($msg)], admin_url('admin.php?page=s2m_magento')));
    exit;
});

/** === Render: UI block — Order Items (Step 4) === */
function o2w_render_magento_import_items_section() {
    if (!current_user_can(o2w_required_cap())) return;

    $m = o2w_items_metrics();

    $fetch100 = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_fetch_items&n=100'),
        'o2w_magento_fetch_items'
    );
    $fetch20  = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_fetch_items&n=20'),
        'o2w_magento_fetch_items'
    );

    echo '<hr>';
    ?>
    <h2 class="title">Import from Magento — Order Items (Step 4)</h2>
    <p class="description" style="max-width:800px;">
        Stores the <strong>sold products per order</strong> in the local table
        <code><?php global $wpdb; echo esc_html($wpdb->prefix.'o2w_magento_order_items'); ?></code>.
        Only <em>visible</em> items are inserted (child lines of configurables/bundles are skipped), and rows are upserted to avoid duplicates.
    </p>

    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row">Summary</th>
                <td>
                    <div>
                        <strong>Orders ready (done):</strong> <?php echo (int)$m['total_done']; ?> —
                        <strong>Orders with items:</strong> <?php echo (int)$m['orders_with_items']; ?> —
                        <strong>Pending:</strong> <?php echo (int)$m['pending_orders']; ?> —
                        <strong>Total items:</strong> <?php echo (int)$m['items_count']; ?>
                    </div>
                    <p class="description">Processed in pages of ~20 orders per request to avoid stressing Magento.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Actions</th>
                <td>
                    <a href="<?php echo esc_url($fetch100); ?>" class="button button-primary">Fetch items (100 orders)</a>
                    <a href="<?php echo esc_url($fetch20); ?>" class="button">Fetch items (20 orders)</a>
                </td>
            </tr>
        </tbody>
    </table>
    <?php
}
