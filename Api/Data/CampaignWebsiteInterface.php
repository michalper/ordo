<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * One website a campaign is scoped to — a campaign with no rows at all is unscoped (matches
 * every website), see docs/adr/0014. Same "own child entity, not a scalar field" shape as
 * CampaignTriggerInterface, for the same reason: a campaign can be scoped to more than one
 * website.
 */
interface CampaignWebsiteInterface
{
    public const ENTITY_ID = 'entity_id';
    public const CAMPAIGN_ID = 'campaign_id';
    public const WEBSITE_ID = 'website_id';

    public function getEntityId(): ?int;

    public function setEntityId(int $entityId): self;

    public function getCampaignId(): int;

    public function setCampaignId(int $campaignId): self;

    public function getWebsiteId(): int;

    public function setWebsiteId(int $websiteId): self;
}
