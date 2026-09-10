<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Gdpr;

use Magento\Framework\App\ResourceConnection;

/**
 * The data-subject "right to erasure" half of Controller\Adminhtml\Gdpr\Erase.php — deletes
 * every row this module holds that is keyed by customer_id. Deliberately a hard delete, not an
 * anonymize-in-place: every one of these tables is either a short-lived queue/log (notifications,
 * survey prompts, message log) or a derived total (score/tags) that is meaningless without the
 * customer it belongs to, so there is nothing worth keeping in anonymized form.
 *
 * ordo_visitor_event is NOT included — it is keyed by visitor_id, an anonymous browser-cookie
 * identifier this module never links back to a customer_id by design (see Observer\
 * StitchVisitorIdentity's own doc on why that stitching only ever flows into tags/score, not a
 * stored customer_id column on the event rows themselves).
 */
class CustomerDataEraser
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerDataTableProvider $tableProvider
    ) {
    }

    /**
     * @return array<string, int> table => rows deleted
     */
    public function erase(int $customerId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $deleted = [];

        foreach ($this->tableProvider->getTables() as $table) {
            $deleted[$table] = $connection->delete(
                $this->resourceConnection->getTableName($table),
                ['customer_id = ?' => $customerId]
            );
        }

        return $deleted;
    }
}
