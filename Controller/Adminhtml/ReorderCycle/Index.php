<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ReorderCycle;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * Read-only diagnostic view of what CalculateReorderCycle has computed — no edit/delete,
 * this is for verifying "does the detected cycle look right" while debugging, not a CRUD screen.
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    // Gates on its own dedicated acl.xml resource (reorder_cycle) rather than the broader
    // campaigns resource, so access to this diagnostic view can be granted independently of
    // full campaign management permissions.
    public const ADMIN_RESOURCE = 'Ordo_Automation::reorder_cycle';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    protected function activeMenu(): string
    {
        return 'Ordo_Automation::reorder_cycles';
    }

    protected function pageTitle(): Phrase
    {
        return __('Reorder Cycles');
    }
}
