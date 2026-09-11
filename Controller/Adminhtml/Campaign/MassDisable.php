<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Api\CampaignRepositoryInterface;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;

/**
 * See MassEnable's own docblock - same pattern, opposite direction.
 */
class MassDisable extends AbstractCampaignAction implements HttpPostActionInterface
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
            $campaign->setEnabled(false);
            $this->campaignRepository->save($campaign);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 campaign(s) have been disabled.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
