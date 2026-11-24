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

    /**
     * Batch variant of claim() - one SELECT for who's already claimed plus one bulk INSERT for
     * everyone else, instead of one INSERT attempt per customer - found during a full-repo
     * /simplify sweep, same "check existing, bulk-insert the diff" shape
     * CustomerTagManager::addTagsIfAbsent() already uses for the identical "most of this batch
     * was never claimed before" case (the common one - the uncommon one, this exact message
     * genuinely redelivered, excludes everyone via the SELECT below before the insert ever
     * runs). A real race within this call's own SELECT-then-insert window (a second, genuinely
     * concurrent worker claiming the same few customers) falls back to this store's own
     * single-row claim() for just the colliding batch, rather than letting one row's
     * DuplicateException abort every other customer's otherwise-successful claim in the same
     * call.
     *
     * @param int[] $customerIds
     * @return int[] the subset of $customerIds this call successfully claimed - safe to run the
     *   action for. Everything else was already claimed (an earlier, now-redelivered attempt at
     *   this exact message, or a genuinely concurrent claim) and must not run the action again.
     */
    public function claimBatch(string $batchId, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_segment_bulk_action_claim');

        $alreadyClaimed = array_map(
            static fn (mixed $customerId): int => (int) $customerId,
            $connection->fetchCol(
                $connection->select()
                    ->from($table, 'customer_id')
                    ->where('batch_id = ?', $batchId)
                    ->where('customer_id IN (?)', $customerIds)
            )
        );

        $toClaim = array_values(array_diff($customerIds, $alreadyClaimed));
        if ($toClaim === []) {
            return [];
        }

        $claimedAt = date('Y-m-d H:i:s');
        $rows = array_map(
            static fn (int $customerId): array => [
                'batch_id' => $batchId,
                'customer_id' => $customerId,
                'claimed_at' => $claimedAt,
            ],
            $toClaim
        );

        try {
            $connection->insertMultiple($table, $rows);
            return $toClaim;
        } catch (DuplicateException) {
            return array_values(array_filter(
                $toClaim,
                fn (int $customerId): bool => $this->claim($batchId, $customerId)
            ));
        }
    }
}
