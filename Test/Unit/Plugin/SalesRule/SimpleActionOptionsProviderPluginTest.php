<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\SalesRule;

use Magento\SalesRule\Model\Rule\Action\SimpleActionOptionsProvider;
use Ordo\Automation\Plugin\SalesRule\SimpleActionOptionsProviderPlugin;
use PHPUnit\Framework\TestCase;

class SimpleActionOptionsProviderPluginTest extends TestCase
{
    public function testAfterToOptionArrayAppendsCheapestItemFreeOption(): void
    {
        $subject = $this->createStub(SimpleActionOptionsProvider::class);
        $native = [
            ['label' => 'Percent of product price discount', 'value' => 'by_percent'],
        ];

        $result = (new SimpleActionOptionsProviderPlugin())->afterToOptionArray($subject, $native);

        self::assertCount(2, $result);
        self::assertSame($native[0], $result[0]);
        self::assertSame('ordo_cheapest_item_free', $result[1]['value']);
    }
}
