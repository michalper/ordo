<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\EmailTemplateDraft;

use Magento\Framework\App\Request\DataPersistorInterface;
use Ordo\Automation\Model\EmailTemplateDraft;
use Ordo\Automation\Model\EmailTemplateDraft\DataProvider;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft\Collection as EmailTemplateDraftCollection;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft\CollectionFactory as EmailTemplateDraftCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class DataProviderTest extends TestCase
{
    private DataPersistorInterface $dataPersistor;

    protected function setUp(): void
    {
        $this->dataPersistor = $this->createMock(DataPersistorInterface::class);
    }

    private function makeProvider(EmailTemplateDraftCollection $collection): DataProvider
    {
        $collectionFactory = $this->createStub(EmailTemplateDraftCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return new DataProvider(
            'ordo_emailtemplatedraft_form_data_source',
            'entity_id',
            'entity_id',
            $collectionFactory,
            $this->dataPersistor
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDataLoadsFromCollectionKeyedByEntityId(): void
    {
        $draft = $this->createStub(EmailTemplateDraft::class);
        $draft->method('getEntityId')->willReturn(3);
        $draft->method('getData')->willReturn(['entity_id' => 3, 'template_code' => 'ordo_campaign_generic']);

        $collection = $this->createStub(EmailTemplateDraftCollection::class);
        $collection->method('getItems')->willReturn([$draft]);

        $this->dataPersistor->method('get')->willReturn(null);

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame(['entity_id' => 3, 'template_code' => 'ordo_campaign_generic'], $data[3]);
        self::assertSame($data, $provider->getData());
    }

    public function testGetDataAppliesPersistedDataAndClearsIt(): void
    {
        $collection = $this->createStub(EmailTemplateDraftCollection::class);
        $collection->method('getItems')->willReturn([]);

        $this->dataPersistor->method('get')
            ->willReturnMap([['ordo_email_template_draft', ['entity_id' => 5, 'template_subject' => 'Persisted']]]);
        $this->dataPersistor->expects(self::once())->method('clear')->with('ordo_email_template_draft');

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame(['entity_id' => 5, 'template_subject' => 'Persisted'], $data[5]);
    }

    public function testGetDataIgnoresPersistedDataWithoutEntityId(): void
    {
        $collection = $this->createStub(EmailTemplateDraftCollection::class);
        $collection->method('getItems')->willReturn([]);

        $this->dataPersistor->method('get')->willReturn(['template_subject' => 'Persisted']);
        $this->dataPersistor->expects(self::once())->method('clear')->with('ordo_email_template_draft');

        $provider = $this->makeProvider($collection);

        self::assertSame([], $provider->getData());
    }
}
