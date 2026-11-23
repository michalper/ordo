<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\CreditLimitManagementInterface;
use Ordo\Automation\Api\Data\CreditLimitStatusInterface;

class CreditLimitManagement implements CreditLimitManagementInterface
{
    public function __construct(
        private readonly CreditLimitCalculator $creditLimitCalculator,
        private readonly CreditLimitStatusFactory $statusFactory,
        private readonly UserContextInterface $userContext
    ) {
    }

    public function getMyStatus(): CreditLimitStatusInterface
    {
        // UserContextInterface::getUserId() returns 0, not null, for a GraphQL guest - the same
        // trap Model\LoyaltyPointsManagement/LoyaltyWalletPassManagement already guard against at
        // this exact layer. GraphQL's own resolver for this service already checks for 0 before
        // ever calling getMyStatus(), so there's no live exploit today, but this service should
        // fail closed on its own rather than rely on every current and future caller remembering
        // to check 0 upstream.
        $customerId = $this->userContext->getUserId();
        if ($customerId === null || (int) $customerId === 0) {
            throw new NoSuchEntityException(__('No authenticated customer for this request.'));
        }

        return $this->buildStatus((int) $customerId);
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
