<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Ordo\Automation\Model\ResourceModel\MessageLogEvent as MessageLogEventResource;
use Psr\Log\LoggerInterface;

/**
 * The one place a delivery-status webhook writes an open/click event — kept as a small,
 * dedicated collaborator (same role Model\Sms\MessageLogWriter plays for ordo_message_log
 * itself) so Controller\Email\StatusCallback doesn't grow another 3 constructor dependencies for
 * this on top of what it already has.
 */
class MessageLogEventWriter
{
    public function __construct(
        private readonly MessageLogEventFactory $messageLogEventFactory,
        private readonly MessageLogEventResource $messageLogEventResource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function recordOpened(int $messageLogId): void
    {
        $this->record($messageLogId, MessageLogEvent::TYPE_OPENED, null);
    }

    public function recordClicked(int $messageLogId, ?string $url): void
    {
        $this->record($messageLogId, MessageLogEvent::TYPE_CLICKED, $url);
    }

    /**
     * Writing this event row is deliberately best-effort - same "never let bookkeeping break the
     * actual feature" discipline Model\Sms\MessageLogWriter::save() already follows. A failure
     * here must never turn a successfully-processed webhook event into an error response back to
     * SendGrid (which would just cause it to retry the whole batch).
     */
    private function record(int $messageLogId, string $eventType, ?string $url): void
    {
        try {
            $event = $this->messageLogEventFactory->create();
            $event->setMessageLogId($messageLogId)
                ->setEventType($eventType)
                ->setUrl($url);
            $this->messageLogEventResource->save($event);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Ordo_Automation: failed to write ordo_message_log_event row: %s', $e->getMessage())
            );
        }
    }
}
