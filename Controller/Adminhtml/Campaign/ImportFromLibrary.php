<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\Campaign\CampaignImporter;
use Ordo\Automation\Model\Campaign\TemplateLibrary;

/**
 * Handles Library.php's "Use this Template" button - looks up the posted template_id in
 * Model\Campaign\TemplateLibrary and hands its payload straight to Model\Campaign\CampaignImporter,
 * same shape Import.php already hands it an uploaded file's decoded content. Always creates a
 * brand-new campaign, same as Import.php's own promise - picking a template twice creates two
 * separate campaigns, not an error.
 */
class ImportFromLibrary extends AbstractCampaignAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly TemplateLibrary $templateLibrary,
        private readonly CampaignImporter $campaignImporter
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $templateId = (string) $this->getRequest()->getParam('template_id');

        $template = $this->templateLibrary->getTemplate($templateId);
        if ($template === null) {
            $this->messageManager->addErrorMessage(__('Unknown template.'));
            return $resultRedirect->setPath('*/*/library');
        }

        try {
            $campaign = $this->campaignImporter->import($template['payload']);
        } catch (\InvalidArgumentException $e) {
            $this->messageManager->addErrorMessage(__($e->getMessage()));
            return $resultRedirect->setPath('*/*/library');
        }

        $this->messageManager->addSuccessMessage(__('Campaign "%1" was created from the template.', $campaign->getName()));
        return $resultRedirect->setPath('*/*/edit', ['entity_id' => $campaign->getEntityId()]);
    }
}
