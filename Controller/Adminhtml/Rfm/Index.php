<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Rfm;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only RFM report across the whole customer base. Guarded by the segments ACL resource
 * rather than a new one of its own: this is the analytical view behind segment building, and
 * anyone allowed to define RFM segments is already allowed to see the RFM numbers those segments
 * are built from.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::segments';

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
