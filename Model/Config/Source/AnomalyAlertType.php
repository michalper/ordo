<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\Anomaly\AnomalyAlert;

/**
 * The Anomaly Alerts grid's "Type" column filter, matching AnomalyAlert::TYPE_* exactly rather
 * than a second hand-typed list — same pattern as Model\Config\Source\CronRunLogLevel.
 */
class AnomalyAlertType implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => AnomalyAlert::TYPE_CAMPAIGN_REVENUE_DROP, 'label' => __('Campaign Revenue Drop')],
            ['value' => AnomalyAlert::TYPE_CAMPAIGN_CONVERSION_OUTLIER, 'label' => __('Campaign Conversion Outlier')],
        ];
    }
}
