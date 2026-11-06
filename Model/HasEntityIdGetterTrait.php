<?php

declare(strict_types=1);

namespace Ordo\Automation\Model;

/**
 * The single-method counterpart to VisitorIdentityFieldsTrait/ChildRowGettersTrait: several
 * plain AbstractModel entities (ScoreRule, ScoreRuleWebsite, SegmentWebsite, WhatsAppTemplate)
 * have no other shared shape worth a base class for, but still each re-typed this same
 * entity_id getter by hand.
 */
trait HasEntityIdGetterTrait
{
    public function getEntityId(): ?int
    {
        $id = $this->getData('entity_id');
        return $id === null ? null : (int) $id;
    }
}
