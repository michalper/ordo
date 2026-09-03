<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ContentBlock;

use Magento\Framework\App\Request\DataPersistorInterface;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ContentBlock\DataProvider;
use Ordo\Automation\Model\ResourceModel\ContentBlock\Collection as ContentBlockCollection;
use Ordo\Automation\Model\ResourceModel\ContentBlock\CollectionFactory as ContentBlockCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class DataProviderTest extends TestCase
{
    private DataPersistorInterface $dataPersistor;

    protected function setUp(): void
    {
        $this->dataPersistor = $this->createMock(DataPersistorInterface::class);
    }

    private function makeProvider(ContentBlockCollection $collection): DataProvider
    {
        $collectionFactory = $this->createStub(ContentBlockCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return new DataProvider(
            'ordo_contentblock_form_data_source',
            'entity_id',
            'entity_id',
            $collectionFactory,
            $this->dataPersistor
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDataMergesFlatDataAndConfigArrayKeyedByEntityId(): void
    {
        $contentBlock = $this->createStub(ContentBlock::class);
        $contentBlock->method('getEntityId')->willReturn(3);
        $contentBlock->method('getData')->willReturn(['entity_id' => 3, 'type' => 'rss']);
        $contentBlock->method('getConfigArray')->willReturn(['feed_url' => 'https://example.test/feed.xml']);

        $collection = $this->createMock(ContentBlockCollection::class);
        $collection->expects(self::once())->method('getItems')->willReturn([$contentBlock]);

        $this->dataPersistor->method('get')->willReturn(null);

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame(
            ['entity_id' => 3, 'type' => 'rss', 'feed_url' => 'https://example.test/feed.xml'],
            $data[3]
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDataMemoizesAcrossCallsWithoutReReadingCollectionOrPersistor(): void
    {
        $contentBlock = $this->createStub(ContentBlock::class);
        $contentBlock->method('getEntityId')->willReturn(3);
        $contentBlock->method('getData')->willReturn(['entity_id' => 3]);
        $contentBlock->method('getConfigArray')->willReturn([]);

        $collection = $this->createMock(ContentBlockCollection::class);
        $collection->expects(self::once())->method('getItems')->willReturn([$contentBlock]);

        $this->dataPersistor->expects(self::once())->method('get')->willReturn(null);
        $this->dataPersistor->expects(self::never())->method('clear');

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame($data, $provider->getData());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDataAppliesPersistedDataOverLoadedValueAndClearsIt(): void
    {
        $contentBlock = $this->createStub(ContentBlock::class);
        $contentBlock->method('getEntityId')->willReturn(5);
        $contentBlock->method('getData')->willReturn(['entity_id' => 5, 'name' => 'Original']);
        $contentBlock->method('getConfigArray')->willReturn([]);

        $collection = $this->createMock(ContentBlockCollection::class);
        $collection->method('getItems')->willReturn([$contentBlock]);

        $this->dataPersistor->method('get')->with('ordo_content_block')
            ->willReturn(['entity_id' => 5, 'name' => 'Failed save attempt']);
        $this->dataPersistor->expects(self::once())->method('clear')->with('ordo_content_block');

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame(['entity_id' => 5, 'name' => 'Failed save attempt'], $data[5]);
    }

    public function testGetDataIgnoresPersistedDataWithoutEntityId(): void
    {
        $collection = $this->createStub(ContentBlockCollection::class);
        $collection->method('getItems')->willReturn([]);

        $this->dataPersistor->method('get')->willReturn(['name' => 'Orphaned']);
        $this->dataPersistor->expects(self::once())->method('clear')->with('ordo_content_block');

        $provider = $this->makeProvider($collection);

        self::assertSame([], $provider->getData());
    }
}
