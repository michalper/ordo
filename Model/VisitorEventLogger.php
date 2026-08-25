<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Writes one row to the short-lived ordo_visitor_event table (see PruneVisitorEvents for why
 * it's short-lived) and, if the event already has a customer_id (visitor was already
 * identified — logged in, or stitched by StitchVisitorIdentity earlier in the session),
 * immediately runs aggregation so a threshold crossed mid-session tags the customer right
 * away instead of waiting for a batch job.
 */
class VisitorEventLogger
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly VisitorAggregator $visitorAggregator
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
            $this->visitorAggregator->aggregateForCustomer($customerId);
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
    }
}
