<?php
class Orders2WhatsApp_Payload_Factory {

    public function build(string $channel_key, array $vars): array {
        switch ($channel_key) {
            case Orders2WhatsApp_Channel_Zender::KEY:
                return $this->build_zender($vars);

            case Orders2WhatsApp_Channel_File::KEY:
                return $this->build_file($vars);

            case Orders2WhatsApp_Channel_Mautic::KEY:
                return $this->build_mautic($vars);

            default:
                return [];
        }
    }

    private function build_zender(array $v): array {
        $header = "🔔 *Actualización de estado del pedido*\n";
        $header .= ($v['pretty_old'] ? "De *{$v['pretty_old']}* ➜ *{$v['pretty_new']}*\n\n" : "Nuevo estado: *{$v['pretty_new']}*\n\n");

        $lines = [];
        foreach ($v['items'] as $it) {
            $lines[] = "• {$it['name']} x{$it['qty']} - {$it['subtotal']}";
        }
        $content  = "Hola {$v['first_name']} 👋\n\n";
        $content .= "Tu pedido #{$v['order_number']} del {$v['date']} ha cambiado de estado a *{$v['pretty_new']}* ✅\n\n";
        $content .= "📦 *Resumen del pedido:*\n" . implode("\n", $lines) . "\n";
        $content .= "\n🧮 *Subtotal:* {$v['subtotal']}";
        $content .= "\n🏷️ *Descuento:* {$v['discount']}";
        $content .= "\n🚚 *Envío:* {$v['shipping']}";
        $content .= "\n💰 *Total:* {$v['total']}\n";
        if (!empty($v['note'])) {
            $content .= "\n📝 *Nota del cliente:*\n{$v['note']}\n";
        }
        $content .= "\n📍 *Dirección de facturación:*\n{$v['billing_addr']}\n";
        $content .= "\n📦 *Dirección de envío:*\n{$v['shipping_addr']}\n";
        $content .= "\n🙏 ¡Gracias por pedir en www.donitalo.com!";
        $content .= "\n\n👤 *Tus datos de contacto:*\n{$v['full_name']}\n{$v['email']}\n{$v['phone_raw']}\n";
        $content .= "\n\n*Este es un mensaje automatizado. Por favor, no responder a este número de WhatsApp.*";
        $content .= "\n*Si tienes consultas sobre tu pedido, escríbenos por WhatsApp al 51998128448 o al email pedidos@donitalo.com.*";

        return [
            'customer_message' => $header . $content,
            'admin_message'    => "🔔 *Cambio de estado de pedido*\nPedido #{$v['order_number']} — {$v['date']} {$v['time']}\nEstado: " .
                                  ($v['pretty_old'] ? "*{$v['pretty_old']}*" : "—") . " ➜ *{$v['pretty_new']}*\n\n" .
                                  "👤 *Cliente:* {$v['full_name']}\n✉️ {$v['email']}\n📞 {$v['phone_raw']}\n\n" .
                                  $content . "\n---\nEste es un mensaje automatizado, no responder a este número de WhatsApp.",
        ];
    }

    private function build_file(array $v): array {
        $suffix = ($v['new_status'] ?: 'status') . '-' . current_time('Ymd_His');
        $filename = 'order-' . $v['order_id'] . '-' . $suffix . '.txt';
        return [
            'filename' => $filename,
            'contents' => $this->build_zender($v)['customer_message'], // reusa el mismo texto amigable
        ];
    }

    private function build_mautic(array $v): array {
        // JSON limpio para integraciones
        return [
            'json' => [
                'order' => [
                    'id'          => $v['order_id'],
                    'number'      => $v['order_number'],
                    'date'        => $v['date'],
                    'time'        => $v['time'],
                    'currency'    => $v['currency'],
                    'subtotal'    => $v['subtotal'],
                    'discount'    => $v['discount'],
                    'shipping'    => $v['shipping'],
                    'total'       => $v['total'],
                    'items'       => $v['items'], // array con name, qty, subtotal
                ],
                'status' => [
                    'old'   => $v['old_status'],
                    'new'   => $v['new_status'],
                    'label' => $v['pretty_new'],
                ],
                'customer' => [
                    'full_name'   => $v['full_name'],
                    'email'       => $v['email'],
                    'phone_raw'   => $v['phone_raw'],
                    'phone_intl'  => $v['phone_intl'],
                    'billing'     => $v['billing_addr'],
                    'shipping'    => $v['shipping_addr'],
                ],
            ],
        ];
    }
}
