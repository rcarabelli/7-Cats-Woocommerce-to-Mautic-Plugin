<?php
if (!defined('ABSPATH')) exit;

/**
 * Handler del botón "Create custom fields" + instalador de campos en Mautic.
 * - Usa el mismo enfoque de auth/refresh que el canal Mautic, pero en otra clase.
 * - Upsert por alias (si existe -> PATCH; si no -> POST).
 * - Soporta oauth2 | api_key | bearer_fixed.
 */
add_action('admin_post_orders2whatsapp_create_mautic_fields', 'orders2whatsapp_handle_create_mautic_fields');

function orders2whatsapp_handle_create_mautic_fields() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized.');
    check_admin_referer('o2w_create_mautic_fields');

    $creator = new Orders2WhatsApp_Mautic_FieldCreator();
    [$ok, $summary] = $creator->run();

    set_transient('o2w_fields_installer_msg', $summary, 60);
    wp_safe_redirect( admin_url('admin.php?page=orders2whatsapp') );
    exit;
}

/**
 * Creador/actualizador de Custom Fields de Mautic
 */
class Orders2WhatsApp_Mautic_FieldCreator {
    private array $steps = [];

    private function step(string $msg): void {
        $this->steps[] = '[' . current_time('H:i:s') . '] ' . $msg;
        error_log('[o2w][mautic-fields] ' . $msg);
    }

    public function run(): array {
        // 0) Config base
        $base = rtrim((string) get_option('orders2whatsapp_mautic_url'), '/');
        if (!$base) return [false, 'Configura primero la URL base de Mautic.'];

        $tokenFieldRaw = (string) get_option('orders2whatsapp_mautic_token'); // 'oauth2' | 'key:APIKEY' | 'BEARER' | ''
        $mode          = $this->resolve_auth_mode($tokenFieldRaw);

        // 0.1) OAuth2 ensure
        if ($mode === 'oauth2') {
            if (!$this->ensure_oauth2_logged_in($base)) {
                return [false, 'No fue posible obtener un access_token OAuth2 válido. Revisa credenciales.'];
            }
        }

        // 1) Definición de campos (alias exactos)
        $fields = [
            // label, alias, type, group, properties (opcionales)
            ['Last purchase date',            'last_purchase_date',  'datetime', 'core', []],
            ['Last Order ID',                 'last_order_id',       'text',     'core', ['maxlength' => 64]],
            ['Last Order Amount',             'last_order_amount',   'number',   'core', []],
            ['Historic Purchase Events',      'historic_purch_event','number',   'core', ['roundmode'=>3, 'scale'=>'']],
            // OJO: alias es "historic_purch_amoun" (sin 't'), tal como lo estás usando hoy
            ['Historic Purchase Amount',      'historic_purch_amoun','number',   'core', []],
            ['Last Order Products',           'last_ord_products',   'textarea', 'core', []],
            ['Last Order Products Categories','last_ord_prod_cat',   'textarea', 'core', []],
            ['Last Order Status',             'last_order_status',   'text',     'core', ['maxlength' => 64]],
        ];

        $okCnt = 0; $updCnt = 0; $skpCnt = 0; $errCnt = 0;

        foreach ($fields as [$label, $alias, $type, $group, $props]) {
            $res = $this->upsert_field($base, $mode, $tokenFieldRaw, [
                'label'       => $label,
                'alias'       => $alias,
                'type'        => $type,     // text | textarea | number | datetime
                'group'       => $group,    // core / other
                'isPublished' => 1,
                'properties'  => is_array($props) ? $props : [],
            ]);

            if ($res['status'] === 'created') { $okCnt++; }
            elseif ($res['status'] === 'updated') { $updCnt++; }
            elseif ($res['status'] === 'skipped') { $skpCnt++; }
            else { $errCnt++; }
        }

        $msg = sprintf('Campos: creados %d · actualizados %d · omitidos %d · errores %d', $okCnt, $updCnt, $skpCnt, $errCnt);
        if ($errCnt > 0) $msg .= ' — revisa el debug.log para detalles.';
        return [$errCnt === 0, $msg];
    }

    /** ==================== Upsert Field ==================== */

