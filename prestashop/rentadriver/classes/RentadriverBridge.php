<?php
/**
 * PrestaShop side of the RentADriver store-module protocol: config, orders in "rd order v1" shape, order history.
 *
 * @license GPL-2.0-or-later
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class RentadriverBridge implements \RentADriver\StoreModule\Bridge
{
    /** True while we write history, so our own status change does not send an event back. */
    public static $writing = false;

    /** @var Rentadriver */
    private $module;

    public function __construct($module)
    {
        $this->module = $module;
    }

    public function platform()
    {
        return 'prestashop';
    }

    public function moduleVersion()
    {
        return $this->module->version;
    }

    public function platformVersion()
    {
        return _PS_VERSION_;
    }

    public function getConfig($key)
    {
        $v = $this->module->cfg($key);
        return $v === null ? null : (string) $v;
    }

    public function setConfig(array $values)
    {
        $this->module->setCfg($values);
    }

    public function profile()
    {
        $country = new Country((int) Configuration::get('PS_SHOP_COUNTRY_ID'));
        $state = Configuration::get('PS_SHOP_STATE_ID') ? new State((int) Configuration::get('PS_SHOP_STATE_ID')) : null;
        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $city = (string) Configuration::get('PS_SHOP_CITY');
        $line = implode(', ', array_filter(array(
            Configuration::get('PS_SHOP_ADDR1'), Configuration::get('PS_SHOP_ADDR2'), $city,
            $state && Validate::isLoadedObject($state) ? $state->iso_code : null, Configuration::get('PS_SHOP_CODE'), $country->iso_code,
        )));
        return array(
            'name' => (string) Configuration::get('PS_SHOP_NAME'),
            'email' => (string) Configuration::get('PS_SHOP_EMAIL'),
            'currency' => $currency->iso_code,
            'timezone' => (string) Configuration::get('PS_TIMEZONE'),
            'country_code' => $country->iso_code ?: null,
            'weight_unit' => (string) Configuration::get('PS_WEIGHT_UNIT'),
            'address' => array('line' => $line, 'city' => $city ?: null, 'country_code' => $country->iso_code ?: null, 'phone' => Configuration::get('PS_SHOP_PHONE') ?: null),
        );
    }

    public static function address(Address $a)
    {
        $country = new Country((int) $a->id_country);
        $state = $a->id_state ? new State((int) $a->id_state) : null;
        return array(
            'first_name' => $a->firstname, 'last_name' => $a->lastname, 'company' => $a->company ?: null,
            'address1' => $a->address1, 'address2' => $a->address2 ?: null, 'city' => $a->city,
            'province_code' => $state && Validate::isLoadedObject($state) ? $state->iso_code : null,
            'zip' => $a->postcode, 'country_code' => $country->iso_code, 'phone' => $a->phone_mobile ?: ($a->phone ?: null),
        );
    }

    private function load($ref)
    {
        $ref = (string) $ref;
        if (ctype_digit($ref)) {
            $o = new Order((int) $ref);
            if (Validate::isLoadedObject($o)) {
                return $o;
            }
        }
        $found = Order::getByReference(ltrim($ref, '#'));
        $first = $found ? $found->getFirst() : null;
        return $first && Validate::isLoadedObject($first) ? $first : null;
    }

    public function order($ref)
    {
        $o = $this->load($ref);
        return $o ? $this->shape($o) : null;
    }

    private function shape(Order $o)
    {
        $state = (int) $o->getCurrentState();
        $cancelled = in_array($state, array_map('intval', array(Configuration::get('PS_OS_CANCELED'), Configuration::get('PS_OS_REFUND'), Configuration::get('PS_OS_ERROR'))), true);
        $customer = new Customer((int) $o->id_customer);
        $delivery = new Address((int) $o->id_address_delivery);
        $carrier = new Carrier((int) $o->id_carrier);
        $ours = Validate::isLoadedObject($carrier) && $carrier->external_module_name === 'rentadriver';
        $meta = Db::getInstance()->getRow('SELECT rate_code, is_test FROM `' . _DB_PREFIX_ . 'rentadriver_order` WHERE id_order = ' . (int) $o->id);
        $items = array();
        $kg = 0.0;
        foreach ($o->getProducts() as $p) {
            $w = $this->kg((float) $p['product_weight']);
            $kg += $w ? $w * (int) $p['product_quantity'] : 0;
            $items[] = array(
                'id' => (string) $p['id_order_detail'], 'title' => (string) $p['product_name'], 'quantity' => (int) $p['product_quantity'],
                'weight_kg' => $w, 'price' => (string) $p['unit_price_tax_incl'], 'sku' => $p['product_reference'] ?: null,
                'requires_shipping' => empty($p['is_virtual']),
            );
        }
        $currency = new Currency((int) $o->id_currency);
        $messages = Message::getMessagesByOrderId((int) $o->id, false);
        return array(
            'id' => (string) $o->id, 'number' => $o->reference, 'status' => (string) $state, 'currency' => $currency->iso_code,
            'subtotal' => (string) $o->total_products_wt, 'total' => (string) $o->total_paid_tax_incl,
            'paid' => (bool) $o->hasBeenPaid() || (bool) $o->valid, 'cancelled' => $cancelled, 'fulfilled' => (bool) $o->hasBeenShipped(),
            'test' => !empty($meta['is_test']), 'created_at' => date('c', strtotime($o->date_add)),
            'shipping_address' => Validate::isLoadedObject($delivery) ? self::address($delivery) : null,
            'customer' => array('name' => trim($customer->firstname . ' ' . $customer->lastname) ?: null, 'phone' => Validate::isLoadedObject($delivery) ? ($delivery->phone_mobile ?: ($delivery->phone ?: null)) : null, 'email' => $customer->email ?: null),
            'note' => $messages ? substr((string) $messages[count($messages) - 1]['message'], 0, 2000) : null,
            'weight_kg' => $kg ?: null,
            'items' => $items,
            'shipping_line' => array('code' => $ours ? 'rentadriver' : 'carrier_' . (int) $o->id_carrier, 'title' => $carrier->name, 'price' => (string) $o->total_shipping_tax_incl, 'rate_code' => $ours && !empty($meta['rate_code']) ? $meta['rate_code'] : null),
        );
    }

    private function kg($weight)
    {
        $unit = strtolower((string) Configuration::get('PS_WEIGHT_UNIT'));
        $factor = array('kg' => 1, 'g' => 0.001, 'lb' => 0.45359237, 'lbs' => 0.45359237, 'oz' => 0.028349523);
        return $weight > 0 ? round($weight * (isset($factor[$unit]) ? $factor[$unit] : 1), 3) : null;
    }

    public function orders($limit)
    {
        $skip = array_map('intval', array(Configuration::get('PS_OS_CANCELED'), Configuration::get('PS_OS_REFUND'), Configuration::get('PS_OS_ERROR'), Configuration::get('PS_OS_DELIVERED')));
        $ids = Db::getInstance()->executeS('SELECT id_order FROM `' . _DB_PREFIX_ . 'orders` WHERE current_state NOT IN (' . implode(',', $skip) . ') AND date_add > DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY date_add DESC LIMIT ' . (int) $limit);
        $out = array();
        foreach ($ids ?: array() as $row) {
            $o = new Order((int) $row['id_order']);
            if (Validate::isLoadedObject($o)) {
                $out[] = $this->shape($o);
            }
        }
        return $out;
    }

    public function addHistory($orderId, $comment, $notify, $status, array $tracking)
    {
        $o = $this->load($orderId);
        if (!$o) {
            throw new Exception('order not found');
        }
        self::$writing = true;
        try {
            // The tracking number goes on the order's carrier line, so PrestaShop's own "shipped" email links it.
            if (!empty($tracking['number'])) {
                $oc = new OrderCarrier((int) $o->getIdOrderCarrier());
                if (Validate::isLoadedObject($oc) && $oc->tracking_number !== $tracking['number'] && $this->module->isOurCarrier($oc->id_carrier)) {
                    $oc->tracking_number = pSQL($tracking['number']);
                    $oc->update();
                }
            }
            $msg = new Message();
            $msg->message = Tools::substr(strip_tags($comment), 0, 1600);
            $msg->id_order = (int) $o->id;
            $msg->id_cart = (int) $o->id_cart;
            $msg->id_customer = (int) $o->id_customer;
            $msg->private = $notify ? 0 : 1;
            $msg->add();
            $target = $status === 'shipped' ? (int) ($this->getConfig('status_shipped') ?: Configuration::get('PS_OS_SHIPPING'))
                : ($status === 'delivered' ? (int) ($this->getConfig('status_delivered') ?: Configuration::get('PS_OS_DELIVERED')) : 0);
            if ($target && (int) $o->getCurrentState() !== $target && !$o->hasBeenDelivered()) {
                $h = new OrderHistory();
                $h->id_order = (int) $o->id;
                $h->changeIdOrderState($target, $o, true);
                // addWithemail sends the status email only when the status itself is set to send one.
                $notify ? $h->addWithemail(true) : $h->add();
            }
            return (string) $msg->id;
        } finally {
            self::$writing = false;
        }
    }
}
