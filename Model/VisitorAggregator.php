<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Helper\Config;

/**
 * The bridge between raw, short-lived visitor events and the long-lived tag system: turns
 * "customer #42 viewed category 15 three times" into the tag "viewed_category_view_15" —
 * a handful of bytes that lives in ordo_customer_tag indefinitely, instead of the raw events
 * that caused it, which get pruned within days (see PruneVisitorEvents).
 *
 * Tag cardinality tradeoff, intentional and documented rather than hidden: including the
 * event_key (e.g. a specific category/SKU) in the tag name gives precise targeting
 * ("viewed_category_view_15") but means a store with many categories/products can end up
 * with many distinct tags. A coarser variant ("viewed_category_view", no key) would trade
 * precision for a bounded tag count — left as a config/behavior choice for whoever operates
 * this, not decided here.
 */
class VisitorAggregator
{
    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerTagManager $customerTagManager
    ) {
    }

    public function aggregateForCustomer(int $customerId): void
    {
        if (!$this->config->isTrackingEnabled()) {
            return;
        }

        $threshold = $this->config->getTrackingViewThreshold();
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_visitor_event');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['event_type', 'event_key', 'occurrences' => new \Zend_Db_Expr('COUNT(*)')])
                ->where('customer_id = ?', $customerId)
                ->where('event_key IS NOT NULL')
                ->group(['event_type', 'event_key'])
                ->having('occurrences >= ?', $threshold)
        );

        foreach ($rows as $row) {
            $tag = sprintf('viewed_%s_%s', $row['event_type'], $row['event_key']);
            $this->customerTagManager->addTag($customerId, $tag);
        }
    }
}
