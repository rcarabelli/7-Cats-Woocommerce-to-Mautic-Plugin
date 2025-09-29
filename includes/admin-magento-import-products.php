<?php
// includes/admin-magento-import-products.php
if (!defined('ABSPATH')) exit;

/** =====================
 *  Constants
 *  ===================== */
if (!defined('O2W_OPT_MAGENTO_URL'))  define('O2W_OPT_MAGENTO_URL',  'o2w_magento_url');
if (!defined('O2W_OPT_MAGENTO_USER')) define('O2W_OPT_MAGENTO_USER', 'o2w_magento_user');
if (!defined('O2W_OPT_MAGENTO_PASS')) define('O2W_OPT_MAGENTO_PASS', 'o2w_magento_pass');

if (!defined('O2W_TR_MAGENTO_PRODUCTS_TOTAL'))   define('O2W_TR_MAGENTO_PRODUCTS_TOTAL', 'o2w_magento_products_total');
if (!defined('O2W_OPT_MAGENTO_PRODUCTS_CURSOR')) define('O2W_OPT_MAGENTO_PRODUCTS_CURSOR', 'o2w_magento_products_cursor'); // ['page'=>int,'psize'=>int]

/** Capability helper (keeps handlers/UI consistent with menus) */
if (!function_exists('o2w_required_cap')) {
    function o2w_required_cap() {
        return defined('S2M_REQUIRED_CAP') ? S2M_REQUIRED_CAP : 'manage_options';
    }
}

/** =====================
 *  Table helper (avoid collisions)
 *  ===================== */
function o2w_prod_table() {
    if (function_exists('o2w_magento_products_table')) {
        return o2w_magento_products_table(); // defined in install-magento-db.php
    }
    global $wpdb; return $wpdb->prefix . 'o2w_magento_products';
}

/** =====================
 *  Bearer token (shared)
 *  ===================== */
function o2w_prod_get_bearer_token($force_refresh = false) {
    // Prefer global helper if present
    if (function_exists('o2w_magento_get_bearer_token')) {
        return o2w_magento_get_bearer_token($force_refresh);
    }

    // Local fallback
    $transient_key = 'o2w_magento_token';
    if (!$force_refresh) {
        $tok = get_transient($transient_key);
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
    set_transient($transient_key, $token, 50 * MINUTE_IN_SECONDS);
    return $token;
}

/** =====================
 *  Cursor helpers
 *  ===================== */
function o2w_prod_get_cursor() {
    $c = get_option(O2W_OPT_MAGENTO_PRODUCTS_CURSOR, []);
    $page  = isset($c['page'])  ? max(1, (int)$c['page'])  : 1;
    $psize = isset($c['psize']) ? max(50,(int)$c['psize']) : 200;
    return ['page'=>$page,'psize'=>$psize];
}
function o2w_prod_set_cursor($page, $psize = 200) {
    update_option(O2W_OPT_MAGENTO_PRODUCTS_CURSOR, ['page'=>(int)$page,'psize'=>(int)$psize], false);
}

/** =====================
 *  Local metrics (products)
 *  ===================== */
function o2w_prod_local_metrics() {
    global $wpdb;
    $t = o2w_prod_table();
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=%s AND table_name=%s",
        DB_NAME, $t
    ));
    if (!(int)$exists) return ['total'=>0];
    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
    return compact('total');
}

/** =====================
 *  Magento API — products
 *  ===================== */
function o2w_prod_fetch_total($force_refresh = false) {
    if (!$force_refresh) {
        $cached = get_transient(O2W_TR_MAGENTO_PRODUCTS_TOTAL);
        if (is_array($cached) && isset($cached['count'], $cached['ts'])) return $cached;
    }
    $base = rtrim((string)get_option(O2W_OPT_MAGENTO_URL, ''), '/');
    if ($base === '') return new WP_Error('o2w_magento_base', 'Magento REST URL is missing.');

    $token = o2w_prod_get_bearer_token();
    if (is_wp_error($token)) return $token;

    $url = add_query_arg([
        'searchCriteria[currentPage]' => 1,
        'searchCriteria[pageSize]'    => 1, // we only need total_count
    ], $base . '/V1/products');

    $resp = wp_remote_get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 25,
    ]);
    if (is_wp_error($resp)) return $resp;

    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300 || !is_array($body)) {
        return new WP_Error('o2w_magento_products_total_http', 'HTTP ' . $code);
    }

    $total = isset($body['total_count']) ? (int)$body['total_count'] : 0;
    $payload = ['count'=>$total, 'ts'=>time()];
    set_transient(O2W_TR_MAGENTO_PRODUCTS_TOTAL, $payload, 15 * MINUTE_IN_SECONDS);
    return $payload;
}

