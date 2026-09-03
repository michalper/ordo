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

    public function getEntityId(): ?int;

    public function setEntityId(int $entityId): self;

    public function getOfferId(): int;

    public function setOfferId(int $offerId): self;

    public function getSku(): string;

    public function setSku(string $sku): self;
}
