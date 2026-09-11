<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Cron;

use Ordo\Automation\Model\Cron\CronRunLog;
use Ordo\Automation\Test\Unit\Model\AbstractModelTestCase;

class CronRunLogTest extends AbstractModelTestCase
{
    private function makeModel(): CronRunLog
    {
        return new CronRunLog($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testLevelRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setLevel(CronRunLog::LEVEL_FAILURE);
        self::assertSame('failure', $model->getLevel());
    }

    public function testMessageRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setMessage('Ordo_Automation: sent 3 win-back emails.');
        self::assertSame('Ordo_Automation: sent 3 win-back emails.', $model->getMessage());
    }

    public function testGetCreatedAtReturnsNullWhenUnset(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getCreatedAt());
    }
}