function o2w_prod_fetch_page($page, $psize, $token, $base) {
    // Minimal fields for our local master table
    $fields = 'items['
        .'id,sku,name,status,visibility,type_id,attribute_set_id,price,created_at,updated_at,'
        .'extension_attributes[stock_item[qty,is_in_stock],category_links[category_id]],'
        .'media_gallery_entries[file,types],'
        .'custom_attributes[attribute_code,value]'
        .'],total_count';

    $url = add_query_arg([
        'searchCriteria[currentPage]' => (int)$page,
        'searchCriteria[pageSize]'    => (int)$psize,
        'fields' => $fields,
    ], rtrim($base,'/') . '/V1/products');

    $resp = wp_remote_get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 30,
    ]);
    if (is_wp_error($resp)) return $resp;

    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300 || !is_array($body)) {
        return new WP_Error('o2w_magento_products_http', 'HTTP '.$code);
    }

    return isset($body['items']) && is_array($body['items']) ? $body['items'] : [];
}

/** =====================
 *  Mapping + UPSERT
 *  ===================== */
function o2w_prod_map_row(array $p) {
    // custom_attributes -> dict
    $attrs = [];
    if (!empty($p['custom_attributes']) && is_array($p['custom_attributes'])) {
        foreach ($p['custom_attributes'] as $ca) {
            if (!isset($ca['attribute_code'])) continue;
            $attrs[$ca['attribute_code']] = $ca['value'] ?? null;
        }
    }

    // Stock
    $stock = $p['extension_attributes']['stock_item'] ?? [];
    $qty      = isset($stock['qty']) ? (float)$stock['qty'] : null;
    $in_stock = isset($stock['is_in_stock']) ? (int)$stock['is_in_stock'] : null;

    // Categories (ids)
    $cats = [];
    if (!empty($p['extension_attributes']['category_links'])) {
        foreach ($p['extension_attributes']['category_links'] as $cl) {
            if (!empty($cl['category_id'])) $cats[] = (int)$cl['category_id'];
        }
        $cats = array_values(array_unique($cats));
    }

    // Media gallery (paths)
    $media = [];
    if (!empty($p['media_gallery_entries']) && is_array($p['media_gallery_entries'])) {
        foreach ($p['media_gallery_entries'] as $m) {
            if (!empty($m['file'])) $media[] = $m['file'];
        }
    }

    // Common attributes
    $brand = $attrs['brand'] ?? ($attrs['manufacturer'] ?? null);
    $color = $attrs['color'] ?? null;
    $size  = $attrs['size']  ?? null;

    // Optional price/measure fields
    $special_price = isset($attrs['special_price']) ? (float)$attrs['special_price'] : null;
    $cost          = isset($attrs['cost'])          ? (float)$attrs['cost']          : null;
    $weight        = isset($attrs['weight'])        ? (float)$attrs['weight']        : null;

    return [
        'sku'                 => (string)($p['sku'] ?? ''),
        'product_id'          => isset($p['id']) ? (int)$p['id'] : null,
        'name'                => isset($p['name']) ? (string)$p['name'] : null,
        'status'              => isset($p['status']) ? (int)$p['status'] : null,
        'visibility'          => isset($p['visibility']) ? (int)$p['visibility'] : null,
        'type_id'             => isset($p['type_id']) ? (string)$p['type_id'] : null,
        'attribute_set_id'    => isset($p['attribute_set_id']) ? (int)$p['attribute_set_id'] : null,
        'price'               => isset($p['price']) ? (float)$p['price'] : null,
        'special_price'       => $special_price,
        'cost'                => $cost,
        'weight'              => $weight,
        'created_at'          => isset($p['created_at']) ? (string)$p['created_at'] : null,
        'updated_at'          => isset($p['updated_at']) ? (string)$p['updated_at'] : null,
        'url_key'             => isset($attrs['url_key']) ? (string)$attrs['url_key'] : null,
        'image'               => isset($attrs['image']) ? (string)$attrs['image'] : null,
        'small_image'         => isset($attrs['small_image']) ? (string)$attrs['small_image'] : null,
        'thumbnail'           => isset($attrs['thumbnail']) ? (string)$attrs['thumbnail'] : null,
        'media_gallery_json'  => $media ? wp_json_encode($media, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null,
        'categories_json'     => $cats  ? wp_json_encode($cats,  JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null,
        'stock_qty'           => $qty,
        'is_in_stock'         => $in_stock,
        'brand'               => $brand ? (string)$brand : null,
        'color'               => $color ? (string)$color : null,
        'size'                => $size  ? (string)$size  : null,
    ];
}

function o2w_prod_upsert(array $row) {
    global $wpdb;
    $table = o2w_prod_table();

    // REPLACE needs explicit columns
    $data = [
        'sku'                => $row['sku'],
        'product_id'         => $row['product_id'],
        'name'               => $row['name'],
        'status'             => $row['status'],
        'visibility'         => $row['visibility'],
        'type_id'            => $row['type_id'],
        'attribute_set_id'   => $row['attribute_set_id'],
        'price'              => $row['price'],
        'special_price'      => $row['special_price'],
        'cost'               => $row['cost'],
        'weight'             => $row['weight'],
        'created_at'         => $row['created_at'],
        'updated_at'         => $row['updated_at'],
        'url_key'            => $row['url_key'],
        'image'              => $row['image'],
        'small_image'        => $row['small_image'],
        'thumbnail'          => $row['thumbnail'],
        'media_gallery_json' => $row['media_gallery_json'],
        'categories_json'    => $row['categories_json'],
        'stock_qty'          => $row['stock_qty'],
        'is_in_stock'        => $row['is_in_stock'],
        'brand'              => $row['brand'],
        'color'              => $row['color'],
        'size'               => $row['size'],
    ];

    return (false !== $wpdb->replace($table, $data));
}

/** =====================
 *  Actions (handlers)
 *  ===================== */
add_action('admin_post_o2w_magento_products_refresh_total', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_products_refresh_total');

    $r = o2w_prod_fetch_total(true);
    $msg = is_wp_error($r)
        ? ('Could not fetch Magento product total: '.$r->get_error_message())
        : sprintf('Total products in Magento: %d.', (int)$r['count']);

    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode($msg)], admin_url('admin.php?page=s2m_magento')));
    exit;
});

