<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\LeadRouting;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Model\LeadRoutingRule;
use Ordo\Automation\Setup\Patch\Data\AddSalesRepAttributes;

/**
 * Assigns a customer to the next rep in a matched rule's own round-robin pool, then writes the
 * exact three customer attributes AddSalesRepAttributes already defines
 * (ordo_sales_rep_{name,email,phone}) - the same ones Cron\SendSalesRepDigest and
 * Model\SalesRepEmailContext already read, so nothing downstream needs to change to see the
 * assignment.
 *
 * The round-robin pointer (ordo_lead_routing_rule_state.last_assigned_position) is advanced
 * with the same transaction + SELECT ... FOR UPDATE shape as
 * CustomerScoreManager::applyDemographicScore() - two overlapping qualifying events for two
 * different customers matching the same rule must never both read the same stale position and
 * assign the same rep twice in a row. nextRep() seeds the row first (INSERT IGNORE) before that
 * locking SELECT, since FOR UPDATE has nothing to lock on a rule's very first-ever assignment -
 * without the seed, two different customers' first-ever qualifying events for the same rule
 * could both read "no row" and both land on position 0.
 *
 * assign() itself re-checks hasAssignedRep() again, under a per-customer named lock, right
 * before actually assigning - the caller's own hasAssignedRep() check (Observer\
 * AssignLeadRoutingRule) happens before this is even called, so two qualifying events racing
 * for the same customer (e.g. customer_register_success and
 * ordo_customer_score_threshold_crossed arriving close together) could otherwise both pass that
 * check before either one saved, consuming two round-robin turns for what should be one
 * assignment. Reported directly.
 */
class LeadAssigner
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    public function assign(int $customerId, LeadRoutingRule $rule): void
    {
        $reps = json_decode($rule->getReps(), true);
        if (!is_array($reps) || count($reps) === 0) {
            return;
        }
        $reps = array_values($reps);

        $connection = $this->resourceConnection->getConnection();
        $lockName = 'ordo_lead_assign_customer_' . $customerId;

        // Fail closed if the lock can't be acquired (contended, or GET_LOCK() unsupported) -
        // skip this assignment rather than risk a double round-robin turn. 5s is generous for a
        // fast read+write pair on a single customer.
        if (!(bool) $connection->fetchOne('SELECT GET_LOCK(?, 5)', [$lockName])) {
            return;
        }

        try {
            // A fresh load, not the (possibly now-stale) customer the caller's own pre-check
            // read - a second qualifying event racing in for this same customer could have
            // assigned a rep in the moment between that check and this lock being acquired.
            $customer = $this->customerRepository->getById($customerId);
            if ($this->hasAssignedRep($customer)) {
                return;
            }

            $rep = $this->nextRep((int) $rule->getEntityId(), $reps);
            if ($rep === null) {
                return;
            }

            $customer->setCustomAttribute(AddSalesRepAttributes::ATTRIBUTE_REP_NAME, (string) ($rep['name'] ?? ''));
            $customer->setCustomAttribute(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL, (string) ($rep['email'] ?? ''));
            $customer->setCustomAttribute(AddSalesRepAttributes::ATTRIBUTE_REP_PHONE, (string) ($rep['phone'] ?? ''));
            $this->customerRepository->save($customer);
        } finally {
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            $connection->query('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /**
     * Already-assigned means a non-blank ordo_sales_rep_email - the observer's own guard against
     * silently overwriting a manual reassignment.
     */
    public function hasAssignedRep(CustomerInterface $customer): bool
    {
        $attribute = $customer->getCustomAttribute(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL);

        return $attribute !== null && trim((string) $attribute->getValue()) !== '';
    }

    /**
     * @param array<int, array<string, mixed>> $reps
     * @return array<string, mixed>|null
     */
    private function nextRep(int $ruleId, array $reps): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_lead_routing_rule_state');
        $repCount = count($reps);

        // SELECT ... FOR UPDATE below only locks a row that already exists - on this rule's
        // very first assignment ever, there's no row yet for it to lock, so two customers
        // qualifying for the same rule at the same moment could both read "no row" (nothing was
        // locked), both compute position 0, and both get the same first rep. Seeding the row
        // first (a no-op via INSERT IGNORE if one already exists, from a past run or a
        // concurrent call that won this exact race) guarantees a row is always there for the
        // FOR UPDATE select just below to actually lock, closing the gap. repCount - 1 is the
        // unsigned-safe equivalent of "one before position 0" - (repCount - 1 + 1) % repCount
        // is 0, the same first position the old $lastPosition === false branch produced.
        $connection->query(
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            'INSERT IGNORE INTO ' . $connection->quoteIdentifier($table) . ' (rule_id, last_assigned_position) VALUES (?, ?)',
            [$ruleId, $repCount - 1]
        );

        $connection->beginTransaction();
        try {
            $lastPosition = (int) $connection->fetchOne(
                $connection->select()
                    ->from($table, 'last_assigned_position')
                    ->where('rule_id = ?', $ruleId)
                    ->forUpdate(true)
            );

            $nextPosition = ($lastPosition + 1) % $repCount;

            $connection->update($table, ['last_assigned_position' => $nextPosition], ['rule_id = ?' => $ruleId]);

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $reps[$nextPosition] ?? null;
    }
}
