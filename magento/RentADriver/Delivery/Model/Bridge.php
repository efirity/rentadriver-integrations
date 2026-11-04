<?php
/**
 * Magento side of the RentADriver store-module protocol: protocol keys, orders in "rd order v1" shape, history and
 * shipments. Protocol keys live in core_config_data (rentadriver/protocol/*) and are read straight from the table, so
 * the config cache never serves a stale secret.
 *
 * @license GPL-2.0-or-later
 */
namespace RentADriver\Delivery\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use RentADriver\Delivery\Lib\Core;

class Bridge implements \RentADriver\Delivery\Lib\Bridge
{
    public const VERSION = '0.1.4';
    public const PATH = 'rentadriver/protocol/';
    private const LB_TO_KG = 0.45359237;

    /**
     * True while we write, so our own order save does not send an event back.
     *
     * @var bool
     */
    public static $writing = false;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var ScopeConfigInterface
     */
    private $scope;

    /**
     * @var OrderRepositoryInterface
     */
    private $orders;

    /**
     * @var CollectionFactory
     */
    private $collection;

    /**
     * @var ShipOrderInterface
     */
    private $ship;

    /**
     * @var ShipmentTrackCreationInterfaceFactory
     */
    private $tracks;

    /**
     * @var ShipmentCommentCreationInterfaceFactory
     */
    private $shipmentComments;

    /**
     * @var OrderCommentSender
     */
    private $commentSender;

    /**
     * @var ProductMetadataInterface
     */
    private $meta;

    /**
     * @var StoreManagerInterface
     */
    private $stores;

    /**
     * @param ResourceConnection $resource
     * @param ScopeConfigInterface $scope
     * @param OrderRepositoryInterface $orders
     * @param CollectionFactory $collection
     * @param ShipOrderInterface $ship
     * @param ShipmentTrackCreationInterfaceFactory $tracks
     * @param ShipmentCommentCreationInterfaceFactory $shipmentComments
     * @param OrderCommentSender $commentSender
     * @param ProductMetadataInterface $meta
     * @param StoreManagerInterface $stores
     */
    public function __construct(
        ResourceConnection $resource,
        ScopeConfigInterface $scope,
        OrderRepositoryInterface $orders,
        CollectionFactory $collection,
        ShipOrderInterface $ship,
        ShipmentTrackCreationInterfaceFactory $tracks,
        ShipmentCommentCreationInterfaceFactory $shipmentComments,
        OrderCommentSender $commentSender,
        ProductMetadataInterface $meta,
        StoreManagerInterface $stores
    ) {
        $this->resource = $resource;
        $this->scope = $scope;
        $this->orders = $orders;
        $this->collection = $collection;
        $this->ship = $ship;
        $this->tracks = $tracks;
        $this->shipmentComments = $shipmentComments;
        $this->commentSender = $commentSender;
        $this->meta = $meta;
        $this->stores = $stores;
    }

    /**
     * The protocol core bound to this store.
     *
     * @return Core
     */
    public function core()
    {
        return new Core($this);
    }

    /**
     * @inheritdoc
     */
    public function platform()
    {
        return 'magento';
    }

    /**
     * @inheritdoc
     */
    public function moduleVersion()
    {
        return self::VERSION;
    }

    /**
     * @inheritdoc
     */
    public function platformVersion()
    {
        return $this->meta->getVersion();
    }

    /**
     * @inheritdoc
     */
    public function getConfig($key)
    {
        $db = $this->resource->getConnection();
        $select = $db->select()
            ->from($this->resource->getTableName('core_config_data'), 'value')
            ->where('scope = ?', 'default')
            ->where('scope_id = ?', 0)
            ->where('path = ?', self::PATH . $key);
        $v = $db->fetchOne($select);
        return $v === false ? null : (string) $v;
    }

