<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * One resolved line of an AiAgentQuoteResultInterface - the priced counterpart of the requested
 * AiAgentQuoteItemInterface with the same sku, once ProductRepositoryInterface/Quote::addProduct()
 * has actually matched and priced it.
 */
interface AiAgentQuoteLineInterface
{
    /**
     * @return string
     */
    public function getSku(): string;

    /**
     * @param string $sku
     * @return $this
     */
    public function setSku(string $sku): self;

    /**
     * @return float
     */
    public function getQty(): float;

    /**
     * @param float $qty
     * @return $this
     */
    public function setQty(float $qty): self;

    /**
     * @return float
     */
    public function getUnitPrice(): float;

    /**
     * @param float $unitPrice
     * @return $this
     */
    public function setUnitPrice(float $unitPrice): self;

    /**
     * @return float
     */
    public function getRowTotal(): float;

    /**
     * @param float $rowTotal
     * @return $this
     */
    public function setRowTotal(float $rowTotal): self;
}
