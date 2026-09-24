<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\LeadRoutingRule;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\LeadRoutingRule\MassEnable;
use Ordo\Automation\Model\LeadRoutingRule;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule\Collection as LeadRoutingRuleCollection;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule\CollectionFactory as LeadRoutingRuleCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassEnableTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteEnablesEverySelectedRule(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $ruleA = $this->createMock(LeadRoutingRule::class);
        $ruleA->expects(self::once())->method('setEnabled')->with(true)->willReturnSelf();

        $ruleB = $this->createMock(LeadRoutingRule::class);
        $ruleB->expects(self::once())->method('setEnabled')->with(true)->willReturnSelf();

        $collection = $this->makeRealCollection(LeadRoutingRuleCollection::class, 'ordo_lead_routing_rule');
        $collection->addItem($ruleA);
        $collection->addItem($ruleB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $leadRoutingRuleCollectionFactory = $this->createStub(LeadRoutingRuleCollectionFactory::class);
        $leadRoutingRuleCollectionFactory->method('create')->willReturn($this->createStub(LeadRoutingRuleCollection::class));

        $leadRoutingRuleResource = $this->createMock(LeadRoutingRuleResource::class);
        $leadRoutingRuleResource->expects(self::exactly(2))->method('save');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 lead routing rule(s) have been enabled.', 2));

        $controller = new MassEnable($context, $filter, $leadRoutingRuleCollectionFactory, $leadRoutingRuleResource);
        $controller->execute();
    }
}
