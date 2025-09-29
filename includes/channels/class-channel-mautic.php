<?php
/**
 * Canal Mautic para Orders2WhatsApp (WooCommerce + Magento)
 * - Login/refresh OAuth2 ANTES de llamar al API (password grant si existiera; fallback a client_credentials).
 * - Remapea y FILTRA (lista blanca) los campos hacia los aliases REALES de Mautic.
 * - Mueve last_order_json a Nota para evitar problemas de longitud/estructura.
 * - (Nuevo) Etiqueta el contacto según el origen: magento | woocommerce (+ tags extra opcionales).
 */
class Orders2WhatsApp_Channel_Mautic implements Orders2WhatsApp_Channel_Interface {
    public const KEY = 'mautic';
    public static function key(): string { return self::KEY; }

    /** Buffer de pasos para volcar al log */
    private array $steps = [];
    private string $last_log_path = '';

    private function step(string $msg): void {
        $this->steps[] = '[' . current_time('H:i:s') . '] ' . $msg;
        error_log('[o2w][mautic] ' . $msg);
    }

    /** ====== Punto de entrada del canal ====== */
    public function send(array $payload, array $ctx = []): void {
        $this->steps = [];
        $this->last_log_path = '';
        $this->step('send() init');

        // 0) Config base
        $base = rtrim((string) get_option('orders2whatsapp_mautic_url'), '/');
        if (!$base) { $this->step('Endpoint base no configurado. Abort.'); return; }

        // Auth mode
        $tokenFieldRaw = (string) get_option('orders2whatsapp_mautic_token'); // 'oauth2' | 'key:APIKEY' | 'BEARER_TOKEN' | ''
        $tokenMode     = $this->resolve_auth_mode($tokenFieldRaw);

        // 0.1) OAuth2 ensure
        if ($tokenMode === 'oauth2') {
            if (!$this->ensure_oauth2_logged_in($base)) {
                $this->step('OAuth2: no fue posible obtener un access_token válido. Abort.');
                $this->flush_process_trail_to_last_log($ctx);
                return;
            }
        }

        // 1) Identificación de origen (Woo vs Magento)
        $src = $ctx['vars']['source'] ?? '';
        $magento_entity_id = (int)($ctx['vars']['magento_entity_id'] ?? 0);
        $order_id          = (int)($ctx['vars']['order_id'] ?? 0); // Woo

        // 2) Construir campos ricos según origen
        if ($src === 'magento' || $magento_entity_id) {
            $eid = $magento_entity_id ?: $order_id; // por si te llega sólo order_id con el mismo valor
            if (!$eid) { $this->step('Magento: falta magento_entity_id/order_id en ctx. Abort.'); return; }
            if (!function_exists('\Orders2WhatsApp\o2w_build_mautic_contact_payload_from_magento_order')) {
                $this->step('Magento: builder no disponible. Abort.');
                return;
            }
            $built = \Orders2WhatsApp\o2w_build_mautic_contact_payload_from_magento_order($eid, []);
            $this->step('builder=magento entity_id=' . $eid);
        } else {
            if (!$order_id) { $this->step('Woo: falta order_id en ctx. Abort.'); return; }
            if (!function_exists('\Orders2WhatsApp\o2w_build_mautic_contact_payload_from_order')) {
                $this->step('Woo: builder no disponible. Abort.');
                return;
            }
            $built = \Orders2WhatsApp\o2w_build_mautic_contact_payload_from_order($order_id, []);
            $this->step('builder=woo order_id=' . $order_id);
        }

        $lead_fields = $built['lead_fields'] ?? [];
        $note_text   = $built['note_text']   ?? '';

        if (empty($lead_fields['email'])) {
            $this->step('upsert sin email, abortando.');
            return;
        }
        $lead_fields['email'] = strtolower(trim($lead_fields['email']));

        // 2.1) Remap + filtro estricto a aliases válidos
        $lead_fields = $this->remap_and_filter_fields($lead_fields);

        // 2.2) Mover last_order_json a Nota (si vino)
        if (!empty($built['lead_fields']['last_order_json'])) {
            $note_text = ($note_text ? $note_text . "\n\n" : '') .
                "last_order_json:\n" . $built['lead_fields']['last_order_json'];
        }

        // 3) Upsert
        $contactId = $this->upsert_contact($base, $tokenMode, $tokenFieldRaw, $lead_fields, $ctx);
        if (!$contactId) { $this->step('upsert falló.'); $this->flush_process_trail_to_last_log($ctx); return; }

        // 4) Tags (nuevo)
        $originTag = $this->resolve_origin_tag($src);
        $extraTags = $this->parse_extra_tags($payload, $ctx);
        $tags = array_values(array_unique(array_filter(array_merge([$originTag], $extraTags))));
        if ($tags) {
            $okTags = $this->add_tags_to_contact($base, $tokenMode, $tokenFieldRaw, $contactId, $tags, $ctx);
            $this->step('tags ' . ($okTags ? 'OK ' : 'FAIL ') . implode(',', $tags));
        }

        // 5) Nota opcional
        if (!empty($note_text)) {
            $ok = $this->create_note($base, $tokenMode, $tokenFieldRaw, $contactId, $note_text, $ctx);
            $this->step('Nota ' . ($ok ? 'creada' : 'NO creada'));
        } elseif (!empty($payload['admin_message'])) {
            $ok = $this->create_note($base, $tokenMode, $tokenFieldRaw, $contactId, $payload['admin_message'], $ctx);
            $this->step('Nota(admin_message) ' . ($ok ? 'creada' : 'NO creada'));
        }

        $this->step('OK contactId=' . $contactId);
        $this->flush_process_trail_to_last_log($ctx);
    }

