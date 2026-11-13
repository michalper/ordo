<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\ReferralStatus;
use Ordo\Automation\Model\Referral;
use PHPUnit\Framework\TestCase;

class ReferralStatusTest extends TestCase
{
    public function testToOptionArrayListsBothStatuses(): void
    {
        $options = (new ReferralStatus())->toOptionArray();

        $values = array_column($options, 'value');
        self::assertContains(Referral::STATUS_PENDING, $values);
        self::assertContains(Referral::STATUS_CONVERTED, $values);
        self::assertCount(2, $options);
    }
}
