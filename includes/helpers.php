<?php
namespace Orders2WhatsApp;

function normalize_phone($raw, $default_cc = '51') {
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if (!$digits) return '';
    if (strlen($digits) == 9) $digits = $default_cc.$digits;
    if (substr($digits,0,2) !== $default_cc) $digits = $default_cc.$digits;
    return '+'.$digits;
}

function fmt_price($amount, $currency) {
    return strip_tags(html_entity_decode( wc_price($amount, ['currency'=>$currency]) ));
}

// ...tus helpers previos con namespace Orders2WhatsApp aquí...

/**
 * Construye campos para Mautic a partir de un order_id de Woo:
 * - Totales históricos por email (cantidad de órdenes y monto acumulado)
 * - Detalle del último pedido (este), con lista de productos (nombre, url, img, categorías)
 * - Categorías únicas del pedido
 * - Campos core: email, firstname, lastname, phone
 *
 * @return array [ 'lead_fields' => [...], 'note_text' => '...', 'raw' => [...] ]
 */
function o2w_build_mautic_contact_payload_from_order(int $order_id, array $aliases = []): array {
    $order = wc_get_order($order_id);
    if (!$order || !is_a($order, 'WC_Order')) {
        error_log('[o2w] o2w_build_mautic_contact_payload_from_order: WC_Order inválido id='.$order_id);
        return ['lead_fields'=>[], 'note_text'=>'', 'raw'=>[]];
    }

    // ====== Core cliente
    $email     = (string) $order->get_billing_email();
    $firstname = (string) $order->get_billing_first_name();
    $lastname  = (string) $order->get_billing_last_name();
    $phoneRaw  = (string) $order->get_billing_phone();

    // Normaliza +51 si falta (puedes usar tu normalize_phone namespaced si quieres)
    $digits = preg_replace('/\D+/', '', $phoneRaw);
    if ($digits) {
        if (strlen($digits) === 9) $digits = '51'.$digits;
        if (substr($digits,0,2) !== '51') $digits = '51'.$digits;
        $phoneIntl = '+'.$digits;
    } else {
        $phoneIntl = '';
    }

    // ====== Histórico por email
    $hist_count  = 0;
    $hist_amount = 0.0;
    if ($email) {
        $orders = wc_get_orders([
            'billing_email' => $email,
            'limit'         => -1,
            'orderby'       => 'date',
            'order'         => 'DESC',
            // si el dataset es enorme, aquí podrías limitar y sumar con otra estrategia
        ]);
        foreach ($orders as $o) {
            $hist_count  += 1;
            $hist_amount += (float) $o->get_total();
        }
    }

    // ====== Detalle del pedido actual
    $currency   = method_exists($order,'get_currency') ? $order->get_currency() : get_woocommerce_currency();
    $oDate      = $order->get_date_created();
    $dateStr    = $oDate ? $oDate->date_i18n('Y-m-d') : date_i18n('Y-m-d');
    $timeStr    = $oDate ? $oDate->date_i18n('H:i:s') : date_i18n('H:i:s');

    $productos  = [];
    $allCats    = [];

    foreach ($order->get_items() as $item) {
        /** @var WC_Product|false $product */
        $product  = $item->get_product();
        $cats     = [];
        $img_url  = '';
        $permalink= '';

        if ($product) {
            $cats = wp_get_post_terms($product->get_id(), 'product_cat', ['fields'=>'names']) ?: [];
            $img_id = $product->get_image_id();
            if ($img_id) $img_url = wp_get_attachment_url($img_id) ?: '';
            $permalink = $product->get_permalink() ?: '';
        }

        $productos[] = [
            'nombre'       => $item->get_name(),
            'url_producto' => $permalink,
            'url_image'    => $img_url,
            'cantidad'     => (int) $item->get_quantity(),
            'total'        => (float) $item->get_total(),
            'categorias'   => $cats,
        ];

        foreach ($cats as $c) { $allCats[$c] = true; }
    }

    $cats_ult_pedido = implode(', ', array_keys($allCats));

    // ====== Aliases (ajusta a los tuyos en Mautic)
    $defaults = [
        'email'                   => 'email',
        'firstname'               => 'firstname',
        'lastname'                => 'lastname',
        'phone'                   => 'phone',

        'cf_last_order_id'        => 'last_order_id',
        'cf_last_order_date'      => 'last_purchase_date', // datetime
        'cf_last_order_total'     => 'last_order_amount',
        'cf_last_order_currency'  => 'last_order_currency',

        'cf_hist_total_amount'    => 'historic_purch_amount',
        'cf_hist_total_orders'    => 'historic_purch_count',
        'cf_last_categories'      => 'last_order_categories',

        'cf_last_items_json'      => 'last_order_items_json', // textarea largo
        'cf_last_snapshot_json'   => 'last_order_json',       // textarea largo
    ];
    $map = array_merge($defaults, $aliases);

    // ====== Campos flat (formato que Mautic espera)
    $lead_fields = [
        $map['email']                  => $email,
        $map['firstname']              => $firstname,
        $map['lastname']               => $lastname,
        $map['phone']                  => $phoneIntl ?: $phoneRaw,

        $map['cf_last_order_id']       => $order->get_id(),
        $map['cf_last_order_date']     => $dateStr . ' ' . $timeStr,
        $map['cf_last_order_total']    => (float) $order->get_total(),
        $map['cf_last_order_currency'] => $currency,

        $map['cf_hist_total_amount']   => $hist_amount,
        $map['cf_hist_total_orders']   => $hist_count,
        $map['cf_last_categories']     => $cats_ult_pedido,

        $map['cf_last_items_json']     => wp_json_encode($productos, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        $map['cf_last_snapshot_json']  => wp_json_encode([
            'order_id'   => $order->get_id(),
            'status'     => $order->get_status(),
            'currency'   => $currency,
            'subtotal'   => (float) $order->get_subtotal(),
            'discount'   => (float) $order->get_discount_total(),
            'shipping'   => (float) $order->get_shipping_total(),
            'total'      => (float) $order->get_total(),
            'date'       => $dateStr,
            'time'       => $timeStr,
            'items'      => $productos,
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ];

    // ====== Nota (opcional) legible para timeline
    $note_lines = [];
    $note_lines[] = 'Pedido #'.$order->get_order_number().' — '.$order->get_status();
    $note_lines[] = 'Fecha/Hora: '.$dateStr.' '.$timeStr;
    $note_lines[] = 'Total: '.$order->get_currency().' '.number_format((float)$order->get_total(), 2);
    if ($cats_ult_pedido) $note_lines[] = 'Categorías: '.$cats_ult_pedido;
    $note_lines[] = 'Items:';
    foreach ($productos as $p) {
        $note_lines[] = '- '.$p['nombre'].' x'.$p['cantidad'].' = '.number_format($p['total'], 2);
    }
    $note_text = implode("\n", $note_lines);

    return [
        'lead_fields' => $lead_fields,
        'note_text'   => $note_text,
        'raw'         => [
            'email' => $email,
            'hist_count' => $hist_count,
            'hist_amount'=> $hist_amount,
            'productos'  => $productos,
            'cats'       => $cats_ult_pedido,
        ],
    ];
}

