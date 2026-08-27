<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\CreditLimitStatus;
use PHPUnit\Framework\TestCase;

class CreditLimitStatusTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $status = new CreditLimitStatus();
        $status->setCreditLimit(1000.0);
        $status->setUsedCredit(400.0);
        $status->setAvailableCredit(600.0);
        $status->setUtilizationPercent(40.0);

        self::assertSame(1000.0, $status->getCreditLimit());
        self::assertSame(400.0, $status->getUsedCredit());
        self::assertSame(600.0, $status->getAvailableCredit());
        self::assertSame(40.0, $status->getUtilizationPercent());
    }

    public function testAvailableCreditCanBeNegativeWhenOverLimit(): void
    {
        $status = new CreditLimitStatus();
        $status->setAvailableCredit(-250.0);

        self::assertSame(-250.0, $status->getAvailableCredit());
    }
}