    /** ====== Auth helpers ====== */

    /** Decide modo de auth priorizando OAuth2 si hay credenciales completas en ajustes */
    private function resolve_auth_mode(string $tokenFieldRaw): string {
        $hasOauthCreds = $this->have_oauth2_credentials();
        if ($hasOauthCreds) return 'oauth2'; // prioriza OAuth2 si hay credenciales
        if (strpos($tokenFieldRaw, 'key:') === 0) return 'api_key';
        if ($tokenFieldRaw === 'oauth2')         return 'oauth2';
        if ($tokenFieldRaw !== '')               return 'bearer_fixed';
        return 'none';
    }

    /** Verifica si existen TODAS las credenciales OAuth2 requeridas en opciones */
    private function have_oauth2_credentials(): bool {
        $o = $this->oauth2_opts();
        return (bool) ($o['client_id'] && $o['client_secret'] && $o['username'] && $o['password']);
    }

    /** Intenta tener un access_token válido antes de cualquier request */
    private function ensure_oauth2_logged_in(string $base): bool {
        $this->step('OAuth2: ensure logged in');
        $token = $this->oauth2_get_valid_access_token($base);
        if ($token) { $this->step('OAuth2: token válido en memoria/opciones'); return true; }

        // 1) password grant
        $this->step('OAuth2: solicitando password grant inicial');
        if ($this->oauth2_password_grant($base)) {
            $this->step('OAuth2: password grant OK');
            return true;
        }

        // 2) fallback: client_credentials
        $this->step('Password grant no autorizado → intento client_credentials.');
        if ($this->oauth2_client_credentials_grant($base)) {
            return true;
        }

        return false;
    }

    /** ====== Mapeo y FILTRO de campos ====== */

