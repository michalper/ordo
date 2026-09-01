<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit;

use Magento\Quote\Model\Quote\Item;

/**
 * Same reasoning as QuoteTestDouble: Item::setOriginalCustomPrice() exists only as a magic
 * @method docblock (backed by __call()/setData()), and PHPUnit 12 removed
 * MockBuilder::addMethods(), the only way to stub a method a mock's own generated __call()
 * would otherwise shadow. This gives it a real, declared, therefore-mockable implementation.
 */
class QuoteItemTestDouble extends Item
{
    private ?float $testOriginalCustomPrice = null;

    public function __construct()
    {
        // Deliberately skips parent::__construct() — see QuoteTestDouble for why.
    }

    public function setOriginalCustomPrice($value): self
    {
        $this->testOriginalCustomPrice = $value;
        return $this;
    }

    public function getTestOriginalCustomPrice(): ?float
    {
        return $this->testOriginalCustomPrice;
    }
}
