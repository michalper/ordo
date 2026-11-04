<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Segment;

use Magento\Framework\App\Request\DataPersistorInterface;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\Segment\DataProvider;
use Ordo\Automation\Model\SegmentCondition;
use Ordo\Automation\Model\ResourceModel\Segment\Collection as SegmentCollection;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\Collection as ConditionCollection;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as ConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Segment\Website\Collection as WebsiteCollection;
use Ordo\Automation\Model\ResourceModel\Segment\Website\CollectionFactory as WebsiteCollectionFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class DataProviderTest extends TestCase
{
    private ConditionCollectionFactory $conditionCollectionFactory;
    private WebsiteCollectionFactory $websiteCollectionFactory;
    private DataPersistorInterface $dataPersistor;

    protected function setUp(): void
    {
        $this->conditionCollectionFactory = $this->createStub(ConditionCollectionFactory::class);
        $this->websiteCollectionFactory = $this->createStub(WebsiteCollectionFactory::class);
        $this->websiteCollectionFactory->method('create')->willReturn($this->makeEmptyWebsiteCollection());
        $this->dataPersistor = $this->createMock(DataPersistorInterface::class);
    }

    private function makeProvider(SegmentCollection $collection): DataProvider
    {
        $collectionFactory = $this->createStub(SegmentCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return new DataProvider(
            'ordo_segment_form_data_source',
            'entity_id',
            'entity_id',
            $collectionFactory,
            $this->conditionCollectionFactory,
            $this->websiteCollectionFactory,
            $this->dataPersistor
        );
    }

    private function makeEmptyConditionCollection(): ConditionCollection
    {
        $collection = $this->createStub(ConditionCollection::class);
        $collection->method('addSegmentFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        return $collection;
    }

    private function makeEmptyWebsiteCollection(): WebsiteCollection
    {
        $collection = $this->createStub(WebsiteCollection::class);
        $collection->method('addSegmentFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDataMergesConditionRows(): void
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getData')->willReturn(['entity_id' => 1, 'name' => 'VIP customers']);
        $segment->method('getEntityId')->willReturn(1);

        $collection = $this->createStub(SegmentCollection::class);
        $collection->method('getItems')->willReturn([$segment]);

        $conditionRow = $this->createStub(SegmentCondition::class);
        $conditionRow->method('getType')->willReturn('lifetime_spend');
        $conditionRow->method('getParamsJson')->willReturn(json_encode(['min' => '500']));
        $conditionCollection = $this->createStub(ConditionCollection::class);
        $conditionCollection->method('addSegmentFilter')->willReturnSelf();
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$conditionRow]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $this->dataPersistor->method('get')->willReturn(null);

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        // Double-nested under 'conditions' twice: the dynamicRows component's own name AND its
        // own <dataScope> are both "conditions" (see ordo_segment_form.xml), and
        // Magento_Ui/js/dynamic-rows/dynamic-rows.js reads/writes each row at that combined
        // path — a flat array here left the component's own elems empty on an existing
        // segment's edit reload (confirmed via a real CI run).
        self::assertSame('lifetime_spend', $data[1]['conditions']['conditions'][0]['type']);
        self::assertSame(json_encode(['min' => '500']), $data[1]['conditions']['conditions'][0]['params_json']);

        // Second call must hit the cached $loadedData branch, not reload from the collection.
        self::assertSame($data, $provider->getData());
    }

    /**
     * Regression test: the generic merge above (`$rowData += $decoded`) puts a real PHP array
     * under 'tags' for a saved tag/visitor_tag condition - the dynamicRows field it feeds
     * (ordo_segment_form.xml's own "tags" field, a plain text-valued Knockout observable
     * segment-tag-multiselect.js enhances into a chip editor) needs a JSON-encoded string, the
     * same shape the client posts back. Without restringifyJsonDedicatedFields(), re-opening a
     * saved tag condition showed no tags at all - confirmed directly against a real saved
     * segment, not just this test.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testGetDataRestringifiesTagsArrayBackIntoJsonForTheDynamicRowsField(): void
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getData')->willReturn(['entity_id' => 1, 'name' => 'VIP customers']);
        $segment->method('getEntityId')->willReturn(1);

        $collection = $this->createStub(SegmentCollection::class);
        $collection->method('getItems')->willReturn([$segment]);

        $conditionRow = $this->createStub(SegmentCondition::class);
        $conditionRow->method('getType')->willReturn('tag');
        $conditionRow->method('getParamsJson')->willReturn(json_encode(['tags' => ['vip', 'loyal'], 'match_mode' => 'all']));
        $conditionCollection = $this->createStub(ConditionCollection::class);
        $conditionCollection->method('addSegmentFilter')->willReturnSelf();
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$conditionRow]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $this->dataPersistor->method('get')->willReturn(null);

        $data = $this->makeProvider($collection)->getData();
        $rowData = $data[1]['conditions']['conditions'][0];

        self::assertSame(['vip', 'loyal'], json_decode($rowData['tags'], true));
        self::assertIsString($rowData['tags']);
        self::assertSame('all', $rowData['match_mode']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDataMapsGroupRowIntoGroupLogicAndNestedConditions(): void
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getData')->willReturn(['entity_id' => 1, 'name' => 'VIP customers']);
        $segment->method('getEntityId')->willReturn(1);

        $collection = $this->createStub(SegmentCollection::class);
        $collection->method('getItems')->willReturn([$segment]);

        $groupRow = $this->createStub(SegmentCondition::class);
        $groupRow->method('getType')->willReturn('group');
        $groupRow->method('getParams')->willReturn([
            'logic' => 'any',
            'conditions' => [
                ['type' => 'tag', 'params' => ['tag' => 'vip']],
            ],
        ]);
        $conditionCollection = $this->createStub(ConditionCollection::class);
        $conditionCollection->method('addSegmentFilter')->willReturnSelf();
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$groupRow]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $this->dataPersistor->method('get')->willReturn(null);

        $data = $this->makeProvider($collection)->getData();
        $groupRowData = $data[1]['conditions']['conditions'][0];

        self::assertSame('group', $groupRowData['type']);
        self::assertSame('any', $groupRowData['group_logic']);
        self::assertSame(
            [['type' => 'tag', 'params' => ['tag' => 'vip']]],
            json_decode($groupRowData['group_conditions_json'], true)
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDataSkipsMalformedNestedGroupItems(): void
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getData')->willReturn(['entity_id' => 1, 'name' => 'VIP customers']);
        $segment->method('getEntityId')->willReturn(1);

        $collection = $this->createStub(SegmentCollection::class);
        $collection->method('getItems')->willReturn([$segment]);

        $groupRow = $this->createStub(SegmentCondition::class);
        $groupRow->method('getType')->willReturn('group');
        $groupRow->method('getParams')->willReturn([
            'logic' => 'all',
            'conditions' => [
                'not an array',
                ['params' => ['tag' => 'vip']],
                ['type' => 42, 'params' => ['tag' => 'vip']],
                ['type' => 'tag', 'params' => ['tag' => 'vip']],
            ],
        ]);
        $conditionCollection = $this->createStub(ConditionCollection::class);
        $conditionCollection->method('addSegmentFilter')->willReturnSelf();
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$groupRow]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $this->dataPersistor->method('get')->willReturn(null);

        $data = $this->makeProvider($collection)->getData();
        $groupRowData = $data[1]['conditions']['conditions'][0];

        self::assertSame(
            [['type' => 'tag', 'params' => ['tag' => 'vip']]],
            json_decode($groupRowData['group_conditions_json'], true)
        );
    }

    public function testGetDataAppliesPersistedDataAndClearsIt(): void
    {
        $collection = $this->createStub(SegmentCollection::class);
        $collection->method('getItems')->willReturn([]);

        $this->conditionCollectionFactory->method('create')->willReturn($this->makeEmptyConditionCollection());

        $this->dataPersistor->method('get')
            ->willReturnMap([['ordo_segment', ['entity_id' => 5, 'name' => 'Draft']]]);
        $this->dataPersistor->expects(self::once())->method('clear')->with('ordo_segment');

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame(['entity_id' => 5, 'name' => 'Draft'], $data[5]);
    }

    public function testGetDataIgnoresPersistedDataWithoutEntityId(): void
    {
        $collection = $this->createStub(SegmentCollection::class);
        $collection->method('getItems')->willReturn([]);

        $this->conditionCollectionFactory->method('create')->willReturn($this->makeEmptyConditionCollection());

        $this->dataPersistor->method('get')->willReturn(['name' => 'Draft']);
        $this->dataPersistor->expects(self::once())->method('clear')->with('ordo_segment');

        $provider = $this->makeProvider($collection);

        self::assertSame([], $provider->getData());
    }
}
