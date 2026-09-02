<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ReorderCycle;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only diagnostic view of what CalculateReorderCycle has computed — no edit/delete,
 * this is for verifying "does the detected cycle look right" while debugging, not a CRUD screen.
 */
class Index extends Action implements HttpGetActionInterface
{
    // Every other admin controller in this module gates on one of its own top-level acl.xml
    // resources (campaigns/free_gifts/segments) — this one wrongly used
    // Ordo_Automation::config, which is nested under Magento_Config::config specifically for
    // the System Configuration section, not a general "can use this module" resource. Found via
    // a real CI run: a full-access admin got redirected to the dashboard (Magento's standard
    // ACL-denied behavior) hitting this controller. Dashboard::ADMIN_RESOURCE already uses
    // campaigns for the same reason (reorder cycles are part of the same B2C lifecycle area).
    public const ADMIN_RESOURCE = 'Ordo_Automation::campaigns';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::reorder_cycles');
        $resultPage->getConfig()->getTitle()->prepend(__('Reorder Cycles'));

        return $resultPage;
    }
}
