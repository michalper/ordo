<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\FrequencyCapGate;
use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\Campaign\QuietHoursGate;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
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
 * "{{recommended_products_text}}" (see AbstractPhoneMessageAction::execute()). Context must
 * include "customer_id" (phone resolved via the dedicated ordo_sms_phone customer attribute —
 * not the core address telephone, see AddCustomerSmsPhoneAttribute).
 *
 * Only this class's own sendMessage() differs from Model\Campaign\Action\SendRcs's: a failed send
 * is retried (SendRetrier) and, if every attempt is exhausted, enqueued for Cron\
 * RetryFailedMessageSends (MessageSendRetryQueue) - and Twilio's own STOP-reply opt-out
 * (TwilioSmsSender throwing OptedOutException) is recorded the same way an explicit consent
 * withdrawal is (MessageLogWriter::recordOptedOut()), not as a distinct third outcome.
 */
class SendSms extends AbstractPhoneMessageAction
{
    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        private readonly SmsSenderInterface $smsSender,
        Config $config,
        MessageLogWriter $messageLogWriter,
        ConsentManager $consentManager,
        QuietHoursGate $quietHoursGate,
        FrequencyCapGate $frequencyCapGate,
        private readonly SendRetrier $sendRetrier,
        private readonly MessageSendRetryQueue $messageSendRetryQueue,
        LoggerInterface $logger,
        CustomerStoreResolver $customerStoreResolver,
        PhoneNumberResolver $phoneNumberResolver
    ) {
        parent::__construct(
            $customerRepository,
            $config,
            $messageLogWriter,
            $consentManager,
            $quietHoursGate,
            $frequencyCapGate,
            $logger,
            $customerStoreResolver,
            $phoneNumberResolver
        );
    }

    protected function channel(): string
    {
        return 'sms';
    }

    protected function actionType(): string
    {
        return 'send_sms';
    }

    protected function consentChannel(): ConsentChannel
    {
        return ConsentChannel::Sms;
    }

    protected function isChannelEnabled(?int $storeId): bool
    {
        return $this->config->isSmsEnabled($storeId);
    }

    protected function messageFieldLabel(): string
    {
        return (string) __('SMS message');
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $params
     */
    protected function sendMessage(string $phone, string $message, array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $campaignId = isset($context['campaign_id']) ? (int) $context['campaign_id'] : null;
        $variant = isset($context['ordo_split_variant']) ? (string) $context['ordo_split_variant'] : null;
        $triggerOrderId = isset($context['order_id']) ? (int) $context['order_id'] : null;

        try {
            // OptedOutException means "this number opted out via STOP" - permanently invalid, not
            // worth retrying, so it's excluded from SendRetrier's retry loop here.
            $providerMessageId = $this->sendRetrier->attempt(
                fn () => $this->smsSender->send($phone, $message),
                static fn (Throwable $e): bool => !$e instanceof OptedOutException
            );
            $this->messageLogWriter->recordSent(
                'sms',
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
            $this->messageLogWriter->recordOptedOut('sms', $customerId, $phone, $campaignId, $variant);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: campaign send_sms action failed for customer #%d: %s',
                $customerId,
                $e->getMessage()
            ));
            $this->messageLogWriter->recordFailed('sms', $customerId, $phone, $campaignId, $variant);

            // Rethrows on a retry attempt instead of enqueuing again - see
            // MessageSendRetryQueue::enqueueOrRethrow()/RETRY_CONTEXT_FLAG.
            $this->messageSendRetryQueue->enqueueOrRethrow('send_sms', $context, $params, $e);
        }
    }

    /**
     * @return array<int, array{
     *     name: string, label: string, notice?: string, options?: array<int|string, string>, type?: string
     * }>
     */
    public function getParamFields(): array
    {
        return [
            [
                'name' => 'message',
                'label' => $this->messageFieldLabel(),
                'notice' => (string) __(
                    'Include an opt-out instruction (e.g. "Reply STOP to unsubscribe") in the'
                    . ' first message to any recipient — required by Twilio\'s messaging policy'
                    . ' and, in the US, the TCPA.'
                ),
            ],
            $this->consentTopicField(),
        ];
    }
}