    private function upsert_field(string $base, string $mode, string $tokenFieldRaw, array $field): array {
        $alias = $field['alias'] ?? '';
        if (!$alias) return ['status'=>'error', 'msg'=>'alias vacío'];

        // 1) Buscar por alias
        $id = $this->find_field_id_by_alias($base, $mode, $tokenFieldRaw, $alias);
        if ($id) {
            // 2) PATCH (update mínimo: label, isPublished, properties, group, type (no siempre editable))
            $payload = [
                'label'       => $field['label'] ?? $alias,
                'isPublished' => (int) ($field['isPublished'] ?? 1),
                'group'       => $field['group'] ?? 'core',
            ];
            if (isset($field['properties'])) $payload['properties'] = (array) $field['properties'];

            $ok = $this->patch_field($base, $mode, $tokenFieldRaw, $id, $payload);
            return ['status' => $ok ? 'updated' : 'error', 'id' => $id];
        }

        // 3) POST (crear)
        $ok = $this->create_field($base, $mode, $tokenFieldRaw, $field);
        return ['status' => $ok ? 'created' : 'error'];
    }

    /** Trata de encontrar el ID de un campo por alias */
    private function find_field_id_by_alias(string $base, string $mode, string $tokenFieldRaw, string $alias): ?int {
        // Intento 1: search por alias
        $q = 'fields/contact?search=' . rawurlencode('alias:' . $alias);
        $res = $this->api_request_with_retry_oauth($base, $mode, $tokenFieldRaw, 'GET', $q, null);
        if ($res['code'] === 200 && !empty($res['json'])) {
            $rows = $res['json']['fields'] ?? $res['json']['list'] ?? $res['json'];
            if (is_array($rows)) {
                // Estructuras posibles: array indexado, o hash id=>field
                // Normalizamos y buscamos por alias exacto
                foreach ($rows as $id => $row) {
                    if (is_array($row) && isset($row['alias']) && $row['alias'] === $alias) {
                        return (int) ($row['id'] ?? $id);
                    }
                }
            }
        }

        // Intento 2 (fallback): listar y filtrar en cliente (limit razonable)
        $res2 = $this->api_request_with_retry_oauth($base, $mode, $tokenFieldRaw, 'GET', 'fields/contact?limit=500', null);
        if ($res2['code'] === 200 && !empty($res2['json'])) {
            $rows = $res2['json']['fields'] ?? $res2['json']['list'] ?? $res2['json'];
            if (is_array($rows)) {
                foreach ($rows as $id => $row) {
                    if (is_array($row) && isset($row['alias']) && $row['alias'] === $alias) {
                        return (int) ($row['id'] ?? $id);
                    }
                }
            }
        }

        return null;
    }

    private function create_field(string $base, string $mode, string $tokenFieldRaw, array $field): bool {
        $payload = $this->normalize_field_payload($field);

        // intento 1: JSON con wrapper "field"
        $res1 = $this->api_request_with_retry_oauth($base, $mode, $tokenFieldRaw, 'POST', 'fields/contact/new', ['field' => $payload]);
        if (in_array($res1['code'], [200,201], true)) return true;

        // intento 2: JSON plano
        $res2 = $this->api_request_with_retry_oauth($base, $mode, $tokenFieldRaw, 'POST', 'fields/contact/new', $payload);
        if (in_array($res2['code'], [200,201], true)) return true;

        // intento 3: x-www-form-urlencoded
        $form = [];
        foreach ($payload as $k => $v) {
            if ($k === 'properties' && is_array($v)) {
                foreach ($v as $pk => $pv) $form["field[properties][$pk]"] = (string)$pv;
            } else {
                $form["field[$k]"] = (string)$v;
            }
        }
        $res3 = $this->api_request_form_with_retry_oauth($base, $mode, $tokenFieldRaw, 'POST', 'fields/contact/new', $form);
        return in_array($res3['code'], [200,201], true);
    }

    private function patch_field(string $base, string $mode, string $tokenFieldRaw, int $id, array $payload): bool {
        // intento 1: JSON con wrapper
        $res1 = $this->api_request_with_retry_oauth($base, $mode, $tokenFieldRaw, 'PATCH', 'fields/contact/'.$id.'/edit', ['field'=>$payload]);
        if (in_array($res1['code'], [200,202], true)) return true;

        // intento 2: JSON plano
        $res2 = $this->api_request_with_retry_oauth($base, $mode, $tokenFieldRaw, 'PATCH', 'fields/contact/'.$id.'/edit', $payload);
        if (in_array($res2['code'], [200,202], true)) return true;

        // intento 3: form
        $form = [];
        foreach ($payload as $k => $v) {
            if ($k === 'properties' && is_array($v)) {
                foreach ($v as $pk => $pv) $form["field[properties][$pk]"] = (string)$pv;
            } else {
                $form["field[$k]"] = (string)$v;
            }
        }
        $res3 = $this->api_request_form_with_retry_oauth($base, $mode, $tokenFieldRaw, 'PATCH', 'fields/contact/'.$id.'/edit', $form);
        return in_array($res3['code'], [200,202], true);
    }

