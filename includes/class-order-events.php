<?php
// includes/class-order-events.php
if (!defined('ABSPATH')) exit;

class Orders2WhatsApp_Order_Events {
    private Orders2WhatsApp_Payload_Factory $factory;

    public function __construct(Orders2WhatsApp_Payload_Factory $factory) {
        $this->factory = $factory;
    }

    public function init(): void {
        // When order is created
        add_action('woocommerce_new_order', [$this, 'on_new_order_schedule'], 10, 1);

        // When order changes status
        add_action('woocommerce_order_status_changed', [$this, 'on_status_changed'], 10, 4);

        // Our deferred worker – runs after a small delay
        add_action('s2m_push_order_to_mautic', [$this, 'push_order_to_mautic'], 10, 2);
    }

    public function on_new_order_schedule($order_id): void {
        $this->schedule_push((int)$order_id, 'new');
    }

    public function on_status_changed($order_id, $old_status, $new_status, $order): void {
        $this->schedule_push((int)$order_id, 'status:' . $old_status . '>' . $new_status);
    }

    private function schedule_push(int $order_id, string $reason): void {
        if ($order_id <= 0) return;

        // Optional de-dup lock to avoid double scheduling bursts
        $lock_key = 's2m_mautic_lock_' . $order_id;
        if (get_transient($lock_key)) return;          // already scheduled very recently
        set_transient($lock_key, 1, 60);               // lock for up to 60s

        // Small delay so items/meta are fully saved by Woo
        $delay = (int) apply_filters('s2m_mautic_push_delay_seconds', 15);

        // If not already queued with same args, schedule it
        if (!wp_next_scheduled('s2m_push_order_to_mautic', [$order_id, $reason])) {
            wp_schedule_single_event(time() + $delay, 's2m_push_order_to_mautic', [$order_id, $reason]);
        }
    }

/**
 * Deferred worker: Mautic (siempre) + Zender (según políticas) con mensaje rico.
 */
public function push_order_to_mautic(int $order_id, string $reason = ''): void {
    if (!class_exists('WooCommerce')) return;
    $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
    if (!$order) return;

    // ===== 1) Mautic siempre
    if (class_exists('Orders2WhatsApp_Channel_Mautic')) {
        $mautic = new Orders2WhatsApp_Channel_Mautic();
        $ctxM = [
            'vars' => [
                'source'      => 'woocommerce',
                'order_id'    => (int) $order_id,
                'mautic_tags' => ['woocommerce','auto', $reason ?: ('status:'.(string)$order->get_status())],
            ],
        ];
        $mautic->send([], $ctxM);
    }

    // ===== 2) Política de estados para WhatsApp
    $allowed_statuses = apply_filters('orders2whatsapp_customer_notify_statuses', ['processing','completed','cancelled','refunded']);
    $status = (string) $order->get_status();
    $should_notify = in_array($status, (array)$allowed_statuses, true);

    // Pseudo-estado "created"
    if (!$should_notify && $reason === 'new') {
        $should_notify = in_array('created', (array)$allowed_statuses, true);
    }

    if (!$should_notify) {
        error_log('[o2w][zender] skip: status='.$status.' no está en políticas');
        return;
    }

    // ===== 3) Payload rico para Zender (builder especializado si existe; si no, fallback local)
    $payloadZ = [
        'customer_message' => '',
        'admin_message'    => '',
    ];

    $built = null;
    if (method_exists($this->factory, 'build_for_channels')) {
        try {
            $built = $this->factory->build_for_channels($order);
        } catch (\Throwable $e) {
            error_log('[o2w][zender] factory build_for_channels error: '.$e->getMessage());
        }
    }

    if (is_array($built) && !empty($built['zender'])) {
        // Respeta el builder del proyecto si existe
        $payloadZ = array_merge($payloadZ, $built['zender']);
    } else {
        // Fallback: mensaje completo generado aquí
        $payloadZ = $this->build_zender_payload_fallback($order, $reason);
    }

    // ===== 4) Teléfono cliente y envío
    $default_cc = (string) get_option('orders2whatsapp_default_country_code', '51');
    $rawPhone   = (string) $order->get_billing_phone();
    $to_customer= $rawPhone ? \Orders2WhatsApp\normalize_phone($rawPhone, $default_cc) : '';

    if (class_exists('Orders2WhatsApp_Channel_Zender')) {
        $zender = new Orders2WhatsApp_Channel_Zender();
        $ctxZ = [
            'notify'           => 1,                                    // admins on
            'send_to_customer' => $to_customer !== '' ? 1 : 0,
            'phone_intl'       => $to_customer,
            'vars'             => [
                'source'   => 'woocommerce',
                'order_id' => (int)$order_id,
                'status'   => $status,
            ],
        ];
        error_log('[o2w][zender] preflight url='.get_option('orders2whatsapp_api_url').' device='.get_option('orders2whatsapp_device_id'));
        $zender->send($payloadZ, $ctxZ);
    }
}

