<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\LeadRoutingRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersEditPageTrait;
use Ordo\Automation\Model\LeadRoutingRule;
use Ordo\Automation\Model\LeadRoutingRuleFactory;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;

class Edit extends AbstractLeadRoutingRuleAction implements HttpGetActionInterface
{
    use RendersEditPageTrait;

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly LeadRoutingRuleFactory $leadRoutingRuleFactory,
        private readonly LeadRoutingRuleResource $leadRoutingRuleResource
    ) {
        parent::__construct($context);
    }

    protected function newEntity(): AbstractModel
    {
        return $this->leadRoutingRuleFactory->create();
    }

    protected function loadEntityById(AbstractModel $entity, int $entityId): void
    {
        $this->leadRoutingRuleResource->load($entity, $entityId);
    }

    protected function registryKey(): string
    {
        return 'ordo_lead_routing_rule';
    }

    protected function editEntityLabel(): string
    {
        return 'Lead Routing Rule';
    }

    protected function missingEntityLabel(): string
    {
        return 'lead routing rule';
    }

    protected function entityDisplayName(AbstractModel $entity): string
    {
        /** @var LeadRoutingRule $entity */
        return (string) $entity->getName();
    }
}
