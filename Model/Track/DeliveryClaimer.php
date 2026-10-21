<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Track;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Model\AbstractModel;

/**
 * The claim-on-hand-out loop shared by Controller\Track\{Popup,Survey}: the actual claim is an
 * UPDATE conditioned on delivered_at still being NULL, not a blind save of the in-memory model -
 * two concurrent requests can both load the same row from the candidate SELECT, but only one of
 * their UPDATEs can match that WHERE clause, so only one ever gets the row back. The loser falls
 * through to the next candidate instead of coming back empty despite a row genuinely being
 * available.
 */
class DeliveryClaimer
{
    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * @param iterable<AbstractModel> $candidates
     * @return AbstractModel|null the first candidate this request won the claim on, null when
     *   none — the caller knows (and annotates) the concrete model its own collection yields
     */
    public function claimFirst(iterable $candidates, string $tableName, string $now): ?AbstractModel
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName($tableName);

        foreach ($candidates as $candidate) {
            $claimed = $connection->update(
                $table,
                ['delivered_at' => $now],
                ['entity_id = ?' => (int) $candidate->getId(), 'delivered_at IS NULL']
            );

            if ($claimed > 0) {
                return $candidate;
            }
        }

        return null;
    }
}
