<?php
/**
 * OpenCart side of the RentADriver store-module protocol, shared by the OpenCart 4 and OpenCart 3 packages. It reads
 * orders straight from the database (the order tables are the same in 3.0 and 4.x) and writes history through the
 * catalog order model the package passes in, so OpenCart sends its own customer emails and runs its own events.
 *
 * @license GPL-2.0-or-later
 */
namespace RentADriver\StoreModule;

if (!class_exists(__NAMESPACE__ . '\\OpenCartBridge')) {
    class OpenCartBridge implements Bridge
    {
        const CODE = 'shipping_rentadriver';
        /** True while we add history, so our own status change does not send an event back. */
        public static $writing = false;

        private $db;
        private $config;
        private $version;
        private $moduleVersion;
        /** @var callable(int $orderId, int $statusId, string $comment, bool $notify) */
        private $addHistory;

        public function __construct($db, $config, $platformVersion, $moduleVersion, $addHistory = null)
        {
            $this->db = $db;
            $this->config = $config;
            $this->version = $platformVersion;
            $this->moduleVersion = $moduleVersion;
            $this->addHistory = $addHistory;
        }

        public function platform()
        {
            return 'opencart';
        }

        public function moduleVersion()
        {
            return $this->moduleVersion;
        }

        public function platformVersion()
        {
            return $this->version;
        }

        private function t($table)
        {
            return '`' . DB_PREFIX . $table . '`';
        }

        // ---- settings: one row per key in the setting table, code shipping_rentadriver, read fresh every time ----

        public function getConfig($key)
        {
            $k = self::CODE . '_rd_' . $key;
            $q = $this->db->query('SELECT `value` FROM ' . $this->t('setting') . " WHERE store_id = 0 AND `code` = '" . self::CODE . "' AND `key` = '" . $this->db->escape($k) . "' LIMIT 1");
            return $q->num_rows ? (string) $q->row['value'] : null;
        }

        public function setConfig(array $values)
        {
            foreach ($values as $key => $value) {
                $k = $this->db->escape(self::CODE . '_rd_' . $key);
                $this->db->query('DELETE FROM ' . $this->t('setting') . " WHERE store_id = 0 AND `code` = '" . self::CODE . "' AND `key` = '" . $k . "'");
                if ($value !== null) {
                    $this->db->query('INSERT INTO ' . $this->t('setting') . " SET store_id = 0, `code` = '" . self::CODE . "', `key` = '" . $k . "', `value` = '" . $this->db->escape((string) $value) . "', serialized = 0");
                }
            }
        }

        /** A plain (non-protocol) module setting saved by the admin form, e.g. shipping_rentadriver_test_mode. */
        public function setting($name)
        {
            $q = $this->db->query('SELECT `value` FROM ' . $this->t('setting') . " WHERE store_id = 0 AND `key` = '" . $this->db->escape(self::CODE . '_' . $name) . "' LIMIT 1");
            return $q->num_rows ? (string) $q->row['value'] : null;
        }

        private function storeSetting($key)
        {
            $q = $this->db->query('SELECT `value`, serialized FROM ' . $this->t('setting') . " WHERE store_id = 0 AND `key` = '" . $this->db->escape($key) . "' LIMIT 1");
            if (!$q->num_rows) {
                return null;
            }
            return $q->row['serialized'] ? json_decode($q->row['value'], true) : $q->row['value'];
        }

        public function profile()
        {
            $country = $this->db->query('SELECT iso_code_2 FROM ' . $this->t('country') . ' WHERE country_id = ' . (int) $this->storeSetting('config_country_id'))->row;
            $zone = $this->db->query('SELECT code FROM ' . $this->t('zone') . ' WHERE zone_id = ' . (int) $this->storeSetting('config_zone_id'))->row;
            $iso = isset($country['iso_code_2']) ? $country['iso_code_2'] : null;
            // config_address is free text (often several lines); the zone and country make it locatable.
            $address = trim(preg_replace('/\s*[\r\n]+\s*/', ', ', (string) $this->storeSetting('config_address')), ', ');
            $line = implode(', ', array_filter(array($address, isset($zone['code']) ? $zone['code'] : null, $iso)));
            $weight = $this->db->query('SELECT unit FROM ' . $this->t('weight_class_description') . ' WHERE weight_class_id = ' . (int) $this->storeSetting('config_weight_class_id') . ' LIMIT 1')->row;
            return array(
                'name' => (string) $this->storeSetting('config_name'),
                'email' => (string) $this->storeSetting('config_email'),
                'currency' => (string) $this->storeSetting('config_currency'),
                'timezone' => (string) $this->storeSetting('config_timezone') ?: null,
                'country_code' => $iso,
                'weight_unit' => isset($weight['unit']) ? $weight['unit'] : null,
                'address' => array('line' => $line, 'city' => null, 'country_code' => $iso, 'phone' => (string) $this->storeSetting('config_telephone') ?: null),
            );
        }

        // ---- orders ------------------------------------------------------------------------------------------

        /** Status ids: paid (processing + complete), complete, and cancelled (by the standard ids and status names). */
        private function statuses()
        {
            static $memo = null;
            if ($memo !== null) {
                return $memo;
            }
            $processing = array_map('intval', (array) $this->storeSetting('config_processing_status'));
            $complete = array_map('intval', (array) $this->storeSetting('config_complete_status'));
            $cancelled = array(7, 8, 10, 11, 12, 14, 16);
            foreach ($this->db->query('SELECT DISTINCT order_status_id, name FROM ' . $this->t('order_status'))->rows as $s) {
                if (preg_match('/cancel|denied|fail|refund|revers|expir|void|chargeback/i', $s['name'])) {
                    $cancelled[] = (int) $s['order_status_id'];
                }
            }
            return $memo = array('paid' => array_merge($processing, $complete), 'complete' => $complete, 'cancelled' => array_values(array_unique(array_diff($cancelled, $processing, $complete))));
        }

        public function order($ref)
        {
            $ref = ltrim((string) $ref, '#');
            if (!ctype_digit($ref)) {
                return null;
            }
            $o = $this->db->query('SELECT * FROM ' . $this->t('order') . ' WHERE order_id = ' . (int) $ref . ' AND order_status_id > 0')->row;
            return $o ? $this->shape($o) : null;
        }

        public function orders($limit)
        {
            $s = $this->statuses();
            $skip = array_merge($s['cancelled'], $s['complete'], array(0));
            $rows = $this->db->query('SELECT * FROM ' . $this->t('order') . ' WHERE order_status_id NOT IN (' . implode(',', array_map('intval', $skip)) . ') AND date_added > DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY date_added DESC LIMIT ' . (int) $limit)->rows;
            return array_map(array($this, 'shape'), $rows);
        }

        private function kgFactor($weightClassId)
        {
            static $classes = null;
            if ($classes === null) {
                $classes = array();
                foreach ($this->db->query('SELECT wc.weight_class_id, wc.value, wcd.unit FROM ' . $this->t('weight_class') . ' wc LEFT JOIN ' . $this->t('weight_class_description') . ' wcd ON wcd.weight_class_id = wc.weight_class_id')->rows as $r) {
                    $classes[(int) $r['weight_class_id']] = array((float) $r['value'], strtolower((string) $r['unit']));
                }
            }
            $kg = null;
            foreach ($classes as $c) {
                if ($c[1] === 'kg') {
                    $kg = $c[0];
                }
            }
            if (!isset($classes[(int) $weightClassId]) || !$kg || !$classes[(int) $weightClassId][0]) {
                return null;
            }
            return $kg / $classes[(int) $weightClassId][0];
        }

        private function shape(array $o)
        {
            $id = (int) $o['order_id'];
            $s = $this->statuses();
            $status = (int) $o['order_status_id'];
            $products = $this->db->query('SELECT op.order_product_id, op.name, op.model, op.quantity, op.price, op.tax, p.weight, p.weight_class_id, p.shipping, p.sku FROM ' . $this->t('order_product') . ' op LEFT JOIN ' . $this->t('product') . ' p ON p.product_id = op.product_id WHERE op.order_id = ' . $id)->rows;
            $items = array();
            $kgTotal = 0.0;
            foreach ($products as $p) {
                $f = $this->kgFactor($p['weight_class_id']);
                $w = $f && (float) $p['weight'] > 0 ? round((float) $p['weight'] * $f, 3) : null;
                $kgTotal += $w ? $w * (int) $p['quantity'] : 0;
                $items[] = array('id' => (string) $p['order_product_id'], 'title' => (string) $p['name'], 'quantity' => (int) $p['quantity'], 'weight_kg' => $w,
                    'price' => (string) round((float) $p['price'] + (float) $p['tax'], 4), 'sku' => $p['sku'] ?: ($p['model'] ?: null), 'requires_shipping' => $p['shipping'] === null || (bool) $p['shipping']);
            }
            $totals = array();
            foreach ($this->db->query('SELECT code, value FROM ' . $this->t('order_total') . ' WHERE order_id = ' . $id)->rows as $t) {
                $totals[$t['code']] = (float) $t['value'];
            }
            // OpenCart 4.0.2+ stores shipping_method as JSON {name, code}; 3.0 as a title plus shipping_code.
            $method = json_decode((string) $o['shipping_method'], true);
            $code = is_array($method) && isset($method['code']) ? (string) $method['code'] : (isset($o['shipping_code']) ? (string) $o['shipping_code'] : '');
            $title = is_array($method) ? (isset($method['name']) ? (string) $method['name'] : '') : (string) $o['shipping_method'];
            $meta = $this->db->query('SELECT rate_code, is_test FROM ' . $this->t('rentadriver_order') . ' WHERE order_id = ' . $id)->row;
            $zone = $this->db->query('SELECT code FROM ' . $this->t('zone') . ' WHERE zone_id = ' . (int) $o['shipping_zone_id'])->row;
            $country = $this->db->query('SELECT iso_code_2 FROM ' . $this->t('country') . ' WHERE country_id = ' . (int) $o['shipping_country_id'])->row;
            $hasShipping = trim((string) $o['shipping_address_1']) !== '' || trim((string) $o['shipping_city']) !== '';
            // Amounts in the store's default currency (the order table keeps them there; currency_value converts for display).
            $currency = (string) $this->storeSetting('config_currency');
            return array(
                'id' => (string) $id, 'number' => (string) $o['invoice_prefix'] . $id, 'status' => (string) $status, 'currency' => $currency ?: (string) $o['currency_code'],
                'subtotal' => (string) (isset($totals['sub_total']) ? $totals['sub_total'] : 0), 'total' => (string) $o['total'],
                'paid' => in_array($status, $s['paid'], true), 'cancelled' => in_array($status, $s['cancelled'], true), 'fulfilled' => in_array($status, $s['complete'], true),
                'test' => !empty($meta['is_test']), 'created_at' => date('c', strtotime($o['date_added'])),
                'shipping_address' => $hasShipping ? array(
                    'first_name' => $o['shipping_firstname'], 'last_name' => $o['shipping_lastname'], 'company' => $o['shipping_company'] ?: null,
                    'address1' => $o['shipping_address_1'], 'address2' => $o['shipping_address_2'] ?: null, 'city' => $o['shipping_city'],
                    'province_code' => isset($zone['code']) ? $zone['code'] : null, 'zip' => $o['shipping_postcode'], 'country_code' => isset($country['iso_code_2']) ? $country['iso_code_2'] : null,
                    'phone' => $o['telephone'] ?: null,
                ) : null,
                'customer' => array('name' => trim($o['firstname'] . ' ' . $o['lastname']) ?: null, 'phone' => $o['telephone'] ?: null, 'email' => $o['email'] ?: null),
                'note' => $o['comment'] ? substr((string) $o['comment'], 0, 2000) : null,
                'weight_kg' => $kgTotal ?: null,
                'items' => $items,
                'shipping_line' => $code !== '' || $title !== '' ? array('code' => $code, 'title' => $title, 'price' => (string) (isset($totals['shipping']) ? $totals['shipping'] : 0), 'rate_code' => !empty($meta['rate_code']) ? $meta['rate_code'] : Core::rateCodeOf($code)) : null,
            );
        }

        /** First time an order gets a status: remember whether it is a test order and the rate the shopper chose. */
        public function recordOrder($orderId)
        {
            $o = $this->db->query('SELECT shipping_method, ' . ($this->hasColumn('order', 'shipping_code') ? 'shipping_code' : "'' AS shipping_code") . ' FROM ' . $this->t('order') . ' WHERE order_id = ' . (int) $orderId)->row;
            if (!$o) {
                return;
            }
            $method = json_decode((string) $o['shipping_method'], true);
            $code = is_array($method) && isset($method['code']) ? $method['code'] : $o['shipping_code'];
            $rate = Core::rateCodeOf($code);
            $this->db->query('INSERT IGNORE INTO ' . $this->t('rentadriver_order') . ' SET order_id = ' . (int) $orderId . ", rate_code = " . ($rate ? "'" . $this->db->escape($rate) . "'" : 'NULL') . ', is_test = ' . ($this->setting('test_mode') ? 1 : 0) . ', date_added = NOW()');
        }

        private function hasColumn($table, $column)
        {
            return (bool) $this->db->query('SHOW COLUMNS FROM ' . $this->t($table) . " LIKE '" . $this->db->escape($column) . "'")->num_rows;
        }

        public static function installSql($prefix)
        {
            return 'CREATE TABLE IF NOT EXISTS `' . $prefix . 'rentadriver_order` (`order_id` INT(11) NOT NULL PRIMARY KEY, `rate_code` VARCHAR(40) NULL, `is_test` TINYINT(1) NOT NULL DEFAULT 0, `date_added` DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        }

        public function addHistory($orderId, $comment, $notify, $status, array $tracking)
        {
            $o = $this->db->query('SELECT order_status_id FROM ' . $this->t('order') . ' WHERE order_id = ' . (int) $orderId)->row;
            if (!$o) {
                throw new \Exception('order not found');
            }
            $current = (int) $o['order_status_id'];
            $target = $current;
            if ($status === 'shipped' && (int) $this->setting('status_shipped')) {
                $target = (int) $this->setting('status_shipped');
            } elseif ($status === 'delivered' && (int) $this->setting('status_delivered')) {
                $target = (int) $this->setting('status_delivered');
            }
            // Never move a completed order back to "shipped".
            if ($status === 'shipped' && in_array($current, $this->statuses()['complete'], true)) {
                $target = $current;
            }
            self::$writing = true;
            try {
                if ($this->addHistory) {
                    call_user_func($this->addHistory, (int) $orderId, $target, $comment, (bool) $notify);
                } else {
                    $this->db->query('INSERT INTO ' . $this->t('order_history') . ' SET order_id = ' . (int) $orderId . ', order_status_id = ' . $target . ", notify = 0, comment = '" . $this->db->escape($comment) . "', date_added = NOW()");
                    $this->db->query('UPDATE ' . $this->t('order') . ' SET order_status_id = ' . $target . ', date_modified = NOW() WHERE order_id = ' . (int) $orderId);
                }
            } finally {
                self::$writing = false;
            }
            $h = $this->db->query('SELECT MAX(order_history_id) AS id FROM ' . $this->t('order_history') . ' WHERE order_id = ' . (int) $orderId)->row;
            return isset($h['id']) ? (string) $h['id'] : null;
        }

        /** Store-front base URL (https) the module was installed on. */
        public static function storeUrl()
        {
            // Admin defines *_CATALOG for the shop front; the catalog side defines *_SERVER.
            foreach (array('HTTPS_CATALOG', 'HTTP_CATALOG', 'HTTPS_SERVER', 'HTTP_SERVER') as $c) {
                if (defined($c) && strpos(constant($c), 'https://') === 0) {
                    return rtrim(constant($c), '/');
                }
            }
            return null;
        }
    }
}
