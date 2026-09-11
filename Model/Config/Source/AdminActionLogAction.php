<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\AdminActionLog;

/**
 * The Admin Action Log grid's "Action" column filter - create/update, matching
 * AdminActionLog::ACTION_* exactly rather than a second hand-typed list.
 */
class AdminActionLogAction implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => AdminActionLog::ACTION_CREATE, 'label' => __('Create')],
            ['value' => AdminActionLog::ACTION_UPDATE, 'label' => __('Update')],
        ];
    }
}
