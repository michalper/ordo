<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AiAgent;

use Ordo\Automation\Model\AiAgent\AiAgentQuoteItem;
use PHPUnit\Framework\TestCase;

class AiAgentQuoteItemTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $item = new AiAgentQuoteItem();
        $item->setSku('SKU1');
        $item->setQty(2.5);

        self::assertSame('SKU1', $item->getSku());
        self::assertSame(2.5, $item->getQty());
    }
}
