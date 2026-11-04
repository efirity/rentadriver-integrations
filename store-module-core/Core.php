<?php
/**
 * RentADriver store-module protocol v1: the part every self-hosted shop module shares (OpenCart, PrestaShop, Magento).
 * The platform module implements Bridge (config storage, orders, history); this class signs, verifies and dispatches.
 * Protocol: https://github.com/efirity/rentadriver-integrations/blob/main/store-module-core/PROTOCOL.md
 *
 * PHP 7.1+ so OpenCart 3 shops on older hosting can run it. Magento autoloads Core and Bridge: its package build
 * moves both under the module's namespace (RentADriver\Delivery\Lib). No dependencies (cURL, which every supported platform requires).
 *
 * @license GPL-2.0-or-later
 */
namespace RentADriver\StoreModule;

// OpenCart and PrestaShop load this file directly; the Magento package autoloads Bridge and drops this line.
if (!interface_exists(__NAMESPACE__ . '\\Bridge', false)) { require_once __DIR__ . '/Bridge.php'; } // rd:include

class Core
{
    const PROTOCOL = 1;
    const SKEW = 300;
    const INSTALL_TTL = 900;
    const DEFAULT_API = 'https://api.rentadriver.ai';
    const APP_URL = 'https://rentadriver.ai';

    /** @var Bridge */
    private $bridge;

    public function __construct(Bridge $bridge)
    {
        $this->bridge = $bridge;
    }

    public static function sign($secret, $timestamp, $body)
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function verify($secret, $timestamp, $signature, $body, $now = null)
    {
        if (!$secret || !$timestamp || !$signature || !preg_match('/^\d{9,11}$/', (string) $timestamp)) {
            return false;
        }
        $now = $now === null ? time() : $now;
        if (abs($now - (int) $timestamp) > self::SKEW) {
            return false;
        }
        return hash_equals(self::sign($secret, $timestamp, $body), strtolower((string) $signature));
    }

    public static function token($bytes = 32)
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public function connected()
    {
        return (bool) ($this->bridge->getConfig('secret') && $this->bridge->getConfig('store_id'));
    }

    public function apiUrl()
    {
        $u = (string) $this->bridge->getConfig('api_url');
        return $u !== '' ? rtrim($u, '/') : self::DEFAULT_API;
    }

