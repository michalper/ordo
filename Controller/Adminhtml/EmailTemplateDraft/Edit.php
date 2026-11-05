<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\EmailTemplateDraft;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Model\EmailTemplateDraftFactory;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft as EmailTemplateDraftResource;

class Edit extends AbstractEmailTemplateDraftAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly EmailTemplateDraftFactory $draftFactory,
        private readonly EmailTemplateDraftResource $draftResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$entityId) {
            $this->messageManager->addErrorMessage(__('A draft can only be started from the chooser.'));
            return $resultRedirect->setPath('*/*/');
        }

        $draft = $this->draftFactory->create();
        $this->draftResource->load($draft, $entityId);
        if (!$draft->getEntityId()) {
            $this->messageManager->addErrorMessage(__('This draft no longer exists.'));
            return $resultRedirect->setPath('*/*/');
        }

        $this->registry->register('ordo_email_template_draft', $draft);

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::email_template_drafts');
        $resultPage->getConfig()->getTitle()->prepend(
            __('Edit Email Template Draft "%1"', $draft->getTemplateCode())
        );

        return $resultPage;
    }
}
