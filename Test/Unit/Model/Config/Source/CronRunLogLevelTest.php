<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\CronRunLogLevel;
use Ordo\Automation\Model\Cron\CronRunLog;
use PHPUnit\Framework\TestCase;

class CronRunLogLevelTest extends TestCase
{
    public function testToOptionArrayListsBothLevels(): void
    {
        $options = (new CronRunLogLevel())->toOptionArray();

        $values = array_column($options, 'value');
        self::assertContains(CronRunLog::LEVEL_SUMMARY, $values);
        self::assertContains(CronRunLog::LEVEL_FAILURE, $values);
        self::assertCount(2, $options);
    }
}
