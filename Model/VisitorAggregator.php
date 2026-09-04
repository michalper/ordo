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
    /**
     * Event types whose tag reads as "clicked_X" rather than the default "viewed_TYPE_X" — a
     * click is a distinct kind of signal from a view, and "viewed_element_clicked_X" reads
     * oddly. Also the event types that use getTrackingClickThreshold() instead of
     * getTrackingViewThreshold() below (see resolveThreshold()).
     */
    private const array CLICK_EVENT_TYPES = ['element_clicked'];

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerTagManager $customerTagManager,
        private readonly VisitorTagManager $visitorTagManager
    ) {
    }

    public function aggregateForCustomer(int $customerId): void
    {
        if (!$this->config->isTrackingEnabled()) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_visitor_event');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['event_type', 'event_key', 'occurrences' => new \Zend_Db_Expr('COUNT(*)')])
                ->where('customer_id = ?', $customerId)
                ->where('event_key IS NOT NULL')
                ->group(['event_type', 'event_key'])
        );

        /** @var array{event_type: string, event_key: string, occurrences: int|string} $row */
        foreach ($rows as $row) {
            if ((int) $row['occurrences'] < $this->resolveThreshold($row['event_type'])) {
                continue;
            }

            $this->customerTagManager->addTag($customerId, $this->buildTag($row));
        }
    }

    /**
     * Same aggregation as aggregateForCustomer(), for a visitor who has never logged in —
     * without this, an anonymous visitor's behavior only ever gets tagged retroactively at
     * login (StitchVisitorIdentity), which defeats "real-time" for anyone who converts by
     * registering/checking out as guest without ever having logged in mid-session, and gives
     * no signal at all for a visitor who never converts.
     */
    public function aggregateForVisitor(string $visitorId): void
    {
        if (!$this->config->isTrackingEnabled()) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_visitor_event');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['event_type', 'event_key', 'occurrences' => new \Zend_Db_Expr('COUNT(*)')])
                ->where('visitor_id = ?', $visitorId)
                ->where('customer_id IS NULL')
                ->where('event_key IS NOT NULL')
                ->group(['event_type', 'event_key'])
        );

        /** @var array{event_type: string, event_key: string, occurrences: int|string} $row */
        foreach ($rows as $row) {
            if ((int) $row['occurrences'] < $this->resolveThreshold($row['event_type'])) {
                continue;
            }

            $this->visitorTagManager->addTag($visitorId, $this->buildTag($row));
        }
    }

    /**
     * Clicks are a higher-intent signal than views, so they get their own (lower by default)
     * threshold — see Config::getTrackingClickThreshold()'s docblock.
     */
    private function resolveThreshold(string $eventType): int
    {
        return in_array($eventType, self::CLICK_EVENT_TYPES, true)
            ? $this->config->getTrackingClickThreshold()
            : $this->config->getTrackingViewThreshold();
    }

    /**
     * @param array{event_type: string, event_key: string, occurrences: int|string} $row
     */
    private function buildTag(array $row): string
    {
        if (in_array($row['event_type'], self::CLICK_EVENT_TYPES, true)) {
            return sprintf('clicked_%s', $row['event_key']);
        }

        return sprintf('viewed_%s_%s', $row['event_type'], $row['event_key']);
    }
}
