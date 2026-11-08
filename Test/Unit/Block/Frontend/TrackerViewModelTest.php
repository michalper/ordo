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

    public function testIsPollEnabledReturnsFalseWhenAllThreeConcernsAreDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPopupEnabled')->willReturn(false);
        $config->method('isNotificationEnabled')->willReturn(false);
        $config->method('isNpsSurveyEnabled')->willReturn(false);

        self::assertFalse((new TrackerViewModel($config))->isPollEnabled());
    }

    public function testIsPollEnabledReturnsTrueWhenOnlyOneConcernIsEnabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPopupEnabled')->willReturn(false);
        $config->method('isNotificationEnabled')->willReturn(true);
        $config->method('isNpsSurveyEnabled')->willReturn(false);

        self::assertTrue((new TrackerViewModel($config))->isPollEnabled());
    }

    public function testGetPollIntervalSecondsReturnsTheFastestEnabledConcernsInterval(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPopupEnabled')->willReturn(true);
        $config->method('getPopupPollIntervalSeconds')->willReturn(15);
        $config->method('isNotificationEnabled')->willReturn(true);
        $config->method('getNotificationPollIntervalSeconds')->willReturn(5);
        $config->method('isNpsSurveyEnabled')->willReturn(true);
        $config->method('getNpsSurveyPollIntervalSeconds')->willReturn(25);

        self::assertSame(5, (new TrackerViewModel($config))->getPollIntervalSeconds());
    }

    public function testGetPollIntervalSecondsIgnoresDisabledConcernsOwnInterval(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPopupEnabled')->willReturn(true);
        $config->method('getPopupPollIntervalSeconds')->willReturn(15);
        $config->method('isNotificationEnabled')->willReturn(false);
        $config->method('getNotificationPollIntervalSeconds')->willReturn(1);
        $config->method('isNpsSurveyEnabled')->willReturn(false);

        self::assertSame(15, (new TrackerViewModel($config))->getPollIntervalSeconds());
    }

    public function testIsPushEnabledReflectsConfig(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPushEnabled')->willReturn(true);

        self::assertTrue((new TrackerViewModel($config))->isPushEnabled());
    }

    public function testGetVapidPublicKeyReflectsConfig(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getVapidPublicKey')->willReturn('public-key-b64url');

        self::assertSame('public-key-b64url', (new TrackerViewModel($config))->getVapidPublicKey());
    }

    public function testIsGtmBridgeEnabledReflectsConfig(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isGtmBridgeEnabled')->willReturn(true);

        self::assertTrue((new TrackerViewModel($config))->isGtmBridgeEnabled());
    }

    public function testIsExitIntentEnabledReflectsConfig(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isExitIntentEnabled')->willReturn(true);

        self::assertTrue((new TrackerViewModel($config))->isExitIntentEnabled());
    }

    public function testIsSocialProofEnabledReflectsConfig(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSocialProofEnabled')->willReturn(true);

        self::assertTrue((new TrackerViewModel($config))->isSocialProofEnabled());
    }
}
