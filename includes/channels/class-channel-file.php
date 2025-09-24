<?php
class Orders2WhatsApp_Channel_File implements Orders2WhatsApp_Channel_Interface {
    public const KEY = 'file';
    public static function key(): string { return self::KEY; }

    public function send(array $payload, array $ctx = []): void {
        if (empty($payload['filename']) || empty($payload['contents'])) {
            error_log('[o2w][file] payload incompleto (filename/contents).');
            return;
        }
    
        $upload = wp_upload_dir();
        $dir = $upload['basedir'] . '/7c-wc-orders2whatsapp';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            error_log('[o2w][file] creando dir: '.$dir);
        }
    
        $path = trailingslashit($dir) . $payload['filename'];
        $ok = @file_put_contents($path, $payload['contents']);
        if ($ok === false) {
            error_log('[o2w][file] ERROR al escribir: '.$path);
        } else {
            error_log('[o2w][file] escrito OK: '.$path.' (bytes='.$ok.')');
        }
    }
}
