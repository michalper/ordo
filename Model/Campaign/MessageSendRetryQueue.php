<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Ordo\Automation\Model\MessageSendRetryFactory;
use Ordo\Automation\Model\ResourceModel\MessageSendRetry as MessageSendRetryResource;
use Ordo\Automation\Model\Retry\RetryBackoff;
use Psr\Log\LoggerInterface;

/**
 * Persisted retry queue for a send_email/send_sms/send_whatsapp campaign action whose
 * SendRetrier in-process retries were all exhausted - see etc/db_schema.xml's
 * ordo_message_send_retry comment. Send{Email,Sms,WhatsApp} call enqueue() the moment their own
 * retries are exhausted (previously that just wrote an ordo_message_log STATUS_FAILED row and
 * moved on); Cron\RetryFailedMessageSends then owns re-attempting and rescheduling from there.
 *
 * Same backoff shape as Model\Campaign\ActionRetryQueue - a separate queue rather than a shared
 * one because the two retry structurally different things (a scheduled-action resume vs. a
 * single channel send); only the backoff math itself is shared, via Model\Retry\RetryBackoff.
 */
class MessageSendRetryQueue
{
    public const int BASE_DELAY_MINUTES = 5;

    public const int MAX_DELAY_MINUTES = 120;

    /**
     * Context key Cron\RetryFailedMessageSends sets to true before re-running a Send{Email,Sms,
     * WhatsApp} action. Those actions normally catch every Throwable themselves (a failed send
     * must never block the rest of the campaign's actions) - which would otherwise make a retry
     * that fails again look like a success to the retry cron, since no exception would ever
     * reach it. When this flag is set, the action rethrows instead of swallowing, so the retry
     * cron's own try/catch can see the failure and apply backoff/dead-letter bookkeeping; on the
     * original (non-retry) dispatch path this key is always absent, so behavior there is
     * unchanged.
     */
    public const string RETRY_CONTEXT_FLAG = '__message_send_retry_attempt';

    public function __construct(
        private readonly MessageSendRetryFactory $messageSendRetryFactory,
        private readonly MessageSendRetryResource $messageSendRetryResource,
        private readonly LoggerInterface $logger,
        private readonly RetryBackoff $retryBackoff
    ) {
    }

    /**
     * The one failure tail every Send* action shares: on the original dispatch path, persist a
     * retry row; on a RETRY_CONTEXT_FLAG attempt, rethrow instead (see the flag's docblock
     * above) so the retry cron sees the failure — and no second, redundant retry row is ever
     * enqueued. Lives here, next to the flag it interprets, instead of four copies in the
     * actions.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $params
     */
    public function enqueueOrRethrow(string $actionType, array $context, array $params, \Throwable $error): void
    {
        if (!empty($context[self::RETRY_CONTEXT_FLAG])) {
            throw $error;
        }

        $this->enqueue($actionType, $context, $params, $error);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $params
     */
    public function enqueue(string $actionType, array $context, array $params, \Throwable $error): void
    {
        try {
            $retry = $this->messageSendRetryFactory->create();
            $retry->setActionType($actionType);
            $retry->setContext($context);
            $retry->setParams($params);
            $retry->setAttempts(1);
            $retry->setLastError($error->getMessage());
            $retry->setNextRetryAt($this->nextRetryAt(1));
            $this->messageSendRetryResource->save($retry);
        } catch (\Throwable $e) {
            // A DB hiccup enqueuing the retry must not compound the original failure - the
            // original error is already logged by the caller; this one just means the send won't
            // get a second chance, which is no worse than the old drop-on-the-floor behavior this
            // replaces.
            $this->logger->error(sprintf(
                'Ordo_Automation: failed to enqueue message send retry for "%s": %s',
                $actionType,
                $e->getMessage()
            ));
        }
    }

    /**
     * Exponential backoff - the math lives in Model\Retry\RetryBackoff (shared with the other
     * retry queues), parametrized by this queue's own BASE/MAX constants.
     */
    public function nextRetryAt(int $attempts): string
    {
        return $this->retryBackoff->nextRetryAt($attempts, self::BASE_DELAY_MINUTES, self::MAX_DELAY_MINUTES);
    }
}
