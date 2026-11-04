<?php
/**
 * Note which orders changed state in this request; OrderCommitted sends them after the transaction commits, so a slow
 * network never holds a database transaction open and a rolled-back save sends nothing.
 *
 * @license GPL-2.0-or-later
 */
namespace RentADriver\Delivery\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use RentADriver\Delivery\Model\Bridge;

class OrderSaved implements ObserverInterface
{
    /**
     * Order id => topic, waiting for the commit.
     *
     * @var array<int, string>
     */
    public static $pending = [];

    /**
     * Remember new orders and state or status changes (sales_order_save_after).
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $o = $observer->getEvent()->getOrder();
        if (!$o || !$o->getId() || Bridge::$writing) {
            return;
        }
        $new = !$o->getOrigData('entity_id');
        if ($new || $o->dataHasChangedFor('state') || $o->dataHasChangedFor('status')) {
            self::$pending[(int) $o->getId()] = $new ? 'order.created' : 'order.updated';
        }
    }
}
