<?php
// includes/admin-magento-import-categories.php
if (!defined('ABSPATH')) exit;

/** =====================
 *  Constants (categories)
 *  ===================== */
if (!defined('O2W_OPT_MAGENTO_URL'))  define('O2W_OPT_MAGENTO_URL',  'o2w_magento_url');
if (!defined('O2W_OPT_MAGENTO_USER')) define('O2W_OPT_MAGENTO_USER', 'o2w_magento_user');
if (!defined('O2W_OPT_MAGENTO_PASS')) define('O2W_OPT_MAGENTO_PASS', 'o2w_magento_pass');

if (!defined('O2W_TR_MAGENTO_CATEGORIES_TOTAL'))   define('O2W_TR_MAGENTO_CATEGORIES_TOTAL', 'o2w_magento_categories_total');
if (!defined('O2W_OPT_MAGENTO_CATEGORIES_CURSOR')) define('O2W_OPT_MAGENTO_CATEGORIES_CURSOR', 'o2w_magento_categories_cursor'); // ['page'=>int,'psize'=>int]

/** Capability helper (keeps menu + handlers in sync) */
if (!function_exists('o2w_required_cap')) {
    function o2w_required_cap() {
        return defined('S2M_REQUIRED_CAP') ? S2M_REQUIRED_CAP : 'manage_options';
    }
}

/** =====================
 *  Table helper (no collisions)
 *  ===================== */
function o2w_cat_table() {
    if (function_exists('o2w_magento_categories_table')) {
        return o2w_magento_categories_table(); // defined in install-magento-db.php
    }
    global $wpdb; return $wpdb->prefix . 'o2w_magento_categories';
}

/** =====================
 *  Token (reuse global if available)
 *  ===================== */
function o2w_cat_get_bearer_token($force_refresh = false) {
    if (function_exists('o2w_magento_get_bearer_token')) {
        return o2w_magento_get_bearer_token($force_refresh);
    }

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
        return new WP_Error('o2w_magento_token_http', 'HTTP ' . $code . ': ' . $body);
    }
    $token = is_string($body) ? trim($body, " \t\n\r\0\x0B\"") : '';
    if ($token === '') return new WP_Error('o2w_magento_token_empty', 'Magento did not return a token.');
    set_transient($transient_key, $token, 50 * MINUTE_IN_SECONDS);
    return $token;
}

/** =====================
 *  Cursor
 *  ===================== */
function o2w_cat_get_cursor() {
    $c = get_option(O2W_OPT_MAGENTO_CATEGORIES_CURSOR, []);
    $page  = isset($c['page'])  ? max(1, (int)$c['page'])  : 1;
    $psize = isset($c['psize']) ? max(50,(int)$c['psize']) : 200;
    return ['page'=>$page,'psize'=>$psize];
}
function o2w_cat_set_cursor($page, $psize = 200) {
    update_option(O2W_OPT_MAGENTO_CATEGORIES_CURSOR, ['page'=>(int)$page,'psize'=>(int)$psize], false);
}

/** =====================
 *  Local metrics
 *  ===================== */
function o2w_cat_local_metrics() {
    global $wpdb;
    $t = o2w_cat_table();
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=%s AND table_name=%s",
        DB_NAME, $t
    ));
    if (!(int)$exists) return ['total'=>0];
    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
    return compact('total');
}

/** =====================
 *  Magento API — categories
 *  ===================== */
function o2w_cat_fetch_total($force_refresh = false) {
    if (!$force_refresh) {
        $cached = get_transient(O2W_TR_MAGENTO_CATEGORIES_TOTAL);
        if (is_array($cached) && isset($cached['count'], $cached['ts'])) return $cached;
    }
    $base = rtrim((string)get_option(O2W_OPT_MAGENTO_URL, ''), '/');
    if ($base === '') return new WP_Error('o2w_magento_base', 'Magento REST URL is missing.');

    $token = o2w_cat_get_bearer_token();
    if (is_wp_error($token)) return $token;

    // categories/list provides total_count
    $url = add_query_arg([
        'searchCriteria[currentPage]' => 1,
        'searchCriteria[pageSize]'    => 1,
        'fields' => 'items[id],total_count'
    ], $base . '/V1/categories/list');

    $resp = wp_remote_get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 25,
    ]);
    if (is_wp_error($resp)) return $resp;

    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300 || !is_array($body)) {
        return new WP_Error('o2w_magento_categories_total_http', 'HTTP ' . $code);
    }

    $total = isset($body['total_count']) ? (int)$body['total_count'] : 0;
    $payload = ['count'=>$total, 'ts'=>time()];
    set_transient(O2W_TR_MAGENTO_CATEGORIES_TOTAL, $payload, 15 * MINUTE_IN_SECONDS);
    return $payload;
}

