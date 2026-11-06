<?php

declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Ordo\Automation\Model\ConsentTopic;

/**
 * ConsentTopic value => label, plus a leading empty option meaning "use the channel default
 * (Promotional)" - a fixed 2-value enum, not DB-driven, so this is a plain static helper rather
 * than a Magento OptionSourceInterface Source class the way WhatsAppTemplateOptions/
 * EmailTemplateOptions etc. are. Shared by every send_* campaign action's "topic" field
 * (SendEmail, SendSms, SendWhatsApp, SendPush) via their own getParamFields().
 */
class ConsentTopicOptions
{
    /**
     * @return array<string, string>
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- fixed enum-to-label map, nothing to intercept
    public static function toMap(): array
    {
        return [
            '' => (string) __('(Default: Promotional)'),
            ConsentTopic::Promotional->value => (string) __('Promotional'),
            ConsentTopic::AccountUpdates->value => (string) __('Account & Order Updates'),
        ];
    }
}
