<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\OrderApproval;

/**
 * The Order Approvals grid's "Status" column filter - matches OrderApproval::STATUS_* exactly
 * rather than a second hand-typed list.
 */
class OrderApprovalStatus implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => OrderApproval::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => OrderApproval::STATUS_APPROVED, 'label' => __('Approved')],
            ['value' => OrderApproval::STATUS_REJECTED, 'label' => __('Rejected')],
        ];
    }
}
