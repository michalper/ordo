<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Setup;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * A guided first-run checklist (configure a channel -> build a segment -> build a campaign),
 * closing the "no setup wizard" ROADMAP.md gap - see Block\Adminhtml\Setup\SetupWizardViewModel
 * for the actual step/done-state logic.
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::campaigns';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    protected function activeMenu(): string
    {
        return 'Ordo_Automation::top_level';
    }

    protected function pageTitle(): Phrase
    {
        return __('Setup Guide');
    }
}
