<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

interface CampaignConditionInterface
{
    public const ENTITY_ID = 'entity_id';
    public const CAMPAIGN_ID = 'campaign_id';
    public const TYPE = 'type';
    public const PARAMS = 'params';
    public const SORT_ORDER = 'sort_order';

    public function getEntityId(): ?int;

    public function setEntityId(int $entityId): self;

    public function getCampaignId(): int;

    public function setCampaignId(int $campaignId): self;

    /**
     * Condition type key — must match a type registered in Model\Campaign\ConditionPool
     * (the same registry that drives the admin form's type dropdown).
     */
    public function getType(): string;

    public function setType(string $type): self;

    /**
     * Raw JSON string, e.g. {"amount": "500"} — decoded by the condition class at dispatch
     * time (Api\Campaign\ConditionInterface::isSatisfied()'s $params argument). Named
     * *ParamsJson*, not *Params*, to avoid colliding with the model's own
     * getParams(): array helper (already used internally by the dispatcher, returns decoded).
     */
    public function getParamsJson(): string;

    public function setParamsJson(string $paramsJson): self;

    public function getSortOrder(): int;

    public function setSortOrder(int $sortOrder): self;
}
