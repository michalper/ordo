<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Campaign and FreeGiftOffer are both a named, on/off, timestamped top-level entity — mirrors
 * Api\Data\NamedToggleableEntityInterface, which both their own interfaces extend. Holds the
 * getters both share; setters stay in each subclass for the same reason as
 * AbstractCampaignChildModel's: a `self`-typed interface setter requires the declaring class to
 * itself be a proven instance of that interface, and this shared base can't implement both
 * CampaignInterface and FreeGiftOfferInterface at once without making one falsely instanceof
 * the other.
 */
abstract class AbstractNamedToggleableEntityModel extends AbstractModel
{
    public function getEntityId(): ?int
    {
        $id = $this->getData('entity_id');
        return $id === null ? null : (int) $id;
    }

    public function getName(): string
    {
        return (string) $this->getData('name');
    }

    /**
     * Found hand-duplicated verbatim between Campaign and Segment during a full-repo /simplify
     * sweep - neither's own interface declares this (CampaignInterface doesn't, and Segment has
     * no interface at all - admin-only), so there's no interface-covariance reason (see this
     * class's own docblock) to keep it split per subclass.
     */
    public function getConditionLogic(): string
    {
        $value = $this->getData('condition_logic');
        return $value === 'any' ? 'any' : 'all';
    }

    public function setConditionLogic(string $logic): static
    {
        $this->setData('condition_logic', $logic === 'any' ? 'any' : 'all');
        return $this;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getData('enabled');
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData('created_at');
        return $value === null ? null : (string) $value;
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData('updated_at');
        return $value === null ? null : (string) $value;
    }
}