add_action('admin_post_o2w_magento_products_reset_cursor', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_products_reset_cursor');

    o2w_prod_set_cursor(1, 200);
    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode('Products cursor reset to page 1 (pageSize=200).')], admin_url('admin.php?page=s2m_magento')));
    exit;
});

add_action('admin_post_o2w_magento_fetch_products', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_fetch_products');

    $n = isset($_GET['n']) ? max(1, (int)$_GET['n']) : 1000;

    $cursor = o2w_prod_get_cursor();
    $page   = $cursor['page'];
    $psize  = $cursor['psize'];

    $base = rtrim((string)get_option(O2W_OPT_MAGENTO_URL, ''), '/');
    if ($base === '') {
        wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode('Magento REST URL is missing.')], admin_url('admin.php?page=s2m_magento')));
        exit;
    }

    $token = o2w_prod_get_bearer_token();
    if (is_wp_error($token)) {
        wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode('Token error: '.$token->get_error_message())], admin_url('admin.php?page=s2m_magento')));
        exit;
    }

    $pages_to_fetch = (int)ceil($n / $psize);
    $inserted = 0; $seen = 0; $errors = 0;
    $sleepMicros = 200000; // 200 ms between pages

    for ($i = 0; $i < $pages_to_fetch; $i++) {
        $items = o2w_prod_fetch_page($page, $psize, $token, $base);
        if (is_wp_error($items)) { $errors++; break; }
        if (!$items) { $page = 1; break; }

        foreach ($items as $p) {
            $seen++;
            $row = o2w_prod_map_row($p);
            if (empty($row['sku'])) continue; // no SKU = no key
            if (o2w_prod_upsert($row)) $inserted++;
        }

        $page++;
        usleep($sleepMicros);
    }

    o2w_prod_set_cursor($page, $psize);

    $msg = sprintf('Products: seen %d / upserts %d / errors %d. Cursor now at page %d (pageSize=%d).',
                   (int)$seen, (int)$inserted, (int)$errors, (int)$page, (int)$psize);
    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode($msg)], admin_url('admin.php?page=s2m_magento')));
    exit;
});

