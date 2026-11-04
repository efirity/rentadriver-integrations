<?php
/**
 * Connect to RentADriver: remember a one-time install token and send the admin to RentADriver to sign in and set up.
 *
 * @license GPL-2.0-or-later
 */
namespace RentADriver\Delivery\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use RentADriver\Delivery\Model\Bridge;

class Start extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'RentADriver_Delivery::config';

    /**
     * @var Bridge
     */
    private $bridge;

    /**
     * @param Context $context
     * @param Bridge $bridge
     */
    public function __construct(Context $context, Bridge $bridge)
    {
        parent::__construct($context);
        $this->bridge = $bridge;
    }

    /**
     * Redirect to RentADriver's connect page, or back to settings when the store has no HTTPS URL.
     *
     * @return Redirect
     */
    public function execute()
    {
        $store = $this->bridge->storeUrl();
        if ($store === null) {
            $this->messageManager->addErrorMessage(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal, so the phrase collector finds it
                __('Your store must use HTTPS (Stores > Configuration > General > Web > Base URLs (Secure)) before it can connect to RentADriver.')
            );
            return $this->resultRedirectFactory->create()
                ->setPath('adminhtml/system_config/edit', ['section' => 'carriers']);
        }
        // The order page URL without a secret key, so RentADriver can link each order back to its admin page.
        $this->_backendUrl->turnOffSecretKey();
        $orders = rtrim($this->_backendUrl->getUrl('sales/order/view'), '/') . '/order_id/';
        $this->_backendUrl->turnOnSecretKey();
        $url = $this->bridge->core()->connectUrl($store, $store . '/rentadriver/api/index', null, $orders);
        return $this->resultRedirectFactory->create()->setUrl($url);
    }
}
