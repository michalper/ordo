<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\AdminActionLog;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only grid over ordo_admin_action_log - "who changed this campaign/segment last Tuesday"
 * is no longer unanswerable (the ROADMAP.md "no audit log of admin actions" gap, scoped to
 * Campaign/Segment saves first - see Model\AdminActionLog\Recorder).
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::admin_action_log';

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
        $resultPage->getConfig()->getTitle()->prepend(__('Admin Action Log'));

        return $resultPage;
    }
}