function o2w_cat_fetch_page($page, $psize, $token, $base) {
    $fields = 'items['
        .'id,parent_id,name,is_active,position,level,path,children_count,created_at,updated_at,include_in_menu,'
        .'custom_attributes[attribute_code,value]'
        .'],total_count';

    $url = add_query_arg([
        'searchCriteria[currentPage]' => (int)$page,
        'searchCriteria[pageSize]'    => (int)$psize,
        'fields' => $fields,
    ], rtrim($base,'/') . '/V1/categories/list');

    $resp = wp_remote_get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 30,
    ]);
    if (is_wp_error($resp)) return $resp;

    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300 || !is_array($body)) {
        return new WP_Error('o2w_magento_categories_http', 'HTTP '.$code);
    }

    return isset($body['items']) && is_array($body['items']) ? $body['items'] : [];
}

/** =====================
 *  Mapping + UPSERT
 *  ===================== */
function o2w_cat_map_row(array $c) {
    // custom_attributes -> dictionary
    $attrs = [];
    if (!empty($c['custom_attributes']) && is_array($c['custom_attributes'])) {
        foreach ($c['custom_attributes'] as $ca) {
            if (!isset($ca['attribute_code'])) continue;
            $attrs[$ca['attribute_code']] = $ca['value'] ?? null;
        }
    }

    return [
        'category_id'      => isset($c['id']) ? (int)$c['id'] : null,
        'parent_id'        => isset($c['parent_id']) ? (int)$c['parent_id'] : null,
        'path'             => isset($c['path']) ? (string)$c['path'] : null,
        'level'            => isset($c['level']) ? (int)$c['level'] : null,
        'position'         => isset($c['position']) ? (int)$c['position'] : null,
        'is_active'        => isset($c['is_active']) ? (int)$c['is_active'] : null,
        'name'             => isset($c['name']) ? (string)$c['name'] : null,
        'url_key'          => isset($attrs['url_key'])  ? (string)$attrs['url_key']  : null,
        'url_path'         => isset($attrs['url_path']) ? (string)$attrs['url_path'] : null,
        'image'            => isset($attrs['image'])    ? (string)$attrs['image']    : null,
        'include_in_menu'  => isset($c['include_in_menu']) ? (int)$c['include_in_menu'] : null,
        'children_count'   => isset($c['children_count']) ? (int)$c['children_count'] : null,
        'created_at'       => isset($c['created_at']) ? (string)$c['created_at'] : null,
        'updated_at'       => isset($c['updated_at']) ? (string)$c['updated_at'] : null,
        'meta_title'       => isset($attrs['meta_title'])       ? (string)$attrs['meta_title']       : null,
        'meta_keywords'    => isset($attrs['meta_keywords'])    ? (string)$attrs['meta_keywords']    : null,
        'meta_description' => isset($attrs['meta_description']) ? (string)$attrs['meta_description'] : null,
        'custom_attributes_json' => $attrs ? wp_json_encode($attrs, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null,
    ];
}

function o2w_cat_upsert(array $row) {
    global $wpdb;
    $table = o2w_cat_table();

    // REPLACE requires explicit columns
    $data = [
        'category_id'            => $row['category_id'],
        'parent_id'              => $row['parent_id'],
        'path'                   => $row['path'],
        'level'                  => $row['level'],
        'position'               => $row['position'],
        'is_active'              => $row['is_active'],
        'name'                   => $row['name'],
        'url_key'                => $row['url_key'],
        'url_path'               => $row['url_path'],
        'image'                  => $row['image'],
        'include_in_menu'        => $row['include_in_menu'],
        'children_count'         => $row['children_count'],
        'created_at'             => $row['created_at'],
        'updated_at'             => $row['updated_at'],
        'meta_title'             => $row['meta_title'],
        'meta_keywords'          => $row['meta_keywords'],
        'meta_description'       => $row['meta_description'],
        'custom_attributes_json' => $row['custom_attributes_json'],
    ];

    // Natural key: category_id (UNIQUE in table)
    return (false !== $wpdb->replace($table, $data));
}

/** =====================
 *  Actions (handlers)
 *  ===================== */
add_action('admin_post_o2w_magento_categories_refresh_total', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_categories_refresh_total');

    $r = o2w_cat_fetch_total(true);
    $msg = is_wp_error($r) ? ('Could not read Magento categories total: '.$r->get_error_message())
                           : sprintf('Magento categories total: %d.', (int)$r['count']);

    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode($msg)], admin_url('admin.php?page=s2m_magento')));
    exit;
});

add_action('admin_post_o2w_magento_categories_reset_cursor', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_categories_reset_cursor');

    o2w_cat_set_cursor(1, 200);
    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode('Categories cursor reset to page 1.')], admin_url('admin.php?page=s2m_magento')));
    exit;
});

