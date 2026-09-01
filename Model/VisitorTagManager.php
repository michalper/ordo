<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;

/**
 * CustomerTagManager's counterpart for anonymous, never-logged-in visitors — same primitive
 * (a visitor either has a tag or doesn't), keyed by visitor_id instead of customer_id, since an
 * anonymous visitor has no customer row to attach a tag to yet. Kept as its own table/class
 * rather than making customer_id nullable on ordo_customer_tag: every existing reader of that
 * table (CustomerTagManagement API, HasTag condition, AddTag action) is written against a real,
 * non-nullable customer_id, and changing that contract to "customer_id OR visitor_id" would touch
 * all of them for a distinction (identified vs. anonymous) that's cleaner kept as two tables.
 *
 * Fires "ordo_visitor_tag_added" (not a direct CampaignDispatcher call) for the same reason
 * CustomerTagManager fires "ordo_customer_tag_added" — see that class's doc comment.
 */
class VisitorTagManager
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EventManagerInterface $eventManager
    ) {
    }

    public function addTag(string $visitorId, string $tag): void
    {
        if ($this->hasTag($visitorId, $tag)) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->resourceConnection->getTableName('ordo_visitor_tag'), [
            'visitor_id' => $visitorId,
            'tag' => $tag,
            'added_at' => date('Y-m-d H:i:s'),
        ]);

        $this->eventManager->dispatch('ordo_visitor_tag_added', ['visitor_id' => $visitorId, 'tag' => $tag]);
    }

    public function removeTag(string $visitorId, string $tag): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->delete(
            $this->resourceConnection->getTableName('ordo_visitor_tag'),
            ['visitor_id = ?' => $visitorId, 'tag = ?' => $tag]
        );
    }

    public function hasTag(string $visitorId, string $tag): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_visitor_tag');

        $count = $connection->fetchOne(
            $connection->select()
                ->from($table, 'COUNT(*)')
                ->where('visitor_id = ?', $visitorId)
                ->where('tag = ?', $tag)
        );

        return (int) $count > 0;
    }

    /**
     * @return string[]
     */
    public function getTags(string $visitorId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_visitor_tag');

        return $connection->fetchCol(
            $connection->select()
                ->from($table, 'tag')
                ->where('visitor_id = ?', $visitorId)
        );
    }
}
