<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

interface CampaignActionInterface
{
    public const ENTITY_ID = 'entity_id';
    public const CAMPAIGN_ID = 'campaign_id';
    public const TYPE = 'type';
    public const PARAMS = 'params';
    public const SORT_ORDER = 'sort_order';
    public const DELAY_MINUTES = 'delay_minutes';

    public function getEntityId(): ?int;

    public function setEntityId(int $entityId): self;

    public function getCampaignId(): int;

    public function setCampaignId(int $campaignId): self;

    /**
     * Action type key — must match a type registered in Model\Campaign\ActionPool
     * (the same registry that drives the admin form's type dropdown).
     */
    public function getType(): string;

    public function setType(string $type): self;

    /**
     * Raw JSON string, e.g. {"tag": "vip"} — decoded by the action class at dispatch time
     * (Api\Campaign\ActionInterface::execute()'s $params argument). Named *ParamsJson*, not
     * *Params*, to avoid colliding with the model's own getParams(): array helper (already
     * used internally by the dispatcher, returns decoded).
     */
    public function getParamsJson(): string;

    public function setParamsJson(string $paramsJson): self;

    public function getSortOrder(): int;

    public function setSortOrder(int $sortOrder): self;

    /**
     * Minutes to wait after the previous action (or the trigger, if this is the first) before
     * running this one — 0 runs in the same synchronous dispatch as everything before it, a
     * positive value pauses the campaign's action chain (Model\CampaignDispatcher schedules a
     * ordo_campaign_scheduled_action row instead of executing further) until it elapses.
     */
    public function getDelayMinutes(): int;

    public function setDelayMinutes(int $delayMinutes): self;
}
