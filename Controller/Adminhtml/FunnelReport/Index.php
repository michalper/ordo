<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\FunnelReport;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * Read-only purchase funnel / drop-off report (one row per day: distinct registered customers at
 * each stage, and the drop-off into the next one) - same shape as
 * Controller\Adminhtml\CohortRetention\Index. See docs/adr/0031 for the report's own scope.
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::funnel_report';

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
        return __('Purchase Funnel Report');
    }
}
