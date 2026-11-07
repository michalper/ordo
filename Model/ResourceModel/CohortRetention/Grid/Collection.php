<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\CohortRetention\Grid;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
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
 *
 * Optional acquisition-campaign narrowing (docs/adr/0029, closing ADR 0023's own open point):
 * addFieldToFilter('acquisition_campaign_id', $campaignId) rebuilds the whole pipeline via
 * buildCohortSelect() instead of adding a plain WHERE onto the already-aggregated final select -
 * a post-hoc WHERE would only hide output rows, not actually recompute cohort_size/
 * retention_rate against the narrowed customer set, which is the entire point of the filter (see
 * that method's own docblock). ordo_campaign_attribution is multi-touch (an order can have more
 * than one row), so a customer's first order is attributed to exactly ONE campaign here - whichever
 * one has the highest attributed_revenue for that order (MySQL ROW_NUMBER(), ties broken by the
 * lower campaign_id for a stable, deterministic pick) - crediting every touched campaign would
 * double-count the same customer across multiple cohort buckets and distort retention_rate.
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
        $this->buildCohortSelect(null);
    }

    /**
     * addFieldToFilter() runs AFTER _initSelect() has already assembled the full, unfiltered
     * pipeline below - by that point the inner subqueries are already baked into literal SQL
     * strings via assemble(), so a plain WHERE added onto the OUTER select here could only ever
     * hide rows after cohort_size/retention_rate were already computed from the full customer
     * set, not actually recompute them against the narrowed one. Since that recomputation is the
     * entire point of this filter (see buildCohortSelect()'s own docblock), this rebuilds the
     * whole select from scratch instead of delegating to the generic per-field WHERE every other
     * column filter gets.
     *
     * @param string|array<int|string, mixed> $field
     * @param string|array<string, mixed>|null $condition
     * @return $this
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if ($field === 'acquisition_campaign_id' && $condition !== null) {
            $campaignId = (int) (is_array($condition) ? ($condition['eq'] ?? reset($condition)) : $condition);
            $this->buildCohortSelect($campaignId > 0 ? $campaignId : null);

            return $this;
        }

        return parent::addFieldToFilter($field, $condition);
    }

    /**
     * $campaignId narrows $firstOrders to customers whose own first order is attributed (via
     * ordo_campaign_attribution) to that specific campaign, before cohort_size/retention_rate are
     * computed from it - with $campaignId null this is byte-for-byte the same pipeline
     * _initSelect() always built before this filter existed, so "no filter" stays exactly
     * today's behavior.
     */
    private function buildCohortSelect(?int $campaignId): void
    {
        $connection = $this->cohortRetentionResourceConnection->getConnection();
        $orderTable = $this->cohortRetentionResourceConnection->getTableName('sales_order');

        $firstOrders = $connection->select()
            ->from($orderTable, [
                'customer_id',
                'first_order_id' => new \Zend_Db_Expr('MIN(entity_id)'),
                'cohort_month' => new \Zend_Db_Expr("DATE_FORMAT(MIN(created_at), '%Y-%m-01')"),
            ])
            ->where('state != ?', 'canceled')
            ->where('customer_id IS NOT NULL')
            ->group('customer_id');

        if ($campaignId !== null) {
            $firstOrders = $this->narrowToAcquisitionCampaign($connection, $firstOrders, $campaignId);
        }

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

    /**
     * ordo_campaign_attribution is multi-touch (AttributionCalculator can credit more than one
     * campaign per order, see that class's own docblock) - ROW_NUMBER() picks exactly one,
     * whichever has the highest attributed_revenue for that order (ties broken by the lower
     * campaign_id, for a stable and deterministic pick), so a customer's first order - and so the
     * customer themself - is never counted into more than one acquisition-campaign bucket. The
     * alternative (crediting every campaign that touched the order) was rejected: it would let
     * the same customer inflate more than one campaign's cohort_size/retention_rate at once,
     * which is a worse distortion than picking a single, deterministic "main" campaign per order.
     */
    private function narrowToAcquisitionCampaign(
        AdapterInterface $connection,
        Select $firstOrders,
        int $campaignId
    ): Select {
        $attributionTable = $this->cohortRetentionResourceConnection->getTableName('ordo_campaign_attribution');

        $rankedAttribution = '(SELECT order_id, campaign_id, ROW_NUMBER() OVER ('
            . 'PARTITION BY order_id ORDER BY attributed_revenue DESC, campaign_id ASC'
            . ') AS rn FROM ' . $connection->quoteIdentifier($attributionTable) . ')';

        $firstOrderCampaign = $connection->select()
            ->from(['ranked' => new \Zend_Db_Expr($rankedAttribution)], ['order_id', 'campaign_id'])
            ->where('ranked.rn = ?', 1);

        return $connection->select()
            ->from(
                ['fo' => new \Zend_Db_Expr('(' . $firstOrders->assemble() . ')')],
                ['customer_id', 'cohort_month', 'first_order_id']
            )
            ->joinInner(
                ['foc' => new \Zend_Db_Expr('(' . $firstOrderCampaign->assemble() . ')')],
                'foc.order_id = fo.first_order_id',
                []
            )
            ->where('foc.campaign_id = ?', $campaignId);
    }
}
