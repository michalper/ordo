<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\CohortRetention;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only cohort retention report (one row per cohort-month / months-since-first-purchase
 * pair: how many of that cohort were still buying N months later) - same shape as
 * Controller\Adminhtml\RevenueAnalytics\Index.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::cohort_retention';

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
        $resultPage->getConfig()->getTitle()->prepend(__('Cohort Retention Report'));

        return $resultPage;
    }
}
