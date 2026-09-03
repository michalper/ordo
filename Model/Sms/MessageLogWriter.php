<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Sms;

use Ordo\Automation\Model\MessageLog;
use Ordo\Automation\Model\MessageLogFactory;
use Ordo\Automation\Model\ResourceModel\MessageLog as MessageLogResource;
use Psr\Log\LoggerInterface;

/**
 * The one place SendSms writes to ordo_message_log — kept as a small, dedicated collaborator
 * rather than inline in SendSms so the delivery-status webhook controller can share the same
 * "load by provider_message_id, update status" half of this without duplicating it.
 */
class MessageLogWriter
{
    public function __construct(
        private readonly MessageLogFactory $messageLogFactory,
        private readonly MessageLogResource $messageLogResource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function recordSent(
        string $channel,
        ?int $customerId,
        string $toAddress,
        string $providerMessageId
    ): void {
        $log = $this->messageLogFactory->create();
        $log->setChannel($channel)
            ->setCustomerId($customerId)
            ->setToAddress($toAddress)
            ->setProviderMessageId($providerMessageId)
            ->setStatus(MessageLog::STATUS_SENT);

        $this->save($log);
    }

    public function recordOptedOut(string $channel, ?int $customerId, string $toAddress): void
    {
        $log = $this->messageLogFactory->create();
        $log->setChannel($channel)
            ->setCustomerId($customerId)
            ->setToAddress($toAddress)
            ->setStatus(MessageLog::STATUS_OPTED_OUT);

        $this->save($log);
    }

    public function recordFailed(string $channel, ?int $customerId, string $toAddress): void
    {
        $log = $this->messageLogFactory->create();
        $log->setChannel($channel)
            ->setCustomerId($customerId)
            ->setToAddress($toAddress)
            ->setStatus(MessageLog::STATUS_FAILED);

        $this->save($log);
    }

    /**
     * Writing this log row is deliberately best-effort — a failure here must never turn a
     * successfully-sent SMS into a logged campaign-action error (or vice versa mask a real send
     * failure behind a logging exception). Same "never let bookkeeping break the actual feature"
     * discipline the rest of this module's fire-and-forget campaign actions already follow.
     */
    private function save(MessageLog $log): void
    {
        try {
            $this->messageLogResource->save($log);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Ordo_Automation: failed to write ordo_message_log row: %s', $e->getMessage()));
        }
    }
}
