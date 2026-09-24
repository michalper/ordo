<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\LeadRoutingRule;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\LeadRoutingRule\MassDisable;
use Ordo\Automation\Model\LeadRoutingRule;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule\Collection as LeadRoutingRuleCollection;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule\CollectionFactory as LeadRoutingRuleCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDisableTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDisablesEverySelectedRule(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $rule = $this->createMock(LeadRoutingRule::class);
        $rule->expects(self::once())->method('setEnabled')->with(false)->willReturnSelf();

        $collection = $this->makeRealCollection(LeadRoutingRuleCollection::class, 'ordo_lead_routing_rule');
        $collection->addItem($rule);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $leadRoutingRuleCollectionFactory = $this->createStub(LeadRoutingRuleCollectionFactory::class);
        $leadRoutingRuleCollectionFactory->method('create')->willReturn($this->createStub(LeadRoutingRuleCollection::class));

        $leadRoutingRuleResource = $this->createMock(LeadRoutingRuleResource::class);
        $leadRoutingRuleResource->expects(self::once())->method('save')->with($rule);

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 lead routing rule(s) have been disabled.', 1));

        $controller = new MassDisable($context, $filter, $leadRoutingRuleCollectionFactory, $leadRoutingRuleResource);
        $controller->execute();
    }
}
