<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Setup\Patch\Data\AddCustomerCreditLimitAttribute;

/**
 * "Used" credit is the sum of sales_order.base_total_due across the customer's non-canceled
 * orders — i.e. what's been ordered but not yet fully invoiced/paid. base_total_due (not
 * total_due) is used deliberately: a customer can have orders placed under more than one currency
 * (e.g. after a website/currency change), and summing the order-currency total_due would mix
 * currencies into one meaningless number. No separate ledger to keep in sync; it's derived
 * straight from order data every time it's asked for.
 */
class CreditLimitCalculator
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    public function getCreditLimit(int $customerId): float
    {
        return $this->getCreditLimitFromCustomer($this->customerRepository->getById($customerId));
    }

    /**
     * Same as getCreditLimit(), for a caller that already has the CustomerInterface loaded
     * (e.g. from CustomerMapBuilder's own batch load) - avoids a redundant per-customer EAV
     * round trip through customerRepository->getById() in a loop over many customers.
     */
    public function getCreditLimitFromCustomer(CustomerInterface $customer): float
    {
        $attribute = $customer->getCustomAttribute(AddCustomerCreditLimitAttribute::ATTRIBUTE_CODE);

        return $attribute ? (float) $attribute->getValue() : 0.0;
    }

    public function getUsedCredit(int $customerId): float
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $used = $connection->fetchOne(
            $connection->select()
                ->from($orderTable, 'SUM(base_total_due)')
                ->where('customer_id = ?', $customerId)
                ->where('state NOT IN (?)', ['canceled', 'closed'])
        );

        return (float) $used;
    }

    /**
     * Batched counterpart to getUsedCredit() - one GROUP BY query for every customer in
     * $customerIds instead of one query per customer, for callers (Cron\SendCreditLimitAlerts)
     * that need this for many customers in a single pass. A customer with no non-canceled orders
     * at all is simply absent from the returned array - callers should default to 0.0.
     *
     * @param int[] $customerIds
     * @return array<int, float> used credit keyed by customer_id
     */
    public function getUsedCreditForCustomers(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($orderTable, ['customer_id', 'SUM(base_total_due)'])
                ->where('customer_id IN (?)', $customerIds)
                ->where('state NOT IN (?)', ['canceled', 'closed'])
                ->group('customer_id')
        );

        $usedByCustomerId = [];
        foreach ($rows as $customerId => $used) {
            $usedByCustomerId[(int) $customerId] = (float) $used;
        }

        return $usedByCustomerId;
    }

    /**
     * @return float 0-100+ (can exceed 100 if the customer is already over the limit)
     */
    public function getUtilizationPercent(int $customerId): float
    {
        $limit = $this->getCreditLimit($customerId);
        if ($limit <= 0.0) {
            return 0.0;
        }

        return round(($this->getUsedCredit($customerId) / $limit) * 100, 2);
    }

    /**
     * Every customer with a configured (> 0) credit limit — the pool the alert cron iterates over.
     *
     * @return int[] customer IDs
     */
    public function getCustomerIdsWithCreditLimit(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $entityTable = $this->resourceConnection->getTableName('customer_entity');
        $attributeTable = $this->resourceConnection->getTableName('customer_entity_decimal');

        $attributeId = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('eav_attribute'), 'attribute_id')
                ->where('attribute_code = ?', AddCustomerCreditLimitAttribute::ATTRIBUTE_CODE)
        );

        if (!$attributeId) {
            return [];
        }

        $customerIds = $connection->fetchCol(
            $connection->select()
                ->from(['e' => $entityTable], [])
                ->joinInner(
                    ['a' => $attributeTable],
                    'a.entity_id = e.entity_id AND a.attribute_id = ' . (int) $attributeId,
                    ['entity_id']
                )
                ->where('a.value > 0')
        );

        return array_map('intval', $customerIds);
    }
}
