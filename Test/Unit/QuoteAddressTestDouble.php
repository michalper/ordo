<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit;

use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;

/**
 * Same reasoning as QuoteTestDouble, for Model\AiAgent\AiAgentQuoteManagement's use of
 * Quote\Address: getSubtotal()/getDiscountAmount()/getShippingAmount()/getGrandTotal() are magic
 * (@method via AbstractModel), not real declared methods. Deliberately never wrapped in PHPUnit's
 * mock builder itself (unlike QuoteTestDouble) - collectShippingRates()/getAllShippingRates() are
 * overridden directly as plain PHP method overrides below instead, since this double's behavior
 * never needs to vary per PHPUnit expectation/assertion, only per constructed instance.
 */
class QuoteAddressTestDouble extends Address
{
    /** @var Rate[] */
    private array $testRates = [];

    private ?float $testSubtotal = null;
    private ?float $testDiscountAmount = null;
    private ?float $testShippingAmount = null;
    private ?float $testGrandTotal = null;

    public function __construct()
    {
        // Deliberately skips parent::__construct() - this double only ever needs setData()/
        // getData() (already usable without it) plus the overrides below.
    }

    /**
     * @param Rate[] $rates
     */
    public function setTestRates(array $rates): self
    {
        $this->testRates = $rates;
        return $this;
    }

    public function collectShippingRates(): self
    {
        return $this;
    }

    public function getAllShippingRates(): array
    {
        return $this->testRates;
    }

    public function setTestSubtotal(float $subtotal): self
    {
        $this->testSubtotal = $subtotal;
        return $this;
    }

    public function getSubtotal(): ?float
    {
        return $this->testSubtotal;
    }

    public function setTestDiscountAmount(float $discountAmount): self
    {
        $this->testDiscountAmount = $discountAmount;
        return $this;
    }

    public function getDiscountAmount(): ?float
    {
        return $this->testDiscountAmount;
    }

    public function setTestShippingAmount(float $shippingAmount): self
    {
        $this->testShippingAmount = $shippingAmount;
        return $this;
    }

    public function getShippingAmount(): ?float
    {
        return $this->testShippingAmount;
    }

    public function setTestGrandTotal(float $grandTotal): self
    {
        $this->testGrandTotal = $grandTotal;
        return $this;
    }

    public function getGrandTotal(): ?float
    {
        return $this->testGrandTotal;
    }
}
