<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\EmailTemplateDraft;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

class Index extends AbstractEmailTemplateDraftAction implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    protected function activeMenu(): string
    {
        return 'Ordo_Automation::email_template_drafts';
    }

    protected function pageTitle(): Phrase
    {
        return __('Email Template Drafts');
    }
}
