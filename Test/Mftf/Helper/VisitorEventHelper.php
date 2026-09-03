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

    /**
     * Confirms VisitorAggregator actually wrote a real ordo_visitor_tag row for this visitor —
     * the real, DB-observable effect of crossing Config::getTrackingClickThreshold(), and the
     * exact signal Observer\DispatchVisitorTagAddedCampaigns.php's "visitor_tag_added" trigger
     * fires from (see VisitorTagManager::addTag()). Same out-of-band PDO pattern as
     * assertEventLogged() above, against the tag table instead of the event table.
     *
     * @throws \RuntimeException if no matching row exists
     */
    public function assertVisitorTagAdded(
        string $visitorId,
        string $tag,
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
            'SELECT COUNT(*) FROM ordo_visitor_tag WHERE visitor_id = :visitor_id AND tag = :tag'
        );
        $statement->execute(['visitor_id' => $visitorId, 'tag' => $tag]);
        $count = (int) $statement->fetchColumn();

        if ($count < 1) {
            throw new \RuntimeException(sprintf(
                'No ordo_visitor_tag row found for visitor_id="%s" tag="%s".',
                $visitorId,
                $tag
            ));
        }
    }

    /**
     * Confirms CustomerTagManager::addTag() actually wrote a real ordo_customer_tag row for a
     * customer identified by email — used by tests that register a real customer through the
     * storefront form rather than <createData> (which never yields an entity_id/customer_id to
     * reference), so the only handle available afterwards is the email address the form was
     * filled with. Joins through customer_entity the same way the storefront/admin already
     * identify a customer by email. Same out-of-band PDO pattern as assertVisitorTagAdded() above.
     *
     * @throws \RuntimeException if no matching customer or no matching tag row exists
     */
    public function assertCustomerTagAddedByEmail(
        string $email,
        string $tag,
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
            'SELECT COUNT(*) FROM ordo_customer_tag oct '
            . 'INNER JOIN customer_entity ce ON ce.entity_id = oct.customer_id '
            . 'WHERE ce.email = :email AND oct.tag = :tag'
        );
        $statement->execute(['email' => $email, 'tag' => $tag]);
        $count = (int) $statement->fetchColumn();

        if ($count < 1) {
            throw new \RuntimeException(sprintf(
                'No ordo_customer_tag row found for customer email="%s" tag="%s".',
                $email,
                $tag
            ));
        }
    }

    /**
     * Negative counterpart to assertCustomerTagAddedByEmail() above — confirms a tag was NOT
     * applied, for tests proving a deleted/disabled campaign's action never ran. Same
     * out-of-band PDO pattern; a missing customer row is not itself a failure here (unlike the
     * positive assertion) since the point is only that no tag row exists.
     *
     * @throws \RuntimeException if a matching tag row exists
     */
    public function assertCustomerTagNotAddedByEmail(
        string $email,
        string $tag,
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
            'SELECT COUNT(*) FROM ordo_customer_tag oct '
            . 'INNER JOIN customer_entity ce ON ce.entity_id = oct.customer_id '
            . 'WHERE ce.email = :email AND oct.tag = :tag'
        );
        $statement->execute(['email' => $email, 'tag' => $tag]);
        $count = (int) $statement->fetchColumn();

        if ($count > 0) {
            throw new \RuntimeException(sprintf(
                'Unexpected ordo_customer_tag row found for customer email="%s" tag="%s".',
                $email,
                $tag
            ));
        }
    }

    /**
     * Confirms CustomerScoreManager::addPoints() actually wrote/updated a real ordo_customer_score
     * row for a customer identified by email, at or above a minimum total — used by tests where
     * points might already exist from another source in the same run (e.g. a demographic score
     * rule writes to a separate table, but a prior add_points action in the same test could have
     * already accumulated some), so an exact-match assertion would be brittle. Same out-of-band
     * PDO pattern as assertCustomerTagAddedByEmail() above.
     *
     * @throws \RuntimeException if no matching customer row exists or its score is below $minScore
     */
    public function assertCustomerScoreAtLeastByEmail(
        string $email,
        string $minScore,
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
            'SELECT ocs.score FROM ordo_customer_score ocs '
            . 'INNER JOIN customer_entity ce ON ce.entity_id = ocs.customer_id '
            . 'WHERE ce.email = :email'
        );
        $statement->execute(['email' => $email]);
        $score = $statement->fetchColumn();

        if ($score === false) {
            throw new \RuntimeException(sprintf(
                'No ordo_customer_score row found for customer email="%s".',
                $email
            ));
        }

        if ((int) $score < (int) $minScore) {
            throw new \RuntimeException(sprintf(
                'ordo_customer_score for customer email="%s" is %d, expected at least %d.',
                $email,
                (int) $score,
                (int) $minScore
            ));
        }
    }
}
