<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\EmailTemplateDraft;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\EmailTemplateDraftFactory;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft as EmailTemplateDraftResource;

/**
 * Only ever touches the draft resource - never Magento\Email\Model\ResourceModel\Template, which
 * send_email ultimately reads from. See Publish for the only controller that writes to the live
 * template row.
 */
class Save extends AbstractEmailTemplateDraftAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly EmailTemplateDraftFactory $draftFactory,
        private readonly EmailTemplateDraftResource $draftResource
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
            $draft = $this->draftFactory->create();
            if ($entityId) {
                $this->draftResource->load($draft, $entityId);
            }
            if (!$draft->getEntityId()) {
                $this->messageManager->addErrorMessage(__('This draft no longer exists.'));
                return $resultRedirect->setPath('*/*/');
            }

            $draft->setTemplateSubject((string) ($data['template_subject'] ?? ''));
            $draft->setTemplateText((string) ($data['template_text'] ?? ''));
            $templateStyles = $data['template_styles'] ?? null;
            $draft->setTemplateStyles($templateStyles === null || $templateStyles === '' ? null : (string) $templateStyles);

            $this->draftResource->save($draft);

            $this->messageManager->addSuccessMessage(__('The email template draft has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['entity_id' => $draft->getEntityId()]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not save the email template draft: %1', $e->getMessage()));
            return $resultRedirect->setPath('*/*/edit', ['entity_id' => $entityId]);
        }
    }
}
