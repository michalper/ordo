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
use PHPUnit\Framework\TestCase;

class DataProviderTest extends TestCase
{
    private ConditionCollectionFactory $conditionCollectionFactory;
    private DataPersistorInterface $dataPersistor;

    protected function setUp(): void
    {
        $this->conditionCollectionFactory = $this->createMock(ConditionCollectionFactory::class);
        $this->dataPersistor = $this->createMock(DataPersistorInterface::class);
    }

    private function makeProvider(SegmentCollection $collection): DataProvider
    {
        $collectionFactory = $this->createMock(SegmentCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return new DataProvider(
            'ordo_segment_form_data_source',
            'entity_id',
            'entity_id',
            $collectionFactory,
            $this->conditionCollectionFactory,
            $this->dataPersistor
        );
    }

    private function makeEmptyConditionCollection(): ConditionCollection
    {
        $collection = $this->createMock(ConditionCollection::class);
        $collection->method('addSegmentFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        return $collection;
    }

    public function testGetDataMergesConditionRows(): void
    {
        $segment = $this->createMock(Segment::class);
        $segment->method('getData')->willReturn(['entity_id' => 1, 'name' => 'VIP customers']);
        $segment->method('getEntityId')->willReturn(1);

        $collection = $this->createMock(SegmentCollection::class);
        $collection->method('getItems')->willReturn([$segment]);

        $conditionRow = $this->createMock(SegmentCondition::class);
        $conditionRow->method('getType')->willReturn('lifetime_spend');
        $conditionRow->method('getParamsJson')->willReturn(json_encode(['min' => '500']));
        $conditionCollection = $this->createMock(ConditionCollection::class);
        $conditionCollection->method('addSegmentFilter')->willReturnSelf();
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$conditionRow]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $this->dataPersistor->method('get')->willReturn(null);

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame('lifetime_spend', $data[1]['conditions'][0]['type']);
        self::assertSame(json_encode(['min' => '500']), $data[1]['conditions'][0]['params_json']);

        // Second call must hit the cached $loadedData branch, not reload from the collection.
        self::assertSame($data, $provider->getData());
    }

    public function testGetDataAppliesPersistedDataAndClearsIt(): void
    {
        $collection = $this->createMock(SegmentCollection::class);
        $collection->method('getItems')->willReturn([]);

        $this->conditionCollectionFactory->method('create')->willReturn($this->makeEmptyConditionCollection());

        $this->dataPersistor->method('get')->with('ordo_segment')->willReturn(['entity_id' => 5, 'name' => 'Draft']);
        $this->dataPersistor->expects(self::once())->method('clear')->with('ordo_segment');

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame(['entity_id' => 5, 'name' => 'Draft'], $data[5]);
    }

    public function testGetDataIgnoresPersistedDataWithoutEntityId(): void
    {
        $collection = $this->createMock(SegmentCollection::class);
        $collection->method('getItems')->willReturn([]);

        $this->conditionCollectionFactory->method('create')->willReturn($this->makeEmptyConditionCollection());

        $this->dataPersistor->method('get')->willReturn(['name' => 'Draft']);
        $this->dataPersistor->expects(self::once())->method('clear')->with('ordo_segment');

        $provider = $this->makeProvider($collection);

        self::assertSame([], $provider->getData());
    }
}
