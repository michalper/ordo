<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ScoreRule;

use Magento\Framework\App\Request\DataPersistorInterface;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Collection as ScoreRuleCollection;
use Ordo\Automation\Model\ResourceModel\ScoreRule\CollectionFactory as ScoreRuleCollectionFactory;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Website\Collection as ScoreRuleWebsiteCollection;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Website\CollectionFactory as ScoreRuleWebsiteCollectionFactory;
use Ordo\Automation\Model\ScoreRule;
use Ordo\Automation\Model\ScoreRule\DataProvider;
use Ordo\Automation\Model\ScoreRuleWebsite;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class DataProviderTest extends TestCase
{
    private DataPersistorInterface $dataPersistor;
    private ScoreRuleWebsiteCollectionFactory $scoreRuleWebsiteCollectionFactory;

    protected function setUp(): void
    {
        $this->dataPersistor = $this->createMock(DataPersistorInterface::class);
        $this->scoreRuleWebsiteCollectionFactory = $this->createStub(ScoreRuleWebsiteCollectionFactory::class);
        $this->scoreRuleWebsiteCollectionFactory->method('create')->willReturn($this->emptyWebsiteCollection());
    }

    private function emptyWebsiteCollection(): ScoreRuleWebsiteCollection
    {
        $collection = $this->createStub(ScoreRuleWebsiteCollection::class);
        $collection->method('addScoreRuleFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        return $collection;
    }

    private function makeProvider(ScoreRuleCollection $collection): DataProvider
    {
        $collectionFactory = $this->createStub(ScoreRuleCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return new DataProvider(
            'ordo_scorerule_form_data_source',
            'entity_id',
            'entity_id',
            $collectionFactory,
            $this->scoreRuleWebsiteCollectionFactory,
            $this->dataPersistor
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDataLoadsFromCollectionKeyedByEntityId(): void
    {
        $rule = $this->createStub(ScoreRule::class);
        $rule->method('getEntityId')->willReturn(3);
        $rule->method('getData')->willReturn(['entity_id' => 3, 'attribute_code' => 'group_id']);

        $collection = $this->createStub(ScoreRuleCollection::class);
        $collection->method('getItems')->willReturn([$rule]);

        $this->dataPersistor->method('get')->willReturn(null);

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame(['entity_id' => 3, 'attribute_code' => 'group_id', 'website_ids' => []], $data[3]);

        // Second call must hit the cached $loadedData branch, not reload from the collection.
        self::assertSame($data, $provider->getData());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDataPopulatesWebsiteIdsFromTheScoreRuleWebsiteCollection(): void
    {
        $rule = $this->createStub(ScoreRule::class);
        $rule->method('getEntityId')->willReturn(3);
        $rule->method('getData')->willReturn(['entity_id' => 3, 'attribute_code' => 'group_id']);

        $collection = $this->createStub(ScoreRuleCollection::class);
        $collection->method('getItems')->willReturn([$rule]);

        $websiteRow = $this->createStub(ScoreRuleWebsite::class);
        $websiteRow->method('getWebsiteId')->willReturn(2);
        $websiteCollection = $this->createStub(ScoreRuleWebsiteCollection::class);
        $websiteCollection->method('addScoreRuleFilter')->willReturnSelf();
        $websiteCollection->method('getIterator')->willReturn(new \ArrayIterator([$websiteRow]));
        // A fresh stub, not setUp()'s shared $this->scoreRuleWebsiteCollectionFactory - PHPUnit's
        // mock matcher uses the FIRST method('create') stub registered for every call, so a
        // second ->method('create') on the same already-stubbed object would never override
        // setUp()'s own empty-collection default (confirmed directly).
        $this->scoreRuleWebsiteCollectionFactory = $this->createStub(ScoreRuleWebsiteCollectionFactory::class);
        $this->scoreRuleWebsiteCollectionFactory->method('create')->willReturn($websiteCollection);

        $this->dataPersistor->method('get')->willReturn(null);

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame([2], $data[3]['website_ids']);
    }

    public function testGetDataAppliesPersistedDataAndClearsIt(): void
    {
        $collection = $this->createStub(ScoreRuleCollection::class);
        $collection->method('getItems')->willReturn([]);

        $this->dataPersistor->method('get')
            ->willReturnMap([['ordo_score_rule', ['entity_id' => 5, 'attribute_code' => 'country_id']]]);
        $this->dataPersistor->expects(self::once())->method('clear')->with('ordo_score_rule');

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame(['entity_id' => 5, 'attribute_code' => 'country_id'], $data[5]);
    }

    public function testGetDataIgnoresPersistedDataWithoutEntityId(): void
    {
        $collection = $this->createStub(ScoreRuleCollection::class);
        $collection->method('getItems')->willReturn([]);

        $this->dataPersistor->method('get')->willReturn(['attribute_code' => 'country_id']);
        $this->dataPersistor->expects(self::once())->method('clear')->with('ordo_score_rule');

        $provider = $this->makeProvider($collection);

        self::assertSame([], $provider->getData());
    }
}
