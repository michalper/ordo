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

    /**
     * utm_source/utm_campaign (docs/adr/0085) always tag on the first occurrence - a hardcoded
     * threshold of 1, deliberately NOT getTrackingClickThreshold() (admin-configurable, default
     * 1 today, but tuned for a different signal - "did they click something on the page", not
     * "how did they arrive"). A UTM param normally appears on exactly one landing URL per visit
     * (Magento doesn't carry query params forward across internal navigation), so requiring more
     * than one occurrence would mean this would effectively never tag at all.
     */
    private const array IMMEDIATE_EVENT_TYPES = ['utm_source', 'utm_campaign'];

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
                ->where('created_at >= ?', $this->retentionCutoff())
                ->group(['event_type', 'event_key'])
        );

        $tags = $this->overThresholdTags($rows);
        if ($tags === []) {
            return;
        }

        // One getTags() read instead of addTag()'s internal hasTag() SELECT per row — a visitor
        // who already earned their tags otherwise re-pays one query per held tag on every event.
        $existing = array_flip($this->customerTagManager->getTags($customerId));
        foreach ($tags as $tag) {
            if (!isset($existing[$tag])) {
                $this->customerTagManager->addTag($customerId, $tag);
            }
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
                ->where('created_at >= ?', $this->retentionCutoff())
                ->group(['event_type', 'event_key'])
        );

        $tags = $this->overThresholdTags($rows);
        if ($tags === []) {
            return;
        }

        // Same existing-tags diff as aggregateForCustomer() — see the comment there.
        $existing = array_flip($this->visitorTagManager->getTags($visitorId));
        foreach ($tags as $tag) {
            if (!isset($existing[$tag])) {
                $this->visitorTagManager->addTag($visitorId, $tag);
            }
        }
    }

    /**
     * @param array<int, array{event_type: string, event_key: string, occurrences: int|string}> $rows
     * @return string[]
     */
    private function overThresholdTags(array $rows): array
    {
        $tags = [];

        foreach ($rows as $row) {
            if ((int) $row['occurrences'] < $this->resolveThreshold($row['event_type'])) {
                continue;
            }

            $tags[] = $this->buildTag($row);
        }

        return $tags;
    }

    /**
     * Bounds both aggregation queries to Config::getTrackingRetentionDays() - the same window
     * Cron\PruneVisitorEvents already enforces, so this never scans a row that's about to be (or
     * already was) deleted anyway. Without this, every single tracked event re-ran a full,
     * ever-growing GROUP BY over that customer/visitor's entire retained history - the one
     * aggregation query in this module with no date bound at all, unlike every scan cron (see
     * CLAUDE.md's own "everything is a cron-driven scan against *_at claim columns" note).
     * Safe regardless of threshold tuning: tags are additive/sticky (this class's own docblock),
     * never revoked once earned, so a customer who already crossed a threshold using
     * since-pruned events keeps their tag - this only bounds what COUNT(*) has to scan, not
     * which tags a customer can ever hold.
     */
    private function retentionCutoff(): string
    {
        $retentionDays = $this->config->getTrackingRetentionDays();

        return date('Y-m-d H:i:s', (int) strtotime("-{$retentionDays} days"));
    }

    /**
     * Clicks are a higher-intent signal than views, so they get their own (lower by default)
     * threshold — see Config::getTrackingClickThreshold()'s docblock.
     */
    private function resolveThreshold(string $eventType): int
    {
        if (in_array($eventType, self::IMMEDIATE_EVENT_TYPES, true)) {
            return 1;
        }

        return in_array($eventType, self::CLICK_EVENT_TYPES, true)
            ? $this->config->getTrackingClickThreshold()
            : $this->config->getTrackingViewThreshold();
    }

    /**
     * @param array{event_type: string, event_key: string, occurrences: int|string} $row
     */
    private function buildTag(array $row): string
    {
        if (in_array($row['event_type'], self::IMMEDIATE_EVENT_TYPES, true)) {
            // Lowercased - ad platforms are inconsistent about utm param casing
            // ("Google"/"GOOGLE"/"google"), and this tag is meant to be matched against by a
            // campaign/segment's own tag condition, where a predictable, single casing matters
            // more than preserving whatever the raw click happened to carry.
            return sprintf('%s_%s', $row['event_type'], strtolower((string) $row['event_key']));
        }

        if (in_array($row['event_type'], self::CLICK_EVENT_TYPES, true)) {
            return sprintf('clicked_%s', $row['event_key']);
        }

        return sprintf('viewed_%s_%s', $row['event_type'], $row['event_key']);
    }
}
