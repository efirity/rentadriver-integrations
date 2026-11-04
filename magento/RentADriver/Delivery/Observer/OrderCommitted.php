<?php
/**
 * Send the order events OrderSaved noted, once the order save has committed.
 *
 * @license GPL-2.0-or-later
 */
namespace RentADriver\Delivery\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use RentADriver\Delivery\Model\Bridge;

class OrderCommitted implements ObserverInterface
{
    /**
     * @var Bridge
     */
    private $bridge;

    /**
     * @param Bridge $bridge
     */
    public function __construct(Bridge $bridge)
    {
        $this->bridge = $bridge;
    }

    /**
     * Send the pending event for this order (sales_order_save_commit_after).
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $o = $observer->getEvent()->getOrder();
        if (!$o || !isset(OrderSaved::$pending[(int) $o->getId()])) {
            return;
        }
        $topic = OrderSaved::$pending[(int) $o->getId()];
        unset(OrderSaved::$pending[(int) $o->getId()]);
        if ($topic === 'order.created') {
            $this->bridge->recordOrder($o);
        }
        $this->bridge->core()->sendEvent($topic, (int) $o->getId());
    }
}
