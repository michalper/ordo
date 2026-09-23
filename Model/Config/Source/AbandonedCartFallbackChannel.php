<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Which channel Cron\SendAbandonedCartFallbackReminders falls back to when the fixed abandoned-
 * cart reminder email goes unopened - a merchant picks one, not both, since trying SMS then
 * WhatsApp (or vice versa) would risk double-messaging a customer who simply hasn't checked
 * their inbox yet rather than genuinely missed the reminder.
 */
class AbandonedCartFallbackChannel implements OptionSourceInterface
{
    public const string SMS = 'sms';
    public const string WHATSAPP = 'whatsapp';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::SMS, 'label' => __('SMS')],
            ['value' => self::WHATSAPP, 'label' => __('WhatsApp')],
        ];
    }
}
