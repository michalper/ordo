<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\ProductPerformance\Grid;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Backing collection for the standalone product performance report grid
 * (ordo_productperformance_listing) - same shape as Model\ResourceModel\Rfm\Grid\Collection: no
 * entity table of its own, built entirely in _initSelect() over catalog_product_entity (sku is a
 * flat column there, so no EAV join is needed for it - see this class's own docblock on why name
 * isn't shown). resourceModel is left null (SearchResult's own default) - unlike the RFM grid,
 * this one has no mass action, so there's no Magento\Ui\Component\MassAction\Filter call that
 * would need a real getResource()->getIdFieldName() to fall back on.
 *
 * view_count comes from the materialized ordo_product_performance table (Cron\
 * CalculateProductPerformance keeps it current - see that class's own docblock for why it can't
 * be computed live). purchase_count is joined live from sales_order_item instead, the same
 * sales_order/sales_order_item join Model\Recommendation\ProductRecommender's own
 * rankedBestSellerSkus() already uses, just unlimited/paginated here rather than capped at 200 -
 * that data is never pruned, so "live" and "all-time" mean the same thing for it.
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
        private readonly ResourceConnection $productPerformanceResourceConnection,
        $mainTable = 'catalog_product_entity',
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

        $connection = $this->productPerformanceResourceConnection->getConnection();
        $performanceTable = $this->productPerformanceResourceConnection->getTableName('ordo_product_performance');
        $orderTable = $this->productPerformanceResourceConnection->getTableName('sales_order');
        $orderItemTable = $this->productPerformanceResourceConnection->getTableName('sales_order_item');

        $purchases = $connection->select()
            ->from(['o' => $orderTable], [])
            ->joinInner(
                ['oi' => $orderItemTable],
                'oi.order_id = o.entity_id',
                ['sku' => 'oi.sku', 'purchase_count' => 'SUM(oi.qty_ordered)']
            )
            ->where('o.state != ?', 'canceled')
            ->group('oi.sku');

        $viewCount = 'COALESCE(ordo_pp.view_count, 0)';
        $purchaseCount = 'COALESCE(purchases.purchase_count, 0)';

        $this->getSelect()
            ->joinLeft(
                ['ordo_pp' => $performanceTable],
                'ordo_pp.sku = main_table.sku',
                []
            )
            ->joinLeft(
                ['purchases' => new \Zend_Db_Expr('(' . $purchases->assemble() . ')')],
                'purchases.sku = main_table.sku',
                [
                    'view_count' => new \Zend_Db_Expr($viewCount),
                    'purchase_count' => new \Zend_Db_Expr($purchaseCount),
                    // 1 - purchases/views, store-wide - not a per-visitor funnel. A guest who
                    // never logs in has no customer_id on either ordo_visitor_event or
                    // sales_order (no visitor_id column exists on sales_order at all), so that
                    // slice can never be joined to a purchase regardless of retention window -
                    // see docs/adr/0015 for why the funnel version is infeasible, not just
                    // harder. NULLIF guards the zero-views case (no rate, not a 100% abandon
                    // rate - nobody has seen this product yet).
                    'abandon_rate' => new \Zend_Db_Expr(sprintf(
                        '1 - (%s / NULLIF(%s, 0))',
                        $purchaseCount,
                        $viewCount
                    )),
                ]
            );
    }
}
