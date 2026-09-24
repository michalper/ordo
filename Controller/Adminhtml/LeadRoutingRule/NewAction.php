<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\LeadRoutingRule;

use Magento\Framework\App\Action\HttpGetActionInterface;

class NewAction extends AbstractLeadRoutingRuleAction implements HttpGetActionInterface
{
    public function execute()
    {
        return $this->_forward('edit');
    }
}