    /** Remap de payload a aliases REALES de Mautic y filtro estricto. */
    private function remap_and_filter_fields(array $f): array {
        // Funciones unicode seguras
        $u_strlen = static function ($s): int {
            return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
        };
        $u_substr = static function ($s, $start, $len = null): string {
            if (function_exists('mb_substr')) {
                return ($len === null) ? mb_substr($s, $start, null, 'UTF-8') : mb_substr($s, $start, $len, 'UTF-8');
            }
            return ($len === null) ? substr($s, $start) : substr($s, $start, $len);
        };

        // ❌ Campos que NO se envían tal cual
        $deny = [
            'last_order_currency',
            'last_order_json',
        ];

        // ✅ Mapa (payload) -> alias Mautic
        $map = [
            'firstname'              => 'firstname',
            'lastname'               => 'lastname',
            'email'                  => 'email',
            'phone'                  => 'phone',

            'last_order_id'          => 'last_order_id',
            'last_order_amount'      => 'last_order_amount',

            'historic_purch_amount'  => 'historic_purch_amoun',
            'historic_purch_amoun'   => 'historic_purch_amoun',
            'historic_purch_count'   => 'historic_purch_event',
            'historic_purch_event'   => 'historic_purch_event',

            // Status aliases → last_order_status
            'last_order_status'      => 'last_order_status',
            'status'                 => 'last_order_status',
            'order_status'           => 'last_order_status',
            'wc_status'              => 'last_order_status',
            'last_status'            => 'last_order_status',

            'last_purchase_date'     => 'last_purchase_date',

            // textareas
            'last_ord_prod_cat'      => 'last_ord_prod_cat',
            'last_ord_products'      => 'last_ord_products',
        ];

        $norm = static function ($v) {
            return is_string($v) ? html_entity_decode($v, ENT_QUOTES, 'UTF-8') : $v;
        };

        $normalize_status = function (string $raw) use ($u_strlen, $u_substr): string {
            $s = strtolower(trim($raw));
            if ($s === '') return '';
            $s = str_replace('_', '-', $s);
            $s = preg_replace('/^wc-/', '', $s);

            $mapStatus = [
                'pending-payment' => 'pending',
                'pending'         => 'pending',
                'processing'      => 'processing',
                'on-hold'         => 'on-hold',
                'completed'       => 'completed',
                'cancelled'       => 'cancelled',
                'refunded'        => 'refunded',
                'failed'          => 'failed',
            ];
            $v = isset($mapStatus[$s]) ? $mapStatus[$s] : $s;

            if ($u_strlen($v) > 50) {
                $v = $u_substr($v, 0, 50);
            }
            return $v;
        };

        // Convierte array/json items a líneas "name | url_producto | url_image"
        $items_to_lines = function ($items) {
            if (is_string($items)) {
                $s = trim($items);
                if ($s !== '' && ($s[0] === '[' || $s[0] === '{')) {
                    $decoded = json_decode($s, true);
                    if (json_last_error() === JSON_ERROR_NONE) $items = $decoded;
                }
            }
            if (!is_array($items)) return trim((string) $items);

            $lines = [];
            foreach ($it = (array)$items as $it) {
                if (!is_array($it)) continue;
                $nombre = isset($it['nombre']) ? trim((string)$it['nombre']) : '';
                $urlp   = isset($it['url_producto']) ? trim((string)$it['url_producto']) : '';
                $urli   = isset($it['url_image'])    ? trim((string)$it['url_image'])    : '';
                if ($nombre === '' && $urlp === '' && $urli === '') continue;

                $nombre = str_replace('|', '/', $nombre);
                $urlp   = str_replace('|', '/', $urlp);
                $urli   = str_replace('|', '/', $urli);

                $lines[] = $nombre . ' | ' . $urlp . ' | ' . $urli;
            }
            return implode("\n", $lines);
        };

        $out = [];
        foreach ($f as $k => $v) {
            if (in_array($k, $deny, true)) { $this->step("remap: dropping denied key '{$k}'"); continue; }
            if (!isset($map[$k])) {
                if ($k !== 'last_order_categories' && $k !== 'last_order_items_json') {
                    $this->step("remap: dropping unknown key '{$k}'");
                }
                continue;
            }

            $finalKey = $map[$k];
            $v = $norm($v);

            // Sanitización por tipo
            if ($finalKey === 'last_order_amount') {
                $v = (int) $v;

            } elseif ($finalKey === 'historic_purch_event') {
                $v = (int) $v;

            } elseif ($finalKey === 'historic_purch_amoun') {
                $v = (int) $v;

            } elseif ($finalKey === 'last_purchase_date') {
                // 'Y-m-d H:i:s' UTC
                $ts = null;
                if (is_numeric($v)) {
                    $ts = (int) $v;
                } elseif (is_string($v)) {
                    $sv = trim($v);
                    if ($sv !== '') {
                        $sv = str_replace(['T','Z'], [' ',''], $sv);
                        $ts = strtotime($sv);
                    }
                }
                if (!$ts) {
                    $this->step('remap: last_purchase_date invalid, skipping');
                    continue;
                }
                $v = gmdate('Y-m-d H:i:s', $ts);
                $this->step('remap: last_purchase_date → '.$v.' (UTC)');

            } elseif ($finalKey === 'last_order_status') {
                $vNorm = $normalize_status((string)$v);
                if ($vNorm === '') {
                    $this->step('remap: last_order_status empty, skipping this occurrence');
                    continue;
                }
                $v = $vNorm;
                $this->step('remap: last_order_status → '.$v);

            } elseif ($finalKey === 'last_ord_prod_cat') {
                $v = trim((string) $v);
                if ($u_strlen($v) > 2000) {
                    $v = $u_substr($v, 0, 2000);
                    $this->step('remap: last_ord_prod_cat truncated to 2000 chars');
                }

            } elseif ($finalKey === 'last_ord_products') {
                $v = $items_to_lines($v);
                if ($v !== '' && $u_strlen($v) > 4000) {
                    $v = $u_substr($v, 0, 4000);
                    $this->step('remap: last_ord_products truncated to 4000 chars');
                }
            }

            $out[$finalKey] = $v;
        }

        // Fallbacks
        if (empty($out['last_ord_prod_cat']) && !empty($f['last_order_categories'])) {
            $cat = trim((string) $norm($f['last_order_categories']));
            if ($cat !== '') {
                if ($u_strlen($cat) > 2000) {
                    $cat = $u_substr($cat, 0, 2000);
                    $this->step('remap: last_ord_prod_cat (fallback) truncated to 2000 chars');
                }
                $out['last_ord_prod_cat'] = $cat;
                $this->step('remap: last_ord_prod_cat populated from last_order_categories (fallback)');
            }
        }

        if (empty($out['last_ord_products']) && !empty($f['last_order_items_json'])) {
            $items = $items_to_lines($f['last_order_items_json']);
            if ($items !== '' && $u_strlen($items) > 4000) {
                $items = $u_substr($items, 0, 4000);
                $this->step('remap: last_ord_products (fallback) truncated to 4000 chars');
            }
            if ($items !== '') {
                $out['last_ord_products'] = $items;
                $this->step('remap: last_ord_products populated from last_order_items_json (fallback)');
            }
        }

        // Fallback para status desde last_order_json.status
        if (empty($out['last_order_status']) && !empty($f['last_order_json'])) {
            $raw = $f['last_order_json'];
            $dec = null;
            if (is_string($raw)) {
                $tmp = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) $dec = $tmp;
            } elseif (is_array($raw)) {
                $dec = $raw;
            }
            if (is_array($dec) && !empty($dec['status'])) {
                $v = $normalize_status((string)$dec['status']);
                if ($v !== '') {
                    $out['last_order_status'] = $v;
                    $this->step('remap: last_order_status populated from last_order_json.status → '.$v);
                }
            }
        }

