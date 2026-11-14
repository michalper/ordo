<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

interface LoyaltyWalletPassManagementInterface
{
    /**
     * A "Save to Google Wallet" URL embedding the currently authenticated customer's real points
     * balance and loyalty tier as a signed JWT - redirecting a browser/app to it (or GETing it
     * directly) is Google's own documented way to hand a user a wallet pass
     * (https://developers.google.com/wallet/generic/use-cases/jwt).
     *
     * @return string
     * @throws NoSuchEntityException if the caller isn't an authenticated customer
     * @throws LocalizedException if the feature is disabled, or Google Wallet credentials
     *   aren't fully configured
     */
    public function getMyGoogleWalletSaveUrl(): string;
}
