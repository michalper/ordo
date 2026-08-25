<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Campaign\Condition;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\CampaignCondition as CampaignConditionModel;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CampaignConditionModel::class, CampaignConditionResource::class);
    }

    public function addCampaignFilter(int $campaignId): self
    {
        $this->addFieldToFilter('campaign_id', $campaignId);
        $this->setOrder('sort_order', self::SORT_ORDER_ASC);
        return $this;
    }
}
