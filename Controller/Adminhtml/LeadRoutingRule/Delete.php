<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\LeadRoutingRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Ordo\Automation\Controller\Adminhtml\Shared\DeletesEntityTrait;
use Ordo\Automation\Model\LeadRoutingRuleFactory;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;

class Delete extends AbstractLeadRoutingRuleAction implements HttpPostActionInterface
{
    use DeletesEntityTrait;

    public function __construct(
        Context $context,
        private readonly LeadRoutingRuleFactory $leadRoutingRuleFactory,
        private readonly LeadRoutingRuleResource $leadRoutingRuleResource
    ) {
        parent::__construct($context);
    }

    protected function deleteEntity(int $entityId): void
    {
        $leadRoutingRule = $this->leadRoutingRuleFactory->create();
        $this->leadRoutingRuleResource->load($leadRoutingRule, $entityId);
        $this->leadRoutingRuleResource->delete($leadRoutingRule);
    }

    protected function getMissingIdMessage(): Phrase
    {
        return __('Missing lead routing rule id.');
    }

    protected function getDeletedMessage(): Phrase
    {
        return __('The lead routing rule has been deleted.');
    }

    protected function getDeleteErrorMessage(\Throwable $e): Phrase
    {
        return __('Could not delete the lead routing rule: %1', $e->getMessage());
    }
}
