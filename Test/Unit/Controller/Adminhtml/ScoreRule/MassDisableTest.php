<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ScoreRule;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\ScoreRule\MassDisable;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Collection as ScoreRuleCollection;
use Ordo\Automation\Model\ResourceModel\ScoreRule\CollectionFactory as ScoreRuleCollectionFactory;
use Ordo\Automation\Model\ScoreRule;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDisableTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDisablesEverySelectedScoreRule(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $rule = $this->createMock(ScoreRule::class);
        $rule->expects(self::once())->method('setEnabled')->with(false)->willReturnSelf();

        $collection = $this->makeRealCollection(ScoreRuleCollection::class, 'ordo_score_rule');
        $collection->addItem($rule);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $scoreRuleCollectionFactory = $this->createStub(ScoreRuleCollectionFactory::class);
        $scoreRuleCollectionFactory->method('create')->willReturn($this->createStub(ScoreRuleCollection::class));

        $scoreRuleResource = $this->createMock(ScoreRuleResource::class);
        $scoreRuleResource->expects(self::once())->method('save')->with($rule);

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 score rule(s) have been disabled.', 1));

        $controller = new MassDisable($context, $filter, $scoreRuleCollectionFactory, $scoreRuleResource);
        $controller->execute();
    }
}
