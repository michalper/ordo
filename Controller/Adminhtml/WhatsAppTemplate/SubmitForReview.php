<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\WhatsAppTemplate;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\WhatsApp\WhatsAppTemplateClient;
use Ordo\Automation\Model\WhatsAppTemplate;
use Ordo\Automation\Model\WhatsAppTemplateFactory;

/**
 * Submits a draft (or previously rejected) template to Meta for approval - the real, external
 * step Model\WhatsAppTemplate::STATUS_DRAFT/STATUS_REJECTED can never leave on their own.
 * Approval itself happens asynchronously on Meta's side (minutes to a day or more per their own
 * docs) - Controller\Adminhtml\WhatsAppTemplate\RefreshStatus polls the result, this action only
 * ever moves a template into STATUS_PENDING.
 *
 * POST, not GET - this has a real external side effect (registers content with Meta), unlike a
 * plain "refresh this grid" navigation. Reported directly: this was a GET action reachable via a
 * bare link/image tag with no form-key check when admin/security/use_form_key is off, the one
 * outlier against every other mutating action in this module.
 */
class SubmitForReview extends AbstractWhatsAppTemplateAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly WhatsAppTemplateFactory $whatsAppTemplateFactory,
        private readonly WhatsAppTemplateResource $whatsAppTemplateResource,
        private readonly WhatsAppTemplateClient $whatsAppTemplateClient
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('entity_id');

        $template = $this->whatsAppTemplateFactory->create();
        $this->whatsAppTemplateResource->load($template, $entityId);

        if (!$template->getEntityId()) {
            $this->messageManager->addErrorMessage(__('This WhatsApp template no longer exists.'));
            return $resultRedirect->setPath('*/*/');
        }

        // Re-submitting an already-pending/approved template isn't harmless: it re-registers the
        // same content with Meta a second time, which their API may treat as a duplicate/reject -
        // only a draft or a rejected template has anything to gain from submitting again.
        if (in_array($template->getStatus(), [WhatsAppTemplate::STATUS_PENDING, WhatsAppTemplate::STATUS_APPROVED], true)) {
            $this->messageManager->addErrorMessage(
                __('This template has already been submitted to Meta - nothing to do.')
            );
            return $resultRedirect->setPath('*/*/edit', ['entity_id' => $entityId]);
        }

        try {
            $metaTemplateId = $this->whatsAppTemplateClient->submitTemplate(
                $template->getMetaTemplateName(),
                $template->getCategory(),
                $template->getLanguage(),
                $template->getBodyText()
            );

            $template->setMetaTemplateId($metaTemplateId);
            $template->setStatus(WhatsAppTemplate::STATUS_PENDING);
            $template->setRejectionReason(null);
            $template->setSubmittedAt(date('Y-m-d H:i:s'));
            $this->whatsAppTemplateResource->save($template);

            $this->messageManager->addSuccessMessage(__('The template has been submitted to Meta for approval.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not submit the template to Meta: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/edit', ['entity_id' => $entityId]);
    }
}
