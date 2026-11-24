<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;

/**
 * Atomic per-(batch, customer) claim closing a real queue-redelivery gap: Magento's queue can
 * redeliver a message after a consumer crash (worker killed mid-batch, deploy, OOM), and
 * SegmentBulkActionConsumer's own per-customer loop had no checkpoint at all - a redelivered
 * chunk would re-apply its action (e.g. add_points) to every customer already processed before
 * the crash, not just the ones never reached. Same atomic-insert-relying-on-a-real-unique-
 * constraint idiom as CustomerTagManager::addTagIfAbsent() - always attempts the insert and
 * treats the resulting DuplicateException as "already claimed" rather than letting it propagate.
 */
class SegmentBulkActionClaimStore
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return bool true if this call is the one that just claimed (customerId) for this
     *   $batchId (safe to run the action), false if it was already claimed - either from this
     *   same message's own earlier, now-redelivered attempt, or a genuinely concurrent one.
     *   Either way, the caller must not run the action again.
     */
    public function claim(string $batchId, int $customerId): bool
    {
        $connection = $this->resourceConnection->getConnection();

        try {
            $connection->insert($this->resourceConnection->getTableName('ordo_segment_bulk_action_claim'), [
                'batch_id' => $batchId,
                'customer_id' => $customerId,
                'claimed_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (DuplicateException) {
            return false;
        }

        return true;
    }

    /**
     * Rolls back a claim() this same call just made, when the action it guarded then genuinely
     * fails - found during a full-repo /code-review: without this, a transient failure (a bad
     * record, a DB hiccup) for one customer left their claim row in place forever, so a later
     * redelivery of this exact message (the scenario this claim store exists for) would skip
     * them permanently instead of retrying - turning "never double-apply on redelivery" into
     * "never apply at all after any transient failure".
     */
    public function unclaim(string $batchId, int $customerId): void
    {
        $connection = $this->resourceConnection->getConnection();

        $connection->delete(
            $this->resourceConnection->getTableName('ordo_segment_bulk_action_claim'),
            ['batch_id = ?' => $batchId, 'customer_id = ?' => $customerId]
        );
    }
}
