<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\FunnelReport;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only purchase funnel / drop-off report (one row per day: distinct registered customers at
 * each stage, and the drop-off into the next one) - same shape as
 * Controller\Adminhtml\CohortRetention\Index. See docs/adr/0031 for the report's own scope.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::funnel_report';

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
        $resultPage->getConfig()->getTitle()->prepend(__('Purchase Funnel Report'));

        return $resultPage;
    }
}
