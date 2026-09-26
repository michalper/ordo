<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AiAgent;

use Ordo\Automation\Model\AiAgent\AiAgentQuoteLine;
use Ordo\Automation\Model\AiAgent\AiAgentQuoteResult;
use PHPUnit\Framework\TestCase;

class AiAgentQuoteResultTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $line = new AiAgentQuoteLine();
        $result = new AiAgentQuoteResult();

        $result->setCurrency('USD');
        $result->setSubtotal(100.0);
        $result->setDiscountAmount(10.0);
        $result->setShippingAmount(5.0);
        $result->setGrandTotal(95.0);
        $result->setEstimatedDeliveryDays(5);
        $result->setLines([$line]);
        $result->setUnmatchedSkus(['MISSING-SKU']);

        self::assertSame('USD', $result->getCurrency());
        self::assertSame(100.0, $result->getSubtotal());
        self::assertSame(10.0, $result->getDiscountAmount());
        self::assertSame(5.0, $result->getShippingAmount());
        self::assertSame(95.0, $result->getGrandTotal());
        self::assertSame(5, $result->getEstimatedDeliveryDays());
        self::assertSame([$line], $result->getLines());
        self::assertSame(['MISSING-SKU'], $result->getUnmatchedSkus());
    }
}
