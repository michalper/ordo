<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Frontend;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ordo\Automation\Api\ConsentPreferencesManagementInterface;
use Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface;
use Ordo\Automation\Api\Data\ConsentPreferencesInterface;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentTopic;

/**
 * Feeds the storefront "Communication Preferences" account page - reads through
 * ConsentPreferencesManagementInterface::getMyPreferences() (same service the REST/GraphQL
 * transports use), not ConsentManager directly, so the channel-loop/topic-default logic stays
 * in exactly one place.
 */
class ConsentPreferencesViewModel implements ArgumentInterface
{
    /**
     * @var ConsentChannel[] every message-delivery channel - ads is rendered separately, see
     *     isAdsConsented().
     */
    private const array DELIVERY_CHANNELS = [
        ConsentChannel::Email,
        ConsentChannel::Sms,
        ConsentChannel::Push,
        ConsentChannel::WhatsApp,
        ConsentChannel::Rcs,
    ];

    private ?ConsentPreferencesInterface $preferences = null;

    public function __construct(
        private readonly ConsentPreferencesManagementInterface $consentPreferencesManagement
    ) {
    }

    private function getPreferences(): ?ConsentPreferencesInterface
    {
        if (!$this->preferences instanceof ConsentPreferencesInterface) {
            try {
                $this->preferences = $this->consentPreferencesManagement->getMyPreferences();
            } catch (NoSuchEntityException) {
                return null;
            }
        }

        return $this->preferences;
    }

    /**
     * @return ConsentChannel[]
     */
    public function getDeliveryChannels(): array
    {
        return self::DELIVERY_CHANNELS;
    }

    private function getChannelPreference(ConsentChannel $channel): ?ConsentChannelPreferenceInterface
    {
        $preferences = $this->getPreferences();
        if (!$preferences instanceof ConsentPreferencesInterface) {
            return null;
        }

        return match ($channel) {
            ConsentChannel::Email => $preferences->getEmail(),
            ConsentChannel::Sms => $preferences->getSms(),
            ConsentChannel::Push => $preferences->getPush(),
            ConsentChannel::WhatsApp => $preferences->getWhatsapp(),
            ConsentChannel::Rcs => $preferences->getRcs(),
            ConsentChannel::Ads => null,
        };
    }

    public function isChannelConsented(ConsentChannel $channel): bool
    {
        return $this->getChannelPreference($channel)?->getConsented() ?? true;
    }

    public function isTopicConsented(ConsentChannel $channel, ConsentTopic $topic): bool
    {
        $channelPreference = $this->getChannelPreference($channel);
        if (!$channelPreference instanceof ConsentChannelPreferenceInterface) {
            return $topic->defaultsToConsented();
        }

        return $topic === ConsentTopic::Promotional
            ? $channelPreference->getPromotional()
            : $channelPreference->getAccountUpdates();
    }

    public function isAdsConsented(): bool
    {
        return $this->getPreferences()?->getAds() ?? true;
    }
}
