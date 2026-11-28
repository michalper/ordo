<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\LoyaltyPointsManager;
use Ordo\Automation\Model\ResourceModel\LoyaltyPointsReport\Grid\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for Collection's own SQL - a derived-table pipeline with a MySQL
 * SUM() OVER (ORDER BY month) running total, the exact kind of thing a unit test with a mocked
 * adapter can't meaningfully verify (same reasoning CohortRetentionGridCollectionTest's own
 * docblock gives for its ROW_NUMBER() OVER window function).
 *
 * Rows are inserted directly into ordo_loyalty_points_ledger (no FK to customer_entity at all,
 * per that table's own db_schema.xml comment - a plain int customer_id needs no real customer
 * row behind it). Backdated 35-37 months into the past, deliberately far from "now" - the only
 * other real-DB writer to this table in this suite (LoyaltyWalletPassEndpointTest) always dates
 * its own row at the current moment and never cleans it up (a real, pre-existing gap, not
 * something this test should also do), so every assertion here would be polluted if it shared
 * "this month" with that row. Picking months far enough in the past that nothing else in this
 * module's test suite has ever written to makes the running "outstanding_liability" total fully
 * deterministic: with nothing before it, the running total at each of this test's own months is
 * exactly what this test itself inserted, nothing else.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/LoyaltyPointsReportGridCollectionTest.php
 */
class LoyaltyPointsReportGridCollectionTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $ledgerIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        self::$objectManager->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);
    }

    protected function tearDown(): void
    {
        if ($this->ledgerIds === []) {
            return;
        }

        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $resourceConnection->getConnection()->delete(
            $resourceConnection->getTableName('ordo_loyalty_points_ledger'),
            ['entity_id IN (?)' => $this->ledgerIds]
        );
        $this->ledgerIds = [];
    }

    private function insertLedgerRow(int $customerId, string $type, int $pointsDelta, int $monthsAgo): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_loyalty_points_ledger');

        $connection->insert($table, [
            'customer_id' => $customerId,
            'type' => $type,
            'points_delta' => $pointsDelta,
            'created_at' => date('Y-m-15 00:00:00', (int) strtotime("-{$monthsAgo} months")),
        ]);
        $this->ledgerIds[] = (int) $connection->lastInsertId($table);
    }

    public function testGetItemsAggregatesEarnRedeemExpireIntoACorrectMonthlyRunningTotal(): void
    {
        $customer1 = 900001;
        $customer2 = 900002;

        // Month A (-37 months): only an earn - redemption_rate must be 0.0 (earned but nothing
        // redeemed yet), not null.
        $this->insertLedgerRow($customer1, LoyaltyPointsManager::TYPE_EARN, 100, monthsAgo: 37);

        // Month B (-36 months): an earn, a redeem, and an expire together - proves all three
        // feed the same month's net correctly, and redemption_rate is scoped to that month only.
        $this->insertLedgerRow($customer2, LoyaltyPointsManager::TYPE_EARN, 50, monthsAgo: 36);
        $this->insertLedgerRow($customer1, LoyaltyPointsManager::TYPE_REDEEM, -30, monthsAgo: 36);
        $this->insertLedgerRow($customer1, LoyaltyPointsManager::TYPE_EXPIRE, -10, monthsAgo: 36);

        // Month C (-35 months): only an expire, nothing earned - redemption_rate must be NULL
        // (0 earned has no meaningful rate), not 0.
        $this->insertLedgerRow($customer1, LoyaltyPointsManager::TYPE_EXPIRE, -5, monthsAgo: 35);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();

        $monthA = date('Y-m-01', strtotime('-37 months'));
        $monthB = date('Y-m-01', strtotime('-36 months'));
        $monthC = date('Y-m-01', strtotime('-35 months'));

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[$row->getData('month')] = $row;
        }

        self::assertArrayHasKey($monthA, $byMonth, 'Month A must produce its own row.');
        self::assertArrayHasKey($monthB, $byMonth, 'Month B must produce its own row.');
        self::assertArrayHasKey($monthC, $byMonth, 'Month C must produce its own row.');

        $rowA = $byMonth[$monthA];
        self::assertSame(100, (int) $rowA->getData('earned'));
        self::assertSame(0, (int) $rowA->getData('redeemed'));
        self::assertSame(0, (int) $rowA->getData('expired'));
        self::assertSame(100, (int) $rowA->getData('net'));
        self::assertSame(100, (int) $rowA->getData('outstanding_liability'));
        self::assertSame(0.0, (float) $rowA->getData('redemption_rate'));

        $rowB = $byMonth[$monthB];
        self::assertSame(50, (int) $rowB->getData('earned'));
        self::assertSame(30, (int) $rowB->getData('redeemed'));
        self::assertSame(10, (int) $rowB->getData('expired'));
        self::assertSame(10, (int) $rowB->getData('net'), 'net must be earned - redeemed - expired = 50 - 30 - 10.');
        self::assertSame(
            110,
            (int) $rowB->getData('outstanding_liability'),
            'Running total must carry Month A\'s 100 forward plus Month B\'s own net of 10.'
        );
        self::assertSame(60.0, (float) $rowB->getData('redemption_rate'), 'redeemed 30 / earned 50 * 100 = 60.0.');

        $rowC = $byMonth[$monthC];
        self::assertSame(0, (int) $rowC->getData('earned'));
        self::assertSame(5, (int) $rowC->getData('expired'));
        self::assertSame(-5, (int) $rowC->getData('net'));
        self::assertSame(105, (int) $rowC->getData('outstanding_liability'));
        self::assertNull(
            $rowC->getData('redemption_rate'),
            'Zero earned in a month must leave redemption_rate null, not a divide-by-zero or a misleading 0.'
        );
    }
}
