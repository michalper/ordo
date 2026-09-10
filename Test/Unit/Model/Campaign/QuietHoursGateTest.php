<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\CustomerTimezoneResolver;
use Ordo\Automation\Model\Campaign\QuietHoursCalculator;
use Ordo\Automation\Model\Campaign\QuietHoursGate;
use Ordo\Automation\Model\CampaignDispatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QuietHoursGateTest extends TestCase
{
    private Config&\PHPUnit\Framework\MockObject\MockObject $config;
    private CustomerTimezoneResolver&\PHPUnit\Framework\MockObject\MockObject $customerTimezoneResolver;
    private QuietHoursCalculator&\PHPUnit\Framework\MockObject\MockObject $quietHoursCalculator;
    private CampaignDispatcher&\PHPUnit\Framework\MockObject\MockObject $campaignDispatcher;
    private DateTime&\PHPUnit\Framework\MockObject\MockObject $dateTime;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private QuietHoursGate $gate;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->customerTimezoneResolver = $this->createMock(CustomerTimezoneResolver::class);
        $this->quietHoursCalculator = $this->createMock(QuietHoursCalculator::class);
        $this->campaignDispatcher = $this->createMock(CampaignDispatcher::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->gate = new QuietHoursGate(
            $this->config,
            $this->customerTimezoneResolver,
            $this->quietHoursCalculator,
            $this->campaignDispatcher,
            $this->dateTime,
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllowsReturnsTrueAndTouchesNothingElseWhenDisabled(): void
    {
        $this->config->method('isQuietHoursEnabled')->willReturn(false);

        $this->customerTimezoneResolver->expects(self::never())->method('resolve');
        $this->campaignDispatcher->expects(self::never())->method('deferActionUntil');

        self::assertTrue($this->gate->allows(42, 7, 20, ['customer_id' => 42]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllowsReturnsTrueWhenOutsideQuietHours(): void
    {
        $this->config->method('isQuietHoursEnabled')->willReturn(true);
        $this->config->method('getQuietHoursStartHour')->willReturn(21);
        $this->config->method('getQuietHoursEndHour')->willReturn(8);
        $this->customerTimezoneResolver->method('resolve')->willReturn(new \DateTimeZone('UTC'));
        $this->dateTime->method('gmtTimestamp')->willReturn(1700000000);
        $this->quietHoursCalculator->method('isWithinQuietHours')->willReturn(false);

        $this->campaignDispatcher->expects(self::never())->method('deferActionUntil');

        self::assertTrue($this->gate->allows(42, 7, 20, ['customer_id' => 42]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllowsReturnsTrueForASyntheticActionWithNoRealEntityId(): void
    {
        $this->config->method('isQuietHoursEnabled')->willReturn(true);

        $this->customerTimezoneResolver->expects(self::never())->method('resolve');
        $this->campaignDispatcher->expects(self::never())->method('deferActionUntil');

        self::assertTrue($this->gate->allows(42, 7, 0, ['customer_id' => 42]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllowsReturnsFalseAndDefersWhenInsideQuietHours(): void
    {
        $this->config->method('isQuietHoursEnabled')->willReturn(true);
        $this->config->method('getQuietHoursStartHour')->willReturn(21);
        $this->config->method('getQuietHoursEndHour')->willReturn(8);
        $this->customerTimezoneResolver->method('resolve')->willReturn(new \DateTimeZone('UTC'));
        $this->dateTime->method('gmtTimestamp')->willReturn(1700000000);
        $this->quietHoursCalculator->method('isWithinQuietHours')->willReturn(true);
        $this->quietHoursCalculator->method('nextQuietHoursEndUtc')
            ->willReturn(new \DateTimeImmutable('2026-01-16 08:00:00', new \DateTimeZone('UTC')));

        $this->campaignDispatcher->expects(self::once())->method('deferActionUntil')
            ->with(7, 20, '2026-01-16 08:00:00', ['customer_id' => 42]);

        self::assertFalse($this->gate->allows(42, 7, 20, ['customer_id' => 42]));
    }
}
