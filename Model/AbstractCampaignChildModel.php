<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * CampaignAction and CampaignCondition are both a flat, ordered child row of a Campaign
 * (entity_id/campaign_id/type/params/sort_order), differing only in what CampaignAction adds
 * on top (delay_minutes) — this holds the getters both share so that shared shape lives in one
 * place instead of two copies drifting apart.
 *
 * Only getters live here, not setters: each interface (CampaignActionInterface,
 * CampaignConditionInterface) declares its setters as returning `self` bound to *that*
 * interface, and PHP requires the declaring class to itself be a proven instance of the
 * interface for that to type-check — this class can't implement both interfaces (that would
 * make CampaignCondition falsely instanceof CampaignActionInterface, forcing it to fake
 * getDelayMinutes()/setDelayMinutes()), so the setters stay in each concrete subclass.
 */
abstract class AbstractCampaignChildModel extends AbstractModel
{
    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        $raw = (string) $this->getData('params');
        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    public function getEntityId(): ?int
    {
        $id = $this->getData('entity_id');
        return $id === null ? null : (int) $id;
    }

    public function getCampaignId(): int
    {
        return (int) $this->getData('campaign_id');
    }

    public function getType(): string
    {
        return (string) $this->getData('type');
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
