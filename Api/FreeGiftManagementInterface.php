<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\FreeGiftEligibilityInterface;
use Ordo\Automation\Api\Data\FreeGiftSelectionInterface;

interface FreeGiftManagementInterface
{
    /**
     * How many gift slots the cart has earned (cumulative across every cascading tier reached,
     * across every active offer), how many are used, and which SKUs are eligible to fill the
     * rest.
     *
     * @throws NoSuchEntityException
     */
    public function getEligibility(int $cartId): FreeGiftEligibilityInterface;

    /**
     * Replaces the cart's current free-gift selection with the given SKUs — a customer may pick
     * as many gifts as their earned slots allow (configurable per offer via cascading tiers),
     * not a fixed count of 1.
     *
     * @throws NoSuchEntityException
     * @throws InputException
     * @throws LocalizedException
     */
    public function selectGifts(int $cartId, FreeGiftSelectionInterface $selection): FreeGiftEligibilityInterface;
}
