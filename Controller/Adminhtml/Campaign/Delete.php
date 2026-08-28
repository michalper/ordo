<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CacheInterface;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;

/**
 * Invoked via a plain GET link (see Ui\Component\Listing\Column\CampaignActions), matching
 * the interface it's actually dispatched through.
 */
class Delete extends AbstractCampaignAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly CampaignFactory $campaignFactory,
        private readonly CampaignResource $campaignResource,
        private readonly CacheInterface $cache
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('entity_id');

        if (!$entityId) {
            $this->messageManager->addErrorMessage(__('Missing campaign id.'));
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $campaign = $this->campaignFactory->create();
            $this->campaignResource->load($campaign, $entityId);
            // Condition/action rows cascade-delete via the FK ON DELETE CASCADE in db_schema.xml.
            $this->campaignResource->delete($campaign);
            $this->cache->clean([CampaignDispatcher::CACHE_TAG]);

            $this->messageManager->addSuccessMessage(__('The campaign has been deleted.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not delete the campaign: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/');
    }
}
