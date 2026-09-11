<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\OrderApproval;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * First in-backend way to browse/act on pending order approvals - previously only reachable via
 * the original decision email or the REST API, with no in-backend fallback if that email was
 * lost. See Ui\Component\Listing\Column\OrderApprovalActions for the actual approve/reject links.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::order_approval';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::campaigns');
        $resultPage->getConfig()->getTitle()->prepend(__('Order Approvals'));

        return $resultPage;
    }
}
