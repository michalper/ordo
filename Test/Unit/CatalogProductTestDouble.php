<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit;

use Magento\Catalog\Model\Product;

/**
 * Same reasoning as QuoteTestDouble: Product::setIsSuperMode() only exists via magic
 * setData()/__call() (no declaration or even a @method docblock anywhere in Catalog), and
 * PHPUnit 12 removed MockBuilder::addMethods(), the only way to stub a method a mock's own
 * generated __call() would otherwise shadow. This gives it a real, declared, therefore-
 * mockable implementation.
 */
class CatalogProductTestDouble extends Product
{
    private bool $testIsSuperMode = false;

    public function __construct()
    {
        // Deliberately skips parent::__construct() — see QuoteTestDouble for why.
    }

    public function setIsSuperMode($mode = true): self
    {
        $this->testIsSuperMode = (bool) $mode;
        return $this;
    }

    public function getTestIsSuperMode(): bool
    {
        return $this->testIsSuperMode;
    }
}
