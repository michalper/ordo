<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * Snapshot of a cart's free-gift eligibility: how many gift slots the current subtotal has
 * earned (cumulative across every cascading tier reached, across every active offer), how many
 * are already used, and which SKUs are eligible to fill the remaining ones.
 */
interface FreeGiftEligibilityInterface
{
    public function getEarnedSlots(): int;

    public function setEarnedSlots(int $earnedSlots): self;

    public function getUsedSlots(): int;

    public function setUsedSlots(int $usedSlots): self;

    public function getRemainingSlots(): int;

    public function setRemainingSlots(int $remainingSlots): self;

    /**
     * @return string[]
     */
    public function getEligibleSkus(): array;

    /**
     * @param string[] $eligibleSkus
     */
    public function setEligibleSkus(array $eligibleSkus): self;
}
