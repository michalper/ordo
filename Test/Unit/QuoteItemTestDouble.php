<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit;

use Magento\Quote\Model\Quote\Item;

/**
 * Same reasoning as QuoteTestDouble: getPrice()/getRowTotal()/setOriginalCustomPrice() exist only
 * as magic @method docblocks (backed by __call()/getData()/setData()), and PHPUnit 12 removed
 * MockBuilder::addMethods(), the only way to stub a method a mock's own generated __call() would
 * otherwise shadow. This gives them a real, declared, therefore-mockable implementation instead.
 * Item's own constructor also pulls in a large DI graph this module's unit tests have no interest
 * in constructing.
 */
class QuoteItemTestDouble extends Item
{
    private ?float $testOriginalCustomPrice = null;

    public function __construct(
        private readonly ?float $testPrice = null,
        private readonly ?float $testRowTotal = null
    ) {
        // Deliberately skips parent::__construct().
    }

    public function getPrice(): ?float
    {
        return $this->testPrice;
    }

    public function getRowTotal(): ?float
    {
        return $this->testRowTotal;
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
