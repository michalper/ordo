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
 * assign the same rep twice in a row.
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

        $connection->beginTransaction();
        try {
            $lastPosition = $connection->fetchOne(
                $connection->select()
                    ->from($table, 'last_assigned_position')
                    ->where('rule_id = ?', $ruleId)
                    ->forUpdate(true)
            );

            $nextPosition = $lastPosition === false ? 0 : ((int) $lastPosition + 1) % $repCount;

            $connection->query(
                // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
                'INSERT INTO ' . $connection->quoteIdentifier($table) . ' (rule_id, last_assigned_position) '
                . 'VALUES (?, ?) ON DUPLICATE KEY UPDATE last_assigned_position = VALUES(last_assigned_position)',
                [$ruleId, $nextPosition]
            );

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $reps[$nextPosition] ?? null;
    }
}
