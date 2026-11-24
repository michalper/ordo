<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\EmailTemplateVersion;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Email\Model\ResourceModel\Template as MagentoTemplateResource;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory as MagentoTemplateCollectionFactory;
use Magento\Email\Model\Template as MagentoTemplate;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\EmailTemplateVersion;
use Ordo\Automation\Model\ResourceModel\EmailTemplateVersion\CollectionFactory as EmailTemplateVersionCollectionFactory;

/**
 * Copies each selected snapshot's subject/text/styles back onto its own live Magento
 * email_template row - the "restore" half of the "Email template versioning/drafts" ROADMAP.md
 * candidate. A grid mass action (POST, form-key protected) rather than a per-row GET link, same
 * reasoning the WhatsAppTemplate SubmitForReview/RefreshStatus GET-mutation fix already
 * established for this module: an admin action with a real side effect never sits behind a plain
 * link. Saving the live template through its own resource model here (not a raw UPDATE)
 * deliberately goes back through Plugin\Email\SnapshotEmailTemplateVersion, so restoring itself
 * creates a fresh "before you restored" snapshot - a restore is never a dead end, it's just
 * another save. Selecting more than one row restores each independently (they're typically
 * different templates); restoring the same template's version twice in one batch is harmless,
 * just redundant.
 */
class MassRestore extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::email_template_versioning';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly EmailTemplateVersionCollectionFactory $emailTemplateVersionCollectionFactory,
        private readonly MagentoTemplateCollectionFactory $magentoTemplateCollectionFactory,
        private readonly MagentoTemplateResource $magentoTemplateResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->emailTemplateVersionCollectionFactory->create());
        $templatesById = $this->loadTemplatesById($collection);

        $restored = 0;
        foreach ($collection as $version) {
            /** @var EmailTemplateVersion $version */
            $template = $templatesById[(int) $version->getTemplateId()] ?? null;

            if ($template === null) {
                continue;
            }

            try {
                $template->setData('template_subject', $version->getTemplateSubject());
                $template->setData('template_text', $version->getTemplateText());
                $template->setData('template_styles', $version->getTemplateStyles());
                $this->magentoTemplateResource->save($template);
                $restored++;
            } catch (\Throwable $e) {
                $this->messageManager->addErrorMessage(
                    __('Could not restore template "%1": %2', $version->getTemplateCode(), $e->getMessage())
                );
            }
        }

        if ($restored > 0) {
            $this->messageManager->addSuccessMessage(__('Restored %1 template version(s).', $restored));
        }

        return $resultRedirect->setPath('*/*/');
    }

    /**
     * One batched load for every distinct template_id selected, instead of one
     * MagentoTemplateResource::load() call per selected version row - found during a full-repo
     * /simplify sweep. Selecting several versions of the same template is still harmless (see
     * this class's own docblock) - they now simply share the one already-loaded model instance.
     *
     * @param iterable<EmailTemplateVersion> $versions
     * @return array<int, MagentoTemplate>
     */
    private function loadTemplatesById(iterable $versions): array
    {
        $templateIds = [];
        foreach ($versions as $version) {
            /** @var EmailTemplateVersion $version */
            $templateIds[(int) $version->getTemplateId()] = true;
        }

        if ($templateIds === []) {
            return [];
        }

        $collection = $this->magentoTemplateCollectionFactory->create();
        $collection->addFieldToFilter('template_id', ['in' => array_keys($templateIds)]);

        $templatesById = [];
        foreach ($collection as $template) {
            /** @var MagentoTemplate $template */
            $templatesById[(int) $template->getId()] = $template;
        }

        return $templatesById;
    }

    /**
     * This writes to the live core email_template row (via $magentoTemplateResource->save()
     * above), the exact write Magento_Email::template itself exists to gate - found during a
     * full-repo security review: Ordo_Automation::email_template_versioning is a sibling ACL
     * node, not a child of Magento_Email::template, so a role granted the former without the
     * latter could otherwise overwrite any live template despite being explicitly denied core's
     * own template-editing permission.
     */
    protected function _isAllowed()
    {
        return parent::_isAllowed() && $this->_authorization->isAllowed('Magento_Email::template');
    }
}
