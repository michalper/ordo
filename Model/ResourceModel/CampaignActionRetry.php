<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CampaignActionRetry extends AbstractDb implements RetryRowResourceInterface
{
    use ClaimsRetryRowTrait;

    protected function _construct(): void
    {
        $this->_init('ordo_campaign_action_retry', 'entity_id');
    }
}
