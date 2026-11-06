<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Anomaly\AnomalyAlert;
use Ordo\Automation\Model\CampaignOutcomeLogger;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderLogStore;

/**
 * Flags notable deviations in campaign performance — see docs/adr/0025 for why this is scoped to
 * per-campaign anomalies (not per-channel, which this schema can't honestly support) and why the
 * two checks below are the ones chosen.
 */
class DetectAnomalies
{
    private const string ALERT_LOG_TABLE = 'ordo_anomaly_alert';

    public function __construct(
        private readonly Config $config,
        private readonly CampaignOutcomeLogger $campaignOutcomeLogger,
        private readonly ReminderLogStore $reminderLogStore,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isAnomalyAlertsEnabled()) {
            return;
        }

        $lookbackDays = $this->config->getAnomalyLookbackDays();
        $minSampleSize = $this->config->getAnomalyMinSampleSize();

        $now = new \DateTimeImmutable();
        $currentFrom = $now->modify("-{$lookbackDays} days");
        $baselineFrom = $now->modify('-' . ($lookbackDays * 2) . ' days');

        $created = 0;

        try {
            $currentStats = $this->campaignOutcomeLogger->getStatsForWindow($currentFrom, $now);
            $baselineStats = $this->campaignOutcomeLogger->getStatsForWindow($baselineFrom, $currentFrom);

            $created += $this->detectRevenueDrops($currentStats, $baselineStats, $minSampleSize, $lookbackDays);
            $created += $this->detectConversionOutliers($currentStats, $minSampleSize, $lookbackDays);
        } catch (\Throwable $e) {
            $this->cronRunLogger->logFailure('detect anomalies', $e);
            return;
        }

        $this->cronRunLogger->logSummary(sprintf('detected %d new anomaly alert(s)', $created));
    }

    /**
     * @param array<int, array{campaign_id: int, sent: int, converted: int, conversion_rate: float, revenue: float}> $currentStats
     * @param array<int, array{campaign_id: int, sent: int, converted: int, conversion_rate: float, revenue: float}> $baselineStats
     */
    private function detectRevenueDrops(array $currentStats, array $baselineStats, int $minSampleSize, int $lookbackDays): int
    {
        $thresholdPercent = $this->config->getAnomalyRevenueDropThresholdPercent();
        $created = 0;

        foreach ($baselineStats as $campaignId => $baseline) {
            if ($baseline['sent'] < $minSampleSize || $baseline['revenue'] <= 0.0) {
                continue;
            }

            $current = $currentStats[$campaignId] ?? ['revenue' => 0.0];
            $dropPercent = ($baseline['revenue'] - $current['revenue']) / $baseline['revenue'] * 100;

            if ($dropPercent < $thresholdPercent) {
                continue;
            }

            $message = sprintf(
                'Campaign #%d revenue dropped %.1f%% (%.2f -> %.2f over %d days)',
                $campaignId,
                $dropPercent,
                $baseline['revenue'],
                $current['revenue'],
                $lookbackDays
            );

            if ($this->claimAlert(
                AnomalyAlert::TYPE_CAMPAIGN_REVENUE_DROP,
                $campaignId,
                $message,
                $current['revenue'],
                $baseline['revenue'],
                $dropPercent
            )) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param array<int, array{campaign_id: int, sent: int, converted: int, conversion_rate: float, revenue: float}> $currentStats
     */
    private function detectConversionOutliers(array $currentStats, int $minSampleSize, int $lookbackDays): int
    {
        $thresholdPercent = $this->config->getAnomalyConversionOutlierThresholdPercent();

        $qualifying = array_filter($currentStats, static fn (array $stat): bool => $stat['sent'] >= $minSampleSize);

        // Needs at least 2 qualifying campaigns for "deviates from its peers" to mean anything -
        // a single qualifying campaign has no peer group to be an outlier against.
        if (count($qualifying) < 2) {
            return 0;
        }

        $peerAverage = array_sum(array_column($qualifying, 'conversion_rate')) / count($qualifying);
        if ($peerAverage <= 0.0) {
            return 0;
        }

        $created = 0;

        foreach ($qualifying as $campaignId => $stat) {
            $deviationPercent = ($stat['conversion_rate'] - $peerAverage) / $peerAverage * 100;

            if (abs($deviationPercent) < $thresholdPercent) {
                continue;
            }

            $direction = $deviationPercent > 0 ? 'outperforming' : 'underperforming';
            $message = sprintf(
                'Campaign #%d is %s its peers: %.1f%% conversion rate vs. %.1f%% average (%.1f%%'
                . ' deviation) over %d days',
                $campaignId,
                $direction,
                $stat['conversion_rate'],
                $peerAverage,
                $deviationPercent,
                $lookbackDays
            );

            if ($this->claimAlert(
                AnomalyAlert::TYPE_CAMPAIGN_CONVERSION_OUTLIER,
                $campaignId,
                $message,
                $stat['conversion_rate'],
                $peerAverage,
                $deviationPercent
            )) {
                $created++;
            }
        }

        return $created;
    }

    private function claimAlert(
        string $alertType,
        int $campaignId,
        string $message,
        float $currentValue,
        float $baselineValue,
        float $deviationPercent
    ): bool {
        $cooldownDays = $this->config->getAnomalyAlertCooldownDays();
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$cooldownDays} days"));

        return $this->reminderLogStore->claim(
            self::ALERT_LOG_TABLE,
            [
                'alert_type = ?' => $alertType,
                'campaign_id = ?' => $campaignId,
                'detected_at >= ?' => $cutoff,
            ],
            [
                'alert_type' => $alertType,
                'campaign_id' => $campaignId,
                'message' => $message,
                'current_value' => $currentValue,
                'baseline_value' => $baselineValue,
                'deviation_percent' => $deviationPercent,
                'detected_at' => date('Y-m-d H:i:s'),
            ]
        );
    }
}
