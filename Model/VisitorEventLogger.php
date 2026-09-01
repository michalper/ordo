<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Model\Queue\VisitorAggregationPublisher;

/**
 * Writes one row to the short-lived ordo_visitor_event table (see PruneVisitorEvents for why
 * it's short-lived) and publishes a request to check aggregation — against the customer if the
 * event already has a customer_id (visitor was already identified — logged in, or stitched by
 * StitchVisitorIdentity earlier in the session), against the anonymous visitor_id otherwise.
 *
 * Aggregation itself (VisitorAggregator's GROUP BY/HAVING query, plus any resulting tag writes)
 * used to run synchronously right here, inline in the /ordo/track/event request — the same
 * class of problem CampaignDispatcher had before it moved to the queue (see
 * Model\Queue\CampaignDispatchPublisher). VisitorAggregationConsumer now does that work off the
 * request thread; a checkout/tracking request never waits on it.
 */
class VisitorEventLogger
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly VisitorAggregationPublisher $visitorAggregationPublisher
    ) {
    }

    public function log(string $visitorId, string $eventType, ?string $eventKey, ?int $customerId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->resourceConnection->getTableName('ordo_visitor_event'), [
            'visitor_id' => $visitorId,
            'customer_id' => $customerId,
            'event_type' => $eventType,
            'event_key' => $eventKey,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($customerId !== null) {
            $this->visitorAggregationPublisher->publishForCustomer($customerId);
        } else {
            $this->visitorAggregationPublisher->publishForVisitor($visitorId);
        }
    }

    /**
     * Called by StitchVisitorIdentity on login: backfills customer_id onto this visitor's
     * previously-anonymous events, so behavior from before login still counts.
     */
    public function attributeVisitorToCustomer(string $visitorId, int $customerId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName('ordo_visitor_event'),
            ['customer_id' => $customerId],
            ['visitor_id = ?' => $visitorId, 'customer_id IS NULL']
        );

        $this->visitorAggregationPublisher->publishForCustomer($customerId);
    }
}
