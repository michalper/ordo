<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Ordo\Automation\Model\Queue\CampaignDispatchGuard;
use PHPUnit\Framework\TestCase;

class CampaignDispatchGuardTest extends TestCase
{
    public function testDefaultsToNotConsumingAndReflectsWhateverWasSetLast(): void
    {
        $guard = new CampaignDispatchGuard();

        self::assertFalse($guard->isConsuming());

        $guard->setConsuming(true);
        self::assertTrue($guard->isConsuming());

        $guard->setConsuming(false);
        self::assertFalse($guard->isConsuming());
    }
}
