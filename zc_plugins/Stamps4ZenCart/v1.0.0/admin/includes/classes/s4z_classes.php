<?php
/**
 * Stamps4ZenCart -- the Stamps.com (SERA) client, the connection and the
 * plugin's own records.
 *
 * Loaded with require_once from the admin page and the order-page observer.
 * Each class is guarded so an upgrade that briefly has two copies on disk
 * can't redeclare it.
 *
 * Written for PHP 7.4 through 8.5 and Zen Cart 1.5.8 through 3.0.0.
 *
 * @package  Stamps4ZenCart
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if (!defined('IS_ADMIN_FLAG') || IS_ADMIN_FLAG !== true) {
    die('Illegal Access');
}

if (!class_exists('S4zError', false)) {
    /**
     * A failure worth showing to the admin, already worded for them.
     */
    class S4zError extends Exception
    {
        /** @var bool true when the merchant must reconnect */
        public $reconnect = false;

        public function __construct($message, $reconnect = false)
        {
            parent::__construct($message);
            $this->reconnect = (bool)$reconnect;
        }
    }
}

if (!class_exists('S4zSera', false)) {
    /**
     * Talks to Stamps.com, keeping the store's access token fresh through the
     * myZenCartZone token service.
     */
    class S4zSera
    {
        public const API_STAGING = 'https://api.testing.stampsendicia.com/sera';
        public const API_PRODUCTION = 'https://api.stampsendicia.com/sera';

        /** @var string */
        protected $env;

        /** @var array|null the s4z_auth row */
        protected $auth = null;

        public function __construct($env = null)
        {
            $env = ($env === null) ? s4z_setting('S4Z_ENVIRONMENT', 'production') : $env;
            $this->env = ($env === 'production') ? 'production' : 'staging';
            $this->loadAuth();
        }

        public function environment()
        {
            return $this->env;
        }

        public function serviceUrl()
        {
            return rtrim((string)s4z_setting('S4Z_SERVICE_URL', 'https://myzencartzone.com/stamps-connect'), '/');
        }

        public function isConnected()
        {
            return $this->auth !== null && $this->auth['store_id'] !== '' && $this->auth['refresh_token'] !== '';
        }

        public function connectedAt()
        {
            return $this->isConnected() ? (string)$this->auth['connected_at'] : '';
        }

        public function connectedBy()
        {
            return $this->isConnected() ? (string)$this->auth['connected_by'] : '';
        }

        // ------------------------------------------------------------------
        // Connection
        // ------------------------------------------------------------------

        /**
         * Starts a connection: remembers a one-time state and verifier in the
         * admin session and returns the token service URL to send the browser to.
         *
         * @param string $returnUrl this plugin's admin page, absolute
         * @return string
         */
        public function beginConnect($returnUrl)
        {
            $state = s4z_random_token(32);
            $verifier = s4z_random_token(48);
            $_SESSION['s4z_connect'] = [
                'state' => $state,
                'verifier' => $verifier,
                'env' => $this->env,
                'time' => time(),
            ];
            return $this->serviceUrl() . '/start.php?' . http_build_query([
                'env' => $this->env,
                'return_url' => $returnUrl,
                'store_state' => $state,
                'challenge' => s4z_s256($verifier),
            ], '', '&', PHP_QUERY_RFC3986);
        }

        /**
         * Finishes a connection when the browser comes back with a code.
         *
         * @param string $state
         * @param string $pid
         * @param string $code
         * @param string $adminName
         * @return void
         * @throws S4zError
         */
        public function finishConnect($state, $pid, $code, $adminName)
        {
            $pending = $_SESSION['s4z_connect'] ?? null;
            unset($_SESSION['s4z_connect']);
            if (!is_array($pending) || !is_string($state) || !hash_equals((string)$pending['state'], $state)
                || (time() - (int)$pending['time']) > 900 || $pending['env'] !== $this->env) {
                throw new S4zError(S4Z_ERROR_CONNECT_EXPIRED);
            }
            $result = $this->service('exchange.php', ['pid' => (string)$pid, 'code' => (string)$code, 'verifier' => $pending['verifier']]);
            if (empty($result['ok']) || empty($result['refresh_token']) || empty($result['store_id']) || empty($result['store_key'])) {
                throw new S4zError(isset($result['message']) ? (string)$result['message'] : S4Z_ERROR_CONNECT_FAILED);
            }
            $this->saveAuth([
                'environment' => $this->env,
                'store_id' => (string)$result['store_id'],
                'store_key' => (string)$result['store_key'],
                'refresh_token' => (string)$result['refresh_token'],
                'access_token' => (string)$result['access_token'],
                'access_expires' => time() + max(60, (int)$result['expires_in']),
                'connected_at' => date('Y-m-d H:i:s'),
                'connected_by' => substr((string)$adminName, 0, 64),
            ]);
        }

        /**
         * Forgets the connection here and at the token service.
         *
         * @return void
         */
        public function disconnect()
        {
            if ($this->auth !== null && $this->auth['store_id'] !== '') {
                try {
                    $this->service('disconnect.php', ['store_id' => $this->auth['store_id'], 'store_key' => $this->auth['store_key']]);
                } catch (S4zError $e) {
                    // The local connection goes regardless; the service forgets
                    // unused stores on its own.
                }
            }
            $GLOBALS['db']->Execute("DELETE FROM " . TABLE_S4Z_AUTH . " WHERE environment = '" . zen_db_input($this->env) . "'");
            $this->auth = null;
        }

        /**
         * A valid access token, refreshed through the token service when it
         * has less than a minute left.
         *
         * @param bool $force
         * @return string
         * @throws S4zError
         */
        protected function accessToken($force = false)
        {
            if (!$this->isConnected()) {
                throw new S4zError(S4Z_ERROR_NOT_CONNECTED, true);
            }
            if (!$force && $this->auth['access_token'] !== '' && (int)$this->auth['access_expires'] > time() + 60) {
                return $this->auth['access_token'];
            }
            $result = $this->service('refresh.php', [
                'store_id' => $this->auth['store_id'],
                'store_key' => $this->auth['store_key'],
                'refresh_token' => $this->auth['refresh_token'],
            ]);
            if (empty($result['ok']) || empty($result['access_token'])) {
                if (isset($result['error']) && in_array($result['error'], ['reauthorize', 'store_key', 'revoked'], true)) {
                    throw new S4zError(S4Z_ERROR_RECONNECT_NEEDED, true);
                }
                throw new S4zError(isset($result['message']) ? (string)$result['message'] : S4Z_ERROR_REFRESH_FAILED);
            }
            $this->auth['access_token'] = (string)$result['access_token'];
            $this->auth['access_expires'] = time() + max(60, (int)$result['expires_in']);
            if (!empty($result['refresh_token'])) {
                $this->auth['refresh_token'] = (string)$result['refresh_token'];
            }
            $this->saveAuth($this->auth);
            return $this->auth['access_token'];
        }

        /**
         * POSTs JSON to the token service.
         *
         * @param string $endpoint
         * @param array $payload
         * @return array decoded body (an error body still decodes)
         * @throws S4zError when the service can't be reached
         */
        protected function service($endpoint, array $payload)
        {
            [$status, $body] = s4z_http('POST', $this->serviceUrl() . '/' . $endpoint, ['Content-Type: application/json'], json_encode($payload));
            $decoded = json_decode((string)$body, true);
            if ($status === 0 || !is_array($decoded)) {
                s4z_log_error('service ' . $endpoint, $status, 'no usable response');
                throw new S4zError(S4Z_ERROR_SERVICE_DOWN);
            }
            if (empty($decoded['ok'])) {
                s4z_log_error('service ' . $endpoint, $status, isset($decoded['error']) ? (string)$decoded['error'] : '');
            }
            return $decoded;
        }

        protected function loadAuth()
        {
            $row = $GLOBALS['db']->Execute(
                "SELECT * FROM " . TABLE_S4Z_AUTH . " WHERE environment = '" . zen_db_input($this->env) . "' LIMIT 1",
                false,
                false,
                0,
                true
            );
            $this->auth = $row->EOF ? null : [
                'environment' => $row->fields['environment'],
                'store_id' => (string)$row->fields['store_id'],
                'store_key' => (string)$row->fields['store_key'],
                'refresh_token' => (string)$row->fields['refresh_token'],
                'access_token' => (string)$row->fields['access_token'],
                'access_expires' => (int)$row->fields['access_expires'],
                'connected_at' => (string)$row->fields['connected_at'],
                'connected_by' => (string)$row->fields['connected_by'],
            ];
        }

        protected function saveAuth(array $auth)
        {
            $GLOBALS['db']->Execute("DELETE FROM " . TABLE_S4Z_AUTH . " WHERE environment = '" . zen_db_input($this->env) . "'");
            zen_db_perform(TABLE_S4Z_AUTH, [
                'environment' => $this->env,
                'store_id' => $auth['store_id'],
                'store_key' => $auth['store_key'],
                'refresh_token' => $auth['refresh_token'],
                'access_token' => $auth['access_token'],
                'access_expires' => (int)$auth['access_expires'],
                'connected_at' => $auth['connected_at'],
                'connected_by' => $auth['connected_by'],
            ]);
            $this->auth = $auth;
        }

        // ------------------------------------------------------------------
        // Stamps.com API
        // ------------------------------------------------------------------

        /**
         * One SERA call. POSTs carry an Idempotency-Key, so a retry after a
         * timeout replays the first answer instead of buying a second label.
         * Retries: at most two, only when no response arrived. A 401 gets one
         * token refresh and one more try.
         *
         * @param string $method
         * @param string $path e.g. /v1/rates
         * @param array $query
         * @param array|null $body
         * @return array decoded response (may be a list)
         * @throws S4zError
         */
        public function call($method, $path, array $query = [], $body = null)
        {
            $url = ($this->env === 'production' ? self::API_PRODUCTION : self::API_STAGING) . $path;
            if (!empty($query)) {
                $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }
            $idempotencyKey = ($method === 'POST') ? s4z_uuid4() : '';
            $refreshed = false;
            $attempt = 0;
            while (true) {
                $headers = ['Authorization: Bearer ' . $this->accessToken($refreshed), 'Accept: application/json'];
                if ($body !== null) {
                    $headers[] = 'Content-Type: application/json';
                }
                if ($idempotencyKey !== '') {
                    $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
                }
                [$status, $raw] = s4z_http($method, $url, $headers, ($body === null) ? null : json_encode($body));
                if ($status === 0 && $attempt < 2) {
                    $attempt++;
                    continue;
                }
                if ($status === 401 && !$refreshed) {
                    $refreshed = true;
                    continue;
                }
                break;
            }
            if ($status === 0) {
                s4z_log_error($method . ' ' . $path, 0, 'no response');
                throw new S4zError(S4Z_ERROR_STAMPS_DOWN);
            }
            $decoded = json_decode((string)$raw, true);
            if ($status >= 200 && $status < 300) {
                return is_array($decoded) ? $decoded : [];
            }
            $message = s4z_sera_error_text($decoded);
            s4z_log_error($method . ' ' . $path, $status, $message);
            throw new S4zError(sprintf(S4Z_ERROR_STAMPS_SAID, $message !== '' ? $message : ('HTTP ' . $status)), $status === 401);
        }

        public function balance()
        {
            return $this->call('GET', '/v1/balance');
        }

        public function validateAddress(array $address)
        {
            $result = $this->call('POST', '/v1/addresses/validate', [], [$address]);
            return isset($result[0]) && is_array($result[0]) ? $result[0] : [];
        }

        public function rates(array $request)
        {
            return $this->call('POST', '/v1/rates', ['carriers' => 'usps'], $request);
        }

        public function createLabel(array $request)
        {
            return $this->call('POST', '/v1/labels', [], $request);
        }

        /**
         * The label file, fetched fresh from Stamps.com every time: the
         * plugin never keeps label files (Move to Production rule).
         *
         * @param string $labelId
         * @param string|null $format pdf|png; null = the Label Format setting
         * @return array [string $mimeType, string $bytes]
         * @throws S4zError
         */
        public function labelFile($labelId, $format = null)
        {
            $format = ($format === null) ? s4z_label_format() : $format;
            try {
                $result = $this->call('GET', '/v1/labels/' . rawurlencode($labelId), [
                    'label_size' => s4z_label_size(),
                    'label_format' => $format,
                    'label_output_type' => 'base64',
                ]);
            } catch (S4zError $e) {
                // Stamps.com reprints USPS labels only within 1 day of purchase.
                if (stripos($e->getMessage(), 'within 1 day') !== false) {
                    throw new S4zError(S4Z_ERROR_REPRINT_WINDOW);
                }
                // ...and only a few times (staging 2026-11-07: "Reprint limit reached").
                if (stripos($e->getMessage(), 'reprint limit') !== false) {
                    throw new S4zError(S4Z_ERROR_REPRINT_LIMIT);
                }
                throw $e;
            }
            return s4z_decode_label($result, $format);
        }

        public function voidLabel($labelId)
        {
            return $this->call('PUT', '/v1/labels/' . rawurlencode($labelId) . '/void');
        }

        public function tracking($carrier, $trackingNumber)
        {
            return $this->call('GET', '/v1/tracking', ['carrier' => $carrier, 'tracking_number' => $trackingNumber]);
        }

        public function createManifest(array $labelIds)
        {
            return $this->call('POST', '/v1/manifests', [], [
                'label_ids' => array_values($labelIds),
                'label_options' => ['label_format' => 'pdf'],
            ]);
        }

        public function createPickup(array $request)
        {
            return $this->call('POST', '/v1/pickups', [], $request);
        }

        public function cancelPickup($pickupId)
        {
            return $this->call('DELETE', '/v1/pickups/' . rawurlencode($pickupId));
        }
    }
}