    private function normalize_field_payload(array $in): array {
        $out = [
            'label'       => (string) ($in['label'] ?? ''),
            'alias'       => (string) ($in['alias'] ?? ''),
            'type'        => (string) ($in['type']  ?? 'text'),
            'group'       => (string) ($in['group'] ?? 'core'),
            'isPublished' => (int)    ($in['isPublished'] ?? 1),
        ];
        if (!empty($in['properties']) && is_array($in['properties'])) {
            $out['properties'] = $in['properties'];
        }
        return $out;
    }

    /** ==================== Auth & HTTP (mismo enfoque que el canal) ==================== */

    private function resolve_auth_mode(string $tokenFieldRaw): string {
        $hasOauthCreds = $this->have_oauth2_credentials();
        if ($hasOauthCreds) return 'oauth2';
        if (strpos($tokenFieldRaw, 'key:') === 0) return 'api_key';
        if ($tokenFieldRaw === 'oauth2')         return 'oauth2';
        if ($tokenFieldRaw !== '')               return 'bearer_fixed';
        return 'none';
    }

    private function have_oauth2_credentials(): bool {
        $o = $this->oauth2_opts();
        return (bool) ($o['client_id'] && $o['client_secret'] && $o['username'] && $o['password']);
    }

    private function oauth2_opts(): array {
        $rawPass = (string) get_option('orders2whatsapp_mautic_oauth_password', '');
        $decodedPass = html_entity_decode($rawPass, ENT_QUOTES, 'UTF-8');
        return [
            'client_id'     => trim((string) get_option('orders2whatsapp_mautic_oauth_client_id', '')),
            'client_secret' => trim((string) get_option('orders2whatsapp_mautic_oauth_client_secret', '')),
            'username'      => trim((string) get_option('orders2whatsapp_mautic_oauth_username', '')),
            'password'      => $decodedPass,
        ];
    }

    private function ensure_oauth2_logged_in(string $base): bool {
        $this->step('OAuth2 ensure');
        $token = $this->oauth2_get_valid_access_token($base);
        if ($token) return true;

        // password grant
        if ($this->oauth2_password_grant($base)) return true;
        // fallback client_credentials
        return $this->oauth2_client_credentials_grant($base);
    }

    private function oauth2_get_valid_access_token(string $base): ?string {
        $access  = (string) get_option('orders2whatsapp_mautic_access_token',  '');
        $refresh = (string) get_option('orders2whatsapp_mautic_refresh_token', '');
        $expires = (int)    get_option('orders2whatsapp_mautic_access_expires', 0);

        if ($access && $expires > time() + 60) return $access;

        if ($refresh) {
            if ($this->oauth2_refresh($base)) {
                return (string) get_option('orders2whatsapp_mautic_access_token', '');
            }
        }
        if ($this->oauth2_password_grant($base)) {
            return (string) get_option('orders2whatsapp_mautic_access_token', '');
        }
        return null;
    }

    private function oauth2_password_grant(string $base): bool {
        $o = $this->oauth2_opts();
        if (!$o['client_id'] || !$o['client_secret'] || !$o['username'] || !$o['password']) return false;

        $url  = rtrim($base, '/') . '/oauth/v2/token';
        $post = http_build_query([
            'client_id'     => $o['client_id'],
            'client_secret' => $o['client_secret'],
            'grant_type'    => 'password',
            'username'      => $o['username'],
            'password'      => $o['password'],
        ], '', '&');

        $res = $this->oauth2_http_post_form($url, $post);
        if ($res['code'] !== 200 || empty($res['json']['access_token'])) return false;

        $this->oauth2_store_tokens($res['json']);
        return true;
    }

    private function oauth2_client_credentials_grant(string $base): bool {
        $o = $this->oauth2_opts();
        if (!$o['client_id'] || !$o['client_secret']) return false;

        $url  = rtrim($base, '/') . '/oauth/v2/token';
        $post = http_build_query([
            'client_id'     => $o['client_id'],
            'client_secret' => $o['client_secret'],
            'grant_type'    => 'client_credentials',
        ], '', '&');

        $res = $this->oauth2_http_post_form($url, $post);
        if ($res['code'] !== 200 || empty($res['json']['access_token'])) return false;

        $this->oauth2_store_tokens($res['json']);
        return true;
    }

