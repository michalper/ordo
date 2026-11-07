<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Backdates a real, already-delivered ordo_pending_popup row's delivered_at past
 * Cron\PrunePendingPopups' 24-hour grace window, and confirms the row is genuinely gone
 * afterwards — no MFTF-reachable UI/API sets delivered_at directly (it's set purely by
 * Controller\Track\Poll's own claim-on-poll logic), same reasoning as OrderBackdateHelper/
 * CronScheduleHelper for their own tables.
 */
class PendingPopupTestHelper extends Helper
{
    public function backdateDeliveredPopupForCustomer(
        int $customerId,
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
            'UPDATE ordo_pending_popup SET delivered_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) '
            . 'WHERE customer_id = :customer_id AND delivered_at IS NOT NULL'
        );
        $statement->execute(['customer_id' => $customerId]);
    }

    /**
     * Polls directly for the real ordo_pending_popup row ShowPopup::execute() writes, instead of
     * assuming the campaign-dispatch consumer has already finished by the time this runs -
     * drainQueueWithRetry's own retries guard against the CLI bridge returning before Magento
     * finishes bootstrapping (see that helper's own docblock), not against the consumer itself
     * taking longer than usual under CI load. AdminCampaignVisitorTagConditionTest's own real CI
     * run caught this the hard way: both queue messages still showed MESSAGE_STATUS_RETRY_REQUIRED
     * in a post-failure dump, yet the row-producing dispatch is otherwise correct (confirmed via a
     * direct, consumer-bypassing reproduction) - a slow consumer, not a code bug. Closing the race
     * here, the same direct-DB-poll way VisitorEventHelper::assertEventLogged/assertVisitorTagAdded
     * already close the identical race one step earlier in that same test, is more robust than
     * padding drainQueueWithRetry's own blind retry count further.
     *
     * @throws \RuntimeException if no matching row exists after exhausting every attempt
     */
    public function assertPendingPopupForVisitor(
        string $visitorId,
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

        $statement = $pdo->prepare('SELECT COUNT(*) FROM ordo_pending_popup WHERE visitor_id = :visitor_id');

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $statement->execute(['visitor_id' => $visitorId]);
            if ((int) $statement->fetchColumn() > 0) {
                return;
            }

            if ($attempt < $maxAttempts) {
                sleep($sleepSeconds);
            }
        }

        throw new \RuntimeException(sprintf(
            'No ordo_pending_popup row appeared for visitor_id "%s" after %d attempts.',
            $visitorId,
            $maxAttempts
        ));
    }

    /**
     * @throws \RuntimeException if a matching row still exists
     */
    public function assertNoPendingPopupForCustomer(
        int $customerId,
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

        $statement = $pdo->prepare('SELECT COUNT(*) FROM ordo_pending_popup WHERE customer_id = :customer_id');
        $statement->execute(['customer_id' => $customerId]);
        $count = (int) $statement->fetchColumn();

        if ($count > 0) {
            throw new \RuntimeException(sprintf(
                'Unexpected ordo_pending_popup row still found for customer_id=%d.',
                $customerId
            ));
        }
    }
}
