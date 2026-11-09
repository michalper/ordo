<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

interface LoyaltyPointsManagementInterface
{
    /**
     * The currently authenticated customer's real points balance.
     *
     * @return int
     * @throws NoSuchEntityException if the caller isn't an authenticated customer
     */
    public function getMyBalance(): int;

    /**
     * Redeems the currently authenticated customer's points for a coupon code, at the
     * configured redemption cost/rule.
     *
     * @return string the generated coupon code
     * @throws NoSuchEntityException if the caller isn't an authenticated customer
     * @throws LocalizedException if the feature is disabled/unconfigured, or the balance is
     *   below the configured redemption cost
     */
    public function selfRedeem(): string;
}
