<?php
class Orders2WhatsApp_Order_Events {

    public function __construct(private Orders2WhatsApp_Payload_Factory $factory) {}

    public function init() {
        error_log('[o2w] init(): registrando hooks');
        add_action('woocommerce_order_status_changed', [$this, 'on_status_changed'], 10, 4);
        add_action('woocommerce_new_order',          [$this, 'on_new_order'], 10, 1);
    }

    public function on_new_order($order_id) {
        error_log('[o2w] on_new_order: order_id='.$order_id);
        $this->handle_event($order_id, '', 'created', null);
    }

    public function on_status_changed($order_id, $old_status, $new_status, $order_obj) {
        error_log("[o2w] on_status_changed: id=$order_id old=$old_status new=$new_status");
        $this->handle_event($order_id, $old_status, $new_status, $order_obj);
    }

    /**
     * Maneja la creación/cambio de estado de un pedido y despacha a los canales.
     *
     * @param int        $order_id
     * @param string     $old_status  Slug anterior (ej: 'pending')
     * @param string     $new_status  Slug nuevo (ej: 'processing' o 'created' si lo forzamos en on_new_order)
     * @param WC_Order   $order_obj   (opcional) instancia ya creada que provee Woo
     */
/**
 * Maneja la creación/cambio de estado de un pedido y despacha a los canales.
 *
 * @param int        $order_id
 * @param string     $old_status  Slug anterior (ej: 'pending')
 * @param string     $new_status  Slug nuevo (ej: 'processing' o 'created')
 * @param WC_Order   $order_obj   (opcional) instancia ya creada por WooCommerce
 */
private function handle_event($order_id, $old_status = '', $new_status = '', $order_obj = null) {
    error_log("[o2w] handle_event: start id=$order_id old=$old_status new=$new_status");

    // 0) Asegurar objeto
    $order = $order_obj ?: wc_get_order($order_id);
    if (!$order || !is_a($order, 'WC_Order')) {
        error_log('[o2w] handle_event: WC_Order inválido para ID ' . $order_id);
        return;
    }

    // 1) Vars base
    $vars = $this->collect_base_vars($order, $old_status, $new_status);
    error_log('[o2w] handle_event: vars colectadas phone_intl=' . ($vars['phone_intl'] ?? '') . ' total=' . ($vars['total'] ?? ''));

    // 2) Canales
    $default_channels = [
        new Orders2WhatsApp_Channel_File(),   // TXT (siempre)
        new Orders2WhatsApp_Channel_Zender(), // WhatsApp (filtrado por estados)
        new Orders2WhatsApp_Channel_Mautic(), // Mautic (siempre)
    ];
    $channels = apply_filters('orders2whatsapp_active_channels', $default_channels, $vars);
    error_log('[o2w] handle_event: canales activos=' . implode(',', array_map(fn($c)=>$c::key(), $channels)));

    // 3) Estados a notificar (solo WhatsApp)
    $notify_statuses = get_option('orders2whatsapp_notify_statuses');
    if (!is_array($notify_statuses) || empty($notify_statuses)) {
        $notify_statuses = ['processing','completed','cancelled','refunded','created'];
    }
    $notify_statuses = apply_filters('orders2whatsapp_customer_notify_statuses', $notify_statuses);

    // 4) ¿Notifica WA?
    $notify_this_status = in_array($new_status, $notify_statuses, true) || $new_status === 'created';
    error_log('[o2w] handle_event: notify_statuses='.implode('|',$notify_statuses).' -> notify=' . ($notify_this_status?'1':'0'));

    // 5) Teléfono
    $phone_intl = !empty($vars['phone_intl']) ? $vars['phone_intl'] : '';

    // 6) Ctx
    $ctx = [
        'notify'           => $notify_this_status,
        'send_to_customer' => $notify_this_status,
        'new_status'       => $new_status,
        'old_status'       => $old_status,
        'phone_intl'       => $phone_intl,
        'vars'             => $vars,
    ];

    // 7) Payload + send
    foreach ((array)$channels as $channel) {
        if (!is_object($channel) || !method_exists($channel, 'send') || !method_exists($channel, 'key')) {
            error_log('[o2w] handle_event: canal inválido, se omite');
            continue;
        }

        $key = $channel::key();
        error_log('[o2w] handle_event: building payload for '.$key);

        try {
            $payload = $this->factory->build($key, $vars);
            // resumen mínimo para debug
            $desc = is_array($payload) ? implode(',', array_keys($payload)) : gettype($payload);
            error_log("[o2w] handle_event: payload($key) keys=$desc");
        } catch (\Throwable $e) {
            error_log('[o2w] handle_event: build error '.$key.' -> '.$e->getMessage());
            continue;
        }

        try {
            error_log('[o2w] handle_event: sending via '.$key);
            $channel->send($payload, $ctx);
            error_log('[o2w] handle_event: sent '.$key.' OK');
        } catch (\Throwable $e) {
            error_log('[o2w] handle_event: send error '.$key.' -> '.$e->getMessage());
        }
    }

    error_log("[o2w] handle_event: end id=$order_id");
}

    private function collect_base_vars(WC_Order $order, $old_status, $new_status) {
        $currency         = method_exists($order,'get_currency') ? $order->get_currency() : get_woocommerce_currency();
        $order_date       = $order->get_date_created();
        $formatted_date   = $order_date ? $order_date->date_i18n('d/m/Y') : date_i18n('d/m/Y');
        $formatted_time   = $order_date ? $order_date->date_i18n('H:i')   : date_i18n('H:i');

        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = [
                'name'     => $item->get_name(),
                'qty'      => $item->get_quantity(),
                'subtotal' => Orders2WhatsApp\fmt_price($item->get_subtotal(), $currency),
            ];
        }

        return [
            // Estado
            'old_status'   => $old_status,
            'new_status'   => $new_status,
            'pretty_old'   => $old_status ? wc_get_order_status_name('wc-'.$old_status) : '',
            'pretty_new'   => $new_status ? wc_get_order_status_name('wc-'.$new_status) : '',

            // Pedido
            'order_id'     => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'date'         => $formatted_date,
            'time'         => $formatted_time,
            'currency'     => $currency,
            'subtotal'     => Orders2WhatsApp\fmt_price($order->get_subtotal(), $currency),
            'discount'     => Orders2WhatsApp\fmt_price($order->get_discount_total(), $currency),
            'shipping'     => $order->get_shipping_total() > 0 ? Orders2WhatsApp\fmt_price($order->get_shipping_total(), $currency) : 'Envío gratuito',
            'total'        => Orders2WhatsApp\fmt_price($order->get_total(), $currency),
            'items'        => $items,
            'note'         => $order->get_customer_note(),

            // Cliente
            'first_name'   => $order->get_billing_first_name(),
            'full_name'    => $order->get_formatted_billing_full_name(),
            'email'        => $order->get_billing_email(),
            'phone_raw'    => $order->get_billing_phone(),
            'phone_intl'   => Orders2WhatsApp\normalize_phone($order->get_billing_phone()),

            // Direcciones
            'billing_addr'  => wp_strip_all_tags($order->get_formatted_billing_address()),
            'shipping_addr' => wp_strip_all_tags($order->get_formatted_shipping_address()),
        ];
    }
}
