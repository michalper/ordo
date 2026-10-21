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
 * One shared aroundSave() instead of a copy per entity: each audited entity is a thin, real
 * subclass naming only its own entity_type label and audited-field list — NOT a virtualType.
 * A virtualType was tried first (same pattern as the Shared\Edit admin buttons, docs/adr/0009)
 * and failed `setup:di:compile` ("Plugin class ... doesn't exist"): unlike a ui_component
 * button's `class` attribute (resolved via ObjectManager::create() at runtime), Magento's
 * plugin-list compiler statically resolves a `<plugin type="...">` value and never found a
 * single virtualType-as-plugin-type reference anywhere in core across 517 checked — real
 * subclasses are the only pattern that's actually supported here.
 */
abstract class AbstractResourceAuditPlugin
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
            $this->entityType(),
            (int) $model->getId(),
            $isCreate ? AdminActionLog::ACTION_CREATE : AdminActionLog::ACTION_UPDATE,
            $isCreate ? null : $this->recorder->diffFields($model, $this->auditedFields())
        );

        return $result;
    }
}
