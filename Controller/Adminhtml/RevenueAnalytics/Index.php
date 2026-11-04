<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\RevenueAnalytics;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only revenue/channel analytics report (one row per order: revenue, coupon, campaign,
 * billing country/city, new-vs-returning customer) - same shape as Controller\Adminhtml\
 * ProductPerformance\Index, including its own dedicated ACL resource.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::revenue_analytics';

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
        $resultPage->getConfig()->getTitle()->prepend(__('Revenue Analytics Report'));

        return $resultPage;
    }
}
