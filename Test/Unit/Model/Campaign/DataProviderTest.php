<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Magento\Framework\App\Request\DataPersistorInterface;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Campaign\DataProvider;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\Collection as ActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as ActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Collection as CampaignCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\Collection as ConditionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as ConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection as TriggerCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as TriggerCollectionFactory;
use PHPUnit\Framework\TestCase;

class DataProviderTest extends TestCase
{
    private TriggerCollectionFactory $triggerCollectionFactory;
    private ConditionCollectionFactory $conditionCollectionFactory;
    private ActionCollectionFactory $actionCollectionFactory;
    private DataPersistorInterface $dataPersistor;

    protected function setUp(): void
    {
        $this->triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeEmptyTriggerCollection());
        $this->conditionCollectionFactory = $this->createMock(ConditionCollectionFactory::class);
        $this->actionCollectionFactory = $this->createMock(ActionCollectionFactory::class);
        $this->dataPersistor = $this->createMock(DataPersistorInterface::class);
    }

    private function makeProvider(CampaignCollection $collection): DataProvider
    {
        $collectionFactory = $this->createMock(CampaignCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return new DataProvider(
            'ordo_campaign_form_data_source',
            'entity_id',
            'entity_id',
            $collectionFactory,
            $this->triggerCollectionFactory,
            $this->conditionCollectionFactory,
            $this->actionCollectionFactory,
            $this->dataPersistor
        );
    }

    private function makeEmptyTriggerCollection(): TriggerCollection
    {
        $collection = $this->createMock(TriggerCollection::class);
        $collection->method('addCampaignFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        return $collection;
    }

    public function testGetDataMergesChildRowsWithDedicatedFields(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getData')->willReturn(['entity_id' => 1, 'name' => 'Welcome']);
        $campaign->method('getEntityId')->willReturn(1);

        $collection = $this->createMock(CampaignCollection::class);
        $collection->method('getItems')->willReturn([$campaign]);

        $conditionRow = $this->createMock(CampaignCondition::class);
        $conditionRow->method('getType')->willReturn('has_tag');
        $conditionRow->method('getParamsJson')->willReturn(json_encode(['tag' => 'vip']));
        $conditionCollection = $this->createMock(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$conditionRow]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getType')->willReturn('tag_customer');
        $actionRow->method('getParamsJson')->willReturn(json_encode(['tag' => 'reordered']));
        $actionRow->method('getDelayMinutes')->willReturn(60);
        $actionCollection = $this->createMock(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$actionRow]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $this->dataPersistor->method('get')->willReturn(null);

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame('vip', $data[1]['conditions'][0]['tag']);
        self::assertSame('reordered', $data[1]['actions'][0]['tag']);
        self::assertSame(60, $data[1]['actions'][0]['delay_minutes']);

        // Second call must hit the cached $loadedData branch, not reload from the collection.
        self::assertSame($data, $provider->getData());
    }

    public function testGetDataKeepsParamsJsonOnlyWhenDecodeFails(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getData')->willReturn(['entity_id' => 2]);
        $campaign->method('getEntityId')->willReturn(2);

        $collection = $this->createMock(CampaignCollection::class);
        $collection->method('getItems')->willReturn([$campaign]);

        $conditionRow = $this->createMock(CampaignCondition::class);
        $conditionRow->method('getType')->willReturn('has_tag');
        $conditionRow->method('getParamsJson')->willReturn('not-json');
        $conditionCollection = $this->createMock(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$conditionRow]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $this->actionCollectionFactory->method('create')->willReturn($this->makeEmptyActionCollection());
        $this->dataPersistor->method('get')->willReturn(null);

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame('not-json', $data[2]['conditions'][0]['params_json']);
        self::assertArrayNotHasKey('tag', $data[2]['conditions'][0]);
    }

    public function testGetDataIncludesTriggerRows(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getData')->willReturn(['entity_id' => 1, 'name' => 'Welcome']);
        $campaign->method('getEntityId')->willReturn(1);

        $collection = $this->createMock(CampaignCollection::class);
        $collection->method('getItems')->willReturn([$campaign]);

        $triggerRow = $this->createMock(\Ordo\Automation\Model\CampaignTrigger::class);
        $triggerRow->method('getTriggerEvent')->willReturn('order_placed');
        $triggerCollection = $this->createMock(TriggerCollection::class);
        $triggerCollection->method('addCampaignFilter');
        $triggerCollection->method('getIterator')->willReturn(new \ArrayIterator([$triggerRow]));
        $this->triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $this->triggerCollectionFactory->method('create')->willReturn($triggerCollection);

        $this->conditionCollectionFactory->method('create')->willReturn($this->makeEmptyConditionCollection());
        $this->actionCollectionFactory->method('create')->willReturn($this->makeEmptyActionCollection());
        $this->dataPersistor->method('get')->willReturn(null);

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame(['trigger_event' => 'order_placed'], $data[1]['triggers'][0]);
    }

    public function testGetDataAppliesPersistedDataAndClearsIt(): void
    {
        $collection = $this->createMock(CampaignCollection::class);
        $collection->method('getItems')->willReturn([]);

        $this->conditionCollectionFactory->method('create')->willReturn(
            $this->makeEmptyConditionCollection()
        );
        $this->actionCollectionFactory->method('create')->willReturn(
            $this->makeEmptyActionCollection()
        );

        $this->dataPersistor->method('get')->with('ordo_campaign')->willReturn(['entity_id' => 5, 'name' => 'Draft']);
        $this->dataPersistor->expects(self::once())->method('clear')->with('ordo_campaign');

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame(['entity_id' => 5, 'name' => 'Draft'], $data[5]);
    }

    private function makeEmptyConditionCollection(): ConditionCollection
    {
        $collection = $this->createMock(ConditionCollection::class);
        $collection->method('addCampaignFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        return $collection;
    }

    private function makeEmptyActionCollection(): ActionCollection
    {
        $collection = $this->createMock(ActionCollection::class);
        $collection->method('addCampaignFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        return $collection;
    }
}
