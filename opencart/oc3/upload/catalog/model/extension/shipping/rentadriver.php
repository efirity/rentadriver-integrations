<?php
/**
 * RentADriver checkout price for OpenCart 3.0.x. Hidden when RentADriver has no price for the address (outside the
 * delivery area, after cut-off) or cannot be reached quickly.
 *
 * @license GPL-2.0-or-later
 */
require_once DIR_SYSTEM . 'library/rentadriver/Core.php';
require_once DIR_SYSTEM . 'library/rentadriver/OpenCartBridge.php';

use RentADriver\StoreModule\Core;
use RentADriver\StoreModule\OpenCartBridge;

class ModelExtensionShippingRentadriver extends Model
{
    public function getQuote($address)
    {
        $this->load->language('extension/shipping/rentadriver');
        if (!$this->config->get('shipping_rentadriver_status')) {
            return array();
        }
        $core = new Core(new OpenCartBridge($this->db, $this->config, VERSION, '0.1.5'));
        if (!$core->connected()) {
            return array();
        }
        $kgId = 0;
        foreach ($this->db->query("SELECT weight_class_id FROM `" . DB_PREFIX . "weight_class_description` WHERE unit = 'kg' LIMIT 1")->rows as $r) {
            $kgId = (int) $r['weight_class_id'];
        }
        $items = array();
        foreach ($this->cart->getProducts() as $p) {
            $w = $kgId && (float) $p['weight'] > 0 ? round($this->weight->convert((float) $p['weight'], (int) $p['weight_class_id'], $kgId) / max(1, (int) $p['quantity']), 3) : null;
            $items[] = array('quantity' => (int) $p['quantity'], 'weight_kg' => $w, 'requires_shipping' => !empty($p['shipping']));
        }
        $request = array(
            'destination' => array(
                'first_name' => isset($address['firstname']) ? $address['firstname'] : null, 'last_name' => isset($address['lastname']) ? $address['lastname'] : null, 'company' => isset($address['company']) ? $address['company'] : null,
                'address1' => isset($address['address_1']) ? $address['address_1'] : null, 'address2' => isset($address['address_2']) ? $address['address_2'] : null, 'city' => isset($address['city']) ? $address['city'] : null,
                'province_code' => isset($address['zone_code']) ? $address['zone_code'] : null, 'zip' => isset($address['postcode']) ? $address['postcode'] : null, 'country_code' => isset($address['iso_code_2']) ? $address['iso_code_2'] : null,
            ),
            'items' => $items,
            'subtotal' => (string) $this->cart->getSubTotal(),
            'currency' => (string) $this->config->get('config_currency'),
        );
        $key = 'rentadriver_rates_' . md5(json_encode($request));
        $cached = isset($this->session->data[$key]) ? $this->session->data[$key] : array();
        $rates = isset($cached['rates']) ? $cached['rates'] : null;
        if (!is_array($rates) || (isset($cached['at']) ? $cached['at'] : 0) < time() - 300) {
            $rates = $core->rates($request);
            $this->session->data[$key] = array('at' => time(), 'rates' => $rates);
        }
        if (!$rates) {
            return array();
        }
        $quote = array();
        foreach ($rates as $r) {
            $code = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) $r['code']));
            $cost = (float) $r['cost'];
            $tax = (int) $this->config->get('shipping_rentadriver_tax_class_id');
            $quote[$code] = array(
                'code' => 'rentadriver.' . $code,
                'name' => (string) $r['title'],
                'title' => (string) $r['title'],
                'cost' => $cost,
                'tax_class_id' => $tax,
                'text' => $this->currency->format($this->tax->calculate($cost, $tax, $this->config->get('config_tax')), $this->session->data['currency']),
            );
        }
        return array(
            'code' => 'rentadriver',
            'name' => $this->language->get('heading_title'),
            'title' => $this->language->get('heading_title'),
            'quote' => $quote,
            'sort_order' => (int) $this->config->get('shipping_rentadriver_sort_order'),
            'error' => false,
        );
    }
}
