<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AiAgent;

use Ordo\Automation\Model\AiAgent\AiAgentQuoteLine;
use PHPUnit\Framework\TestCase;

class AiAgentQuoteLineTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $line = new AiAgentQuoteLine();
        $line->setSku('SKU1');
        $line->setQty(2.0);
        $line->setUnitPrice(19.99);
        $line->setRowTotal(39.98);

        self::assertSame('SKU1', $line->getSku());
        self::assertSame(2.0, $line->getQty());
        self::assertSame(19.99, $line->getUnitPrice());
        self::assertSame(39.98, $line->getRowTotal());
    }
}
