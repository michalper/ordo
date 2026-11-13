<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Which metric Model\Campaign\SplitWinnerCalculator compares a split action's variants by.
 * Default stays CTR (the original, backward-compatible behavior for any store that already has
 * this feature enabled) - conversion rate/revenue are both read from the existing
 * Model\CampaignOutcomeLogger::getStats() data (sent-vs-converted-vs-revenue per variant), not a
 * new ledger.
 */
class AbTestDecisionMetric implements OptionSourceInterface
{
    public const string CTR = 'ctr';
    public const string CONVERSION_RATE = 'conversion_rate';
    public const string REVENUE = 'revenue';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::CTR, 'label' => __('Click-through rate')],
            ['value' => self::CONVERSION_RATE, 'label' => __('Conversion rate')],
            ['value' => self::REVENUE, 'label' => __('Revenue')],
        ];
    }
}
