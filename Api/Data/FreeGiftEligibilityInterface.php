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
    /**
     * @return int
     */
    public function getEarnedSlots(): int;

    /**
     * @param int $earnedSlots
     * @return $this
     */
    public function setEarnedSlots(int $earnedSlots): self;

    /**
     * @return int
     */
    public function getUsedSlots(): int;

    /**
     * @param int $usedSlots
     * @return $this
     */
    public function setUsedSlots(int $usedSlots): self;

    /**
     * @return int
     */
    public function getRemainingSlots(): int;

    /**
     * @param int $remainingSlots
     * @return $this
     */
    public function setRemainingSlots(int $remainingSlots): self;

    /**
     * @return string[]
     */
    public function getEligibleSkus(): array;

    /**
     * @param string[] $eligibleSkus
     * @return $this
     */
    public function setEligibleSkus(array $eligibleSkus): self;
}
