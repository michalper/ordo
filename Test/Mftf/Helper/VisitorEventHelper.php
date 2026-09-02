<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Confirms window.ordoTrack() (view/frontend/web/js/tracker.js) actually reached
 * Controller/Track/Event.php and got persisted into ordo_visitor_event — the one part of the
 * tracking snippet no unit test can cover (a real fetch() call, from a real browser, hitting a
 * real controller). MFTF has no built-in "check a database row" action, so this connects
 * directly with the same connection details mftf.yml's own `mysql` service container uses
 * (root, no password, database "magento", 127.0.0.1) — the same hardcoded-default pattern
 * MailHogHelper.php already uses for its own out-of-band verification.
 */
class VisitorEventHelper extends Helper
{
    /**
     * @throws \RuntimeException if no matching row exists
     */
    public function assertEventLogged(
        string $visitorId,
        string $eventType,
        string $eventKey = '',
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

        $sql = 'SELECT COUNT(*) FROM ordo_visitor_event WHERE visitor_id = :visitor_id AND event_type = :event_type';
        $params = ['visitor_id' => $visitorId, 'event_type' => $eventType];
        if ($eventKey !== '') {
            $sql .= ' AND event_key = :event_key';
            $params['event_key'] = $eventKey;
        }

        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $count = (int) $statement->fetchColumn();

        if ($count < 1) {
            throw new \RuntimeException(sprintf(
                'No ordo_visitor_event row found for visitor_id="%s" event_type="%s"%s.',
                $visitorId,
                $eventType,
                $eventKey !== '' ? sprintf(' event_key="%s"', $eventKey) : ''
            ));
        }
    }
}
