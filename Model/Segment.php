<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;

/**
 * A saved, reusable set of AND-ed conditions — see etc/db_schema.xml's ordo_segment comment.
 * Admin-only (no WebAPI service contract): unlike Campaign/Offer/etc, nothing here needs to be
 * read or written by a storefront/customer, so there's no Api\Data\SegmentInterface to keep in
 * sync — just a named, toggleable top-level entity like Campaign/FreeGiftOffer, the admin-only
 * entities that came before REST CRUD was added to this module.
 */
class Segment extends AbstractNamedToggleableEntityModel
{
    protected function _construct(): void
    {
        $this->_init(SegmentResource::class);
    }

    public function setName(string $name): self
    {
        $this->setData('name', $name);
        return $this;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->setData('enabled', $enabled);
        return $this;
    }

    /**
     * 'all' (AND, the historical/default behavior) or 'any' (OR) across this segment's
     * conditions — see SegmentMatcher/SegmentMemberResolver, the two places that actually read
     * this to decide how to combine per-condition results.
     */
    public function getConditionLogic(): string
    {
        $value = $this->getData('condition_logic');
        return $value === 'any' ? 'any' : 'all';
    }

    public function setConditionLogic(string $logic): self
    {
        $this->setData('condition_logic', $logic === 'any' ? 'any' : 'all');
        return $this;
    }
}