/** =====================
 *  Render UI
 *  ===================== */
function o2w_render_magento_import_products_section_ui() {
    if (!current_user_can(o2w_required_cap())) return;

    $remote = get_transient(O2W_TR_MAGENTO_PRODUCTS_TOTAL);
    $remote_count = is_array($remote) ? (int)$remote['count'] : null;
    $remote_ts    = is_array($remote) ? (int)$remote['ts'] : null;

    $m = o2w_prod_local_metrics();

    $pct = (is_int($remote_count) && $remote_count > 0)
        ? min(100, round(($m['total'] / $remote_count) * 100))
        : null;

    $cur = o2w_prod_get_cursor();

    $refresh_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_products_refresh_total'),
        'o2w_magento_products_refresh_total'
    );
    $fetch1000_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_fetch_products&n=1000'),
        'o2w_magento_fetch_products'
    );
    $fetch200_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_fetch_products&n=200'),
        'o2w_magento_fetch_products'
    );
    $reset_cursor_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_products_reset_cursor'),
        'o2w_magento_products_reset_cursor'
    );

    echo '<hr>';
    ?>
    <h2 class="title">Import from Magento — Products (master) (Step 5)</h2>
    <p class="description" style="max-width:800px;">
        Imports all <strong>products</strong> from Magento (sold or not) into the local table
        <code><?php echo esc_html(o2w_prod_table()); ?></code>. Fields include price, stock, categories,
        media paths, and common attributes (brand, color, size).
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
                    <div class="description">Magento product total has not been fetched yet.</div>
                <?php endif; ?>

                <div><strong>Total in local table:</strong> <?php echo (int)$m['total']; ?></div>

                <div class="description" style="margin-top:6px;">
                    Current cursor: <strong>page <?php echo (int)$cur['page']; ?></strong> (Magento, pageSize=<?php echo (int)$cur['psize']; ?>)
                </div>

                <?php if ($pct !== null) : ?>
                    <div style="margin-top:6px; width:320px; background:#eee; height:10px; border-radius:6px; overflow:hidden;">
                        <div style="width:<?php echo (int)$pct; ?>%; height:10px; background:#2271b1;"></div>
                    </div>
                    <p class="description" style="margin-top:6px;"><?php echo (int)$pct; ?>% imported</p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row">Actions</th>
            <td>
                <a href="<?php echo esc_url($refresh_url); ?>" class="button">Refresh remote total</a>
                <a href="<?php echo esc_url($fetch1000_url); ?>" class="button button-primary">Fetch products (1000)</a>
                <a href="<?php echo esc_url($fetch200_url); ?>" class="button">Fetch products (200)</a>
                <a href="<?php echo esc_url($reset_cursor_url); ?>" class="button button-secondary">Reset cursor</a>
                <p class="description" style="margin-top:6px;">
                    Uses <em>batches</em> of <?php echo (int)$cur['psize']; ?> per request with a short pause between pages to avoid overloading Magento.
                </p>
            </td>
        </tr>
        </tbody>
    </table>
    <?php
}

/** Backward-compatible alias (if your code calls this older name) */
if (!function_exists('o2w_render_magento_import_products_section')) {
    function o2w_render_magento_import_products_section() {
        o2w_render_magento_import_products_section_ui();
    }
}