        // Sanitización final
        if (isset($out['email'])) $out['email'] = strtolower(trim((string) $out['email']));
        if (isset($out['phone'])) $out['phone'] = trim((string) $out['phone']);

        return $out;
    }

    /** ====== HTTP ====== */

    private function api_base(string $base): string {
        return rtrim($base, '/') . '/api/';
    }

    /** Devuelve headers finales según el modo de auth */
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

    /** Llamada HTTP genérica (JSON) con logging */
    private function api_request(string $base, string $mode, string $tokenFieldRaw, string $method, string $path, ?array $payload = null, array $ctx = []): array {
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

        $this->maybe_log_raw($url, $headers, $json ?? '', $code, $resp, $curl_err, ['json'=>$payload], $ctx);
        return ['code'=>$code, 'body'=>$resp, 'json'=>$decoded, 'error'=>$curl_err ?: null, 'url'=>$url];
    }

    /** Igual que api_request() pero enviando application/x-www-form-urlencoded */
    private function api_request_form(string $base, string $mode, string $tokenFieldRaw, string $method, string $path, array $formFields, array $ctx = []): array {
        $url = $this->api_base($base) . ltrim($path, '/');

        // Partimos de los headers de auth y reemplazamos el Content-Type
        $headers = $this->build_headers($base, $mode, $tokenFieldRaw);
        // quita cualquier Content-Type previo
        $headers = array_values(array_filter($headers, static fn($h) => stripos($h, 'Content-Type:') !== 0));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';

        $postBody = is_string($formFields) ? $formFields : http_build_query($formFields, '', '&');

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

        // Log
        $this->maybe_log_raw($url, $headers, $postBody, $code, $resp, $curl_err, ['json'=>null], $ctx);
        return ['code'=>$code, 'body'=>$resp, 'json'=>$decoded, 'error'=>$curl_err ?: null, 'url'=>$url];
    }

    /** Reintento automático si es 401 y estamos en OAuth2 */
    private function api_request_with_retry_oauth(string $base, string $mode, string $tokenFieldRaw, string $method, string $path, ?array $payload = null, array $ctx = []): array {
        $res = $this->api_request($base, $mode, $tokenFieldRaw, $method, $path, $payload, $ctx);
        if ($mode === 'oauth2' && $res['code'] === 401) {
            $this->step('401 detectado → intento refresh OAuth2');
            if ($this->oauth2_refresh($base)) {
                $res = $this->api_request($base, $mode, $tokenFieldRaw, $method, $path, $payload, $ctx);
            }
        }
        return $res;
    }

    /** ====== Contacto ====== */

    /** Busca contacto por email. Devuelve ID o null. */
    private function find_contact_id_by_email(string $base, string $mode, string $tokenFieldRaw, string $email, array $ctx = []): ?int {
        if (!$email) return null;
        $q = 'contacts?search=' . rawurlencode('email:' . $email);
        $res = $this->api_request_with_retry_oauth($base, $mode, $tokenFieldRaw, 'GET', $q, null, $ctx);

        if ($res['error']) { $this->step('find error cURL: ' . $res['error']); return null; }
        if ($res['code'] === 401) { $this->step('find 401: credenciales inválidas/expiradas.'); return null; }
        if ($res['code'] !== 200 || empty($res['json']['total'])) { $this->step('find: no hay coincidencias para ' . $email); return null; }

        $contacts = $res['json']['contacts'] ?? [];
        if (!$contacts) { $this->step('find: contacts vacío'); return null; }

        $firstId = (int) array_key_first($contacts);
        $this->step('find: encontrado contactId=' . $firstId . ' para ' . $email);
        return $firstId ?: null;
    }

    /** Crea contacto */
    private function create_contact(string $base, string $mode, string $tokenFieldRaw, array $fields, array $ctx = []): ?int {
        $this->step('create_contact → POST contacts/new');
        $res = $this->api_request_with_retry_oauth($base, $mode, $tokenFieldRaw, 'POST', 'contacts/new', $fields, $ctx);

        if ($res['error']) { $this->step('create error cURL: '.$res['error']); return null; }
        if (!in_array($res['code'], [200, 201], true)) {
            $this->step('create http '.$res['code'].' body: '.substr((string)$res['body'],0,400));
            return null;
        }
        $id = (int) ($res['json']['contact']['id'] ?? 0);
        $this->step('create: id='.$id);
        return $id ?: null;
    }

    /** Edita contacto */
    private function edit_contact(string $base, string $mode, string $tokenFieldRaw, int $id, array $fields, array $ctx = []): bool {
        $this->step('edit_contact → PATCH contacts/'.$id.'/edit');
        $res = $this->api_request_with_retry_oauth($base, $mode, $tokenFieldRaw, 'PATCH', 'contacts/'.$id.'/edit', $fields, $ctx);

        if ($res['error']) { $this->step('edit error cURL: '.$res['error']); return false; }
        if (!in_array($res['code'], [200, 202], true)) {
            $this->step('edit http '.$res['code'].' body: '.substr((string)$res['body'],0,400));
            return false;
        }
        $this->step('edit OK');
        return true;
    }

    /** Upsert por email */
    private function upsert_contact(string $base, string $mode, string $tokenFieldRaw, array $fields, array $ctx = []): ?int {
        $email = $fields['email'] ?? '';
        if (!$email) { $this->step('upsert sin email'); return null; }

        $id = $this->find_contact_id_by_email($base, $mode, $tokenFieldRaw, $email, $ctx);
        if ($id) {
            $this->step('upsert: existe → editar id='.$id);
            $ok = $this->edit_contact($base, $mode, $tokenFieldRaw, $id, $fields, $ctx);
            if (!$ok) {
                $this->step('upsert: edit falló → intento crear nuevo');
                $newId = $this->create_contact($base, $mode, $tokenFieldRaw, $fields, $ctx);
                if ($newId) { $this->step('upsert: creado nuevo id='.$newId.' (fallback)'); }
                return $newId ?: null;
            }
            return $id;
        }

        $this->step('upsert: no existe → creando');
        return $this->create_contact($base, $mode, $tokenFieldRaw, $fields, $ctx);
    }

    /** ====== Tags (nuevo) ====== */

    /** Devuelve el tag de origen aplicando opciones y filtro. */
    private function resolve_origin_tag(string $src): string {
        $tagWoo = trim((string) get_option('orders2whatsapp_mautic_tag_woo', 'woocommerce'));
        $tagMag = trim((string) get_option('orders2whatsapp_mautic_tag_magento', 'magento'));

        $tag = ($src === 'magento') ? $tagMag : $tagWoo;
        $tag = $this->normalize_tag($tag ?: (($src === 'magento') ? 'magento' : 'woocommerce'));

        /** Permite override desde código */
        $tag = apply_filters('o2w_mautic_origin_tag', $tag, $src);
        return $this->normalize_tag($tag);
    }

    /** Normaliza un tag: minúsculas, espacios→guiones, recorta, limpia. */
    private function normalize_tag(string $s): string {
        $s = strtolower(trim($s));
        $s = str_replace(['"',"'"], '', $s);
        $s = preg_replace('/\s+/', '-', $s);
        $s = preg_replace('/[^a-z0-9\-_]/', '', $s);
        if (strlen($s) > 60) $s = substr($s, 0, 60);
        return $s;
    }

    /** Recolecta tags extra desde payload/ctx (array o 'a,b,c'). */
    private function parse_extra_tags(array $payload, array $ctx): array {
        $out = [];
        $c1 = $ctx['vars']['mautic_tags'] ?? null;
        $c2 = $payload['mautic_tags'] ?? null;
        foreach ([$c1, $c2] as $src) {
            if (!$src) continue;
            if (is_string($src)) {
                $parts = array_map('trim', explode(',', $src));
            } elseif (is_array($src)) {
                $parts = array_map('strval', $src);
            } else {
                continue;
            }
            foreach ($parts as $p) {
                $p = $this->normalize_tag($p);
                if ($p !== '') $out[] = $p;
            }
        }
        return $out;
    }

    /** Intenta añadir tags al contacto con varios formatos/endpoints. */
    private function add_tags_to_contact(string $base, string $mode, string $tokenFieldRaw, int $contactId, array $tags, array $ctx = []): bool {
        $tags = array_values(array_unique(array_filter(array_map([$this,'normalize_tag'], $tags))));
        if (!$tags) return true;

        $joined = implode(',', $tags);

        // Endpoint variante A
        $pathA = 'contacts/' . $contactId . '/tags/add';
        // Endpoint variante B (algunas instalaciones usan singular)
        $pathB = 'contacts/' . $contactId . '/tag/add';

        // 1) JSON {'tags': 'a,b'}
        foreach ([$pathA, $pathB] as $p) {
            $res = $this->api_request_with_retry_oauth($base, $mode, $tokenFieldRaw, 'POST', $p, ['tags' => $joined], $ctx);
            if (in_array($res['code'], [200, 201], true)) return true;
            $this->step("tags fallback #1 ($p) http ".$res['code'].' body: '.substr((string)$res['body'],0,180));
        }

        // 2) JSON {'tags': ['a','b']}
        foreach ([$pathA, $pathB] as $p) {
            $res = $this->api_request_with_retry_oauth($base, $mode, $tokenFieldRaw, 'POST', $p, ['tags' => $tags], $ctx);
            if (in_array($res['code'], [200, 201], true)) return true;
            $this->step("tags fallback #2 ($p) http ".$res['code'].' body: '.substr((string)$res['body'],0,180));
        }

        // 3) JSON {'tag': 'a'} si sólo es 1
        if (count($tags) === 1) {
            foreach ([$pathA, $pathB] as $p) {
                $res = $this->api_request_with_retry_oauth($base, $mode, $tokenFieldRaw, 'POST', $p, ['tag' => $tags[0]], $ctx);
                if (in_array($res['code'], [200, 201], true)) return true;
                $this->step("tags fallback #3 ($p) http ".$res['code'].' body: '.substr((string)$res['body'],0,180));
            }
        }

        // 4) FORM tags=a,b
        foreach ([$pathA, $pathB] as $p) {
            $res = $this->api_request_form_with_retry_oauth($base, $mode, $tokenFieldRaw, 'POST', $p, http_build_query(['tags' => $joined], '', '&'), $ctx);
            if (in_array($res['code'], [200, 201], true)) return true;
            $this->step("tags fallback #4 ($p form) http ".$res['code'].' body: '.substr((string)$res['body'],0,180));
        }

        return false;
    }

    /** ====== Notas ====== */

    /** Crea una nota para el contacto (con 3 variantes de payload). */
    private function create_note(string $base, string $mode, string $tokenFieldRaw, int $contactId, string $text, array $ctx = []): bool {
        $clean = trim($text);
        if ($clean === '') {
            $this->step('note: texto vacío tras trim → no envío');
            return false;
        }

        // 1) JSON con wrapper "note"
        $payload1 = ['note' => ['type' => 'general', 'text' => $clean, 'lead' => $contactId]];
        $res1 = $this->api_request_with_retry_oauth($base, $mode, $tokenFieldRaw, 'POST', 'notes/new', $payload1, $ctx);
        if (in_array($res1['code'], [200, 201], true)) { $this->step('note OK (json/wrapper)'); return true; }
        $this->step('note fallback #1 http '.$res1['code'].' body: '.substr((string)$res1['body'],0,200));

        // 2) JSON plano
        $payload2 = ['type' => 'general', 'text' => $clean, 'lead' => $contactId];
        $res2 = $this->api_request_with_retry_oauth($base, $mode, $tokenFieldRaw, 'POST', 'notes/new', $payload2, $ctx);
        if (in_array($res2['code'], [200, 201], true)) { $this->step('note OK (json/plain)'); return true; }
        $this->step('note fallback #2 http '.$res2['code'].' body: '.substr((string)$res2['body'],0,200));

        // 3) FORM note[...]
        $res3 = $this->api_request_form_with_retry_oauth(
            $base, $mode, $tokenFieldRaw, 'POST', 'notes/new',
            http_build_query([
                'note[lead]' => $contactId,
                'note[type]' => 'general',
                'note[text]' => $clean
            ], '', '&'),
            $ctx
        );
        $ok3 = in_array($res3['code'], [200, 201], true);
        $this->step('note ' . ($ok3 ? 'OK (form-urlencoded)' : 'FAIL http '.$res3['code'].' body: '.substr((string)$res3['body'],0,200)));
        return $ok3;
    }

    /** Envío form-urlencoded con retry OAuth (para notas, etc.). */
    private function api_request_form_with_retry_oauth(string $base, string $mode, string $tokenFieldRaw, string $method, string $path, string $postQuery, array $ctx = []): array {
        $url = $this->api_base($base) . ltrim($path, '/');
        $headers = $this->build_headers($base, $mode, $tokenFieldRaw);
        // Sustituir Content-Type por form
        $headers = array_values(array_filter(array_map(function($h){
            return (stripos($h, 'Content-Type:') === 0) ? null : $h;
        }, $headers)));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_POSTFIELDS     => $postQuery,
        ];
        curl_setopt_array($ch, $opts);
        $resp     = curl_exec($ch);
        $curl_err = curl_error($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = null;
        if ($resp && ($tmp = json_decode($resp, true)) !== null) $decoded = $tmp;

        $this->maybe_log_raw($url, $headers, $postQuery, $code, $resp, $curl_err, ['json'=>null], $ctx);

        // Retry si 401 y estamos en OAuth2
        if ($mode === 'oauth2' && $code === 401) {
            $this->step('401 detectado (form) → intento refresh OAuth2');
            if ($this->oauth2_refresh($base)) {
                return $this->api_request_form_with_retry_oauth($base, $mode, $tokenFieldRaw, $method, $path, $postQuery, $ctx);
            }
        }
        return ['code'=>$code, 'body'=>$resp, 'json'=>$decoded, 'error'=>$curl_err ?: null, 'url'=>$url];
    }

    /** ====== OAuth2 ====== */

    private function safe_opt(string $k, string $default = ''): string {
        return (string) get_option($k, $default);
    }

    private function oauth2_opts(): array {
        $rawPass = $this->safe_opt('orders2whatsapp_mautic_oauth_password');
        $decodedPass = html_entity_decode($rawPass, ENT_QUOTES, 'UTF-8');

        return [
            'client_id'     => trim($this->safe_opt('orders2whatsapp_mautic_oauth_client_id')),
            'client_secret' => trim($this->safe_opt('orders2whatsapp_mautic_oauth_client_secret')),
            'username'      => trim($this->safe_opt('orders2whatsapp_mautic_oauth_username')),
            'password'      => $decodedPass,
        ];
    }

    private function oauth2_get_valid_access_token(string $base): ?string {
        $access  = $this->safe_opt('orders2whatsapp_mautic_access_token', '');
        $refresh = $this->safe_opt('orders2whatsapp_mautic_refresh_token', '');
        $expires = (int) get_option('orders2whatsapp_mautic_access_expires', 0);

        if ($access && $expires > time() + 60) return $access;

        if ($refresh) {
            if ($this->oauth2_refresh($base)) {
                return $this->safe_opt('orders2whatsapp_mautic_access_token', '');
            }
        }
        if ($this->oauth2_password_grant($base)) {
            return $this->safe_opt('orders2whatsapp_mautic_access_token', '');
        }
        $this->step('OAuth2: no se obtuvo access_token');
        return null;
    }

    private function oauth2_password_grant(string $base): bool {
        $opts = $this->oauth2_opts();
        if (!$opts['client_id'] || !$opts['client_secret'] || !$opts['username'] || !$opts['password']) {
            $this->step('OAuth2: faltan credenciales en ajustes (client_id/secret/username/password).');
            return false;
        }

        $url = rtrim($base, '/') . '/oauth/v2/token';
        $post = http_build_query([
            'client_id'     => $opts['client_id'],
            'client_secret' => $opts['client_secret'],
            'grant_type'    => 'password',
            'username'      => $opts['username'],
            'password'      => $opts['password'],
        ], '', '&');

        $res = $this->oauth2_http_post_form($url, $post);
        if ($res['error']) { $this->step('OAuth2 password cURL error: '.$res['error']); return false; }
        if ($res['code'] !== 200 || empty($res['json']['access_token'])) {
            $this->step('OAuth2 password http '.$res['code'].' body: '.substr((string)$res['body'],0,400));
            return false;
        }
        $this->oauth2_store_tokens($res['json']);
        $this->step('OAuth2 password OK');
        return true;
    }

    private function oauth2_client_credentials_grant(string $base): bool {
        $opts = $this->oauth2_opts();
        if (!$opts['client_id'] || !$opts['client_secret']) {
            $this->step('OAuth2: faltan client_id/secret para client_credentials.');
            return false;
        }
        $url  = rtrim($base, '/') . '/oauth/v2/token';
        $post = http_build_query([
            'client_id'     => $opts['client_id'],
            'client_secret' => $opts['client_secret'],
            'grant_type'    => 'client_credentials',
        ], '', '&');

        $res = $this->oauth2_http_post_form($url, $post);
        if ($res['error']) { $this->step('OAuth2 client_credentials cURL error: '.$res['error']); return false; }
        if ($res['code'] !== 200 || empty($res['json']['access_token'])) {
            $this->step('OAuth2 client_credentials http '.$res['code'].' body: '.substr((string)$res['body'],0,400));
            return false;
        }
        $this->oauth2_store_tokens($res['json']);
        $this->step('OAuth2 client_credentials OK');
        return true;
    }

    public function oauth2_refresh(string $base): bool {
        $opts = $this->oauth2_opts();
        $refresh = $this->safe_opt('orders2whatsapp_mautic_refresh_token', '');
        if (!$refresh) { $this->step('OAuth2 refresh: no hay refresh_token'); return false; }
        if (!$opts['client_id'] || !$opts['client_secret']) {
            $this->step('OAuth2 refresh: faltan client_id/secret'); return false;
        }

        $url = rtrim($base, '/') . '/oauth/v2/token';
        $post = http_build_query([
            'client_id'     => $opts['client_id'],
            'client_secret' => $opts['client_secret'],
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
        ], '', '&');

        $res = $this->oauth2_http_post_form($url, $post);
        if ($res['error']) { $this->step('OAuth2 refresh cURL error: '.$res['error']); return false; }
        if ($res['code'] !== 200 || empty($res['json']['access_token'])) {
            $this->step('OAuth2 refresh http '.$res['code'].' body: '.substr((string)$res['body'],0,400));
            return false;
        }
        $this->oauth2_store_tokens($res['json']);
        $this->step('OAuth2 refresh OK');
        return true;
    }

    private function oauth2_http_post_form(string $url, string $postQuery): array {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postQuery,
            CURLOPT_TIMEOUT        => 20,
        ];
        curl_setopt_array($ch, $opts);
        $resp     = curl_exec($ch);
        $curl_err = curl_error($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = null;
        if ($resp && ($tmp = json_decode($resp, true)) !== null) $decoded = $tmp;

        $this->maybe_log_raw($url, ['Content-Type: application/x-www-form-urlencoded'], $postQuery, $code, $resp, $curl_err, ['json'=>null], ['vars'=>[]]);
        return ['code'=>$code, 'body'=>$resp, 'json'=>$decoded, 'error'=>$curl_err ?: null, 'url'=>$url];
    }

    private function oauth2_store_tokens(array $json): void {
        $access     = (string) ($json['access_token']  ?? '');
        $refresh    = (string) ($json['refresh_token'] ?? '');
        $expires_in = (int)    ($json['expires_in']    ?? 3600);

        if ($access)  update_option('orders2whatsapp_mautic_access_token',  $access,  false);
        if ($refresh) update_option('orders2whatsapp_mautic_refresh_token', $refresh, false);
        update_option('orders2whatsapp_mautic_access_expires', time() + max(60, $expires_in - 30), false);
    }

    /** ====== Logging ====== */

    private function maybe_log_raw(string $url, array $headers, string $body, int $http_code, ?string $resp, ?string $curl_err, array $payload, array $ctx): void {
        $log_enabled = (int) get_option('orders2whatsapp_mautic_log_enabled', 0);
        if (!$log_enabled) return;

        $upload = wp_upload_dir();
        $dir    = trailingslashit($upload['basedir']) . '7c-shop2mautic';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            $this->step('creando dir de logs: ' . $dir);
        }

        // Detectar origen y referencia para el nombre de archivo
        $src  = $ctx['vars']['source'] ?? '';
        $ref  = $ctx['vars']['magento_entity_id'] ?? ($ctx['vars']['order_id'] ?? ($payload['json']['order']['id'] ?? 'unknown'));
        $tag  = ($src === 'magento' || isset($ctx['vars']['magento_entity_id'])) ? 'magento' : 'woo';

        $stamp     = current_time('Ymd_His');
        $filename  = sprintf('mautic-%s-%s-%s.txt', $tag, $ref, $stamp);
        $path      = trailingslashit($dir) . $filename;
        $this->last_log_path = $path;

        $log  = "===== MAUTIC REQUEST =====\n";
        $log .= 'Date: ' . current_time('Y-m-d H:i:s') . "\n";
        $log .= 'URL: ' . $url . "\n";
        $log .= "Headers:\n" . implode("\n", $headers) . "\n\n";
        if ($body !== '') {
            $log .= "Payload:\n" . $body . "\n\n";
        }

        $log .= "===== MAUTIC RESPONSE =====\n";
        $log .= 'HTTP Code: ' . $http_code . "\n";
        if ($curl_err)   { $log .= "cURL Error:\n" . $curl_err . "\n"; }
        if ($resp !== null && $resp !== '') { $log .= "Body:\n" . $resp . "\n"; }

        if (!empty($this->steps)) {
            $log .= "\n===== PROCESS TRAIL =====\n" . implode("\n", $this->steps) . "\n";
        }

        @file_put_contents($path, $log);

        $retention = (int) get_option('orders2whatsapp_mautic_log_retention_days', 14);
        if ($retention > 0) {
            $cutoff = time() - ($retention * DAY_IN_SECONDS);
            foreach (glob($dir . '/mautic-*.txt') as $file) {
                $mtime = @filemtime($file);
                if ($mtime !== false && $mtime < $cutoff) @unlink($file);
            }
        }
    }

    private function flush_process_trail_to_last_log(array $ctx = []): void {
        if (!$this->last_log_path || empty($this->steps)) return;
        $append  = "\n===== PROCESS TRAIL (FINAL) =====\n" . implode("\n", $this->steps) . "\n";
        @file_put_contents($this->last_log_path, $append, FILE_APPEND);
    }
}
