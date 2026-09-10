<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Segment;

use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition as SegmentConditionResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\Collection as SegmentConditionCollection;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\Segment\SegmentSaveProcessor;
use Ordo\Automation\Model\SegmentCondition;
use Ordo\Automation\Model\SegmentConditionFactory;
use Ordo\Automation\Model\SegmentFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class SegmentSaveProcessorTest extends TestCase
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
        $this->segmentConditionCollectionFactory = $this->createStub(SegmentConditionCollectionFactory::class);
    }

    private function makeProcessor(): SegmentSaveProcessor
    {
        return new SegmentSaveProcessor(
            $this->segmentFactory,
            $this->segmentResource,
            $this->segmentConditionFactory,
            $this->segmentConditionResource,
            $this->segmentConditionCollectionFactory
        );
    }

    private function emptyConditionCollection(): SegmentConditionCollection
    {
        $collection = $this->createStub(SegmentConditionCollection::class);
        $collection->method('addSegmentFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessSavesNewSegmentAndConditions(): void
    {
        $processor = $this->makeProcessor();

        $segment = $this->createMock(Segment::class);
        $segment->expects(self::once())->method('setName')->with('VIP customers');
        $segment->expects(self::once())->method('setEnabled')->with(true);
        $segment->expects(self::once())->method('setConditionLogic')->with('all');
        $segment->method('getEntityId')->willReturn(7);
        $this->segmentFactory->method('create')->willReturn($segment);
        $this->segmentResource->expects(self::once())->method('save')->with($segment);
        $this->segmentResource->expects(self::never())->method('load');

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

        $result = $processor->process([
            'entity_id' => 0,
            'name' => 'VIP customers',
            'enabled' => '1',
            'conditions' => ['conditions' => [['type' => 'lifetime_spend', 'params_json' => '{"min":"500"}']]],
        ]);

        self::assertSame($segment, $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessLoadsExistingSegmentAndDeletesOldConditions(): void
    {
        $processor = $this->makeProcessor();

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(7);
        $this->segmentFactory->method('create')->willReturn($segment);
        $this->segmentResource->expects(self::once())->method('load')->with($segment, 7);

        $existingCondition = $this->createStub(SegmentCondition::class);
        $conditionCollection = $this->createStub(SegmentConditionCollection::class);
        $conditionCollection->method('addSegmentFilter')->willReturnSelf();
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$existingCondition]));
        $this->segmentConditionCollectionFactory->method('create')->willReturn($conditionCollection);
        $this->segmentConditionResource->expects(self::once())->method('delete')->with($existingCondition);

        $processor->process(['entity_id' => 7, 'name' => 'VIP customers']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessSkipsConditionRowsWithoutType(): void
    {
        $processor = $this->makeProcessor();

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(1);
        $this->segmentFactory->method('create')->willReturn($segment);

        $this->segmentConditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $this->segmentConditionFactory->expects(self::never())->method('create');

        $processor->process(['conditions' => ['conditions' => [['type' => '']]]]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessDefaultsParamsToEmptyJsonObjectWhenAbsent(): void
    {
        $processor = $this->makeProcessor();

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(1);
        $this->segmentFactory->method('create')->willReturn($segment);

        $this->segmentConditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $condition = $this->createMock(SegmentCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['params'] === '{}'
        ));
        $this->segmentConditionFactory->method('create')->willReturn($condition);

        $processor->process(['conditions' => ['conditions' => [['type' => 'has_tag']]]]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessMergesDedicatedFieldsOverParamsJson(): void
    {
        $processor = $this->makeProcessor();

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(1);
        $this->segmentFactory->method('create')->willReturn($segment);

        $this->segmentConditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $condition = $this->createMock(SegmentCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            // "amount" from the dedicated field wins over the stale "200" the JSON textarea
            // still had - same "dedicated fields win" precedence as
            // Model\Campaign\CampaignSaveProcessor::normalizeRowParams().
            fn (array $data) => json_decode($data['params'], true) === ['amount' => '500']
        ));
        $this->segmentConditionFactory->method('create')->willReturn($condition);

        $processor->process([
            'conditions' => ['conditions' => [[
                'type' => 'monetary_total_at_least',
                'params_json' => '{"amount":"200"}',
                'amount' => '500',
            ]]],
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessPersistsEventOccurredConditionFields(): void
    {
        $processor = $this->makeProcessor();

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(1);
        $this->segmentFactory->method('create')->willReturn($segment);

        $this->segmentConditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $condition = $this->createMock(SegmentCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => json_decode($data['params'], true) === [
                'event_type' => 'cart_add',
                'event_key' => '24-MB01',
                'within_days' => '14',
            ]
        ));
        $this->segmentConditionFactory->method('create')->willReturn($condition);

        $processor->process([
            'conditions' => ['conditions' => [[
                'type' => 'event_occurred',
                'event_type' => 'cart_add',
                'event_key' => '24-MB01',
                'within_days' => '14',
                'params_json' => '',
            ]]],
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessSetsAnyConditionLogicWhenPosted(): void
    {
        $processor = $this->makeProcessor();

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(1);
        $segment->expects(self::once())->method('setConditionLogic')->with('any');
        $this->segmentFactory->method('create')->willReturn($segment);

        $this->segmentConditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $processor->process(['name' => 'Anyone', 'condition_logic' => 'any']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessSavesGroupRowAsNestedJson(): void
    {
        $processor = $this->makeProcessor();

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(1);
        $this->segmentFactory->method('create')->willReturn($segment);

        $this->segmentConditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $condition = $this->createMock(SegmentCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['type'] === 'group'
                && json_decode($data['params'], true) === [
                    'logic' => 'any',
                    'conditions' => [
                        ['type' => 'tag', 'params' => ['tag' => 'vip']],
                        ['type' => 'monetary_total_at_least', 'params' => ['amount' => '1']],
                    ],
                ]
        ));
        $this->segmentConditionFactory->method('create')->willReturn($condition);

        $processor->process([
            'conditions' => ['conditions' => [[
                'type' => 'group',
                'group_logic' => 'any',
                'group_conditions_json' => json_encode([
                    ['type' => 'tag', 'params' => ['tag' => 'vip']],
                    ['type' => 'monetary_total_at_least', 'params' => ['amount' => '1']],
                ]),
            ]]],
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessSkipsNestedGroupRowsWithoutType(): void
    {
        $processor = $this->makeProcessor();

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(1);
        $this->segmentFactory->method('create')->willReturn($segment);

        $this->segmentConditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $condition = $this->createMock(SegmentCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => json_decode($data['params'], true) === ['logic' => 'all', 'conditions' => []]
        ));
        $this->segmentConditionFactory->method('create')->willReturn($condition);

        $processor->process([
            'conditions' => ['conditions' => [[
                'type' => 'group',
                'group_conditions_json' => json_encode([['type' => '']]),
            ]]],
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessCapsConditionsAtMaxPerList(): void
    {
        $processor = $this->makeProcessor();

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(1);
        $this->segmentFactory->method('create')->willReturn($segment);

        $this->segmentConditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $this->segmentConditionFactory->expects(self::exactly(10))->method('create')
            ->willReturn($this->createStub(SegmentCondition::class));

        $rows = [];
        for ($i = 0; $i < 15; $i++) {
            $rows[] = ['type' => 'tag', 'tag' => "tag{$i}"];
        }

        $processor->process(['conditions' => ['conditions' => $rows]]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessPropagatesExceptionFromSave(): void
    {
        $processor = $this->makeProcessor();

        $segment = $this->createStub(Segment::class);
        $this->segmentFactory->method('create')->willReturn($segment);
        $this->segmentResource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db down');

        $processor->process(['entity_id' => 3, 'name' => 'VIP customers']);
    }
}
