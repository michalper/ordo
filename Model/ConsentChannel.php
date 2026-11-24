<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

/**
 * Every channel ConsentManager tracks consent for. A backed enum instead of the plain string
 * constants this used to be (ConsentManager::CHANNEL_*) — a typo'd channel string used to compile
 * fine and silently always report "consented" (ConsentManager::hasConsent()'s own default-consented
 * behavior for a channel with no row), which is exactly how the admin GDPR screen's own
 * SetConsent controller shipped without WhatsApp in its channel allow-list for a while: a second,
 * hand-maintained list of the same strings had simply gone stale. ConsentChannel::tryFrom()
 * structurally can't go stale the same way - it validates against the one place every channel is
 * actually defined.
 */
enum ConsentChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Push = 'push';
    case WhatsApp = 'whatsapp';
    case Rcs = 'rcs';

    /**
     * Not a message-delivery channel like the others above - governs whether a customer's
     * (hashed) email may be uploaded to a third-party ad platform for audience matching
     * (Cron\SyncAdAudiences). Kept as its own channel rather than reusing Email, since a customer
     * could reasonably want transactional/marketing email but not want their data shared with
     * Google/Meta for ad targeting, or vice versa.
     */
    case Ads = 'ads';

    /**
     * Every message-delivery channel (everything except Ads, which has no message-delivery
     * concept of its own - see its own case docblock above). Found during a full-repo /simplify
     * sweep hand-copied in 3 places (Controller\Adminhtml\Gdpr\Export, Block\Adminhtml\Gdpr\Index,
     * Block\Frontend\ConsentPreferencesViewModel) - exactly the stale-second-list failure mode
     * this enum's own class docblock exists to prevent, just one level up.
     *
     * @return self[]
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a plain cases() filter, nothing to intercept
    public static function deliveryChannels(): array
    {
        return array_filter(self::cases(), static fn (self $channel): bool => $channel !== self::Ads);
    }
}
