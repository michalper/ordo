<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\LeadRoutingRule;

use Magento\Backend\App\Action;

abstract class AbstractLeadRoutingRuleAction extends Action
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::lead_routing_rules';
}
