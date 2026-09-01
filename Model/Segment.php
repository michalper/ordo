<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;

/**
 * A saved, reusable set of AND-ed conditions — see etc/db_schema.xml's ordo_segment comment.
 * Admin-only (no WebAPI service contract): unlike Campaign/Offer/etc, nothing here needs to be
 * read or written by a storefront/customer, so there's no Api\Data\SegmentInterface to keep in
 * sync — just a plain AbstractModel like the admin-only entities that came before REST CRUD was
 * added to this module.
 */
class Segment extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(SegmentResource::class);
    }

    public function getEntityId(): ?int
    {
        $id = $this->getData('entity_id');
        return $id === null ? null : (int) $id;
    }

    public function getName(): string
    {
        return (string) $this->getData('name');
    }

    public function setName(string $name): self
    {
        $this->setData('name', $name);
        return $this;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getData('enabled');
    }

    public function setEnabled(bool $enabled): self
    {
        $this->setData('enabled', $enabled);
        return $this;
    }
}
