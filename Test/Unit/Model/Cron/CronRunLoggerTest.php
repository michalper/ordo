<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Cron;

use Ordo\Automation\Model\Cron\CronRunLog;
use Ordo\Automation\Model\Cron\CronRunLogFactory;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\ResourceModel\Cron\CronRunLog as CronRunLogResource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class CronRunLoggerTest extends TestCase
{
    private LoggerInterface $logger;
    private CronRunLogFactory $cronRunLogFactory;
    private CronRunLogResource $cronRunLogResource;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cronRunLogFactory = $this->createMock(CronRunLogFactory::class);
        $this->cronRunLogResource = $this->createMock(CronRunLogResource::class);
    }

    private function makeLogger(): CronRunLogger
    {
        return new CronRunLogger($this->logger, $this->cronRunLogFactory, $this->cronRunLogResource);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLogFailureFormatsActionAndExceptionMessage(): void
    {
        $this->logger->expects(self::once())->method('error')->with(
            'Ordo_Automation: failed to send win-back email to customer #5: send failed'
        );
        $this->cronRunLogFactory->method('create')->willReturn($this->createStub(CronRunLog::class));

        $this->makeLogger()->logFailure(
            'send win-back email to customer #5',
            new \RuntimeException('send failed')
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLogSummaryFormatsSummaryWithPrefixAndPeriod(): void
    {
        $this->logger->expects(self::once())->method('info')->with('Ordo_Automation: sent 3 win-back emails.');
        $this->cronRunLogFactory->method('create')->willReturn($this->createStub(CronRunLog::class));

        $this->makeLogger()->logSummary('sent 3 win-back emails');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLogFailurePersistsAFailureLevelRowWithTheSameFormattedMessage(): void
    {
        $entry = $this->createMock(CronRunLog::class);
        $entry->expects(self::once())->method('setLevel')->with(CronRunLog::LEVEL_FAILURE);
        $entry->expects(self::once())->method('setMessage')->with(
            'Ordo_Automation: failed to send win-back email to customer #5: send failed'
        );
        $this->cronRunLogFactory->method('create')->willReturn($entry);
        $this->cronRunLogResource->expects(self::once())->method('save')->with($entry);

        $this->makeLogger()->logFailure('send win-back email to customer #5', new \RuntimeException('send failed'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLogSummaryPersistsASummaryLevelRowWithTheSameFormattedMessage(): void
    {
        $entry = $this->createMock(CronRunLog::class);
        $entry->expects(self::once())->method('setLevel')->with(CronRunLog::LEVEL_SUMMARY);
        $entry->expects(self::once())->method('setMessage')->with('Ordo_Automation: sent 3 win-back emails.');
        $this->cronRunLogFactory->method('create')->willReturn($entry);
        $this->cronRunLogResource->expects(self::once())->method('save')->with($entry);

        $this->makeLogger()->logSummary('sent 3 win-back emails');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLogSummarySwallowsAPersistFailureInsteadOfThrowing(): void
    {
        $this->cronRunLogFactory->method('create')->willReturn($this->createStub(CronRunLog::class));
        $this->cronRunLogResource->method('save')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects(self::once())->method('info');
        $this->logger->expects(self::once())->method('error')->with(
            self::stringContains('failed to persist cron run log entry')
        );

        $this->makeLogger()->logSummary('sent 3 win-back emails');
    }
}
