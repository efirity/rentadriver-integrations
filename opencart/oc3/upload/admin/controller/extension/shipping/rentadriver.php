<?php
/**
 * RentADriver Same-Day Delivery for OpenCart 3.0.x: admin settings, connect, and install/uninstall.
 *
 * @license GPL-2.0-or-later
 */
require_once DIR_SYSTEM . 'library/rentadriver/Core.php';
require_once DIR_SYSTEM . 'library/rentadriver/OpenCartBridge.php';

class ControllerExtensionShippingRentadriver extends Controller
{
    const VERSION = '0.1.5';
    const ROUTE = 'extension/shipping/rentadriver';
    private $keys = array('status', 'sort_order', 'test_mode', 'status_shipped', 'status_delivered', 'tax_class_id');

    private function bridge()
    {
        return new \RentADriver\StoreModule\OpenCartBridge($this->db, $this->config, VERSION, self::VERSION);
    }

    private function core()
    {
        return new \RentADriver\StoreModule\Core($this->bridge());
    }

    private function link($route)
    {
        return $this->url->link($route, 'user_token=' . $this->session->data['user_token'], true);
    }

    public function index()
    {
        $this->load->language(self::ROUTE);
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');
        $data = array();
        if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->user->hasPermission('modify', self::ROUTE)) {
            // Keep the protocol keys (shipping_rentadriver_rd_*): editSetting replaces the whole code.
            $keep = array();
            foreach ($this->db->query("SELECT `key`, `value` FROM `" . DB_PREFIX . "setting` WHERE store_id = 0 AND `code` = 'shipping_rentadriver' AND `key` LIKE 'shipping\\_rentadriver\\_rd\\_%'")->rows as $r) {
                $keep[$r['key']] = $r['value'];
            }
            $post = array();
            foreach ($this->keys as $k) {
                $post['shipping_rentadriver_' . $k] = isset($this->request->post['shipping_rentadriver_' . $k]) ? (string) $this->request->post['shipping_rentadriver_' . $k] : '';
            }
            $this->model_setting_setting->editSetting('shipping_rentadriver', $post + $keep);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->link(self::ROUTE));
        }
        $data['error_warning'] = isset($this->session->data['error']) ? $this->session->data['error'] : '';
        unset($this->session->data['error']);
        $data['success'] = isset($this->session->data['success']) ? $this->session->data['success'] : '';
        unset($this->session->data['success']);
        $data['breadcrumbs'] = array(
            array('text' => $this->language->get('text_home'), 'href' => $this->link('common/dashboard')),
            array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true)),
            array('text' => $this->language->get('heading_title'), 'href' => $this->link(self::ROUTE)),
        );
        $core = $this->core();
        $data['action'] = $this->link(self::ROUTE);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true);
        $data['connect'] = $this->link(self::ROUTE . '/connect');
        $data['disconnect'] = $this->link(self::ROUTE . '/disconnect');
        $data['connected'] = $core->connected();
        $data['store_key'] = (string) $this->bridge()->getConfig('store_key');
        $data['open'] = $core->handoffUrl();
        $data['setup'] = $core->handoffUrl(true);
        $data['https'] = \RentADriver\StoreModule\OpenCartBridge::storeUrl() !== null;
        $data['version'] = self::VERSION;
        foreach ($this->keys as $k) {
            $data['shipping_rentadriver_' . $k] = $this->config->get('shipping_rentadriver_' . $k);
        }
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $this->load->model('localisation/tax_class');
        $data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view(self::ROUTE, $data));
    }

    public function connect()
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $this->response->redirect($this->link(self::ROUTE));
            return;
        }
        $store = \RentADriver\StoreModule\OpenCartBridge::storeUrl();
        if ($store === null) {
            $this->session->data['error'] = $this->language->get('error_https');
            $this->response->redirect($this->link(self::ROUTE));
            return;
        }
        // After connecting, the admin lands in RentADriver to sign in and finish setup (no admin URL or token leaves the shop).
        $admin = defined('HTTPS_SERVER') ? HTTPS_SERVER : HTTP_SERVER;
        $this->response->redirect($this->core()->connectUrl($store, $store . '/index.php?route=extension/shipping/rentadriver/api', null, rtrim($admin, '/') . '/index.php?route=sale/order/info&order_id='));
    }

    public function disconnect()
    {
        if ($this->user->hasPermission('modify', self::ROUTE)) {
            $this->core()->disconnect();
        }
        $this->response->redirect($this->link(self::ROUTE));
    }

    public function install()
    {
        $this->db->query(\RentADriver\StoreModule\OpenCartBridge::installSql(DB_PREFIX));
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('rentadriver');
        $this->model_setting_event->addEvent('rentadriver', 'catalog/model/checkout/order/addOrderHistory/after', 'extension/shipping/rentadriver/onHistory');
        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('shipping_rentadriver', array('shipping_rentadriver_status' => '1', 'shipping_rentadriver_sort_order' => '1'));
    }

    public function uninstall()
    {
        $this->core()->disconnect();
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('rentadriver');
        $this->db->query('DROP TABLE IF EXISTS `' . DB_PREFIX . 'rentadriver_order`');
    }
}
