<?php
/**
 * RentADriver for OpenCart 4.0.2+: the signed endpoint RentADriver calls, and the order-history event.
 *
 * @license GPL-2.0-or-later
 */
namespace Opencart\Catalog\Controller\Extension\Rentadriver\Shipping;

require_once DIR_EXTENSION . 'rentadriver/system/library/rentadriver/Core.php';
require_once DIR_EXTENSION . 'rentadriver/system/library/rentadriver/OpenCartBridge.php';

use RentADriver\StoreModule\Core;
use RentADriver\StoreModule\OpenCartBridge;

class Rentadriver extends \Opencart\System\Engine\Controller
{
    const VERSION = '0.1.5';

    private function bridge()
    {
        $self = $this;
        return new OpenCartBridge($this->db, $this->config, VERSION, self::VERSION, function ($orderId, $statusId, $comment, $notify) use ($self) {
            $self->load->model('checkout/order');
            $self->model_checkout_order->addHistory($orderId, $statusId, $comment, $notify);
        });
    }

    /** POST index.php?route=extension/rentadriver/shipping/rentadriver.api — JSON in, JSON out. */
    public function api(): void
    {
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            Core::respond(array(405, array('ok' => false, 'error' => 'method_not_allowed')));
        }
        $core = new Core($this->bridge());
        Core::respond($core->handle((string) file_get_contents('php://input'), array(Core::class, 'serverHeader')));
    }

    /** Event catalog/model/checkout/order.addHistory/after: tell RentADriver; it reads the order back. */
    public function onHistory(string &$route, array &$args, &$output): void
    {
        if (OpenCartBridge::$writing || empty($args[0])) {
            return;
        }
        $bridge = $this->bridge();
        $bridge->recordOrder((int) $args[0]);
        (new Core($bridge))->sendEvent('order.updated', (int) $args[0]);
    }
}
