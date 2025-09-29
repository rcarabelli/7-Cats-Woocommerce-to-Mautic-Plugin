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
        $this->schedule_push((int)$order_id, 'status:' . $new_status);
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
     * Deferred worker: call Mautic channel with ctx['vars']['order_id'].
     */
    public function push_order_to_mautic(int $order_id, string $reason = ''): void {
        if (!class_exists('WooCommerce')) return;
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) return;

        // Build other channel payloads if you use them (Zender/File), not needed for Mautic
        // $payloads = $this->factory->build_for_channels($order);

        if (class_exists('Orders2WhatsApp_Channel_Mautic')) {
            $mautic = new Orders2WhatsApp_Channel_Mautic();
            $ctx = [
                'vars' => [
                    'source'      => 'woocommerce',
                    'order_id'    => (int) $order_id,
                    'mautic_tags' => ['woocommerce','auto',$reason], // optional
                ],
            ];
            // Channel rebuilds rich fields from the order id
            $mautic->send([], $ctx);
        }
    }
}
