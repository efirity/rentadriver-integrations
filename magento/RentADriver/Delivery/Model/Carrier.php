<?php
/**
 * RentADriver carrier: asks RentADriver for a same-day price for the quote's address. No price (outside the delivery
 * area, after cut-off, API slow or down) = the method is not shown.
 *
 * @license GPL-2.0-or-later
 */
namespace RentADriver\Delivery\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Shipping\Model\Tracking\Result\StatusFactory;
use Psr\Log\LoggerInterface;

class Carrier extends AbstractCarrier implements CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = 'rentadriver';

    /**
     * @var bool
     */
    protected $_isFixed = true;

    /**
     * @var ResultFactory
     */
    private $results;

    /**
     * @var MethodFactory
     */
    private $methods;

    /**
     * @var Bridge
     */
    private $bridge;

    /**
     * @var StatusFactory
     */
    private $trackStatus;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param ResultFactory $results
     * @param MethodFactory $methods
     * @param Bridge $bridge
     * @param StatusFactory $trackStatus
     * @param array $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $results,
        MethodFactory $methods,
        Bridge $bridge,
        StatusFactory $trackStatus,
        array $data = []
    ) {
        $this->results = $results;
        $this->methods = $methods;
        $this->bridge = $bridge;
        $this->trackStatus = $trackStatus;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * Ask RentADriver for prices; no price means the method is not shown.
     *
     * @param RateRequest $request
     * @return \Magento\Shipping\Model\Rate\Result|bool
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }
        $core = $this->bridge->core();
        if (!$core->connected()) {
            return false;
        }
        $lbs = $this->_scopeConfig->getValue('general/locale/weight_unit') === 'lbs';
        $items = [];
        foreach ($request->getAllItems() ?: [] as $item) {
            if ($item->getParentItem()) {
                continue;
            }
            $w = (float) $item->getWeight();
            $items[] = [
                'quantity' => (int) $item->getQty(),
                'weight_kg' => $w > 0 ? round($lbs ? $w * 0.45359237 : $w, 3) : null,
                'requires_shipping' => !$item->getProduct() || !$item->getProduct()->getIsVirtual(),
            ];
        }
        $street = preg_split('/\r?\n/', (string) $request->getDestStreet());
        $rates = $core->rates([
            'destination' => [
                'address1' => isset($street[0]) ? $street[0] : null,
                'address2' => isset($street[1]) ? $street[1] : null,
                'city' => $request->getDestCity(),
                'province_code' => $request->getDestRegionCode(),
                'zip' => $request->getDestPostcode(),
                'country_code' => $request->getDestCountryId(),
            ],
            'items' => $items,
            'subtotal' => (string) $request->getBaseSubtotalInclTax()
                ?: (string) $request->getPackageValueWithDiscount(),
            'currency' => (string) $this->_scopeConfig->getValue('currency/options/base'),
        ]);
        if (!$rates) {
            return false;
        }
        $result = $this->results->create();
        foreach ($rates as $r) {
            $m = $this->methods->create();
            $m->setCarrier($this->_code);
            $m->setCarrierTitle($this->getConfigData('title'));
            $m->setMethod(strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) $r['code'])));
            $m->setMethodTitle((string) $r['title']);
            $m->setPrice((float) $r['cost']);
            $m->setCost((float) $r['cost']);
            $result->append($m);
        }
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function getAllowedMethods()
    {
        return ['rd_sameday' => __('Same-day'), 'rd_nextday' => __('Next morning')];
    }

    /**
     * @inheritdoc
     */
    public function isTrackingAvailable()
    {
        return true;
    }

    /**
     * The RentADriver tracking page for a tracking number.
     *
     * @param string $number
     * @return \Magento\Shipping\Model\Tracking\Result\Status
     */
    public function getTrackingInfo($number)
    {
        $s = $this->trackStatus->create();
        $s->setCarrier($this->_code);
        $s->setCarrierTitle($this->getConfigData('title'));
        $s->setTracking($number);
        $s->setUrl('https://rentadriver.ai/track/' . rawurlencode((string) $number));
        return $s;
    }
}
