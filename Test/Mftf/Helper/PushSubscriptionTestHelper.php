<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Direct-DB read access to ordo_push_subscription (keyed by endpoint_hash = sha256(endpoint),
 * the same lookup PushSubscriptionManager itself uses - see that table's own db_schema.xml
 * comment for why endpoint, a text column, isn't queried directly) for
 * AdminPushSubscriptionLifecycleTest/AdminPushSubscriptionLoggedInOriginCheckTest - proving a
 * rejected registration produced no row, and an accepted one produced the real customer_id/
 * visitor_id/key values it should have.
 */
class PushSubscriptionTestHelper extends Helper
{
    use DbConnectionTrait;

    public function countSubscriptionsForEndpoint(
        string $endpoint
    ): int {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM ordo_push_subscription WHERE endpoint_hash = :endpoint_hash'
        );
        $statement->execute(['endpoint_hash' => hash('sha256', $endpoint)]);

        return (int) $statement->fetchColumn();
    }

    public function getCustomerIdForEndpoint(
        string $endpoint
    ): string {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'SELECT customer_id FROM ordo_push_subscription WHERE endpoint_hash = :endpoint_hash'
        );
        $statement->execute(['endpoint_hash' => hash('sha256', $endpoint)]);

        return (string) $statement->fetchColumn();
    }
}
