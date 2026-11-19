<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Inserts a real ordo_message_log row directly via SQL — this table is written only by
 * Model\Sms\MessageLogWriter (from a real send_sms campaign action, which needs a real Twilio
 * account this test environment doesn't have, see ROADMAP.md) or the delivery-status webhook, so
 * there is no MFTF-reachable path that produces a row without one. Same reasoning as
 * OfferTestHelper/OrderBackdateHelper for their own tables.
 */
class MessageLogTestHelper extends Helper
{
    use DbConnectionTrait;

    public function insertMessageLog(
        string $channel,
        string $toAddress,
        string $status,
        int $customerId = 0
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'INSERT INTO ordo_message_log (channel, customer_id, to_address, status) '
            . 'VALUES (:channel, :customer_id, :to_address, :status)'
        );
        $statement->execute([
            'channel' => $channel,
            'customer_id' => $customerId > 0 ? $customerId : null,
            'to_address' => $toAddress,
            'status' => $status,
        ]);
    }

    /**
     * The real ordo_message_log.variant column a split-tested send_email action's own real
     * dispatch stamps (Model\Campaign\Action\SendEmail reading $context['ordo_split_variant']) -
     * there is no grid column or other MFTF-reachable UI surface for it (see that column's own
     * db_schema.xml comment), so this reads it directly.
     */
    public function getVariantForRecipient(
        string $toAddress
    ): string {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'SELECT variant FROM ordo_message_log WHERE to_address = :to_address ORDER BY entity_id DESC LIMIT 1'
        );
        $statement->execute(['to_address' => $toAddress]);
        $variant = $statement->fetchColumn();

        return $variant === false || $variant === null ? '' : (string) $variant;
    }
}
