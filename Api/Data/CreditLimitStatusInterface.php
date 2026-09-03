<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * A customer's credit limit standing, computed live from `Model\CreditLimitCalculator` — no
 * separate ledger, so this always reflects the current `sales_order.total_due` sum, not a
 * cached snapshot.
 */
interface CreditLimitStatusInterface
{
    /**
     * @return float
     */
    public function getCreditLimit(): float;

    /**
     * @param float $creditLimit
     * @return $this
     */
    public function setCreditLimit(float $creditLimit): self;

    /**
     * @return float
     */
    public function getUsedCredit(): float;

    /**
     * @param float $usedCredit
     * @return $this
     */
    public function setUsedCredit(float $usedCredit): self;

    /**
     * creditLimit - usedCredit — deliberately NOT clamped to zero, so a negative value
     * correctly signals "already over the limit by this much", not "0 remaining".
     *
     * @return float
     */
    public function getAvailableCredit(): float;

    /**
     * @param float $availableCredit
     * @return $this
     */
    public function setAvailableCredit(float $availableCredit): self;

    /**
     * 0-100+, can exceed 100 if already over the limit.
     *
     * @return float
     */
    public function getUtilizationPercent(): float;

    /**
     * @param float $utilizationPercent
     * @return $this
     */
    public function setUtilizationPercent(float $utilizationPercent): self;
}
