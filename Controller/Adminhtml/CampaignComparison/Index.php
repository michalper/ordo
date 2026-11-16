<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\CampaignComparison;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * Read-only cross-campaign comparison report (one row per campaign: sent, converted,
 * conversion rate, attributed revenue, average CLV of converted customers) - same shape as
 * Controller\Adminhtml\RevenueAnalytics\Index.
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::campaign_comparison';

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
        return __('Campaign Comparison Report');
    }
}