    /**
     * Connect button: remember a fresh one-time install token and return the URL that sends the admin to RentADriver.
     * $storeUrl is the shop's public https base; $endpoint its API URL on that origin. $returnTo: an https page on the
     * shop's origin to come back to, or null to land in RentADriver (never pass an admin URL carrying a session token).
     */
    public function connectUrl($storeUrl, $endpoint, $returnTo, $adminOrderUrl = null)
    {
        $token = self::token(32);
        // $adminOrderUrl: the shop's admin order page without any session token, ending where the order id goes
        // (e.g. ".../index.php?route=sale/order.info&order_id="). RentADriver links each order there.
        $this->bridge->setConfig(array('install_token' => $token, 'install_expires' => (string) (time() + self::INSTALL_TTL), 'admin_order_url' => $adminOrderUrl && strpos($adminOrderUrl, 'https://') === 0 ? $adminOrderUrl : null));
        $q = array('store' => $storeUrl, 'endpoint' => $endpoint, 'install_token' => $token);
        if ($returnTo) {
            $q['return_to'] = $returnTo;
        }
        return $this->apiUrl() . '/v1/' . $this->bridge->platform() . '/install?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    /** "Open RentADriver": a short-lived link signed with the shared secret. Render it only for administrators. */
    public function handoffUrl($setup = false)
    {
        if (!$this->connected()) {
            return null;
        }
        $store = (string) $this->bridge->getConfig('store_key');
        $exp = (string) (time() + 300);
        $q = array('store' => $store, 'exp' => $exp, 'sig' => self::sign($this->bridge->getConfig('secret'), $exp, 'handoff|' . $store));
        if ($setup) {
            $q['setup'] = '1';
        }
        return $this->apiUrl() . '/v1/' . $this->bridge->platform() . '/handoff?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    public function disconnect()
    {
        if ($this->connected()) {
            $this->sendEvent('app.uninstalled', null);
        }
        $this->bridge->setConfig(array('secret' => null, 'store_id' => null, 'store_key' => null, 'callback_token' => null, 'webhooks_url' => null, 'rates_url' => null, 'install_token' => null, 'install_expires' => null, 'events_seen' => null));
    }

    /**
     * Handle one request to the module endpoint. Returns [httpStatus, array body]; the platform controller prints it
     * as JSON. $header is callable(name) → value|null with case-insensitive names.
     */
    public function handle($raw, $header)
    {
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || !isset($data['action'])) {
            return array(400, array('ok' => false, 'error' => 'bad_request'));
        }
        $action = (string) $data['action'];
        // Throwable, not Exception: a TypeError or a database error in a bridge answers the protocol's error JSON
        // instead of a fatal 500 page (found on Magento's shipment write-back, #69).
        try {
            if ($action === 'claim') {
                return $this->claim($data);
            }
            if (!self::verify((string) $this->bridge->getConfig('secret'), $header('x-rd-timestamp'), $header('x-rd-signature'), (string) $raw)) {
                return array(401, array('ok' => false, 'error' => 'bad_signature'));
            }
            switch ($action) {
                case 'ping':
                    return array(200, array('ok' => true, 'protocol' => self::PROTOCOL, 'module' => $this->bridge->moduleVersion(), 'platform_version' => $this->bridge->platformVersion()));
                case 'profile':
                    return array(200, array('ok' => true, 'profile' => $this->profile()));
                case 'configure':
                    $this->bridge->setConfig(array_intersect_key(array_map('strval', array_filter($data, 'is_scalar')), array_flip(array('webhooks_url', 'rates_url', 'store_id', 'callback_token', 'app_url'))));
                    return array(200, array('ok' => true));
                case 'order':
                    return array(200, array('ok' => true, 'order' => $this->bridge->order((string) (isset($data['ref']) ? $data['ref'] : ''))));
                case 'orders':
                    $limit = isset($data['limit']) ? max(1, min(100, (int) $data['limit'])) : 50;
                    return array(200, array('ok' => true, 'orders' => $this->bridge->orders($limit)));
                case 'history':
                    return array(200, $this->history($data));
                default:
                    return array(400, array('ok' => false, 'error' => 'unknown_action'));
            }
        } catch (\Throwable $e) {
            return array(500, array('ok' => false, 'error' => substr($e->getMessage(), 0, 300)));
        }
    }

    private function claim(array $data)
    {
        $token = (string) $this->bridge->getConfig('install_token');
        $expires = (int) $this->bridge->getConfig('install_expires');
        $given = isset($data['install_token']) ? (string) $data['install_token'] : '';
        if ($token === '' || $given === '' || $expires < time() || !hash_equals($token, $given)) {
            return array(401, array('ok' => false, 'error' => 'install_token_invalid'));
        }
        foreach (array('secret', 'store_id', 'store_key', 'callback_token', 'api_url', 'webhooks_url', 'rates_url') as $k) {
            if (!isset($data[$k]) || !is_string($data[$k]) || $data[$k] === '') {
                return array(400, array('ok' => false, 'error' => 'missing_' . $k));
            }
        }
        if (!preg_match('#^https://#', $data['api_url'])) {
            return array(400, array('ok' => false, 'error' => 'bad_api_url'));
        }
        // One use: the token is gone before we answer, so a replayed claim fails.
        $this->bridge->setConfig(array(
            'install_token' => null, 'install_expires' => null,
            'secret' => $data['secret'], 'store_id' => $data['store_id'], 'store_key' => $data['store_key'], 'callback_token' => $data['callback_token'],
            'api_url' => rtrim($data['api_url'], '/'), 'webhooks_url' => $data['webhooks_url'], 'rates_url' => $data['rates_url'], 'events_seen' => null,
        ));
        return array(200, array('ok' => true, 'profile' => $this->profile()));
    }

    private function profile()
    {
        $p = $this->bridge->profile();
        $admin = $this->bridge->getConfig('admin_order_url');
        if ($admin) {
            $p['admin_ref'] = $admin;
        }
        return $p;
    }

    /** Idempotent: a repeated event_key returns the first entry's id without writing again. */
    private function history(array $data)
    {
        $key = isset($data['event_key']) ? substr((string) $data['event_key'], 0, 160) : '';
        $seen = json_decode((string) $this->bridge->getConfig('events_seen'), true);
        $seen = is_array($seen) ? $seen : array();
        if ($key !== '' && isset($seen[$key])) {
            return array('ok' => true, 'id' => $seen[$key], 'duplicate' => true);
        }
        $status = isset($data['status']) && in_array($data['status'], array('shipped', 'delivered'), true) ? $data['status'] : null;
        $tracking = isset($data['tracking']) && is_array($data['tracking']) ? $data['tracking'] : array();
        $id = $this->bridge->addHistory((string) $data['order_id'], (string) (isset($data['comment']) ? $data['comment'] : ''), !empty($data['notify']), $status, $tracking);
        if ($key !== '') {
            $seen[$key] = $id;
            // Keep the newest 300 keys: retries arrive within hours, not months.
            $seen = array_slice($seen, -300, null, true);
            $this->bridge->setConfig(array('events_seen' => json_encode($seen)));
        }
        return array('ok' => true, 'id' => $id);
    }

    /** Tell RentADriver an order changed; it reads the order back before acting. Never throws (checkout must not fail). */
    public function sendEvent($topic, $orderId)
    {
        $url = (string) $this->bridge->getConfig('webhooks_url');
        if (!$this->connected() || $url === '') {
            return false;
        }
        $body = json_encode(array('order_id' => $orderId === null ? null : (string) $orderId, 'protocol' => self::PROTOCOL));
        $ts = (string) time();
        list($status) = self::post($url, $body, array(
            'X-RD-Store: ' . $this->bridge->getConfig('store_key'),
            'X-RD-Topic: ' . $topic,
            'X-RD-Event-Id: ' . $topic . ':' . ($orderId === null ? '' : $orderId) . ':' . self::token(9),
            'X-RD-Timestamp: ' . $ts,
            'X-RD-Signature: ' . self::sign($this->bridge->getConfig('secret'), $ts, $body),
        ), 5);
        return $status >= 200 && $status < 300;
    }

    /**
     * Checkout rates. $request: destination {first_name,last_name,company,address1,address2,city,province_code,zip,
     * country_code,phone}, items [{quantity, weight_kg, requires_shipping}], subtotal, currency. Returns the rates list
     * (possibly empty). A slow or failing API hides our option rather than holding up the checkout.
     */
    public function rates(array $request)
    {
        $url = (string) $this->bridge->getConfig('rates_url');
        if (!$this->connected() || $url === '') {
            return array();
        }
        list($status, $json) = self::post($url, json_encode($request), array(), 6);
        return $status === 200 && is_array($json) && isset($json['rates']) && is_array($json['rates']) ? $json['rates'] : array();
    }

    /** @return array [status, decoded json|null] */
    public static function post($url, $body, array $headers, $timeout)
    {
        $headers = array_merge(array('Content-Type: application/json', 'Accept: application/json', 'User-Agent: RentADriver-StoreModule/' . self::PROTOCOL), $headers);
        // cURL is a requirement of OpenCart, PrestaShop and Magento alike.
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ));
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        return array($out === false ? 0 : $status, $out === false ? null : json_decode($out, true));
    }

    /** Case-insensitive header reader over $_SERVER for the platform controllers. */
    public static function serverHeader($name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }

    /** Print a handle() result as JSON and stop. */
    public static function respond(array $result)
    {
        http_response_code($result[0]);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($result[1]);
        exit;
    }

    /** Rate code from a carrier method code like `rentadriver.rd_same_day` (null when it is not ours). */
    public static function rateCodeOf($methodCode)
    {
        if (preg_match('/rentadriver[._:](rd_[a-z0-9_]+)/i', (string) $methodCode, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }
}
