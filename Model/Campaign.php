<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Ordo\Automation\Api\Data\CampaignInterface;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;

class Campaign extends AbstractNamedToggleableEntityModel implements CampaignInterface
{
    protected function _construct(): void
    {
        $this->_init(CampaignResource::class);
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

    /**
     * 'all' (AND, the historical/default behavior) or 'any' (OR) across this campaign's
     * conditions — mirrors Model\Segment::getConditionLogic()/setConditionLogic(); see
     * CampaignDispatcher::allConditionsSatisfied(), the one place that reads it. Not part of
     * CampaignInterface: the Flow canvas is the only editor for campaign conditions today and
     * doesn't yet expose this toggle, so it's a plain model accessor rather than a REST-visible
     * field for now.
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

    /**
     * 0 (the stored default, including every pre-existing campaign row from before this column
     * existed, which reads back as null) means holdout is off - nobody is ever held out unless an
     * admin deliberately sets this above zero. Clamped defensively here too, in addition to
     * CampaignSaveProcessor's own clamp on save, so a row written any other way (a direct API
     * call, a hand-edited fixture) can't hand CampaignDispatcher an out-of-range percent.
     */
    public function getHoldoutPercent(): int
    {
        return max(0, min(100, (int) $this->getData('holdout_percent')));
    }

    public function setHoldoutPercent(int $percent): self
    {
        $this->setData('holdout_percent', max(0, min(100, $percent)));
        return $this;
    }
}
