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
