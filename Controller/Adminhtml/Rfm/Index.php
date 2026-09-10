<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Rfm;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only RFM report across the whole customer base. Guarded by its own dedicated ACL
 * resource (rfm) rather than the broader segments resource, so access can be granted
 * independently of full segment management permissions.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::rfm';

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
        $resultPage->getConfig()->getTitle()->prepend(__('RFM Report'));

        return $resultPage;
    }
}
