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
 * Polls Meta for a pending template's current approval status - a manual "Refresh Now" pull,
 * same pattern this module already uses for the standalone shopping feed
 * (Controller\Adminhtml\ProductFeed\RefreshNow, which stays GET - a read-only local recompute).
 * This one is POST instead: it makes a real outbound Graph API call and writes the result,
 * unlike that local, idempotent recompute. Controller\WhatsApp\Webhook also updates this same
 * field automatically when Meta's own message_template_status_update webhook event arrives -
 * this action exists for whenever an admin doesn't want to wait for that.
 */
class RefreshStatus extends AbstractWhatsAppTemplateAction implements HttpPostActionInterface
{
    /**
     * @var array<string, string> Meta's own uppercase status value => WhatsAppTemplate::STATUS_*
     */
    private const array META_STATUS_MAP = [
        'PENDING' => WhatsAppTemplate::STATUS_PENDING,
        'APPROVED' => WhatsAppTemplate::STATUS_APPROVED,
        'REJECTED' => WhatsAppTemplate::STATUS_REJECTED,
        'DISABLED' => WhatsAppTemplate::STATUS_DISABLED,
    ];

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

        if (!$template->getEntityId() || !$template->getMetaTemplateId()) {
            $this->messageManager->addErrorMessage(
                __('This template has not been submitted to Meta yet - nothing to refresh.')
            );
            return $resultRedirect->setPath('*/*/edit', ['entity_id' => $entityId]);
        }

        try {
            $result = $this->whatsAppTemplateClient->getTemplateStatus((string) $template->getMetaTemplateId());

            $template->setStatus(self::META_STATUS_MAP[$result['status']] ?? $template->getStatus());
            $template->setRejectionReason($result['rejectionReason']);
            $this->whatsAppTemplateResource->save($template);

            $this->messageManager->addSuccessMessage(__('Status refreshed from Meta: %1', $template->getStatus()));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not refresh the template status: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/edit', ['entity_id' => $entityId]);
    }
}
