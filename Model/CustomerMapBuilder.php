<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * "Given a list of customer ids, load them and index by id" — extracted after SonarCloud flagged
 * this exact private method, byte-for-byte identical, duplicated across five reminder/alert/digest
 * cron classes (SendWinBackEmails, SendOfferExpiryReminders, SendReorderReminders,
 * SendCreditLimitAlerts, SendSalesRepDigest).
 */
class CustomerMapBuilder
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @param int[] $customerIds
     * @return array<int, CustomerInterface>
     */
    public function build(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', array_values(array_unique($customerIds)), 'in')
            ->create();

        $customerMap = [];
        foreach ($this->customerRepository->getList($searchCriteria)->getItems() as $customer) {
            $customerMap[(int) $customer->getId()] = $customer;
        }

        return $customerMap;
    }
}
