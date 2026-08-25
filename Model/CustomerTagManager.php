<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;

/**
 * Behavioral tagging, the same primitive general-purpose MA platforms (SalesManago, iPresso,
 * Klaviyo...) build their segmentation on: a customer either has a tag or doesn't, and every
 * trigger/segment in this module reads or writes tags instead of inventing its own ad-hoc flag.
 * Kept intentionally dumb — no rule engine, just add/remove/check — so it stays easy to reason
 * about and easy to extend later (a rule-based auto-tagger is just another cron that calls this).
 *
 * Fires the "ordo_customer_tag_added" Magento event (not a direct CampaignDispatcher call) when
 * a new tag is actually added — CampaignDispatcher's HasTag condition itself depends on this
 * class, so calling it directly here would create a DI cycle. Going through Magento's own event
 * manager, with a thin observer on the other end, breaks the cycle the idiomatic way.
 */
class CustomerTagManager
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EventManagerInterface $eventManager
    ) {
    }

    public function addTag(int $customerId, string $tag): void
    {
        if ($this->hasTag($customerId, $tag)) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->resourceConnection->getTableName('ordo_customer_tag'), [
            'customer_id' => $customerId,
            'tag' => $tag,
            'added_at' => date('Y-m-d H:i:s'),
        ]);

        $this->eventManager->dispatch('ordo_customer_tag_added', ['customer_id' => $customerId, 'tag' => $tag]);
    }

    public function removeTag(int $customerId, string $tag): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->delete(
            $this->resourceConnection->getTableName('ordo_customer_tag'),
            ['customer_id = ?' => $customerId, 'tag = ?' => $tag]
        );
    }

    public function hasTag(int $customerId, string $tag): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_customer_tag');

        $count = $connection->fetchOne(
            $connection->select()
                ->from($table, 'COUNT(*)')
                ->where('customer_id = ?', $customerId)
                ->where('tag = ?', $tag)
        );

        return (int) $count > 0;
    }

    /**
     * @return string[]
     */
    public function getTags(int $customerId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_customer_tag');

        return $connection->fetchCol(
            $connection->select()
                ->from($table, 'tag')
                ->where('customer_id = ?', $customerId)
        );
    }

    /**
     * All customers currently carrying a given tag — the basic building block for targeting
     * a campaign at a segment ("send this to everyone tagged 'vip'").
     *
     * @return int[]
     */
    public function getCustomerIdsWithTag(string $tag): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_customer_tag');

        $ids = $connection->fetchCol(
            $connection->select()
                ->from($table, 'customer_id')
                ->where('tag = ?', $tag)
        );

        return array_map('intval', $ids);
    }
}
