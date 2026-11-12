<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\FrequencyCapGate;
use Ordo\Automation\Model\Campaign\QuietHoursGate;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\Rcs\RcsSenderInterface;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Ordo\Automation\Model\Sms\PhoneNumberResolver;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Params: {"message": "RCS text", "topic": "promotional"|"account_updates" (optional, defaults to
 * "promotional")}. Byte-for-byte the same shape as Model\Campaign\Action\SendSms (both extend
 * AbstractPhoneMessageAction) - same ordo_sms_phone customer attribute/PhoneNumberResolver (RCS,
 * like SMS, is addressed by phone number via carrier routing), same ConsentManager/
 * QuietHoursGate/FrequencyCapGate plumbing - only the channel string and the sender interface
 * differ structurally; see ADR 0049 for why the actual send step isn't shared.
 *
 * Does not special-case an "opted out" outcome the way SendSms does for Twilio's SMS/MMS error
 * 21610 - RcsSenderInterface/TwilioRcsSender never throw one, see that class's own docblock for
 * why. A genuine RCS-side block still surfaces as a generic send failure (recordFailed()), not
 * silently as recordOptedOut() - a documented v1 gap, not a crash. Also doesn't participate in
 * Cron\RetryFailedMessageSends (MessageSendRetryQueue) - Twilio's RCS error taxonomy isn't
 * established well enough yet to retry indiscriminately without risking a double-send on an
 * error that was actually permanent.
 */
class SendRcs extends AbstractPhoneMessageAction
{
    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        private readonly RcsSenderInterface $rcsSender,
        Config $config,
        MessageLogWriter $messageLogWriter,
        ConsentManager $consentManager,
        QuietHoursGate $quietHoursGate,
        FrequencyCapGate $frequencyCapGate,
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
        return 'rcs';
    }

    protected function actionType(): string
    {
        return 'send_rcs';
    }

    protected function consentChannel(): ConsentChannel
    {
        return ConsentChannel::Rcs;
    }

    protected function isChannelEnabled(?int $storeId): bool
    {
        return $this->config->isRcsEnabled($storeId);
    }

    protected function messageFieldLabel(): string
    {
        return (string) __('RCS message');
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
            $providerMessageId = $this->rcsSender->send($phone, $message);
            $this->messageLogWriter->recordSent(
                'rcs',
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
            $this->messageLogWriter->recordFailed('rcs', $customerId, $phone, $campaignId, $variant);
        }
    }
}
