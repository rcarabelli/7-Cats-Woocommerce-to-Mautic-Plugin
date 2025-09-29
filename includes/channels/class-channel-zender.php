<?php
class Orders2WhatsApp_Channel_Zender implements Orders2WhatsApp_Channel_Interface {
    public const KEY = 'zender';
    public static function key(): string { return self::KEY; }

    public function send(array $payload, array $ctx = []): void {
        $api_url    = trim((string) get_option('orders2whatsapp_api_url'));
        $api_secret = trim((string) get_option('orders2whatsapp_api_secret'));
        $device_id  = trim((string) get_option('orders2whatsapp_device_id'));
        if (!$api_url || !$api_secret || !$device_id) {
            error_log('[o2w][zender] config incompleta (url/secret/device)');
            return;
        }

        error_log('[o2w][zender] preflight url='.$api_url.' device='.$device_id);

        // === Cliente
        $send_customer = !empty($ctx['send_to_customer']);
        $cust_msg      = trim((string)($payload['customer_message'] ?? ''));
        $phone         = (string)($ctx['phone_intl'] ?? ($ctx['vars']['phone_intl'] ?? ''));

        if ($send_customer && $cust_msg !== '' && $phone !== '') {
            error_log('[o2w][zender] cliente notify=1 phone='.$phone.' len='.strlen($cust_msg));
            $this->post_zender($api_url, $api_secret, $device_id, $phone, $cust_msg);
        } else {
            error_log('[o2w][zender] cliente: no aplica (notify='.(int)$send_customer.' msglen='.strlen($cust_msg).' phone='.( $phone!==''?1:0 ).')');
        }

        // === Administradores
        $admin_msg = trim((string)($payload['admin_message'] ?? ''));
        if (!empty($ctx['notify']) && $admin_msg !== '') {
            $admins_raw = trim((string) get_option('orders2whatsapp_admin_numbers'));
            if ($admins_raw !== '') {
                $admins = array_filter(array_map('trim', explode(',', $admins_raw)));
                error_log('[o2w][zender] admins notify=1 count='.count($admins));
                foreach ($admins as $admin) {
                    $to = '+' . ltrim($admin, '+');
                    $this->post_zender($api_url, $api_secret, $device_id, $to, $admin_msg);
                }
            } else {
                error_log('[o2w][zender] admins: lista vacía en ajustes');
            }
        } else {
            error_log('[o2w][zender] admins: no aplica (notify='.(int)!empty($ctx['notify']).' msglen='.strlen($admin_msg).')');
        }
    }

    /** ÚNICA definición del POST a Zender con logging de http code */
    private function post_zender(string $url, string $secret, string $device, string $to, string $message): void {
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
            CURLOPT_POSTFIELDS     => http_build_query($data, '', '&'),
            CURLOPT_TIMEOUT        => 25,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            error_log('[o2w][zender] POST to='.$to.' CURL error: '.$err);
            return;
        }

        $snippet = is_string($resp) ? substr($resp, 0, 240) : '';
        error_log('[o2w][zender] POST to='.$to.' http='.$code.' resp='. $snippet);
    }
}
