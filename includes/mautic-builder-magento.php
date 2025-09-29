<?php
// includes/mautic-builder-magento.php
namespace Orders2WhatsApp;

if (!defined('ABSPATH')) exit;

/** Helpers nombre de tablas ya existentes en install-magento-db.php */
if (!function_exists('o2w_magento_orders_table'))     { function o2w_magento_orders_table()     { global $wpdb; return $wpdb->prefix . 'o2w_magento_orders'; } }
if (!function_exists('o2w_magento_order_items_table')){ function o2w_magento_order_items_table(){ global $wpdb; return $wpdb->prefix . 'o2w_magento_order_items'; } }
if (!function_exists('o2w_magento_products_table'))   { function o2w_magento_products_table()   { global $wpdb; return $wpdb->prefix . 'o2w_magento_products'; } }
if (!function_exists('o2w_magento_customers_table'))  { function o2w_magento_customers_table()  { global $wpdb; return $wpdb->prefix . 'o2w_magento_customers'; } }
if (!function_exists('o2w_magento_categories_table')) { function o2w_magento_categories_table() { global $wpdb; return $wpdb->prefix . 'o2w_magento_categories'; } }

/** Opcionales para armar URLs públicas (si las tenés en ajustes) */
function o2w_magento_store_base_url(): string {
    $u = rtrim((string) get_option('o2w_magento_store_base_url', ''), '/');
    return $u;
}
function o2w_magento_media_base_url(): string {
    $u = rtrim((string) get_option('o2w_magento_media_base_url', ''), '/');
    return $u;
}
function o2w_magento_product_url(?string $url_key): ?string {
    if (!$url_key) return null;
    $base = o2w_magento_store_base_url();
    $url  = ($base ? $base : '') . '/' . ltrim($url_key, '/');
    if (!str_ends_with($url, '.html')) $url .= '.html';
    /** Permite override fino desde theme/plugin */
    return apply_filters('o2w_magento_product_url', $url, $url_key, $base);
}
function o2w_magento_image_url(?string $image): ?string {
    if (!$image) return null;
    if (preg_match('#^https?://#i', $image)) return $image;
    $base = o2w_magento_media_base_url();
    $url  = ($base ? $base : '') . '/' . ltrim($image, '/');
    return apply_filters('o2w_magento_image_url', $url, $image, $base);
}

/**
 * Builder principal: arma el payload para Mautic desde un pedido Magento (entity_id)
 * Devuelve: ['lead_fields'=>[], 'note_text'=>string]
 */
