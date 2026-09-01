<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit;

use Magento\Quote\Model\Quote;

/**
 * PHPUnit's MockBuilder::addMethods() — the only way to stub a method that exists solely as a
 * magic @method docblock (getSubtotal(), getCustomerId() on Quote, backed by __call()/getData())
 * — was removed in PHPUnit 12 with no replacement: a generated mock's own __call() shadows the
 * real class's, so stubbing getData() instead doesn't work either (verified directly against a
 * real PHPUnit 12 run before writing this). This tiny concrete subclass gives those two getters
 * a real, declared, therefore-mockable-via-onlyMethods() implementation instead. Every other
 * method used in tests (getId(), getStoreId(), collectTotals(), addProduct(), removeItem()) is
 * already a real declared method on Quote/AbstractModel and needs no help here.
 */
class QuoteTestDouble extends Quote
{
    private ?float $testSubtotal = null;
    private ?int $testCustomerId = null;

    public function __construct()
    {
        // Deliberately skips parent::__construct() — this double only ever needs the two
        // overridden getters below plus whatever real methods a test stubs via onlyMethods(),
        // none of which touch AbstractModel's real internal state.
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

    public function setTestCustomerId(int $customerId): self
    {
        $this->testCustomerId = $customerId;
        return $this;
    }

    public function getCustomerId(): ?int
    {
        return $this->testCustomerId;
    }
}
