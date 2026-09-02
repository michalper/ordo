<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Rfm\Grid;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Backing collection for the standalone RFM report grid (ordo_rfm_listing). Unlike the module's
 * other Grid\Collections this one has no entity table of its own — it reports over
 * customer_entity joined to a live aggregate of sales_order, so the whole thing is built in
 * _initSelect() and paged/sorted by the database rather than assembled in PHP. That's the point:
 * the report has to stay usable on a customer base of tens of thousands of rows, which rules out
 * the "load everything into an array and render" shape the dashboard block uses.
 *
 * SearchResult supports a null resourceModel (getResource() then falls back to
 * ResourceConnection), which is what this uses — there is no Ordo resource model for
 * customer_entity and inventing one just to satisfy the constructor would be worse. mainTable
 * and identifierName come from etc/di.xml; ResourceConnection is injected explicitly so table
 * names still get the installation's table prefix.
 */
class Collection extends SearchResult
{
    /**
     * A customer with no orders at all must still rank — in the worst bucket, not be excluded —
     * so the null last_order_at is folded to a day count far beyond any real one before ranking.
     */
    private const NO_ORDER_RECENCY_DAYS = 999999;

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
        private readonly ResourceConnection $rfmResourceConnection,
        $mainTable = 'customer_entity',
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

    /**
     * customer_entity LEFT JOINed to a grouped sales_order subselect, so every customer appears
     * exactly once — including customers with zero orders, whose frequency/monetary COALESCE to
     * 0 and whose last_order_at stays null.
     *
     * The per-metric quintiles are MySQL 8 NTILE(5) window functions evaluated over the joined
     * result. Each is ordered so quintile 5 is always the *best* fifth of the customer base:
     * highest monetary, highest frequency, most recent (lowest recency_days).
     *
     * Note the window functions and the COALESCE/DATEDIFF expressions are select-list aliases,
     * which MySQL allows in ORDER BY but not in WHERE — so ordo_rfm_listing.xml deliberately
     * offers filters only on the plain customer_entity columns, and leaves the derived columns
     * sortable-but-not-filterable.
     */
    protected function _initSelect(): void
    {
        parent::_initSelect();

        $connection = $this->rfmResourceConnection->getConnection();
        $orderTable = $this->rfmResourceConnection->getTableName('sales_order');

        $aggregate = $connection->select()
            ->from($orderTable, [
                'customer_id' => 'customer_id',
                'frequency' => 'COUNT(*)',
                'monetary' => 'SUM(grand_total)',
                'last_order_at' => 'MAX(created_at)',
            ])
            ->where('state != ?', 'canceled')
            ->where('customer_id IS NOT NULL')
            ->group('customer_id');

        $frequency = 'COALESCE(rfm_agg.frequency, 0)';
        $monetary = 'COALESCE(rfm_agg.monetary, 0)';
        // DATEDIFF is calendar-day arithmetic in the database's timezone, so at a day boundary
        // it can differ by one from RfmCalculator's PHP floor((now - last_order) / 86400). This
        // is a report view, not a matching decision — segment membership is always decided by
        // RfmCalculator, never by this column.
        $recencyDays = 'DATEDIFF(NOW(), rfm_agg.last_order_at)';
        $recencyForRanking = sprintf('COALESCE(%s, %d)', $recencyDays, self::NO_ORDER_RECENCY_DAYS);

        $this->getSelect()->joinLeft(
            ['rfm_agg' => new \Zend_Db_Expr('(' . $aggregate->assemble() . ')')],
            'rfm_agg.customer_id = main_table.entity_id',
            [
                'frequency' => new \Zend_Db_Expr($frequency),
                'monetary' => new \Zend_Db_Expr($monetary),
                'last_order_at' => 'last_order_at',
                'recency_days' => new \Zend_Db_Expr($recencyDays),
                'monetary_quintile' => new \Zend_Db_Expr(
                    sprintf('NTILE(5) OVER (ORDER BY %s ASC)', $monetary)
                ),
                'frequency_quintile' => new \Zend_Db_Expr(
                    sprintf('NTILE(5) OVER (ORDER BY %s ASC)', $frequency)
                ),
                'recency_quintile' => new \Zend_Db_Expr(
                    sprintf('NTILE(5) OVER (ORDER BY %s DESC)', $recencyForRanking)
                ),
                // The standard "555" RFM-notation score (best on all three down to "111"). Can't
                // reference the recency_quintile/frequency_quintile/monetary_quintile aliases
                // above — MySQL doesn't allow one select-list expression to reference another
                // window-function alias in the same SELECT — so this repeats the same three
                // NTILE() window specs inline instead; same result, same query plan MySQL already
                // built for the aliased columns, just evaluated a second time.
                'rfm_score' => new \Zend_Db_Expr(sprintf(
                    'CONCAT(NTILE(5) OVER (ORDER BY %s DESC), NTILE(5) OVER (ORDER BY %s ASC), '
                    . 'NTILE(5) OVER (ORDER BY %s ASC))',
                    $recencyForRanking,
                    $frequency,
                    $monetary
                )),
            ]
        );
    }
}
