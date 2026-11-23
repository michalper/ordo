<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Authorization\Model\UserContextInterface;
use Ordo\Automation\Api\CreditLimitManagementInterface;
use Ordo\Automation\Api\Data\CreditLimitStatusInterface;

class CreditLimitManagement implements CreditLimitManagementInterface
{
    public function __construct(
        private readonly CreditLimitCalculator $creditLimitCalculator,
        private readonly CreditLimitStatusFactory $statusFactory,
        private readonly UserContextInterface $userContext,
        private readonly UserContextCustomerIdResolver $userContextCustomerIdResolver
    ) {
    }

    public function getMyStatus(): CreditLimitStatusInterface
    {
        // GraphQL's own resolver for this service already checks for customer id 0 before ever
        // calling getMyStatus() (see UserContextCustomerIdResolver's own docblock for why that
        // check exists at all), so there's no live exploit today, but this service should fail
        // closed on its own rather than rely on every current and future caller remembering to
        // check upstream.
        return $this->buildStatus($this->userContextCustomerIdResolver->requireCustomerId($this->userContext));
    }

    public function getStatusForCustomer(int $customerId): CreditLimitStatusInterface
    {
        return $this->buildStatus($customerId);
    }

    private function buildStatus(int $customerId): CreditLimitStatusInterface
    {
        $limit = $this->creditLimitCalculator->getCreditLimit($customerId);
        $used = $this->creditLimitCalculator->getUsedCredit($customerId);

        return $this->statusFactory->create()
            ->setCreditLimit($limit)
            ->setUsedCredit($used)
            ->setAvailableCredit($limit - $used)
            ->setUtilizationPercent($this->creditLimitCalculator->getUtilizationPercent($customerId));
    }
}
