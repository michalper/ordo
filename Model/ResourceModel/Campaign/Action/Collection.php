<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Campaign\Action;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\CampaignAction as CampaignActionModel;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CampaignActionModel::class, CampaignActionResource::class);
    }

    public function addCampaignFilter(int $campaignId): self
    {
        $this->addFieldToFilter('campaign_id', $campaignId);
        $this->setOrder('sort_order', self::SORT_ORDER_ASC);
        return $this;
    }

    /**
     * Loads actions for every given campaign in a single query — used by CampaignDispatcher
     * to avoid one query per matched campaign when dispatching a trigger event.
     *
     * @param int[] $campaignIds
     */
    public function addCampaignIdsFilter(array $campaignIds): self
    {
        $this->addFieldToFilter('campaign_id', ['in' => $campaignIds]);
        $this->setOrder('sort_order', self::SORT_ORDER_ASC);
        return $this;
    }
}
