<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\CohortRetention;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * Read-only cohort retention report (one row per cohort-month / months-since-first-purchase
 * pair: how many of that cohort were still buying N months later) - same shape as
 * Controller\Adminhtml\RevenueAnalytics\Index.
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::cohort_retention';

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
        return __('Cohort Retention Report');
    }
}
