<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\WhatsAppTemplate;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\WhatsAppTemplate;
use Ordo\Automation\Model\WhatsAppTemplateFactory;

class Save extends AbstractWhatsAppTemplateAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly WhatsAppTemplateFactory $whatsAppTemplateFactory,
        private readonly WhatsAppTemplateResource $whatsAppTemplateResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var array<string, mixed> $data */
        $data = $this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $entityId = (int) ($data['entity_id'] ?? 0);

        try {
            $template = $this->whatsAppTemplateFactory->create();
            if ($entityId) {
                $this->whatsAppTemplateResource->load($template, $entityId);
            }

            $bodyChanged = $template->getBodyText() !== (string) ($data['body_text'] ?? '');

            $template->setName((string) ($data['name'] ?? ''));
            $template->setMetaTemplateName((string) ($data['meta_template_name'] ?? ''));
            $template->setCategory((string) ($data['category'] ?? ''));
            $template->setLanguage((string) ($data['language'] ?? 'en_US'));
            $template->setBodyText((string) ($data['body_text'] ?? ''));
            $template->setPlaceholderLegend(
                trim((string) ($data['placeholder_legend'] ?? '')) !== ''
                    ? trim((string) $data['placeholder_legend'])
                    : null
            );

            // Editing the actual template text after it was already submitted/approved/rejected
            // invalidates whatever Meta has on file - back to draft, needs SubmitForReview again,
            // same reasoning Meta's own template editor UI applies.
            if (!$entityId || $bodyChanged) {
                $template->setStatus(WhatsAppTemplate::STATUS_DRAFT);
                $template->setMetaTemplateId(null);
                $template->setRejectionReason(null);
            }

            $this->whatsAppTemplateResource->save($template);

            $this->messageManager->addSuccessMessage(__('The WhatsApp template has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['entity_id' => $template->getEntityId()]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not save the WhatsApp template: %1', $e->getMessage()));
            return $resultRedirect->setPath('*/*/edit', ['entity_id' => $entityId]);
        }
    }
}
