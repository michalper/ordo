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
    public function getCreditLimit(): float;

    public function setCreditLimit(float $creditLimit): self;

    public function getUsedCredit(): float;

    public function setUsedCredit(float $usedCredit): self;

    /**
     * creditLimit - usedCredit — deliberately NOT clamped to zero, so a negative value
     * correctly signals "already over the limit by this much", not "0 remaining".
     */
    public function getAvailableCredit(): float;

    public function setAvailableCredit(float $availableCredit): self;

    /**
     * 0-100+, can exceed 100 if already over the limit.
     */
    public function getUtilizationPercent(): float;

    public function setUtilizationPercent(float $utilizationPercent): self;
}
