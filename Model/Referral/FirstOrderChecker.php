<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Referral;

use Magento\Framework\App\ResourceConnection;

/**
 * Whether a customer has exactly one order on record - used right after
 * `sales_order_place_after` (the order is already persisted by then) to tell a genuinely first
 * order apart from a repeat purchase, without loading a full order collection just to count rows.
 */
class FirstOrderChecker
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function isFirstOrder(int $customerId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $select = $connection->select()
            ->from($orderTable, 'COUNT(*)')
            ->where('customer_id = ?', $customerId);

        return (int) $connection->fetchOne($select) === 1;
    }
}
