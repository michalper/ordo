<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\EmailTemplateVersion;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only view of every ordo_email_template_version snapshot, across every Magento email
 * template - closes the "Email template versioning/drafts" ROADMAP.md candidate's version-history
 * half. Guarded by its own dedicated ACL resource, matching every other diagnostic grid in this
 * module.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::email_template_versioning';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::email_template_versioning');
        $resultPage->getConfig()->getTitle()->prepend(__('Email Template Versions'));

        return $resultPage;
    }
}
