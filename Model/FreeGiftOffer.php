<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Ordo\Automation\Api\Data\FreeGiftOfferInterface;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;

class FreeGiftOffer extends AbstractNamedToggleableEntityModel implements FreeGiftOfferInterface
{
    protected function _construct(): void
    {
        $this->_init(FreeGiftOfferResource::class);
    }

    public function setEntityId($entityId): self
    {
        $this->setData(self::ENTITY_ID, (int) $entityId);
        return $this;
    }

    public function setName(string $name): self
    {
        $this->setData(self::NAME, $name);
        return $this;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->setData(self::ENABLED, $enabled);
        return $this;
    }
}
