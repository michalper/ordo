<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Gdpr;

use Magento\Framework\App\ResourceConnection;

/**
 * The data-subject "right to erasure" half of Controller\Adminhtml\Gdpr\Erase.php — deletes
 * every row this module holds that is keyed by customer_id (see CustomerDataTableProvider for the
 * full list, including ordo_visitor_event once a visitor's browsing history has been linked to a
 * customer). Deliberately a hard delete, not an anonymize-in-place: every one of these tables is
 * either a short-lived queue/log (notifications, survey prompts, message log) or a derived total
 * (score/tags) that is meaningless without the customer it belongs to, so there is nothing worth
 * keeping in anonymized form.
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
