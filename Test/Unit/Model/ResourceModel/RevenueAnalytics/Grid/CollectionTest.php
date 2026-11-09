<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\RevenueAnalytics\Grid;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\ResourceModel\RevenueAnalytics\Grid\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Same ObjectManager-singleton-stubbing technique MessageLog\Grid\CollectionTest already
 * establishes for a SearchResult-based grid collection, extended for a _initSelect() with
 * multiple subselects/joins: each connection->select() call returns a FRESH stub in the exact
 * order _initSelect() makes them (parent::_initSelect() first, for the main select, then one per
 * subselect), via willReturnOnConsecutiveCalls - joinLeft is recorded (not asserted inline via
 * with()) since PHPUnit 10+ dropped withConsecutive, the only way to assert N different calls to
 * the same mocked method.
 */
class CollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    public function testInitSelectExcludesCanceledOrdersAndJoinsAttributionAndNewCustomerFlag(): void
    {
        $mainSelect = $this->createMock(Select::class);
        $mainSelect->method('from')->willReturnSelf();
        $mainSelect->expects(self::once())->method('where')
            ->with('main_table.state != ?', 'canceled')->willReturnSelf();
        $joinCalls = [];
        $mainSelect->method('joinLeft')->willReturnCallback(
            function (...$args) use (&$joinCalls, $mainSelect) {
                $joinCalls[] = $args;
                return $mainSelect;
            }
        );
        $mainSelect->expects(self::once())->method('columns')
            ->with(['revenue' => 'main_table.base_grand_total'])->willReturnSelf();

        $firstOrdersSelect = $this->createMock(Select::class);
        $firstOrdersSelect->expects(self::once())->method('from')->with(
            'sales_order',
            self::callback(
                fn (array $cols) => $cols[0] === 'customer_id'
                    && (string) $cols['first_order_id'] === 'MIN(entity_id)'
            )
        )->willReturnSelf();
        $firstOrdersWhereArgs = [];
        $firstOrdersSelect->method('where')->willReturnCallback(
            function (...$args) use (&$firstOrdersWhereArgs, $firstOrdersSelect) {
                $firstOrdersWhereArgs[] = $args;
                return $firstOrdersSelect;
            }
        );
        $firstOrdersSelect->expects(self::once())->method('group')->with('customer_id')->willReturnSelf();
        $firstOrdersSelect->method('assemble')->willReturn('FIRST_ORDERS_SQL');

        $primaryOutcomeSelect = $this->createMock(Select::class);
        $primaryOutcomeSelect->expects(self::once())->method('from')->with(
            'ordo_campaign_outcome_log',
            self::callback(
                fn (array $cols) => $cols[0] === 'order_id'
                    && (string) $cols['campaign_id'] === 'MIN(campaign_id)'
            )
        )->willReturnSelf();
        $primaryOutcomeSelect->expects(self::once())->method('where')
            ->with('order_id IS NOT NULL')->willReturnSelf();
        $primaryOutcomeSelect->expects(self::once())->method('group')->with('order_id')->willReturnSelf();
        $primaryOutcomeSelect->method('assemble')->willReturn('PRIMARY_OUTCOME_SQL');

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnOnConsecutiveCalls(
            // SearchResult::__construct() builds the main select lazily via two separate
            // getSelect() accesses (setMainTable(true) for the initial FROM part, then the real
            // table name) before _initSelect() ever runs - confirmed directly by instrumenting
            // connection->select() with a call logger. Both must resolve to the SAME mock.
            $mainSelect,
            $mainSelect,
            $firstOrdersSelect,
            $primaryOutcomeSelect
        );

        $this->buildAndConstruct($connection);

        // Covers the "a guest/returning order's own first_orders join fires the new-vs-returning
        // flag correctly, and canceled orders are excluded from the one-row-per-customer
        // bucketing itself" business logic the class's own docblock calls out.
        self::assertSame(['state != ?', 'canceled', null], $firstOrdersWhereArgs[0]);
        self::assertSame(['customer_id IS NOT NULL', null, null], $firstOrdersWhereArgs[1]);

        self::assertCount(7, $joinCalls);

        [$aliases, $ons, $cols] = [[], [], []];
        foreach ($joinCalls as $call) {
            $aliases[] = array_key_first($call[0]);
            $ons[] = $call[1];
            $cols[] = $call[2];
        }

        self::assertSame(
            ['billing_address', 'outcome', 'campaign', 'generated_coupon', 'coupon_campaign_log', 'coupon_campaign', 'first_orders'],
            $aliases
        );

        self::assertSame('sales_order_address', $joinCalls[0][0]['billing_address']);
        self::assertSame(
            'billing_address.parent_id = main_table.entity_id AND billing_address.address_type = \'billing\'',
            $ons[0]
        );
        self::assertSame(['country_id' => 'billing_address.country_id', 'city' => 'billing_address.city'], $cols[0]);

        // outcome join embeds the primaryOutcome subselect's own assembled SQL, not some other one.
        self::assertSame('(PRIMARY_OUTCOME_SQL)', (string) $joinCalls[1][0]['outcome']);
        self::assertSame('outcome.order_id = main_table.entity_id', $ons[1]);
        self::assertSame([], $cols[1]);

        self::assertSame('ordo_campaign', $joinCalls[2][0]['campaign']);
        self::assertSame('campaign.entity_id = outcome.campaign_id', $ons[2]);
        self::assertSame(['campaign_name' => 'campaign.name'], $cols[2]);

        self::assertSame('salesrule_coupon', $joinCalls[3][0]['generated_coupon']);
        self::assertSame('generated_coupon.code = main_table.coupon_code', $ons[3]);
        self::assertSame([], $cols[3]);

        self::assertSame('ordo_coupon_campaign_log', $joinCalls[4][0]['coupon_campaign_log']);
        self::assertSame('coupon_campaign_log.coupon_id = generated_coupon.coupon_id', $ons[4]);
        self::assertSame([], $cols[4]);

        self::assertSame('ordo_campaign', $joinCalls[5][0]['coupon_campaign']);
        self::assertSame('coupon_campaign.entity_id = coupon_campaign_log.campaign_id', $ons[5]);
        self::assertSame(['coupon_campaign_name' => 'coupon_campaign.name'], $cols[5]);

        // first_orders join embeds the firstOrders subselect's own assembled SQL - proves the
        // new-vs-returning flag is actually wired to the right subquery, not a copy-paste of the
        // outcome one.
        self::assertSame('(FIRST_ORDERS_SQL)', (string) $joinCalls[6][0]['first_orders']);
        self::assertSame('first_orders.customer_id = main_table.customer_id', $ons[6]);
        self::assertSame(
            'main_table.customer_id IS NULL OR first_orders.first_order_id = main_table.entity_id',
            (string) $cols[6]['is_new_customer']
        );
    }

    private function buildAndConstruct(AdapterInterface $connection): Collection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(
            static fn ($table) => is_string($table) ? $table : 'sales_order'
        );

        $resourceModel = $this->createStub(AbstractDb::class);
        $resourceModel->method('getConnection')->willReturn($connection);
        $resourceModel->method('getIdFieldName')->willReturn('entity_id');

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($resourceConnection);
        $objectManager->method('create')->willReturn($resourceModel);
        ObjectManager::setInstance($objectManager);

        return new Collection(
            $this->createStub(EntityFactoryInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(FetchStrategyInterface::class),
            $this->createStub(ManagerInterface::class),
            $resourceConnection
        );
    }
}
