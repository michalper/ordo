<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\WhatsAppTemplate;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate\CollectionFactory as WhatsAppTemplateCollectionFactory;

/**
 * Grid mass-delete counterpart to the single-row delete already available from this grid's
 * actions column — closes the admin-platform ROADMAP.md gap where this grid's own
 * selectionsColumn rendered checkboxes with no massaction behind them. Same
 * Ui\Component\MassAction\Filter pattern as Controller\Adminhtml\Campaign\MassDelete.
 *
 * No MassEnable/MassDisable here, unlike the other four entities that got mass actions in the
 * same pass (AdAudience/ContentBlock/FreeGiftOffer/ScoreRule) - WhatsAppTemplate has no plain
 * enabled boolean, only a status lifecycle (draft/pending/approved/rejected/disabled) driven by
 * Meta's own template approval process (SubmitForReview/RefreshStatus), with no existing
 * single-row admin action to force it to STATUS_DISABLED. Adding one here would be new business
 * logic, not the same mechanical "wire up the already-existing single-row action" this pass is
 * scoped to.
 */
class MassDelete extends AbstractWhatsAppTemplateAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly WhatsAppTemplateCollectionFactory $whatsAppTemplateCollectionFactory,
        private readonly WhatsAppTemplateResource $whatsAppTemplateResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->whatsAppTemplateCollectionFactory->create());

        $count = 0;
        foreach ($collection as $template) {
            /** @var \Ordo\Automation\Model\WhatsAppTemplate $template */
            $this->whatsAppTemplateResource->delete($template);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 WhatsApp template(s) have been deleted.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
