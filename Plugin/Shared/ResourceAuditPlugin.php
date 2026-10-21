<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\Shared;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;

/**
 * Records who saved an admin-managed entity, and what changed — closes the admin-platform
 * ROADMAP.md gap where the audit log covered only Campaign/Segment saves. Unlike those two
 * (audited via a dedicated SaveProcessor's own process() method, only ever invoked from the
 * admin Save controller), these entities have no such extracted processor — their admin Save
 * controllers build and save the model inline, so the plugin targets each
 * ResourceModel::save() instead. The Recorder::hasLoggedInAdmin() guard keeps non-admin
 * persistence (cron, consumers) out of the log.
 *
 * One parametrized class instead of a copy per entity: each audited entity is a virtualType of
 * this in etc/adminhtml/di.xml, carrying only its entity_type label and audited-field list —
 * same virtualType pattern as the Shared\Edit admin buttons (docs/adr/0009).
 */
class ResourceAuditPlugin
{
    /**
     * @param string[] $auditedFields
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly string $entityType,
        private readonly array $auditedFields
    ) {
    }

    /**
     * @param callable(AbstractModel): AbstractDb $proceed
     */
    public function aroundSave(AbstractDb $subject, callable $proceed, AbstractModel $model): AbstractDb
    {
        if (!$this->recorder->hasLoggedInAdmin()) {
            return $proceed($model);
        }

        $isCreate = !$model->getId();
        $result = $proceed($model);

        $this->recorder->record(
            $this->entityType,
            (int) $model->getId(),
            $isCreate ? AdminActionLog::ACTION_CREATE : AdminActionLog::ACTION_UPDATE,
            $isCreate ? null : $this->recorder->diffFields($model, $this->auditedFields)
        );

        return $result;
    }
}
