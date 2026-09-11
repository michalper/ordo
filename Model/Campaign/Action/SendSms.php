<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\FrequencyCapGate;
use Ordo\Automation\Model\Campaign\QuietHoursGate;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Ordo\Automation\Model\Sms\OptedOutException;
use Ordo\Automation\Model\Sms\SmsSenderInterface;
use Ordo\Automation\Setup\Patch\Data\AddCustomerSmsPhoneAttribute;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Params: {"message": "SMS text"} - "message" may include the literal token
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
 * Checks ConsentManager::hasConsent() before sending — an explicit SMS opt-out is recorded the
 * same way Twilio's own STOP-reply opt-out already is (MessageLogWriter::recordOptedOut()), not
 * as a distinct third outcome. Also checks FrequencyCapGate::allows() right after (opt-in,
 * cross-channel) — over the configured contact-volume cap is recorded as suppressed, not sent.
 */
class SendSms implements ActionInterface
{
    private const string CHANNEL = 'sms';

    /**
     * E.164: a leading "+", then 8-15 digits total, first digit non-zero (ITU-T E.164 caps the
     * whole number, country code included, at 15 digits). Deliberately permissive beyond that —
     * this is a fail-fast sanity check before spending a Twilio API call, not a full numbering-plan validator,
     * so it doesn't try to validate per-country length/prefix rules.
     */
    private const string E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SmsSenderInterface $smsSender,
        private readonly Config $config,
        private readonly MessageLogWriter $messageLogWriter,
        private readonly ConsentManager $consentManager,
        private readonly QuietHoursGate $quietHoursGate,
        private readonly FrequencyCapGate $frequencyCapGate,
        private readonly SendRetrier $sendRetrier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $campaignId = isset($context['campaign_id']) ? (int) $context['campaign_id'] : null;
        $variant = isset($context['ordo_split_variant']) ? (string) $context['ordo_split_variant'] : null;
        if ($customerId <= 0) {
            $this->logger->error('Ordo_Automation: send_sms action is missing customer_id in context.');
            return;
        }

        if (!$this->config->isSmsEnabled()) {
            $this->logger->debug('Ordo_Automation: send_sms action skipped, SMS sending is disabled in config.');
            return;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (Throwable $e) {
            return;
        }

        $phoneAttribute = $customer->getCustomAttribute(AddCustomerSmsPhoneAttribute::ATTRIBUTE_CODE);
        $phone = $phoneAttribute !== null ? trim((string) $phoneAttribute->getValue()) : '';
        if ($phone === '') {
            $this->logger->error(sprintf(
                'Ordo_Automation: send_sms action has no ordo_sms_phone set for customer #%d.',
                $customerId
            ));
            return;
        }

        if (!$this->consentManager->hasConsent($customerId, ConsentChannel::Sms)) {
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

        if (!preg_match(self::E164_PATTERN, $phone)) {
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
                $variant
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
        }
    }
}
