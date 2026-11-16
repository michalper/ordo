<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\AdminActionLog;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * Read-only grid over ordo_admin_action_log - "who changed this campaign/segment last Tuesday"
 * is no longer unanswerable (the ROADMAP.md "no audit log of admin actions" gap, scoped to
 * Campaign/Segment saves first - see Model\AdminActionLog\Recorder).
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::admin_action_log';

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
        return __('Admin Action Log');
    }
}
