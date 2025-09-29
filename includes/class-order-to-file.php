<?php
class Order_To_File {

    public function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        // Dispara en cualquier cambio de estado
        // Firma: ($order_id, $old_status, $new_status, $order)
        add_action('woocommerce_order_status_changed', [$this, 'save_order_to_file'], 10, 4);
    }

    /**
     * Guarda el resumen a archivo y envía WhatsApp cuando cambie el estado.
     *
     * @param int        $order_id
     * @param string     $old_status   (slug, ej: pending)
     * @param string     $new_status   (slug, ej: processing)
     * @param WC_Order   $order_obj    (puede venir seteado por Woo)
     */
    public function save_order_to_file($order_id, $old_status = null, $new_status = null, $order_obj = null) {
        $order = $order_obj ?: wc_get_order($order_id);
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }

        // Bonitos (ej: 'Processing' en vez de 'processing')
        $pretty_old = $old_status ? wc_get_order_status_name('wc-' . $old_status) : '';
        $pretty_new = $new_status ? wc_get_order_status_name('wc-' . $new_status) : '';

        $customer_name  = $order->get_billing_first_name();
        $order_number   = $order->get_order_number();
        $order_date     = $order->get_date_created();
        $currency       = method_exists($order, 'get_currency') ? $order->get_currency() : 'PEN';

        $formatted_date = $order_date ? $order_date->format('d/m/Y') : date('d/m/Y');
        $formatted_time = $order_date ? $order_date->format('H:i')     : date('H:i');

        $full_name     = $order->get_formatted_billing_full_name();
        $billing_email = $order->get_billing_email();
        $billing_phone = $order->get_billing_phone();

        // Encabezado con cambio de estado
        $header = '';
        if ($pretty_new) {
            $header = "🔔 *Actualización de estado del pedido*\n";
            if ($pretty_old) {
                $header .= "De *{$pretty_old}* ➜ *{$pretty_new}*\n\n";
            } else {
                $header .= "Nuevo estado: *{$pretty_new}*\n\n";
            }
        }

        // Contenido base del cliente (reutiliza tu formato)
        $content  = "Hola $customer_name 👋\n\n";
        $content .= "Tu pedido #$order_number del $formatted_date ha cambiado de estado";
        $content .= $pretty_new ? " a *{$pretty_new}* ✅" : "";
        $content .= "\n\n📦 *Resumen del pedido:*\n";

        foreach ($order->get_items() as $item) {
            $product_name = $item->get_name();
            $quantity     = $item->get_quantity();
            $price        = $item->get_subtotal();
            $price_fmt    = wc_price($price, ['currency' => $currency]);
            $price_clean  = strip_tags(html_entity_decode($price_fmt));
            $content     .= "• $product_name x$quantity - $price_clean\n";
        }

        $subtotal = strip_tags(html_entity_decode(wc_price($order->get_subtotal(),      ['currency' => $currency])));
        $discount = strip_tags(html_entity_decode(wc_price($order->get_discount_total(), ['currency' => $currency])));
        $shipping = $order->get_shipping_total() > 0
            ? strip_tags(html_entity_decode(wc_price($order->get_shipping_total(), ['currency' => $currency])))
            : 'Envío gratuito';
        $total    = strip_tags(html_entity_decode(wc_price($order->get_total(),         ['currency' => $currency])));

        $content .= "\n🧮 *Subtotal:* $subtotal";
        $content .= "\n🏷️ *Descuento:* $discount";
        $content .= "\n🚚 *Envío:* $shipping";
        $content .= "\n💰 *Total:* $total\n";

        if ($order->get_customer_note()) {
            $content .= "\n📝 *Nota del cliente:*\n" . $order->get_customer_note() . "\n";
        }

        $billing_addr   = $order->get_formatted_billing_address();
        $shipping_addr  = $order->get_formatted_shipping_address();

        $content .= "\n📍 *Dirección de facturación:*\n" . strip_tags($billing_addr) . "\n";
        $content .= "\n📦 *Dirección de envío:*\n" . strip_tags($shipping_addr) . "\n";

        $content .= "\n🙏 ¡Gracias por pedir en www.donitalo.com!";

        $content .= "\n\n👤 *Tus datos de contacto:*\n";
        $content .= "$full_name\n";
        $content .= "$billing_email\n";
        $content .= "$billing_phone\n";

        $content .= "\n\n*Este es un mensaje automatizado. Por favor, no responder a este número de WhatsApp.*";
        $content .= "\n*Si tienes consultas sobre tu pedido, escríbenos por WhatsApp al 51998128448 o al email pedidos@donitalo.com.*";

        // Une header + content para el mensaje final
        $message_for_customer = $header . $content;

        // === Guardado a archivo (uno por evento) ===
        $upload_dir      = wp_upload_dir();
        $order_files_dir = $upload_dir['basedir'] . '/7c-shop2mautic';

        if (!file_exists($order_files_dir)) {
            wp_mkdir_p($order_files_dir);
        }

        $suffix    = ($new_status ?: 'status') . '-' . current_time('Ymd_His');
        $file_path = $order_files_dir . '/order-' . $order->get_id() . '-' . $suffix . '.txt';

        if (false === file_put_contents($file_path, $message_for_customer)) {
            error_log('Failed to write order file for order ' . $order->get_id());
        }

        // === Envío a cliente: solo ciertos estados por defecto (evitar spam) ===
        $notify_customer_statuses = apply_filters(
            'orders2whatsapp_customer_notify_statuses',
            ['processing', 'completed', 'cancelled', 'refunded'] // slugs
        );

        if ($new_status && in_array($new_status, $notify_customer_statuses, true)) {
            $customer_phone = $order->get_billing_phone();
            if ($customer_phone) {
                $formatted = preg_replace('/[^0-9]/', '', $customer_phone);
                if (strlen($formatted) >= 9) {
                    if (strlen($formatted) == 9) { $formatted = '51' . $formatted; }
                    if (substr($formatted, 0, 2) !== '51') { $formatted = '51' . $formatted; }
                    $formatted = '+' . $formatted;
                    WhatsApp_Sender::send_message($formatted, $message_for_customer);
                }
            }
        }

        // === Mensaje especial para administradores (siempre) ===
        $admin_message  = "🔔 *Cambio de estado de pedido*\n";
        $admin_message .= "Pedido #$order_number — $formatted_date $formatted_time\n";
        if ($pretty_old || $pretty_new) {
            $admin_message .= "Estado: " . ($pretty_old ? "*$pretty_old*" : "—") . " ➜ " . ($pretty_new ? "*$pretty_new*" : "—") . "\n\n";
        } else {
            $admin_message .= "\n";
        }
        $admin_message .= "👤 *Cliente:* $full_name\n";
        $admin_message .= "✉️ $billing_email\n";
        $admin_message .= "📞 $billing_phone\n\n";
        $admin_message .= $content; // reusa el detalle
        $admin_message .= "\n---\nEste es un mensaje automatizado, no responder a este número de WhatsApp.";

        $admin_numbers = get_option('orders2whatsapp_admin_numbers');
        if ($admin_numbers) {
            $numbers = array_map('trim', explode(',', $admin_numbers));
            foreach ($numbers as $number) {
                $formatted_admin = '+' . ltrim($number, '+');
                WhatsApp_Sender::send_message($formatted_admin, $admin_message);
            }
        }
    }
}
