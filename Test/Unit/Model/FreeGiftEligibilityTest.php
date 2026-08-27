<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\FreeGiftEligibility;
use PHPUnit\Framework\TestCase;

class FreeGiftEligibilityTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $eligibility = new FreeGiftEligibility();
        $eligibility->setEarnedSlots(3);
        $eligibility->setUsedSlots(1);
        $eligibility->setRemainingSlots(2);
        $eligibility->setEligibleSkus(['SKU-1', 'SKU-2']);

        self::assertSame(3, $eligibility->getEarnedSlots());
        self::assertSame(1, $eligibility->getUsedSlots());
        self::assertSame(2, $eligibility->getRemainingSlots());
        self::assertSame(['SKU-1', 'SKU-2'], $eligibility->getEligibleSkus());
    }
}
