<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\CampaignComparison;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only cross-campaign comparison report (one row per campaign: sent, converted,
 * conversion rate, attributed revenue, average CLV of converted customers) - same shape as
 * Controller\Adminhtml\RevenueAnalytics\Index.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::campaign_comparison';

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
        $resultPage->getConfig()->getTitle()->prepend(__('Campaign Comparison Report'));

        return $resultPage;
    }
}
