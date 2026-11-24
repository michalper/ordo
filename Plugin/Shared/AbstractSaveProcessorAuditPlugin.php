<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\Shared;

use Ordo\Automation\Model\AbstractNamedToggleableEntityModel;
use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;

/**
 * Records who saved a campaign/segment, and what changed - see AbstractResourceAuditPlugin's own
 * docblock for the overall design and why this is a real subclass per entity, not a virtualType.
 * This is that same shape one level up: Campaign/Segment are audited via their own SaveProcessor's
 * process() rather than a ResourceModel::save() (see CampaignSaveProcessorAuditPlugin's own
 * previous docblock for why - create-vs-update must be derived from the raw posted $data before
 * process() mutates anything). Found hand-duplicated between CampaignSaveProcessorAuditPlugin and
 * SegmentSaveProcessorAuditPlugin during a full-repo /simplify sweep - the exact "3 near-identical
 * copies flagged by SonarCloud" situation AbstractResourceAuditPlugin itself was already
 * extracted to fix, recurring one level up.
 */
abstract class AbstractSaveProcessorAuditPlugin
{
    public function __construct(
        private readonly Recorder $recorder
    ) {
    }

    abstract protected function entityType(): string;

    /**
     * @return string[]
     */
    abstract protected function auditedFields(): array;

    /**
     * @param callable(array<string, mixed>): AbstractNamedToggleableEntityModel $proceed
     * @param array<string, mixed> $data
     */
    protected function doAroundProcess(callable $proceed, array $data): AbstractNamedToggleableEntityModel
    {
        $isCreate = empty($data['entity_id']);

        $entity = $proceed($data);

        $this->recorder->record(
            $this->entityType(),
            $entity->getEntityId(),
            $isCreate ? AdminActionLog::ACTION_CREATE : AdminActionLog::ACTION_UPDATE,
            $isCreate ? null : $this->recorder->diffFields($entity, $this->auditedFields())
        );

        return $entity;
    }
}
