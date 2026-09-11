<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ScoreRule;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\ScoreRule\MassDelete;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Collection as ScoreRuleCollection;
use Ordo\Automation\Model\ResourceModel\ScoreRule\CollectionFactory as ScoreRuleCollectionFactory;
use Ordo\Automation\Model\ScoreRule;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDeleteTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesEverySelectedScoreRule(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $ruleA = $this->createStub(ScoreRule::class);
        $ruleB = $this->createStub(ScoreRule::class);

        $collection = $this->makeRealCollection(ScoreRuleCollection::class, 'ordo_score_rule');
        $collection->addItem($ruleA);
        $collection->addItem($ruleB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $scoreRuleCollectionFactory = $this->createStub(ScoreRuleCollectionFactory::class);
        $scoreRuleCollectionFactory->method('create')->willReturn($this->createStub(ScoreRuleCollection::class));

        $scoreRuleResource = $this->createMock(ScoreRuleResource::class);
        $scoreRuleResource->expects(self::exactly(2))->method('delete');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 score rule(s) have been deleted.', 2));

        $controller = new MassDelete($context, $filter, $scoreRuleCollectionFactory, $scoreRuleResource);
        $controller->execute();
    }
}
