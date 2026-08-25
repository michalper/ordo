<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ReorderCycle;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only diagnostic view of what CalculateReorderCycle has computed — no edit/delete,
 * this is for verifying "does the detected cycle look right" while debugging, not a CRUD screen.
 */
class Index extends Action
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::config';

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
