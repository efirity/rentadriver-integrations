<?php
/**
 * RentADriver for OpenCart 3.0.x: the signed endpoint RentADriver calls, and the order-history event.
 *
 * @license GPL-2.0-or-later
 */
require_once DIR_SYSTEM . 'library/rentadriver/Core.php';
require_once DIR_SYSTEM . 'library/rentadriver/OpenCartBridge.php';

class ControllerExtensionShippingRentadriver extends Controller
{
    const VERSION = '0.1.5';

    private function bridge()
    {
        $self = $this;
        return new \RentADriver\StoreModule\OpenCartBridge($this->db, $this->config, VERSION, self::VERSION, function ($orderId, $statusId, $comment, $notify) use ($self) {
            $self->load->model('checkout/order');
            $self->model_checkout_order->addOrderHistory($orderId, $statusId, $comment, $notify);
        });
    }

    /** POST index.php?route=extension/shipping/rentadriver/api — JSON in, JSON out. */
    public function api()
    {
        if (!isset($this->request->server['REQUEST_METHOD']) || $this->request->server['REQUEST_METHOD'] !== 'POST') {
            \RentADriver\StoreModule\Core::respond(array(405, array('ok' => false, 'error' => 'method_not_allowed')));
        }
        $core = new \RentADriver\StoreModule\Core($this->bridge());
        \RentADriver\StoreModule\Core::respond($core->handle((string) file_get_contents('php://input'), array('\RentADriver\StoreModule\Core', 'serverHeader')));
    }

    /** Event catalog/model/checkout/order/addOrderHistory/after: tell RentADriver; it reads the order back. */
    public function onHistory(&$route, &$args, &$output)
    {
        if (\RentADriver\StoreModule\OpenCartBridge::$writing || empty($args[0])) {
            return;
        }
        $bridge = $this->bridge();
        $bridge->recordOrder((int) $args[0]);
        $core = new \RentADriver\StoreModule\Core($bridge);
        $core->sendEvent('order.updated', (int) $args[0]);
    }
}
