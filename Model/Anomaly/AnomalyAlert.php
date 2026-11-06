<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Anomaly;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\Anomaly\AnomalyAlert as AnomalyAlertResource;

/**
 * One persisted row per deviation Cron\DetectAnomalies flags — see docs/adr/0025. Plain data
 * holder, same shape as Model\Cron\CronRunLog; DetectAnomalies is the only thing that writes
 * these, via Model\Cron\ReminderLogStore::claim() rather than this model's own save() directly
 * (the claim's atomic check-then-insert is what prevents the same alert_type+campaign_id pair
 * from re-firing inside its cooldown window).
 */
class AnomalyAlert extends AbstractModel
{
    public const string TYPE_CAMPAIGN_REVENUE_DROP = 'campaign_revenue_drop';
    public const string TYPE_CAMPAIGN_CONVERSION_OUTLIER = 'campaign_conversion_outlier';

    protected function _construct(): void
    {
        $this->_init(AnomalyAlertResource::class);
    }

    public function getAlertType(): string
    {
        return (string) $this->getData('alert_type');
    }

    public function getCampaignId(): int
    {
        return (int) $this->getData('campaign_id');
    }

    public function getMessage(): string
    {
        return (string) $this->getData('message');
    }

    public function getCurrentValue(): float
    {
        return (float) $this->getData('current_value');
    }

    public function getBaselineValue(): float
    {
        return (float) $this->getData('baseline_value');
    }

    public function getDeviationPercent(): float
    {
        return (float) $this->getData('deviation_percent');
    }

    public function getDetectedAt(): ?string
    {
        $value = $this->getData('detected_at');
        return $value === null ? null : (string) $value;
    }
}
