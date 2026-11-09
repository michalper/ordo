<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\DetectAnomalies;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Anomaly\AnomalyAlert;
use Ordo\Automation\Model\CampaignOutcomeLogger;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DetectAnomaliesTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private function makeConfig(
        bool $enabled = true,
        int $lookbackDays = 7,
        int $minSampleSize = 20,
        int $revenueDropThresholdPercent = 30,
        int $conversionOutlierThresholdPercent = 50,
        int $cooldownDays = 7
    ): Config {
        $config = $this->createStub(Config::class);
        $config->method('isAnomalyAlertsEnabled')->willReturn($enabled);
        $config->method('getAnomalyLookbackDays')->willReturn($lookbackDays);
        $config->method('getAnomalyMinSampleSize')->willReturn($minSampleSize);
        $config->method('getAnomalyRevenueDropThresholdPercent')->willReturn($revenueDropThresholdPercent);
        $config->method('getAnomalyConversionOutlierThresholdPercent')->willReturn($conversionOutlierThresholdPercent);
        $config->method('getAnomalyAlertCooldownDays')->willReturn($cooldownDays);

        return $config;
    }

    public function testDoesNothingWhenDisabled(): void
    {
        $campaignOutcomeLogger = $this->createMock(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->expects(self::never())->method('getStatsForWindow');

        $reminderLogStore = $this->createMock(ReminderLogStore::class);
        $reminderLogStore->expects(self::never())->method('claim');

        $cron = new DetectAnomalies(
            $this->makeConfig(enabled: false),
            $campaignOutcomeLogger,
            $reminderLogStore,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        );

        $cron->execute();
    }

    public function testFlagsARevenueDropAboveThreshold(): void
    {
        $campaignOutcomeLogger = $this->createStub(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->method('getStatsForWindow')->willReturnOnConsecutiveCalls(
            [5 => ['campaign_id' => 5, 'sent' => 25, 'converted' => 2, 'conversion_rate' => 8.0, 'revenue' => 60.0]],
            [5 => ['campaign_id' => 5, 'sent' => 25, 'converted' => 5, 'conversion_rate' => 20.0, 'revenue' => 100.0]]
        );

        $reminderLogStore = $this->createMock(ReminderLogStore::class);
        $reminderLogStore->expects(self::once())->method('claim')->with(
            'ordo_anomaly_alert',
            self::callback(fn (array $c): bool => $c['alert_type = ?'] === AnomalyAlert::TYPE_CAMPAIGN_REVENUE_DROP
                && $c['campaign_id = ?'] === 5),
            self::callback(fn (array $d): bool => $d['alert_type'] === AnomalyAlert::TYPE_CAMPAIGN_REVENUE_DROP
                && $d['campaign_id'] === 5
                && $d['current_value'] === 60.0
                && $d['baseline_value'] === 100.0
                && abs($d['deviation_percent'] - 40.0) < 0.01
                && str_contains($d['message'], 'Campaign #5 revenue dropped'))
        )->willReturn(true);

        $cron = new DetectAnomalies(
            $this->makeConfig(),
            $campaignOutcomeLogger,
            $reminderLogStore,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        );

        $cron->execute();
    }

    public function testDoesNotFlagARevenueDropBelowThreshold(): void
    {
        $campaignOutcomeLogger = $this->createStub(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->method('getStatsForWindow')->willReturnOnConsecutiveCalls(
            [5 => ['campaign_id' => 5, 'sent' => 25, 'converted' => 4, 'conversion_rate' => 16.0, 'revenue' => 90.0]],
            [5 => ['campaign_id' => 5, 'sent' => 25, 'converted' => 5, 'conversion_rate' => 20.0, 'revenue' => 100.0]]
        );

        $reminderLogStore = $this->createMock(ReminderLogStore::class);
        $reminderLogStore->expects(self::never())->method('claim');

        $cron = new DetectAnomalies(
            $this->makeConfig(),
            $campaignOutcomeLogger,
            $reminderLogStore,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        );

        $cron->execute();
    }

    public function testSkipsACampaignBelowMinimumBaselineSampleSize(): void
    {
        $campaignOutcomeLogger = $this->createStub(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->method('getStatsForWindow')->willReturnOnConsecutiveCalls(
            [5 => ['campaign_id' => 5, 'sent' => 2, 'converted' => 0, 'conversion_rate' => 0.0, 'revenue' => 0.0]],
            [5 => ['campaign_id' => 5, 'sent' => 5, 'converted' => 5, 'conversion_rate' => 100.0, 'revenue' => 100.0]]
        );

        $reminderLogStore = $this->createMock(ReminderLogStore::class);
        $reminderLogStore->expects(self::never())->method('claim');

        $cron = new DetectAnomalies(
            $this->makeConfig(minSampleSize: 20),
            $campaignOutcomeLogger,
            $reminderLogStore,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        );

        $cron->execute();
    }

    public function testSkipsACampaignWithNoBaselineRevenue(): void
    {
        $campaignOutcomeLogger = $this->createStub(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->method('getStatsForWindow')->willReturnOnConsecutiveCalls(
            [5 => ['campaign_id' => 5, 'sent' => 25, 'converted' => 0, 'conversion_rate' => 0.0, 'revenue' => 0.0]],
            [5 => ['campaign_id' => 5, 'sent' => 25, 'converted' => 0, 'conversion_rate' => 0.0, 'revenue' => 0.0]]
        );

        $reminderLogStore = $this->createMock(ReminderLogStore::class);
        $reminderLogStore->expects(self::never())->method('claim');

        $cron = new DetectAnomalies(
            $this->makeConfig(),
            $campaignOutcomeLogger,
            $reminderLogStore,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        );

        $cron->execute();
    }

    public function testFlagsAnOutperformingCampaignAgainstItsPeersAverage(): void
    {
        // With only 2 campaigns the average sits exactly between them, so both would always
        // deviate by the same magnitude (opposite sign) - a 3rd, near-average campaign is needed
        // so only campaign #5 actually crosses the threshold.
        $current = [
            5 => ['campaign_id' => 5, 'sent' => 25, 'converted' => 10, 'conversion_rate' => 40.0, 'revenue' => 100.0],
            6 => ['campaign_id' => 6, 'sent' => 25, 'converted' => 5, 'conversion_rate' => 20.0, 'revenue' => 100.0],
            7 => ['campaign_id' => 7, 'sent' => 25, 'converted' => 4, 'conversion_rate' => 18.0, 'revenue' => 100.0],
        ];

        $campaignOutcomeLogger = $this->createStub(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->method('getStatsForWindow')->willReturnOnConsecutiveCalls($current, []);

        $reminderLogStore = $this->createMock(ReminderLogStore::class);
        $reminderLogStore->expects(self::once())->method('claim')->with(
            'ordo_anomaly_alert',
            self::callback(fn (array $c): bool => $c['alert_type = ?'] === AnomalyAlert::TYPE_CAMPAIGN_CONVERSION_OUTLIER
                && $c['campaign_id = ?'] === 5),
            self::callback(fn (array $d): bool => $d['campaign_id'] === 5
                && $d['current_value'] === 40.0
                && str_contains($d['message'], 'outperforming'))
        )->willReturn(true);

        $cron = new DetectAnomalies(
            $this->makeConfig(),
            $campaignOutcomeLogger,
            $reminderLogStore,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        );

        $cron->execute();
    }

    public function testFlagsAnUnderperformingCampaignAgainstItsPeersAverage(): void
    {
        $current = [
            5 => ['campaign_id' => 5, 'sent' => 25, 'converted' => 1, 'conversion_rate' => 4.0, 'revenue' => 100.0],
            6 => ['campaign_id' => 6, 'sent' => 25, 'converted' => 5, 'conversion_rate' => 20.0, 'revenue' => 100.0],
            7 => ['campaign_id' => 7, 'sent' => 25, 'converted' => 6, 'conversion_rate' => 22.0, 'revenue' => 100.0],
        ];

        $campaignOutcomeLogger = $this->createStub(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->method('getStatsForWindow')->willReturnOnConsecutiveCalls($current, []);

        $reminderLogStore = $this->createMock(ReminderLogStore::class);
        $reminderLogStore->expects(self::once())->method('claim')->with(
            'ordo_anomaly_alert',
            self::callback(fn (array $c): bool => $c['alert_type = ?'] === AnomalyAlert::TYPE_CAMPAIGN_CONVERSION_OUTLIER
                && $c['campaign_id = ?'] === 5),
            self::callback(fn (array $d): bool => $d['campaign_id'] === 5 && str_contains($d['message'], 'underperforming'))
        )->willReturn(true);

        $cron = new DetectAnomalies(
            $this->makeConfig(),
            $campaignOutcomeLogger,
            $reminderLogStore,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        );

        $cron->execute();
    }

    public function testDoesNotRunTheOutlierCheckWithFewerThanTwoQualifyingCampaigns(): void
    {
        $current = [
            5 => ['campaign_id' => 5, 'sent' => 25, 'converted' => 10, 'conversion_rate' => 40.0, 'revenue' => 100.0],
        ];

        $campaignOutcomeLogger = $this->createStub(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->method('getStatsForWindow')->willReturnOnConsecutiveCalls($current, []);

        $reminderLogStore = $this->createMock(ReminderLogStore::class);
        $reminderLogStore->expects(self::never())->method('claim');

        $cron = new DetectAnomalies(
            $this->makeConfig(),
            $campaignOutcomeLogger,
            $reminderLogStore,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        );

        $cron->execute();
    }

    public function testDoesNotRunTheOutlierCheckWhenThePeerAverageConversionRateIsZero(): void
    {
        // Two qualifying campaigns (enough for a peer group), but neither has converted anyone
        // yet - a 0% peer average has no meaningful "deviates from its peers" direction, so the
        // outlier check must bail out rather than divide by zero.
        $current = [
            5 => ['campaign_id' => 5, 'sent' => 25, 'converted' => 0, 'conversion_rate' => 0.0, 'revenue' => 0.0],
            6 => ['campaign_id' => 6, 'sent' => 25, 'converted' => 0, 'conversion_rate' => 0.0, 'revenue' => 0.0],
        ];

        $campaignOutcomeLogger = $this->createStub(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->method('getStatsForWindow')->willReturnOnConsecutiveCalls($current, []);

        $reminderLogStore = $this->createMock(ReminderLogStore::class);
        $reminderLogStore->expects(self::never())->method('claim');

        $cron = new DetectAnomalies(
            $this->makeConfig(),
            $campaignOutcomeLogger,
            $reminderLogStore,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        );

        $cron->execute();
    }

    public function testACooldownRejectedClaimIsNotCountedInTheSummary(): void
    {
        $campaignOutcomeLogger = $this->createStub(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->method('getStatsForWindow')->willReturnOnConsecutiveCalls(
            [5 => ['campaign_id' => 5, 'sent' => 25, 'converted' => 2, 'conversion_rate' => 8.0, 'revenue' => 60.0]],
            [5 => ['campaign_id' => 5, 'sent' => 25, 'converted' => 5, 'conversion_rate' => 20.0, 'revenue' => 100.0]]
        );

        $reminderLogStore = $this->createStub(ReminderLogStore::class);
        $reminderLogStore->method('claim')->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('detected 0 new anomaly alert'));

        $cron = new DetectAnomalies(
            $this->makeConfig(),
            $campaignOutcomeLogger,
            $reminderLogStore,
            $this->makeCronRunLogger($logger)
        );

        $cron->execute();
    }

    public function testAFailureDuringDetectionIsLoggedAndDoesNotCrash(): void
    {
        $campaignOutcomeLogger = $this->createStub(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->method('getStatsForWindow')->willThrowException(new \RuntimeException('db gone'));

        $reminderLogStore = $this->createMock(ReminderLogStore::class);
        $reminderLogStore->expects(self::never())->method('claim');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('db gone'));
        $logger->expects(self::never())->method('info');

        $cron = new DetectAnomalies(
            $this->makeConfig(),
            $campaignOutcomeLogger,
            $reminderLogStore,
            $this->makeCronRunLogger($logger)
        );

        $cron->execute();
    }
}
