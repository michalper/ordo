<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\Config\Source\AdminActionLogAction;
use PHPUnit\Framework\TestCase;

class AdminActionLogActionTest extends TestCase
{
    public function testToOptionArrayMatchesModelConstants(): void
    {
        $values = array_column(new AdminActionLogAction()->toOptionArray(), 'value');

        self::assertSame([AdminActionLog::ACTION_CREATE, AdminActionLog::ACTION_UPDATE], $values);
    }
}