// ----------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------

if (!function_exists('s4z_setting')) {
    /**
     * A configuration value. zen_config() is v3.0.0-only, so this uses the
     * constant directly, as that function does internally.
     */
    function s4z_setting($key, $default = '')
    {
        return defined($key) ? constant($key) : $default;
    }

    function s4z_random_token($bytes)
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    function s4z_s256($value)
    {
        return rtrim(strtr(base64_encode(hash('sha256', $value, true)), '+/', '-_'), '=');
    }

    function s4z_uuid4()
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    /**
     * The Download PDF format: pdf or png. No EPL/ZPL: every label goes through the printer's
     * own driver as a document or image (anything else stored, such as an old ZPL choice, means pdf).
     */
    function s4z_label_format()
    {
        $f = (string)s4z_setting('S4Z_LABEL_FORMAT', 'pdf');
        return in_array($f, ['pdf', 'png'], true) ? $f : 'pdf';
    }

    function s4z_label_size()
    {
        return ((string)s4z_setting('S4Z_LABEL_SIZE', '4x6') === 'letter') ? 'letter' : '4x6';
    }

    /**
     * Pulls the first label out of a SERA labels response.
     *
     * @return array [mime, bytes]
     * @throws S4zError
     */
    function s4z_decode_label(array $result, $format)
    {
        $labels = isset($result['labels']) && is_array($result['labels']) ? $result['labels'] : [];
        foreach ($labels as $label) {
            if (is_array($label) && !empty($label['label_data'])) {
                $bytes = base64_decode((string)$label['label_data'], true);
                if ($bytes !== false && $bytes !== '') {
                    $mime = ($format === 'png') ? 'image/png' : 'application/pdf';
                    return [$mime, $bytes];
                }
            }
        }
        throw new S4zError(S4Z_ERROR_NO_LABEL_FILE);
    }

    /**
     * One HTTPS request with curl.
     *
     * @return array [int $status (0 = no response), string $body]
     */
    function s4z_http($method, $url, array $headers, $body = null)
    {
        if (!function_exists('curl_init')) {
            return [0, ''];
        }
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Stamps4ZenCart/1.0.0',
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $failed = ($raw === false || curl_errno($ch) !== 0);
        // curl_close() does nothing since PHP 8.0 and is deprecated in 8.5.
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        return $failed ? [0, ''] : [$status, (string)$raw];
    }

    /**
     * The human-readable part of a SERA error body, whatever shape it takes.
     */
    function s4z_sera_error_text($decoded)
    {
        if (!is_array($decoded)) {
            return '';
        }
        $messages = [];
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            foreach ($decoded['errors'] as $err) {
                if (is_array($err)) {
                    foreach (['message', 'error_message', 'description'] as $k) {
                        if (!empty($err[$k]) && is_string($err[$k])) {
                            $messages[] = $err[$k];
                            break;
                        }
                    }
                } elseif (is_string($err)) {
                    $messages[] = $err;
                }
            }
        }
        foreach (['message', 'error_description', 'error'] as $k) {
            if (empty($messages) && !empty($decoded[$k]) && is_string($decoded[$k])) {
                $messages[] = $decoded[$k];
            }
        }
        return substr(implode(' ', $messages), 0, 500);
    }

    /**
     * Logs a failed request: what was called, the HTTP status and Stamps.com's
     * message. Never a token, key, address or request body.
     */
    function s4z_log_error($what, $status, $message)
    {
        if ((string)s4z_setting('S4Z_LOG_ERRORS', 'true') !== 'true' || !defined('DIR_FS_LOGS')) {
            return;
        }
        $line = date('Y-m-d H:i:s') . ' [' . s4z_setting('S4Z_ENVIRONMENT', 'production') . '] ' . $what . ' -> HTTP ' . (int)$status
            . ($message !== '' ? ' : ' . preg_replace('/[\r\n]+/', ' ', $message) : '') . "\n";
        @file_put_contents(DIR_FS_LOGS . '/stamps4zencart-' . date('Ym') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * The configured ship-from address in SERA's shape.
     */
    function s4z_from_address()
    {
        // Zen Cart stores configuration text HTML-escaped ("Smith &amp; Sons");
        // labels need the real characters.
        $plain = static function ($key) {
            return html_entity_decode((string)s4z_setting($key), ENT_QUOTES, 'UTF-8');
        };
        return [
            'name' => $plain('S4Z_FROM_NAME'),
            'company_name' => $plain('S4Z_FROM_COMPANY'),
            'address_line1' => $plain('S4Z_FROM_ADDRESS1'),
            'address_line2' => $plain('S4Z_FROM_ADDRESS2'),
            'city' => $plain('S4Z_FROM_CITY'),
            'state_province' => strtoupper($plain('S4Z_FROM_STATE')),
            'postal_code' => $plain('S4Z_FROM_POSTCODE'),
            'country_code' => 'US',
            'phone' => $plain('S4Z_FROM_PHONE'),
            'email' => $plain('S4Z_FROM_EMAIL'),
        ];
    }

    /**
     * The settings that must be filled in before a label can be bought.
     *
     * @return array the titles of the missing ones
     */
    function s4z_missing_from_fields()
    {
        $missing = [];
        foreach (['S4Z_FROM_ADDRESS1' => 'Street Address', 'S4Z_FROM_CITY' => 'City', 'S4Z_FROM_STATE' => 'State',
                  'S4Z_FROM_POSTCODE' => 'ZIP Code'] as $key => $title) {
            if (trim((string)s4z_setting($key)) === '') {
                $missing[] = $title;
            }
        }
        if (trim((string)s4z_setting('S4Z_FROM_NAME')) === '' && trim((string)s4z_setting('S4Z_FROM_COMPANY')) === '') {
            $missing[] = 'Name or Company';
        }
        return $missing;
    }

    /**
     * The order's ship-to address and phone, read straight from the orders
     * table so it works the same on every Zen Cart release.
     *
     * @param int $oID
     * @return array|null SERA-shaped address plus 'country_name', or null
     */
    function s4z_order_ship_to($oID)
    {
        global $db;
        $o = $db->Execute(
            "SELECT delivery_name, delivery_company, delivery_street_address, delivery_suburb, delivery_city,
                    delivery_postcode, delivery_state, delivery_country, customers_telephone, customers_email_address,
                    date_purchased
               FROM " . TABLE_ORDERS . " WHERE orders_id = " . (int)$oID . " LIMIT 1"
        );
        if ($o->EOF) {
            return null;
        }
        $f = $o->fields;
        $country = $db->Execute(
            "SELECT countries_id, countries_iso_code_2 FROM " . TABLE_COUNTRIES . "
              WHERE countries_name = '" . zen_db_input($f['delivery_country']) . "' LIMIT 1"
        );
        $iso = $country->EOF ? '' : $country->fields['countries_iso_code_2'];
        $state = (string)$f['delivery_state'];
        if (!$country->EOF && $state !== '') {
            $zone = $db->Execute(
                "SELECT zone_code FROM " . TABLE_ZONES . "
                  WHERE zone_country_id = " . (int)$country->fields['countries_id'] . "
                    AND (zone_name = '" . zen_db_input($state) . "' OR zone_code = '" . zen_db_input($state) . "')
                  LIMIT 1"
            );
            if (!$zone->EOF) {
                $state = $zone->fields['zone_code'];
            }
        }
        return [
            'name' => (string)$f['delivery_name'],
            'company_name' => (string)$f['delivery_company'],
            'address_line1' => (string)$f['delivery_street_address'],
            'address_line2' => (string)$f['delivery_suburb'],
            'city' => (string)$f['delivery_city'],
            'state_province' => $state,
            'postal_code' => (string)$f['delivery_postcode'],
            'country_code' => $iso,
            'phone' => (string)$f['customers_telephone'],
            'email' => (string)$f['customers_email_address'],
            'country_name' => (string)$f['delivery_country'],
            'order_date' => date('c', strtotime((string)$f['date_purchased'])),
        ];
    }

    /**
     * The order's product weight (product weights x quantities), in the
     * store's weight unit.
     */
    function s4z_order_weight($oID)
    {
        global $db;
        $r = $db->Execute(
            "SELECT SUM(op.products_quantity * IFNULL(p.products_weight, 0)) AS total
               FROM " . TABLE_ORDERS_PRODUCTS . " op
               LEFT JOIN " . TABLE_PRODUCTS . " p ON p.products_id = op.products_id
              WHERE op.orders_id = " . (int)$oID
        );
        return $r->EOF ? 0.0 : round((float)$r->fields['total'], 2);
    }

    /**
     * Labels recorded for one order, newest first.
     */
    function s4z_order_labels($oID)
    {
        global $db;
        $rows = [];
        $r = $db->Execute(
            "SELECT * FROM " . TABLE_S4Z_LABELS . " WHERE orders_id = " . (int)$oID . " ORDER BY s4z_label_id DESC",
            false, false, 0, true
        );
        while (!$r->EOF) {
            $rows[] = $r->fields;
            $r->MoveNext();
        }
        return $rows;
    }

    /**
     * One label row by our own id, or null.
     */
    function s4z_label_row($s4zLabelId)
    {
        global $db;
        $r = $db->Execute("SELECT * FROM " . TABLE_S4Z_LABELS . " WHERE s4z_label_id = " . (int)$s4zLabelId . " LIMIT 1", false, false, 0, true);
        return $r->EOF ? null : $r->fields;
    }

    /**
     * Readable name for a SERA service code, e.g. usps_priority_mail -> USPS Priority Mail.
     */
    function s4z_service_name($code)
    {
        $names = [
            'usps_ground_advantage' => 'USPS Ground Advantage',
            'usps_priority_mail' => 'USPS Priority Mail',
            'usps_priority_mail_express' => 'USPS Priority Mail Express',
            'usps_first_class_mail' => 'USPS First-Class Mail',
            'usps_media_mail' => 'USPS Media Mail',
            'usps_parcel_select' => 'USPS Parcel Select',
        ];
        if (isset($names[$code])) {
            return $names[$code];
        }
        return ucwords(str_replace(['usps_', '_'], ['USPS ', ' '], (string)$code));
    }

    /**
     * Readable name for a SERA packaging code.
     */
    function s4z_package_name($code)
    {
        $names = [
            'package' => 'Package',
            'large_envelope' => 'Large Envelope or Flat',
            'usps_flat_rate_envelope' => 'Flat Rate Envelope',
            'usps_padded_flat_rate_envelope' => 'Padded Flat Rate Envelope',
            'usps_legal_flat_rate_envelope' => 'Legal Flat Rate Envelope',
            'usps_small_flat_rate_box' => 'Small Flat Rate Box',
            'usps_medium_flat_rate_box' => 'Medium Flat Rate Box',
            'usps_large_flat_rate_box' => 'Large Flat Rate Box',
        ];
        return $names[$code] ?? ucwords(str_replace(['usps_', '_'], ['', ' '], (string)$code));
    }

    /**
     * True while Stamps.com will still reprint a label: USPS labels can be
     * reprinted only within 1 day of purchase (found in staging 2026-11-07).
     * Ten minutes are kept in hand so the button never outlives the window.
     */
    function s4z_can_reprint(array $row)
    {
        return $row['status'] === 'active' && strtotime((string)$row['created_at']) > time() - 86400 + 600;
    }

    /**
     * True for a label bought in the last five minutes, while Stamps.com may still answer
     * a void with "Label could not be found".
     */
    function s4z_is_just_bought(array $row)
    {
        $t = strtotime((string)$row['created_at']);
        return $t !== false && $t > time() - 300;
    }

    /**
     * Keeps a just-bought label's PNG in the admin session for its first print, so that
     * print doesn't use up one of Stamps.com's few reprints. Stamps.com: "Labels should not
     * be stored client-side after the initial print", so it's one slot (the latest label),
     * handed out once and dropped after an hour. Never written to disk or the database
     * tables. A response without image data just leaves the first print to the reprint call.
     */
    function s4z_hold_first_print($labelRowId, $createResponse)
    {
        unset($_SESSION['s4z_first_print']);
        try {
            [, $bytes] = s4z_decode_label($createResponse, 'png');
        } catch (S4zError $e) {
            return;
        }
        if ($bytes !== '') {
            $_SESSION['s4z_first_print'] = ['id' => (int)$labelRowId, 'png' => base64_encode($bytes), 'at' => time()];
        }
    }

    /**
     * Hands out the held first-print PNG for this label row once, or '' when there is none
     * (already printed, another label, or more than an hour old).
     */
    function s4z_take_first_print($labelRowId)
    {
        $held = $_SESSION['s4z_first_print'] ?? null;
        if (!is_array($held) || !isset($held['id'], $held['png'], $held['at'])) {
            return '';
        }
        if ((int)$held['at'] < time() - 3600) {
            unset($_SESSION['s4z_first_print']);
            return '';
        }
        if ((int)$held['id'] !== (int)$labelRowId) {
            return '';
        }
        unset($_SESSION['s4z_first_print']);
        $bytes = base64_decode((string)$held['png'], true);
        return ($bytes === false) ? '' : $bytes;
    }

    /**
     * True when a USPS service qualifies a pickup as free (Priority Mail or
     * Priority Mail Express), per Stamps.com's checklist.
     */
    function s4z_is_priority_service($code)
    {
        return in_array($code, ['usps_priority_mail', 'usps_priority_mail_express'], true);
    }

    /**
     * The next USPS pickup day (tomorrow, skipping Sundays and the federal
     * holidays USPS observes) as Y-m-d, from a given date.
     */
    function s4z_next_pickup_day($fromTs = null)
    {
        $ts = ($fromTs === null) ? time() : (int)$fromTs;
        for ($i = 1; $i <= 10; $i++) {
            $day = strtotime('+' . $i . ' day', $ts);
            if ((int)date('w', $day) === 0 || s4z_is_usps_holiday($day)) {
                continue;
            }
            return date('Y-m-d', $day);
        }
        return date('Y-m-d', strtotime('+1 day', $ts));
    }

    /**
     * The federal holidays USPS doesn't deliver (observed dates).
     */
    function s4z_is_usps_holiday($ts)
    {
        $y = (int)date('Y', $ts);
        $md = date('m-d', $ts);
        $nth = static function ($n, $dow, $month) use ($y) {
            $first = mktime(0, 0, 0, $month, 1, $y);
            $offset = ($dow - (int)date('w', $first) + 7) % 7;
            return date('m-d', mktime(0, 0, 0, $month, 1 + $offset + 7 * ($n - 1), $y));
        };
        $lastMonday = static function ($month) use ($y) {
            $last = mktime(0, 0, 0, $month + 1, 0, $y);
            return date('m-d', $last - ((((int)date('w', $last)) + 6) % 7) * 86400);
        };
        $fixed = ['01-01', '06-19', '07-04', '11-11', '12-25'];
        foreach ($fixed as $f) {
            $d = strtotime($y . '-' . $f);
            $w = (int)date('w', $d);
            // Saturday holidays are observed Friday; Sunday ones Monday.
            $observed = ($w === 6) ? strtotime('-1 day', $d) : (($w === 0) ? strtotime('+1 day', $d) : $d);
            if (date('m-d', $observed) === $md) {
                return true;
            }
        }
        $floating = [$nth(3, 1, 1), $nth(3, 1, 2), $lastMonday(5), $nth(1, 1, 9), $nth(2, 1, 10), $nth(4, 4, 11)];
        return in_array($md, $floating, true);
    }
}

if (!function_exists('s4z_markdown_html')) {
    /**
     * README.md as HTML for the admin readme page: a small, deliberately limited Markdown
     * subset (# headings, paragraphs, - and 1. lists, > quotes, **bold**, *italic*,
     * `code`, bare http(s) links). Every character is escaped first, so nothing in the
     * file can become markup or script; only the tags made here are output.
     *
     * @param string $md
     * @return string
     */
    function s4z_markdown_html($md)
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", (string)$md));
        $html = '';
        $para = [];
        $list = null;      // 'ul' or 'ol'
        $items = [];
        $quote = [];
        $flush = static function () use (&$html, &$para, &$list, &$items, &$quote) {
            if ($para !== []) {
                $html .= '<p>' . s4z_markdown_inline(implode(' ', $para)) . "</p>\n";
                $para = [];
            }
            if ($list !== null) {
                $html .= "<$list>\n";
                foreach ($items as $item) {
                    $html .= '<li>' . s4z_markdown_inline($item) . "</li>\n";
                }
                $html .= "</$list>\n";
                $list = null;
                $items = [];
            }
            if ($quote !== []) {
                $html .= '<blockquote>' . s4z_markdown_inline(implode(' ', $quote)) . "</blockquote>\n";
                $quote = [];
            }
        };
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $flush();
                continue;
            }
            if (preg_match('/^(#{1,3})\s+(.+)$/', $trim, $m)) {
                $flush();
                $n = strlen($m[1]);
                $html .= "<h$n>" . s4z_markdown_inline($m[2]) . "</h$n>\n";
                continue;
            }
            if (preg_match('/^>\s?(.*)$/', $trim, $m)) {
                if ($quote === []) {
                    $flush();
                }
                $quote[] = $m[1];
                continue;
            }
            if (preg_match('/^-\s+(.+)$/', $line, $m) || preg_match('/^\d+\.\s+(.+)$/', $line, $m2)) {
                $type = isset($m[1]) ? 'ul' : 'ol';
                $text = isset($m[1]) ? $m[1] : $m2[1];
                if ($list !== $type) {
                    $flush();
                    $list = $type;
                }
                $items[] = $text;
                $m = $m2 = [];
                continue;
            }
            if ($list !== null && preg_match('/^\s{2,}\S/', $line)) {
                $items[count($items) - 1] .= ' ' . $trim;
                continue;
            }
            if ($list !== null || $quote !== []) {
                $flush();
            }
            $para[] = $trim;
        }
        $flush();
        return $html;
    }

    /**
     * One line of README text: escaped, then `code`, **bold**, *italic* and bare
     * http(s) links (opened in a new window, no opener, no referrer).
     *
     * @param string $text
     * @return string
     */
    function s4z_markdown_inline($text)
    {
        $text = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
        $codes = [];
        $text = preg_replace_callback('/`([^`]+)`/', static function ($m) use (&$codes) {
            $codes[] = '<code>' . $m[1] . '</code>';
            return "\x00" . (count($codes) - 1) . "\x00";
        }, $text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $text);
        $text = preg_replace_callback('~https?://[^\s<>()"\']+~', static function ($m) {
            $url = rtrim($m[0], '.,;:');
            $tail = substr($m[0], strlen($url));
            return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $tail;
        }, $text);
        return preg_replace_callback('/\x00(\d+)\x00/', static function ($m) use ($codes) {
            return $codes[(int)$m[1]];
        }, $text);
    }
}
