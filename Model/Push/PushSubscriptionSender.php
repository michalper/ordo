<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Push;

use Ordo\Automation\Model\Campaign\Action\SendRetrier;
use Ordo\Automation\Model\Push\Exception\SubscriptionGoneException;
use Ordo\Automation\Model\PushSubscription;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The single per-subscription send+error-handling collaborator shared by
 * Model\Campaign\Action\SendPush (the original fan-out loop) and Cron\RetryFailedPushSends (the
 * persisted-retry cron) - extracted so the two never drift on what counts as a permanently-dead
 * subscription vs. a transient failure worth another try.
 *
 * $isRetryAttempt mirrors Model\Campaign\MessageSendRetryQueue::RETRY_CONTEXT_FLAG's rethrow
 * distinction, just as a plain bool instead of a context-array flag since this doesn't go
 * through Model\Campaign\ActionPool at all: on the original (non-retry) send, a transient failure
 * is enqueued via PushSendRetryQueue and swallowed (a failed send must never block the rest of
 * SendPush's fan-out loop, or the rest of the campaign's actions); on a retry attempt, it's
 * rethrown instead so Cron\RetryFailedPushSends' own try/catch can see the failure and apply its
 * own backoff/dead-letter bookkeeping.
 */
class PushSubscriptionSender
{
    private const string CHANNEL = 'push';

    public function __construct(
        private readonly PushSender $pushSender,
        private readonly PushSubscriptionManager $pushSubscriptionManager,
        private readonly PushSendRetryQueue $pushSendRetryQueue,
        private readonly MessageLogWriter $messageLogWriter,
        private readonly SendRetrier $sendRetrier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(
        PushSubscription $subscription,
        string $payload,
        int $customerId,
        ?int $campaignId,
        ?string $variant,
        bool $isRetryAttempt = false
    ): void {
        // ordo_message_log.to_address is varchar(255) (sized for phone numbers/emails); some
        // push services' endpoint URLs run longer, so this truncates purely for logging - the
        // real, full endpoint used to send always comes straight from the subscription row.
        $endpoint = substr($subscription->getEndpoint(), 0, 255);

        try {
            // A dead/gone subscription (SubscriptionGoneException) is permanently invalid -
            // excluded from SendRetrier's retry loop, same reasoning as SendSms's
            // OptedOutException exclusion.
            $this->sendRetrier->attempt(
                function () use ($subscription, $payload): void {
                    $this->pushSender->send($subscription, $payload);
                },
                static fn (Throwable $e): bool => !$e instanceof SubscriptionGoneException
            );
            $this->messageLogWriter->recordSent(self::CHANNEL, $customerId, $endpoint, null, $campaignId, $variant);
        } catch (SubscriptionGoneException) {
            $this->pushSubscriptionManager->delete($subscription);
            $this->messageLogWriter->recordFailed(self::CHANNEL, $customerId, $endpoint, $campaignId, $variant);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: campaign send_push action failed for customer #%d: %s',
                $customerId,
                $e->getMessage()
            ));
            $this->messageLogWriter->recordFailed(self::CHANNEL, $customerId, $endpoint, $campaignId, $variant);

            if ($isRetryAttempt) {
                throw $e;
            }

            $this->pushSendRetryQueue->enqueue(
                (int) $subscription->getEntityId(),
                $customerId,
                $campaignId,
                $variant,
                $payload,
                $e
            );
        }
    }
}