    private function oauth2_refresh(string $base): bool {
        $o = $this->oauth2_opts();
        $refresh = (string) get_option('orders2whatsapp_mautic_refresh_token', '');
        if (!$refresh || !$o['client_id'] || !$o['client_secret']) return false;

        $url  = rtrim($base, '/') . '/oauth/v2/token';
        $post = http_build_query([
            'client_id'     => $o['client_id'],
            'client_secret' => $o['client_secret'],
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
        ], '', '&');

        $res = $this->oauth2_http_post_form($url, $post);
        if ($res['code'] !== 200 || empty($res['json']['access_token'])) return false;

        $this->oauth2_store_tokens($res['json']);
        return true;
    }

    private function oauth2_store_tokens(array $json): void {
        $access     = (string) ($json['access_token']  ?? '');
        $refresh    = (string) ($json['refresh_token'] ?? '');
        $expires_in = (int)    ($json['expires_in']    ?? 3600);

        if ($access)  update_option('orders2whatsapp_mautic_access_token',  $access,  false);
        if ($refresh) update_option('orders2whatsapp_mautic_refresh_token', $refresh, false);
        update_option('orders2whatsapp_mautic_access_expires', time() + max(60, $expires_in - 30), false);
    }

    private function oauth2_http_post_form(string $url, string $postQuery): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postQuery,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp     = curl_exec($ch);
        $curl_err = curl_error($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = null;
        if ($resp && ($tmp = json_decode($resp, true)) !== null) $decoded = $tmp;

        return ['code'=>$code, 'body'=>$resp, 'json'=>$decoded, 'error'=>$curl_err ?: null, 'url'=>$url];
    }

    /** ==================== HTTP genérico (+ retry OAuth donde aplique) ==================== */

    private function api_base(string $base): string {
        return rtrim($base, '/') . '/api/';
    }

    private function build_headers(string $base, string $mode, string $tokenFieldRaw): array {
        $headers = ['Content-Type: application/json'];

        if ($mode === 'api_key') {
            $headers[] = 'X-Api-Key: ' . substr($tokenFieldRaw, 4);
            return $headers;
        }
        if ($mode === 'oauth2') {
            $bearer = $this->oauth2_get_valid_access_token($base);
            if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;
            return $headers;
        }
        if ($mode === 'bearer_fixed' && $tokenFieldRaw !== '') {
            $headers[] = 'Authorization: Bearer ' . $tokenFieldRaw;
        }
        return $headers;
    }

    private function api_request(string $base, string $mode, string $tokenFieldRaw, string $method, string $path, ?array $payload = null): array {
        $url = $this->api_base($base) . ltrim($path, '/');
        $headers = $this->build_headers($base, $mode, $tokenFieldRaw);
        $json = $payload !== null ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => 25,
        ];
        if ($json !== null) $opts[CURLOPT_POSTFIELDS] = $json;
        curl_setopt_array($ch, $opts);

        $resp     = curl_exec($ch);
        $curl_err = curl_error($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = null;
        if ($resp && ($tmp = json_decode($resp, true)) !== null) $decoded = $tmp;

        return ['code'=>$code, 'body'=>$resp, 'json'=>$decoded, 'error'=>$curl_err ?: null, 'url'=>$url];
    }

    private function api_request_with_retry_oauth(string $base, string $mode, string $tokenFieldRaw, string $method, string $path, ?array $payload = null): array {
        $res = $this->api_request($base, $mode, $tokenFieldRaw, $method, $path, $payload);
        if ($mode === 'oauth2' && $res['code'] === 401) {
            if ($this->oauth2_refresh($base)) {
                $res = $this->api_request($base, $mode, $tokenFieldRaw, $method, $path, $payload);
            }
        }
        return $res;
    }

    private function api_request_form_with_retry_oauth(string $base, string $mode, string $tokenFieldRaw, string $method, string $path, array $formFields): array {
        $url = $this->api_base($base) . ltrim($path, '/');
        $headers = $this->build_headers($base, $mode, $tokenFieldRaw);
        // Reemplazar content-type por form
        $headers = array_values(array_filter($headers, static fn($h) => stripos($h, 'Content-Type:') !== 0));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';

        $postBody = http_build_query($formFields, '', '&');

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_POSTFIELDS     => $postBody,
        ];
        curl_setopt_array($ch, $opts);

        $resp     = curl_exec($ch);
        $curl_err = curl_error($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = null;
        if ($resp && ($tmp = json_decode($resp, true)) !== null) $decoded = $tmp;

        if ($mode === 'oauth2' && $code === 401) {
            if ($this->oauth2_refresh($base)) {
                return $this->api_request_form_with_retry_oauth($base, $mode, $tokenFieldRaw, $method, $path, $formFields);
            }
        }
        return ['code'=>$code, 'body'=>$resp, 'json'=>$decoded, 'error'=>$curl_err ?: null, 'url'=>$url];
    }
}
