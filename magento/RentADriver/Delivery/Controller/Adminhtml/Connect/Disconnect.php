<?php
/**
 * Disconnect this store from RentADriver.
 *
 * @license GPL-2.0-or-later
 */
namespace RentADriver\Delivery\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use RentADriver\Delivery\Model\Bridge;

class Disconnect extends Action implements HttpPostActionInterface
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
     * Forget the connection and return to the delivery methods settings.
     *
     * @return Redirect
     */
    public function execute()
    {
        $this->bridge->core()->disconnect();
        $this->messageManager->addSuccessMessage(__('This store is disconnected from RentADriver.'));
        return $this->resultRedirectFactory->create()
            ->setPath('adminhtml/system_config/edit', ['section' => 'carriers']);
    }
}
