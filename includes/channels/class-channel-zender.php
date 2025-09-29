<?php
class Orders2WhatsApp_Channel_Zender implements Orders2WhatsApp_Channel_Interface {
    public const KEY = 'zender';
    public static function key(): string { return self::KEY; }

    public function send(array $payload, array $ctx = []): void {
        $api_url    = get_option('orders2whatsapp_api_url');
        $api_secret = get_option('orders2whatsapp_api_secret');
        $device_id  = get_option('orders2whatsapp_device_id');
        if (!$api_url || !$api_secret || !$device_id) { error_log('[o2w][zender] config incompleta'); return; }

        // === Cliente (respeta política de estados) ===
        if (!empty($ctx['send_to_customer']) && !empty($payload['customer_message'])) {
            $phone = $ctx['phone_intl'] ?? null;
            if (!$phone && !empty($ctx['vars']['phone_intl'])) $phone = $ctx['vars']['phone_intl'];
            error_log('[o2w][zender] cliente notify='.(int)!empty($ctx['send_to_customer']).' phone='.($phone?:''));
            if ($phone) {
                $this->post_zender($api_url, $api_secret, $device_id, $phone, $payload['customer_message']);
            }
        } else {
            error_log('[o2w][zender] cliente: no aplica (notify=0 o sin mensaje)');
        }

        // === Administradores (misma política de estados) ===
        if (!empty($ctx['notify']) && !empty($payload['admin_message'])) {
            $admins = get_option('orders2whatsapp_admin_numbers');
            error_log('[o2w][zender] admins notify=1 admins='.$admins);
            if ($admins) {
                foreach (array_map('trim', explode(',', $admins)) as $admin) {
                    if ($admin === '') continue;
                    $to = '+' . ltrim($admin, '+');
                    $this->post_zender($api_url, $api_secret, $device_id, $to, $payload['admin_message']);
                }
            }
        } else {
            error_log('[o2w][zender] admins: no aplica (notify=0 o sin mensaje)');
        }
    }

    /** ÚNICA definición del método que hace el POST a Zender */
    private function post_zender($url, $secret, $device, $to, $message) {
        error_log('[o2w][zender] POST recipient='.$to.' len='.strlen($message));
        $data = [
            'secret'    => $secret,
            'account'   => $device,
            'recipient' => $to,
            'type'      => 'text',
            'message'   => $message,
            'priority'  => 1,
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
        ]);
        $resp = curl_exec($ch);
        if ($err = curl_error($ch)) {
            error_log('Zender CURL: '.$err);
        } else {
            // Opcional: log de status según tu API
            // error_log('[o2w][zender] resp: '.$resp);
        }
        curl_close($ch);
    }
}
