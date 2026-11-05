<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\EmailTemplateDraft;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory as MagentoTemplateCollectionFactory;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft\CollectionFactory as EmailTemplateDraftCollectionFactory;

/**
 * Feeds view/adminhtml/templates/emailtemplatedraft/chooser.phtml the list of live Magento email
 * templates that don't already have an open draft - picking one submits to NewAction, which
 * creates the draft (or, if one slipped in between page load and submit, redirects to it).
 */
class Chooser extends Template
{
    public function __construct(
        Context $context,
        private readonly MagentoTemplateCollectionFactory $magentoTemplateCollectionFactory,
        private readonly EmailTemplateDraftCollectionFactory $emailTemplateDraftCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array{id: int, code: string}>
     */
    public function getUndraftedTemplates(): array
    {
        $draftedTemplateIds = array_map(
            static fn ($templateId) => (int) $templateId,
            $this->emailTemplateDraftCollectionFactory->create()->getColumnValues('template_id')
        );

        $templates = [];
        foreach ($this->magentoTemplateCollectionFactory->create() as $template) {
            $id = (int) $template->getId();
            if (in_array($id, $draftedTemplateIds, true)) {
                continue;
            }
            $templates[] = ['id' => $id, 'code' => (string) $template->getData('template_code')];
        }

        return $templates;
    }

    public function getNewDraftUrl(): string
    {
        return $this->getUrl('*/*/new');
    }
}
