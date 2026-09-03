<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * One SKU in the pool a customer may pick a free gift from, for a given offer.
 */
interface FreeGiftOfferProductInterface
{
    public const ENTITY_ID = 'entity_id';
    public const OFFER_ID = 'offer_id';
    public const SKU = 'sku';

    /**
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * @param int $entityId
     * @return $this
     */
    public function setEntityId(int $entityId): self;

    /**
     * @return int
     */
    public function getOfferId(): int;

    /**
     * @param int $offerId
     * @return $this
     */
    public function setOfferId(int $offerId): self;

    /**
     * @return string
     */
    public function getSku(): string;

    /**
     * @param string $sku
     * @return $this
     */
    public function setSku(string $sku): self;
}
