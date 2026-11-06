<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\FrequencyCapGate;
use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\Campaign\QuietHoursGate;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Ordo\Automation\Model\Sms\OptedOutException;
use Ordo\Automation\Model\Sms\PhoneNumberResolver;
use Ordo\Automation\Model\Sms\SmsSenderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Params: {"message": "SMS text", "topic": "promotional"|"account_updates" (optional, defaults
 * to "promotional")} - "message" may include the literal token
 * "{{recommended_products_text}}", substituted with the plain-text rendering
 * Model\Campaign\Action\AddProductRecommendations writes into the context (empty string if that
 * action never ran, or found nothing to recommend). Not a general templating engine - this one
 * token only. Context must include "customer_id" (phone resolved via the
 * dedicated ordo_sms_phone customer attribute — not the core address telephone, see
 * AddCustomerSmsPhoneAttribute). Mirrors SendEmail's shape: read customer_id, resolve the
 * customer, resolve the delivery target, then hand off to a provider abstraction
 * (SmsSenderInterface, Twilio-backed by default via di.xml preference) inside a try/catch that
 * logs and swallows — a failed SMS never blocks the rest of the campaign's actions.
 *
 * Checks ConsentManager::hasTopicConsent() before sending — an explicit SMS opt-out, at either
 * the channel level or this send's own topic (see ConsentTopic), is recorded the same way
 * Twilio's own STOP-reply opt-out already is (MessageLogWriter::recordOptedOut()), not as a
 * distinct third outcome. Also checks FrequencyCapGate::allows() right after (opt-in,
 * cross-channel) — over the configured contact-volume cap is recorded as suppressed, not sent.
 */
class SendSms implements ActionInterface
{
    private const string CHANNEL = 'sms';
    private const string ACTION_TYPE = 'send_sms';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SmsSenderInterface $smsSender,
        private readonly Config $config,
        private readonly MessageLogWriter $messageLogWriter,
        private readonly ConsentManager $consentManager,
        private readonly QuietHoursGate $quietHoursGate,
        private readonly FrequencyCapGate $frequencyCapGate,
        private readonly SendRetrier $sendRetrier,
        private readonly MessageSendRetryQueue $messageSendRetryQueue,
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
            $this->logger->error('Ordo_Automation: send_sms action is missing customer_id in context.');
            return;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (Throwable $e) {
            return;
        }

        // The customer's own store, not Magento's ambient "current store" - see SendEmail's
        // identical reasoning. Fetched before the config check (unlike before) specifically so
        // this check is store-scoped too.
        if (!$this->config->isSmsEnabled($this->customerStoreResolver->resolveStoreId($customer))) {
            $this->logger->debug('Ordo_Automation: send_sms action skipped, SMS sending is disabled in config.');
            return;
        }

        $phone = $this->phoneNumberResolver->resolve($customer);
        if ($phone === null) {
            $this->logger->error(sprintf(
                'Ordo_Automation: send_sms action has no ordo_sms_phone set for customer #%d.',
                $customerId
            ));
            return;
        }

        $topic = ConsentTopic::tryFrom((string) ($params['topic'] ?? '')) ?? ConsentTopic::Promotional;
        if (!$this->consentManager->hasTopicConsent($customerId, ConsentChannel::Sms, $topic)) {
            $this->logger->info(sprintf(
                'Ordo_Automation: send_sms action skipped for customer #%d, SMS consent withdrawn.',
                $customerId
            ));
            $this->messageLogWriter->recordOptedOut(self::CHANNEL, $customerId, $phone, $campaignId, $variant);
            return;
        }

        $actionId = (int) ($context['ordo_action_id'] ?? 0);
        if (!$this->quietHoursGate->allows($customerId, $campaignId ?? 0, $actionId, $context)) {
            return;
        }

        if (!$this->frequencyCapGate->allows($customerId, self::CHANNEL, $phone, 'send_sms', $campaignId, $variant)) {
            return;
        }

        if (!$this->phoneNumberResolver->isValidE164($phone)) {
            $this->logger->error(sprintf(
                'Ordo_Automation: send_sms action skipped for customer #%d, ordo_sms_phone "%s" is not a valid'
                . ' E.164 number.',
                $customerId,
                $phone
            ));
            $this->messageLogWriter->recordFailed(self::CHANNEL, $customerId, $phone, $campaignId, $variant);
            return;
        }

        $message = trim((string) ($params['message'] ?? ''));
        if ($message === '') {
            $this->logger->error('Ordo_Automation: send_sms action is missing "message" in params.');
            return;
        }

        // Only this one token, deliberately - not a general templating engine. See
        // AddProductRecommendations's own docblock for why this context key exists.
        $message = str_replace(
            '{{recommended_products_text}}',
            (string) ($context['recommended_products_text'] ?? ''),
            $message
        );

        try {
            // OptedOutException means "this number opted out via STOP" - permanently invalid, not
            // worth retrying, so it's excluded from SendRetrier's retry loop here.
            $providerMessageId = $this->sendRetrier->attempt(
                fn () => $this->smsSender->send($phone, $message),
                static fn (Throwable $e): bool => !$e instanceof OptedOutException
            );
            $this->messageLogWriter->recordSent(
                self::CHANNEL,
                $customerId,
                $phone,
                $providerMessageId,
                $campaignId,
                $variant,
                $triggerOrderId
            );
        } catch (OptedOutException $e) {
            // Expected, routine outcome (Twilio's own STOP/opt-out handling) — not a delivery
            // failure, so this logs at a distinctly lower severity than the generic catch below
            // and records it as opted_out, not failed, in ordo_message_log.
            $this->logger->info(sprintf(
                'Ordo_Automation: send_sms action skipped for customer #%d: %s',
                $customerId,
                $e->getMessage()
            ));
            $this->messageLogWriter->recordOptedOut(self::CHANNEL, $customerId, $phone, $campaignId, $variant);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: campaign send_sms action failed for customer #%d: %s',
                $customerId,
                $e->getMessage()
            ));
            $this->messageLogWriter->recordFailed(self::CHANNEL, $customerId, $phone, $campaignId, $variant);

            // Rethrows on a retry attempt instead of enqueuing again - see
            // MessageSendRetryQueue::enqueueOrRethrow()/RETRY_CONTEXT_FLAG.
            $this->messageSendRetryQueue->enqueueOrRethrow(self::ACTION_TYPE, $context, $params, $e);
        }
    }
}
