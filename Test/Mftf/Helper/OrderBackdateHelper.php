<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Backdates a real customer's most recent orders' created_at, oldest-to-newest, to the given
 * days-ago offsets — needed for Cron\CalculateReorderCycle, which requires a real interval
 * between orders (same-day repeat purchases are explicitly skipped, see its own class doc) that
 * a real MFTF checkout flow can't otherwise produce: three real storefront checkouts placed back
 * to back all land on the same wall-clock day. No MFTF-reachable admin UI or REST endpoint can
 * set sales_order.created_at directly, so (same reasoning as CronScheduleHelper) this connects to
 * the test DB directly via PDO using the credentials mftf.yml's setup:install step configures.
 */
class OrderBackdateHelper extends Helper
{
    use DbConnectionTrait;

    /**
     * @param string $daysAgoCsv Comma-separated days-ago offsets, oldest order first (e.g.
     *   "30,20,10" backdates the 3 most recent orders for $customerId to 30/20/10 days ago,
     *   in placement order).
     */
    public function backdateRecentOrders(
        string $daysAgoCsv,
        int $customerId
    ): void {
        $daysAgoList = array_map('intval', explode(',', $daysAgoCsv));
        $limit = count($daysAgoList);

        $pdo = $this->resolveDbConnection();

        // The $limit most recent orders for this customer, oldest-first among that batch -
        // matches the oldest-first order of $daysAgoCsv.
        $select = $pdo->prepare(
            "SELECT entity_id FROM sales_order WHERE customer_id = :customer_id "
            . "ORDER BY entity_id DESC LIMIT {$limit}"
        );
        $select->execute(['customer_id' => $customerId]);
        $orderIds = array_reverse($select->fetchAll(\PDO::FETCH_COLUMN));

        if (count($orderIds) !== $limit) {
            throw new \RuntimeException(sprintf(
                'Expected %d recent orders for customer #%d, found %d.',
                $limit,
                $customerId,
                count($orderIds)
            ));
        }

        $update = $pdo->prepare(
            'UPDATE sales_order SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY) '
            . 'WHERE entity_id = :id'
        );
        foreach ($orderIds as $index => $orderId) {
            $update->execute(['days' => $daysAgoList[$index], 'id' => $orderId]);
        }
    }
}
