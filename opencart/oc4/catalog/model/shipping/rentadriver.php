<?php
/**
 * RentADriver checkout price for OpenCart 4.0.2+. Hidden when RentADriver has no price for the address (outside the
 * delivery area, after cut-off) or cannot be reached quickly.
 *
 * @license GPL-2.0-or-later
 */
namespace Opencart\Catalog\Model\Extension\Rentadriver\Shipping;

require_once DIR_EXTENSION . 'rentadriver/system/library/rentadriver/Core.php';
require_once DIR_EXTENSION . 'rentadriver/system/library/rentadriver/OpenCartBridge.php';

use RentADriver\StoreModule\Core;
use RentADriver\StoreModule\OpenCartBridge;

class Rentadriver extends \Opencart\System\Engine\Model
{
    public function getQuote(array $address): array
    {
        $this->load->language('extension/rentadriver/shipping/rentadriver');
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
                'first_name' => $address['firstname'] ?? null, 'last_name' => $address['lastname'] ?? null, 'company' => $address['company'] ?? null,
                'address1' => $address['address_1'] ?? null, 'address2' => $address['address_2'] ?? null, 'city' => $address['city'] ?? null,
                'province_code' => $address['zone_code'] ?? null, 'zip' => $address['postcode'] ?? null, 'country_code' => $address['iso_code_2'] ?? null,
            ),
            'items' => $items,
            'subtotal' => (string) $this->cart->getSubTotal(),
            'currency' => (string) $this->config->get('config_currency'),
        );
        $key = 'rentadriver_rates_' . md5(json_encode($request));
        $rates = $this->session->data[$key]['rates'] ?? null;
        if (!is_array($rates) || ($this->session->data[$key]['at'] ?? 0) < time() - 300) {
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
