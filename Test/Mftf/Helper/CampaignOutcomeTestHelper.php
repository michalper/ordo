<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Polls the real ordo_campaign_outcome_log row Model\CampaignOutcomeLogger::logSent() writes,
 * instead of assuming the campaign-dispatch consumer has already finished by the time the next
 * step runs - drainQueueWithRetry's own retries only guard against the CLI bridge returning
 * before Magento finishes bootstrapping (see that helper's own docblock), not against the
 * consumer itself taking longer than usual to process a message under CI load. Needed
 * specifically where a second real event depends on the first one's own outcome row already
 * existing and being un-acted (see AdminCampaignFunnelReflectsRealSendAndConversionTest) -
 * Observer\RecordCampaignOutcome::markActed() marks every un-acted row for a customer, not just
 * one, so if BOTH sends' own outcome rows are still un-acted at the same moment a later order's
 * markActed() call runs, that one order wrongly converts both instead of just the one it should.
 */
class CampaignOutcomeTestHelper extends Helper
{
    /**
     * @throws \RuntimeException if no matching row appears within the given attempts
     */
    public function assertOutcomeLogged(
        int $campaignId,
        int $customerId,
        int $maxAttempts = 10,
        int $sleepSeconds = 1,
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

        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM ordo_campaign_outcome_log WHERE campaign_id = :campaign_id AND customer_id = :customer_id'
        );

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $statement->execute(['campaign_id' => $campaignId, 'customer_id' => $customerId]);
            if ((int) $statement->fetchColumn() > 0) {
                return;
            }

            if ($attempt < $maxAttempts) {
                sleep($sleepSeconds);
            }
        }

        throw new \RuntimeException(sprintf(
            'No ordo_campaign_outcome_log row appeared for campaign_id=%d, customer_id=%d after %d attempts.',
            $campaignId,
            $customerId,
            $maxAttempts
        ));
    }
}
