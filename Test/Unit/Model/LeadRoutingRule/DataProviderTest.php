<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\LeadRoutingRule;

use Magento\Framework\App\Request\DataPersistorInterface;
use Ordo\Automation\Model\LeadRoutingRule;
use Ordo\Automation\Model\LeadRoutingRule\DataProvider;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule\Collection as LeadRoutingRuleCollection;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule\CollectionFactory as LeadRoutingRuleCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class DataProviderTest extends TestCase
{
    private DataPersistorInterface $dataPersistor;

    protected function setUp(): void
    {
        $this->dataPersistor = $this->createMock(DataPersistorInterface::class);
    }

    private function makeProvider(LeadRoutingRuleCollection $collection): DataProvider
    {
        $collectionFactory = $this->createStub(LeadRoutingRuleCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return new DataProvider(
            'ordo_leadroutingrule_form_data_source',
            'entity_id',
            'entity_id',
            $collectionFactory,
            $this->dataPersistor
        );
    }

    /**
     * The "reps" JSON column must come back nested as {"reps": [...]} - the dataScope-then-
     * component-name shape the form's "reps" dynamicRows field expects on load.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testGetDataDecodesRepsIntoNestedArrayKeyedByEntityId(): void
    {
        $rule = $this->createStub(LeadRoutingRule::class);
        $rule->method('getEntityId')->willReturn(3);
        $rule->method('getData')->willReturn([
            'entity_id' => 3,
            'name' => 'EU B2B leads',
            'reps' => json_encode([['email' => 'rep1@example.com', 'name' => 'Rep One', 'phone' => '111']]),
        ]);

        $collection = $this->createStub(LeadRoutingRuleCollection::class);
        $collection->method('getItems')->willReturn([$rule]);

        $this->dataPersistor->method('get')->willReturn(null);

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame(
            [['email' => 'rep1@example.com', 'name' => 'Rep One', 'phone' => '111']],
            $data[3]['reps']['reps']
        );

        // Second call must hit the cached $loadedData branch, not reload from the collection.
        self::assertSame($data, $provider->getData());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDataDecodesBlankOrInvalidRepsAsEmptyArray(): void
    {
        $rule = $this->createStub(LeadRoutingRule::class);
        $rule->method('getEntityId')->willReturn(4);
        $rule->method('getData')->willReturn(['entity_id' => 4, 'reps' => '']);

        $collection = $this->createStub(LeadRoutingRuleCollection::class);
        $collection->method('getItems')->willReturn([$rule]);

        $this->dataPersistor->method('get')->willReturn(null);

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame([], $data[4]['reps']['reps']);
    }

    public function testGetDataAppliesPersistedDataAndClearsIt(): void
    {
        $collection = $this->createStub(LeadRoutingRuleCollection::class);
        $collection->method('getItems')->willReturn([]);

        $this->dataPersistor->method('get')
            ->willReturnMap([['ordo_lead_routing_rule', ['entity_id' => 5, 'name' => 'Persisted']]]);
        $this->dataPersistor->expects(self::once())->method('clear')->with('ordo_lead_routing_rule');

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame(['entity_id' => 5, 'name' => 'Persisted'], $data[5]);
    }

    public function testGetDataIgnoresPersistedDataWithoutEntityId(): void
    {
        $collection = $this->createStub(LeadRoutingRuleCollection::class);
        $collection->method('getItems')->willReturn([]);

        $this->dataPersistor->method('get')->willReturn(['name' => 'Persisted']);
        $this->dataPersistor->expects(self::once())->method('clear')->with('ordo_lead_routing_rule');

        $provider = $this->makeProvider($collection);

        self::assertSame([], $provider->getData());
    }
}
