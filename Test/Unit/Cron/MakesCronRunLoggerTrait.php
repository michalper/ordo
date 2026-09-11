<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Model\Cron\CronRunLog;
use Ordo\Automation\Model\Cron\CronRunLogFactory;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\ResourceModel\Cron\CronRunLog as CronRunLogResource;
use Psr\Log\LoggerInterface;

/**
 * Every cron test in this directory builds a real CronRunLogger (not a mock of it) to exercise
 * its own logFailure()/logSummary() calls end to end - CronRunLogger's own persistence behavior
 * is covered separately, in Test\Unit\Model\Cron\CronRunLoggerTest. These stub-only factory/
 * resource args exist purely so CronRunLogger's own internals don't fatal on a null return - no
 * test in this directory asserts anything about the persisted row itself.
 */
trait MakesCronRunLoggerTrait
{
    private function makeCronRunLogger(LoggerInterface $logger): CronRunLogger
    {
        $factory = $this->createStub(CronRunLogFactory::class);
        $factory->method('create')->willReturn($this->createStub(CronRunLog::class));

        return new CronRunLogger($logger, $factory, $this->createStub(CronRunLogResource::class));
    }
}
