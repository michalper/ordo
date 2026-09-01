<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Frontend;

use Ordo\Automation\Block\Frontend\TrackerViewModel;
use Ordo\Automation\Helper\Config;
use PHPUnit\Framework\TestCase;

class TrackerViewModelTest extends TestCase
{
    public function testIsTrackingEnabledReflectsConfig(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isTrackingEnabled')->willReturn(true);

        self::assertTrue((new TrackerViewModel($config))->isTrackingEnabled());
    }

    public function testIsTrackingEnabledReturnsFalseWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isTrackingEnabled')->willReturn(false);

        self::assertFalse((new TrackerViewModel($config))->isTrackingEnabled());
    }

    public function testIsPopupEnabledReflectsConfig(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPopupEnabled')->willReturn(true);

        self::assertTrue((new TrackerViewModel($config))->isPopupEnabled());
    }

    public function testGetPopupPollIntervalSecondsReflectsConfig(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getPopupPollIntervalSeconds')->willReturn(20);

        self::assertSame(20, (new TrackerViewModel($config))->getPopupPollIntervalSeconds());
    }
}
