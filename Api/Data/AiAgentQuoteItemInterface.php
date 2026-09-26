<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * One requested line of AiAgentQuoteManagementInterface::getQuote()'s "items" array - a plain
 * sku+qty pair, the smallest unit a shopping agent asks a price for.
 */
interface AiAgentQuoteItemInterface
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
}
