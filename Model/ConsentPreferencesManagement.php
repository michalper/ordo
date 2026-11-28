<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Api\ConsentPreferencesManagementInterface;
use Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface;
use Ordo\Automation\Api\Data\ConsentPreferencesInterface;
use Ordo\Automation\Setup\Patch\Data\AddCustomerMaxMessagesPerWindowAttribute;

class ConsentPreferencesManagement implements ConsentPreferencesManagementInterface
{
    public function __construct(
        private readonly ConsentManager $consentManager,
        private readonly UserContextInterface $userContext,
        private readonly ConsentPreferencesFactory $consentPreferencesFactory,
        private readonly ConsentChannelPreferenceFactory $consentChannelPreferenceFactory,
        private readonly UserContextCustomerIdResolver $userContextCustomerIdResolver,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    public function getMyPreferences(): ConsentPreferencesInterface
    {
        return $this->buildPreferences($this->userContextCustomerIdResolver->requireCustomerId($this->userContext));
    }

    public function setMyChannelConsent(string $channel, bool $consented): ConsentPreferencesInterface
    {
        $customerId = $this->userContextCustomerIdResolver->requireCustomerId($this->userContext);

        $channelEnum = ConsentChannel::tryFrom($channel);
        if ($channelEnum === null) {
            throw new LocalizedException(__('"%1" is not a valid consent channel.', $channel));
        }

        $this->consentManager->setConsent($customerId, $channelEnum, $consented, 'storefront');

        return $this->buildPreferences($customerId);
    }

    public function setMyTopicConsent(string $channel, string $topic, bool $consented): ConsentPreferencesInterface
    {
        $customerId = $this->userContextCustomerIdResolver->requireCustomerId($this->userContext);

        $channelEnum = ConsentChannel::tryFrom($channel);
        $topicEnum = ConsentTopic::tryFrom($topic);
        if ($channelEnum === null || $topicEnum === null) {
            throw new LocalizedException(__('"%1"/"%2" is not a valid consent channel/topic pair.', $channel, $topic));
        }

        $this->consentManager->setTopicConsent($customerId, $channelEnum, $topicEnum, $consented, 'storefront');

        return $this->buildPreferences($customerId);
    }

    public function setMyMaxMessagesPerWindow(?int $maxMessagesPerWindow): ConsentPreferencesInterface
    {
        $customerId = $this->userContextCustomerIdResolver->requireCustomerId($this->userContext);

        $customer = $this->customerRepository->getById($customerId);
        $customer->setCustomAttribute(
            AddCustomerMaxMessagesPerWindowAttribute::ATTRIBUTE_CODE,
            $maxMessagesPerWindow !== null && $maxMessagesPerWindow > 0 ? $maxMessagesPerWindow : null
        );
        $this->customerRepository->save($customer);

        return $this->buildPreferences($customerId);
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
            ->setAds($states[ConsentChannel::Ads->value] ?? true)
            ->setMaxMessagesPerWindow($this->getCustomerOwnMaxMessagesPerWindow($customerId));
    }

    private function getCustomerOwnMaxMessagesPerWindow(int $customerId): ?int
    {
        $customer = $this->customerRepository->getById($customerId);
        $attribute = $customer->getCustomAttribute(AddCustomerMaxMessagesPerWindowAttribute::ATTRIBUTE_CODE);

        return $attribute !== null ? (int) $attribute->getValue() : null;
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
