<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\LeadRoutingRule;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\LeadRoutingRule\Save;
use Ordo\Automation\Model\LeadRoutingRule;
use Ordo\Automation\Model\LeadRoutingRuleFactory;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SaveTest extends AbstractAdminActionTestCase
{
    private LeadRoutingRuleFactory $leadRoutingRuleFactory;
    private LeadRoutingRuleResource $leadRoutingRuleResource;

    protected function setUp(): void
    {
        $this->leadRoutingRuleFactory = $this->createMock(LeadRoutingRuleFactory::class);
        $this->leadRoutingRuleResource = $this->createMock(LeadRoutingRuleResource::class);
    }

    private function makeController(): Save
    {
        return new Save(
            $this->makeContext(),
            $this->leadRoutingRuleFactory,
            $this->leadRoutingRuleResource
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

        $this->leadRoutingRuleFactory->expects(self::never())->method('create');

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesNewRuleAndRedirectsToGrid(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 0,
            'name' => 'EU B2B leads',
            'attribute_code' => 'group_id',
            'operator' => 'equals',
            'value' => '1',
            'enabled' => '1',
            'sort_order' => '0',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', null]]);

        $leadRoutingRule = $this->createMock(LeadRoutingRule::class);
        $leadRoutingRule->method('getEntityId')->willReturn(7);
        $this->leadRoutingRuleFactory->method('create')->willReturn($leadRoutingRule);

        $this->leadRoutingRuleResource->expects(self::never())->method('load');
        $this->leadRoutingRuleResource->expects(self::once())->method('save')->with($leadRoutingRule);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLoadsExistingRuleBeforeUpdating(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 3,
            'name' => 'EU B2B leads',
            'attribute_code' => 'group_id',
            'operator' => 'equals',
            'value' => '1',
            'enabled' => '0',
            'sort_order' => '10',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', '1']]);

        $leadRoutingRule = $this->createMock(LeadRoutingRule::class);
        $leadRoutingRule->method('getEntityId')->willReturn(3);
        $this->leadRoutingRuleFactory->method('create')->willReturn($leadRoutingRule);

        $this->leadRoutingRuleResource->expects(self::once())->method('load')->with($leadRoutingRule, 3);
        $this->leadRoutingRuleResource->expects(self::once())->method('save')->with($leadRoutingRule);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    /**
     * The real bug this PR fixed: the "reps" dynamicRows field posts nested as
     * $data['reps']['reps'] (dataScope matching its own component name), not $data['reps'].
     * Rows keyed by internal row id, blank-email rows dropped, survivors reindexed to a plain
     * JSON array.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteEncodesNestedRepsRowsIntoJsonArray(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 0,
            'name' => 'EU B2B leads',
            'attribute_code' => '',
            'operator' => '',
            'value' => '',
            'enabled' => '1',
            'sort_order' => '0',
            'reps' => [
                'reps' => [
                    '123' => ['email' => ' rep1@example.com ', 'name' => 'Rep One', 'phone' => '111'],
                    '456' => ['email' => '', 'name' => 'Blank Row', 'phone' => ''],
                    '789' => ['email' => 'rep2@example.com', 'name' => '', 'phone' => ''],
                ],
            ],
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', null]]);

        $leadRoutingRule = $this->createMock(LeadRoutingRule::class);
        $leadRoutingRule->method('getEntityId')->willReturn(9);
        $leadRoutingRule->expects(self::once())->method('setReps')->with(
            json_encode([
                ['email' => 'rep1@example.com', 'name' => 'Rep One', 'phone' => '111'],
                ['email' => 'rep2@example.com', 'name' => '', 'phone' => ''],
            ])
        );
        $this->leadRoutingRuleFactory->method('create')->willReturn($leadRoutingRule);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToEditWithErrorWhenSaveThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['entity_id' => 3, 'name' => 'EU B2B leads']);

        $leadRoutingRule = $this->createStub(LeadRoutingRule::class);
        $this->leadRoutingRuleFactory->method('create')->willReturn($leadRoutingRule);
        $this->leadRoutingRuleResource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }
}
