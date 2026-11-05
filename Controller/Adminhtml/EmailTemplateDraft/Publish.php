<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\EmailTemplateDraft;

use Magento\Backend\App\Action\Context;
use Magento\Email\Model\ResourceModel\Template as MagentoTemplateResource;
use Magento\Email\Model\TemplateFactory as MagentoTemplateFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\EmailTemplateDraftFactory;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft as EmailTemplateDraftResource;

/**
 * Copies a draft's subject/text/styles onto the live Magento email_template row the same way
 * Controller\Adminhtml\EmailTemplateVersion\MassRestore does for a version snapshot - saving
 * through the real core resource model so this re-triggers Plugin\Email\SnapshotEmailTemplateVersion
 * for free (if versioning is enabled), giving an automatic "state before this publish" history
 * point. The draft row is then deleted: there is no "published" status to track, since editing
 * again after publish just starts a fresh draft from the now-current live content via
 * NewAction's own copy-on-create logic. A POST mutation with a confirm dialog
 * (Block\Adminhtml\EmailTemplateDraft\Edit\PublishButton), not a GET link - this has a real,
 * immediate, irreversible-without-another-publish effect on what send_email sends.
 */
class Publish extends AbstractEmailTemplateDraftAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly EmailTemplateDraftFactory $draftFactory,
        private readonly EmailTemplateDraftResource $draftResource,
        private readonly MagentoTemplateFactory $magentoTemplateFactory,
        private readonly MagentoTemplateResource $magentoTemplateResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('entity_id');

        $draft = $this->draftFactory->create();
        $this->draftResource->load($draft, $entityId);
        if (!$draft->getEntityId()) {
            $this->messageManager->addErrorMessage(__('This draft no longer exists.'));
            return $resultRedirect->setPath('*/*/');
        }

        $liveTemplate = $this->magentoTemplateFactory->create();
        $this->magentoTemplateResource->load($liveTemplate, $draft->getTemplateId());
        if (!$liveTemplate->getId()) {
            $this->messageManager->addErrorMessage(
                __('The live template this draft belongs to no longer exists.')
            );
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $liveTemplate->setData('template_subject', $draft->getTemplateSubject());
            $liveTemplate->setData('template_text', $draft->getTemplateText());
            $liveTemplate->setData('template_styles', $draft->getTemplateStyles());
            $this->magentoTemplateResource->save($liveTemplate);

            $this->draftResource->delete($draft);

            $this->messageManager->addSuccessMessage(
                __('Published the draft to template "%1".', $draft->getTemplateCode())
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not publish this draft: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/');
    }
}
