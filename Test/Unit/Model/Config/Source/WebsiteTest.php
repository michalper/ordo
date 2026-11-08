<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\Config\Source\Website;
use PHPUnit\Framework\TestCase;

class WebsiteTest extends TestCase
{
    public function testToOptionArrayListsEveryWebsiteByIdAndName(): void
    {
        $main = $this->createStub(WebsiteInterface::class);
        $main->method('getId')->willReturn(1);
        $main->method('getName')->willReturn('Main Website');

        $eu = $this->createStub(WebsiteInterface::class);
        $eu->method('getId')->willReturn(2);
        $eu->method('getName')->willReturn('EU Website');

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([$main, $eu]);

        $options = (new Website($storeManager))->toOptionArray();

        self::assertSame([
            ['value' => 1, 'label' => 'Main Website'],
            ['value' => 2, 'label' => 'EU Website'],
        ], $options);
    }
}
