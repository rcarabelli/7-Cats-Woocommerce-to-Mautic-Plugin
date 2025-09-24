<?php
/**
 * Clase encargada de enviar mensajes por WhatsApp usando Zender
 */

if (!defined('ABSPATH')) {
    exit;
}

class WhatsApp_Sender {
    public static function send_message($recipient_number, $message) {
        $api_url = get_option('orders2whatsapp_api_url');
        $api_secret = get_option('orders2whatsapp_api_secret');
        $device_id = get_option('orders2whatsapp_device_id');

        if (!$api_url || !$api_secret || !$device_id || !$recipient_number || !$message) {
            error_log('WhatsApp_Sender: Faltan datos para enviar mensaje');
            return false;
        }

        $data = [
            'secret' => $api_secret,
            'account' => $device_id,
            'recipient' => $recipient_number,
            'type' => 'text',
            'message' => $message,
            'priority' => 1
        ];

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('WhatsApp_Sender CURL error: ' . $error);
            return false;
        }

        $result = json_decode($response, true);
        if (isset($result['status']) && $result['status'] == 200) {
            return true;
        } else {
            error_log('WhatsApp_Sender response error: ' . $response);
            return false;
        }
    }

    public static function notify_admins($message) {
        $admin_numbers = get_option('orders2whatsapp_admin_numbers');
        if (!$admin_numbers) return;

        $numbers = array_map('trim', explode(',', $admin_numbers));
        foreach ($numbers as $number) {
            $formatted = '+' . ltrim($number, '+');
            self::send_message($formatted, $message);
        }
    }
}
