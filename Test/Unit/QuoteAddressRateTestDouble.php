<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit;

use Magento\Quote\Model\Quote\Address\Rate;

/**
 * Same reasoning as QuoteTestDouble: getPrice()/getCode() are magic (@method via AbstractModel),
 * and Rate's own constructor pulls in Magento\Framework\Model\Context/Registry this module's unit
 * tests have no interest in constructing - a real, declared, always-fixed implementation instead.
 */
class QuoteAddressRateTestDouble extends Rate
{
    public function __construct(
        private readonly string $testCode,
        private readonly float $testPrice
    ) {
        // Deliberately skips parent::__construct().
    }

    public function getCode(): string
    {
        return $this->testCode;
    }

    public function getPrice(): float
    {
        return $this->testPrice;
    }
}
