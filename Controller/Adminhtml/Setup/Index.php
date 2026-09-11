<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Setup;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * A guided first-run checklist (configure a channel -> build a segment -> build a campaign),
 * closing the "no setup wizard" ROADMAP.md gap - see Block\Adminhtml\Setup\SetupWizardViewModel
 * for the actual step/done-state logic.
 */
class Index extends Action implements HttpGetActionInterface
{
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
        $resultPage->setActiveMenu('Ordo_Automation::top_level');
        $resultPage->getConfig()->getTitle()->prepend(__('Setup Guide'));

        return $resultPage;
    }
}
