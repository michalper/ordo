<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\AdminActionLogEntityType;
use PHPUnit\Framework\TestCase;

class AdminActionLogEntityTypeTest extends TestCase
{
    public function testToOptionArrayReturnsCampaignAndSegment(): void
    {
        $values = array_column(new AdminActionLogEntityType()->toOptionArray(), 'value');

        self::assertSame(['campaign', 'segment'], $values);
    }
}
