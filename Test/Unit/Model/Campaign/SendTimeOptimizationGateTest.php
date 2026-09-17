<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Model\Campaign\CustomerTimezoneResolver;
use Ordo\Automation\Model\Campaign\SendTimeOptimizationGate;
use Ordo\Automation\Model\Campaign\SendTimeOptimizer;
use Ordo\Automation\Model\CampaignDispatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendTimeOptimizationGateTest extends TestCase
{
    private SendTimeOptimizer&\PHPUnit\Framework\MockObject\MockObject $sendTimeOptimizer;
    private CustomerTimezoneResolver&\PHPUnit\Framework\MockObject\MockObject $customerTimezoneResolver;
    private CampaignDispatcher&\PHPUnit\Framework\MockObject\MockObject $campaignDispatcher;
    private DateTime&\PHPUnit\Framework\MockObject\MockObject $dateTime;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private SendTimeOptimizationGate $gate;

    protected function setUp(): void
    {
        $this->sendTimeOptimizer = $this->createMock(SendTimeOptimizer::class);
        $this->customerTimezoneResolver = $this->createMock(CustomerTimezoneResolver::class);
        $this->campaignDispatcher = $this->createMock(CampaignDispatcher::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->gate = new SendTimeOptimizationGate(
            $this->sendTimeOptimizer,
            $this->customerTimezoneResolver,
            $this->campaignDispatcher,
            $this->dateTime,
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllowsReturnsTrueAndTouchesNothingElseWhenDisabled(): void
    {
        $this->sendTimeOptimizer->expects(self::never())->method('getBestHour');
        $this->campaignDispatcher->expects(self::never())->method('deferActionUntil');

        self::assertTrue($this->gate->allows(42, 7, 20, ['customer_id' => 42], false));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllowsReturnsTrueForASyntheticActionWithNoRealEntityId(): void
    {
        $this->sendTimeOptimizer->expects(self::never())->method('getBestHour');
        $this->campaignDispatcher->expects(self::never())->method('deferActionUntil');

        self::assertTrue($this->gate->allows(42, 7, 0, ['customer_id' => 42], true));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllowsReturnsTrueWhenThereIsNotEnoughDataToOptimize(): void
    {
        $this->sendTimeOptimizer->method('getBestHour')->willReturn(null);

        $this->campaignDispatcher->expects(self::never())->method('deferActionUntil');

        self::assertTrue($this->gate->allows(42, 7, 20, ['customer_id' => 42], true));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllowsReturnsTrueWhenTheBestHourIsAlreadyNow(): void
    {
        $this->sendTimeOptimizer->method('getBestHour')->willReturn(14);
        $this->customerTimezoneResolver->method('resolve')->willReturn(new \DateTimeZone('UTC'));
        // 2026-01-15 14:30:00 UTC -> local hour 14, same as the predicted best hour.
        $this->dateTime->method('gmtTimestamp')->willReturn(
            (new \DateTimeImmutable('2026-01-15 14:30:00', new \DateTimeZone('UTC')))->getTimestamp()
        );

        $this->campaignDispatcher->expects(self::never())->method('deferActionUntil');

        self::assertTrue($this->gate->allows(42, 7, 20, ['customer_id' => 42], true));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllowsReturnsFalseAndDefersToTodaysBestHourWhenItHasNotPassedYet(): void
    {
        $this->sendTimeOptimizer->method('getBestHour')->willReturn(18);
        $this->customerTimezoneResolver->method('resolve')->willReturn(new \DateTimeZone('UTC'));
        // Now is 09:00 UTC, best hour is 18:00 - later today.
        $this->dateTime->method('gmtTimestamp')->willReturn(
            (new \DateTimeImmutable('2026-01-15 09:00:00', new \DateTimeZone('UTC')))->getTimestamp()
        );

        $this->campaignDispatcher->expects(self::once())->method('deferActionUntil')
            ->with(7, 20, '2026-01-15 18:00:00', ['customer_id' => 42]);

        self::assertFalse($this->gate->allows(42, 7, 20, ['customer_id' => 42], true));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllowsReturnsFalseAndDefersToTomorrowsBestHourWhenItHasAlreadyPassedToday(): void
    {
        $this->sendTimeOptimizer->method('getBestHour')->willReturn(8);
        $this->customerTimezoneResolver->method('resolve')->willReturn(new \DateTimeZone('UTC'));
        // Now is 20:00 UTC, best hour is 08:00 - already passed today, so tomorrow.
        $this->dateTime->method('gmtTimestamp')->willReturn(
            (new \DateTimeImmutable('2026-01-15 20:00:00', new \DateTimeZone('UTC')))->getTimestamp()
        );

        $this->campaignDispatcher->expects(self::once())->method('deferActionUntil')
            ->with(7, 20, '2026-01-16 08:00:00', ['customer_id' => 42]);

        self::assertFalse($this->gate->allows(42, 7, 20, ['customer_id' => 42], true));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllowsConvertsTheDeferredTimestampBackToUtcForANonUtcTimezone(): void
    {
        $this->sendTimeOptimizer->method('getBestHour')->willReturn(8);
        $warsaw = new \DateTimeZone('Europe/Warsaw');
        $this->customerTimezoneResolver->method('resolve')->willReturn($warsaw);
        // 2026-01-15 09:00 UTC = 10:00 in Warsaw (UTC+1, January) - best hour 8 has already
        // passed locally today, so the next 08:00 Warsaw is tomorrow, which is 07:00 UTC.
        $this->dateTime->method('gmtTimestamp')->willReturn(
            (new \DateTimeImmutable('2026-01-15 09:00:00', new \DateTimeZone('UTC')))->getTimestamp()
        );

        $this->campaignDispatcher->expects(self::once())->method('deferActionUntil')
            ->with(7, 20, '2026-01-16 07:00:00', ['customer_id' => 42]);

        self::assertFalse($this->gate->allows(42, 7, 20, ['customer_id' => 42], true));
    }
}
