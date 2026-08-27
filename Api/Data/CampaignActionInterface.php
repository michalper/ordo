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

    /**
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * @param int $entityId
     * @return $this
     */
    public function setEntityId(int $entityId): self;

    /**
     * @return int
     */
    public function getCampaignId(): int;

    /**
     * @param int $campaignId
     * @return $this
     */
    public function setCampaignId(int $campaignId): self;

    /**
     * Action type key — must match a type registered in Model\Campaign\ActionPool
     * (the same registry that drives the admin form's type dropdown).
     *
     * @return string
     */
    public function getType(): string;

    /**
     * @param string $type
     * @return $this
     */
    public function setType(string $type): self;

    /**
     * Raw JSON string, e.g. {"tag": "vip"} — decoded by the action class at dispatch time
     * (Api\Campaign\ActionInterface::execute()'s $params argument). Named *ParamsJson*, not
     * *Params*, to avoid colliding with the model's own getParams(): array helper (already
     * used internally by the dispatcher, returns decoded).
     *
     * @return string
     */
    public function getParamsJson(): string;

    /**
     * @param string $paramsJson
     * @return $this
     */
    public function setParamsJson(string $paramsJson): self;

    /**
     * @return int
     */
    public function getSortOrder(): int;

    /**
     * @param int $sortOrder
     * @return $this
     */
    public function setSortOrder(int $sortOrder): self;
}
