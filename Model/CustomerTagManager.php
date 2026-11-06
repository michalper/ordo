<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Ordo\Automation\Api\CustomerTagManagementInterface;

/**
 * Behavioral tagging, the same primitive general-purpose MA platforms build their segmentation
 * on: a customer either has a tag or doesn't, and every
 * trigger/segment in this module reads or writes tags instead of inventing its own ad-hoc flag.
 * Kept intentionally dumb — no rule engine, just add/remove/check — so it stays easy to reason
 * about and easy to extend later (a rule-based auto-tagger is just another cron that calls this).
 *
 * Fires the "ordo_customer_tag_added" Magento event (not a direct CampaignDispatcher call) when
 * a new tag is actually added — CampaignDispatcher's HasTag condition itself depends on this
 * class, so calling it directly here would create a DI cycle. Going through Magento's own event
 * manager, with a thin observer on the other end, breaks the cycle the idiomatic way.
 */
class CustomerTagManager implements CustomerTagManagementInterface
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

        $connection = $this->connection();
        $connection->insert($this->table(), [
            'customer_id' => $customerId,
            'tag' => $tag,
            'added_at' => date('Y-m-d H:i:s'),
        ]);

        $this->eventManager->dispatch('ordo_customer_tag_added', ['customer_id' => $customerId, 'tag' => $tag]);
    }

    public function removeTag(int $customerId, string $tag): void
    {
        $connection = $this->connection();
        $connection->delete(
            $this->table(),
            ['customer_id = ?' => $customerId, 'tag = ?' => $tag]
        );
    }

    public function hasTag(int $customerId, string $tag): bool
    {
        $connection = $this->connection();
        $table = $this->table();

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
        $connection = $this->connection();
        $table = $this->table();

        return $connection->fetchCol(
            $connection->select()
                ->from($table, 'tag')
                ->where('customer_id = ?', $customerId)
        );
    }

    /**
     * Batch variant of hasTag() - one query for the whole set instead of one per customer, for
     * crons (TagInactiveCustomers, SendWinBackEmails) that otherwise call hasTag() once per
     * candidate inside a loop that can run to thousands of rows.
     *
     * @param int[] $customerIds
     * @return int[] the subset of $customerIds that already carry $tag
     */
    public function getCustomerIdsWithTagFromSet(array $customerIds, string $tag): array
    {
        if ($customerIds === []) {
            return [];
        }

        $connection = $this->connection();
        $table = $this->table();

        $ids = $connection->fetchCol(
            $connection->select()
                ->from($table, 'customer_id')
                ->where('customer_id IN (?)', $customerIds)
                ->where('tag = ?', $tag)
        );

        return array_map('intval', $ids);
    }

    /**
     * Distinct existing tag values matching a search term - backs the admin autocomplete
     * (Controller\Adminhtml\TagSearch) for the Has Tag / Visitor Has Tag / add_tag fields, so a
     * merchant picks from real values instead of retyping one that already exists with a typo.
     * Intentionally does not also search ordo_visitor_tag - Visitor Has Tag's own suggestions
     * come from the same customer-tag vocabulary a merchant already uses everywhere else (tags
     * added by Visitor Has Tag-adjacent crons/actions overwhelmingly end up as customer tags too
     * once a visitor converts), and a merged query would need a second table scan for a dataset
     * this module's own tagging crons never actually keep disjoint in practice.
     *
     * @return string[]
     */
    public function searchTags(string $term, int $limit = 20): array
    {
        if ($term === '') {
            return [];
        }

        $connection = $this->connection();
        $table = $this->table();

        // distinct() is called as its own statement, not chained - 'DISTINCT tag' as a bare
        // column string gets backtick-quoted as a literal identifier ("DISTINCT"."tag") instead
        // of being parsed as SQL, producing a real "column not found" error (confirmed against a
        // real database, not just a lint concern) - same reasoning as having()'s own comment in
        // getCustomerIdsWithAllTags() below for why it isn't chained either.
        $select = $connection->select()
            ->from($table, 'tag')
            ->where('tag LIKE ?', '%' . $term . '%')
            ->order('tag ASC')
            ->limit($limit);
        $select->distinct();

        return $connection->fetchCol($select);
    }

    /**
     * All customers currently carrying a given tag — the basic building block for targeting
     * a campaign at a segment ("send this to everyone tagged 'vip'").
     *
     * @return int[]
     */
    public function getCustomerIdsWithTag(string $tag): array
    {
        $connection = $this->connection();
        $table = $this->table();
        $customerTable = $this->resourceConnection->getTableName('customer_entity');

        // ordo_customer_tag has no FK to customer_entity (same small-table design as
        // ordo_customer_score — see CustomerScoreManager::getCustomerIdsWithScoreAtLeast()'s
        // identical fix), so a deleted customer's tag row would otherwise still count as a
        // match here.
        $ids = $connection->fetchCol(
            $connection->select()
                ->from(['t' => $table], 'customer_id')
                ->join(['c' => $customerTable], 't.customer_id = c.entity_id', [])
                ->where('t.tag = ?', $tag)
        );

        return array_map('intval', $ids);
    }

    /**
     * Has at least one of the given tags - the "any" match mode for HasTag.
     *
     * @param string[] $tags
     */
    public function hasAnyTag(int $customerId, array $tags): bool
    {
        if ($tags === []) {
            return false;
        }

        $connection = $this->connection();
        $table = $this->table();

        $count = $connection->fetchOne(
            $connection->select()
                ->from($table, 'COUNT(*)')
                ->where('customer_id = ?', $customerId)
                ->where('tag IN (?)', $tags)
        );

        return (int) $count > 0;
    }

    /**
     * Has every one of the given tags - the "all" match mode for HasTag.
     *
     * @param string[] $tags
     */
    public function hasAllTags(int $customerId, array $tags): bool
    {
        if ($tags === []) {
            return false;
        }

        $connection = $this->connection();
        $table = $this->table();

        $matched = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, 'COUNT(DISTINCT tag)')
                ->where('customer_id = ?', $customerId)
                ->where('tag IN (?)', $tags)
        );

        return $matched >= count(array_unique($tags));
    }

    /**
     * Set-level counterpart of hasAnyTag() - every customer carrying at least one of the given
     * tags, for HasTag::resolveCustomerIds() (BulkResolvableConditionInterface).
     *
     * @param string[] $tags
     * @return int[]
     */
    public function getCustomerIdsWithAnyTag(array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        $connection = $this->connection();
        $table = $this->table();
        $customerTable = $this->resourceConnection->getTableName('customer_entity');

        // distinct() as its own statement - see searchTags()'s own comment on why a bare
        // "DISTINCT column" string in from()'s column argument is a real SQL error, not chained
        // here for the same reason having() isn't chained in getCustomerIdsWithAllTags() below.
        $select = $connection->select()
            ->from(['t' => $table], 'customer_id')
            ->join(['c' => $customerTable], 't.customer_id = c.entity_id', [])
            ->where('t.tag IN (?)', $tags);
        $select->distinct();

        $ids = $connection->fetchCol($select);

        return array_map('intval', $ids);
    }

    /**
     * Set-level counterpart of hasAllTags() - every customer carrying every one of the given
     * tags, for HasTag::resolveCustomerIds() (BulkResolvableConditionInterface).
     *
     * @param string[] $tags
     * @return int[]
     */
    public function getCustomerIdsWithAllTags(array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        $connection = $this->connection();
        $table = $this->table();
        $customerTable = $this->resourceConnection->getTableName('customer_entity');

        // having() is called as its own statement, not chained - Magento's Select overrides
        // where()/group()/join() to return self (Select), but inherits having() unchanged from
        // Zend_Db_Select, so chaining it would narrow $select's own static type to the parent
        // class and break fetchCol()'s Select|string parameter type.
        $select = $connection->select()
            ->from(['t' => $table], 'customer_id')
            ->join(['c' => $customerTable], 't.customer_id = c.entity_id', [])
            ->where('t.tag IN (?)', $tags)
            ->group('t.customer_id');
        $select->having('COUNT(DISTINCT t.tag) >= ?', count(array_unique($tags)));

        return array_map('intval', $connection->fetchCol($select));
    }

    private function connection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }

    private function table(): string
    {
        return $this->resourceConnection->getTableName('ordo_customer_tag');
    }
}
