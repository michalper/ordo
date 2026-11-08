<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Config\Source\CampaignOptions;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use PHPUnit\Framework\TestCase;

class CampaignOptionsTest extends TestCase
{
    public function testToOptionArrayListsEveryCampaignByIdAndNameIncludingDisabledOnes(): void
    {
        $enabled = $this->createStub(Campaign::class);
        $enabled->method('getEntityId')->willReturn(1);
        $enabled->method('getName')->willReturn('Welcome Series');

        $disabled = $this->createStub(Campaign::class);
        $disabled->method('getEntityId')->willReturn(2);
        $disabled->method('getName')->willReturn('Old Win-Back (disabled)');

        $collectionFactory = $this->createStub(CampaignCollectionFactory::class);
        $collection = new \ArrayIterator([$enabled, $disabled]);
        $collectionFactory->method('create')->willReturn($collection);

        $options = (new CampaignOptions($collectionFactory))->toOptionArray();

        self::assertSame([
            ['value' => 1, 'label' => 'Welcome Series'],
            ['value' => 2, 'label' => 'Old Win-Back (disabled)'],
        ], $options);
    }
}
