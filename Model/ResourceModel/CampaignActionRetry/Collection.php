<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\CampaignActionRetry;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\CampaignActionRetry as CampaignActionRetryModel;
use Ordo\Automation\Model\ResourceModel\CampaignActionRetry as CampaignActionRetryResource;
use Ordo\Automation\Model\ResourceModel\RetryDueFilterTrait;

class Collection extends AbstractCollection
{
    use RetryDueFilterTrait;

    protected function _construct(): void
    {
        $this->_init(CampaignActionRetryModel::class, CampaignActionRetryResource::class);
    }
}
