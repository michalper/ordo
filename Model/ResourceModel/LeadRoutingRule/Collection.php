<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\LeadRoutingRule;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\LeadRoutingRule as LeadRoutingRuleModel;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(LeadRoutingRuleModel::class, LeadRoutingRuleResource::class);
    }
}