    /** === Fallback rico para WhatsApp si no hay builder del proyecto === */
    private function build_zender_payload_fallback(\WC_Order $order, string $reason): array {
        $old = ''; $new = (string)$order->get_status();
        if (strpos($reason, 'status:') === 0) {
            $spec = substr($reason, 7);
            if (strpos($spec, '>') !== false) {
                [$old, $new] = array_map('trim', explode('>', $spec, 2));
            } else {
                $new = trim($spec);
            }
        }
        $map = [
            'pending' => 'Pago pendiente', 'processing' => 'Procesando', 'on-hold' => 'En espera',
            'completed' => 'Completado', 'cancelled' => 'Cancelado', 'refunded' => 'Reembolsado',
            'failed' => 'Fallido', 'checkout-draft' => 'Borrador',
        ];
        $label = function($s) use ($map){ $s = (string)$s; return $map[$s] ?? $s; };
    
        $first = (string)$order->get_billing_first_name();
        $last  = (string)$order->get_billing_last_name();
        $name  = trim($first.' '.$last) ?: 'Cliente';
    
        $nro   = $order->get_order_number();
        $curr  = method_exists($order,'get_currency') ? $order->get_currency() : get_woocommerce_currency();
        $date  = $order->get_date_created() ? $order->get_date_created()->date_i18n('d/m/Y') : date_i18n('d/m/Y');
    
        $subtotal = (float)$order->get_subtotal();
        $discount = (float)$order->get_discount_total();
        $shipping = (float)$order->get_shipping_total();
        $total    = (float)$order->get_total();
    
        // Items
        $lines = [];
        foreach ($order->get_items() as $it) {
            $q = (int)$it->get_quantity();
            $t = (float)$it->get_total();
            $lines[] = '• ' . $it->get_name() . ' x' . $q . ' - ' . $curr . ' ' . number_format($t, 2);
        }
        $items_txt = implode("\n", $lines);
    
        // Direcciones
        $fmt_addr = function($o, $type){
            $fn = 'get_'.$type.'_first_name'; $ln = 'get_'.$type.'_last_name';
            $a1 = 'get_'.$type.'_address_1';  $a2 = 'get_'.$type.'_address_2';
            $ct = 'get_'.$type.'_city';       $st = 'get_'.$type.'_state'; $co = 'get_'.$type.'_country';
            $parts = array_filter([
                trim((string)$o->$fn().' '.(string)$o->$ln()),
                trim((string)$o->$a1().' '.(string)$o->$a2()),
                trim((string)$o->$ct().' '.(string)$o->$st()),
                (string)$o->$co(),
            ]);
            return implode("\n", $parts);
        };
        $bill = $fmt_addr($order, 'billing');
        $ship = $fmt_addr($order, 'shipping');
    
        // Encabezado (con flecha si tenemos old y new)
        $hdr = "🔔 Actualización de estado del pedido\n";
        if ($old !== '') {
            $hdr .= 'De '.$label($old).' ➜ '.$label($new)."\n\n";
        }
    
        $customer = $hdr
            . 'Hola ' . $first . " 👋\n\n"
            . "Tu pedido #{$nro} del {$date} ha cambiado de estado a " . $label($new) . " ✅\n\n"
            . "📦 Resumen del pedido:\n{$items_txt}\n\n"
            . "🧮 Subtotal: {$curr} " . number_format($subtotal, 2) . "\n"
            . "🏷 Descuento: {$curr} " . number_format($discount, 2) . "\n"
            . "🚚 Envío: {$curr} " . number_format($shipping, 2) . "\n"
            . "💰 Total: {$curr} " . number_format($total, 2) . "\n\n"
            . "📍 Dirección de facturación:\n{$bill}\n\n"
            . "📦 Dirección de envío:\n{$ship}\n\n"
            . "🙏 ¡Gracias por pedir en " . parse_url(home_url(), PHP_URL_HOST) . "!\n\n"
            . "👤 Tus datos de contacto:\n{$name}\n" . (string)$order->get_billing_email() . "\n" . (string)$order->get_billing_phone() . "\n\n"
            . "Este es un mensaje automatizado. Por favor, no responder a este número de WhatsApp.";
    
        $admin = "🧭 Pedido #{$nro} — " . $label($new) . "\n"
            . "{$name} • {$curr} " . number_format($total, 2) . "\n"
            . "Email: " . (string)$order->get_billing_email() . " | Tel: " . (string)$order->get_billing_phone() . "\n\n"
            . "Items:\n{$items_txt}";
    
        return [
            'customer_message' => $customer,
            'admin_message'    => $admin,
        ];
    }
}
