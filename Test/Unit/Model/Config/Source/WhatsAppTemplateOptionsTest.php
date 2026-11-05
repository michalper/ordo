<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\WhatsAppTemplateOptions;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate\Collection as WhatsAppTemplateCollection;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate\CollectionFactory as WhatsAppTemplateCollectionFactory;
use Ordo\Automation\Model\WhatsAppTemplate;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class WhatsAppTemplateOptionsTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testToOptionArrayListsOnlyApprovedTemplatesByIdAndName(): void
    {
        $templateA = $this->createStub(WhatsAppTemplate::class);
        $templateA->method('getEntityId')->willReturn(1);
        $templateA->method('getName')->willReturn('Order Shipped');
        $templateA->method('getPlaceholderLegend')->willReturn(null);

        $collection = $this->createMock(WhatsAppTemplateCollection::class);
        $collection->expects(self::once())->method('addApprovedFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$templateA]));

        $collectionFactory = $this->createStub(WhatsAppTemplateCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $options = (new WhatsAppTemplateOptions($collectionFactory))->toOptionArray();

        self::assertSame([
            ['value' => 1, 'label' => 'Order Shipped'],
        ], $options);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testToOptionArrayAppendsThePlaceholderLegendWhenSet(): void
    {
        $templateA = $this->createStub(WhatsAppTemplate::class);
        $templateA->method('getEntityId')->willReturn(1);
        $templateA->method('getName')->willReturn('Order Shipped');
        $templateA->method('getPlaceholderLegend')->willReturn('{{1}} = first name, {{2}} = order number');

        $collection = $this->createStub(WhatsAppTemplateCollection::class);
        $collection->method('addApprovedFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$templateA]));

        $collectionFactory = $this->createStub(WhatsAppTemplateCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $options = (new WhatsAppTemplateOptions($collectionFactory))->toOptionArray();

        self::assertSame([
            ['value' => 1, 'label' => 'Order Shipped ({{1}} = first name, {{2}} = order number)'],
        ], $options);
    }
}
