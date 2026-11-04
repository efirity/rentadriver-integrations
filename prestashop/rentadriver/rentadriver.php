<?php
/**
 * RentADriver Same-Day Delivery for PrestaShop 1.7.8 – 9.x.
 *
 * A carrier module: the checkout asks RentADriver for a same-day price for the cart's address, paid orders are sent to
 * RentADriver (automatic booking follows the shop's RentADriver settings), and delivery progress comes back as order
 * messages, the tracking number and the Shipped / Delivered statuses. Protocol: lib/Core.php.
 *
 * @author    RentADriver <support@rentadriver.ai>
 * @license   GPL-2.0-or-later
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/lib/Core.php';
require_once __DIR__ . '/classes/RentadriverBridge.php';

class Rentadriver extends CarrierModule
{
    const CARRIER_REF = 'RENTADRIVER_CARRIER_REF';

    /** @var int set by PrestaShop to the carrier being priced */
    public $id_carrier;

    public function __construct()
    {
        $this->name = 'rentadriver';
        $this->tab = 'shipping_logistics';
        $this->version = '0.1.2';
        $this->author = 'RentADriver';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.7.8.0', 'max' => '9.99.99');
        parent::__construct();
        $this->displayName = $this->l('RentADriver Same-Day Delivery');
        $this->description = $this->l('Same-day local delivery by vetted RentADriver drivers: live checkout price, automatic booking and tracking.');
        $this->confirmUninstall = $this->l('Disconnect RentADriver and remove the carrier?');
    }

    public function core()
    {
        return new \RentADriver\StoreModule\Core(new RentadriverBridge($this));
    }

    public function install()
    {
        if (!extension_loaded('curl')) {
            $this->_errors[] = $this->l('The cURL PHP extension is required.');
            return false;
        }
        return parent::install()
            && $this->installDb()
            && $this->installCarrier()
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('actionCarrierUpdate')
            && $this->registerHook('displayAdminOrderSide');
    }

    public function uninstall()
    {
        $this->core()->disconnect();
        $carrier = $this->carrier();
        if ($carrier) {
            $carrier->deleted = 1;
            $carrier->update();
        }
        foreach (array(self::CARRIER_REF, 'RENTADRIVER_CFG') as $k) {
            Configuration::deleteByName($k);
        }
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'rentadriver_order`');
        return parent::uninstall();
    }

    private function installDb()
    {
        // Per-order facts PrestaShop has no field for: the rate the shopper chose and whether it was a test order.
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'rentadriver_order` (
            `id_order` INT UNSIGNED NOT NULL PRIMARY KEY,
            `rate_code` VARCHAR(40) NULL,
            `is_test` TINYINT(1) NOT NULL DEFAULT 0,
            `date_add` DATETIME NOT NULL
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    private function installCarrier()
    {
        $carrier = new Carrier();
        $carrier->name = 'RentADriver';
        $carrier->is_module = true;
        $carrier->active = 1;
        $carrier->need_range = 1;
        $carrier->shipping_external = true;
        $carrier->external_module_name = $this->name;
        $carrier->shipping_method = 2;
        $carrier->range_behavior = 1;
        $carrier->url = 'https://rentadriver.ai/track/@';
        foreach (Language::getLanguages(false) as $lang) {
            $carrier->delay[(int) $lang['id_lang']] = $this->l('Same-day local delivery');
        }
        if (!$carrier->add()) {
            return false;
        }
        foreach (Group::getGroups(true) as $group) {
            Db::getInstance()->insert('carrier_group', array('id_carrier' => (int) $carrier->id, 'id_group' => (int) $group['id_group']));
        }
        $range = new RangePrice();
        $range->id_carrier = (int) $carrier->id;
        $range->delimiter1 = '0';
        $range->delimiter2 = '1000000';
        $range->add();
        foreach (Zone::getZones(true) as $zone) {
            $carrier->addZone((int) $zone['id_zone']);
            Db::getInstance()->insert('delivery', array('id_carrier' => (int) $carrier->id, 'id_range_price' => (int) $range->id, 'id_range_weight' => null, 'id_zone' => (int) $zone['id_zone'], 'price' => '0'), true);
        }
        $logo = __DIR__ . '/views/img/carrier.png';
        if (is_file($logo)) {
            @copy($logo, _PS_SHIP_IMG_DIR_ . '/' . (int) $carrier->id . '.jpg');
        }
        Configuration::updateValue(self::CARRIER_REF, (int) $carrier->id);
        return true;
    }

    /** The current carrier (editing a carrier in PrestaShop makes a new id; the reference stays). */
    public function carrier()
    {
        $ref = (int) Configuration::get(self::CARRIER_REF);
        if (!$ref) {
            return null;
        }
        $c = Carrier::getCarrierByReference($ref);
        return $c && Validate::isLoadedObject($c) ? $c : null;
    }

    public function isOurCarrier($idCarrier)
    {
        $c = new Carrier((int) $idCarrier);
        return Validate::isLoadedObject($c) && $c->external_module_name === $this->name;
    }

    public function hookActionCarrierUpdate($params)
    {
        if (isset($params['carrier']) && (int) $params['carrier']->id_reference === (int) Configuration::get(self::CARRIER_REF)) {
            Configuration::updateValue(self::CARRIER_REF, (int) $params['carrier']->id_reference);
        }
    }

    // ---- checkout price ------------------------------------------------------------------------------------------

    public function getOrderShippingCost($params, $shipping_cost)
    {
        return $this->getOrderShippingCostExternal($params);
    }

    /** One price per cart and address per request; false hides the carrier (outside the area, closed, API down). */
    public function getOrderShippingCostExternal($cart)
    {
        static $memo = array();
        // No cookie (back-office order creation, cron): price without the per-visitor cache.
        if (!$cart instanceof Cart || !$cart->id_address_delivery || !$this->core()->connected()) {
            return false;
        }
        $address = new Address((int) $cart->id_address_delivery);
        if (!Validate::isLoadedObject($address)) {
            return false;
        }
        $items = array();
        foreach ($cart->getProducts() as $p) {
            $items[] = array('quantity' => (int) $p['cart_quantity'], 'weight_kg' => $this->kg((float) $p['weight']), 'requires_shipping' => empty($p['is_virtual']));
        }
        $currency = new Currency((int) $cart->id_currency);
        $request = array(
            'destination' => RentadriverBridge::address($address),
            'items' => $items,
            'subtotal' => (string) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS),
            'currency' => $currency->iso_code,
        );
        $key = md5(json_encode($request));
        if (!isset($memo[$key])) {
            $cookie = isset($this->context->cookie) ? $this->context->cookie : null;
            $cache = $cookie && isset($cookie->rd_rates) ? json_decode($cookie->rd_rates, true) : null;
            if (is_array($cache) && isset($cache['k'], $cache['t'], $cache['r']) && $cache['k'] === $key && $cache['t'] > time() - 300) {
                $memo[$key] = $cache['r'];
            } else {
                $rates = $this->core()->rates($request);
                $memo[$key] = $rates ? array('cost' => (float) $rates[0]['cost'], 'code' => (string) $rates[0]['code']) : null;
                if ($cookie) {
                    $cookie->rd_rates = json_encode(array('k' => $key, 't' => time(), 'r' => $memo[$key]));
                }
            }
        }
        if (!$memo[$key]) {
            return false;
        }
        if (isset($this->context->cookie)) {
            $this->context->cookie->rd_rate_code = $memo[$key]['code'];
        }
        // RentADriver prices in the shop's currency; convert when the shopper browses in another one.
        $default = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        return (int) $cart->id_currency === $default ? $memo[$key]['cost'] : Tools::convertPrice($memo[$key]['cost'], $currency);
    }

    private function kg($weight)
    {
        $unit = strtolower((string) Configuration::get('PS_WEIGHT_UNIT'));
        $factor = array('kg' => 1, 'g' => 0.001, 'lb' => 0.45359237, 'lbs' => 0.45359237, 'oz' => 0.028349523);
        return $weight > 0 ? round($weight * (isset($factor[$unit]) ? $factor[$unit] : 1), 3) : null;
    }

    // ---- order events --------------------------------------------------------------------------------------------

    public function hookActionValidateOrder($params)
    {
        if (empty($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return;
        }
        $order = $params['order'];
        $ours = $this->isOurCarrier($order->id_carrier);
        Db::getInstance()->insert('rentadriver_order', array(
            'id_order' => (int) $order->id,
            'rate_code' => $ours && isset($this->context->cookie) && !empty($this->context->cookie->rd_rate_code) ? pSQL($this->context->cookie->rd_rate_code) : null,
            'is_test' => (int) ((bool) $this->cfg('test_mode')),
            'date_add' => date('Y-m-d H:i:s'),
        ), false, true, Db::INSERT_IGNORE);
        $this->core()->sendEvent('order.created', $order->id);
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        if (!empty($params['id_order']) && !RentadriverBridge::$writing) {
            $this->core()->sendEvent('order.updated', (int) $params['id_order']);
        }
    }

    public function hookDisplayAdminOrderSide($params)
    {
        if (empty($params['id_order']) || !$this->core()->connected()) {
            return '';
        }
        $this->context->smarty->assign(array('rd_open' => $this->core()->handoffUrl()));
        return $this->display(__FILE__, 'views/templates/admin/order_side.tpl');
    }

    // ---- module config (stored as one JSON value so the protocol keys stay together) ---------------------------------

    public function cfg($key)
    {
        $all = json_decode((string) Configuration::getGlobalValue('RENTADRIVER_CFG'), true);
        return is_array($all) && isset($all[$key]) ? $all[$key] : null;
    }

    public function setCfg(array $values)
    {
        $all = json_decode((string) Configuration::getGlobalValue('RENTADRIVER_CFG'), true);
        $all = is_array($all) ? $all : array();
        foreach ($values as $k => $v) {
            if ($v === null) {
                unset($all[$k]);
            } else {
                $all[$k] = (string) $v;
            }
        }
        Configuration::updateGlobalValue('RENTADRIVER_CFG', json_encode($all));
    }

    // ---- admin page ----------------------------------------------------------------------------------------------

    public function getContent()
    {
        $core = $this->core();
        $out = '';
        $self = $this->context->link->getAdminLink('AdminModules', true, array(), array('configure' => $this->name));
        if (Tools::isSubmit('rd_connect')) {
            $shop = rtrim(Tools::getShopDomainSsl(true) . __PS_BASE_URI__, '/');
            if (strpos($shop, 'https://') !== 0) {
                $out .= $this->displayError($this->l('Your shop must use HTTPS to connect RentADriver (Shop Parameters > General > Enable SSL).'));
            } else {
                // The admin lands in RentADriver to sign in and finish setup; no admin URL (it carries a token) leaves the shop.
                // Legacy order URL: PrestaShop asks for its token and then opens the order.
                $admin = $shop . '/' . basename(_PS_ADMIN_DIR_) . '/index.php?controller=AdminOrders&vieworder=1&id_order=';
                Tools::redirect($core->connectUrl($shop, $this->endpoint(), null, $admin));
            }
        }
        if (Tools::isSubmit('rd_disconnect')) {
            $core->disconnect();
            $out .= $this->displayConfirmation($this->l('Disconnected.'));
        }
        if (Tools::isSubmit('rd_save')) {
            $this->setCfg(array(
                'test_mode' => Tools::getValue('rd_mark_test_orders') ? '1' : '0',
                'status_shipped' => (string) (int) Tools::getValue('rd_status_shipped'),
                'status_delivered' => (string) (int) Tools::getValue('rd_status_delivered'),
            ));
            $out .= $this->displayConfirmation($this->l('Settings saved.'));
        }
        $states = OrderState::getOrderStates((int) $this->context->language->id);
        $this->context->smarty->assign(array(
            'rd_connected' => $core->connected(),
            'rd_store' => $this->cfg('store_key'),
            'rd_open' => $core->handoffUrl(),
            'rd_setup' => $core->handoffUrl(true),
            'rd_action' => $self,
            'rd_mark_test_orders' => (bool) $this->cfg('test_mode'),
            'rd_states' => $states,
            'rd_status_shipped' => (int) ($this->cfg('status_shipped') ?: Configuration::get('PS_OS_SHIPPING')),
            'rd_status_delivered' => (int) ($this->cfg('status_delivered') ?: Configuration::get('PS_OS_DELIVERED')),
            'rd_carrier' => $this->carrier() ? $this->carrier()->name : null,
            'rd_version' => $this->version,
        ));
        return $out . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    public function endpoint()
    {
        return $this->context->link->getModuleLink($this->name, 'api', array(), true);
    }
}
