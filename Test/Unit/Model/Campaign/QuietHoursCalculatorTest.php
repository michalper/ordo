<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Model\Campaign\QuietHoursCalculator;
use PHPUnit\Framework\TestCase;

class QuietHoursCalculatorTest extends TestCase
{
    private QuietHoursCalculator $calculator;
    private \DateTimeZone $utc;

    protected function setUp(): void
    {
        $this->calculator = new QuietHoursCalculator();
        $this->utc = new \DateTimeZone('UTC');
    }

    private function utcInstant(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-15 ' . $time . ':00', $this->utc);
    }

    public function testSameDayWindowIsWithinQuietHoursInsideRange(): void
    {
        self::assertTrue($this->calculator->isWithinQuietHours($this->utc, 1, 5, $this->utcInstant('03:00')));
    }

    public function testSameDayWindowIsNotWithinQuietHoursOutsideRange(): void
    {
        self::assertFalse($this->calculator->isWithinQuietHours($this->utc, 1, 5, $this->utcInstant('12:00')));
    }

    public function testSameDayWindowStartHourItselfIsWithinQuietHours(): void
    {
        self::assertTrue($this->calculator->isWithinQuietHours($this->utc, 1, 5, $this->utcInstant('01:00')));
    }

    public function testSameDayWindowEndHourItselfIsNotWithinQuietHours(): void
    {
        self::assertFalse($this->calculator->isWithinQuietHours($this->utc, 1, 5, $this->utcInstant('05:00')));
    }

    public function testOvernightWindowIsWithinQuietHoursBeforeMidnight(): void
    {
        self::assertTrue($this->calculator->isWithinQuietHours($this->utc, 21, 8, $this->utcInstant('23:00')));
    }

    public function testOvernightWindowIsWithinQuietHoursAfterMidnight(): void
    {
        self::assertTrue($this->calculator->isWithinQuietHours($this->utc, 21, 8, $this->utcInstant('03:00')));
    }

    public function testOvernightWindowIsNotWithinQuietHoursDuringTheDay(): void
    {
        self::assertFalse($this->calculator->isWithinQuietHours($this->utc, 21, 8, $this->utcInstant('14:00')));
    }

    public function testOvernightWindowStartHourItselfIsWithinQuietHours(): void
    {
        self::assertTrue($this->calculator->isWithinQuietHours($this->utc, 21, 8, $this->utcInstant('21:00')));
    }

    public function testOvernightWindowEndHourItselfIsNotWithinQuietHours(): void
    {
        self::assertFalse($this->calculator->isWithinQuietHours($this->utc, 21, 8, $this->utcInstant('08:00')));
    }

    public function testEqualStartAndEndHourIsNeverWithinQuietHours(): void
    {
        self::assertFalse($this->calculator->isWithinQuietHours($this->utc, 9, 9, $this->utcInstant('09:00')));
    }

    public function testIsWithinQuietHoursConvertsToTheGivenTimezone(): void
    {
        // 23:00 UTC = 00:00 in Europe/Warsaw (UTC+1 in January) - within a 21-8 window there,
        // even though 23:00 in UTC itself would also be within it (different reason: proves the
        // timezone conversion actually happens, not just that the UTC hour happens to match).
        $warsaw = new \DateTimeZone('Europe/Warsaw');
        self::assertTrue($this->calculator->isWithinQuietHours($warsaw, 21, 8, $this->utcInstant('23:00')));

        // 20:30 UTC = 21:30 in Europe/Warsaw - within the window in Warsaw, but NOT yet within
        // it in UTC (20 < 21) - proves the conversion, not a coincidence.
        self::assertFalse($this->calculator->isWithinQuietHours($this->utc, 21, 8, $this->utcInstant('20:30')));
        self::assertTrue($this->calculator->isWithinQuietHours($warsaw, 21, 8, $this->utcInstant('20:30')));
    }

    public function testNextQuietHoursEndUtcSameDayWhenEndHourHasNotPassedYet(): void
    {
        $result = $this->calculator->nextQuietHoursEndUtc($this->utc, 8, $this->utcInstant('03:00'));

        self::assertSame('2026-01-15 08:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testNextQuietHoursEndUtcRollsOverToTheNextDayWhenEndHourAlreadyPassed(): void
    {
        $result = $this->calculator->nextQuietHoursEndUtc($this->utc, 8, $this->utcInstant('23:00'));

        self::assertSame('2026-01-16 08:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testNextQuietHoursEndUtcConvertsBackToUtcForANonUtcTimezone(): void
    {
        $warsaw = new \DateTimeZone('Europe/Warsaw');

        // 2026-01-15 23:00 UTC = 2026-01-16 00:00 in Warsaw (Jan, UTC+1) - the next 08:00 Warsaw
        // is later the same Warsaw calendar day (Jan 16), which converts back to 07:00 UTC.
        $result = $this->calculator->nextQuietHoursEndUtc($warsaw, 8, $this->utcInstant('23:00'));

        self::assertSame('2026-01-16 07:00:00', $result->format('Y-m-d H:i:s'));
    }
}
