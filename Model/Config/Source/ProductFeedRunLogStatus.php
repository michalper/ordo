<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\ProductFeed\ProductFeedRunLog;

/**
 * The Product Feed Health grid's "Status" column filter - success/error, matching
 * ProductFeedRunLog::STATUS_SUCCESS/STATUS_ERROR exactly rather than a second hand-typed list.
 */
class ProductFeedRunLogStatus implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => ProductFeedRunLog::STATUS_SUCCESS, 'label' => __('Success')],
            ['value' => ProductFeedRunLog::STATUS_ERROR, 'label' => __('Error')],
        ];
    }
}
