<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Seeds a real ordo_message_log row plus $eventCount ordo_message_log_event "opened" rows all at
 * the same UTC timestamp - the exact shape SendTimeOptimizer::getBestHour() reads (a real
 * SendGrid Event Webhook open/click history), without needing a live SendGrid account this
 * environment doesn't have to produce it (same reasoning as MessageLogTestHelper's own docblock).
 * All events land in the SAME hour on purpose - the point is to make that one hour the
 * unambiguous histogram mode, not to model a realistic spread.
 */
class SendTimeOptimizerTestHelper extends Helper
{
    use DbConnectionTrait;

    public function seedEmailOpenHistory(
        int $customerId,
        string $toAddress,
        string $eventTimestampUtc,
        int $eventCount = 3
    ): void {
        $pdo = $this->resolveDbConnection();

        $messageLogStatement = $pdo->prepare(
            "INSERT INTO ordo_message_log (channel, customer_id, to_address, status) "
            . "VALUES ('email', :customer_id, :to_address, 'delivered')"
        );
        $messageLogStatement->execute(['customer_id' => $customerId, 'to_address' => $toAddress]);
        $messageLogId = (int) $pdo->lastInsertId();

        $eventStatement = $pdo->prepare(
            "INSERT INTO ordo_message_log_event (message_log_id, event_type, created_at) "
            . "VALUES (:message_log_id, 'opened', :created_at)"
        );
        for ($i = 0; $i < $eventCount; $i++) {
            $eventStatement->execute(['message_log_id' => $messageLogId, 'created_at' => $eventTimestampUtc]);
        }
    }
}
