<?php
/**
 * The connection row in Stores > Configuration > Sales > Delivery Methods > RentADriver: connect, open, disconnect.
 *
 * @license GPL-2.0-or-later
 */
namespace RentADriver\Delivery\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use RentADriver\Delivery\Model\Bridge;

class Connection extends Field
{
    /**
     * @var Bridge
     */
    private $bridge;

    /**
     * @param Context $context
     * @param Bridge $bridge
     * @param array $data
     */
    public function __construct(Context $context, Bridge $bridge, array $data = [])
    {
        $this->bridge = $bridge;
        parent::__construct($context, $data);
    }

    /**
     * Connect button, or the connected store with open, guided setup and disconnect.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $core = $this->bridge->core();
        $e = function ($s) {
            return $this->escapeHtml((string) $s);
        };
        $link = function ($url, $label, $class, $newTab) use ($e) {
            return '<a class="' . $class . '"' . ($newTab ? ' target="_blank" rel="noopener"' : '')
                . ' href="' . $this->escapeUrl($url) . '">' . $e($label) . '</a>';
        };
        if (!$core->connected()) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal, so the phrase collector finds it
            $intro = __('Offer same-day local delivery by vetted RentADriver drivers. Connect your store, then choose your prices, delivery area and automatic booking in RentADriver.');
            $start = $this->getUrl('rentadriver/connect/start');
            return '<p>' . $e($intro) . '</p>'
                . $link($start, __('Connect to RentADriver'), 'action-default primary', false);
        }
        $connected = __(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal, so the phrase collector finds it
            'Connected as %1. Prices, delivery area, automatic booking and your delivery account are managed in RentADriver.',
            $this->bridge->getConfig('store_key')
        );
        return '<p>' . $e($connected) . '</p>'
            . $link($core->handoffUrl(), __('Open RentADriver'), 'action-default primary', true) . ' '
            . $link($core->handoffUrl(true), __('Guided setup'), 'action-default', true) . ' '
            . '<form method="post" style="display:inline" action="'
            . $this->escapeUrl($this->getUrl('rentadriver/connect/disconnect')) . '">'
            . '<input type="hidden" name="form_key" value="' . $e($this->getFormKey()) . '"/>'
            . '<button type="submit" class="action-default">' . $e(__('Disconnect')) . '</button></form>';
    }
}
