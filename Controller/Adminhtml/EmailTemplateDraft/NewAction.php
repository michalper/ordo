<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\EmailTemplateDraft;

use Magento\Backend\App\Action\Context;
use Magento\Email\Model\ResourceModel\Template as MagentoTemplateResource;
use Magento\Email\Model\TemplateFactory as MagentoTemplateFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ordo\Automation\Model\EmailTemplateDraft;
use Ordo\Automation\Model\EmailTemplateDraftFactory;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft as EmailTemplateDraftResource;

/**
 * Starts a new draft of a live Magento email template, copying its current subject/text/styles
 * so the draft begins as an exact match of what's live today. If a draft for this template_id
 * already exists (the table's own unique constraint on template_id is the real guarantee; this
 * check just avoids throwing on the common case), redirects straight to editing it instead of
 * creating a duplicate - this is where Block\Adminhtml\EmailTemplateDraft\Chooser's "Start Draft"
 * form submits. Looks up the existing draft via AbstractDb::load()'s own by-arbitrary-field
 * form (same mechanism Edit/Save already use by entity_id, just against template_id here) rather
 * than a Collection, since a Collection's getFirstItem() forces a real load()/DB round trip this
 * controller has no other reason to pay for.
 */
class NewAction extends AbstractEmailTemplateDraftAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly MagentoTemplateFactory $magentoTemplateFactory,
        private readonly MagentoTemplateResource $magentoTemplateResource,
        private readonly EmailTemplateDraftFactory $draftFactory,
        private readonly EmailTemplateDraftResource $draftResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $templateId = (int) $this->getRequest()->getParam('template_id');

        if (!$templateId) {
            $this->messageManager->addErrorMessage(__('A template to draft must be specified.'));
            return $resultRedirect->setPath('*/*/');
        }

        $liveTemplate = $this->magentoTemplateFactory->create();
        $this->magentoTemplateResource->load($liveTemplate, $templateId);
        if (!$liveTemplate->getId()) {
            $this->messageManager->addErrorMessage(__('That email template no longer exists.'));
            return $resultRedirect->setPath('*/*/');
        }

        /** @var EmailTemplateDraft $existingDraft */
        $existingDraft = $this->draftFactory->create();
        $this->draftResource->load($existingDraft, $templateId, EmailTemplateDraft::TEMPLATE_ID);
        if ($existingDraft->getEntityId()) {
            return $resultRedirect->setPath('*/*/edit', ['entity_id' => $existingDraft->getEntityId()]);
        }

        $draft = $this->draftFactory->create();
        $draft->setTemplateId($templateId);
        $draft->setTemplateCode((string) $liveTemplate->getData('template_code'));
        $draft->setTemplateSubject((string) $liveTemplate->getData('template_subject'));
        $draft->setTemplateText((string) $liveTemplate->getData('template_text'));
        $templateStyles = $liveTemplate->getData('template_styles');
        $draft->setTemplateStyles($templateStyles === null ? null : (string) $templateStyles);
        $this->draftResource->save($draft);

        return $resultRedirect->setPath('*/*/edit', ['entity_id' => $draft->getEntityId()]);
    }
}