add_action('admin_post_o2w_magento_fetch_categories', function () {
    if (!current_user_can(o2w_required_cap())) wp_die('Forbidden');
    check_admin_referer('o2w_magento_fetch_categories');

    $n = isset($_GET['n']) ? max(1, (int)$_GET['n']) : 1000;

    $cursor = o2w_cat_get_cursor();
    $page   = $cursor['page'];
    $psize  = $cursor['psize'];

    $base = rtrim((string)get_option(O2W_OPT_MAGENTO_URL, ''), '/');
    if ($base === '') {
        wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode('Magento REST URL is missing.')], admin_url('admin.php?page=s2m_magento')));
        exit;
    }

    $token = o2w_cat_get_bearer_token();
    if (is_wp_error($token)) {
        wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode('Token error: '.$token->get_error_message())], admin_url('admin.php?page=s2m_magento')));
        exit;
    }

    $pages_to_fetch = (int)ceil($n / $psize);
    $inserted = 0; $seen = 0; $errors = 0;
    $sleepMicros = 200000; // 200 ms between pages

    for ($i = 0; $i < $pages_to_fetch; $i++) {
        $items = o2w_cat_fetch_page($page, $psize, $token, $base);
        if (is_wp_error($items)) { $errors++; break; }
        if (!$items) { $page = 1; break; }

        foreach ($items as $c) {
            $seen++;
            $row = o2w_cat_map_row($c);
            if (empty($row['category_id'])) continue;
            if (o2w_cat_upsert($row)) $inserted++;
        }

        $page++;
        usleep($sleepMicros);
    }

    o2w_cat_set_cursor($page, $psize);

    $msg = sprintf('Categories: seen %d / upserts %d / errors %d. Cursor now at page %d (pageSize=%d).',
                   (int)$seen, (int)$inserted, (int)$errors, (int)$page, (int)$psize);
    wp_safe_redirect(add_query_arg(['o2w_notice'=>rawurlencode($msg)], admin_url('admin.php?page=s2m_magento')));
    exit;
});

/** =====================
 *  Render UI (categories)
 *  ===================== */
function o2w_render_magento_import_categories_section_ui() {
    if (!current_user_can(o2w_required_cap())) return;

    $remote = get_transient(O2W_TR_MAGENTO_CATEGORIES_TOTAL);
    $remote_count = is_array($remote) ? (int)$remote['count'] : null;
    $remote_age   = is_array($remote) ? max(0, time() - (int)$remote['ts']) : null;

    $m = o2w_cat_local_metrics();

    $pct = (is_int($remote_count) && $remote_count > 0)
        ? min(100, round(($m['total'] / $remote_count) * 100))
        : null;

    $cur = o2w_cat_get_cursor();

    $refresh_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_categories_refresh_total'),
        'o2w_magento_categories_refresh_total'
    );
    $fetch1000_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_fetch_categories&n=1000'),
        'o2w_magento_fetch_categories'
    );
    $fetch200_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_fetch_categories&n=200'),
        'o2w_magento_fetch_categories'
    );
    $reset_cursor_url = wp_nonce_url(
        admin_url('admin-post.php?action=o2w_magento_categories_reset_cursor'),
        'o2w_magento_categories_reset_cursor'
    );

    echo '<hr>';
    ?>
    <h2 class="title">Import from Magento — Categories (Step 6)</h2>
    <p class="description" style="max-width:800px;">
        Fetches the <strong>category master</strong> from Magento and stores it in
        <code><?php echo esc_html(o2w_cat_table()); ?></code>. Run in batches to avoid overloading the API.
    </p>

    <table class="form-table" role="presentation">
        <tbody>
        <tr>
            <th scope="row">Summary</th>
            <td>
                <?php if (is_int($remote_count)) : ?>
                    <div><strong>Total in Magento:</strong> <?php echo esc_html(number_format_i18n($remote_count)); ?>
                        <span class="description">
                            (cached<?php
                                if ($remote_age !== null) {
                                    echo ', ' . esc_html(human_time_diff(time() - $remote_age, time())) . ' ago';
                                }
                            ?>)
                        </span>
                    </div>
                <?php else : ?>
                    <div class="description">Magento categories total has not been queried yet.</div>
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
                <a href="<?php echo esc_url($fetch1000_url); ?>" class="button button-primary">Fetch categories (1000)</a>
                <a href="<?php echo esc_url($fetch200_url); ?>" class="button">Fetch categories (200)</a>
                <a href="<?php echo esc_url($reset_cursor_url); ?>" class="button button-secondary">Reset cursor</a>
                <p class="description" style="margin-top:6px;">
                    Requests run in batches of <code><?php echo (int)$cur['psize']; ?></code> per page with a short pause between pages.
                </p>
            </td>
        </tr>
        </tbody>
    </table>
    <?php
}

/** Backward-compatible alias */
if (!function_exists('o2w_render_magento_import_categories_section')) {
    function o2w_render_magento_import_categories_section() {
        o2w_render_magento_import_categories_section_ui();
    }
}