    /**
     * @inheritdoc
     */
    public function setConfig(array $values)
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('core_config_data');
        foreach ($values as $key => $value) {
            $db->delete($table, ['scope = ?' => 'default', 'scope_id = ?' => 0, 'path = ?' => self::PATH . $key]);
            if ($value !== null) {
                $db->insert(
                    $table,
                    ['scope' => 'default', 'scope_id' => 0, 'path' => self::PATH . $key, 'value' => (string) $value]
                );
            }
        }
    }

    /**
     * The default store view's secure base URL, or null when it is not HTTPS.
     *
     * @return string|null
     */
    public function storeUrl()
    {
        $base = $this->stores->getDefaultStoreView()->getBaseUrl(UrlInterface::URL_TYPE_WEB, true);
        $url = rtrim((string) $base, '/');
        return strpos($url, 'https://') === 0 ? $url : null;
    }

    /**
     * @inheritdoc
     */
    public function profile()
    {
        $c = function ($path) {
            return (string) $this->scope->getValue($path);
        };
        $country = $c('general/store_information/country_id') ?: $c('shipping/origin/country_id');
        $city = $c('shipping/origin/city') ?: $c('general/store_information/city');
        $line = implode(', ', array_filter([
            $c('shipping/origin/street_line1') ?: $c('general/store_information/street_line1'),
            $c('shipping/origin/street_line2') ?: $c('general/store_information/street_line2'),
            $city,
            $c('shipping/origin/postcode') ?: $c('general/store_information/postcode'),
            $country,
        ]));
        return [
            'name' => $c('general/store_information/name') ?: $this->stores->getDefaultStoreView()->getFrontendName(),
            'email' => $c('trans_email/ident_general/email'),
            'currency' => $c('currency/options/base'),
            'timezone' => $c('general/locale/timezone') ?: null,
            'country_code' => $country ?: null,
            'weight_unit' => $c('general/locale/weight_unit') === 'lbs' ? 'lb' : 'kg',
            'address' => [
                'line' => $line,
                'city' => $city ?: null,
                'country_code' => $country ?: null,
                'phone' => $c('general/store_information/phone') ?: null,
            ],
        ];
    }

    /**
     * An order by display number (increment id) or entity id.
     *
     * @param string|int $ref
     * @return OrderInterface|null
     */
    private function load($ref)
    {
        $ref = ltrim((string) $ref, '#');
        if ($ref === '') {
            return null;
        }
        // Our order id is the entity id; a display number (increment id) works too.
        $found = $this->collection->create()->addFieldToFilter('increment_id', $ref)->setPageSize(1)->getFirstItem();
        if ($found && $found->getId()) {
            return $this->orders->get((int) $found->getId());
        }
        if (ctype_digit($ref)) {
            try {
                return $this->orders->get((int) $ref);
            } catch (NoSuchEntityException $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * @inheritdoc
     */
    public function order($ref)
    {
        $o = $this->load($ref);
        return $o ? $this->shape($o) : null;
    }

    /**
     * @inheritdoc
     */
    public function orders($limit)
    {
        $open = [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT, Order::STATE_PROCESSING];
        $list = $this->collection->create()
            ->addFieldToFilter('state', ['in' => $open])
            ->addFieldToFilter('created_at', ['gt' => date('Y-m-d H:i:s', time() - 30 * 86400)])
            ->setOrder('created_at', 'DESC')
            ->setPageSize((int) $limit);
        $out = [];
        foreach ($list as $o) {
            $out[] = $this->shape($this->orders->get((int) $o->getId()));
        }
        return $out;
    }

    /**
     * A weight in the store's unit as kilograms, or null when unknown.
     *
     * @param float|string|null $weight
     * @return float|null
     */
    private function kg($weight)
    {
        $w = (float) $weight;
        if ($w <= 0) {
            return null;
        }
        return round($this->scope->getValue('general/locale/weight_unit') === 'lbs' ? $w * self::LB_TO_KG : $w, 3);
    }

    /**
     * One order in "rd order v1" shape.
     *
     * @param Order $o
     * @return array
     */
    private function shape($o)
    {
        $db = $this->resource->getConnection();
        $select = $db->select()
            ->from($this->resource->getTableName('rentadriver_order'))
            ->where('order_id = ?', (int) $o->getId());
        $meta = $db->fetchRow($select) ?: [];
        $items = [];
        foreach ($o->getAllVisibleItems() as $i) {
            $items[] = [
                'id' => (string) $i->getId(),
                'title' => (string) $i->getName(),
                'quantity' => (int) $i->getQtyOrdered(),
                'weight_kg' => $this->kg($i->getWeight()),
                'price' => (string) $i->getBasePriceInclTax(),
                'sku' => $i->getSku() ?: null,
                'requires_shipping' => !$i->getIsVirtual(),
            ];
        }
        $a = $o->getShippingAddress();
        $method = (string) $o->getShippingMethod();
        $state = (string) $o->getState();
        $grandTotal = (float) $o->getBaseGrandTotal();
        return [
            'id' => (string) $o->getId(),
            'number' => (string) $o->getIncrementId(),
            'status' => (string) $o->getStatus(),
            'currency' => (string) $o->getBaseCurrencyCode(),
            'subtotal' => (string) $o->getBaseSubtotalInclTax(),
            'total' => (string) $o->getBaseGrandTotal(),
            'paid' => in_array($state, [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true)
                || ((float) $o->getBaseTotalDue() <= 0 && $grandTotal > 0),
            'cancelled' => in_array($state, [Order::STATE_CANCELED, Order::STATE_CLOSED], true),
            'fulfilled' => (bool) $o->hasShipments(),
            'test' => !empty($meta['is_test']),
            'created_at' => date('c', strtotime((string) $o->getCreatedAt())),
            'shipping_address' => $a ? [
                'first_name' => $a->getFirstname(),
                'last_name' => $a->getLastname(),
                'company' => $a->getCompany() ?: null,
                'address1' => (string) $a->getStreetLine(1),
                'address2' => trim($a->getStreetLine(2) . ' ' . $a->getStreetLine(3)) ?: null,
                'city' => $a->getCity(),
                'province_code' => $a->getRegionCode() ?: null,
                'zip' => $a->getPostcode(),
                'country_code' => $a->getCountryId(),
                'phone' => $a->getTelephone() ?: null,
            ] : null,
            'customer' => [
                'name' => trim($o->getCustomerFirstname() . ' ' . $o->getCustomerLastname()) ?: null,
                'phone' => $a ? ($a->getTelephone() ?: null) : null,
                'email' => $o->getCustomerEmail() ?: null,
            ],
            'note' => $o->getCustomerNote() ? substr((string) $o->getCustomerNote(), 0, 2000) : null,
            'weight_kg' => $this->kg($o->getWeight()),
            'items' => $items,
            'shipping_line' => $method !== '' ? [
                'code' => $method,
                'title' => (string) $o->getShippingDescription(),
                'price' => (string) $o->getBaseShippingInclTax(),
                'rate_code' => !empty($meta['rate_code']) ? $meta['rate_code'] : Core::rateCodeOf($method),
            ] : null,
        ];
    }

    /**
     * New order: remember whether it is a test order and the rate the shopper chose.
     *
     * @param Order $o
     * @return void
     */
    public function recordOrder($o)
    {
        $db = $this->resource->getConnection();
        $db->insertIgnore($this->resource->getTableName('rentadriver_order'), [
            'order_id' => (int) $o->getId(),
            'rate_code' => Core::rateCodeOf((string) $o->getShippingMethod()),
            'is_test' => (int) (bool) $this->scope->getValue('carriers/rentadriver/test_mode'),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function addHistory($orderId, $comment, $notify, $status, array $tracking)
    {
        $o = $this->load($orderId);
        if (!$o) {
            throw new NoSuchEntityException(__('order not found'));
        }
        self::$writing = true;
        try {
            // "shipped": a shipment with our tracking number, so Magento's own shipment email and tracking popup work.
            if ($status === 'shipped' && $o->canShip()) {
                $track = $this->tracks->create();
                $track->setTrackNumber(isset($tracking['number']) ? (string) $tracking['number'] : '');
                $track->setTitle('RentADriver');
                $track->setCarrierCode('rentadriver');
                $shipmentComment = null;
                if ($comment !== '') {
                    $shipmentComment = $this->shipmentComments->create();
                    $shipmentComment->setComment($comment);
                    $shipmentComment->setIsVisibleOnFront(1);
                }
                $shipmentId = $this->ship->execute(
                    (int) $o->getId(),
                    [],
                    (bool) $notify,
                    $shipmentComment !== null,
                    $shipmentComment,
                    [$track]
                );
                return 'shipment-' . $shipmentId;
            }
            $h = $o->addCommentToStatusHistory($comment, false, (bool) $notify);
            $h->setIsCustomerNotified((bool) $notify);
            $this->orders->save($o);
            if ($notify) {
                $this->commentSender->send($o, true, $comment);
            }
            return (string) $h->getId();
        } finally {
            self::$writing = false;
        }
    }
}
