<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Gdpr;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\Gdpr\ConsentStates;

class Index extends Template
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly ConsentManager $consentManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getCustomerId(): int
    {
        return (int) $this->registry->registry('ordo_gdpr_customer_id');
    }

    public function getCustomerEmail(): string
    {
        return (string) $this->registry->registry('ordo_gdpr_customer_email');
    }

    public function isCustomerResolved(): bool
    {
        return $this->getCustomerId() > 0;
    }

    public function getConsentStates(): ConsentStates
    {
        $states = $this->consentManager->getConsentStates($this->getCustomerId());

        return new ConsentStates(
            email: $states[ConsentChannel::Email->value] ?? true,
            sms: $states[ConsentChannel::Sms->value] ?? true,
            push: $states[ConsentChannel::Push->value] ?? true,
            whatsapp: $states[ConsentChannel::WhatsApp->value] ?? true,
            ads: $states[ConsentChannel::Ads->value] ?? true,
        );
    }

    public function getSearchFormAction(): string
    {
        return $this->getUrl('*/*/index');
    }

    public function getSetConsentFormAction(): string
    {
        return $this->getUrl('*/*/setConsent');
    }

    /**
     * @return array<string, array<string, bool>> channel value => (topic value => consented),
     *     for every message-delivery channel (not Ads, which has no topic concept) - a topic this
     *     customer doesn't have an explicit row for falls back to that topic's own default (see
     *     ConsentTopic::defaultsToConsented()), not a single uniform default for every topic.
     */
    public function getTopicConsentStates(): array
    {
        $customerId = $this->getCustomerId();
        $states = [];

        $channels = [ConsentChannel::Email, ConsentChannel::Sms, ConsentChannel::Push, ConsentChannel::WhatsApp];
        foreach ($channels as $channel) {
            $channelStates = $this->consentManager->getTopicConsentStates($customerId, $channel);
            $states[$channel->value] = [
                ConsentTopic::Promotional->value =>
                    $channelStates[ConsentTopic::Promotional->value]
                    ?? ConsentTopic::Promotional->defaultsToConsented(),
                ConsentTopic::AccountUpdates->value =>
                    $channelStates[ConsentTopic::AccountUpdates->value]
                    ?? ConsentTopic::AccountUpdates->defaultsToConsented(),
            ];
        }

        return $states;
    }

    public function getSetTopicConsentFormAction(): string
    {
        return $this->getUrl('*/*/setTopicConsent');
    }

    public function getTopicLabel(string $topic): string
    {
        return match ($topic) {
            ConsentTopic::Promotional->value => (string) __('Promotional'),
            ConsentTopic::AccountUpdates->value => (string) __('Account & Order Updates'),
            default => $topic,
        };
    }

    public function getExportUrl(): string
    {
        return $this->getUrl('*/*/export', ['customer_id' => $this->getCustomerId()]);
    }

    public function getEraseFormAction(): string
    {
        return $this->getUrl('*/*/erase');
    }
}
