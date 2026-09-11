<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Api\CampaignRepositoryInterface;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;

/**
 * Grid mass-action counterpart to the single-campaign enable/disable toggle already available
 * from Campaign Edit — closes the admin-platform ROADMAP.md gap where the grid's own
 * selectionsColumn rendered checkboxes that did nothing. Standard Magento
 * Ui\Component\MassAction\Filter pattern (same shape as core's own Cms\Block\MassDelete):
 * resolves the grid's "selected"/"excluded"+"select all" choice into a real collection, then
 * saves each row through CampaignRepositoryInterface (a service contract) rather than calling
 * Campaign::save() directly.
 */
class MassEnable extends AbstractCampaignAction implements HttpPostActionInterface
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
            $campaign->setEnabled(true);
            $this->campaignRepository->save($campaign);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 campaign(s) have been enabled.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
