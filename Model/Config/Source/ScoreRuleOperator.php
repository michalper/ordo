<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The operator options for a score rule's condition, read by ScoreRuleEvaluator.
 */
class ScoreRuleOperator implements OptionSourceInterface
{
    public const EQUALS = 'equals';
    public const NOT_EQUALS = 'not_equals';
    public const CONTAINS = 'contains';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::EQUALS, 'label' => __('Equals')],
            ['value' => self::NOT_EQUALS, 'label' => __('Not Equals')],
            ['value' => self::CONTAINS, 'label' => __('Contains')],
        ];
    }
}
