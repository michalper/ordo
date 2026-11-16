<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\RevenueAnalytics;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * Read-only revenue/channel analytics report (one row per order: revenue, coupon, campaign,
 * billing country/city, new-vs-returning customer) - same shape as Controller\Adminhtml\
 * ProductPerformance\Index, including its own dedicated ACL resource.
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::revenue_analytics';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    protected function activeMenu(): string
    {
        return 'Ordo_Automation::campaigns';
    }

    protected function pageTitle(): Phrase
    {
        return __('Revenue Analytics Report');
    }
}
