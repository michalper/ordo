<?php

declare(strict_types=1);

namespace Ordo\Automation\Model;

/**
 * A flat, typed, ordered child row's shared getters (entity_id/type/params/sort_order) -
 * AbstractCampaignChildModel's own shape minus getCampaignId(), extracted so SegmentCondition
 * (which has no campaign_id - it belongs to a segment_id instead) can share the same getters
 * without inheriting a getCampaignId() that would read a column it doesn't have.
 */
trait ChildRowGettersTrait
{
    public function getEntityId(): ?int
    {
        $id = $this->getData('entity_id');
        return $id === null ? null : (int) $id;
    }

    public function getType(): string
    {
        return (string) $this->getData('type');
    }

    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return ParamsMap::fromJson((string) $this->getData('params'));
    }

    public function getParamsJson(): string
    {
        return (string) $this->getData('params');
    }

    public function getSortOrder(): int
    {
        return (int) $this->getData('sort_order');
    }
}
