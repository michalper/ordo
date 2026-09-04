<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ScoreRule;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\ScoreRule\Save;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ScoreRule;
use Ordo\Automation\Model\ScoreRuleFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SaveTest extends AbstractAdminActionTestCase
{
    private ScoreRuleFactory $scoreRuleFactory;
    private ScoreRuleResource $scoreRuleResource;

    protected function setUp(): void
    {
        $this->scoreRuleFactory = $this->createMock(ScoreRuleFactory::class);
        $this->scoreRuleResource = $this->createMock(ScoreRuleResource::class);
    }

    private function makeController(): Save
    {
        return new Save(
            $this->makeContext(),
            $this->scoreRuleFactory,
            $this->scoreRuleResource
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsImmediatelyWhenNoPostData(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(null);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->scoreRuleFactory->expects(self::never())->method('create');

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesNewScoreRuleAndRedirectsToGrid(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 0,
            'attribute_code' => 'group_id',
            'operator' => 'equals',
            'value' => '1',
            'points' => '10',
            'enabled' => '1',
            'sort_order' => '0',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', null]]);

        $scoreRule = $this->createMock(ScoreRule::class);
        $scoreRule->method('getEntityId')->willReturn(7);
        $this->scoreRuleFactory->method('create')->willReturn($scoreRule);

        $this->scoreRuleResource->expects(self::never())->method('load');
        $this->scoreRuleResource->expects(self::once())->method('save')->with($scoreRule);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLoadsExistingScoreRuleBeforeUpdating(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 3,
            'attribute_code' => 'group_id',
            'operator' => 'equals',
            'value' => '1',
            'points' => '-5',
            'enabled' => '0',
            'sort_order' => '10',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', '1']]);

        $scoreRule = $this->createMock(ScoreRule::class);
        $scoreRule->method('getEntityId')->willReturn(3);
        $this->scoreRuleFactory->method('create')->willReturn($scoreRule);

        $this->scoreRuleResource->expects(self::once())->method('load')->with($scoreRule, 3);
        $this->scoreRuleResource->expects(self::once())->method('save')->with($scoreRule);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToEditWithErrorWhenSaveThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['entity_id' => 3, 'attribute_code' => 'group_id']);

        $scoreRule = $this->createStub(ScoreRule::class);
        $this->scoreRuleFactory->method('create')->willReturn($scoreRule);
        $this->scoreRuleResource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }
}
