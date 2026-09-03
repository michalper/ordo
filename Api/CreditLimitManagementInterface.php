<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\CreditLimitStatusInterface;

interface CreditLimitManagementInterface
{
    /**
     * Credit limit status for the currently authenticated customer — lets a headless storefront
     * show "how much credit do I have left" without needing an admin token or the customer's
     * own entity id.
     *
     * @throws NoSuchEntityException if the caller isn't an authenticated customer
     */
    public function getMyStatus(): CreditLimitStatusInterface;

    /**
     * Admin/sales-rep lookup for an arbitrary customer.
     *
     * @throws NoSuchEntityException
     */
    public function getStatusForCustomer(int $customerId): CreditLimitStatusInterface;
}
