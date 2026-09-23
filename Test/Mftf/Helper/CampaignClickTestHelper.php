<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Inserts a real ordo_message_log_event 'clicked' row directly via SQL, tied to the most
 * recently written ordo_message_log row for a given recipient+campaign — this event is normally
 * written only by Controller\Email\StatusCallback (Model\MessageLogEventWriter::recordClicked()),
 * which requires a real, signed SendGrid Event Webhook payload this test environment has no
 * account/key to produce, same reasoning MessageLogTestHelper already documents for its own
 * table. Model\Campaign\AttributionCalculator's own query joins ordo_message_log_event back to
 * ordo_message_log by message_log_id (not customer_id/campaign_id directly), so this locates the
 * exact row a real campaign send just wrote (Model\Sms\MessageLogWriter::recordSent()'s own real
 * side effect of a real order_placed dispatch) rather than fabricating one from scratch.
 */
class CampaignClickTestHelper extends Helper
{
    public function recordClickForCampaign(
        string $toAddress,
        string $campaignId,
        string $dbHost = '127.0.0.1',
        string $dbName = 'magento',
        string $dbUser = 'root',
        string $dbPassword = ''
    ): void {
        $pdo = new \PDO(
            "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPassword,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $messageLogId = $pdo->prepare(
            'SELECT entity_id FROM ordo_message_log '
            . 'WHERE to_address = :to_address AND campaign_id = :campaign_id '
            . 'ORDER BY entity_id DESC LIMIT 1'
        );
        $messageLogId->execute(['to_address' => $toAddress, 'campaign_id' => $campaignId]);
        $id = $messageLogId->fetchColumn();

        if ($id === false) {
            throw new \RuntimeException(sprintf(
                'CampaignClickTestHelper: no ordo_message_log row found for to_address="%s", campaign_id="%s" '
                . '— the campaign must have already sent a real message to this recipient.',
                $toAddress,
                $campaignId
            ));
        }

        $insert = $pdo->prepare(
            "INSERT INTO ordo_message_log_event (message_log_id, event_type, url, created_at) "
            . "VALUES (:message_log_id, 'clicked', 'https://example.com/', UTC_TIMESTAMP())"
        );
        $insert->execute(['message_log_id' => $id]);
    }
}
