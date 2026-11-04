<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Campaign\Website;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\CampaignWebsite as CampaignWebsiteModel;
use Ordo\Automation\Model\ResourceModel\Campaign\Website as CampaignWebsiteResource;

/**
 * @method \ArrayIterator<int, CampaignWebsiteModel> getIterator()
 * @method CampaignWebsiteModel[] getItems()
 */
class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CampaignWebsiteModel::class, CampaignWebsiteResource::class);
    }

    public function addCampaignFilter(int $campaignId): self
    {
        $this->addFieldToFilter('campaign_id', $campaignId);
        return $this;
    }

    /**
     * Every website row for every given campaign in one query — CampaignDispatcher's own
     * website-scope filter uses this to check all of a trigger's candidate campaigns at once,
     * same "one query, not N" reasoning as Campaign\Action\Collection::addCampaignIdsFilter().
     *
     * @param int[] $campaignIds
     */
    public function addCampaignIdsFilter(array $campaignIds): self
    {
        $this->addFieldToFilter('campaign_id', ['in' => $campaignIds]);
        return $this;
    }
}
