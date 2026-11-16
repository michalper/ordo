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

    /**
     * The full "topic" param field definition, not just its options - byte-for-byte identical
     * across every send_* campaign action (SendEmail, SendPush, and - via
     * AbstractPhoneMessageAction::consentTopicField() - SendSms/SendRcs/SendWhatsApp), so this is
     * the one place its label/notice text lives.
     *
     * @return array{name: string, label: string, notice: string, options: array<string, string>}
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- fixed field definition, nothing to intercept
    public static function field(): array
    {
        return [
            'name' => 'topic',
            'label' => (string) __('Consent topic'),
            'options' => self::toMap(),
            'notice' => (string) __(
                'Which opt-out preference this send is checked against, beyond the'
                . ' customer\'s own per-channel consent. Defaults to Promotional if left blank.'
            ),
        ];
    }
}
