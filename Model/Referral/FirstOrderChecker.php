<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Referral;

use Magento\Framework\App\ResourceConnection;

/**
 * Whether a customer has exactly one non-canceled order on record - used right after
 * `sales_order_place_after` (the order is already persisted by then) to tell a genuinely first
 * order apart from a repeat purchase, without loading a full order collection just to count rows.
 *
 * Excludes canceled orders, the same `state != 'canceled'` filter every other order-count
 * aggregate in this module already applies (RfmCalculator/ClvCalculator/ChurnRiskCalculator) -
 * without it, a customer whose only real order was canceled and who then places a genuine first
 * order would be counted as having 2 orders here, silently missing the referral-conversion reward
 * this class exists to trigger.
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
            ->where('customer_id = ?', $customerId)
            ->where('state != ?', 'canceled');

        return (int) $connection->fetchOne($select) === 1;
    }
}
