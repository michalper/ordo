<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Segment;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\Segment\Save;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\SegmentCondition;
use Ordo\Automation\Model\SegmentConditionFactory;
use Ordo\Automation\Model\SegmentFactory;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition as SegmentConditionResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\Collection as SegmentConditionCollection;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;

class SaveTest extends AbstractAdminActionTestCase
{
    private SegmentFactory $segmentFactory;
    private SegmentResource $segmentResource;
    private SegmentConditionFactory $segmentConditionFactory;
    private SegmentConditionResource $segmentConditionResource;
    private SegmentConditionCollectionFactory $segmentConditionCollectionFactory;

    protected function setUp(): void
    {
        $this->segmentFactory = $this->createMock(SegmentFactory::class);
        $this->segmentResource = $this->createMock(SegmentResource::class);
        $this->segmentConditionFactory = $this->createMock(SegmentConditionFactory::class);
        $this->segmentConditionResource = $this->createMock(SegmentConditionResource::class);
        $this->segmentConditionCollectionFactory = $this->createMock(SegmentConditionCollectionFactory::class);
    }

    private function makeController(): Save
    {
        return new Save(
            $this->makeContext(),
            $this->segmentFactory,
            $this->segmentResource,
            $this->segmentConditionFactory,
            $this->segmentConditionResource,
            $this->segmentConditionCollectionFactory
        );
    }

    private function emptyConditionCollection(): SegmentConditionCollection
    {
        $collection = $this->createMock(SegmentConditionCollection::class);
        $collection->method('addSegmentFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        return $collection;
    }

    public function testExecuteRedirectsImmediatelyWhenNoPostData(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(null);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->segmentFactory->expects(self::never())->method('create');

        self::assertSame($redirect, $controller->execute());
    }

    public function testExecuteSavesNewSegmentAndRedirectsToGrid(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn([
            'entity_id' => 0,
            'name' => 'VIP customers',
            'enabled' => '1',
            'conditions' => ['conditions' => [['type' => 'lifetime_spend', 'params_json' => '{"min":"500"}']]],
        ]);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $segment = $this->createMock(Segment::class);
        $segment->expects(self::once())->method('setName')->with('VIP customers');
        $segment->expects(self::once())->method('setEnabled')->with(true);
        $segment->method('getEntityId')->willReturn(7);
        $this->segmentFactory->method('create')->willReturn($segment);
        $this->segmentResource->expects(self::once())->method('save')->with($segment);

        $this->segmentConditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $condition = $this->createMock(SegmentCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['segment_id'] === 7
                && $data['type'] === 'lifetime_spend'
                && json_decode($data['params'], true) === ['min' => '500']
                && $data['sort_order'] === 0
        ));
        $this->segmentConditionFactory->method('create')->willReturn($condition);
        $this->segmentConditionResource->expects(self::once())->method('save')->with($condition);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    public function testExecuteLoadsExistingSegmentAndDeletesOldConditions(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn([
            'entity_id' => 7,
            'name' => 'VIP customers',
        ]);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(7);
        $this->segmentFactory->method('create')->willReturn($segment);
        $this->segmentResource->expects(self::once())->method('load')->with($segment, 7);

        $existingCondition = $this->createMock(SegmentCondition::class);
        $conditionCollection = $this->createMock(SegmentConditionCollection::class);
        $conditionCollection->method('addSegmentFilter')->willReturnSelf();
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$existingCondition]));
        $this->segmentConditionCollectionFactory->method('create')->willReturn($conditionCollection);
        $this->segmentConditionResource->expects(self::once())->method('delete')->with($existingCondition);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();
    }

    public function testExecuteSkipsConditionRowsWithoutType(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn([
            'conditions' => ['conditions' => [['type' => '']]],
        ]);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(1);
        $this->segmentFactory->method('create')->willReturn($segment);

        $this->segmentConditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $this->segmentConditionFactory->expects(self::never())->method('create');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();
    }

    public function testExecuteRedirectsToEditWhenBackParamSet(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['name' => 'VIP customers']);
        $this->request->method('getParam')->with('back')->willReturn('1');

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(7);
        $this->segmentFactory->method('create')->willReturn($segment);

        $this->segmentConditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/edit', ['entity_id' => 7])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    public function testExecuteRedirectsToEditWithErrorWhenSaveThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['entity_id' => 3, 'name' => 'VIP customers']);

        $segment = $this->createMock(Segment::class);
        $this->segmentFactory->method('create')->willReturn($segment);
        $this->segmentResource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/edit', ['entity_id' => 3])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    public function testExecuteDefaultsParamsToEmptyJsonObjectWhenAbsent(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn([
            'conditions' => ['conditions' => [['type' => 'has_tag']]],
        ]);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(1);
        $this->segmentFactory->method('create')->willReturn($segment);

        $this->segmentConditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $condition = $this->createMock(SegmentCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['params'] === '{}'
        ));
        $this->segmentConditionFactory->method('create')->willReturn($condition);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();
    }
}
