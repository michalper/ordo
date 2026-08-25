<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Setup\Patch\Data\AddCustomerCreditLimitAttribute;

/**
 * "Used" credit is the sum of sales_order.total_due across the customer's non-canceled orders —
 * i.e. what's been ordered but not yet fully invoiced/paid. No separate ledger to keep in sync;
 * it's derived straight from order data every time it's asked for.
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
        $customer = $this->customerRepository->getById($customerId);
        $attribute = $customer->getCustomAttribute(AddCustomerCreditLimitAttribute::ATTRIBUTE_CODE);

        return $attribute ? (float) $attribute->getValue() : 0.0;
    }

    public function getUsedCredit(int $customerId): float
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $used = $connection->fetchOne(
            $connection->select()
                ->from($orderTable, 'SUM(total_due)')
                ->where('customer_id = ?', $customerId)
                ->where('state NOT IN (?)', ['canceled', 'closed'])
        );

        return (float) $used;
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
                ->joinInner(['a' => $attributeTable], 'a.entity_id = e.entity_id AND a.attribute_id = ' . (int) $attributeId, ['entity_id'])
                ->where('a.value > 0')
        );

        return array_map('intval', $customerIds);
    }
}
