<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\Referral;

/**
 * The Referrals grid's "Status" column filter - matches Referral::STATUS_* exactly rather than a
 * second hand-typed list.
 */
class ReferralStatus implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Referral::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => Referral::STATUS_CONVERTED, 'label' => __('Converted')],
        ];
    }
}