function o2w_build_mautic_contact_payload_from_magento_order(int $entity_id, array $opts = []): array {
    global $wpdb;

    $orders = o2w_magento_orders_table();
    $itemsT = o2w_magento_order_items_table();
    $prodsT = o2w_magento_products_table();
    $custsT = o2w_magento_customers_table();
    $catsT  = o2w_magento_categories_table();

    $order = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$orders} WHERE entity_id = %d", $entity_id),
        ARRAY_A
    );
    if (!$order) {
        return ['lead_fields'=>[], 'note_text'=>"Magento: no se encontró order entity_id={$entity_id}"];
    }

    // ---- Datos de contacto
    $email = trim((string)($order['customer_email'] ?? ''));
    $cust  = null;
    if ($email !== '') {
        $cust = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$custsT} WHERE email = %s", $email),
            ARRAY_A
        );
    } elseif (!empty($order['customer_id'])) {
        $cust = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$custsT} WHERE customer_id = %d", (int)$order['customer_id']),
            ARRAY_A
        );
        if ($cust && empty($email)) $email = trim((string)$cust['email']);
    }

    // Nombre
    $first = $cust['first_name'] ?? '';
    $last  = $cust['last_name']  ?? '';
    if ((!$first && !$last) && !empty($cust['full_name'])) {
        $fn = trim((string)$cust['full_name']);
        if ($fn !== '') {
            $parts = preg_split('/\s+/', $fn);
            $first = $parts ? array_shift($parts) : '';
            $last  = $parts ? implode(' ', $parts) : '';
        }
    }
    $phone = $cust['phone'] ?? '';

    // ---- Ítems de pedido
    $lines = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$itemsT} WHERE order_entity_id = %d", $entity_id),
        ARRAY_A
    ) ?: [];

    $pids = [];
    $skus = [];
    foreach ($lines as $l) {
        if (!empty($l['product_id'])) $pids[(int)$l['product_id']] = true;
        if (!empty($l['sku']))        $skus[trim((string)$l['sku'])] = true;
    }

    // Traigo productos en un sólo tiro por product_id y/o sku
    $prodById  = [];
    $prodBySku = [];
    if ($pids) {
        $place = implode(',', array_fill(0, count($pids), '%d'));
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$prodsT} WHERE product_id IN ($place)", array_keys($pids)),
            ARRAY_A
        );
        foreach ($rows as $r) $prodById[(int)$r['product_id']] = $r;
    }
    if ($skus) {
        $place = implode(',', array_fill(0, count($skus), '%s'));
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$prodsT} WHERE sku IN ($place)", array_keys($skus)),
            ARRAY_A
        );
        foreach ($rows as $r) $prodBySku[trim((string)$r['sku'])] = $r;
    }

    // ---- Categorías (ids → nombre)
    $catIds = [];
    foreach ($lines as $l) {
        $p = null;
        if (!empty($l['product_id']) && isset($prodById[(int)$l['product_id']])) {
            $p = $prodById[(int)$l['product_id']];
        } elseif (!empty($l['sku']) && isset($prodBySku[trim((string)$l['sku'])])) {
            $p = $prodBySku[trim((string)$l['sku'])];
        }
        if ($p && !empty($p['categories_json'])) {
            $ids = json_decode((string)$p['categories_json'], true);
            if (is_array($ids)) {
                foreach ($ids as $cid) { $cid = (int)$cid; if ($cid) $catIds[$cid] = true; }
            }
        }
    }
    $catNames = [];
    if ($catIds) {
        $place = implode(',', array_fill(0, count($catIds), '%d'));
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT category_id, name FROM {$catsT} WHERE category_id IN ($place)", array_keys($catIds)),
            ARRAY_A
        );
        foreach ($rows as $r) {
            $n = trim((string)$r['name']);
            if ($n !== '') $catNames[$n] = true;
        }
    }
    $last_ord_prod_cat = $catNames ? implode(', ', array_keys($catNames)) : '';

    // ---- last_ord_products: "name | url | image"
    $last_ord_products_lines = [];
    foreach ($lines as $l) {
        $name = trim((string)($l['product_name'] ?? ''));
        $sku  = isset($l['sku']) ? trim((string)$l['sku']) : '';
        $pid  = isset($l['product_id']) ? (int)$l['product_id'] : 0;

        $pRow = null;
        if ($pid && isset($prodById[$pid]))         $pRow = $prodById[$pid];
        elseif ($sku !== '' && isset($prodBySku[$sku])) $pRow = $prodBySku[$sku];

        $url   = $pRow ? o2w_magento_product_url($pRow['url_key'] ?? null) : null;
        $image = $pRow ? o2w_magento_image_url($pRow['image']    ?? null) : null;

        // Saneamos separadores
        $name  = str_replace('|','/',$name);
        $url   = $url   ? str_replace('|','/',$url)   : '';
        $image = $image ? str_replace('|','/',$image) : '';

        if ($name || $url || $image) {
            $last_ord_products_lines[] = trim($name . ' | ' . $url . ' | ' . $image);
        }
    }
    $last_ord_products = implode("\n", $last_ord_products_lines);

    // ---- Historia rápida por email (opcional)
    $historic_amount = null;
    $historic_count  = null;
    if ($email !== '') {
        $hist = $wpdb->get_row(
            $wpdb->prepare("SELECT COUNT(*) AS c, SUM(grand_total) AS s FROM {$orders} WHERE customer_email = %s", $email),
            ARRAY_A
        );
        if ($hist) {
            $historic_count  = isset($hist['c']) ? (int)$hist['c'] : null;
            $historic_amount = isset($hist['s']) ? (int)round((float)$hist['s']) : null;
        }
    }

    // ---- Campos finales (coinciden con los que tu canal espera antes del remap)
    $lead_fields = array_filter([
        'firstname'             => $first ?: null,
        'lastname'              => $last  ?: null,
        'email'                 => $email ?: null,
        'phone'                 => $phone ?: null,

        'last_order_id'         => $order['increment_id'] ?: (string)$order['entity_id'],
        'last_order_amount'     => isset($order['grand_total']) ? (int)round((float)$order['grand_total']) : null,
        'last_purchase_date'    => !empty($order['created_at']) ? gmdate('Y-m-d H:i:s', strtotime($order['created_at'])) : null,
        'last_order_status'     => $order['status'] ?? null,

        'last_ord_prod_cat'     => $last_ord_prod_cat ?: null,
        'last_ord_products'     => $last_ord_products ?: null,

        // estos dos caen en tus aliases de Mautic via remap()
        'historic_purch_amount' => $historic_amount,
        'historic_purch_count'  => $historic_count,
    ], static function($v){ return $v !== null && $v !== ''; });

    // ---- Nota opcional
    $note_text = sprintf(
        "Magento order %s\nMonto: %s\nItems: %d\nFecha: %s\nEstado: %s",
        $lead_fields['last_order_id'] ?? $order['entity_id'],
        (string)($order['grand_total'] ?? '-'),
        count($lines),
        !empty($order['created_at']) ? $order['created_at'] : '-',
        (string)($order['status'] ?? '-')
    );

    return ['lead_fields' => $lead_fields, 'note_text' => $note_text];
}
