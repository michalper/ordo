<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\ConsentTopicOptions;
use Ordo\Automation\Model\Campaign\FrequencyCapGate;
use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\Campaign\QuietHoursGate;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\Rcs\RcsSenderInterface;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Ordo\Automation\Model\Sms\PhoneNumberResolver;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Params: {"message": "RCS text", "topic": "promotional"|"account_updates" (optional, defaults to
 * "promotional")}. Byte-for-byte the same shape as Model\Campaign\Action\SendSms — same
 * ordo_sms_phone customer attribute/PhoneNumberResolver (RCS, like SMS, is addressed by phone
 * number via carrier routing), same ConsentManager/QuietHoursGate/FrequencyCapGate/retry-queue
 * plumbing - only the channel string and the sender interface differ. No "{{recommended_products_
 * text}}" substitution duplication avoided by literally reusing the same token handling SendSms
 * already has - RCS supports the same rich-enough body text SMS does for this module's purposes.
 *
 * Does not special-case an "opted out" outcome the way SendSms does for Twilio's SMS/MMS error
 * 21610 - RcsSenderInterface/TwilioRcsSender never throw one, see that class's own docblock for
 * why. A genuine RCS-side block still surfaces as a generic send failure (recordFailed()), not
 * silently as recordOptedOut() - a documented v1 gap, not a crash.
 */
class SendRcs implements ActionInterface
{
    private const string CHANNEL = 'rcs';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly RcsSenderInterface $rcsSender,
        private readonly Config $config,
        private readonly MessageLogWriter $messageLogWriter,
        private readonly ConsentManager $consentManager,
        private readonly QuietHoursGate $quietHoursGate,
        private readonly FrequencyCapGate $frequencyCapGate,
        private readonly LoggerInterface $logger,
        private readonly CustomerStoreResolver $customerStoreResolver,
        private readonly PhoneNumberResolver $phoneNumberResolver
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $campaignId = isset($context['campaign_id']) ? (int) $context['campaign_id'] : null;
        $variant = isset($context['ordo_split_variant']) ? (string) $context['ordo_split_variant'] : null;
        $triggerOrderId = isset($context['order_id']) ? (int) $context['order_id'] : null;
        if ($customerId <= 0) {
            $this->logger->error('Ordo_Automation: send_rcs action is missing customer_id in context.');
            return;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (Throwable $e) {
            return;
        }

        if (!$this->config->isRcsEnabled($this->customerStoreResolver->resolveStoreId($customer))) {
            $this->logger->debug('Ordo_Automation: send_rcs action skipped, RCS sending is disabled in config.');
            return;
        }

        $phone = $this->phoneNumberResolver->resolve($customer);
        if ($phone === null) {
            $this->logger->error(sprintf(
                'Ordo_Automation: send_rcs action has no ordo_sms_phone set for customer #%d.',
                $customerId
            ));
            return;
        }

        $topic = ConsentTopic::tryFrom((string) ($params['topic'] ?? '')) ?? ConsentTopic::Promotional;
        if (!$this->consentManager->hasTopicConsent($customerId, ConsentChannel::Rcs, $topic)) {
            $this->logger->info(sprintf(
                'Ordo_Automation: send_rcs action skipped for customer #%d, RCS consent withdrawn.',
                $customerId
            ));
            $this->messageLogWriter->recordOptedOut(self::CHANNEL, $customerId, $phone, $campaignId, $variant);
            return;
        }

        $actionId = (int) ($context['ordo_action_id'] ?? 0);
        if (!$this->quietHoursGate->allows($customerId, $campaignId ?? 0, $actionId, $context)) {
            return;
        }

        if (!$this->frequencyCapGate->allows($customerId, self::CHANNEL, $phone, 'send_rcs', $campaignId, $variant)) {
            return;
        }

        if (!$this->phoneNumberResolver->isValidE164($phone)) {
            $this->logger->error(sprintf(
                'Ordo_Automation: send_rcs action skipped for customer #%d, ordo_sms_phone "%s" is not a valid'
                . ' E.164 number.',
                $customerId,
                $phone
            ));
            $this->messageLogWriter->recordFailed(self::CHANNEL, $customerId, $phone, $campaignId, $variant);
            return;
        }

        $message = trim((string) ($params['message'] ?? ''));
        if ($message === '') {
            $this->logger->error('Ordo_Automation: send_rcs action is missing "message" in params.');
            return;
        }

        $message = str_replace(
            '{{recommended_products_text}}',
            (string) ($context['recommended_products_text'] ?? ''),
            $message
        );

        try {
            $providerMessageId = $this->rcsSender->send($phone, $message);
            $this->messageLogWriter->recordSent(
                self::CHANNEL,
                $customerId,
                $phone,
                $providerMessageId,
                $campaignId,
                $variant,
                $triggerOrderId
            );
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: campaign send_rcs action failed for customer #%d: %s',
                $customerId,
                $e->getMessage()
            ));
            $this->messageLogWriter->recordFailed(self::CHANNEL, $customerId, $phone, $campaignId, $variant);
        }
    }

    public function getParamFields(): array
    {
        return [
            [
                'name' => 'message',
                'label' => (string) __('RCS message'),
            ],
            [
                'name' => 'topic',
                'label' => (string) __('Consent topic'),
                'options' => ConsentTopicOptions::toMap(),
                'notice' => (string) __(
                    'Which opt-out preference this send is checked against, beyond the'
                    . ' customer\'s own per-channel consent. Defaults to Promotional if left blank.'
                ),
            ],
        ];
    }
}
