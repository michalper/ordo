<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\Cron\CronRunLog;

/**
 * The Cron Run Log grid's "Level" column filter - summary/failure, matching
 * CronRunLog::LEVEL_SUMMARY/LEVEL_FAILURE exactly rather than a second hand-typed list.
 */
class CronRunLogLevel implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => CronRunLog::LEVEL_SUMMARY, 'label' => __('Summary')],
            ['value' => CronRunLog::LEVEL_FAILURE, 'label' => __('Failure')],
        ];
    }
}
