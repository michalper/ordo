<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\ConsentPreferencesManagementInterface;
use Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface;
use Ordo\Automation\Api\Data\ConsentPreferencesInterface;

class ConsentPreferencesManagement implements ConsentPreferencesManagementInterface
{
    public function __construct(
        private readonly ConsentManager $consentManager,
        private readonly UserContextInterface $userContext,
        private readonly ConsentPreferencesFactory $consentPreferencesFactory,
        private readonly ConsentChannelPreferenceFactory $consentChannelPreferenceFactory
    ) {
    }

    public function getMyPreferences(): ConsentPreferencesInterface
    {
        return $this->buildPreferences($this->resolveCustomerId());
    }

    public function setMyChannelConsent(string $channel, bool $consented): ConsentPreferencesInterface
    {
        $customerId = $this->resolveCustomerId();

        $channelEnum = ConsentChannel::tryFrom($channel);
        if ($channelEnum === null) {
            throw new LocalizedException(__('"%1" is not a valid consent channel.', $channel));
        }

        $this->consentManager->setConsent($customerId, $channelEnum, $consented, 'storefront');

        return $this->buildPreferences($customerId);
    }

    public function setMyTopicConsent(string $channel, string $topic, bool $consented): ConsentPreferencesInterface
    {
        $customerId = $this->resolveCustomerId();

        $channelEnum = ConsentChannel::tryFrom($channel);
        $topicEnum = ConsentTopic::tryFrom($topic);
        if ($channelEnum === null || $topicEnum === null) {
            throw new LocalizedException(__('"%1"/"%2" is not a valid consent channel/topic pair.', $channel, $topic));
        }

        $this->consentManager->setTopicConsent($customerId, $channelEnum, $topicEnum, $consented, 'storefront');

        return $this->buildPreferences($customerId);
    }

    /**
     * Same "0 means guest, not a real id" caveat getUserId() has everywhere else in this module
     * (see Model\GraphQl\CustomerIdResolver's own docblock) doesn't apply to the REST/frontend
     * path this implementation is written against - Magento's REST dispatch only ever populates
     * UserContextInterface with a real customer id or null here, never 0. GraphQL callers guard
     * against the 0-for-guest case of their own context before ever reaching this class - see
     * Model\GraphQl\Resolver\ConsentPreferences's own docblock.
     */
    private function resolveCustomerId(): int
    {
        $customerId = $this->userContext->getUserId();
        if ($customerId === null) {
            throw new NoSuchEntityException(__('No authenticated customer for this request.'));
        }

        return (int) $customerId;
    }

    private function buildPreferences(int $customerId): ConsentPreferencesInterface
    {
        $states = $this->consentManager->getConsentStates($customerId);

        return $this->consentPreferencesFactory->create()
            ->setEmail($this->buildChannelPreference($customerId, $states, ConsentChannel::Email))
            ->setSms($this->buildChannelPreference($customerId, $states, ConsentChannel::Sms))
            ->setPush($this->buildChannelPreference($customerId, $states, ConsentChannel::Push))
            ->setWhatsapp($this->buildChannelPreference($customerId, $states, ConsentChannel::WhatsApp))
            ->setRcs($this->buildChannelPreference($customerId, $states, ConsentChannel::Rcs))
            ->setAds($states[ConsentChannel::Ads->value] ?? true);
    }

    /**
     * @param array<string, bool> $channelStates Model\ConsentManager::getConsentStates()'s own
     *     return shape - fetched once by the caller and reused across every channel, instead of
     *     re-querying it per channel.
     */
    private function buildChannelPreference(
        int $customerId,
        array $channelStates,
        ConsentChannel $channel
    ): ConsentChannelPreferenceInterface {
        $topicStates = $this->consentManager->getTopicConsentStates($customerId, $channel);

        return $this->consentChannelPreferenceFactory->create()
            ->setConsented($channelStates[$channel->value] ?? true)
            ->setPromotional(
                $topicStates[ConsentTopic::Promotional->value] ?? ConsentTopic::Promotional->defaultsToConsented()
            )
            ->setAccountUpdates(
                $topicStates[ConsentTopic::AccountUpdates->value] ?? ConsentTopic::AccountUpdates->defaultsToConsented()
            );
    }
}
