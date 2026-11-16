<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\EmailTemplateVersion;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * Read-only view of every ordo_email_template_version snapshot, across every Magento email
 * template - closes the "Email template versioning/drafts" ROADMAP.md candidate's version-history
 * half. Guarded by its own dedicated ACL resource, matching every other diagnostic grid in this
 * module.
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::email_template_versioning';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    protected function activeMenu(): string
    {
        return 'Ordo_Automation::email_template_versioning';
    }

    protected function pageTitle(): Phrase
    {
        return __('Email Template Versions');
    }
}
