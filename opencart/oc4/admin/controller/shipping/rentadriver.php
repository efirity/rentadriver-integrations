<?php
/**
 * RentADriver Same-Day Delivery for OpenCart 4.0.2+: admin settings, connect, and install/uninstall.
 *
 * @license GPL-2.0-or-later
 */
namespace Opencart\Admin\Controller\Extension\Rentadriver\Shipping;

require_once DIR_EXTENSION . 'rentadriver/system/library/rentadriver/Core.php';
require_once DIR_EXTENSION . 'rentadriver/system/library/rentadriver/OpenCartBridge.php';

use RentADriver\StoreModule\Core;
use RentADriver\StoreModule\OpenCartBridge;

class Rentadriver extends \Opencart\System\Engine\Controller
{
    const VERSION = '0.1.5';
    const ROUTE = 'extension/rentadriver/shipping/rentadriver';

    private function bridge()
    {
        return new OpenCartBridge($this->db, $this->config, VERSION, self::VERSION);
    }

    private function core()
    {
        return new Core($this->bridge());
    }

    private function link($method = '', array $q = array())
    {
        return $this->url->link(self::ROUTE . ($method !== '' ? '.' . $method : ''), http_build_query(array_merge(array('user_token' => $this->session->data['user_token']), $q)));
    }

    public function index(): void
    {
        $this->load->language(self::ROUTE);
        $this->document->setTitle($this->language->get('heading_title'));
        $core = $this->core();
        $data = array();
        $data['breadcrumbs'] = array(
            array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])),
            array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping')),
            array('text' => $this->language->get('heading_title'), 'href' => $this->link()),
        );
        $data['save'] = $this->link('save');
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping');
        $data['connect'] = $this->link('connect');
        $data['disconnect'] = $this->link('disconnect');
        $data['connected'] = $core->connected();
        $data['store_key'] = (string) $this->bridge()->getConfig('store_key');
        $data['open'] = $core->handoffUrl();
        $data['setup'] = $core->handoffUrl(true);
        $data['https'] = OpenCartBridge::storeUrl() !== null;
        $data['version'] = self::VERSION;
        foreach (array('status', 'sort_order', 'test_mode', 'status_shipped', 'status_delivered', 'tax_class_id') as $k) {
            $data['shipping_rentadriver_' . $k] = $this->config->get('shipping_rentadriver_' . $k);
        }
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $this->load->model('localisation/tax_class');
        $data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();
        $data['user_token'] = $this->session->data['user_token'];
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view(self::ROUTE, $data));
    }

    public function save(): void
    {
        $this->load->language(self::ROUTE);
        $json = array();
        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $json['error']['warning'] = $this->language->get('error_permission');
        }
        if (!$json) {
            // Keep the protocol keys (shipping_rentadriver_rd_*): editSetting replaces the whole code.
            $keep = array();
            foreach ($this->db->query("SELECT `key`, `value` FROM `" . DB_PREFIX . "setting` WHERE store_id = 0 AND `code` = 'shipping_rentadriver' AND `key` LIKE 'shipping\\_rentadriver\\_rd\\_%'")->rows as $r) {
                $keep[$r['key']] = $r['value'];
            }
            $post = array();
            foreach (array('status', 'sort_order', 'test_mode', 'status_shipped', 'status_delivered', 'tax_class_id') as $k) {
                $post['shipping_rentadriver_' . $k] = isset($this->request->post['shipping_rentadriver_' . $k]) ? (string) $this->request->post['shipping_rentadriver_' . $k] : '';
            }
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('shipping_rentadriver', $post + $keep);
            $json['success'] = $this->language->get('text_success');
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function connect(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $this->response->redirect($this->link());
            return;
        }
        $store = OpenCartBridge::storeUrl();
        if ($store === null) {
            $this->session->data['error'] = $this->language->get('error_https');
            $this->response->redirect($this->link());
            return;
        }
        $endpoint = $store . '/index.php?route=extension/rentadriver/shipping/rentadriver.api';
        // After connecting, the admin lands in RentADriver to sign in and finish setup (no admin URL or token leaves the shop).
        $this->response->redirect($this->core()->connectUrl($store, $endpoint, null, rtrim(HTTP_SERVER, '/') . '/index.php?route=sale/order.info&order_id='));
    }

    public function disconnect(): void
    {
        if ($this->user->hasPermission('modify', self::ROUTE)) {
            $this->core()->disconnect();
        }
        $this->response->redirect($this->link());
    }

    public function install(): void
    {
        if (!$this->user->hasPermission('modify', 'extension/shipping')) {
            return;
        }
        $this->db->query(OpenCartBridge::installSql(DB_PREFIX));
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('rentadriver');
        $this->model_setting_event->addEvent(array(
            'code' => 'rentadriver', 'description' => 'RentADriver: send order changes',
            'trigger' => 'catalog/model/checkout/order.addHistory/after',
            'action' => 'extension/rentadriver/shipping/rentadriver.onHistory', 'status' => 1, 'sort_order' => 0,
        ));
        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('shipping_rentadriver', array('shipping_rentadriver_status' => '1', 'shipping_rentadriver_sort_order' => '1'));
    }

    public function uninstall(): void
    {
        if (!$this->user->hasPermission('modify', 'extension/shipping')) {
            return;
        }
        $this->core()->disconnect();
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('rentadriver');
        $this->db->query('DROP TABLE IF EXISTS `' . DB_PREFIX . 'rentadriver_order`');
    }
}
