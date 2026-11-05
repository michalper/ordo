<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\CohortRetention\Grid;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Backing collection for the standalone cohort retention report grid (ordo_cohortretention_listing).
 * Unlike every other report grid in this module (RevenueAnalytics/ProductPerformance/
 * CampaignComparison), there is no real per-row entity to build on top of with joins - a row here
 * is a (cohort_month, months_since_first_purchase) pair, a pure aggregate over sales_order with
 * no natural identity of its own. parent::_initSelect() still runs (sets up the base SearchResult
 * machinery against the placeholder $mainTable), but the FROM clause it builds is then thrown away
 * and replaced entirely with a derived subquery - the one deliberate departure from this module's
 * usual "extend the real select, don't replace it" Grid\Collection shape, called out here because
 * it's the only place doing it.
 *
 * Cohort = the calendar month of a customer's own first non-canceled order (guest orders have no
 * identity to cohort by, so they're excluded entirely, same reasoning RevenueAnalytics\Grid\
 * Collection's "is_new_customer" join gives for why guests fall out of its own first-order join).
 * "Retained" for a given (cohort, N) pair = placed at least one more non-canceled order in the
 * calendar month N months after their own first order - MySQL's PERIOD_DIFF() on 'YYYYMM' values
 * does the month arithmetic, so N=0 is always 100% by definition (everyone in a cohort ordered in
 * their own first month). No upper bound on N is imposed here - an old store naturally has more
 * (cohort, N) rows for its oldest cohorts; the grid's own pagination/filtering handles that, not a
 * hardcoded window.
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
        private readonly ResourceConnection $cohortRetentionResourceConnection,
        $mainTable = 'sales_order',
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

        $connection = $this->cohortRetentionResourceConnection->getConnection();
        $orderTable = $this->cohortRetentionResourceConnection->getTableName('sales_order');

        $firstOrders = $connection->select()
            ->from($orderTable, [
                'customer_id',
                'cohort_month' => new \Zend_Db_Expr("DATE_FORMAT(MIN(created_at), '%Y-%m-01')"),
            ])
            ->where('state != ?', 'canceled')
            ->where('customer_id IS NOT NULL')
            ->group('customer_id');

        $orderMonths = $connection->select()
            ->distinct()
            ->from($orderTable, [
                'customer_id',
                'order_month' => new \Zend_Db_Expr("DATE_FORMAT(created_at, '%Y-%m-01')"),
            ])
            ->where('state != ?', 'canceled')
            ->where('customer_id IS NOT NULL');

        $cohortSizes = $connection->select()
            ->from(
                ['fo' => new \Zend_Db_Expr('(' . $firstOrders->assemble() . ')')],
                ['cohort_month', 'cohort_size' => new \Zend_Db_Expr('COUNT(DISTINCT customer_id)')]
            )
            ->group('cohort_month');

        $retention = $connection->select()
            ->from(['fo' => new \Zend_Db_Expr('(' . $firstOrders->assemble() . ')')], ['cohort_month'])
            ->joinInner(
                ['om' => new \Zend_Db_Expr('(' . $orderMonths->assemble() . ')')],
                'om.customer_id = fo.customer_id',
                [
                    'months_since_first_purchase' => new \Zend_Db_Expr(
                        "PERIOD_DIFF(DATE_FORMAT(om.order_month, '%Y%m'), DATE_FORMAT(fo.cohort_month, '%Y%m'))"
                    ),
                ]
            )
            ->columns(['active_customers' => new \Zend_Db_Expr('COUNT(DISTINCT om.customer_id)')])
            ->group(['fo.cohort_month', 'months_since_first_purchase']);

        $cohort = $connection->select()
            ->from(
                ['r' => new \Zend_Db_Expr('(' . $retention->assemble() . ')')],
                [
                    'entity_id' => new \Zend_Db_Expr("CONCAT(r.cohort_month, ':', r.months_since_first_purchase)"),
                    'cohort_month' => 'r.cohort_month',
                    'months_since_first_purchase' => 'r.months_since_first_purchase',
                    'active_customers' => 'r.active_customers',
                ]
            )
            ->joinInner(
                ['cs' => new \Zend_Db_Expr('(' . $cohortSizes->assemble() . ')')],
                'cs.cohort_month = r.cohort_month',
                [
                    'cohort_size' => 'cs.cohort_size',
                    'retention_rate' => new \Zend_Db_Expr('ROUND((r.active_customers / cs.cohort_size) * 100, 1)'),
                ]
            );

        $this->getSelect()->reset(Select::FROM);
        $this->getSelect()->from(['main_table' => new \Zend_Db_Expr('(' . $cohort->assemble() . ')')]);
    }
}
