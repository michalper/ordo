<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\Campaign\CampaignImporter;
use Ordo\Automation\Model\Import\UploadedJsonFileReader;

/**
 * Handles ImportForm.php's file upload - decodes the posted JSON and hands it to
 * Model\Campaign\CampaignImporter, which does the actual validation/persistence. Always creates
 * a brand-new campaign (see CampaignImporter's own docblock); never overwrites an existing one,
 * matching Export.php's own "no entity ids in the payload" promise.
 */
class Import extends AbstractCampaignAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly CampaignImporter $campaignImporter,
        private readonly UploadedJsonFileReader $uploadedJsonFileReader
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            $decoded = $this->uploadedJsonFileReader->read($this->getRequest(), 'import_file');
        } catch (\InvalidArgumentException $e) {
            $this->messageManager->addErrorMessage(__($e->getMessage()));
            return $resultRedirect->setPath('*/*/importform');
        }

        try {
            $campaign = $this->campaignImporter->import($decoded);
        } catch (\InvalidArgumentException $e) {
            $this->messageManager->addErrorMessage(__($e->getMessage()));
            return $resultRedirect->setPath('*/*/importform');
        }

        $this->messageManager->addSuccessMessage(__('Campaign "%1" was imported.', $campaign->getName()));
        return $resultRedirect->setPath('*/*/edit', ['entity_id' => $campaign->getEntityId()]);
    }
}
