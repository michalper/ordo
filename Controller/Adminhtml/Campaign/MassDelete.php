<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Api\CampaignRepositoryInterface;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;

/**
 * See MassEnable's own docblock for the shared Filter/collection pattern. Deletes through
 * CampaignRepositoryInterface (a service contract) rather than calling Campaign::delete()
 * directly — its own delete() already flushes exactly the per-trigger-event cache tags this
 * campaign's trigger rows were tied to (see CampaignRepository::delete()'s own comments), so
 * there's nothing extra to clean up here.
 */
class MassDelete extends AbstractCampaignAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CampaignCollectionFactory $campaignCollectionFactory,
        private readonly CampaignRepositoryInterface $campaignRepository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->campaignCollectionFactory->create());

        $count = 0;
        foreach ($collection as $campaign) {
            /** @var \Ordo\Automation\Model\Campaign $campaign */
            // Condition/action rows cascade-delete via the FK ON DELETE CASCADE in db_schema.xml.
            $this->campaignRepository->delete($campaign);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 campaign(s) have been deleted.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
