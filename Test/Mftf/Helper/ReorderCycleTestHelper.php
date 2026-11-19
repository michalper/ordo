<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Reads a real ordo_reorder_cycle row's own entity_id by customer_id — the row-level "Send
 * Reminder Now"/"Build Cart" admin actions (Ui\Component\Listing\Column\ReorderCycleActions)
 * are addressed by entity_id, not customer_id, and there is no MFTF-reachable UI flow to grab it
 * (unlike a campaign/segment's own entity_id, which a real save redirects to in the URL) — this
 * grid is entirely cron-populated, never admin-authored. Same out-of-band PDO pattern as
 * OrderBackdateHelper for the identical "no MFTF-reachable read path" reason.
 */
class ReorderCycleTestHelper extends Helper
{
    use DbConnectionTrait;

    public function getEntityIdByCustomerId(
        int $customerId
    ): string {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'SELECT entity_id FROM ordo_reorder_cycle WHERE customer_id = :customer_id LIMIT 1'
        );
        $statement->execute(['customer_id' => $customerId]);
        $entityId = $statement->fetchColumn();

        if ($entityId === false) {
            throw new \RuntimeException(sprintf(
                'No ordo_reorder_cycle row found for customer_id=%d.',
                $customerId
            ));
        }

        return (string) $entityId;
    }
}
