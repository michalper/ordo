<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Backdates a real, already-read ordo_notification row's read_at past
 * Cron\PruneNotifications' 24-hour grace window, and confirms the row is genuinely gone
 * afterwards — no MFTF-reachable UI/API sets read_at directly (it's set purely by
 * Controller\Track\DismissNotification's own dismiss logic), same reasoning as
 * PendingPopupTestHelper for its own table.
 */
class NotificationTestHelper extends Helper
{
    use DbConnectionTrait;

    public function backdateReadNotificationForCustomer(
        int $customerId
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'UPDATE ordo_notification SET read_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) '
            . 'WHERE customer_id = :customer_id AND read_at IS NOT NULL'
        );
        $statement->execute(['customer_id' => $customerId]);
    }

    /**
     * @throws \RuntimeException if a matching row still exists
     */
    public function assertNoNotificationForCustomer(
        int $customerId
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare('SELECT COUNT(*) FROM ordo_notification WHERE customer_id = :customer_id');
        $statement->execute(['customer_id' => $customerId]);
        $count = (int) $statement->fetchColumn();

        if ($count > 0) {
            throw new \RuntimeException(sprintf(
                'Unexpected ordo_notification row still found for customer_id=%d.',
                $customerId
            ));
        }
    }
}
