<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Magento\Email\Model\ResourceModel\Template\Collection as MagentoTemplateCollection;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory as MagentoTemplateCollectionFactory;
use Magento\Email\Model\Template\Config as EmailTemplateConfig;
use Ordo\Automation\Model\Config\Source\EmailTemplateOptions;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class EmailTemplateOptionsTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testToOptionArrayCombinesXmlDefaultsAndDbSavedTemplates(): void
    {
        $emailTemplateConfig = $this->createStub(EmailTemplateConfig::class);
        $emailTemplateConfig->method('getAvailableTemplates')->willReturn([
            ['value' => 'ordo_campaign_generic', 'label' => 'Generic Campaign Email'],
        ]);

        $collection = $this->createStub(MagentoTemplateCollection::class);
        $collection->method('toOptionArray')->willReturn([
            ['value' => '12', 'label' => 'Custom Welcome'],
        ]);
        $collectionFactory = $this->createStub(MagentoTemplateCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $options = (new EmailTemplateOptions($emailTemplateConfig, $collectionFactory))->toOptionArray();

        self::assertSame([
            ['value' => 'ordo_campaign_generic', 'label' => 'Generic Campaign Email'],
            ['value' => 12, 'label' => 'Custom Welcome'],
        ], $options);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testToOptionArrayReturnsEmptyArrayWhenNeitherSourceHasAnything(): void
    {
        $emailTemplateConfig = $this->createStub(EmailTemplateConfig::class);
        $emailTemplateConfig->method('getAvailableTemplates')->willReturn([]);

        $collection = $this->createStub(MagentoTemplateCollection::class);
        $collection->method('toOptionArray')->willReturn([]);
        $collectionFactory = $this->createStub(MagentoTemplateCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        self::assertSame([], (new EmailTemplateOptions($emailTemplateConfig, $collectionFactory))->toOptionArray());
    }
}
