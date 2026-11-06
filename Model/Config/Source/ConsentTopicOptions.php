<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\ConsentTopic;

/**
 * The classic dynamicRows editor's `topic` field on send_email/send_sms (ordo_campaign_form.xml
 * - send_whatsapp/send_push have no classic-editor fields at all, same as their other dedicated
 * fields) - the Flow canvas's own equivalent field is Block\Adminhtml\Campaign\Edit\
 * Flow::getConsentTopicOptions(), inlined there rather than injecting this class, since that
 * block already has a precedent (use_optimal_send_time) for a fixed, non-DB-driven option list.
 */
class ConsentTopicOptions implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '', 'label' => __('(Default: Promotional)')],
            ['value' => ConsentTopic::Promotional->value, 'label' => __('Promotional')],
            ['value' => ConsentTopic::AccountUpdates->value, 'label' => __('Account & Order Updates')],
        ];
    }
}
