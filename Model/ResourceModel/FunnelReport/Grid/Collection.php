<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\FunnelReport\Grid;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Backing collection for the standalone purchase funnel report grid (ordo_funnelreport_listing) -
 * one row per calendar day, extending the real ordo_funnel_daily_stats table with joins, same
 * "extend the real select, don't replace it" shape Model\ResourceModel\ProductPerformance\Grid\
 * Collection already uses (unlike Model\ResourceModel\CohortRetention\Grid\Collection, which has
 * no backing table of its own and is the one deliberate exception to this).
 *
 * product_view_visitors/cart_add_visitors/checkout_start_visitors come straight from
 * ordo_funnel_daily_stats (Cron\CalculateFunnelMetrics keeps it current - see that class's own
 * docblock for why it can't be computed live, same reasoning ordo_product_performance already
 * established). completed_order_visitors is joined live from sales_order instead - that data is
 * never pruned, so "live" and "all-time" mean the same thing for it, the same precedent
 * ProductPerformance\Grid\Collection already set for purchase_count.
 *
 * Every stage here (including the live-joined one) is scoped to customer_id IS NOT NULL - see
 * docs/adr/0031 for why that scope has to be uniform across every stage, not just the last one,
 * or the drop-off percentages between stages would be structurally meaningless.
 */
class Collection extends SearchResult
{
    /**
     * @param string $mainTable
     * @param string|null $resourceModel
     * @param string|null $identifierName
     * @param string|null $connectionName
     */
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        private readonly ResourceConnection $funnelReportResourceConnection,
        $mainTable = 'ordo_funnel_daily_stats',
        $resourceModel = null,
        $identifierName = 'entity_id',
        $connectionName = null
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $mainTable,
            $resourceModel,
            $identifierName,
            $connectionName
        );
    }

    protected function _initSelect(): void
    {
        parent::_initSelect();

        $connection = $this->funnelReportResourceConnection->getConnection();
        $orderTable = $this->funnelReportResourceConnection->getTableName('sales_order');

        $completedOrders = $connection->select()
            ->from($orderTable, [
                'date' => new \Zend_Db_Expr('DATE(created_at)'),
                'completed_order_visitors' => new \Zend_Db_Expr('COUNT(DISTINCT customer_id)'),
            ])
            ->where('customer_id IS NOT NULL')
            ->where('state != ?', 'canceled')
            ->group(new \Zend_Db_Expr('DATE(created_at)'));

        $completedOrderVisitors = 'COALESCE(orders.completed_order_visitors, 0)';

        $this->getSelect()
            ->joinLeft(
                ['orders' => new \Zend_Db_Expr('(' . $completedOrders->assemble() . ')')],
                'orders.date = main_table.date',
                ['completed_order_visitors' => new \Zend_Db_Expr($completedOrderVisitors)]
            )
            ->columns([
                // ROUND((1 - next_stage / NULLIF(this_stage, 0)) * 100, 1) - percentage points,
                // same convention CohortRetention\Grid\Collection's own retention_rate column
                // already uses (store the already-multiplied value, label the column "(%)"), not
                // a raw 0..1 fraction. NULLIF guards the zero-visitor case (no rate to show, not
                // a 100% drop-off - nobody reached this stage at all that day), same guard
                // ProductPerformance\Grid\Collection's own abandon_rate uses.
                'cart_add_drop_off_rate' => new \Zend_Db_Expr(
                    'ROUND((1 - (main_table.cart_add_visitors / NULLIF(main_table.product_view_visitors, 0))) * 100, 1)'
                ),
                'checkout_start_drop_off_rate' => new \Zend_Db_Expr(
                    'ROUND((1 - (main_table.checkout_start_visitors / NULLIF(main_table.cart_add_visitors, 0))) * 100, 1)'
                ),
                'completed_order_drop_off_rate' => new \Zend_Db_Expr(sprintf(
                    'ROUND((1 - (%s / NULLIF(main_table.checkout_start_visitors, 0))) * 100, 1)',
                    $completedOrderVisitors
                )),
            ]);
    }

    /**
     * "date" alone is ambiguous once the live sales_order-derived "orders" join is in the select
     * (both main_table and that subquery alias have their own "date" column) - a plain
     * addFieldToFilter('date', ...) (what the grid's own dateRange filter UI calls under the
     * hood) would otherwise hit a real SQLSTATE 23000 "Column 'date' in where clause is
     * ambiguous" error. Same override shape Model\ResourceModel\MessageLog\Grid\Collection
     * already establishes for a column name that needs qualifying instead of the generic
     * WHERE-on-the-plain-name every other column filter gets.
     *
     * @param string|array<int|string, mixed> $field
     * @param string|array<string, mixed>|null $condition
     * @return $this
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if ($field === 'date' && $condition !== null) {
            $this->getSelect()->where(
                $this->getConnection()->prepareSqlCondition('main_table.date', $condition)
            );

            return $this;
        }

        return parent::addFieldToFilter($field, $condition);
    }
}
